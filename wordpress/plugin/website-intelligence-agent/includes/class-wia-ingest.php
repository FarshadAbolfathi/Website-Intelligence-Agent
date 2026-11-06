<?php
/**
 * Learning from WordPress content through its own APIs. Nothing is fetched over HTTP.
 *
 * Immediately: a saved, trashed or deleted post updates knowledge in the same request (one document only).
 * In the background: a resumable scan runs in small, time-limited batches over WP-Cron, so the first scan of a large
 * site cannot exceed a shared host's PHP time limit. A daily sweep catches bulk edits, imports and direct DB changes.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Ingest {
	const BATCH_SIZE   = 25;
	const BATCH_SECONDS = 15;
	const TAXONOMIES   = array( 'product_cat', 'product_brand', 'category' );

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 30, 2 );
		// save_post fires halfway through a WooCommerce save, before attributes and visibility are written. These fire after.
		add_action( 'woocommerce_new_product', array( __CLASS__, 'on_product' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product' ) );
		add_action( 'trashed_post', array( __CLASS__, 'on_remove' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'on_remove' ) );
		add_action( 'edited_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'created_term', array( __CLASS__, 'on_term' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'on_term_delete' ), 10, 3 );
		add_action( 'wia_scan_batch', array( __CLASS__, 'run_batch' ) );
		add_action( 'wia_daily', array( __CLASS__, 'daily' ) );
	}

	public static function types() {
		$settings = WIA_DB::settings();
		return (array) apply_filters( 'wia_content_types', (array) $settings['types'] );
	}

	/** Every public post type, for the "what to learn" settings. */
	public static function available_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$out[ $type->name ] = $type->labels->name;
			}
		}
		return $out;
	}

	/* ---------- text extraction ---------- */

	/** HTML to sections [heading, text], with code, shortcodes and instruction-like sentences removed. */
	public static function sections( $html ) {
		$html  = (string) $html;
		$html  = strip_shortcodes( $html );
		$html  = preg_replace( '/\[\/?[a-zA-Z_][^\]]{0,200}\]/u', ' ', $html );
		$html  = preg_replace( '#<(script|style|iframe|noscript|form|svg)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html  = preg_replace( '#<([a-z0-9]+)\b[^>]*(?:display\s*:\s*none|visibility\s*:\s*hidden)[^>]*>.*?</\1>#is', ' ', $html );
		$parts = preg_split( '#<h[1-6][^>]*>(.*?)</h[1-6]>#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = array();
		$out[] = array( 'heading' => '', 'text' => self::plain( $parts[0] ) );
		for ( $i = 1, $n = count( $parts ); $i < $n; $i += 2 ) {
			$out[] = array( 'heading' => trim( self::line( $parts[ $i ] ) ), 'text' => self::plain( isset( $parts[ $i + 1 ] ) ? $parts[ $i + 1 ] : '' ) );
		}
		return array_values( array_filter( $out, function ( $s ) { return '' !== $s['text']; } ) );
	}

	private static function line( $html ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	private static function plain( $html ) {
		$s = preg_replace( '#<(br|/p|/li|/div|/tr|/blockquote|/td|/th)\b[^>]*>#i', "\n", (string) $html );
		$s = html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' );
		$lines = array();
		foreach ( preg_split( '/\R+/u', $s ) as $line ) {
			$line = trim( preg_replace( '/[ \t\x{00A0}]+/u', ' ', $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}
		list( $clean ) = WIA_Text::strip_injection( implode( "\n", $lines ) );
		return $clean;
	}

	private static function page_type( $post ) {
		if ( 'product' === $post->post_type ) {
			return 'product';
		}
		if ( 'post' === $post->post_type ) {
			return 'article';
		}
		if ( 'page' !== $post->post_type ) {
			return 'page';
		}
		$hay = urldecode( (string) $post->post_name ) . ' ' . $post->post_title;
		foreach ( array(
			'contact' => '/contact|تماس|اتصل/iu',
			'about'   => '/about|درباره|من نحن/iu',
			'faq'     => '/\bfaqs?\b|سوالات|پرسش|الأسئلة/iu',
			'policy'  => '/terms|privacy|policy|refund|shipping|return|warranty|قوانین|حریم|ارسال|مرجوع|گارانتی|شروط|الخصوصية|سياسة|الشحن/iu',
			'service' => '/services?|خدمات/iu',
		) as $type => $re ) {
			if ( preg_match( $re, $hay ) ) {
				return $type;
			}
		}
		return 'page';
	}

	/** Learn one post. @return string new|updated|unchanged|removed|empty|skipped|duplicate */
	public static function ingest_post( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! in_array( $post->post_type, self::types(), true ) ) {
			return 'skipped';
		}
		$public = 'publish' === $post->post_status && '' === (string) $post->post_password;
		if ( $public && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			$public  = $product && 'hidden' !== $product->get_catalog_visibility();
		}
		if ( ! $public || apply_filters( 'wia_exclude_post', false, $post ) ) {
			return WIA_Knowledge::remove( $post->post_type, $post->ID ) ? 'removed' : 'skipped';
		}

		$title    = trim( wp_strip_all_tags( get_the_title( $post ) ) );
		$sections = self::sections( (string) $post->post_content );
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			array_unshift( $sections, array( 'heading' => '', 'text' => self::plain( $post->post_excerpt ) ) );
		}
		$boost = $title;
		if ( 'product' === $post->post_type ) {
			$facts = WIA_Catalog::searchable_facts( $post->ID );
			$boost .= ' ' . $facts['boost'];
			if ( '' !== $facts['text'] ) {
				$sections[] = array( 'heading' => '', 'text' => $facts['text'] );
			}
			// A product with no description is still known by its name. Its price is read live and never stored.
			if ( ! array_filter( wp_list_pluck( $sections, 'text' ) ) ) {
				$sections[] = array( 'heading' => '', 'text' => $title );
			}
		}
		$chunks = WIA_Knowledge::chunk( $sections );
		if ( ! $chunks ) {
			return WIA_Knowledge::remove( $post->post_type, $post->ID ) ? 'removed' : 'empty';
		}
		$language = WIA_Text::detect_language( $title . "\n" . mb_substr( $chunks[0]['text'], 0, 1500 ), WIA_DB::default_language() );
		return WIA_Knowledge::save(
			array(
				'source_type'   => $post->post_type,
				'source_id'     => $post->ID,
				'url'           => (string) get_permalink( $post ),
				'title'         => $title,
				'content_type'  => self::page_type( $post ),
				'language'      => $language,
				'last_modified' => $post->post_modified_gmt,
			),
			$chunks,
			$boost
		);
	}

	public static function ingest_term( $term ) {
		$description = trim( self::plain( $term->description ) );
		$settings    = WIA_DB::settings();
		if ( '' === $description || ! $settings['taxonomies'] ) {
			WIA_Knowledge::remove( 'term', $term->term_id );
			return 'empty';
		}
		$link = get_term_link( $term );
		return WIA_Knowledge::save(
			array(
				'source_type'  => 'term',
				'source_id'    => $term->term_id,
				'url'          => is_wp_error( $link ) ? '' : $link,
				'title'        => $term->name,
				'content_type' => 'category',
				'language'     => WIA_Text::detect_language( $term->name . ' ' . $description, WIA_DB::default_language() ),
			),
			WIA_Knowledge::chunk( array( array( 'heading' => $term->name, 'text' => $description ) ) ),
			$term->name
		);
	}

	/* ---------- hooks ---------- */

	public static function on_save( $id, $post ) {
		if ( wp_is_post_revision( $id ) || wp_is_post_autosave( $id ) ) {
			return;
		}
		self::ingest_post( $post );
	}

	public static function on_product( $id ) {
		self::ingest_post( (int) $id );
	}

	public static function on_remove( $id ) {
		$post = get_post( $id );
		if ( $post ) {
			WIA_Knowledge::remove( $post->post_type, $id );
		}
	}

	public static function on_term( $term_id, $tt_id, $taxonomy ) {
		if ( in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				self::ingest_term( $term );
			}
		}
	}

	public static function on_term_delete( $term_id, $tt_id, $taxonomy ) {
		if ( in_array( $taxonomy, self::TAXONOMIES, true ) ) {
			WIA_Knowledge::remove( 'term', $term_id );
		}
	}

	/* ---------- resumable scan ---------- */

	public static function start_scan( $full = true ) {
		update_option(
			'wia_scan',
			array( 'state' => 'running', 'phase' => 'posts', 'after' => 0, 'full' => (bool) $full, 'since' => $full ? '' : (string) get_option( 'wia_last_sweep', '' ), 'started' => current_time( 'mysql', true ), 'counts' => array(), 'error' => '' ),
			false
		);
		wp_clear_scheduled_hook( 'wia_scan_batch' );
		wp_schedule_single_event( time() + 2, 'wia_scan_batch' );
	}

	public static function scan_state() {
		$scan = get_option( 'wia_scan', array() );
		return is_array( $scan ) && $scan ? $scan : array( 'state' => 'idle', 'counts' => array() );
	}

	private static function bump( &$scan, $key ) {
		$scan['counts'][ $key ] = ( isset( $scan['counts'][ $key ] ) ? $scan['counts'][ $key ] : 0 ) + 1;
	}

	/** One time-limited step. Safe to call repeatedly, from WP-Cron or from the admin page's scan button. */
	public static function run_batch() {
		global $wpdb;
		$scan = self::scan_state();
		if ( 'running' !== $scan['state'] ) {
			return $scan;
		}
		if ( get_transient( 'wia_scan_lock' ) ) {
			return $scan;
		}
		set_transient( 'wia_scan_lock', 1, 2 * self::BATCH_SECONDS );
		$deadline = microtime( true ) + self::BATCH_SECONDS;
		$types    = self::types();

		if ( 'posts' === $scan['phase'] ) {
			$place = implode( ',', array_fill( 0, max( 1, count( $types ) ), '%s' ) );
			$args  = array_merge( (array) $types, array( (int) $scan['after'] ) );
			$since = '';
			if ( ! empty( $scan['since'] ) ) {
				$since  = ' AND post_modified_gmt >= %s';
				$args[] = $scan['since'];
			}
			$args[] = self::BATCH_SIZE;
			$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($place) AND post_status = 'publish' AND ID > %d{$since} ORDER BY ID LIMIT %d", $args ) ); // phpcs:ignore WordPress.DB
			foreach ( $ids as $id ) {
				try {
					self::bump( $scan, self::ingest_post( (int) $id ) );
				} catch ( Throwable $e ) {
					self::bump( $scan, 'errors' );
					$scan['error'] = $e->getMessage();
				}
				$scan['after'] = (int) $id;
				if ( microtime( true ) > $deadline ) {
					break;
				}
			}
			if ( ! $ids ) {
				$scan['phase'] = 'terms';
				$scan['after'] = 0;
			}
		} elseif ( 'terms' === $scan['phase'] ) {
			foreach ( self::TAXONOMIES as $taxonomy ) {
				if ( taxonomy_exists( $taxonomy ) ) {
					foreach ( (array) get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ) as $term ) {
						if ( ! is_wp_error( $term ) && '' !== (string) $term->description ) {
							self::bump( $scan, self::ingest_term( $term ) );
						}
					}
				}
			}
			$scan['phase'] = 'cleanup';
			$scan['after'] = 0;
		} else {
			// Retire knowledge whose source is gone, no longer public, or now excluded in settings.
			$docs = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source_type, source_id FROM ' . WIA_DB::t( 'documents' ) . " WHERE status <> 'removed' AND id > %d AND source_type <> 'term' ORDER BY id LIMIT %d", (int) $scan['after'], self::BATCH_SIZE * 4 ) ); // phpcs:ignore WordPress.DB
			foreach ( (array) $docs as $doc ) {
				$post = get_post( (int) $doc->source_id );
				if ( ! $post || 'publish' !== $post->post_status || $post->post_type !== $doc->source_type || ! in_array( $doc->source_type, $types, true ) ) {
					if ( WIA_Knowledge::remove( $doc->source_type, (int) $doc->source_id ) ) {
						self::bump( $scan, 'removed' );
					}
				}
				$scan['after'] = (int) $doc->id;
			}
			if ( ! $docs ) {
				$scan['state']    = 'done';
				$scan['finished'] = current_time( 'mysql', true );
				update_option( 'wia_last_sweep', (string) $scan['started'], false );
				WIA_Graph::forget();
				WIA_Chat::forget_starters();
				WIA_Learn::snapshot();
			}
		}
		update_option( 'wia_scan', $scan, false );
		delete_transient( 'wia_scan_lock' );
		if ( 'running' === $scan['state'] ) {
			wp_schedule_single_event( time() + 3, 'wia_scan_batch' );
		}
		return $scan;
	}

	public static function daily() {
		$scan = self::scan_state();
		if ( 'running' !== $scan['state'] ) {
			self::start_scan( false );
		}
		WIA_Chat::purge_old();
		WIA_Learn::snapshot();
		WIA_Reference::daily();
	}

	/** Public content the settings leave unread, shown in the admin so nothing is silently skipped. */
	public static function coverage() {
		global $wpdb;
		$types = self::types();
		$out   = array();
		foreach ( self::available_types() as $name => $label ) {
			$out[] = array(
				'name'    => $name,
				'label'   => $label,
				'count'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'", $name ) ), // phpcs:ignore WordPress.DB
				'learned' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WIA_DB::t( 'documents' ) . " WHERE source_type = %s AND status = 'active'", $name ) ), // phpcs:ignore WordPress.DB
				'enabled' => in_array( $name, $types, true ),
			);
		}
		return $out;
	}
}

<?php
/**
 * Learning from other websites the owner chooses: competitors, suppliers, a manufacturer.
 *
 * The plugin reads their public pages, as a visitor would, and keeps only which words they use and on how many
 * pages. It keeps none of their text and never uses them to answer visitors: visitors only ever get this
 * site's own content. What it is for is the knowledge map: topics those sites cover and this site does not, so
 * the owner can decide what to write next.
 *
 * Fetching is gentle: robots.txt is respected, at most MAX_PAGES pages per site, a few pages per background
 * batch, and requests go through wp_safe_remote_get, which refuses private and local addresses, including on
 * redirects.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Reference {
	const MAX_SOURCES   = 5;
	const MAX_PAGES     = 30;
	const PER_BATCH     = 4;
	const REFRESH_DAYS  = 7;
	const USER_AGENT    = 'WIA-Reference/0.2 (WordPress; reads public pages, honours robots.txt)';

	public static function init() {
		add_action( 'wia_ref_batch', array( __CLASS__, 'run_batch' ) );
	}

	public static function sources() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT id, url, status, pages, skipped, last_error, fetched_at, (SELECT COUNT(*) FROM ' . WIA_DB::t( 'ref_terms' ) . ' t WHERE t.source_id = s.id) terms FROM ' . WIA_DB::t( 'ref_sources' ) . ' s ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** @return string '' when the address may be used, otherwise the reason it may not */
	public static function check_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) || ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return 'invalid';
		}
		$own = wp_parse_url( home_url() );
		if ( isset( $own['host'] ) && strtolower( $own['host'] ) === strtolower( $parts['host'] ) ) {
			return 'own_site';
		}
		return wp_http_validate_url( $url ) ? '' : 'not_public';
	}

	/**
	 * Replace the list of reference sites. Sites removed from the list are forgotten with their words.
	 *
	 * @return array rejected url => reason
	 */
	public static function set_sources( $urls ) {
		global $wpdb;
		$table    = WIA_DB::t( 'ref_sources' );
		$wanted   = array();
		$rejected = array();
		foreach ( (array) $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
			if ( '' === $url ) {
				continue;
			}
			$why = self::check_url( $url );
			if ( '' !== $why ) {
				$rejected[ $url ] = $why;
			} elseif ( count( $wanted ) < self::MAX_SOURCES ) {
				$wanted[ untrailingslashit( $url ) ] = 1;
			} else {
				$rejected[ $url ] = 'limit';
			}
		}
		foreach ( self::sources() as $row ) {
			$key = untrailingslashit( $row['url'] );
			if ( isset( $wanted[ $key ] ) ) {
				unset( $wanted[ $key ] );
			} else {
				self::forget( (int) $row['id'] );
			}
		}
		foreach ( array_keys( $wanted ) as $url ) {
			$wpdb->insert( $table, array( 'url' => $url, 'status' => 'queued' ) ); // phpcs:ignore WordPress.DB
		}
		self::schedule();
		WIA_Graph::forget();
		return $rejected;
	}

	private static function forget( $id ) {
		global $wpdb;
		$wpdb->delete( WIA_DB::t( 'ref_terms' ), array( 'source_id' => $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->delete( WIA_DB::t( 'ref_sources' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	}

	/** Read a site again from the start. */
	public static function requeue( $id ) {
		global $wpdb;
		$wpdb->delete( WIA_DB::t( 'ref_terms' ), array( 'source_id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		$wpdb->update( WIA_DB::t( 'ref_sources' ), array( 'status' => 'queued', 'queue' => null, 'pages' => 0, 'skipped' => 0, 'last_error' => '' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		self::schedule();
	}

	public static function schedule() {
		if ( self::pending() && ! wp_next_scheduled( 'wia_ref_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'wia_ref_batch' );
		}
	}

	public static function pending() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WIA_DB::t( 'ref_sources' ) . " WHERE status IN ('queued','running')" ); // phpcs:ignore WordPress.DB
	}

	/** Daily: refresh sites last read more than a week ago. */
	public static function daily() {
		global $wpdb;
		$old = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . WIA_DB::t( 'ref_sources' ) . " WHERE status IN ('done','error') AND (fetched_at IS NULL OR fetched_at < %s)", gmdate( 'Y-m-d H:i:s', time() - self::REFRESH_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		foreach ( (array) $old as $id ) {
			self::requeue( (int) $id );
		}
	}

	/** One small batch: discover a site's pages, or read a few of them. */
	public static function run_batch() {
		global $wpdb;
		if ( get_transient( 'wia_ref_lock' ) ) {
			return 'locked';
		}
		set_transient( 'wia_ref_lock', 1, 2 * MINUTE_IN_SECONDS );
		$table = WIA_DB::t( 'ref_sources' );
		$row   = $wpdb->get_row( "SELECT * FROM {$table} WHERE status IN ('running','queued') ORDER BY status = 'running' DESC, id LIMIT 1", ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( $row ) {
			$queue = null === $row['queue'] ? null : json_decode( (string) $row['queue'], true );
			if ( ! is_array( $queue ) ) {
				$found = self::discover( $row['url'] );
				$wpdb->update( $table, array( 'status' => 'running', 'queue' => wp_json_encode( $found['urls'] ), 'skipped' => $found['skipped'], 'last_error' => $found['error'] ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB
				if ( ! $found['urls'] ) {
					$wpdb->update( $table, array( 'status' => 'error', 'fetched_at' => current_time( 'mysql', true ) ), array( 'id' => $row['id'] ) ); // phpcs:ignore WordPress.DB
				}
			} else {
				$pages = (int) $row['pages'];
				$error = '';
				foreach ( array_splice( $queue, 0, self::PER_BATCH ) as $url ) {
					$terms = self::read_page( $url, $error );
					if ( $terms ) {
						self::add_terms( (int) $row['id'], $terms );
						$pages++;
					}
				}
				$done = ! $queue;
				$wpdb->update( // phpcs:ignore WordPress.DB
					$table,
					array(
						'queue'      => wp_json_encode( $queue ),
						'pages'      => $pages,
						'last_error' => $error,
						'status'     => $done ? ( $pages ? 'done' : 'error' ) : 'running',
						'fetched_at' => $done ? current_time( 'mysql', true ) : $row['fetched_at'],
					),
					array( 'id' => $row['id'] )
				);
				if ( $done ) {
					WIA_Graph::forget();
				}
			}
		}
		delete_transient( 'wia_ref_lock' );
		if ( self::pending() ) {
			wp_schedule_single_event( time() + 30, 'wia_ref_batch' );
			return 'running';
		}
		return 'done';
	}

	private static function get( $url, $accept = 'text/html' ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 3,
				'user-agent'          => self::USER_AGENT,
				'limit_response_size' => 1048576,
				'headers'             => array( 'Accept' => $accept ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array( 0, '', '', $response->get_error_code() );
		}
		return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_header( $response, 'content-type' ), (string) wp_remote_retrieve_body( $response ), '' );
	}

	/** Disallow rules for all robots and for this reader, as regular expressions. */
	public static function robots_rules( $text ) {
		$rules   = array();
		$maps    = array();
		$applies = false;
		$in_ua   = false;
		foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
			$line = trim( preg_replace( '/#.*/', '', $line ) );
			if ( ! preg_match( '/^([A-Za-z-]+)\s*:\s*(.*)$/', $line, $m ) ) {
				continue;
			}
			$key   = strtolower( $m[1] );
			$value = trim( $m[2] );
			if ( 'sitemap' === $key ) {
				$maps[] = $value;
				continue;
			}
			if ( 'user-agent' === $key ) {
				$match   = '*' === $value || ( '' !== $value && false !== stripos( self::USER_AGENT, $value ) );
				$applies = $in_ua ? ( $applies || $match ) : $match;
				$in_ua   = true;
				continue;
			}
			$in_ua = false;
			if ( $applies && 'disallow' === $key && '' !== $value ) {
				$rules[] = '#^' . str_replace( array( '\*', '\$' ), array( '.*', '$' ), preg_quote( $value, '#' ) ) . '#';
			}
		}
		return array( $rules, $maps );
	}

	private static function allowed( $url, $rules ) {
		$parts = wp_parse_url( $url );
		$path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		foreach ( $rules as $re ) {
			if ( preg_match( $re, $path ) ) {
				return false;
			}
		}
		return true;
	}

	private static function same_site( $url, $base ) {
		$a = wp_parse_url( $url );
		$b = wp_parse_url( $base );
		return isset( $a['host'], $b['host'] ) && preg_replace( '/^www\./', '', strtolower( $a['host'] ) ) === preg_replace( '/^www\./', '', strtolower( $b['host'] ) );
	}

	private static function locs( $xml ) {
		preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)\s*(?:\]\]>)?\s*</loc>#i', (string) $xml, $m );
		return array_map( 'html_entity_decode', $m[1] );
	}

	/** Pages to read: from the sitemap when there is one, otherwise from the links on the home page. */
	public static function discover( $base ) {
		$origin = preg_replace( '#^(https?://[^/]+).*$#i', '$1', $base );
		list( $code, , $robots ) = self::get( $origin . '/robots.txt', 'text/plain' );
		list( $rules, $maps )    = 200 === $code ? self::robots_rules( $robots ) : array( array(), array() );
		$maps = $maps ? $maps : array( $origin . '/wp-sitemap.xml', $origin . '/sitemap_index.xml', $origin . '/sitemap.xml' );
		$urls = array();
		foreach ( array_slice( $maps, 0, 3 ) as $map ) {
			list( $code, , $xml ) = self::get( $map, 'application/xml' );
			if ( 200 !== $code ) {
				continue;
			}
			$locs = self::locs( $xml );
			if ( false !== stripos( $xml, '<sitemapindex' ) ) {
				// Content sitemaps first; users and tags say little about what a site covers.
				usort( $locs, function ( $x, $y ) { return (int) (bool) preg_match( '/user|author|tag/i', $x ) <=> (int) (bool) preg_match( '/user|author|tag/i', $y ); } );
				$children = array();
				foreach ( array_slice( $locs, 0, 3 ) as $child ) {
					list( $c2, , $x2 ) = self::get( $child, 'application/xml' );
					$children = 200 === $c2 ? array_merge( $children, self::locs( $x2 ) ) : $children;
				}
				$locs = $children;
			}
			$urls = array_merge( $urls, $locs );
			if ( $urls ) {
				break;
			}
		}
		if ( ! $urls ) {
			list( $code, , $html ) = self::get( $base );
			if ( 200 === $code ) {
				preg_match_all( '#<a\s[^>]*href\s*=\s*["\']([^"\'\#]+)#i', $html, $m );
				foreach ( $m[1] as $href ) {
					$urls[] = 0 === strpos( $href, '/' ) && 0 !== strpos( $href, '//' ) ? $origin . $href : $href;
				}
			}
		}
		array_unshift( $urls, $base );
		$out     = array();
		$skipped = 0;
		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ), array( 'http', 'https' ) );
			if ( '' === $url || ! self::same_site( $url, $base ) || preg_match( '/\.(?:jpe?g|png|gif|webp|svg|pdf|zip|rar|mp4|mp3|css|js|xml)(?:\?|$)/i', $url ) ) {
				continue;
			}
			if ( ! self::allowed( $url, $rules ) ) {
				$skipped++;
				continue;
			}
			$out[ untrailingslashit( $url ) ] = $url;
			if ( count( $out ) >= self::MAX_PAGES ) {
				break;
			}
		}
		return array( 'urls' => array_values( $out ), 'skipped' => $skipped, 'error' => $out ? '' : 'no_pages' );
	}

	/** The distinct words of a page's main text. Navigation, scripts and forms are left out. */
	public static function page_words( $html ) {
		$html = preg_replace( '#<(script|style|noscript|svg|template|iframe|nav|header|footer|aside|form)\b[^>]*>.*?</\1>#is', ' ', (string) $html );
		$html = preg_replace( '#<(?:br|p|div|li|h[1-6]|tr|td|section|article)\b[^>]*>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		list( $text ) = WIA_Text::strip_injection( $text );
		$soft  = WIA_Text::soft_stems();
		$words = array();
		foreach ( WIA_Text::tokenize( $text ) as $t ) {
			if ( ! isset( $soft[ $t ] ) && ! preg_match( '/^[\d.+\-]+$/', $t ) && mb_strlen( $t ) >= 2 && mb_strlen( $t ) <= 40 ) {
				$words[ $t ] = 1;
			}
			if ( count( $words ) >= 3000 ) {
				break;
			}
		}
		return array_keys( $words );
	}

	private static function read_page( $url, &$error ) {
		list( $code, $type, $body, $err ) = self::get( $url );
		if ( 200 !== $code || ( '' !== $type && false === stripos( $type, 'html' ) ) ) {
			$error = '' !== $err ? $err : 'http_' . $code;
			return array();
		}
		return self::page_words( $body );
	}

	private static function add_terms( $source_id, $terms ) {
		global $wpdb;
		$table = WIA_DB::t( 'ref_terms' );
		foreach ( array_chunk( $terms, 300 ) as $batch ) {
			$place = implode( ',', array_fill( 0, count( $batch ), '(%d,%s,1)' ) );
			$args  = array();
			foreach ( $batch as $t ) {
				$args[] = $source_id;
				$args[] = mb_substr( $t, 0, 80 );
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (source_id, term, df) VALUES {$place} ON DUPLICATE KEY UPDATE df = df + 1", $args ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Topics reference sites cover on several pages that this site never mentions.
	 *
	 * @return array of term, sources (how many sites), pages
	 */
	public static function missing_topics( $limit = 20 ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT term, COUNT(*) sources, SUM(df) pages FROM ' . WIA_DB::t( 'ref_terms' ) . ' GROUP BY term HAVING SUM(df) >= 2 ORDER BY sources DESC, pages DESC LIMIT 400', ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $rows ) {
			return array();
		}
		$all = array();
		foreach ( $rows as $r ) {
			$all[ $r['term'] ] = $r;
		}
		$known = array();
		foreach ( array_chunk( array_keys( $all ), 100 ) as $chunk ) {
			$place = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$known = array_merge( $known, (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT term FROM ' . WIA_DB::t( 'postings' ) . " WHERE term IN ($place)", $chunk ) ) ); // phpcs:ignore WordPress.DB
		}
		$known = array_flip( $known );
		$out   = array();
		foreach ( $all as $term => $r ) {
			if ( isset( $known[ $term ] ) ) {
				continue;
			}
			// A joined pair of words is one topic shown twice.
			$len = mb_strlen( $term );
			for ( $i = 2; $i <= $len - 2; $i++ ) {
				if ( isset( $all[ mb_substr( $term, 0, $i ) ], $all[ mb_substr( $term, $i ) ] ) ) {
					continue 2;
				}
			}
			$out[] = array( 'term' => (string) $term, 'sources' => (int) $r['sources'], 'pages' => (int) $r['pages'] );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	public static function summary() {
		$s = array( 'sites' => 0, 'pages' => 0, 'terms' => 0 );
		foreach ( self::sources() as $row ) {
			$s['sites']++;
			$s['pages'] += (int) $row['pages'];
			$s['terms'] += (int) $row['terms'];
		}
		return $s;
	}
}

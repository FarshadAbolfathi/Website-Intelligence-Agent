<?php
/**
 * The answer pipeline, in the same order as the Python engine. Without a language model it finds and quotes the site's
 * own text: it does not write new sentences, and it says so when the site has no answer.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Chat {
	private static $messages = null;

	public static function msg( $lang, $key, $vars = array() ) {
		if ( null === self::$messages ) {
			self::$messages = json_decode( (string) file_get_contents( WIA_DIR . 'shared/chat-messages.json' ), true );
		}
		$text = isset( self::$messages['text'][ $lang ][ $key ] ) ? self::$messages['text'][ $lang ][ $key ] : self::$messages['text']['en'][ $key ];
		foreach ( $vars as $k => $v ) {
			$text = str_replace( '{' . $k . '}', $v, $text );
		}
		return $text;
	}

	private static function currencies() {
		self::msg( 'en', 'greeting' );
		return self::$messages['currency'];
	}

	/**
	 * @return array text, language, mode, sources[url,title,checked_at], stale, context_doc
	 */
	public static function answer( $question, $context_doc = 0, $previous_question = '' ) {
		$question = trim( mb_substr( (string) $question, 0, 1000 ) );
		$default  = WIA_DB::default_language();
		$lang     = WIA_Text::detect_language( $question, $default );
		$reply    = function ( $mode, $text, $sources = array(), $doc = 0 ) use ( $lang, $context_doc ) {
			return array( 'text' => $text, 'language' => $lang, 'mode' => $mode, 'sources' => $sources, 'stale' => false, 'context_doc' => $doc ? $doc : $context_doc );
		};
		if ( '' === $question || WIA_Text::intent( 'greeting', $question ) ) {
			return $reply( 'smalltalk', self::msg( $lang, 'greeting' ) );
		}
		if ( WIA_Text::intent( 'thanks', $question ) ) {
			return $reply( 'smalltalk', self::msg( $lang, 'thanks' ) );
		}
		if ( WIA_Text::looks_like_injection( $question ) || WIA_Text::probes_for_secrets( $question ) ) {
			return $reply( 'guard', self::msg( $lang, 'guard' ) );
		}

		$faq = self::match_faq( $question, $lang );
		if ( $faq ) {
			return $reply( 'faq', $faq['answer'] );
		}

		$normalized = WIA_Text::normalize( $question );
		if ( WIA_Text::intent( 'catalog', $normalized ) || WIA_Text::intent( 'catalog', $question ) ) {
			$list = WIA_Catalog::listing();
			if ( $list ) {
				$sources = array();
				$lines   = array( self::msg( $lang, 'products_head' ) );
				foreach ( $list as $item ) {
					$lines[]   = '• ' . $item['name'];
					$sources[] = array( 'url' => $item['url'], 'title' => $item['name'], 'checked_at' => gmdate( 'c' ) );
				}
				return $reply( 'structured', implode( "\n", $lines ), $sources );
			}
		}

		$wants_price = WIA_Text::intent( 'price', $normalized );
		$wants_stock = WIA_Text::intent( 'stock', $normalized );
		$query       = self::resolve_followup( $question, $normalized, $context_doc, $previous_question );
		$hits        = WIA_Knowledge::search( $query, 5 );
		// Each product holds only part of a comparison's words, so the gate would refuse it. Requiring two
		// products that are really named in the question is the stricter check here.
		if ( $hits && WIA_Text::intent( 'compare', $normalized ) ) {
			$compared = self::compare( $hits, $query, $lang );
			if ( $compared ) {
				return $compared;
			}
		}
		$best = 0.0;
		foreach ( $hits as $hit ) {
			$best = max( $best, (float) $hit['coverage'] );
		}
		if ( ! $hits || $best < WIA_Knowledge::MIN_COVERAGE ) {
			return self::not_found( $lang, $context_doc );
		}
		if ( $wants_price || $wants_stock ) {
			$fact = self::product_fact( $hits, $query, $lang, $wants_price, $wants_stock, $context_doc );
			if ( $fact ) {
				return $fact;
			}
		}
		return self::extractive( $query, $hits, $lang );
	}

	private static function not_found( $lang, $context_doc = 0 ) {
		$settings = WIA_DB::settings();
		$custom   = isset( $settings['not_found'][ $lang ] ) ? trim( (string) $settings['not_found'][ $lang ] ) : '';
		return array( 'text' => '' !== $custom ? $custom : self::msg( $lang, 'not_found' ), 'language' => $lang, 'mode' => 'not_found', 'sources' => array(), 'stale' => false, 'context_doc' => $context_doc );
	}

	private static function source( $hit ) {
		return array( 'url' => $hit['url'], 'title' => $hit['title'], 'checked_at' => mysql2date( 'c', $hit['crawl_date'], false ) );
	}

	private static function match_faq( $question, $lang ) {
		global $wpdb;
		$tokens     = array_unique( WIA_Text::tokenize( $question ) );
		$normalized = ' ' . WIA_Text::normalize( $question ) . ' ';
		if ( ! $tokens ) {
			return null;
		}
		$best       = null;
		$best_score = 0.0;
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . WIA_DB::t( 'faqs' ) . ' ORDER BY id', ARRAY_A ) as $faq ) { // phpcs:ignore WordPress.DB
			if ( '' !== $faq['language'] && $faq['language'] !== $lang ) {
				continue;
			}
			foreach ( preg_split( '/[،,\n]/u', (string) $faq['keywords'] ) as $keyword ) {
				$keyword = trim( $keyword );
				if ( '' !== $keyword && false !== strpos( $normalized, ' ' . WIA_Text::normalize( $keyword ) . ' ' ) ) {
					return $faq;
				}
			}
			$candidate = array_unique( WIA_Text::tokenize( $faq['question'] ) );
			if ( ! $candidate ) {
				continue;
			}
			$overlap = count( array_intersect( $tokens, $candidate ) ) / count( array_unique( array_merge( $tokens, $candidate ) ) );
			if ( $overlap > $best_score ) {
				$best       = $faq;
				$best_score = $overlap;
			}
		}
		return $best_score >= 0.6 ? $best : null;
	}

	private static function resolve_followup( $question, $normalized, $context_doc, $previous_question ) {
		if ( ! $context_doc && '' === $previous_question ) {
			return $question;
		}
		if ( ! WIA_Text::intent( 'followup', $normalized ) && count( WIA_Text::tokenize( $question ) ) > 2 ) {
			return $question;
		}
		$subject = '';
		if ( $context_doc ) {
			global $wpdb;
			$subject = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT title FROM ' . WIA_DB::t( 'documents' ) . " WHERE id = %d AND status = 'active'", $context_doc ) ); // phpcs:ignore WordPress.DB
		}
		if ( '' === $subject ) {
			$subject = $previous_question;
		}
		return trim( $subject . ' ' . $question );
	}

	/** Only a product whose name is really in the question may answer. A nearby product must not answer for another. */
	private static function pick_product( $hits, $query ) {
		$q          = array_unique( WIA_Text::tokenize( $query ) );
		$best       = null;
		$best_ratio = 0.0;
		foreach ( $hits as $hit ) {
			if ( 'product' !== $hit['content_type'] ) {
				continue;
			}
			$name = array_unique( WIA_Text::tokenize( $hit['title'] ) );
			if ( ! $name ) {
				continue;
			}
			$ratio = count( array_intersect( $name, $q ) ) / count( $name );
			if ( $ratio > $best_ratio ) {
				$best       = $hit;
				$best_ratio = $ratio;
			}
		}
		return $best_ratio >= 0.5 ? $best : null;
	}

	private static function product_fact( $hits, $query, $lang, $wants_price, $wants_stock, $context_doc ) {
		$hit = self::pick_product( $hits, $query );
		if ( ! $hit && $context_doc ) {
			foreach ( $hits as $h ) {
				if ( (int) $h['doc_id'] === (int) $context_doc && 'product' === $h['content_type'] ) {
					$hit = $h;
					break;
				}
			}
		}
		if ( ! $hit ) {
			return null;
		}
		$live = WIA_Catalog::live( (int) $hit['source_id'] );
		if ( ! $live ) {
			return null;
		}
		$lines = array();
		if ( $wants_price ) {
			if ( '' !== (string) $live['price'] ) {
				$lines[] = self::msg( $lang, 'price', array( 'name' => $live['name'], 'price' => trim( WIA_Catalog::number( $live['price'], $lang ) . ' ' . WIA_Catalog::currency( $lang, self::currencies() ) ) ) );
			} else {
				$lines[] = self::msg( $lang, 'no_price', array( 'name' => $live['name'] ) );
			}
		}
		if ( $wants_stock ) {
			$key     = array( 'in_stock' => 'stock_in', 'out_of_stock' => 'stock_out', 'preorder' => 'stock_pre' );
			$lines[] = self::msg( $lang, isset( $key[ $live['availability'] ] ) ? $key[ $live['availability'] ] : 'stock_unknown', array( 'name' => $live['name'] ) );
		}
		return array(
			'text'        => implode( "\n", $lines ),
			'language'    => $lang,
			'mode'        => 'structured',
			'sources'     => array( array( 'url' => $live['url'], 'title' => $live['name'], 'checked_at' => gmdate( 'c' ) ) ),
			'stale'       => false,
			'context_doc' => (int) $hit['doc_id'],
		);
	}

	private static function compare( $hits, $query, $lang ) {
		$q        = array_unique( WIA_Text::tokenize( $query ) );
		$products = array();
		foreach ( $hits as $hit ) {
			$name = array_unique( WIA_Text::tokenize( $hit['title'] ) );
			if ( 'product' === $hit['content_type'] && $name && count( array_intersect( $name, $q ) ) / count( $name ) >= 0.5 && ! isset( $products[ $hit['doc_id'] ] ) ) {
				$products[ $hit['doc_id'] ] = $hit;
			}
		}
		if ( count( $products ) < 2 ) {
			return null;
		}
		$pair = array_slice( array_values( $products ), 0, 2 );
		$live = array( WIA_Catalog::live( (int) $pair[0]['source_id'] ), WIA_Catalog::live( (int) $pair[1]['source_id'] ) );
		if ( ! $live[0] || ! $live[1] ) {
			return null;
		}
		$lines   = array( self::msg( $lang, 'compare_head', array( 'a' => $live[0]['name'], 'b' => $live[1]['name'] ) ) );
		$sources = array();
		foreach ( array( 0, 1 ) as $i ) {
			$first     = WIA_Text::sentences( $pair[ $i ]['body'] );
			$price     = '' !== (string) $live[ $i ]['price'] ? trim( WIA_Catalog::number( $live[ $i ]['price'], $lang ) . ' ' . WIA_Catalog::currency( $lang, self::currencies() ) ) : '-';
			$lines[]   = '• ' . $live[ $i ]['name'] . ': ' . $price . ( $first ? ' - ' . $first[0] : '' );
			$sources[] = array( 'url' => $live[ $i ]['url'], 'title' => $live[ $i ]['name'], 'checked_at' => gmdate( 'c' ) );
		}
		return array( 'text' => implode( "\n", $lines ), 'language' => $lang, 'mode' => 'structured', 'sources' => $sources, 'stale' => false, 'context_doc' => 0 );
	}

	private static function extractive( $query, $hits, $lang ) {
		$best   = $hits[0];
		$tokens = array_unique( WIA_Text::tokenize( $query ) );
		$scored = array();
		foreach ( WIA_Text::sentences( $best['body'] ) as $i => $sentence ) {
			$scored[] = array( count( array_intersect( $tokens, WIA_Text::tokenize( $sentence ) ) ), -$i, $sentence );
		}
		usort( $scored, function ( $a, $b ) { return $b[0] <=> $a[0] ?: $b[1] <=> $a[1]; } );
		$picked = array_slice( $scored, 0, 2 );
		usort( $picked, function ( $a, $b ) { return $b[1] <=> $a[1]; } );
		$quote = implode( ' ', array_column( $picked, 2 ) );
		$quote = '' !== $quote ? $quote : mb_substr( $best['body'], 0, 300 );
		$head  = self::msg( $lang, 'from_source', array( 'title' => $best['title'] ) );
		if ( '' !== $best['language'] && $best['language'] !== $lang ) {
			$head = self::msg( $lang, 'other_lang' ) . "\n" . $head;
		}
		return array( 'text' => $head . "\n" . $quote, 'language' => $lang, 'mode' => 'extractive', 'sources' => array( self::source( $best ) ), 'stale' => false, 'context_doc' => (int) $best['doc_id'] );
	}

	/**
	 * Optional: forward the question to the owner's own self-hosted engine. Off by default.
	 * On any failure, the site answers locally instead of showing an error.
	 */
	public static function ask_engine( $question, $conversation ) {
		$settings = WIA_DB::settings();
		if ( empty( $settings['engine_enabled'] ) || '' === $settings['engine_url'] || '' === $settings['engine_key'] ) {
			return null;
		}
		$response = wp_safe_remote_post(
			trailingslashit( $settings['engine_url'] ) . 'public/v1/chat',
			array(
				'timeout' => 25,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( array( 'site_key' => $settings['engine_key'], 'message' => $question, 'conversation_id' => $conversation ? $conversation : null ) ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		return is_array( $data ) && ! empty( $data['answer'] ) ? $data : null;
	}

	/* ---------- conversation store ---------- */

	public static function load_conversation( $id ) {
		global $wpdb;
		if ( '' === (string) $id || ! preg_match( '/^[A-Za-z0-9_\-]{10,40}$/', $id ) ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WIA_DB::t( 'conversations' ) . ' WHERE id = %s', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$row['state'] = json_decode( (string) $row['state'], true ) ?: array();
		return $row;
	}

	public static function save_turn( $id, $state, $question, $answer ) {
		global $wpdb;
		$settings = WIA_DB::settings();
		$now      = current_time( 'mysql', true );
		$table    = WIA_DB::t( 'conversations' );
		if ( '' === (string) $id ) {
			$id = wp_generate_password( 32, false, false );
			$wpdb->insert( $table, array( 'id' => $id, 'state' => wp_json_encode( $state ), 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB
		} else {
			$wpdb->update( $table, array( 'state' => wp_json_encode( $state ), 'updated_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		}
		if ( ! empty( $settings['log_messages'] ) ) {
			$messages = WIA_DB::t( 'messages' );
			$wpdb->insert( $messages, array( 'conversation_id' => $id, 'role' => 'user', 'body' => $question, 'mode' => $answer['mode'], 'created_at' => $now ) ); // phpcs:ignore WordPress.DB
			$wpdb->insert( $messages, array( 'conversation_id' => $id, 'role' => 'assistant', 'body' => $answer['text'], 'mode' => $answer['mode'], 'created_at' => $now ) ); // phpcs:ignore WordPress.DB
		}
		return $id;
	}

	public static function purge_old() {
		global $wpdb;
		$settings = WIA_DB::settings();
		$days     = max( 1, (int) $settings['retention_days'] );
		$cutoff   = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$conv     = WIA_DB::t( 'conversations' );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . WIA_DB::t( 'messages' ) . " WHERE conversation_id IN (SELECT id FROM {$conv} WHERE updated_at < %s)", $cutoff ) ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$conv} WHERE updated_at < %s", $cutoff ) ); // phpcs:ignore WordPress.DB
	}
}

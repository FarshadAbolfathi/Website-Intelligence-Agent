<?php
/**
 * Learning from conversations, and the counters behind the knowledge map.
 *
 * When the site does not know a visitor's word («کیف»), the assistant offers what it has. If the visitor then
 * settles on a page, by choosing a suggestion or by asking again in other words, that is one vote for
 * «کیف» -> that page. After enough votes from different conversations the phrase becomes active and later
 * visitors get that page directly. Answers are still quoted from the site's own page, so a wrong phrase can at
 * worst point to the wrong page of this site, and the owner can reject it on the Learning screen.
 *
 * Counters keep numbers and single unknown words only, never whole messages, so they work with logging off.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Learn {
	const VOTES_TO_ACTIVATE = 3;
	const KEEP_DAYS         = 120;
	const MAX_GAPS          = 300;

	/** Phrase key: sorted unique stems. */
	public static function phrase( $stems ) {
		$stems = array_values( array_unique( array_filter( array_map( 'strval', (array) $stems ), 'strlen' ) ) );
		sort( $stems );
		return mb_substr( implode( ' ', $stems ), 0, 190 );
	}

	/** Called after every visitor turn with the conversation state, before the state is saved. */
	public static function after_turn( &$state, $question, $answer ) {
		$pending = isset( $state['learn'] ) && is_array( $state['learn'] ) ? $state['learn'] : null;
		if ( $pending && ! empty( $pending['words'] ) && in_array( $answer['mode'], array( 'extractive', 'structured' ), true ) && (int) $answer['context_doc'] > 0 ) {
			// Asking again with the same unknown word is not a rephrasing, so it teaches nothing.
			$asked = array_flip( WIA_Text::tokenize( $question ) );
			$fresh = true;
			foreach ( $pending['words'] as $w ) {
				if ( isset( $asked[ $w ] ) ) {
					$fresh = false;
				}
			}
			$key = self::phrase( $pending['words'] ) . '|' . (int) $answer['context_doc'];
			if ( $fresh && empty( $state['voted'][ $key ] ) ) {
				self::vote( $pending['words'], (int) $answer['context_doc'] );
				$state['voted'][ $key ] = 1;
			}
		}
		$state['learn'] = ! empty( $answer['learn'] ) ? $answer['learn'] : null;
	}

	public static function vote( $words, $doc_id ) {
		global $wpdb;
		$phrase = self::phrase( $words );
		if ( '' === $phrase || $doc_id <= 0 ) {
			return;
		}
		$table = WIA_DB::t( 'aliases' );
		$now   = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (phrase, doc_id, votes, status, created_at, updated_at) VALUES (%s, %d, 1, 'candidate', %s, %s) ON DUPLICATE KEY UPDATE votes = votes + 1, updated_at = VALUES(updated_at)", $phrase, $doc_id, $now, $now ) ); // phpcs:ignore WordPress.DB
		if ( ! empty( WIA_DB::settings()['auto_learn'] ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'active' WHERE phrase = %s AND doc_id = %d AND status = 'candidate' AND votes >= %d", $phrase, $doc_id, self::VOTES_TO_ACTIVATE ) ); // phpcs:ignore WordPress.DB
		}
	}

	/**
	 * Hits for an active learned phrase, with its page first, or an empty array.
	 *
	 * @param array $info from WIA_Knowledge::search
	 */
	public static function alias_hits( $question, $info ) {
		global $wpdb;
		$unknown = isset( $info['unknown'] ) ? (array) $info['unknown'] : array();
		if ( ! $unknown ) {
			return array();
		}
		// The phrase must cover every unknown word: «ساک» learned for a hiking pack says nothing about «ساک دستی».
		$sql = 'SELECT a.doc_id, d.title FROM ' . WIA_DB::t( 'aliases' ) . ' a JOIN ' . WIA_DB::t( 'documents' ) . " d ON d.id = a.doc_id WHERE a.status = 'active' AND d.status = 'active' AND a.phrase = %s ORDER BY a.votes DESC LIMIT 1";
		$row = $wpdb->get_row( $wpdb->prepare( $sql, self::phrase( $unknown ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return array();
		}
		$hits = WIA_Knowledge::search( trim( $row['title'] . ' ' . $question ), 8 );
		$own  = array();
		$rest = array();
		foreach ( $hits as $hit ) {
			if ( (int) $hit['doc_id'] === (int) $row['doc_id'] ) {
				$own[] = $hit;
			} else {
				$rest[] = $hit;
			}
		}
		return $own ? array_merge( $own, $rest ) : array();
	}

	/* ---------- counters ---------- */

	public static function count( $mode ) {
		$map = array( 'extractive' => 'answered', 'structured' => 'answered', 'faq' => 'answered', 'contact' => 'answered', 'clarify' => 'clarify', 'not_found' => 'missed', 'rephrase' => 'missed', 'rejected' => 'rejected' );
		if ( ! isset( $map[ $mode ] ) ) {
			return;
		}
		$days = get_option( 'wia_daily_stats', array() );
		$days = is_array( $days ) ? $days : array();
		$day  = gmdate( 'Y-m-d' );
		$key  = $map[ $mode ];
		$days[ $day ][ $key ] = ( isset( $days[ $day ][ $key ] ) ? (int) $days[ $day ][ $key ] : 0 ) + 1;
		if ( count( $days ) > self::KEEP_DAYS ) {
			ksort( $days );
			$days = array_slice( $days, -self::KEEP_DAYS, null, true );
		}
		update_option( 'wia_daily_stats', $days, false );
	}

	/** Words visitors asked about that this site does not contain. */
	public static function record_gaps( $words ) {
		$words = array_filter( array_map( function ( $w ) { return mb_substr( trim( (string) $w ), 0, 40 ); }, (array) $words ), 'strlen' );
		if ( ! $words ) {
			return;
		}
		$gaps = get_option( 'wia_gaps', array() );
		$gaps = is_array( $gaps ) ? $gaps : array();
		foreach ( $words as $w ) {
			$gaps[ $w ] = array( isset( $gaps[ $w ][0] ) ? (int) $gaps[ $w ][0] + 1 : 1, gmdate( 'Y-m-d' ) );
		}
		if ( count( $gaps ) > self::MAX_GAPS ) {
			uasort( $gaps, function ( $a, $b ) { return $b[0] <=> $a[0] ?: strcmp( $b[1], $a[1] ); } );
			$gaps = array_slice( $gaps, 0, self::MAX_GAPS, true );
		}
		update_option( 'wia_gaps', $gaps, false );
	}

	/**
	 * Gap words the site still does not know, most asked first. A word disappears from the list once content
	 * containing it is published.
	 */
	public static function open_gaps( $limit = 30 ) {
		$gaps = get_option( 'wia_gaps', array() );
		$out  = array();
		if ( ! is_array( $gaps ) || ! $gaps ) {
			return $out;
		}
		uasort( $gaps, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		global $wpdb;
		// A word visitors already taught the assistant is answered, so it is no longer a gap.
		$learned = array_flip( (array) $wpdb->get_col( 'SELECT phrase FROM ' . WIA_DB::t( 'aliases' ) . " WHERE status = 'active'" ) ); // phpcs:ignore WordPress.DB
		foreach ( $gaps as $word => $row ) {
			$tokens = WIA_Text::tokenize( (string) $word );
			if ( $tokens && ! isset( $learned[ self::phrase( $tokens ) ] ) && ! WIA_Knowledge::knows( $tokens ) ) {
				$out[] = array( 'word' => (string) $word, 'count' => (int) $row[0], 'last' => (string) $row[1] );
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/** Totals over the last $days days. */
	public static function totals( $days = 30 ) {
		$all   = get_option( 'wia_daily_stats', array() );
		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		$out   = array( 'answered' => 0, 'clarify' => 0, 'missed' => 0, 'rejected' => 0 );
		foreach ( is_array( $all ) ? $all : array() as $day => $row ) {
			if ( $day > $since ) {
				foreach ( $out as $k => $v ) {
					$out[ $k ] += isset( $row[ $k ] ) ? (int) $row[ $k ] : 0;
				}
			}
		}
		$asked       = $out['answered'] + $out['clarify'] + $out['missed'];
		$out['asked'] = $asked;
		$out['rate']  = $asked ? round( ( $out['answered'] + $out['clarify'] ) / $asked, 3 ) : null;
		return $out;
	}

	/** One snapshot per day of how much the assistant knows, for the growth chart. */
	public static function snapshot() {
		global $wpdb;
		$history = get_option( 'wia_history', array() );
		$history = is_array( $history ) ? $history : array();
		$stats   = WIA_Knowledge::stats();
		$active  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WIA_DB::t( 'aliases' ) . " WHERE status = 'active'" ); // phpcs:ignore WordPress.DB
		$history[ gmdate( 'Y-m-d' ) ] = array(
			'docs'    => isset( $stats['documents']['active'] ) ? (int) $stats['documents']['active'] : 0,
			'terms'   => WIA_Knowledge::vocabulary(),
			'learned' => $active,
		);
		ksort( $history );
		update_option( 'wia_history', array_slice( $history, -self::KEEP_DAYS, null, true ), false );
	}

	public static function aliases( $status = '' ) {
		global $wpdb;
		$where = '' !== $status ? $wpdb->prepare( 'WHERE a.status = %s', $status ) : '';
		return (array) $wpdb->get_results( 'SELECT a.*, d.title, d.url FROM ' . WIA_DB::t( 'aliases' ) . ' a LEFT JOIN ' . WIA_DB::t( 'documents' ) . " d ON d.id = a.doc_id {$where} ORDER BY a.status = 'active' DESC, a.votes DESC, a.id DESC LIMIT 200", ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function set_alias_status( $id, $status ) {
		global $wpdb;
		if ( 'delete' === $status ) {
			$wpdb->delete( WIA_DB::t( 'aliases' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		} elseif ( in_array( $status, array( 'active', 'rejected', 'candidate' ), true ) ) {
			$wpdb->update( WIA_DB::t( 'aliases' ), array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB
		}
	}

	/** The owner teaches a phrase directly: «کیف» means this page. */
	public static function teach( $words_text, $doc_id ) {
		global $wpdb;
		$phrase = self::phrase( WIA_Text::tokenize( $words_text ) );
		if ( '' === $phrase || $doc_id <= 0 ) {
			return false;
		}
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( 'INSERT INTO ' . WIA_DB::t( 'aliases' ) . " (phrase, doc_id, votes, status, created_at, updated_at) VALUES (%s, %d, 0, 'active', %s, %s) ON DUPLICATE KEY UPDATE status = 'active', updated_at = VALUES(updated_at)", $phrase, $doc_id, $now, $now ) ); // phpcs:ignore WordPress.DB
		return true;
	}
}

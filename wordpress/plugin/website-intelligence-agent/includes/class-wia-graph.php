<?php
/**
 * The knowledge map: what the assistant knows, drawn as a graph.
 *
 * Nodes are the site's main topics (words found in many pages), the pages that cover them, the words visitors
 * asked about that the site lacks, and topics that reference sites cover but this site does not. Links between
 * topics mean they appear in the same pages. Everything comes from the index, so the map shows real knowledge,
 * not an impression of it. It is built on demand and cached until the next scan.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Graph {
	const CACHE      = 'wia_graph';
	const MAX_TOPICS = 60;
	const MAX_DOCS   = 60;

	public static function forget() {
		delete_option( self::CACHE );
	}

	public static function get( $rebuild = false ) {
		$cached = get_option( self::CACHE );
		if ( ! $rebuild && is_array( $cached ) && isset( $cached['built'] ) && $cached['built'] > time() - DAY_IN_SECONDS ) {
			return $cached;
		}
		$graph = self::build();
		update_option( self::CACHE, $graph, false );
		return $graph;
	}

	/** Terms ranked by the number of documents that contain them. */
	private static function topic_terms( $n_docs ) {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT p.term, COUNT(DISTINCT c.doc_id) df FROM ' . WIA_DB::t( 'postings' ) . ' p JOIN ' . WIA_DB::t( 'chunks' ) . ' c ON c.id = p.chunk_id JOIN ' . WIA_DB::t( 'documents' ) . " d ON d.id = c.doc_id WHERE d.status = 'active' GROUP BY p.term ORDER BY df DESC LIMIT 600" ); // phpcs:ignore WordPress.DB
		$soft = WIA_Text::soft_stems();
		$df   = array();
		foreach ( (array) $rows as $r ) {
			$t = (string) $r->term;
			// Digits, single letters, question words and boilerplate found on most pages are not topics.
			if ( isset( $soft[ $t ] ) || preg_match( '/^[\d.+\-]+$/', $t ) || mb_strlen( $t ) < 2 || ( $n_docs >= 10 && $r->df > 0.6 * $n_docs ) ) {
				continue;
			}
			$df[ $t ] = (int) $r->df;
		}
		// The index also holds joined pairs of short words (کوله + پشتی). Drawing both would show one topic twice.
		foreach ( array_keys( $df ) as $t ) {
			$len = mb_strlen( $t );
			for ( $i = 2; $i <= $len - 2; $i++ ) {
				if ( isset( $df[ mb_substr( $t, 0, $i ) ], $df[ mb_substr( $t, $i ) ] ) ) {
					unset( $df[ $t ] );
					break;
				}
			}
		}
		$min = $n_docs >= 4 ? 2 : 1;
		return array_slice( array_filter( $df, function ( $f ) use ( $min ) { return $f >= $min; } ), 0, self::MAX_TOPICS, true );
	}

	public static function build() {
		global $wpdb;
		$p     = WIA_DB::t( 'postings' );
		$ch    = WIA_DB::t( 'chunks' );
		$d     = WIA_DB::t( 'documents' );
		$stats = WIA_Knowledge::stats();
		$n     = isset( $stats['documents']['active'] ) ? (int) $stats['documents']['active'] : 0;
		$nodes = array();
		$edges = array();
		$topic = self::topic_terms( $n );

		if ( $topic ) {
			$terms = array_keys( $topic );
			$place = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
			foreach ( $topic as $t => $f ) {
				$nodes[] = array( 'id' => 't:' . $t, 'label' => $t, 'kind' => 'topic', 'weight' => $f );
			}
			// Topics that share pages. Strength is normalised so that common words do not link to everything.
			$pairs = $wpdb->get_results( $wpdb->prepare( "SELECT a.term ta, b.term tb, COUNT(DISTINCT ca.doc_id) n FROM {$p} a JOIN {$p} b ON b.chunk_id = a.chunk_id AND a.term < b.term JOIN {$ch} ca ON ca.id = a.chunk_id JOIN {$d} dd ON dd.id = ca.doc_id AND dd.status = 'active' WHERE a.term IN ($place) AND b.term IN ($place) GROUP BY a.term, b.term", array_merge( $terms, $terms ) ) ); // phpcs:ignore WordPress.DB
			$per = array();
			foreach ( (array) $pairs as $r ) {
				if ( (int) $r->n < 2 && $n >= 4 ) {
					continue;
				}
				$s = (int) $r->n / sqrt( $topic[ $r->ta ] * $topic[ $r->tb ] );
				$per[ $r->ta ][] = array( $r->tb, $s, (int) $r->n );
				$per[ $r->tb ][] = array( $r->ta, $s, (int) $r->n );
			}
			$seen = array();
			foreach ( $per as $t => $list ) {
				usort( $list, function ( $x, $y ) { return $y[1] <=> $x[1]; } );
				foreach ( array_slice( $list, 0, 4 ) as $e ) {
					$key = $t < $e[0] ? $t . '|' . $e[0] : $e[0] . '|' . $t;
					if ( ! isset( $seen[ $key ] ) ) {
						$seen[ $key ] = 1;
						$edges[]      = array( 's' => 't:' . $t, 't' => 't:' . $e[0], 'w' => round( $e[1], 3 ), 'kind' => 'related' );
					}
				}
			}

			// Pages, each linked to the topics it says the most about.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.doc_id, p.term, SUM(p.tf) tf FROM {$p} p JOIN {$ch} c ON c.id = p.chunk_id JOIN {$d} dd ON dd.id = c.doc_id AND dd.status = 'active' WHERE p.term IN ($place) GROUP BY c.doc_id, p.term", $terms ) ); // phpcs:ignore WordPress.DB
			$docs = array();
			foreach ( (array) $rows as $r ) {
				$docs[ (int) $r->doc_id ][ $r->term ] = (float) $r->tf * log( 1 + $n / max( 1, $topic[ $r->term ] ) );
			}
			uasort( $docs, function ( $x, $y ) { return count( $y ) <=> count( $x ) ?: array_sum( $y ) <=> array_sum( $x ); } );
			$docs = array_slice( $docs, 0, self::MAX_DOCS, true );
			if ( $docs ) {
				$ids  = implode( ',', array_map( 'intval', array_keys( $docs ) ) );
				$meta = array();
				foreach ( (array) $wpdb->get_results( "SELECT id, title, url, content_type FROM {$d} WHERE id IN ($ids)" ) as $r ) { // phpcs:ignore WordPress.DB
					$meta[ (int) $r->id ] = $r;
				}
				foreach ( $docs as $id => $weights ) {
					if ( ! isset( $meta[ $id ] ) ) {
						continue;
					}
					arsort( $weights );
					$nodes[] = array( 'id' => 'd:' . $id, 'label' => wp_strip_all_tags( (string) $meta[ $id ]->title ), 'kind' => 'doc', 'type' => (string) $meta[ $id ]->content_type, 'url' => (string) $meta[ $id ]->url, 'weight' => count( $weights ) );
					foreach ( array_slice( array_keys( $weights ), 0, 3 ) as $t ) {
						$edges[] = array( 's' => 'd:' . $id, 't' => 't:' . $t, 'w' => 1, 'kind' => 'covers' );
					}
				}
			}
		}

		// Learned phrases point from the visitor's word to the page they settled on.
		foreach ( WIA_Learn::aliases( 'active' ) as $a ) {
			$nodes[] = array( 'id' => 'a:' . $a['id'], 'label' => (string) $a['phrase'], 'kind' => 'learned', 'weight' => (int) $a['votes'] );
			if ( ! self::has_node( $nodes, 'd:' . $a['doc_id'] ) && $a['title'] ) {
				$nodes[] = array( 'id' => 'd:' . $a['doc_id'], 'label' => wp_strip_all_tags( (string) $a['title'] ), 'kind' => 'doc', 'type' => '', 'url' => (string) $a['url'], 'weight' => 1 );
			}
			$edges[] = array( 's' => 'a:' . $a['id'], 't' => 'd:' . $a['doc_id'], 'w' => 1, 'kind' => 'learned' );
		}
		foreach ( WIA_Learn::open_gaps( 20 ) as $i => $g ) {
			$nodes[] = array( 'id' => 'g:' . $i, 'label' => $g['word'], 'kind' => 'gap', 'weight' => $g['count'] );
		}
		foreach ( WIA_Reference::missing_topics( 15 ) as $i => $r ) {
			$nodes[] = array( 'id' => 'r:' . $i, 'label' => $r['term'], 'kind' => 'reference', 'weight' => $r['sources'] );
		}

		$totals = WIA_Learn::totals( 30 );
		return array(
			'built'  => time(),
			'nodes'  => $nodes,
			'edges'  => $edges,
			'stats'  => array(
				'docs'       => $n,
				'chunks'     => (int) $stats['chunks'],
				'vocabulary' => WIA_Knowledge::vocabulary(),
				'topics'     => count( $topic ),
				'learned'    => count( WIA_Learn::aliases( 'active' ) ),
				'candidates' => count( WIA_Learn::aliases( 'candidate' ) ),
				'gaps'       => count( WIA_Learn::open_gaps( 300 ) ),
				'asked'      => $totals['asked'],
				'rate'       => $totals['rate'],
				'rejected'   => $totals['rejected'],
				'refs'       => WIA_Reference::summary(),
			),
		);
	}

	private static function has_node( $nodes, $id ) {
		foreach ( $nodes as $node ) {
			if ( $node['id'] === $id ) {
				return true;
			}
		}
		return false;
	}
}

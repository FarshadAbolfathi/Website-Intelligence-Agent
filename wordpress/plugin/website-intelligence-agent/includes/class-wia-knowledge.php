<?php
/**
 * Knowledge store and BM25 search over MySQL postings. A query reads only the postings of its own terms, so memory
 * use stays flat as the site grows. Ranking follows the Python engine: k1 1.4, b 0.7, weighted title terms and a
 * coverage gate in which a query word unknown to this site blocks a confident answer.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Knowledge {
	const K1           = 1.4;
	const B            = 0.7;
	const MIN_COVERAGE = 0.6;
	const CHUNK_CHARS  = 900;

	public static function chunk( $sections ) {
		$out = array();
		foreach ( $sections as $section ) {
			$text = trim( (string) $section['text'] );
			if ( '' === $text ) {
				continue;
			}
			$buf = '';
			foreach ( explode( "\n", $text ) as $para ) {
				$parts = mb_strlen( $para ) <= self::CHUNK_CHARS ? array( $para ) : WIA_Text::sentences( $para );
				foreach ( $parts as $part ) {
					if ( '' !== $buf && mb_strlen( $buf ) + mb_strlen( $part ) + 1 > self::CHUNK_CHARS ) {
						$out[]    = array( 'heading' => $section['heading'], 'text' => $buf );
						$last     = WIA_Text::sentences( $buf );
						$tail     = mb_strlen( $buf ) > self::CHUNK_CHARS * 0.6 ? (string) end( $last ) : '';
						$buf      = mb_strlen( $tail ) < self::CHUNK_CHARS * 0.3 ? $tail : '';
					}
					$buf = '' === $buf ? $part : $buf . "\n" . $part;
				}
			}
			if ( '' !== $buf ) {
				$out[] = array( 'heading' => $section['heading'], 'text' => $buf );
			}
		}
		return $out;
	}

	public static function find( $type, $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WIA_DB::t( 'documents' ) . ' WHERE source_type = %s AND source_id = %d', $type, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Save a document and its chunks. Identical content is left alone.
	 *
	 * @return string new | updated | unchanged | duplicate
	 */
	public static function save( $doc, $chunks, $boost = '' ) {
		global $wpdb;
		$body_hash = md5( WIA_Text::normalize( implode( "\n", wp_list_pluck( $chunks, 'text' ) ) ) );
		$hash      = md5( $doc['title'] . "\n" . $body_hash . "\n" . $boost );
		$old       = self::find( $doc['source_type'], $doc['source_id'] );
		if ( $old && $old['content_hash'] === $hash && 'removed' !== $old['status'] ) {
			$wpdb->update( WIA_DB::t( 'documents' ), array( 'crawl_date' => current_time( 'mysql', true ), 'url' => $doc['url'] ), array( 'id' => $old['id'] ) ); // phpcs:ignore WordPress.DB
			return 'unchanged';
		}
		$row = array(
			'source_type'   => $doc['source_type'],
			'source_id'     => (int) $doc['source_id'],
			'url'           => $doc['url'],
			'title'         => $doc['title'],
			'content_type'  => $doc['content_type'],
			'language'      => $doc['language'],
			'content_hash'  => $hash,
			'body_hash'     => $body_hash,
			'status'        => 'active',
			'dup_of'        => 0,
			'last_modified' => isset( $doc['last_modified'] ) ? $doc['last_modified'] : null,
			'crawl_date'    => current_time( 'mysql', true ),
		);
		if ( $old ) {
			$row['version'] = (int) $old['version'] + 1;
			$wpdb->update( WIA_DB::t( 'documents' ), $row, array( 'id' => $old['id'] ) ); // phpcs:ignore WordPress.DB
			$doc_id = (int) $old['id'];
			self::delete_chunks( $doc_id );
		} else {
			$wpdb->insert( WIA_DB::t( 'documents' ), $row ); // phpcs:ignore WordPress.DB
			$doc_id = (int) $wpdb->insert_id;
		}

		foreach ( $chunks as $i => $chunk ) {
			$language = WIA_Text::detect_language( $chunk['text'], $doc['language'] );
			$counts   = WIA_Text::term_counts( $chunk['heading'], $chunk['text'], $boost, $language );
			$wpdb->insert( // phpcs:ignore WordPress.DB
				WIA_DB::t( 'chunks' ),
				array( 'doc_id' => $doc_id, 'ord' => $i, 'heading' => $chunk['heading'], 'body' => $chunk['text'], 'language' => $language, 'length' => (int) array_sum( $counts ) )
			);
			self::insert_postings( (int) $wpdb->insert_id, $counts );
		}

		if ( 'product' !== $doc['content_type'] && $chunks ) {
			$dup = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . WIA_DB::t( 'documents' ) . " WHERE status = 'active' AND id <> %d AND body_hash = %s AND content_type <> 'product' ORDER BY id LIMIT 1", $doc_id, $body_hash ) ); // phpcs:ignore WordPress.DB
			if ( $dup ) {
				$wpdb->update( WIA_DB::t( 'documents' ), array( 'status' => 'duplicate', 'dup_of' => $dup ), array( 'id' => $doc_id ) ); // phpcs:ignore WordPress.DB
				return 'duplicate';
			}
		}
		return $old ? 'updated' : 'new';
	}

	private static function insert_postings( $chunk_id, $counts ) {
		global $wpdb;
		$table = WIA_DB::t( 'postings' );
		foreach ( array_chunk( $counts, 200, true ) as $batch ) {
			$place = array();
			$args  = array();
			foreach ( $batch as $term => $tf ) {
				$place[] = '(%s,%d,%f)';
				$args[]  = mb_substr( (string) $term, 0, 80 );
				$args[]  = $chunk_id;
				$args[]  = $tf;
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (term, chunk_id, tf) VALUES " . implode( ',', $place ), $args ) ); // phpcs:ignore WordPress.DB
		}
	}

	private static function delete_chunks( $doc_id ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . WIA_DB::t( 'chunks' ) . ' WHERE doc_id = %d', $doc_id ) ); // phpcs:ignore WordPress.DB
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( 'DELETE FROM ' . WIA_DB::t( 'postings' ) . " WHERE chunk_id IN ($in)" ); // phpcs:ignore WordPress.DB
			$wpdb->query( 'DELETE FROM ' . WIA_DB::t( 'chunks' ) . " WHERE id IN ($in)" ); // phpcs:ignore WordPress.DB
		}
	}

	public static function remove( $type, $id ) {
		global $wpdb;
		$doc = self::find( $type, $id );
		if ( ! $doc || 'removed' === $doc['status'] ) {
			return false;
		}
		self::delete_chunks( (int) $doc['id'] );
		$wpdb->update( WIA_DB::t( 'documents' ), array( 'status' => 'removed', 'crawl_date' => current_time( 'mysql', true ) ), array( 'id' => $doc['id'] ) ); // phpcs:ignore WordPress.DB
		return true;
	}

	private static function corpus_stats() {
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT COUNT(*) n, AVG(c.length) a FROM ' . WIA_DB::t( 'chunks' ) . ' c JOIN ' . WIA_DB::t( 'documents' ) . " d ON d.id = c.doc_id WHERE d.status = 'active'" ); // phpcs:ignore WordPress.DB
		return array( (int) $row->n, (float) $row->a ?: 1.0 );
	}

	private static function fix_typo( $term, $n ) {
		global $wpdb;
		$len = mb_strlen( $term );
		if ( $len < 5 ) {
			return '';
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT term, COUNT(*) df FROM ' . WIA_DB::t( 'postings' ) . ' WHERE term LIKE %s AND CHAR_LENGTH(term) BETWEEN %d AND %d GROUP BY term LIMIT 2000', $wpdb->esc_like( mb_substr( $term, 0, 1 ) ) . '%', $len - 1, $len + 1 ) ); // phpcs:ignore WordPress.DB
		$best    = '';
		$best_df = 0;
		foreach ( (array) $rows as $row ) {
			if ( (int) $row->df <= max( 6, (int) ( $n * 0.02 ) ) && (int) $row->df > $best_df && WIA_Text::edit_within_one( $row->term, $term ) ) {
				$best    = $row->term;
				$best_df = (int) $row->df;
			}
		}
		return $best;
	}

	private static function document_frequency( $terms ) {
		global $wpdb;
		if ( ! $terms ) {
			return array();
		}
		$place = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		$sql   = 'SELECT p.term, COUNT(*) df FROM ' . WIA_DB::t( 'postings' ) . ' p JOIN ' . WIA_DB::t( 'chunks' ) . ' c ON c.id = p.chunk_id JOIN ' . WIA_DB::t( 'documents' ) . " d ON d.id = c.doc_id WHERE d.status = 'active' AND p.term IN ($place) GROUP BY p.term";
		$df    = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $terms ) ) as $row ) { // phpcs:ignore WordPress.DB
			$df[ $row->term ] = (int) $row->df;
		}
		return $df;
	}

	/** @return array of hits: chunk_id, doc_id, score, coverage, heading, body, language, url, title, content_type, source_type, source_id, crawl_date */
	public static function search( $query, $limit = 5 ) {
		global $wpdb;
		$terms = WIA_Text::query_terms( $query );
		if ( ! $terms ) {
			return array();
		}
		list( $n, $avg ) = self::corpus_stats();
		if ( ! $n ) {
			return array();
		}
		$df   = self::document_frequency( $terms );
		$base = array_values( array_intersect( $terms, array_unique( WIA_Text::tokenize( $query ) ) ) );
		$changed = false;
		foreach ( $base as $i => $term ) {
			if ( ! isset( $df[ $term ] ) ) {
				$fixed = self::fix_typo( $term, $n );
				if ( '' !== $fixed ) {
					$terms[ array_search( $term, $terms, true ) ] = $fixed;
					$base[ $i ] = $fixed;
					$changed    = true;
				}
			}
		}
		if ( $changed ) {
			$terms = array_values( array_unique( $terms ) );
			$df    = self::document_frequency( $terms );
		}
		if ( ! $df ) {
			return array();
		}
		$idf = array();
		foreach ( $df as $term => $f ) {
			$idf[ $term ] = log( 1 + ( $n - $f + 0.5 ) / ( $f + 0.5 ) );
		}
		$soft    = WIA_Text::soft_stems();
		$subject = array_values( array_filter( $base, function ( $w ) use ( $soft ) { return ! isset( $soft[ $w ] ); } ) );
		$subject = $subject ? $subject : $base;
		$weights = array();
		foreach ( $subject as $w ) {
			$weights[ $w ] = isset( $idf[ $w ] ) ? $idf[ $w ] : log( 1 + $n ) + 1;
		}
		if ( ! $weights ) {
			$weights = array_fill_keys( array_keys( $df ), 1.0 );
		}
		$total = array_sum( $weights ) ?: 1.0;

		$keys  = array_map( 'strval', array_keys( $df ) );
		$place = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql   = 'SELECT p.chunk_id, p.term, p.tf, c.length FROM ' . WIA_DB::t( 'postings' ) . ' p JOIN ' . WIA_DB::t( 'chunks' ) . ' c ON c.id = p.chunk_id JOIN ' . WIA_DB::t( 'documents' ) . " d ON d.id = c.doc_id WHERE d.status = 'active' AND p.term IN ($place)";
		$scores  = array();
		$matched = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $keys ) ) as $row ) { // phpcs:ignore WordPress.DB
			$norm = $row->tf * ( self::K1 + 1 ) / ( $row->tf + self::K1 * ( 1 - self::B + self::B * $row->length / $avg ) );
			$id   = (int) $row->chunk_id;
			$scores[ $id ]               = ( isset( $scores[ $id ] ) ? $scores[ $id ] : 0 ) + $idf[ $row->term ] * $norm;
			$matched[ $id ][ $row->term ] = 1;
		}
		arsort( $scores );
		$top = array_slice( array_keys( $scores ), 0, $limit, true );
		if ( ! $top ) {
			return array();
		}
		$in   = implode( ',', array_map( 'intval', $top ) );
		$rows = $wpdb->get_results( 'SELECT c.id chunk_id, c.doc_id, c.heading, c.body, c.language, d.url, d.title, d.content_type, d.source_type, d.source_id, d.crawl_date FROM ' . WIA_DB::t( 'chunks' ) . ' c JOIN ' . WIA_DB::t( 'documents' ) . " d ON d.id = c.doc_id WHERE c.id IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB
		$by   = array();
		foreach ( (array) $rows as $row ) {
			$by[ (int) $row['chunk_id'] ] = $row;
		}
		$out = array();
		foreach ( $top as $id ) {
			if ( ! isset( $by[ $id ] ) ) {
				continue;
			}
			$hit = 0.0;
			foreach ( $weights as $w => $v ) {
				if ( isset( $matched[ $id ][ $w ] ) ) {
					$hit += $v;
				}
			}
			$by[ $id ]['score']    = round( $scores[ $id ], 4 );
			$by[ $id ]['coverage'] = round( $hit / $total, 4 );
			$out[]                 = $by[ $id ];
		}
		return $out;
	}

	public static function stats() {
		global $wpdb;
		$d   = WIA_DB::t( 'documents' );
		$out = array( 'documents' => array(), 'types' => array(), 'chunks' => 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$d} GROUP BY status" ) as $r ) { // phpcs:ignore WordPress.DB
			$out['documents'][ $r->status ] = (int) $r->n;
		}
		foreach ( (array) $wpdb->get_results( "SELECT content_type, COUNT(*) n FROM {$d} WHERE status = 'active' GROUP BY content_type" ) as $r ) { // phpcs:ignore WordPress.DB
			$out['types'][ $r->content_type ] = (int) $r->n;
		}
		$out['chunks'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WIA_DB::t( 'chunks' ) . " c JOIN {$d} d ON d.id = c.doc_id WHERE d.status = 'active'" ); // phpcs:ignore WordPress.DB
		return $out;
	}
}

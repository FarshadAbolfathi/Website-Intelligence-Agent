<?php
// A site larger than typical shared-hosting sites, inserted without hooks, then scanned in batches. Answers a concrete
// question: does a search still work with flat memory, and does any single batch stay within a host's time limit?
require '/tests/harness.php';
global $wpdb;

remove_action( 'save_post', array( 'WIA_Ingest', 'on_save' ), 30 );
$words = explode( ' ', 'گوشی لپ تاپ ارسال سفارش گارانتی باتری دوربین حافظه پردازنده کیفیت shipping warranty battery camera memory laptop phone order return policy ' );
mt_srand( 42 );
for ( $i = 1; $i <= 3000; $i++ ) {
	$body = '';
	for ( $p = 0; $p < 4; $p++ ) {
		$sentence = array();
		for ( $w = 0; $w < 40; $w++ ) {
			$sentence[] = $words[ mt_rand( 0, count( $words ) - 1 ) ] . mt_rand( 0, 60 );
		}
		$body .= '<p>' . implode( ' ', $sentence ) . '.</p>';
	}
	if ( 2717 === $i ) {
		$body .= '<p>The rare marmalade calibration procedure takes exactly seventeen minutes.</p>';
	}
	$wpdb->insert( $wpdb->posts, array( 'post_title' => "Scale article $i", 'post_name' => "scale-$i", 'post_content' => $body, 'post_type' => 'post', 'post_status' => 'publish', 'post_author' => 1, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ), 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '' ) );
}

WIA_Ingest::start_scan();
$longest = 0.0;
$batches = 0;
$started = microtime( true );
do {
	$t    = microtime( true );
	$scan = WIA_Ingest::run_batch();
	$longest = max( $longest, microtime( true ) - $t );
	$batches++;
	delete_transient( 'wia_scan_lock' );
	wp_cache_flush();
} while ( 'running' === $scan['state'] && $batches < 2000 );
$elapsed = microtime( true ) - $started;

$stats = WIA_Knowledge::stats();
echo sprintf( "info documents=%d chunks=%d batches=%d total=%.1fs longest_batch=%.2fs\n", $stats['documents']['active'], $stats['chunks'], $batches, $elapsed, $longest );
$postings = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WIA_DB::t( 'postings' ) );
$size = $wpdb->get_var( $wpdb->prepare( 'SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE %s', $wpdb->prefix . 'wia\_%' ) );
echo "info postings=$postings tables_mb=$size\n";

ok( 'done' === $scan['state'], 'scan of a 3000-article site finishes', $scan );
ok( $longest < 30, 'no batch comes near a 30-second PHP limit', $longest );
ok( $stats['documents']['active'] >= 3000, 'all articles learned', $stats );

$before = memory_get_peak_usage( true );
$t = microtime( true );
$runs = 0;
foreach ( array( 'marmalade calibration procedure', 'rare marmalade seventeen minutes', 'battery12 warranty30', 'گوشی7 گارانتی20' ) as $q ) {
	WIA_Knowledge::search( $q, 5 );
	$runs++;
}
$query_time = ( microtime( true ) - $t ) / $runs;
$used = ( memory_get_peak_usage( true ) - $before ) / 1048576;
echo sprintf( "info search_avg=%.0fms extra_peak_memory=%.1fMB\n", $query_time * 1000, $used );
$hits = WIA_Knowledge::search( 'marmalade calibration procedure', 5 );
ok( $hits && false !== strpos( $hits[0]['body'], 'seventeen minutes' ), 'the single relevant article is found among 3000', $hits ? $hits[0]['title'] : '' );
ok( $query_time < 2.0, 'search stays under two seconds on average', $query_time );
ok( $used < 64, 'search does not load the whole index into memory', $used );

add_action( 'save_post', array( 'WIA_Ingest', 'on_save' ), 30, 2 );
finish();

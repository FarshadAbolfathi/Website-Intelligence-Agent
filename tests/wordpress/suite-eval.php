<?php
// The shared evaluation dataset (shared/eval), played through converse() exactly as the REST endpoint does.
// This file only produces answers. They are scored by core/evaluation/dataset.py, the same code that scores the
// Self-Hosted edition, so the two editions are compared on one definition of "correct".
require '/tests/harness.php';
global $wpdb;

// WIA_EVAL_SET=holdout runs the held-out set instead of the development set.
$set   = 'holdout' === getenv( 'WIA_EVAL_SET' ) ? 'holdout' : 'dev';
$dir   = 'holdout' === $set ? '/shared/eval/holdout/' : '/shared/eval/';
$site  = json_decode( (string) file_get_contents( $dir . 'site.json' ), true );
$cases = json_decode( (string) file_get_contents( $dir . 'cases.json' ), true );

foreach ( get_posts( array( 'post_type' => array( 'post', 'page', 'product' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( array( 'documents', 'chunks', 'postings', 'faqs', 'aliases', 'conversations', 'messages', 'ref_sources', 'ref_terms' ) as $table ) {
	$wpdb->query( 'TRUNCATE TABLE ' . WIA_DB::t( $table ) ); // phpcs:ignore WordPress.DB
}
delete_option( 'wia_gaps' );
update_option( 'woocommerce_currency', $site['currency'] );
$s              = WIA_DB::settings();
$s['contact']   = array_merge( array( 'phone' => '', 'email' => '', 'whatsapp' => '', 'telegram' => '', 'page' => '', 'hours' => '' ), $site['contact'] );
$s['not_found'] = array();
$s['starters']  = array();
$s['auto_learn'] = 0;
update_option( 'wia_settings', $s, false );
WIA_Chat::forget_starters();

foreach ( $site['pages'] as $p ) {
	page( $p['title'], $p['html'], $p['type'] );
}
foreach ( $site['products'] as $row ) {
	$product = new WC_Product_Simple();
	$product->set_name( $row['name'] );
	$product->set_regular_price( $row['price'] );
	$product->set_description( '<p>' . $row['body'] . '</p>' );
	$product->set_status( 'publish' );
	$product->set_stock_status( $row['stock'] );
	$product->save();
}
foreach ( $site['faqs'] as $faq ) {
	$wpdb->insert( WIA_DB::t( 'faqs' ), array( 'question' => $faq['question'], 'answer' => $faq['answer'], 'keywords' => $faq['keywords'], 'language' => '', 'created_at' => current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB
}
$scan = run_scan();
ok( 'done' === $scan['state'], 'evaluation site scanned', $scan );

$turns = array();
foreach ( $cases['cases'] as $case ) {
	$state = array();
	foreach ( $case['turns'] as $index => $turn ) {
		$started = microtime( true );
		$answer  = WIA_Chat::converse( $turn['q'], $state );
		$turns[] = array(
			'case'       => $case['id'],
			'turn'       => $index,
			'q'          => $turn['q'],
			'text'       => $answer['text'],
			'mode'       => $answer['mode'],
			'language'   => $answer['language'],
			'sources'    => array_values( array_map( function ( $s ) { return wp_strip_all_tags( $s['title'] ); }, $answer['sources'] ) ),
			'latency_ms' => (int) round( 1000 * ( microtime( true ) - $started ) ),
		);
	}
}
// One line, picked up by run.sh and handed to the scorer.
echo 'WIA_EVAL_JSON ' . wp_json_encode( array( 'edition' => 'lite', 'set' => $set, 'config' => array( 'llm' => 'none' ), 'turns' => $turns ), JSON_UNESCAPED_UNICODE ) . "\n";
ok( count( $turns ) > 0, 'evaluation turns produced', count( $turns ) );
finish();

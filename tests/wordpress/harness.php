<?php
// Minimal assertions. Run through `wp eval-file`, so these tests use a real WordPress and real MySQL.
$GLOBALS['wia_fail'] = 0;
$GLOBALS['wia_pass'] = 0;

function ok( $condition, $name, $detail = '' ) {
	if ( $condition ) {
		$GLOBALS['wia_pass']++;
		echo "ok   $name\n";
	} else {
		$GLOBALS['wia_fail']++;
		echo "FAIL $name" . ( '' !== $detail ? ': ' . mb_substr( is_string( $detail ) ? $detail : wp_json_encode( $detail, JSON_UNESCAPED_UNICODE ), 0, 400 ) : '' ) . "\n";
	}
}

function finish() {
	echo "--- {$GLOBALS['wia_pass']} passed, {$GLOBALS['wia_fail']} failed\n";
	if ( $GLOBALS['wia_fail'] ) {
		exit( 1 );
	}
}

function run_scan() {
	WIA_Ingest::start_scan();
	for ( $i = 0; $i < 400; $i++ ) {
		$scan = WIA_Ingest::run_batch();
		if ( 'running' !== $scan['state'] ) {
			return $scan;
		}
		delete_transient( 'wia_scan_lock' );
	}
	return $scan;
}

function page( $title, $content, $type = 'page', $status = 'publish' ) {
	return wp_insert_post( array( 'post_title' => $title, 'post_content' => $content, 'post_type' => $type, 'post_status' => $status ) );
}

function rest_chat( $message, $conversation = '' ) {
	$request = new WP_REST_Request( 'POST', '/wia/v1/chat' );
	$request->set_body_params( array( 'message' => $message, 'conversation_id' => $conversation ) );
	return rest_do_request( $request );
}

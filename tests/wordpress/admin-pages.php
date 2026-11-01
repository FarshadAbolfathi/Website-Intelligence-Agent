<?php
// Renders every admin tab as a real administrator and as a subscriber. Escaping is checked with hostile data.
require '/tests/harness.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once WIA_DIR . 'includes/class-wia-admin.php';

global $wpdb;
$evil = '<img src=x onerror=alert(1)>';
$wpdb->insert( WIA_DB::t( 'faqs' ), array( 'question' => $evil, 'answer' => $evil, 'keywords' => '', 'language' => '', 'created_at' => current_time( 'mysql', true ) ) );
wp_insert_post( array( 'post_title' => 'Stored ' . $evil, 'post_content' => '<p>This page has a hostile title used to test escaping in the admin tables here.</p>', 'post_type' => 'page', 'post_status' => 'publish' ) );
$wpdb->insert( WIA_DB::t( 'conversations' ), array( 'id' => 'adminpagetest0001', 'state' => '{}', 'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ) ) );
$wpdb->insert( WIA_DB::t( 'messages' ), array( 'conversation_id' => 'adminpagetest0001', 'role' => 'user', 'body' => $evil, 'mode' => 'not_found', 'created_at' => current_time( 'mysql', true ) ) );
$wpdb->query( 'UPDATE ' . WIA_DB::t( 'documents' ) . ' SET title = ' . $wpdb->prepare( '%s', $evil ) . ' WHERE id = (SELECT id FROM (SELECT MAX(id) id FROM ' . WIA_DB::t( 'documents' ) . ') x)' );

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
foreach ( array( 'status', 'knowledge', 'faq', 'widget', 'chats', 'advanced' ) as $tab ) {
	$_GET = array( 'page' => WIA_Admin::SLUG, 'tab' => $tab );
	ob_start();
	WIA_Admin::page();
	$html = ob_get_clean();
	ok( strlen( $html ) > 300 && false !== strpos( $html, 'nav-tab-active' ), "tab renders: $tab", strlen( $html ) );
	ok( false === strpos( $html, '<img src=x' ), "hostile data is escaped: $tab" );
}
$_GET = array( 'page' => WIA_Admin::SLUG, 'tab' => 'chats', 'c' => 'adminpagetest0001' );
ob_start();
WIA_Admin::page();
$html = ob_get_clean();
ok( false !== strpos( $html, '&lt;img' ) && false === strpos( $html, '<img src=x' ), 'conversation view escapes messages' );
$_GET = array( 'page' => WIA_Admin::SLUG, 'tab' => 'knowledge', 'q' => '"><script>x</script>' );
ob_start();
WIA_Admin::page();
ok( false === strpos( ob_get_clean(), '<script>x' ), 'search box escapes the query' );

$sub = wp_insert_user( array( 'user_login' => 'sub', 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 's@example.test' ) );
wp_set_current_user( $sub );
ob_start();
WIA_Admin::page();
ok( '' === ob_get_clean(), 'a subscriber sees nothing' );
ok( ! current_user_can( 'manage_options' ), 'and has no management capability' );

$wpdb->delete( WIA_DB::t( 'faqs' ), array( 'question' => $evil ) );
wp_set_current_user( 0 );
finish();

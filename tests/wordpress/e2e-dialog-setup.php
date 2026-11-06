<?php
// Content for tests/e2e/wp_dialog.py: a small bag shop with contact details, a learned phrase, unanswered words
// and one reference site. Run with `wp eval-file` on the test stack after the plugin and WooCommerce are active.
require '/tests/harness.php';
update_option( 'woocommerce_currency', 'IRT' );
$cat = wp_insert_term( 'کوله پشتی', 'product_cat' );
$cat = is_wp_error( $cat ) ? (int) $cat->get_error_data() : $cat['term_id'];
foreach ( array( array( 'کوله پشتی مدرسه آبی', '1200000', 'کوله پشتی مدرسه با بند قابل تنظیم و جای بطری آب.' ), array( 'کوله پشتی کوهنوردی ۴۰ لیتری', '3500000', 'کوله کوهنوردی چهل لیتری با فریم آلومینیومی و کاور باران.' ), array( 'کیف لپ تاپ چرمی', '2000000', 'کیف لپ تاپ از چرم طبیعی با جای شارژر، مناسب لپ تاپ ۱۵ اینچ.' ) ) as $row ) {
	$p = new WC_Product_Simple();
	$p->set_name( $row[0] );
	$p->set_regular_price( $row[1] );
	$p->set_description( '<p>' . $row[2] . '</p>' );
	$p->set_status( 'publish' );
	$p->set_category_ids( array( $cat ) );
	$p->save();
}
page( 'تولید کوله پشتی در تهران', '<p>تولیدی کوله پشتی یک واحد تولیدی است که کوله پشتی های متنوعی را تولید می کند. این کوله پشتی ها برای مدرسه، دانشگاه، کار و گردشگری مناسب هستند.</p>' );
page( 'شرایط ارسال', '<p>ارسال سفارش به سراسر کشور با پست پیشتاز بین ۲ تا ۴ روز کاری انجام می‌شود.</p>' );
$s             = WIA_DB::settings();
$s['language'] = 'fa';
$s['teaser_delay'] = 1;
$s['teaser'] = array( 'fa' => 'سلام 👋 دنبال کیف یا کوله هستید؟ بپرسید.' );
$s['contact']  = array( 'phone' => '021-0000-0000', 'email' => 'support@example.test', 'whatsapp' => '+98 900 000 0000', 'telegram' => '', 'page' => '', 'hours' => '۹ تا ۱۷' );
update_option( 'wia_settings', $s, false );
run_scan();
for ( $i = 0; $i < 3; $i++ ) {
	$state = array();
	WIA_Chat::converse( 'ساک', $state );
	WIA_Chat::converse( 'کوله پشتی کوهنوردی ۴۰ لیتری', $state );
}
foreach ( array( 'هلیکوپتر', 'زیردریایی', 'هلیکوپتر' ) as $q ) {
	$state = array();
	WIA_Chat::converse( $q, $state );
}
add_filter( 'http_request_host_is_external', function ( $external, $host ) { return 'ref' === $host ? true : $external; }, 10, 2 );
WIA_Reference::set_sources( array( 'http://ref:8080/' ) );
for ( $i = 0; $i < 10 && WIA_Reference::pending(); $i++ ) {
	WIA_Reference::run_batch();
}
WIA_Graph::get( true );
delete_transient( 'wia_rl_' );
echo "setup done\n";

<?php
require '/tests/harness.php';

ok( class_exists( 'WooCommerce' ), 'WooCommerce is active' );
update_option( 'woocommerce_currency', 'IRT' );

function product( $name, $price, $description, $stock = 'instock' ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_description( $description );
	$p->set_status( 'publish' );
	$p->set_stock_status( $stock );
	return $p->save();
}

$phone  = product( 'گوشی ایکس', '25000000', '<p>گوشی ایکس دارای دوربین ۵۰ مگاپیکسل و باتری ۵۰۰۰ میلی آمپر است.</p>' );
$laptop = product( 'Laptop Alpha', '60000000', '<p>Laptop Alpha has a fourteen inch display and sixteen gigabytes of memory.</p>', 'outofstock' );
$bare   = product( 'Nameonly Kettle', '100', '' );

$a = WIA_Chat::answer( 'قیمت گوشی ایکس چقدر است؟' );
ok( 'structured' === $a['mode'] && false !== strpos( $a['text'], '۲۵٬۰۰۰٬۰۰۰' ) && false !== strpos( $a['text'], 'تومان' ), 'price answer in Persian with currency', $a );
ok( false !== strpos( $a['sources'][0]['url'], 'http' ), 'price answer links to the product', $a['sources'] );

$a = WIA_Chat::answer( 'آیا گوشی ایکس موجود است؟' );
ok( 'structured' === $a['mode'] && false !== strpos( $a['text'], 'موجود است' ), 'in stock', $a );
$a = WIA_Chat::answer( 'Is Laptop Alpha in stock?' );
ok( 'structured' === $a['mode'] && false !== strpos( $a['text'], 'out of stock' ), 'out of stock', $a );

// A price changed through WooCommerce must show at once, with no rescan, because it is read live.
$p = wc_get_product( $phone );
$p->set_regular_price( '27500000' );
$p->save();
$a = WIA_Chat::answer( 'قیمت گوشی ایکس چقدر است؟' );
ok( false !== strpos( $a['text'], '۲۷٬۵۰۰٬۰۰۰' ) && false === strpos( $a['text'], '۲۵٬۰۰۰٬۰۰۰' ), 'changed price is live', $a['text'] );
$version = (int) WIA_Knowledge::find( 'product', $phone )['version'];
WIA_Ingest::ingest_post( $phone );
ok( $version === (int) WIA_Knowledge::find( 'product', $phone )['version'], 'a price change does not rewrite the knowledge' );

// Stock changed outside the post editor.
wc_update_product_stock_status( $laptop, 'instock' );
ok( false !== strpos( WIA_Chat::answer( 'Is Laptop Alpha in stock?' )['text'], 'is in stock' ), 'changed stock is live' );

// The index holds no price at all.
global $wpdb;
$body = $wpdb->get_var( $wpdb->prepare( 'SELECT GROUP_CONCAT(c.body) FROM ' . WIA_DB::t( 'chunks' ) . ' c JOIN ' . WIA_DB::t( 'documents' ) . " d ON d.id = c.doc_id WHERE d.source_type = 'product' AND d.source_id = %d", $phone ) );
ok( false === strpos( (string) $body, '27500000' ), 'price is not stored in the knowledge base', $body );

ok( WIA_Knowledge::search( 'Nameonly Kettle' ), 'product without a description is still known by name' );
ok( false !== strpos( WIA_Chat::answer( 'How much is Nameonly Kettle?' )['text'], '100' ), 'and its price can be asked' );

$a = WIA_Chat::answer( 'How much is the Zanzibar Quantum drone?' );
ok( 'not_found' === $a['mode'], 'unknown product never borrows another price', $a );

$a = WIA_Chat::answer( 'این فروشگاه چه محصولاتی دارد؟' );
ok( false !== strpos( $a['text'], 'گوشی ایکس' ) && false !== strpos( $a['text'], 'Laptop Alpha' ), 'catalog question', $a );

$a = WIA_Chat::answer( 'What is the difference between Laptop Alpha and Nameonly Kettle?' );
ok( 'structured' === $a['mode'] && 2 === count( $a['sources'] ), 'comparison of two products', $a );

// Follow-up question about the product under discussion.
$r  = rest_chat( 'قیمت گوشی ایکس چقدر است؟' );
$r2 = rest_chat( 'موجوده؟', $r->get_data()['conversation_id'] );
ok( false !== strpos( $r2->get_data()['answer'], 'گوشی ایکس' ) && false !== strpos( $r2->get_data()['answer'], 'موجود است' ), 'follow-up uses the product from context', $r2->get_data() );

// A new question with its own subject must not inherit the product under discussion.
page( 'شرایط ارسال', '<p>ارسال سفارش به سراسر کشور با پست پیشتاز بین ۲ تا ۴ روز کاری انجام می‌شود.</p>' );
$r3 = rest_chat( 'شرایط ارسال چیست؟', $r->get_data()['conversation_id'] );
ok( false !== strpos( $r3->get_data()['answer'], 'پست پیشتاز' ), 'a new topic stands alone', $r3->get_data() );

// Attributes and category descriptions.
$attr = new WC_Product_Attribute();
$attr->set_name( 'Material' );
$attr->set_options( array( 'Titanium' ) );
$attr->set_visible( true );
$p = wc_get_product( $laptop );
$p->set_attributes( array( $attr ) );
$p->save();
ok( WIA_Knowledge::search( 'titanium' ), 'product attributes are learned' );

$term = wp_insert_term( 'Audio gear', 'product_cat', array( 'description' => 'Everything for listening: amplifiers, turntables and high fidelity speakers.' ) );
ok( WIA_Knowledge::search( 'turntables amplifiers' ), 'category description is learned' );
wp_update_term( $term['term_id'], 'product_cat', array( 'description' => '' ) );
ok( ! WIA_Knowledge::search( 'turntables amplifiers' ), 'an emptied description is forgotten' );

// Hidden and deleted products.
$hidden = wc_get_product( $bare );
$hidden->set_catalog_visibility( 'hidden' );
$hidden->save();
ok( ! WIA_Knowledge::search( 'Nameonly Kettle' ), 'catalog-hidden product is removed' );

wp_delete_post( $phone, true );
ok( 'not_found' === WIA_Chat::answer( 'قیمت گوشی ایکس چقدر است؟' )['mode'], 'deleted product can no longer be answered' );

finish();

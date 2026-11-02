<?php
// Dialogue on a small bag shop: the «کیف» stopword bug, clarifying questions, partial answers, rejection,
// handoff to contact details, learning from conversations, the knowledge map and reference sites.
require '/tests/harness.php';
global $wpdb;

function product_in( $name, $price, $description, $cat ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_description( $description );
	$p->set_status( 'publish' );
	$p->set_category_ids( array( $cat ) );
	return $p->save();
}

function talk( $messages ) {
	$state = array();
	$out   = array();
	foreach ( (array) $messages as $m ) {
		$out[] = WIA_Chat::converse( $m, $state );
	}
	return array( $out, $state );
}

update_option( 'woocommerce_currency', 'IRT' );
$cat = wp_insert_term( 'کوله پشتی', 'product_cat' );
$cat = $cat['term_id'];
$school = product_in( 'کوله پشتی مدرسه آبی', '1200000', '<p>کوله پشتی مدرسه با بند قابل تنظیم و جای بطری آب.</p>', $cat );
$hiking = product_in( 'کوله پشتی کوهنوردی ۴۰ لیتری', '3500000', '<p>کوله کوهنوردی چهل لیتری با فریم آلومینیومی و کاور باران.</p>', $cat );
$laptop = product_in( 'کیف لپ تاپ چرمی', '2000000', '<p>کیف لپ تاپ از چرم طبیعی با جای شارژر، مناسب لپ تاپ ۱۵ اینچ.</p>', $cat );
page( 'تولید کوله پشتی در تهران', '<p>تولیدی کوله پشتی یک واحد تولیدی است که کوله پشتی های متنوعی را تولید می کند. این کوله پشتی ها برای مدرسه، دانشگاه، کار و گردشگری مناسب هستند.</p>' );
page( 'تماس با ما', '<p>برای سفارش عمده فرم این صفحه را پر کنید.</p>' );
$scan = run_scan();
ok( 'done' === $scan['state'], 'shop scanned', $scan );

// ---- the reported bug: «کیف» was dropped as the Arabic word for "how" ------------------------------
$a = WIA_Chat::answer( 'کیف' );
ok( 'not_found' !== $a['mode'] && false !== strpos( $a['text'] . implode( ' ', $a['suggestions'] ), 'کیف لپ تاپ' ), '«کیف» alone finds the bag', $a );
$a = WIA_Chat::answer( 'کیف چرمی دارید؟' );
ok( in_array( $a['mode'], array( 'extractive', 'clarify' ), true ) && false !== strpos( $a['text'], 'چرم' ), '«کیف چرمی» is answered', $a );
ok( false !== strpos( $a['text'], 'قیمت: ۲٬۰۰۰٬۰۰۰' ), 'a product answer adds its live price', $a['text'] );

// ---- several products match: ask which one ---------------------------------------------------------
$a = WIA_Chat::answer( 'قیمت کوله پشتی چنده؟' );
ok( 'clarify' === $a['mode'] && false !== strpos( $a['text'], '۱٬۲۰۰٬۰۰۰' ) && false !== strpos( $a['text'], '۳٬۵۰۰٬۰۰۰' ), 'ambiguous price question lists the candidates with live prices', $a );
ok( count( $a['suggestions'] ) >= 2 && in_array( 'کوله پشتی مدرسه آبی', $a['suggestions'], true ), 'and offers them as choices', $a['suggestions'] );
$a = WIA_Chat::answer( 'قیمت کوله پشتی مدرسه آبی چنده؟' );
ok( 'structured' === $a['mode'] && false !== strpos( $a['text'], '۱٬۲۰۰٬۰۰۰' ), 'a fully named product is answered directly', $a );
$a = WIA_Chat::answer( 'کوله پشتی' );
ok( in_array( $a['mode'], array( 'extractive', 'clarify' ), true ) && $a['suggestions'], 'a broad topic is answered with next steps', $a );

// ---- partial and empty misses explain themselves ---------------------------------------------------
$a = WIA_Chat::answer( 'کوله پشتی ضدآب' );
ok( 'not_found' === $a['mode'] && false !== strpos( $a['text'], 'ضدآب' ) && $a['suggestions'], 'partial miss names the unknown word and offers what exists', $a );
$a = WIA_Chat::answer( 'هلیکوپتر' );
ok( 'not_found' === $a['mode'] && false !== strpos( $a['text'], 'هلیکوپتر' ) && in_array( 'کوله پشتی', $a['suggestions'], true ), 'unknown topic says so and suggests the site\'s categories', $a );

// ---- small talk ------------------------------------------------------------------------------------
foreach ( array( 'خداحافظ' => 'farewell', 'تو کی هستی؟' => 'identity', 'خوبی؟' => 'howareyou', 'who are you?' => 'identity', 'مع السلامة' => 'farewell' ) as $q => $key ) {
	$a = WIA_Chat::answer( $q );
	ok( 'smalltalk' === $a['mode'] && WIA_Chat::msg( $a['language'], $key ) === $a['text'], "small talk: $q", $a );
}
ok( WIA_Chat::answer( 'سلام' )['suggestions'], 'greeting offers starter questions' );

// ---- contact and handoff ---------------------------------------------------------------------------
$a = WIA_Chat::answer( 'شماره تماس شما چیه؟' );
ok( 'contact' === $a['mode'] && in_array( 'تماس با ما', wp_list_pluck( $a['actions'], 'label' ), true ), 'with no details set, the contact page is offered', $a );
$s            = WIA_DB::settings();
$s['contact'] = array( 'phone' => '021-0000-0000', 'email' => 'support@example.test', 'whatsapp' => '+98 900 000 0000', 'telegram' => '@example_shop', 'page' => '', 'hours' => '9-17' );
update_option( 'wia_settings', $s, false );
$a = WIA_Chat::answer( 'راه ارتباط با پشتیبانی؟' );
$urls = wp_list_pluck( $a['actions'], 'url' );
ok( 'contact' === $a['mode'] && in_array( 'tel:02100000000', $urls, true ) && in_array( 'mailto:support@example.test', $urls, true ) && in_array( 'https://wa.me/989000000000', $urls, true ) && in_array( 'https://t.me/example_shop', $urls, true ), 'contact details become call, mail and chat links', $a );
ok( ! array_filter( $a['actions'], function ( $x ) { return ! WIA_REST::safe_action( $x ); } ), 'every contact link passes the URL filter' );
list( $turns ) = talk( array( 'هلیکوپتر', 'زیردریایی' ) );
ok( 'not_found' === $turns[0]['mode'] && ! $turns[0]['actions'], 'first miss offers alternatives, no handoff yet', $turns[0] );
ok( 'handoff' === $turns[1]['mode'] && false !== strpos( $turns[1]['text'], '021-0000-0000' ) && $turns[1]['actions'], 'second miss in a row hands off to the contact details', $turns[1] );
list( $turns ) = talk( array( 'هلیکوپتر', 'کیف لپ تاپ چرمی', 'زیردریایی' ) );
ok( 'not_found' === $turns[2]['mode'], 'an answer in between resets the miss count', $turns[2]['mode'] );
ok( in_array( WIA_Chat::msg( 'fa', 'contact_chip' ), WIA_Chat::starters( 'fa' ), true ), 'starters include contacting support once details exist' );

// ---- rejecting an answer, and accepting a single offer ---------------------------------------------
list( $turns, $state ) = talk( array( 'کوله پشتی', 'نه، این نبود' ) );
ok( $turns[0]['alts'] && 'rephrase' === $turns[1]['mode'] && $turns[1]['suggestions'] === $turns[0]['alts'], '«نه، این نبود» brings back the alternatives', $turns );
list( $turns ) = talk( array( 'کوله پشتی', 'نه، قیمت کیف لپ تاپ چرمی چنده؟' ) );
ok( 'structured' === $turns[1]['mode'] && false !== strpos( $turns[1]['text'], '۲٬۰۰۰٬۰۰۰' ), 'a rejection that carries a new question answers it', $turns[1] );
$state = array( 'offer' => 'کیف لپ تاپ چرمی' );
$a     = WIA_Chat::converse( 'بله', $state );
ok( 'extractive' === $a['mode'] && false !== strpos( $a['text'], 'چرم' ), '«بله» accepts the single offer', $a );
$state = array();
ok( 'smalltalk' === WIA_Chat::converse( 'باشه', $state )['mode'], '«باشه» with nothing offered is a polite acknowledgement' );

// ---- learning from conversations -------------------------------------------------------------------
// «ساک» is not on the site. Visitors who ask for it settle on the hiking pack.
ok( 'not_found' === WIA_Chat::answer( 'ساک' )['mode'], '«ساک» is unknown at first' );
for ( $i = 1; $i <= 3; $i++ ) {
	list( $turns ) = talk( array( 'ساک', 'کوله پشتی کوهنوردی ۴۰ لیتری' ) );
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WIA_DB::t( 'aliases' ) . ' WHERE phrase = %s', 'ساک' ), ARRAY_A );
	ok( $row && (int) $row['votes'] === $i && ( $i < 3 ? 'candidate' === $row['status'] : 'active' === $row['status'] ), "vote $i recorded, active only after three", $row );
}
$doc = WIA_Knowledge::find( 'product', $hiking );
$a   = WIA_Chat::answer( 'ساک' );
ok( 'extractive' === $a['mode'] && $a['learned'] && (int) $a['context_doc'] === (int) $doc['id'], 'the learned phrase now answers from the chosen page', $a );
ok( ! in_array( 'ساک', wp_list_pluck( WIA_Learn::open_gaps(), 'word' ), true ), 'a learned word is no longer listed as a gap' );
list( $turns ) = talk( array( 'ساک دستی', 'ساک دستی چرمی' ) );
ok( ! $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WIA_DB::t( 'aliases' ) . ' WHERE phrase LIKE %s', '%دستی%' ) ), 'asking again with the same unknown word teaches nothing' );
list( $turns ) = talk( array( 'ساک', 'ساک' ) );
ok( 3 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT votes FROM ' . WIA_DB::t( 'aliases' ) . ' WHERE phrase = %s', 'ساک' ) ), 'an answered question is not a vote' );
WIA_Learn::set_alias_status( (int) $row['id'], 'rejected' );
ok( 'not_found' === WIA_Chat::answer( 'ساک' )['mode'], 'a rejected phrase stops answering' );
ok( WIA_Learn::teach( 'بک پک', (int) $doc['id'] ) && WIA_Chat::answer( 'بک پک' )['learned'], 'the owner can teach a phrase directly' );

// ---- counters and gaps -----------------------------------------------------------------------------
$totals = WIA_Learn::totals( 30 );
ok( $totals['asked'] > 0 && $totals['missed'] > 0 && $totals['answered'] > 0 && null !== $totals['rate'], 'daily counters record answers and misses', $totals );
$gaps = wp_list_pluck( WIA_Learn::open_gaps(), 'word' );
ok( in_array( 'هلیکوپتر', $gaps, true ) && in_array( 'زیردریایی', $gaps, true ), 'unknown words are listed as gaps', $gaps );
page( 'هلیکوپتر اسباب بازی', '<p>هلیکوپتر کنترلی برای کودکان به زودی عرضه می شود.</p>' );
ok( ! in_array( 'هلیکوپتر', wp_list_pluck( WIA_Learn::open_gaps(), 'word' ), true ), 'a gap disappears once the site covers it' );

// ---- REST shape ------------------------------------------------------------------------------------
$r = rest_chat( 'قیمت کوله پشتی چنده؟' )->get_data();
ok( isset( $r['suggestions'], $r['actions'] ) && 'clarify' === $r['mode'] && count( $r['suggestions'] ) >= 2, 'REST answer carries suggestions', $r );
$r2 = rest_chat( $r['suggestions'][0], $r['conversation_id'] )->get_data();
ok( in_array( $r2['mode'], array( 'extractive', 'structured' ), true ), 'choosing a suggestion over REST answers it', $r2 );

// ---- reference sites -------------------------------------------------------------------------------
ok( 'not_public' === WIA_Reference::check_url( 'http://ref:8080/' ), 'a private network address is refused' );
ok( 'own_site' === WIA_Reference::check_url( home_url( '/' ) ), 'the site itself is refused' );
ok( 'invalid' === WIA_Reference::check_url( 'ftp://example.test/' ), 'only http and https' );
// The test stack's reference site lives on the Docker network; this one host is allowed for the test only.
add_filter( 'http_request_host_is_external', function ( $external, $host ) { return 'ref' === $host ? true : $external; }, 10, 2 );
$rejected = WIA_Reference::set_sources( array( 'http://ref:8080/', 'not a url' ) );
ok( 1 === count( WIA_Reference::sources() ) && $rejected, 'valid reference site added, invalid one reported', $rejected );
for ( $i = 0; $i < 20 && WIA_Reference::pending(); $i++ ) {
	WIA_Reference::run_batch();
}
$src = WIA_Reference::sources()[0];
ok( 'done' === $src['status'] && (int) $src['pages'] >= 3, 'reference pages read over HTTP', $src );
ok( (int) $src['skipped'] >= 1, 'robots.txt disallowed page was skipped', $src );
$terms = $wpdb->get_col( 'SELECT term FROM ' . WIA_DB::t( 'ref_terms' ) );
ok( in_array( 'ارگونومیک', $terms, true ), 'words from reference pages are counted' );
ok( ! array_intersect( array( 'کلمهمحرمانه', 'منوینمونه', 'فوترنمونه', 'trackingword', 'نادیده' ), $terms ), 'disallowed pages, navigation, scripts and injected instructions are left out', array_values( array_intersect( array( 'کلمهمحرمانه', 'منوینمونه', 'فوترنمونه', 'trackingword', 'نادیده' ), $terms ) ) );
ok( ! $wpdb->get_var( "SHOW COLUMNS FROM " . WIA_DB::t( 'ref_terms' ) . " LIKE 'body'" ), 'no text of other sites is stored' );
$missing = wp_list_pluck( WIA_Reference::missing_topics(), 'term' );
ok( in_array( 'ارگونومیک', $missing, true ) && ! in_array( 'کوله', $missing, true ), 'topics only the reference site covers are reported', $missing );
$a = WIA_Chat::answer( 'کوله ارگونومیک' );
ok( false === strpos( $a['text'], 'بند سینه' ) && false === strpos( wp_json_encode( $a['sources'] ), 'ref:8080' ), 'visitors are never answered from a reference site', $a );
ok( 'not_found' === WIA_Chat::answer( 'ارگونومیک' )['mode'], 'a reference-only word stays unanswered' );

// ---- knowledge map ---------------------------------------------------------------------------------
$t     = microtime( true );
$graph = WIA_Graph::get( true );
$took  = microtime( true ) - $t;
$kinds = array_count_values( wp_list_pluck( $graph['nodes'], 'kind' ) );
ok( ! empty( $kinds['topic'] ) && ! empty( $kinds['doc'] ) && ! empty( $kinds['learned'] ) && ! empty( $kinds['gap'] ) && ! empty( $kinds['reference'] ), 'map has topics, pages, learned phrases, gaps and reference topics', $kinds );
$labels = wp_list_pluck( $graph['nodes'], 'label' );
ok( in_array( 'کوله', $labels, true ) && in_array( 'ارگونومیک', $labels, true ), 'map labels are real words', array_slice( $labels, 0, 30 ) );
$ids = array_flip( wp_list_pluck( $graph['nodes'], 'id' ) );
ok( $graph['edges'] && ! array_filter( $graph['edges'], function ( $e ) use ( $ids ) { return ! isset( $ids[ $e['s'] ], $ids[ $e['t'] ] ); } ), 'every link joins two drawn nodes', count( $graph['edges'] ) );
ok( count( $ids ) === count( $graph['nodes'] ), 'node ids are unique' );
ok( $graph['stats']['learned'] >= 1 && $graph['stats']['refs']['pages'] >= 3 && $graph['stats']['vocabulary'] > 20, 'map statistics', $graph['stats'] );
echo "     map: " . count( $graph['nodes'] ) . ' nodes, ' . count( $graph['edges'] ) . ' links, built in ' . round( $took, 3 ) . " s\n";

$admin = get_user_by( 'login', 'admin' );
wp_set_current_user( $admin->ID );
require_once WIA_DIR . 'includes/class-wia-admin.php';
foreach ( array( 'map' => 'wia-graph-data', 'learning' => 'ref:8080' ) as $tab => $needle ) {
	$_GET = array( 'page' => WIA_Admin::SLUG, 'tab' => $tab );
	ob_start();
	WIA_Admin::page();
	ok( false !== strpos( ob_get_clean(), $needle ), "admin tab shows its data: $tab" );
}
preg_match( '#<script type="application/json" id="wia-graph-data">(.*?)</script>#s', ( function () { $_GET = array( 'page' => WIA_Admin::SLUG, 'tab' => 'map' ); ob_start(); WIA_Admin::page(); return ob_get_clean(); } )(), $m );
ok( isset( $m[1] ) && is_array( json_decode( $m[1], true ) ) && false === strpos( $m[1], '<' ), 'embedded map data is valid JSON with no raw markup' );

remove_all_filters( 'http_request_host_is_external' );
WIA_Reference::set_sources( array() );
ok( ! WIA_Reference::sources() && ! $wpdb->get_var( 'SELECT COUNT(*) FROM ' . WIA_DB::t( 'ref_terms' ) ), 'removing a reference site forgets its words' );
finish();

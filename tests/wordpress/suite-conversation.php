<?php
// Conversation quality on a realistic small shop, in the way customers really write: colloquial Persian, typos,
// possessive endings, synonyms, follow-ups and questions the site cannot answer. Every question states what a
// good answer must contain. "stretch" questions are reported but do not fail the suite: they measure the limits
// of an edition that quotes and has no language model.
require '/tests/harness.php';
global $wpdb;

// A clean site: earlier suites leave their own content behind.
foreach ( get_posts( array( 'post_type' => array( 'post', 'page', 'product' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( array( 'documents', 'chunks', 'postings', 'faqs', 'aliases', 'conversations', 'messages', 'ref_sources', 'ref_terms' ) as $table ) {
	$wpdb->query( 'TRUNCATE TABLE ' . WIA_DB::t( $table ) ); // phpcs:ignore WordPress.DB
}
delete_option( 'wia_gaps' );
delete_option( 'wia_daily_stats' );
update_option( 'woocommerce_currency', 'IRT' );
$s            = WIA_DB::settings();
$s['contact'] = array( 'phone' => '021-0000-0000', 'email' => 'support@example.test', 'whatsapp' => '', 'telegram' => '', 'page' => '', 'hours' => '' );
$s['not_found'] = array();
update_option( 'wia_settings', $s, false );

$pages = array(
	'درباره ما'           => 'ما از سال ۱۳۹۰ کیف و کوله پشتی تولید می‌کنیم. کارگاه ما در تهران است و همه محصولات با پارچه برزنت و کوردورا دوخته می‌شوند.',
	'شرایط ارسال'          => 'سفارش‌ها به سراسر ایران با پست پیشتاز ارسال می‌شوند و بین ۲ تا ۴ روز کاری می‌رسند. برای تهران ارسال با پیک موتوری در همان روز امکان‌پذیر است. هزینه ارسال برای خریدهای بالای ۲ میلیون تومان رایگان است.',
	'روش‌های پرداخت'       => 'پرداخت آنلاین با همه کارت‌های بانکی عضو شتاب انجام می‌شود. پرداخت در محل فقط برای تهران فعال است. امکان خرید اقساطی در چهار قسط هم وجود دارد.',
	'ضمانت و مرجوعی'       => 'همه کیف‌ها شش ماه ضمانت دوخت و زیپ دارند. اگر کالا را نپسندید تا ۷ روز می‌توانید آن را مرجوع کنید و مبلغ کامل به شما برگردانده می‌شود.',
	'تماس با ما'           => 'تلفن پشتیبانی ۰۲۱-۰۰۰۰۰۰۰۰ است. ساعات کاری شنبه تا پنجشنبه از ۹ صبح تا ۶ عصر است. آدرس فروشگاه: تهران، خیابان نمونه، پلاک ۱.',
	'سفارش عمده'           => 'برای سفارش عمده مدارس و شرکت‌ها حداقل سفارش ۵۰ عدد است و چاپ لوگو روی کوله رایگان انجام می‌شود.',
	'پیگیری سفارش'         => 'پس از ارسال، کد رهگیری پستی برای شما پیامک می‌شود و وضعیت سفارش را در بخش حساب کاربری می‌بینید.',
);
foreach ( $pages as $title => $body ) {
	page( $title, '<p>' . $body . '</p>' );
}
page( 'راهنمای انتخاب حجم کوله', '<p>برای دانش‌آموزان ابتدایی کوله ۱۵ تا ۲۰ لیتری مناسب است. برای سفر کوتاه کوله ۳۰ لیتری و برای کوهنوردی چند روزه کوله ۵۰ لیتری پیشنهاد می‌شود.</p>', 'post' );

$cats = array();
foreach ( array( 'کوله پشتی', 'کیف', 'ساک' ) as $name ) {
	$t             = wp_insert_term( $name, 'product_cat' );
	$cats[ $name ] = is_wp_error( $t ) ? (int) $t->get_error_data() : (int) $t['term_id'];
}
foreach ( array(
	array( 'کوله پشتی مدرسه آبی', '1200000', 'کوله پشتی مدرسه با بند قابل تنظیم، جای بطری آب و پشتی طبی.', 'کوله پشتی', 'instock' ),
	array( 'کوله پشتی کوهنوردی ۴۰ لیتری', '3500000', 'کوله کوهنوردی چهل لیتری با فریم آلومینیومی و کاور باران.', 'کوله پشتی', 'instock' ),
	array( 'کیف لپ تاپ چرمی', '2000000', 'کیف لپ تاپ از چرم طبیعی با جای شارژر، مناسب لپ تاپ ۱۵ اینچ.', 'کیف', 'instock' ),
	array( 'کیف پول مردانه', '450000', 'کیف پول چرمی با هشت جای کارت.', 'کیف', 'outofstock' ),
	array( 'ساک ورزشی', '900000', 'ساک ورزشی با جای کفش جداگانه و پارچه ضد آب.', 'ساک', 'instock' ),
) as $row ) {
	$p = new WC_Product_Simple();
	$p->set_name( $row[0] );
	$p->set_regular_price( $row[1] );
	$p->set_description( '<p>' . $row[2] . '</p>' );
	$p->set_status( 'publish' );
	$p->set_stock_status( $row[4] );
	$p->set_category_ids( array( $cats[ $row[3] ] ) );
	$p->save();
}
$wpdb->insert( WIA_DB::t( 'faqs' ), array( 'question' => 'آیا تخفیف دارید؟', 'answer' => 'برای اولین خرید با کد WELCOME ده درصد تخفیف می‌گیرید.', 'keywords' => 'تخفیف، کد تخفیف', 'language' => '', 'created_at' => current_time( 'mysql', true ) ) );
$scan = run_scan();
ok( 'done' === $scan['state'], 'shop scanned', $scan );

// [ question, any of these must appear in the reply (text, suggestions or action labels), modes allowed or '', stretch ]
$single = array(
	array( 'سلام', array( 'دستیار' ), 'smalltalk' ),
	array( 'ارسال دارید؟', array( 'پست پیشتاز' ), '' ),
	array( 'به شیراز هم میفرستین؟', array( 'سراسر ایران' ), '' ),
	array( 'چند روزه میرسه دستم؟', array( '۲ تا ۴ روز', '2 تا 4 روز' ), '', true ),
	array( 'هزینه ارسال چقدره؟', array( 'رایگان' ), '' ),
	array( 'پیک دارین برای تهران؟', array( 'پیک موتوری' ), '' ),
	array( 'چطور پرداخت کنم؟', array( 'پرداخت آنلاین' ), '' ),
	array( 'پرداخت در محل دارید؟', array( 'فقط برای تهران' ), '' ),
	array( 'قسطی میشه خرید؟', array( 'اقساطی' ), '' ),
	array( 'گارانتی دارن؟', array( 'ضمانت' ), '' ),
	array( 'مرجوعی چند روزه؟', array( '۷ روز', '7 روز' ), '' ),
	array( 'اگه خوشم نیومد میتونم پس بدم؟', array( 'مرجوع' ), '', true ),
	array( 'شماره تماستون؟', array( '021-0000-0000' ), '' ),
	array( 'ساعت کاری فروشگاه؟', array( 'شنبه تا پنجشنبه' ), '' ),
	array( 'آدرستون کجاست؟', array( 'خیابان نمونه' ), '' ),
	array( 'تخفیف دارین؟', array( 'WELCOME' ), 'faq' ),
	array( 'کوله مدرسه دارید؟', array( 'کوله پشتی مدرسه آبی' ), '' ),
	array( 'قیمت کیف لپتاپ؟', array( '۲٬۰۰۰٬۰۰۰' ), '' ),
	array( 'کیف پول موجوده؟', array( 'موجود نیست' ), 'structured' ),
	array( 'یه کوله برای کوه میخوام', array( 'کوهنوردی' ), '' ),
	array( 'قیمت کوله پشتی چنده؟', array( '۱٬۲۰۰٬۰۰۰' ), 'clarify' ),
	array( 'سفارش عمده برای مدرسه قبول میکنید؟', array( 'حداقل سفارش ۵۰', 'حداقل سفارش 50' ), '' ),
	array( 'لوگو چاپ میکنید؟', array( 'چاپ لوگو' ), '' ),
	array( 'کوله چند لیتری برای سفر خوبه؟', array( '۳۰ لیتری', '30 لیتری' ), '' ),
	array( 'کد رهگیری چطوری میاد؟', array( 'پیامک' ), '' ),
	array( 'سفارشمو چطوری پیگیری کنم؟', array( 'کد رهگیری', 'حساب کاربری' ), '' ),
	array( 'کیفاتون چرمیه؟', array( 'چرم' ), '', true ),
	array( 'ساک ضد آب دارید؟', array( 'ساک ورزشی' ), '' ),
	array( 'جنس کوله ها چیه؟', array( 'برزنت', 'کوردورا' ), '' ),
	array( 'تولیدی هستید یا فروشگاه؟', array( 'تولید' ), '', true ),
	array( 'چه محصولاتی دارید؟', array( 'کوله', 'کیف' ), 'structured' ),
	array( 'تو کی هستی؟', array( 'دستیار خودکار' ), 'smalltalk' ),
	array( 'ممنون', array( 'خواهش' ), 'smalltalk' ),
	array( 'هلیکوپتر میفروشید؟', array( 'هلیکوپتر' ), 'not_found' ),
	array( 'گارانتى دارید', array( 'ضمانت' ), '' ),
	array( 'Do you ship to Shiraz?', array( 'سراسر ایران' ), '', true ),
	array( 'What is your return policy?', array( 'مرجوع' ), '', true ),
	array( 'هل يوجد ضمان؟', array( 'ضمانت' ), '', true ),
);
$dialogues = array(
	'follow-up on a product'         => array( array( 'قیمت کوله پشتی کوهنوردی ۴۰ لیتری چنده؟', array( '۳٬۵۰۰٬۰۰۰' ) ), array( 'موجوده؟', array( 'موجود است' ) ), array( 'گارانتی داره؟', array( 'ضمانت' ) ) ),
	'choosing from a clarification'  => array( array( 'قیمت کوله پشتی چنده؟', array( 'کدام' ) ), array( 'کوله پشتی مدرسه آبی', array( '۱٬۲۰۰٬۰۰۰' ) ) ),
	'rejecting then asking again'    => array( array( 'کوله پشتی', array( 'کوله' ) ), array( 'نه، این نبود', array( 'کوله پشتی' ) ), array( 'نه، قیمت ساک ورزشی چنده؟', array( '۹۰۰٬۰۰۰' ) ) ),
	'a new topic after a product'    => array( array( 'قیمت کیف لپ تاپ چرمی؟', array( '۲٬۰۰۰٬۰۰۰' ) ), array( 'شرایط ارسال چیه؟', array( 'پست پیشتاز' ) ) ),
	'two misses reach a person'      => array( array( 'زیردریایی دارید؟', array( 'زیردریایی' ) ), array( 'هواپیما چی؟', array( '021-0000-0000' ) ) ),
	'greeting, question, thanks'     => array( array( 'سلام وقت بخیر', array( 'سلام' ) ), array( 'روش های پرداخت چیه؟', array( 'پرداخت آنلاین' ) ), array( 'مرسی', array( 'خواهش' ) ) ),
);

$core_pass = 0;
$core_all  = 0;
$str_pass  = 0;
$str_all   = 0;
$report    = array();
$judge     = function ( $answer, $needles, $mode ) {
	$hay = $answer['text'] . ' ' . implode( ' ', (array) $answer['suggestions'] ) . ' ' . implode( ' ', wp_list_pluck( (array) $answer['actions'], 'label' ) );
	$hit = false;
	foreach ( $needles as $n ) {
		$hit = $hit || false !== mb_strpos( $hay, $n );
	}
	return $hit && ( '' === $mode || $mode === $answer['mode'] );
};
foreach ( $single as $case ) {
	$state   = array();
	$answer  = WIA_Chat::converse( $case[0], $state );
	$good    = $judge( $answer, $case[1], $case[2] );
	$stretch = ! empty( $case[3] );
	if ( $stretch ) {
		$str_all++;
		$str_pass += $good ? 1 : 0;
	} else {
		$core_all++;
		$core_pass += $good ? 1 : 0;
		ok( $good, 'answers: ' . $case[0], array( $answer['mode'], $answer['text'], $answer['suggestions'] ) );
	}
	$report[] = ( $good ? 'ok  ' : ( $stretch ? 'miss' : 'FAIL' ) ) . ' [' . $answer['mode'] . '] ' . $case[0] . ' -> ' . str_replace( "\n", ' | ', mb_substr( $answer['text'], 0, 110 ) );
}
foreach ( $dialogues as $name => $turns ) {
	$state = array();
	$good  = true;
	$trace = array();
	foreach ( $turns as $turn ) {
		$answer  = WIA_Chat::converse( $turn[0], $state );
		$good    = $good && $judge( $answer, $turn[1], '' );
		$trace[] = $turn[0] . ' => [' . $answer['mode'] . '] ' . mb_substr( str_replace( "\n", ' | ', $answer['text'] ), 0, 90 );
	}
	$core_all++;
	$core_pass += $good ? 1 : 0;
	ok( $good, 'dialogue: ' . $name, $trace );
	$report[] = ( $good ? 'ok  ' : 'FAIL' ) . ' dialogue: ' . $name . ( $good ? '' : "\n        " . implode( "\n        ", $trace ) );
}
echo "\n     conversation report\n";
foreach ( $report as $line ) {
	echo '     ' . $line . "\n";
}
printf( "     core: %d/%d (%.0f%%), stretch: %d/%d\n\n", $core_pass, $core_all, 100 * $core_pass / max( 1, $core_all ), $str_pass, $str_all );
finish();

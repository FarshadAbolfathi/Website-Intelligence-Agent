<?php
// A second, different business, written after the shop suite was tuned, to check that the improvements are
// general and not fitted to one site: a language school with no WooCommerce products.
require '/tests/harness.php';
global $wpdb;

foreach ( get_posts( array( 'post_type' => array( 'post', 'page', 'product' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}
foreach ( array( 'documents', 'chunks', 'postings', 'faqs', 'aliases', 'conversations', 'messages' ) as $table ) {
	$wpdb->query( 'TRUNCATE TABLE ' . WIA_DB::t( $table ) ); // phpcs:ignore WordPress.DB
}
$s            = WIA_DB::settings();
$s['contact'] = array( 'phone' => '021-0000-1111', 'email' => '', 'whatsapp' => '', 'telegram' => '', 'page' => '', 'hours' => '' );
update_option( 'wia_settings', $s, false );

foreach ( array(
	'دوره‌های زبان انگلیسی' => 'آموزشگاه دوره‌های انگلیسی عمومی، مکالمه و آمادگی آیلتس برگزار می‌کند. هر ترم ۱۸ جلسه است و کلاس‌ها هفته‌ای دو بار تشکیل می‌شوند.',
	'شهریه'                => 'شهریه هر ترم انگلیسی عمومی ۴ میلیون تومان و دوره آیلتس ۹ میلیون تومان است. برای ثبت‌نام دو ترم با هم ۱۰ درصد تخفیف داده می‌شود.',
	'تعیین سطح'            => 'پیش از ثبت‌نام، آزمون تعیین سطح رایگان به صورت حضوری یا آنلاین برگزار می‌شود و حدود ۳۰ دقیقه طول می‌کشد.',
	'کلاس‌های آنلاین'       => 'همه دوره‌ها به صورت آنلاین هم برگزار می‌شوند. جلسات ضبط می‌شوند و تا پایان ترم در دسترس هستند.',
	'مدرک'                 => 'در پایان هر ترم به قبول‌شدگان گواهی پایان دوره داده می‌شود.',
	'ثبت‌نام'               => 'ثبت‌نام از طریق سایت یا مراجعه حضوری انجام می‌شود. ثبت‌نام ترم جدید از اول هر فصل شروع می‌شود.',
	'تماس با ما'           => 'آموزشگاه در اصفهان، خیابان نمونه قرار دارد. ساعات پاسخ‌گویی شنبه تا چهارشنبه از ۸ تا ۲۰ است.',
	'لغو و انصراف'         => 'در صورت انصراف تا پیش از جلسه دوم، ۸۰ درصد شهریه بازگردانده می‌شود. پس از جلسه دوم امکان بازگشت شهریه وجود ندارد.',
	'کلاس کودکان'          => 'کلاس‌های کودکان برای سنین ۷ تا ۱۲ سال با روش بازی و داستان برگزار می‌شود. هر کلاس حداکثر ۸ نفر است.',
) as $title => $body ) {
	page( $title, '<p>' . $body . '</p>' );
}
run_scan();

$cases = array(
	array( 'سلام خسته نباشید', array( 'دستیار' ) ),
	array( 'چه دوره هایی دارید؟', array( 'مکالمه', 'آیلتس' ) ),
	array( 'شهریه چنده؟', array( '۴ میلیون' ) ),
	array( 'هزینه دوره آیلتس چقدره؟', array( '۹ میلیون' ) ),
	array( 'هر ترم چند جلسه ست؟', array( '۱۸ جلسه' ) ),
	array( 'هفته ای چند بار کلاس دارین؟', array( 'دو بار' ) ),
	array( 'تعیین سطح دارید؟', array( 'تعیین سطح' ) ),
	array( 'آزمون تعیین سطح پولیه؟', array( 'رایگان' ) ),
	array( 'آنلاین هم برگزار میکنید؟', array( 'آنلاین' ) ),
	array( 'جلسات ضبط میشه؟', array( 'ضبط' ) ),
	array( 'مدرک میدید؟', array( 'گواهی' ) ),
	array( 'چطوری ثبت نام کنم؟', array( 'از طریق سایت', 'حضوری' ) ),
	array( 'ثبت نام ترم جدید کی شروع میشه؟', array( 'اول هر فصل' ) ),
	array( 'آدرس آموزشگاه کجاست؟', array( 'اصفهان' ) ),
	array( 'شماره تماس', array( '021-0000-1111' ) ),
	array( 'اگه انصراف بدم پولم برمیگرده؟', array( '۸۰ درصد' ) ),
	array( 'کلاس برای بچه ها دارید؟', array( 'کودکان' ) ),
	array( 'کلاس کودکان چند نفره ست؟', array( '۸ نفر' ) ),
	array( 'برای دو ترم تخفیف هست؟', array( '۱۰ درصد' ) ),
	array( 'زبان آلمانی هم دارید؟', array( 'آلمانی' ) ),
	array( 'خداحافظ', array( 'خدانگهدار' ) ),
);
$pass = 0;
foreach ( $cases as $case ) {
	$state  = array();
	$answer = WIA_Chat::converse( $case[0], $state );
	$hay    = $answer['text'] . ' ' . implode( ' ', $answer['suggestions'] ) . ' ' . implode( ' ', wp_list_pluck( $answer['actions'], 'label' ) );
	$good   = false;
	foreach ( $case[1] as $n ) {
		$good = $good || false !== mb_strpos( $hay, $n );
	}
	$pass += $good ? 1 : 0;
	echo '     ' . ( $good ? 'ok  ' : 'miss' ) . ' [' . $answer['mode'] . '] ' . $case[0] . ' -> ' . str_replace( "\n", ' | ', mb_substr( $answer['text'], 0, 110 ) ) . "\n";
}
$rate = $pass / count( $cases );
printf( "     language school: %d/%d (%.0f%%)\n", $pass, count( $cases ), 100 * $rate );
// A floor, not a target: this suite measures generalisation, so it is not tuned question by question.
ok( $rate >= 0.8, 'at least 80% of a second, untuned business is answered well', $rate );
finish();

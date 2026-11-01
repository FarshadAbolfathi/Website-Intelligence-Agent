<?php
/**
 * Admin screens. All data changes require manage_options and a nonce. All output is escaped.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Admin {
	const SLUG = 'website-intelligence-agent';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wia_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_wia_faq', array( __CLASS__, 'faq_action' ) );
		add_action( 'admin_post_wia_scan', array( __CLASS__, 'scan_action' ) );
		add_action( 'admin_post_wia_purge', array( __CLASS__, 'purge_action' ) );
		add_action( 'wp_ajax_wia_scan_step', array( __CLASS__, 'scan_step' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page( __( 'Site Assistant', 'website-intelligence-agent' ), __( 'Site Assistant', 'website-intelligence-agent' ), 'manage_options', self::SLUG, array( __CLASS__, 'page' ), 'dashicons-format-chat', 58 );
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wia-admin', WIA_URL . 'assets/admin.css', array(), WIA_VERSION );
		wp_enqueue_script( 'wia-admin', WIA_URL . 'assets/admin.js', array(), WIA_VERSION, true );
		wp_localize_script(
			'wia-admin',
			'WIA_ADMIN',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'wia_scan_step' ),
				'done'  => __( 'Scan finished.', 'website-intelligence-agent' ),
				'error' => __( 'The scan stopped because of a connection error. It will continue in the background.', 'website-intelligence-agent' ),
			)
		);
	}

	private static function tabs() {
		return array(
			'status'    => __( 'Status', 'website-intelligence-agent' ),
			'knowledge' => __( 'Knowledge', 'website-intelligence-agent' ),
			'faq'       => __( 'Questions & answers', 'website-intelligence-agent' ),
			'widget'    => __( 'Chat widget', 'website-intelligence-agent' ),
			'chats'     => __( 'Conversations', 'website-intelligence-agent' ),
			'advanced'  => __( 'Advanced', 'website-intelligence-agent' ),
		);
	}

	private static function url( $tab, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'status'; // phpcs:ignore WordPress.Security.NonceVerification
		$tab = array_key_exists( $tab, self::tabs() ) ? $tab : 'status';
		echo '<div class="wrap wia"><h1>' . esc_html__( 'Site Assistant', 'website-intelligence-agent' ) . '</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'website-intelligence-agent' ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $key => $label ) {
			printf( '<a class="nav-tab %s" href="%s">%s</a>', $key === $tab ? 'nav-tab-active' : '', esc_url( self::url( $key ) ), esc_html( $label ) );
		}
		echo '</nav>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	private static function tab_status() {
		$stats    = WIA_Knowledge::stats();
		$scan     = WIA_Ingest::scan_state();
		$docs     = isset( $stats['documents']['active'] ) ? $stats['documents']['active'] : 0;
		$settings = WIA_DB::settings();
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Lite edition: the assistant finds and quotes your site\'s own text and reads prices and stock live from WooCommerce. It does not run a language model and does not write new sentences. Nothing is sent to any outside service.', 'website-intelligence-agent' ) . '</p></div>';
		echo '<div class="wia-stats">';
		foreach ( array(
			__( 'Documents learned', 'website-intelligence-agent' ) => $docs,
			__( 'Text sections indexed', 'website-intelligence-agent' ) => $stats['chunks'],
			__( 'Duplicates ignored', 'website-intelligence-agent' ) => isset( $stats['documents']['duplicate'] ) ? $stats['documents']['duplicate'] : 0,
			__( 'Removed', 'website-intelligence-agent' ) => isset( $stats['documents']['removed'] ) ? $stats['documents']['removed'] : 0,
		) as $label => $value ) {
			echo '<div class="wia-stat"><span>' . esc_html( $label ) . '</span><b>' . esc_html( number_format_i18n( $value ) ) . '</b></div>';
		}
		echo '</div>';

		echo '<div class="wia-card"><h2>' . esc_html__( 'Learning', 'website-intelligence-agent' ) . '</h2>';
		echo '<p id="wia-scan-state" data-state="' . esc_attr( $scan['state'] ) . '">';
		if ( 'running' === $scan['state'] ) {
			esc_html_e( 'A scan is running…', 'website-intelligence-agent' );
		} elseif ( 'done' === $scan['state'] ) {
			/* translators: %s: date and time */
			echo esc_html( sprintf( __( 'Last scan finished: %s', 'website-intelligence-agent' ), get_date_from_gmt( $scan['finished'] ) ) );
		} else {
			esc_html_e( 'No scan has run yet.', 'website-intelligence-agent' );
		}
		echo '</p><p id="wia-scan-counts" class="description">' . esc_html( self::counts_text( $scan ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wia_scan">';
		wp_nonce_field( 'wia_scan' );
		echo '<button class="button button-primary">' . esc_html__( 'Scan the site now', 'website-intelligence-agent' ) . '</button></form></div>';

		echo '<div class="wia-card"><h2>' . esc_html__( 'What the assistant reads', 'website-intelligence-agent' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Content type', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Published', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Learned', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Enabled', 'website-intelligence-agent' ) . '</th></tr></thead><tbody>';
		foreach ( WIA_Ingest::coverage() as $row ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $row['label'] ), esc_html( number_format_i18n( $row['count'] ) ), esc_html( number_format_i18n( $row['learned'] ) ), $row['enabled'] ? '✓' : '—' );
		}
		echo '</tbody></table><p class="description">' . esc_html( WIA_Catalog::active() ? __( 'WooCommerce detected: prices and stock are read live.', 'website-intelligence-agent' ) : __( 'WooCommerce is not active.', 'website-intelligence-agent' ) ) . ' ' . esc_html( empty( $settings['engine_enabled'] ) ? __( 'Answers are produced on this server only.', 'website-intelligence-agent' ) : __( 'Questions are forwarded to your self-hosted engine.', 'website-intelligence-agent' ) ) . '</p></div>';
	}

	public static function counts_text( $scan ) {
		$c = isset( $scan['counts'] ) ? (array) $scan['counts'] : array();
		$g = function ( $k ) use ( $c ) { return isset( $c[ $k ] ) ? (int) $c[ $k ] : 0; };
		/* translators: 1: new, 2: updated, 3: unchanged, 4: removed, 5: errors */
		return sprintf( __( 'New %1$d, updated %2$d, unchanged %3$d, removed %4$d, errors %5$d', 'website-intelligence-agent' ), $g( 'new' ), $g( 'updated' ), $g( 'unchanged' ), $g( 'removed' ), $g( 'errors' ) );
	}

	private static function tab_knowledge() {
		$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="wia-card"><h2>' . esc_html__( 'Test a question', 'website-intelligence-agent' ) . '</h2><form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="knowledge"><input type="search" name="q" class="regular-text" value="' . esc_attr( $q ) . '"> <button class="button">' . esc_html__( 'Ask', 'website-intelligence-agent' ) . '</button></form>';
		if ( '' !== $q ) {
			$answer = WIA_Chat::answer( $q );
			echo '<div class="wia-answer"><strong>' . esc_html( $answer['mode'] ) . '</strong><p>' . nl2br( esc_html( $answer['text'] ) ) . '</p></div><ol>';
			foreach ( WIA_Knowledge::search( $q, 5 ) as $hit ) {
				printf( '<li><a href="%s">%s</a> <span class="description">%s %s, %s %s</span><br>%s</li>', esc_url( $hit['url'] ), esc_html( $hit['title'] ), esc_html__( 'score', 'website-intelligence-agent' ), esc_html( $hit['score'] ), esc_html__( 'coverage', 'website-intelligence-agent' ), esc_html( $hit['coverage'] ), esc_html( mb_substr( $hit['body'], 0, 200 ) ) );
			}
			echo '</ol>';
		}
		echo '</div>';
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT id, url, title, content_type, language, status, crawl_date, version FROM ' . WIA_DB::t( 'documents' ) . ' ORDER BY crawl_date DESC LIMIT 100' ); // phpcs:ignore WordPress.DB
		echo '<div class="wia-card"><h2>' . esc_html__( 'Latest documents', 'website-intelligence-agent' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Title', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Type', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Language', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Status', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Checked', 'website-intelligence-agent' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $rows as $row ) {
			printf( '<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_url( $row->url ), esc_html( $row->title ), esc_html( $row->content_type ), esc_html( $row->language ), esc_html( $row->status ), esc_html( get_date_from_gmt( $row->crawl_date ) ) );
		}
		echo '</tbody></table></div>';
	}

	private static function tab_faq() {
		global $wpdb;
		echo '<div class="wia-card"><h2>' . esc_html__( 'Add a question and answer', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'Your own answers are used before anything else. Keywords are optional, comma separated.', 'website-intelligence-agent' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wia_faq"><input type="hidden" name="do" value="add">';
		wp_nonce_field( 'wia_faq' );
		echo '<p><label>' . esc_html__( 'Question', 'website-intelligence-agent' ) . '<br><input type="text" name="question" class="large-text" required dir="auto"></label></p>';
		echo '<p><label>' . esc_html__( 'Answer', 'website-intelligence-agent' ) . '<br><textarea name="answer" class="large-text" rows="3" required dir="auto"></textarea></label></p>';
		echo '<p><label>' . esc_html__( 'Keywords', 'website-intelligence-agent' ) . '<br><input type="text" name="keywords" class="large-text" dir="auto"></label></p>';
		echo '<p><label>' . esc_html__( 'Language', 'website-intelligence-agent' ) . ' <select name="language"><option value="">' . esc_html__( 'Any', 'website-intelligence-agent' ) . '</option><option value="fa">فارسی</option><option value="ar">العربية</option><option value="en">English</option></select></label></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Add', 'website-intelligence-agent' ) . '</button></p></form></div>';
		$rows = $wpdb->get_results( 'SELECT * FROM ' . WIA_DB::t( 'faqs' ) . ' ORDER BY id DESC' ); // phpcs:ignore WordPress.DB
		if ( $rows ) {
			echo '<div class="wia-card"><table class="widefat striped"><tbody>';
			foreach ( $rows as $row ) {
				$del = wp_nonce_url( add_query_arg( array( 'action' => 'wia_faq', 'do' => 'delete', 'id' => (int) $row->id ), admin_url( 'admin-post.php' ) ), 'wia_faq' );
				printf( '<tr><td dir="auto"><b>%s</b><br>%s</td><td><a href="%s">%s</a></td></tr>', esc_html( $row->question ), esc_html( $row->answer ), esc_url( $del ), esc_html__( 'Delete', 'website-intelligence-agent' ) );
			}
			echo '</tbody></table></div>';
		}
	}

	private static function lang_inputs( $name, $values, $rows = 0 ) {
		foreach ( array( 'fa' => 'فارسی', 'ar' => 'العربية', 'en' => 'English' ) as $code => $label ) {
			$value = isset( $values[ $code ] ) ? (string) $values[ $code ] : '';
			if ( $rows ) {
				printf( '<p><label>%s<br><textarea class="large-text" rows="%d" name="%s[%s]" dir="auto">%s</textarea></label></p>', esc_html( $label ), (int) $rows, esc_attr( $name ), esc_attr( $code ), esc_textarea( $value ) );
			} else {
				printf( '<p><label>%s<br><input class="regular-text" type="text" name="%s[%s]" value="%s" dir="auto"></label></p>', esc_html( $label ), esc_attr( $name ), esc_attr( $code ), esc_attr( $value ) );
			}
		}
	}

	private static function form_open( $section ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wia-card"><input type="hidden" name="action" value="wia_save"><input type="hidden" name="section" value="' . esc_attr( $section ) . '">';
		wp_nonce_field( 'wia_save' );
	}

	private static function tab_widget() {
		$s = WIA_DB::settings();
		self::form_open( 'widget' );
		printf( '<p><label><input type="checkbox" name="enabled" value="1" %s> <b>%s</b></label></p>', checked( ! empty( $s['enabled'] ), true, false ), esc_html__( 'Show the chat widget on the site', 'website-intelligence-agent' ) );
		printf( '<p><label>%s <input type="color" name="color" value="%s"></label></p>', esc_html__( 'Color', 'website-intelligence-agent' ), esc_attr( $s['color'] ) );
		printf( '<p><label>%s <select name="position"><option value="right" %s>%s</option><option value="left" %s>%s</option></select></label></p>', esc_html__( 'Position', 'website-intelligence-agent' ), selected( $s['position'], 'right', false ), esc_html__( 'Right', 'website-intelligence-agent' ), selected( $s['position'], 'left', false ), esc_html__( 'Left', 'website-intelligence-agent' ) );
		printf( '<p><label>%s <select name="language"><option value="" %s>%s</option><option value="fa" %s>فارسی</option><option value="ar" %s>العربية</option><option value="en" %s>English</option></select></label></p>', esc_html__( 'Default language', 'website-intelligence-agent' ), selected( $s['language'], '', false ), esc_html__( 'Same as the site', 'website-intelligence-agent' ), selected( $s['language'], 'fa', false ), selected( $s['language'], 'ar', false ), selected( $s['language'], 'en', false ) );
		printf( '<p><label>%s<br><input type="url" class="regular-text" name="avatar_url" value="%s" dir="ltr"></label></p>', esc_html__( 'Logo or avatar URL (https)', 'website-intelligence-agent' ), esc_attr( $s['avatar_url'] ) );
		printf( '<p><label><input type="checkbox" name="show_sources" value="1" %s> %s</label></p>', checked( ! empty( $s['show_sources'] ), true, false ), esc_html__( 'Show answer sources', 'website-intelligence-agent' ) );
		echo '<h2>' . esc_html__( 'Title', 'website-intelligence-agent' ) . '</h2>';
		self::lang_inputs( 'title', $s['title'] );
		echo '<h2>' . esc_html__( 'Welcome message', 'website-intelligence-agent' ) . '</h2>';
		self::lang_inputs( 'welcome', $s['welcome'], 2 );
		echo '<h2>' . esc_html__( 'Message when no answer is found', 'website-intelligence-agent' ) . '</h2>';
		self::lang_inputs( 'not_found', $s['not_found'], 2 );
		echo '<p><button class="button button-primary">' . esc_html__( 'Save', 'website-intelligence-agent' ) . '</button></p></form>';
	}

	private static function tab_chats() {
		global $wpdb;
		$id = isset( $_GET['c'] ) ? sanitize_text_field( wp_unslash( $_GET['c'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $id ) {
			echo '<p><a href="' . esc_url( self::url( 'chats' ) ) . '">' . esc_html__( '← Back to list', 'website-intelligence-agent' ) . '</a></p><div class="wia-card">';
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WIA_DB::t( 'messages' ) . ' WHERE conversation_id = %s ORDER BY id LIMIT 300', $id ) ) as $m ) { // phpcs:ignore WordPress.DB
				printf( '<div class="wia-msg wia-%s" dir="auto">%s<small>%s · %s</small></div>', esc_attr( $m->role ), nl2br( esc_html( $m->body ) ), esc_html( get_date_from_gmt( $m->created_at ) ), esc_html( $m->mode ) );
			}
			echo '</div>';
			return;
		}
		$messages = WIA_DB::t( 'messages' );
		$unanswered = $wpdb->get_results( "SELECT body, created_at FROM {$messages} WHERE role = 'user' AND mode = 'not_found' ORDER BY id DESC LIMIT 30" ); // phpcs:ignore WordPress.DB
		echo '<div class="wia-card"><h2>' . esc_html__( 'Questions without an answer', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'Add content or a question and answer for these.', 'website-intelligence-agent' ) . '</p><ul>';
		foreach ( (array) $unanswered as $row ) {
			echo '<li dir="auto">' . esc_html( $row->body ) . '</li>';
		}
		if ( ! $unanswered ) {
			echo '<li>' . esc_html__( 'None yet.', 'website-intelligence-agent' ) . '</li>';
		}
		echo '</ul></div>';
		$list = $wpdb->get_results( 'SELECT c.id, c.updated_at, COUNT(m.id) n FROM ' . WIA_DB::t( 'conversations' ) . " c LEFT JOIN {$messages} m ON m.conversation_id = c.id GROUP BY c.id, c.updated_at ORDER BY c.updated_at DESC LIMIT 100" ); // phpcs:ignore WordPress.DB
		echo '<div class="wia-card"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Last activity', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Messages', 'website-intelligence-agent' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( (array) $list as $row ) {
			printf( '<tr><td>%s</td><td>%s</td><td><a class="button" href="%s">%s</a></td></tr>', esc_html( get_date_from_gmt( $row->updated_at ) ), esc_html( number_format_i18n( $row->n ) ), esc_url( self::url( 'chats', array( 'c' => $row->id ) ) ), esc_html__( 'View', 'website-intelligence-agent' ) );
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wia_purge">';
		wp_nonce_field( 'wia_purge' );
		echo '<p><button class="button">' . esc_html__( 'Delete all conversations', 'website-intelligence-agent' ) . '</button></p></form></div>';
	}

	private static function tab_advanced() {
		$s = WIA_DB::settings();
		self::form_open( 'advanced' );
		echo '<h2>' . esc_html__( 'Content to learn', 'website-intelligence-agent' ) . '</h2>';
		foreach ( WIA_Ingest::available_types() as $name => $label ) {
			printf( '<p><label><input type="checkbox" name="types[]" value="%s" %s> %s</label></p>', esc_attr( $name ), checked( in_array( $name, (array) $s['types'], true ), true, false ), esc_html( $label ) );
		}
		printf( '<p><label><input type="checkbox" name="taxonomies" value="1" %s> %s</label></p>', checked( ! empty( $s['taxonomies'] ), true, false ), esc_html__( 'Category and brand descriptions', 'website-intelligence-agent' ) );
		echo '<h2>' . esc_html__( 'Privacy', 'website-intelligence-agent' ) . '</h2>';
		printf( '<p><label><input type="checkbox" name="log_messages" value="1" %s> %s</label></p>', checked( ! empty( $s['log_messages'] ), true, false ), esc_html__( 'Keep conversation messages for review', 'website-intelligence-agent' ) );
		printf( '<p><label>%s <input type="number" min="1" max="3650" name="retention_days" value="%d" class="small-text"></label></p>', esc_html__( 'Delete conversations after this many days', 'website-intelligence-agent' ), (int) $s['retention_days'] );
		printf( '<p><label>%s <input type="number" min="1" max="600" name="rate_limit" value="%d" class="small-text"></label></p>', esc_html__( 'Messages allowed per visitor per minute', 'website-intelligence-agent' ), (int) $s['rate_limit'] );
		echo '<h2>' . esc_html__( 'Your own self-hosted engine (optional)', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'Off by default. If you run the Self-Hosted edition on your own server, visitors\' questions can be sent to it for generated answers. Questions then leave this WordPress host and go to the address below. If it does not respond, this site answers on its own.', 'website-intelligence-agent' ) . '</p>';
		printf( '<p><label><input type="checkbox" name="engine_enabled" value="1" %s> %s</label></p>', checked( ! empty( $s['engine_enabled'] ), true, false ), esc_html__( 'Use my self-hosted engine', 'website-intelligence-agent' ) );
		printf( '<p><label>%s<br><input type="url" class="regular-text" name="engine_url" value="%s" dir="ltr" placeholder="https://engine.example.com"></label></p>', esc_html__( 'Engine URL', 'website-intelligence-agent' ), esc_attr( $s['engine_url'] ) );
		printf( '<p><label>%s<br><input type="text" class="regular-text" name="engine_key" value="%s" dir="ltr" autocomplete="off"></label></p>', esc_html__( 'Site key', 'website-intelligence-agent' ), esc_attr( $s['engine_key'] ) );
		echo '<h2>' . esc_html__( 'Uninstall', 'website-intelligence-agent' ) . '</h2>';
		printf( '<p><label><input type="checkbox" name="delete_on_uninstall" value="1" %s> %s</label></p>', checked( '1', (string) get_option( 'wia_delete_on_uninstall', '0' ), false ), esc_html__( 'When the plugin is deleted, also delete all its data (knowledge, questions and answers, conversations, settings).', 'website-intelligence-agent' ) );
		echo '<p><button class="button button-primary">' . esc_html__( 'Save', 'website-intelligence-agent' ) . '</button></p></form>';
	}

	/* ---------- actions ---------- */

	private static function require_admin( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'website-intelligence-agent' ), 403 );
		}
		check_admin_referer( $nonce );
	}

	private static function clean_langs( $key ) {
		$out = array();
		$raw = isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security
		foreach ( array( 'fa', 'ar', 'en' ) as $code ) {
			if ( isset( $raw[ $code ] ) && '' !== trim( (string) $raw[ $code ] ) ) {
				$out[ $code ] = mb_substr( sanitize_textarea_field( (string) $raw[ $code ] ), 0, 500 );
			}
		}
		return $out;
	}

	public static function save() {
		self::require_admin( 'wia_save' );
		$s       = WIA_DB::settings();
		$section = isset( $_POST['section'] ) ? sanitize_key( $_POST['section'] ) : '';
		$tab     = 'widget';
		if ( 'widget' === $section ) {
			$s['enabled']      = empty( $_POST['enabled'] ) ? 0 : 1;
			$color             = isset( $_POST['color'] ) ? sanitize_hex_color( wp_unslash( $_POST['color'] ) ) : '';
			$s['color']        = $color ? $color : $s['color'];
			$s['position']     = isset( $_POST['position'] ) && 'left' === $_POST['position'] ? 'left' : 'right';
			$language          = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';
			$s['language']     = in_array( $language, array( 'fa', 'ar', 'en' ), true ) ? $language : '';
			$avatar            = isset( $_POST['avatar_url'] ) ? esc_url_raw( wp_unslash( $_POST['avatar_url'] ), array( 'https' ) ) : '';
			$s['avatar_url']   = $avatar;
			$s['show_sources'] = empty( $_POST['show_sources'] ) ? 0 : 1;
			$s['title']        = self::clean_langs( 'title' );
			$s['welcome']      = self::clean_langs( 'welcome' );
			$s['not_found']    = self::clean_langs( 'not_found' );
		} elseif ( 'advanced' === $section ) {
			$tab             = 'advanced';
			$available       = array_keys( WIA_Ingest::available_types() );
			$picked          = isset( $_POST['types'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['types'] ) ) : array();
			$old_types       = (array) $s['types'];
			$s['types']      = array_values( array_intersect( $picked, $available ) );
			$s['taxonomies'] = empty( $_POST['taxonomies'] ) ? 0 : 1;
			$s['log_messages']   = empty( $_POST['log_messages'] ) ? 0 : 1;
			$s['retention_days'] = max( 1, min( 3650, absint( isset( $_POST['retention_days'] ) ? $_POST['retention_days'] : 30 ) ) );
			$s['rate_limit']     = max( 1, min( 600, absint( isset( $_POST['rate_limit'] ) ? $_POST['rate_limit'] : 30 ) ) );
			$s['engine_enabled'] = empty( $_POST['engine_enabled'] ) ? 0 : 1;
			$s['engine_url']     = isset( $_POST['engine_url'] ) ? esc_url_raw( wp_unslash( $_POST['engine_url'] ), array( 'http', 'https' ) ) : '';
			$s['engine_key']     = isset( $_POST['engine_key'] ) ? sanitize_text_field( wp_unslash( $_POST['engine_key'] ) ) : '';
			update_option( 'wia_delete_on_uninstall', empty( $_POST['delete_on_uninstall'] ) ? '0' : '1', false );
			if ( $old_types !== $s['types'] ) {
				update_option( 'wia_settings', $s, false );
				WIA_Ingest::start_scan();
			}
		}
		update_option( 'wia_settings', $s, false );
		wp_safe_redirect( self::url( $tab, array( 'saved' => 1 ) ) );
		exit;
	}

	public static function faq_action() {
		self::require_admin( 'wia_faq' );
		global $wpdb;
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		if ( 'add' === $do ) {
			$question = isset( $_POST['question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) : '';
			$answer   = isset( $_POST['answer'] ) ? sanitize_textarea_field( wp_unslash( $_POST['answer'] ) ) : '';
			$language = isset( $_POST['language'] ) ? sanitize_key( $_POST['language'] ) : '';
			if ( '' !== $question && '' !== $answer ) {
				$wpdb->insert( // phpcs:ignore WordPress.DB
					WIA_DB::t( 'faqs' ),
					array(
						'question'   => $question,
						'answer'     => $answer,
						'keywords'   => isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '',
						'language'   => in_array( $language, array( 'fa', 'ar', 'en' ), true ) ? $language : '',
						'created_at' => current_time( 'mysql', true ),
					)
				);
			}
		} elseif ( 'delete' === $do ) {
			$wpdb->delete( WIA_DB::t( 'faqs' ), array( 'id' => isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0 ) ); // phpcs:ignore WordPress.DB
		}
		wp_safe_redirect( self::url( 'faq', array( 'saved' => 1 ) ) );
		exit;
	}

	public static function scan_action() {
		self::require_admin( 'wia_scan' );
		WIA_Ingest::start_scan();
		wp_safe_redirect( self::url( 'status', array( 'saved' => 1 ) ) );
		exit;
	}

	public static function purge_action() {
		self::require_admin( 'wia_purge' );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . WIA_DB::t( 'messages' ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . WIA_DB::t( 'conversations' ) ); // phpcs:ignore WordPress.DB
		wp_safe_redirect( self::url( 'chats', array( 'saved' => 1 ) ) );
		exit;
	}

	/** Runs one batch while the admin watches, because WP-Cron may be slow or disabled on some hosts. */
	public static function scan_step() {
		check_ajax_referer( 'wia_scan_step', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		$scan = WIA_Ingest::run_batch();
		wp_send_json_success( array( 'state' => $scan['state'], 'counts' => self::counts_text( $scan ) ) );
	}
}

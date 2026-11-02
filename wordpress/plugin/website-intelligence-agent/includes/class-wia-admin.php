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
		add_action( 'wp_ajax_wia_ref_step', array( __CLASS__, 'ref_step' ) );
		add_action( 'admin_post_wia_learning', array( __CLASS__, 'learning_action' ) );
		add_action( 'admin_post_wia_graph', array( __CLASS__, 'graph_action' ) );
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
				'ref'   => wp_create_nonce( 'wia_ref_step' ),
				'done'  => __( 'Scan finished.', 'website-intelligence-agent' ),
				'error' => __( 'The scan stopped because of a connection error. It will continue in the background.', 'website-intelligence-agent' ),
				'read'  => __( 'Reading finished. Reload the page to see the results.', 'website-intelligence-agent' ),
			)
		);
		if ( isset( $_GET['tab'] ) && 'map' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_enqueue_script( 'wia-graph', WIA_URL . 'assets/graph.js', array(), WIA_VERSION, true );
		}
	}

	private static function tabs() {
		return array(
			'status'    => __( 'Status', 'website-intelligence-agent' ),
			'map'       => __( 'Knowledge map', 'website-intelligence-agent' ),
			'knowledge' => __( 'Knowledge', 'website-intelligence-agent' ),
			'learning'  => __( 'Learning', 'website-intelligence-agent' ),
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
			echo '<div class="wia-answer"><strong>' . esc_html( $answer['mode'] ) . '</strong><p>' . nl2br( esc_html( $answer['text'] ) ) . '</p>';
			if ( $answer['suggestions'] ) {
				echo '<p class="wia-chips">';
				foreach ( $answer['suggestions'] as $chip ) {
					echo '<a class="wia-chip" href="' . esc_url( self::url( 'knowledge', array( 'q' => $chip ) ) ) . '">' . esc_html( $chip ) . '</a> ';
				}
				echo '</p>';
			}
			echo '</div><ol>';
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
		$prefill = isset( $_GET['prefill'] ) ? sanitize_text_field( wp_unslash( $_GET['prefill'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<p><label>' . esc_html__( 'Question', 'website-intelligence-agent' ) . '<br><input type="text" name="question" class="large-text" required dir="auto" value="' . esc_attr( $prefill ) . '"></label></p>';
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
		echo '<h2>' . esc_html__( 'Suggested questions', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'Shown as buttons under the welcome message, one per line. Leave empty to suggest your main categories.', 'website-intelligence-agent' ) . '</p>';
		self::lang_inputs( 'starters', $s['starters'], 3 );
		$c = $s['contact'];
		echo '<h2>' . esc_html__( 'Contact and handoff', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'When a visitor asks how to reach you, or the assistant misses twice in a row, it offers these. Leave all empty to link your contact page, if the site has one.', 'website-intelligence-agent' ) . '</p>';
		foreach ( array(
			'phone'    => array( __( 'Phone', 'website-intelligence-agent' ), 'text' ),
			'email'    => array( __( 'Email', 'website-intelligence-agent' ), 'email' ),
			'whatsapp' => array( __( 'WhatsApp number, with country code', 'website-intelligence-agent' ), 'text' ),
			'telegram' => array( __( 'Telegram username', 'website-intelligence-agent' ), 'text' ),
			'page'     => array( __( 'Contact page URL', 'website-intelligence-agent' ), 'url' ),
			'hours'    => array( __( 'Response hours', 'website-intelligence-agent' ), 'text' ),
		) as $key => $field ) {
			printf( '<p><label>%s<br><input type="%s" class="regular-text" name="contact[%s]" value="%s" dir="auto"></label></p>', esc_html( $field[0] ), esc_attr( $field[1] ), esc_attr( $key ), esc_attr( $c[ $key ] ) );
		}
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
			$s['starters']     = self::clean_langs( 'starters' );
			$raw               = isset( $_POST['contact'] ) && is_array( $_POST['contact'] ) ? wp_unslash( $_POST['contact'] ) : array(); // phpcs:ignore WordPress.Security
			$g                 = function ( $k ) use ( $raw ) { return isset( $raw[ $k ] ) ? trim( (string) $raw[ $k ] ) : ''; };
			$s['contact']      = array(
				'phone'    => mb_substr( sanitize_text_field( $g( 'phone' ) ), 0, 40 ),
				'email'    => sanitize_email( $g( 'email' ) ),
				'whatsapp' => mb_substr( sanitize_text_field( $g( 'whatsapp' ) ), 0, 40 ),
				'telegram' => mb_substr( sanitize_text_field( $g( 'telegram' ) ), 0, 80 ),
				'page'     => esc_url_raw( $g( 'page' ), array( 'http', 'https' ) ),
				'hours'    => mb_substr( sanitize_text_field( $g( 'hours' ) ), 0, 120 ),
			);
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

	/* ---------- knowledge map ---------- */

	private static function percent( $rate ) {
		return null === $rate ? '—' : number_format_i18n( 100 * $rate ) . '%';
	}

	/** A small line chart as inline SVG. Values are numbers only. */
	private static function spark( $values, $label ) {
		$values = array_values( array_map( 'floatval', (array) $values ) );
		if ( count( $values ) < 2 ) {
			return '<p class="description">' . esc_html__( 'A chart appears after two days of data.', 'website-intelligence-agent' ) . '</p>';
		}
		$max = max( $values );
		$min = min( $values );
		$pts = array();
		foreach ( $values as $i => $v ) {
			$pts[] = round( 4 + 292 * $i / ( count( $values ) - 1 ), 1 ) . ',' . round( 56 - 52 * ( $v - $min ) / ( ( $max - $min ) ? ( $max - $min ) : 1 ), 1 );
		}
		return '<svg class="wia-spark" viewBox="0 0 300 60" role="img" aria-label="' . esc_attr( $label ) . '"><polyline fill="none" stroke="currentColor" stroke-width="2" points="' . esc_attr( implode( ' ', $pts ) ) . '"/></svg>';
	}

	private static function tab_map() {
		$graph = WIA_Graph::get();
		$st    = $graph['stats'];
		echo '<div class="notice notice-info inline"><p>' . esc_html__( 'This map is drawn from what the assistant has really indexed. Green circles are your main topics, blue ones are pages, purple ones are phrases visitors taught it, red ones are words visitors asked about that your site lacks, and orange ones are topics your reference sites cover and you do not. Lines join topics that appear on the same pages.', 'website-intelligence-agent' ) . '</p></div>';
		echo '<div class="wia-stats">';
		// One number per card, with its context in words: combined figures such as "9 / 33%" read backwards in RTL.
		foreach ( array(
			array( __( 'Pages learned', 'website-intelligence-agent' ), number_format_i18n( $st['docs'] ), '' ),
			array( __( 'Words known', 'website-intelligence-agent' ), number_format_i18n( $st['vocabulary'] ), '' ),
			/* translators: %s: number of questions */
			array( __( 'Answered, last 30 days', 'website-intelligence-agent' ), self::percent( $st['rate'] ), sprintf( __( 'of %s questions', 'website-intelligence-agent' ), number_format_i18n( $st['asked'] ) ) ),
			/* translators: %s: number of phrases */
			array( __( 'Phrases learned from visitors', 'website-intelligence-agent' ), number_format_i18n( $st['learned'] ), $st['candidates'] ? sprintf( __( '%s more waiting for votes', 'website-intelligence-agent' ), number_format_i18n( $st['candidates'] ) ) : '' ),
			array( __( 'Unanswered words', 'website-intelligence-agent' ), number_format_i18n( $st['gaps'] ), '' ),
			/* translators: %s: number of pages */
			array( __( 'Reference sites read', 'website-intelligence-agent' ), number_format_i18n( $st['refs']['sites'] ), $st['refs']['sites'] ? sprintf( __( '%s pages', 'website-intelligence-agent' ), number_format_i18n( $st['refs']['pages'] ) ) : '' ),
		) as $card ) {
			echo '<div class="wia-stat"><span>' . esc_html( $card[0] ) . '</span><b>' . esc_html( $card[1] ) . '</b>' . ( '' !== $card[2] ? '<small>' . esc_html( $card[2] ) . '</small>' : '' ) . '</div>';
		}
		echo '</div>';

		echo '<div class="wia-card wia-map-card"><div class="wia-map-head"><h2>' . esc_html__( 'Knowledge map', 'website-intelligence-agent' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wia_graph">';
		wp_nonce_field( 'wia_graph' );
		/* translators: %s: date and time */
		echo '<span class="description">' . esc_html( sprintf( __( 'Built %s', 'website-intelligence-agent' ), get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $graph['built'] ) ) ) ) . '</span> <button class="button">' . esc_html__( 'Rebuild', 'website-intelligence-agent' ) . '</button></form></div>';
		if ( ! $graph['nodes'] ) {
			echo '<p>' . esc_html__( 'Nothing learned yet. Run a scan from the Status tab.', 'website-intelligence-agent' ) . '</p></div>';
		} else {
			echo '<div id="wia-graph" class="wia-graph" data-labels="' . esc_attr( wp_json_encode( array( 'search' => __( 'Find a topic…', 'website-intelligence-agent' ), 'open' => __( 'Open page', 'website-intelligence-agent' ), 'test' => __( 'Ask the assistant', 'website-intelligence-agent' ), 'faq' => __( 'Add an answer', 'website-intelligence-agent' ), 'links' => __( 'Connections', 'website-intelligence-agent' ), 'kinds' => array( 'topic' => __( 'Topic', 'website-intelligence-agent' ), 'doc' => __( 'Page or product', 'website-intelligence-agent' ), 'learned' => __( 'Learned from visitors', 'website-intelligence-agent' ), 'gap' => __( 'Asked, not on the site', 'website-intelligence-agent' ), 'reference' => __( 'Only on reference sites', 'website-intelligence-agent' ) ) ) ) ) . '"><canvas role="img" aria-label="' . esc_attr__( 'Knowledge map', 'website-intelligence-agent' ) . '"></canvas><div class="wia-graph-info" hidden></div></div>';
			echo '<ul class="wia-legend">';
			foreach ( array(
				'topic'     => __( 'Topic', 'website-intelligence-agent' ),
				'doc'       => __( 'Page or product', 'website-intelligence-agent' ),
				'learned'   => __( 'Learned from visitors', 'website-intelligence-agent' ),
				'gap'       => __( 'Asked, not on the site', 'website-intelligence-agent' ),
				'reference' => __( 'Only on reference sites', 'website-intelligence-agent' ),
			) as $kind => $label ) {
				echo '<li><i class="wia-dot wia-' . esc_attr( $kind ) . '"></i>' . esc_html( $label ) . '</li>';
			}
			echo '</ul><p class="description">' . esc_html__( 'Drag to move, scroll to zoom, click a circle for details.', 'website-intelligence-agent' ) . '</p>';
			// Every markup-significant character is hex-escaped, so page titles cannot close the script element.
			echo '<script type="application/json" id="wia-graph-data">' . wp_json_encode( array( 'nodes' => $graph['nodes'], 'edges' => $graph['edges'], 'faq' => self::url( 'faq' ), 'test' => self::url( 'knowledge' ) ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE ) . '</script></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		$history = get_option( 'wia_history', array() );
		$daily   = get_option( 'wia_daily_stats', array() );
		$daily   = is_array( $daily ) ? $daily : array();
		ksort( $daily );
		$rates = array();
		foreach ( $daily as $row ) {
			$good  = ( isset( $row['answered'] ) ? $row['answered'] : 0 ) + ( isset( $row['clarify'] ) ? $row['clarify'] : 0 );
			$asked = $good + ( isset( $row['missed'] ) ? $row['missed'] : 0 );
			if ( $asked ) {
				$rates[] = $good / $asked;
			}
		}
		echo '<div class="wia-grid2"><div class="wia-card"><h2>' . esc_html__( 'Words known over time', 'website-intelligence-agent' ) . '</h2>' . self::spark( wp_list_pluck( is_array( $history ) ? $history : array(), 'terms' ), __( 'Words known over time', 'website-intelligence-agent' ) ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- spark() escapes
		echo '<div class="wia-card"><h2>' . esc_html__( 'Share of questions answered, by day', 'website-intelligence-agent' ) . '</h2>' . self::spark( $rates, __( 'Share of questions answered, by day', 'website-intelligence-agent' ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- spark() escapes

		$gaps = WIA_Learn::open_gaps( 30 );
		echo '<div class="wia-card"><h2>' . esc_html__( 'What visitors ask that your site does not cover', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'Publish content or add a question and answer for these words. A word leaves this list once your site contains it.', 'website-intelligence-agent' ) . '</p>';
		if ( $gaps ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Word', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Times asked', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Last asked', 'website-intelligence-agent' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $gaps as $g ) {
				printf( '<tr><td dir="auto">%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a> · <a href="%s">%s</a></td></tr>', esc_html( $g['word'] ), esc_html( number_format_i18n( $g['count'] ) ), esc_html( $g['last'] ), esc_url( self::url( 'faq', array( 'prefill' => $g['word'] ) ) ), esc_html__( 'Add an answer', 'website-intelligence-agent' ), esc_url( self::url( 'learning', array( 'teach' => $g['word'] ) ) ), esc_html__( 'Point to a page', 'website-intelligence-agent' ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'None yet.', 'website-intelligence-agent' ) . '</p>';
		}
		echo '</div>';

		$missing = WIA_Reference::missing_topics( 30 );
		if ( $missing ) {
			echo '<div class="wia-card"><h2>' . esc_html__( 'Topics your reference sites cover and you do not', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'Ideas for new content. The assistant never answers visitors from other sites.', 'website-intelligence-agent' ) . '</p><p class="wia-chips">';
			foreach ( $missing as $m ) {
				/* translators: 1: number of sites, 2: number of pages */
				echo '<span class="wia-chip" title="' . esc_attr( sprintf( __( '%1$d sites, %2$d pages', 'website-intelligence-agent' ), $m['sources'], $m['pages'] ) ) . '">' . esc_html( $m['term'] ) . '</span> ';
			}
			echo '</p></div>';
		}
	}

	/* ---------- learning ---------- */

	private static function tab_learning() {
		global $wpdb;
		$s = WIA_DB::settings();
		echo '<div class="wia-card"><h2>' . esc_html__( 'Learning from conversations', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'When your site does not contain a visitor\'s word, the assistant offers what it has. If the visitor then settles on a page, that counts as one vote for that word and page. Three votes from different conversations make the phrase active, and later visitors get that page directly. Answers still come only from your own pages.', 'website-intelligence-agent' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wia_learning"><input type="hidden" name="do" value="settings">';
		wp_nonce_field( 'wia_learning' );
		printf( '<p><label><input type="checkbox" name="auto_learn" value="1" %s> %s</label></p>', checked( ! empty( $s['auto_learn'] ), true, false ), esc_html__( 'Activate learned phrases automatically after three votes', 'website-intelligence-agent' ) );
		echo '<h3>' . esc_html__( 'Reference sites', 'website-intelligence-agent' ) . '</h3><p class="description">' . esc_html__( 'Up to five other websites, one address per line, for example competitors or suppliers. Their public pages are read in the background, honouring robots.txt, at most 30 pages each. Only the words they use are kept, not their text, and they are used only for the knowledge map. They are never used to answer visitors.', 'website-intelligence-agent' ) . '</p>';
		$sources = WIA_Reference::sources();
		echo '<p><textarea name="ref_urls" rows="4" class="large-text" dir="ltr" placeholder="https://example.com">' . esc_textarea( implode( "\n", wp_list_pluck( $sources, 'url' ) ) ) . '</textarea></p>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Save', 'website-intelligence-agent' ) . '</button></p></form>';
		$rejected = get_transient( 'wia_ref_rejected' );
		if ( $rejected ) {
			delete_transient( 'wia_ref_rejected' );
			$why = array( 'invalid' => __( 'not a web address', 'website-intelligence-agent' ), 'own_site' => __( 'this is your own site', 'website-intelligence-agent' ), 'not_public' => __( 'not a public address', 'website-intelligence-agent' ), 'limit' => __( 'more than five sites', 'website-intelligence-agent' ) );
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Not added:', 'website-intelligence-agent' ) . '</p><ul>';
			foreach ( (array) $rejected as $url => $reason ) {
				echo '<li dir="ltr">' . esc_html( $url ) . ' — ' . esc_html( isset( $why[ $reason ] ) ? $why[ $reason ] : $reason ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( $sources ) {
			$pending = WIA_Reference::pending();
			echo '<p id="wia-ref-state" data-state="' . ( $pending ? 'running' : 'done' ) . '" class="description">' . esc_html( $pending ? __( 'Reading reference sites…', 'website-intelligence-agent' ) : '' ) . '</p>';
			$status = array( 'queued' => __( 'Waiting', 'website-intelligence-agent' ), 'running' => __( 'Reading', 'website-intelligence-agent' ), 'done' => __( 'Done', 'website-intelligence-agent' ), 'error' => __( 'Failed', 'website-intelligence-agent' ) );
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Site', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Status', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Pages read', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Words', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Skipped by robots.txt', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Checked', 'website-intelligence-agent' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $sources as $row ) {
				$again = wp_nonce_url( add_query_arg( array( 'action' => 'wia_learning', 'do' => 'refetch', 'id' => (int) $row['id'] ), admin_url( 'admin-post.php' ) ), 'wia_learning' );
				printf(
					'<tr><td dir="ltr">%s</td><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a></td></tr>',
					esc_html( $row['url'] ),
					esc_html( isset( $status[ $row['status'] ] ) ? $status[ $row['status'] ] : $row['status'] ),
					'' !== $row['last_error'] ? ' <span class="description">(' . esc_html( $row['last_error'] ) . ')</span>' : '',
					esc_html( number_format_i18n( $row['pages'] ) ),
					esc_html( number_format_i18n( $row['terms'] ) ),
					esc_html( number_format_i18n( $row['skipped'] ) ),
					esc_html( $row['fetched_at'] ? get_date_from_gmt( $row['fetched_at'] ) : '—' ),
					esc_url( $again ),
					esc_html__( 'Read again', 'website-intelligence-agent' )
				);
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		$teach = isset( $_GET['teach'] ) ? sanitize_text_field( wp_unslash( $_GET['teach'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$docs  = $wpdb->get_results( 'SELECT id, title, content_type FROM ' . WIA_DB::t( 'documents' ) . " WHERE status = 'active' ORDER BY content_type, title LIMIT 500" ); // phpcs:ignore WordPress.DB
		echo '<div class="wia-card"><h2>' . esc_html__( 'Teach a phrase', 'website-intelligence-agent' ) . '</h2><p class="description">' . esc_html__( 'For words your visitors use that your pages do not, such as a colloquial name for a product.', 'website-intelligence-agent' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wia_learning"><input type="hidden" name="do" value="teach">';
		wp_nonce_field( 'wia_learning' );
		echo '<p><label>' . esc_html__( 'Visitors\' words', 'website-intelligence-agent' ) . '<br><input type="text" name="phrase" class="regular-text" required dir="auto" value="' . esc_attr( $teach ) . '"></label></p><p><label>' . esc_html__( 'Answer from this page', 'website-intelligence-agent' ) . '<br><select name="doc_id" required>';
		foreach ( (array) $docs as $doc ) {
			printf( '<option value="%d">%s</option>', (int) $doc->id, esc_html( '[' . $doc->content_type . '] ' . wp_strip_all_tags( $doc->title ) ) );
		}
		echo '</select></label></p><p><button class="button button-primary">' . esc_html__( 'Teach', 'website-intelligence-agent' ) . '</button></p></form></div>';

		$aliases = WIA_Learn::aliases();
		echo '<div class="wia-card"><h2>' . esc_html__( 'Learned phrases', 'website-intelligence-agent' ) . '</h2>';
		if ( ! $aliases ) {
			echo '<p>' . esc_html__( 'None yet.', 'website-intelligence-agent' ) . '</p></div>';
			return;
		}
		$labels = array( 'active' => __( 'Active', 'website-intelligence-agent' ), 'candidate' => __( 'Waiting for votes', 'website-intelligence-agent' ), 'rejected' => __( 'Rejected', 'website-intelligence-agent' ) );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Words', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Page', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Votes', 'website-intelligence-agent' ) . '</th><th>' . esc_html__( 'Status', 'website-intelligence-agent' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $aliases as $a ) {
			$links = array();
			foreach ( array( 'active' => __( 'Approve', 'website-intelligence-agent' ), 'rejected' => __( 'Reject', 'website-intelligence-agent' ), 'delete' => __( 'Delete', 'website-intelligence-agent' ) ) as $to => $label ) {
				if ( $to !== $a['status'] ) {
					$links[] = '<a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wia_learning', 'do' => 'alias', 'id' => (int) $a['id'], 'to' => $to ), admin_url( 'admin-post.php' ) ), 'wia_learning' ) ) . '">' . esc_html( $label ) . '</a>';
				}
			}
			printf( '<tr><td dir="auto">%s</td><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( $a['phrase'] ), esc_url( (string) $a['url'] ), esc_html( wp_strip_all_tags( (string) $a['title'] ) ), esc_html( number_format_i18n( $a['votes'] ) ), esc_html( isset( $labels[ $a['status'] ] ) ? $labels[ $a['status'] ] : $a['status'] ), implode( ' · ', $links ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- links escaped above
		}
		echo '</tbody></table></div>';
	}

	public static function learning_action() {
		self::require_admin( 'wia_learning' );
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( $_REQUEST['do'] ) : '';
		if ( 'settings' === $do ) {
			$s               = WIA_DB::settings();
			$s['auto_learn'] = empty( $_POST['auto_learn'] ) ? 0 : 1;
			update_option( 'wia_settings', $s, false );
			$urls     = isset( $_POST['ref_urls'] ) ? preg_split( '/\s+/', sanitize_textarea_field( wp_unslash( $_POST['ref_urls'] ) ) ) : array();
			$rejected = WIA_Reference::set_sources( $urls );
			if ( $rejected ) {
				set_transient( 'wia_ref_rejected', $rejected, 5 * MINUTE_IN_SECONDS );
			}
		} elseif ( 'teach' === $do ) {
			WIA_Learn::teach( isset( $_POST['phrase'] ) ? sanitize_text_field( wp_unslash( $_POST['phrase'] ) ) : '', isset( $_POST['doc_id'] ) ? absint( $_POST['doc_id'] ) : 0 );
			WIA_Graph::forget();
		} elseif ( 'alias' === $do ) {
			WIA_Learn::set_alias_status( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0, isset( $_GET['to'] ) ? sanitize_key( $_GET['to'] ) : '' );
			WIA_Graph::forget();
		} elseif ( 'refetch' === $do ) {
			WIA_Reference::requeue( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		}
		wp_safe_redirect( self::url( 'learning', array( 'saved' => 1 ) ) );
		exit;
	}

	public static function graph_action() {
		self::require_admin( 'wia_graph' );
		WIA_Graph::get( true );
		wp_safe_redirect( self::url( 'map' ) );
		exit;
	}

	/** Reads a batch of reference pages while the admin watches, for hosts where WP-Cron is slow. */
	public static function ref_step() {
		check_ajax_referer( 'wia_ref_step', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		wp_send_json_success( array( 'state' => WIA_Reference::run_batch() ) );
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

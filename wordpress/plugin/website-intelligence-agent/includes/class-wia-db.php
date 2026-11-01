<?php
/**
 * Tables and lifecycle. Activation only creates tables and schedules work. Content is never scanned during activation,
 * so a large site cannot hit the host's PHP time limit there.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_DB {
	const VERSION = '1';

	public static function t( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'wia_' . $name;
	}

	public static function activate() {
		self::install();
		if ( ! wp_next_scheduled( 'wia_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wia_daily' );
		}
		WIA_Ingest::start_scan();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'wia_scan_batch' );
		wp_clear_scheduled_hook( 'wia_daily' );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wia_db_version' ) !== self::VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$documents = self::t( 'documents' );
		$chunks    = self::t( 'chunks' );
		$postings  = self::t( 'postings' );
		$faqs      = self::t( 'faqs' );
		$conv      = self::t( 'conversations' );
		$messages  = self::t( 'messages' );

		dbDelta(
			"CREATE TABLE {$documents} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_type varchar(32) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			url varchar(2048) NOT NULL DEFAULT '',
			title text NULL,
			content_type varchar(20) NOT NULL DEFAULT 'page',
			language varchar(8) NOT NULL DEFAULT '',
			content_hash char(32) NOT NULL DEFAULT '',
			body_hash char(32) NOT NULL DEFAULT '',
			status varchar(12) NOT NULL DEFAULT 'active',
			dup_of bigint(20) unsigned NOT NULL DEFAULT 0,
			version int(11) NOT NULL DEFAULT 1,
			last_modified datetime NULL,
			crawl_date datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source (source_type,source_id),
			KEY status (status),
			KEY body_hash (body_hash)
		) $c;"
		);
		dbDelta(
			"CREATE TABLE {$chunks} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			doc_id bigint(20) unsigned NOT NULL,
			ord int(11) NOT NULL DEFAULT 0,
			heading text NULL,
			body mediumtext NOT NULL,
			language varchar(8) NOT NULL DEFAULT '',
			length int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY doc_id (doc_id)
		) $c;"
		);
		// Binary collation: terms are already normalised, and a case- or accent-folding collation would merge distinct words.
		dbDelta(
			"CREATE TABLE {$postings} (
			term varchar(80) COLLATE utf8mb4_bin NOT NULL,
			chunk_id bigint(20) unsigned NOT NULL,
			tf float NOT NULL DEFAULT 1,
			KEY term (term),
			KEY chunk_id (chunk_id)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;"
		);
		dbDelta(
			"CREATE TABLE {$faqs} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			question text NOT NULL,
			answer text NOT NULL,
			keywords text NULL,
			language varchar(8) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id)
		) $c;"
		);
		dbDelta(
			"CREATE TABLE {$conv} (
			id varchar(40) NOT NULL,
			state text NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY updated_at (updated_at)
		) $c;"
		);
		dbDelta(
			"CREATE TABLE {$messages} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			conversation_id varchar(40) NOT NULL,
			role varchar(10) NOT NULL,
			body text NOT NULL,
			mode varchar(20) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY conversation_id (conversation_id),
			KEY mode (mode),
			KEY created_at (created_at)
		) $c;"
		);
		update_option( 'wia_db_version', self::VERSION, false );
	}

	public static function settings() {
		$defaults = array(
			'enabled'          => 1,
			'types'            => array( 'post', 'page', 'product' ),
			'taxonomies'       => 1,
			'language'         => '',
			'title'            => array(),
			'welcome'          => array(),
			'not_found'        => array(),
			'color'            => '#2563eb',
			'position'         => 'right',
			'avatar_url'       => '',
			'show_sources'     => 1,
			'log_messages'     => 1,
			'retention_days'   => 30,
			'rate_limit'       => 30,
			'engine_enabled'   => 0,
			'engine_url'       => '',
			'engine_key'       => '',
		);
		$saved = get_option( 'wia_settings', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public static function default_language() {
		$s = self::settings();
		if ( in_array( $s['language'], array( 'fa', 'ar', 'en' ), true ) ) {
			return $s['language'];
		}
		$locale = substr( (string) get_locale(), 0, 2 );
		return in_array( $locale, array( 'fa', 'ar' ), true ) ? $locale : 'en';
	}
}

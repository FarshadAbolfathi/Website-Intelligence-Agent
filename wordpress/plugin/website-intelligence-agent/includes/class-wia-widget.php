<?php
/**
 * Front-end widget loader. The script is the same file the self-hosted edition serves.
 *
 * The settings are printed into the page, so the widget needs no request until the visitor sends a message, and
 * the script is deferred so it never delays the page. With page caching, settings changes appear once the
 * cache is cleared.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Widget {
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue() {
		$settings = WIA_DB::settings();
		if ( empty( $settings['enabled'] ) || ! apply_filters( 'wia_show_widget', true ) ) {
			return;
		}
		wp_enqueue_script( 'wia-widget', WIA_URL . 'shared/widget/wia-widget.js', array(), WIA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_add_inline_script(
			'wia-widget',
			'window.WIA_WIDGET=' . wp_json_encode(
				array(
					'endpoint' => esc_url_raw( untrailingslashit( rest_url( 'wia/v1' ) ) ),
					'siteKey'  => 'wp',
					'config'   => WIA_REST::widget(),
				),
				JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE
			) . ';',
			'before'
		);
	}
}

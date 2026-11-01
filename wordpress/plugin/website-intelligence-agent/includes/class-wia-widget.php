<?php
/** Front-end widget loader. The script is the same file the self-hosted edition serves. */
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
		wp_enqueue_script( 'wia-widget', WIA_URL . 'shared/widget/wia-widget.js', array(), WIA_VERSION, true );
		wp_add_inline_script(
			'wia-widget',
			'window.WIA_WIDGET=' . wp_json_encode( array( 'endpoint' => esc_url_raw( untrailingslashit( rest_url( 'wia/v1' ) ) ), 'siteKey' => 'wp' ) ) . ';',
			'before'
		);
	}
}

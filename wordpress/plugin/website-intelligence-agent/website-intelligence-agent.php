<?php
/**
 * Plugin Name:       Website Intelligence Agent Lite
 * Plugin URI:        https://github.com/FarshadAbolfathi/Website-Intelligence-Agent
 * Description:       A site assistant that learns your pages, posts and WooCommerce products and answers visitors from them, in Persian, Arabic and English. Runs on ordinary shared hosting with no external AI service. It finds and quotes your content; it does not include a generative language model.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Farshad Abolfathi
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       website-intelligence-agent
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WIA_VERSION', '0.2.0' );
define( 'WIA_FILE', __FILE__ );
define( 'WIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'WIA_URL', plugin_dir_url( __FILE__ ) );

require WIA_DIR . 'includes/class-wia-text.php';
require WIA_DIR . 'includes/class-wia-db.php';
require WIA_DIR . 'includes/class-wia-knowledge.php';
require WIA_DIR . 'includes/class-wia-ingest.php';
require WIA_DIR . 'includes/class-wia-catalog.php';
require WIA_DIR . 'includes/class-wia-chat.php';
require WIA_DIR . 'includes/class-wia-learn.php';
require WIA_DIR . 'includes/class-wia-graph.php';
require WIA_DIR . 'includes/class-wia-reference.php';
require WIA_DIR . 'includes/class-wia-rest.php';
require WIA_DIR . 'includes/class-wia-widget.php';

register_activation_hook( __FILE__, array( 'WIA_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WIA_DB', 'deactivate' ) );

add_action( 'plugins_loaded', 'wia_boot' );

function wia_boot() {
	load_plugin_textdomain( 'website-intelligence-agent', false, dirname( plugin_basename( WIA_FILE ) ) . '/languages' );
	WIA_DB::maybe_upgrade();
	WIA_Ingest::init();
	WIA_REST::init();
	WIA_Reference::init();
	WIA_Widget::init();
	if ( is_admin() ) {
		require_once WIA_DIR . 'includes/class-wia-admin.php';
		WIA_Admin::init();
	}
}

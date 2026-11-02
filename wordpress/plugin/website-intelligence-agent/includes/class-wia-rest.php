<?php
/**
 * Public REST API, with the same request and response shape as the self-hosted engine's /public/v1, so one widget
 * serves both editions. It uses no nonce on purpose: a nonce embedded in a cached page expires and breaks chat for
 * every visitor behind page caching. The endpoints only read public content, and are protected by a rate limit.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_REST {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'wia/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true', // public by design, see the class comment
				'callback'            => array( __CLASS__, 'chat' ),
				'args'                => array(
					'message'         => array( 'required' => true, 'type' => 'string', 'validate_callback' => array( __CLASS__, 'valid_message' ) ),
					'conversation_id' => array( 'required' => false ),
					'site_key'        => array( 'required' => false ),
				),
			)
		);
		register_rest_route(
			'wia/v1',
			'/widget',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'widget' ),
			)
		);
	}

	public static function valid_message( $value ) {
		return is_string( $value ) && '' !== trim( $value ) && mb_strlen( $value ) <= 1000;
	}

	private static function rate_ok() {
		$settings = WIA_DB::settings();
		// The address is hashed with a site secret, so the transient table holds no visitor IPs.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'wia_rl_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 24 );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) $settings['rate_limit'] ) {
			return false;
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
		return true;
	}

	public static function chat( WP_REST_Request $request ) {
		$settings = WIA_DB::settings();
		if ( empty( $settings['enabled'] ) ) {
			return new WP_Error( 'wia_disabled', 'assistant disabled', array( 'status' => 403 ) );
		}
		if ( ! self::rate_ok() ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429, array( 'Retry-After' => '60' ) );
		}
		$question     = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		$conversation = WIA_Chat::load_conversation( (string) $request->get_param( 'conversation_id' ) );
		$state        = $conversation ? $conversation['state'] : array();
		$id           = $conversation ? $conversation['id'] : '';

		$remote = WIA_Chat::ask_engine( $question, isset( $state['engine_conversation'] ) ? $state['engine_conversation'] : '' );
		if ( $remote ) {
			$state['engine_conversation'] = isset( $remote['conversation_id'] ) ? (string) $remote['conversation_id'] : '';
			$answer = array(
				'suggestions' => isset( $remote['suggestions'] ) && is_array( $remote['suggestions'] ) ? $remote['suggestions'] : array(),
				'actions'     => array(),
				'text'     => (string) $remote['answer'],
				'language' => isset( $remote['language'] ) ? (string) $remote['language'] : 'en',
				'mode'     => isset( $remote['mode'] ) ? 'engine_' . sanitize_key( $remote['mode'] ) : 'engine',
				'sources'  => isset( $remote['sources'] ) && is_array( $remote['sources'] ) ? $remote['sources'] : array(),
				'stale'    => ! empty( $remote['stale'] ),
			);
		} else {
			$answer = WIA_Chat::converse( $question, $state );
		}
		$id = WIA_Chat::save_turn( $id, $state, $question, $answer );
		$response = new WP_REST_Response(
			array(
				'conversation_id' => $id,
				'answer'          => $answer['text'],
				'language'        => $answer['language'],
				'mode'            => $answer['mode'],
				'sources'         => empty( $settings['show_sources'] ) ? array() : $answer['sources'],
				'stale'           => (bool) $answer['stale'],
				'suggestions'     => array_values( array_slice( array_map( 'strval', (array) $answer['suggestions'] ), 0, 4 ) ),
				'actions'         => array_values( array_filter( (array) $answer['actions'], array( __CLASS__, 'safe_action' ) ) ),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Contact buttons may only be web, phone or mail links. */
	public static function safe_action( $action ) {
		return is_array( $action ) && isset( $action['url'], $action['label'] ) && preg_match( '#^(?:https?://|tel:\+?[0-9]+$|mailto:[^\s<>"]+$)#i', (string) $action['url'] );
	}

	public static function widget() {
		$s = WIA_DB::settings();
		return array(
			'enabled'      => (bool) $s['enabled'],
			'title'        => (array) $s['title'],
			'welcome'      => (array) $s['welcome'],
			'not_found'    => (array) $s['not_found'],
			'color'        => (string) $s['color'],
			'position'     => (string) $s['position'],
			'language'     => WIA_DB::default_language(),
			'avatar_url'   => (string) $s['avatar_url'],
			'show_sources' => (bool) $s['show_sources'],
			'starters'     => WIA_Chat::starters( WIA_DB::default_language() ),
		);
	}
}

<?php
/**
 * WooCommerce facts. Price and stock are always read live from WooCommerce and are never copied into the index,
 * so the Lite edition cannot quote an out-of-date price.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WIA_Catalog {
	public static function active() {
		return function_exists( 'wc_get_product' );
	}

	/** Attributes, categories and SKU: searchable, and not volatile. */
	public static function searchable_facts( $product_id ) {
		$out = array( 'text' => '', 'boost' => '' );
		if ( ! self::active() ) {
			return $out;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return $out;
		}
		$lines = array();
		foreach ( $product->get_attributes() as $attribute ) {
			$label  = wc_attribute_label( $attribute->get_name() );
			$values = $attribute->is_taxonomy() ? wc_get_product_terms( $product_id, $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
			if ( $values ) {
				$lines[] = $label . ': ' . implode( '، ', (array) $values );
			}
		}
		$cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $cats ) && $cats ) {
			$out['boost'] .= implode( ' ', $cats );
		}
		$out['boost'] .= ' ' . $product->get_sku();
		$out['text']   = implode( "\n", $lines );
		return $out;
	}

	public static function number( $value, $lang ) {
		$value = (float) $value;
		$text  = floor( $value ) === $value ? number_format( $value ) : number_format( $value, 2 );
		return 'fa' === $lang ? strtr( $text, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹', ',' => '٬' ) ) : $text;
	}

	public static function currency( $lang, $table ) {
		$code = get_woocommerce_currency();
		return isset( $table[ $code ][ $lang ] ) ? $table[ $code ][ $lang ] : html_entity_decode( get_woocommerce_currency_symbol( $code ), ENT_QUOTES, 'UTF-8' );
	}

	/** Live facts, or null if the product no longer exists or is not public. */
	public static function live( $product_id ) {
		if ( ! self::active() ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() || 'hidden' === $product->get_catalog_visibility() ) {
			return null;
		}
		$status = $product->get_stock_status();
		return array(
			'id'           => $product->get_id(),
			'name'         => wp_strip_all_tags( $product->get_name() ),
			'price'        => $product->get_price(),
			'availability' => 'instock' === $status ? 'in_stock' : ( 'onbackorder' === $status ? 'preorder' : 'out_of_stock' ),
			'url'          => (string) get_permalink( $product->get_id() ),
		);
	}

	public static function listing( $limit = 8 ) {
		if ( ! self::active() ) {
			return array();
		}
		$out = array();
		foreach ( wc_get_products( array( 'limit' => $limit, 'status' => 'publish', 'visibility' => 'catalog', 'orderby' => 'date', 'order' => 'DESC' ) ) as $product ) {
			$out[] = array( 'name' => wp_strip_all_tags( $product->get_name() ), 'url' => (string) get_permalink( $product->get_id() ) );
		}
		return $out;
	}
}

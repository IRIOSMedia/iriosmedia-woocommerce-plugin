<?php

namespace Irios\WooCommerce;

/**
 * Stock changes notify the store's "Product updated" webhooks, so the IRIOS Media sync service learns about them
 * within minutes, like any other product change.
 *
 * A sale (or anything else that calls wc_update_product_stock / wc_update_product_stock_status) writes the stock
 * directly and does not fire woocommerce_update_product, so WooCommerce delivers no product.updated webhook and a
 * product that runs out keeps being advertised until the next periodic read. Here every stock or stock status change
 * fires ACTION with the product id, and ACTION is added to the hooks of the product.updated topic: WooCommerce's own
 * webhook machinery delivers it (signature, retries, logs, disabling after failures), with no new webhook, URL or
 * secret. Without a Product updated webhook, nothing is delivered.
 *
 * A variation is reported as its parent product: the sync service reads the parent with all its variations, and the
 * product.updated webhook is meant for products, not variations.
 *
 * Duplicates in one request (several variations of the same product, stock and stock status of one product, a stock
 * change inside a product save) are left to WooCommerce: each webhook delivers a given argument at most once per
 * request (WC_Webhook::is_already_processed, a strict comparison). That is why the id is always an int, the same type
 * woocommerce_update_product passes.
 *
 * Always active, like the API for the sync service (Feed): it only does something when the store has created a
 * Product updated webhook.
 */
final class Stock {

	public const ACTION = 'irios_media_product_stock_changed';
	public const TOPIC  = 'product.updated';

	public static function register(): void {
		add_action( 'woocommerce_product_set_stock', array( self::class, 'stock_set' ) );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'stock_set' ) );
		add_action( 'woocommerce_product_set_stock_status', array( self::class, 'stock_status_set' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( self::class, 'stock_status_set' ), 10, 3 );
		add_filter( 'woocommerce_webhook_topic_hooks', array( self::class, 'topic_hooks' ) );
	}

	/**
	 * The product.updated topic also listens to ACTION; the topic's other hooks and the other topics are kept.
	 *
	 * @param mixed $topic_hooks Topic → hooks.
	 * @return mixed
	 */
	public static function topic_hooks( $topic_hooks ) {
		if ( ! is_array( $topic_hooks ) ) {
			return $topic_hooks;
		}
		$hooks = isset( $topic_hooks[ self::TOPIC ] ) && is_array( $topic_hooks[ self::TOPIC ] ) ? $topic_hooks[ self::TOPIC ] : array();
		if ( ! in_array( self::ACTION, $hooks, true ) ) {
			$hooks[] = self::ACTION;
		}
		$topic_hooks[ self::TOPIC ] = $hooks;
		return $topic_hooks;
	}

	/**
	 * woocommerce_product_set_stock / woocommerce_variation_set_stock.
	 *
	 * @param mixed $product The product whose stock quantity changed.
	 */
	public static function stock_set( $product ): void {
		if ( $product instanceof \WC_Product ) {
			self::notify( self::product_id( $product ) );
		}
	}

	/**
	 * woocommerce_product_set_stock_status / woocommerce_variation_set_stock_status.
	 *
	 * @param mixed $product_id Id of the product whose stock status changed.
	 * @param mixed $status     New stock status (not used: the sync service reads the product again).
	 * @param mixed $product    The product, when WooCommerce passes it.
	 */
	public static function stock_status_set( $product_id, $status = '', $product = null ): void {
		if ( ! $product instanceof \WC_Product ) {
			$product = wc_get_product( absint( $product_id ) );
		}
		if ( $product instanceof \WC_Product ) {
			self::notify( self::product_id( $product ) );
		}
	}

	/** Id the sync service reads: the parent of a variation, the product itself otherwise. */
	public static function product_id( \WC_Product $product ): int {
		return $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();
	}

	private static function notify( int $product_id ): void {
		if ( $product_id > 0 ) {
			do_action( self::ACTION, $product_id );
		}
	}
}

<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Irios\WooCommerce\Stock;

/** Stock changes notify the product.updated webhooks with the product id (the parent of a variation). */
final class StockTest extends WordPressTestCase {

	/** Ids passed to Stock::ACTION in this request, in order. */
	private array $notified = array();

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'absint' )->alias( fn ( $value ) => abs( (int) $value ) );
		Actions\expectDone( Stock::ACTION )->zeroOrMoreTimes()->whenHappen(
			function ( $product_id ) {
				$this->notified[] = $product_id;
			}
		);
	}

	public function test_registers_the_stock_hooks_and_the_topic_filter(): void {
		Stock::register();

		$this->assertNotFalse( has_action( 'woocommerce_product_set_stock', array( Stock::class, 'stock_set' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_variation_set_stock', array( Stock::class, 'stock_set' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_product_set_stock_status', array( Stock::class, 'stock_status_set' ) ) );
		$this->assertNotFalse( has_action( 'woocommerce_variation_set_stock_status', array( Stock::class, 'stock_status_set' ) ) );
		$this->assertNotFalse( has_filter( 'woocommerce_webhook_topic_hooks', array( Stock::class, 'topic_hooks' ) ) );
	}

	public function test_a_simple_product_notifies_its_own_id(): void {
		Stock::stock_set( new \WC_Product( 10 ) );

		$this->assertSame( array( 10 ), $this->notified );
	}

	public function test_a_variation_notifies_its_parent_id(): void {
		Stock::stock_set( ( new \WC_Product( 21, 10.0, 'variation' ) )->with( 'publish', true, 20 ) );

		$this->assertSame( array( 20 ), $this->notified );
	}

	public function test_a_stock_status_change_notifies_the_product_or_the_parent(): void {
		Stock::stock_status_set( 10, 'outofstock', new \WC_Product( 10 ) );
		Stock::stock_status_set( 21, 'outofstock', ( new \WC_Product( 21, 10.0, 'variation' ) )->with( 'publish', true, 20 ) );

		$this->assertSame( array( 10, 20 ), $this->notified );
	}

	public function test_a_stock_status_change_without_the_product_reads_it(): void {
		$this->given_products( ( new \WC_Product( 21, 10.0, 'variation' ) )->with( 'publish', true, 20 ) );

		Stock::stock_status_set( '21', 'instock' );
		Stock::stock_status_set( 99, 'instock' ); // no longer exists

		$this->assertSame( array( 20 ), $this->notified );
	}

	public function test_notifies_nothing_without_a_product_or_for_a_variation_without_parent(): void {
		Stock::stock_set( null );
		Stock::stock_set( 10 );
		Stock::stock_set( new \WC_Product( 21, 10.0, 'variation' ) );

		$this->assertSame( array(), $this->notified );
	}

	public function test_the_ids_are_ints_so_woocommerce_delivers_each_product_once_per_request(): void {
		// Checkout of two variations of product 20 and a simple product 10: stock and stock status of each one
		$variation_a = ( new \WC_Product( 21, 10.0, 'variation' ) )->with( 'publish', true, 20 );
		$variation_b = ( new \WC_Product( 22, 10.0, 'variation' ) )->with( 'publish', true, 20 );
		$simple      = new \WC_Product( 10 );
		foreach ( array( $variation_a, $variation_b, $simple ) as $product ) {
			Stock::stock_set( $product );
			Stock::stock_status_set( $product->get_id(), 'outofstock', $product );
		}

		// What each WC_Webhook does: an argument already processed in this request is not delivered again (strict)
		$delivered = array();
		foreach ( array_merge( $this->notified, array( 10 ) ) as $arg ) { // 10 again: woocommerce_update_product
			if ( false === array_search( $arg, $delivered, true ) ) {
				$delivered[] = $arg;
			}
		}
		$this->assertContainsOnly( 'int', $this->notified );
		$this->assertSame( array( 20, 10 ), $delivered );
	}

	public function test_the_product_updated_topic_also_listens_to_stock_changes(): void {
		$topic_hooks = array(
			'product.created' => array( 'woocommerce_new_product' ),
			'product.updated' => array( 'woocommerce_process_product_meta', 'woocommerce_update_product', 'woocommerce_update_product_variation' ),
		);

		$filtered = Stock::topic_hooks( $topic_hooks );

		$this->assertSame(
			array( 'woocommerce_process_product_meta', 'woocommerce_update_product', 'woocommerce_update_product_variation', Stock::ACTION ),
			$filtered['product.updated']
		);
		$this->assertSame( $topic_hooks['product.created'], $filtered['product.created'] );
		$this->assertSame( $filtered, Stock::topic_hooks( $filtered ) ); // added once
	}

	public function test_the_topic_filter_leaves_unexpected_values_alone(): void {
		$this->assertSame( 'not a list', Stock::topic_hooks( 'not a list' ) );
		$this->assertSame( array( 'product.updated' => array( Stock::ACTION ) ), Stock::topic_hooks( array() ) );
	}
}

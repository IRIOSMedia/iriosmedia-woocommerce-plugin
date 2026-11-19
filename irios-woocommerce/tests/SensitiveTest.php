<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Events;
use Irios\WooCommerce\Feed;
use Irios\WooCommerce\Integration;
use Irios\WooCommerce\Sensitive;
use Irios\WooCommerce\Settings;

/**
 * Sensitive categories (health…): nothing about them reaches IRIOS Media tied to a person. Marking a category covers
 * all its subcategories; a product is sensitive if any of its categories is (a variation, through its parent); and
 * whatever cannot be checked counts as sensitive.
 */
final class SensitiveTest extends WordPressTestCase {

	/**
	 * Categories: 1 Health > 2 Allergy > 3 Eye drops; 4 Sports (root). Products: 10 in Eye drops, 20 in Sports, 21 a
	 * variation of 20, 30 in Sports and Allergy, 40 without categories, 50 whose categories cannot be read. 99 does
	 * not exist.
	 */
	protected function setUp(): void {
		parent::setUp();
		$terms   = array(
			1 => new \WP_Term( 1, 'Health' ),
			2 => new \WP_Term( 2, 'Allergy', 1 ),
			3 => new \WP_Term( 3, 'Eye drops', 2 ),
			4 => new \WP_Term( 4, 'Sports' ),
		);
		$parents = array( 2 => array( 1 ), 3 => array( 2, 1 ) );
		$types   = array( 10 => 'product', 20 => 'product', 21 => 'product_variation', 30 => 'product', 40 => 'product', 50 => 'product' );
		$cats    = array(
			10 => array( $terms[3] ),
			20 => array( $terms[4] ),
			30 => array( $terms[4], $terms[2] ),
			40 => false,
			50 => new \WP_Error(),
		);
		Functions\when( 'get_ancestors' )->alias( fn ( $id ) => $parents[ $id ] ?? array() );
		Functions\when( 'get_post_type' )->alias( fn ( $id ) => $types[ $id ] ?? false );
		Functions\when( 'wp_get_post_parent_id' )->alias( fn ( $id ) => 21 === $id ? 20 : 0 );
		Functions\when( 'get_the_terms' )->alias( fn ( $id ) => $cats[ $id ] ?? false );
		Functions\when( 'is_wp_error' )->alias( fn ( $thing ) => $thing instanceof \WP_Error );
		Functions\when( 'get_terms' )->justReturn( array_values( $terms ) );
		Functions\when( 'get_term_by' )->alias(
			fn ( $field, $slug ) => array( 'health' => $terms[1], 'eye-drops' => $terms[3], 'sports' => $terms[4] )[ $slug ] ?? false
		);
		Functions\when( 'get_query_var' )->justReturn( '' );
		$this->given_page();
	}

	/** Categories marked as sensitive in the settings. */
	private function given_marked( string $ids, string $searches = 'no' ): Settings {
		$this->settings['sensitive_categories'] = $ids;
		$this->settings['sensitive_searches']   = $searches;
		Settings::forget();
		return Settings::load();
	}

	/** The WordPress conditionals that are true on the page being viewed (all the others, false). */
	private function given_page( string ...$true ): void {
		foreach ( array( 'is_search', 'is_product_category', 'is_product' ) as $conditional ) {
			Functions\when( $conditional )->justReturn( in_array( $conditional, $true, true ) );
		}
	}

	// ---- What is sensitive ---------------------------------------------------------------------------------------

	public function test_marking_a_category_covers_all_its_subcategories_but_not_its_parent(): void {
		$this->given_marked( '2' );

		$this->assertTrue( Sensitive::category( 2 ) );
		$this->assertTrue( Sensitive::category( 3 ), 'a subcategory, also one created later' );
		$this->assertFalse( Sensitive::category( 1 ) );
		$this->assertFalse( Sensitive::category( 4 ) );
	}

	public function test_a_product_is_sensitive_if_any_of_its_categories_is(): void {
		$this->given_marked( '1' );

		$this->assertTrue( Sensitive::product( 10 ), 'in a subcategory of a marked one' );
		$this->assertTrue( Sensitive::product( 30 ), 'in a sensitive and in another category' );
		$this->assertFalse( Sensitive::product( 20 ) );
		$this->assertFalse( Sensitive::product( 40 ), 'without categories' );
	}

	public function test_a_variation_is_sensitive_through_its_parent_product(): void {
		$this->given_marked( '4' );

		$this->assertTrue( Sensitive::product( 21 ) );
	}

	public function test_what_cannot_be_checked_is_sensitive(): void {
		$this->given_marked( '1' );

		$this->assertTrue( Sensitive::product( 99 ), 'a product that no longer exists' );
		$this->assertTrue( Sensitive::product( 0 ), 'a line without a product' );
		$this->assertTrue( Sensitive::product( 50 ), 'categories that cannot be read' );
	}

	public function test_without_sensitive_categories_nothing_is_sensitive_not_even_an_unknown_product(): void {
		$this->given_marked( '' );

		$this->assertFalse( Sensitive::product( 10 ) );
		$this->assertFalse( Sensitive::product( 99 ) );
		$this->assertFalse( Sensitive::category( 3 ) );
	}

	// ---- The page: printed for the storefront JS -----------------------------------------------------------------

	public function test_the_page_of_a_sensitive_product_or_category_is_sensitive(): void {
		$settings = $this->given_marked( '2' );
		Functions\when( 'get_queried_object_id' )->justReturn( 10 );
		$this->given_page( 'is_product' );
		$this->assertTrue( Sensitive::page( $settings ) );

		Functions\when( 'get_queried_object_id' )->justReturn( 20 );
		$this->assertFalse( Sensitive::page( $settings ) );

		Functions\when( 'get_queried_object' )->justReturn( new \WP_Term( 3, 'Eye drops', 2 ) );
		$this->given_page( 'is_product_category' );
		$this->assertTrue( Sensitive::page( $settings ) );

		Functions\when( 'get_queried_object' )->justReturn( new \WP_Term( 1, 'Health' ) );
		$this->assertFalse( Sensitive::page( $settings ), 'the parent of a marked category is not' );

		$this->given_page();
		$this->assertFalse( Sensitive::page( $settings ), 'any other page' );
	}

	public function test_a_search_is_sensitive_when_all_searches_are_or_it_is_filtered_by_a_sensitive_category(): void {
		$this->given_page( 'is_search' );
		$query = array( 'post_type' => 'product' );
		Functions\when( 'get_query_var' )->alias( function ( $name ) use ( &$query ) {
			return $query[ $name ] ?? '';
		} );

		$this->assertFalse( Sensitive::page( $this->given_marked( '2' ) ) );
		$this->assertTrue( Sensitive::page( $this->given_marked( '', 'yes' ) ) );

		$query['product_cat'] = 'eye-drops';
		$this->assertTrue( Sensitive::page( $this->given_marked( '2' ) ) );
		$query['product_cat'] = 'sports';
		$this->assertFalse( Sensitive::page( $this->given_marked( '2' ) ) );
		$query['product_cat'] = 'no-such-category';
		$this->assertTrue( Sensitive::page( $this->given_marked( '2' ) ), 'a category that cannot be found' );
	}

	public function test_the_page_data_says_whether_the_page_is_sensitive(): void {
		$this->given_marked( '2' );
		$this->given_page( 'is_product_category' );
		Functions\when( 'get_queried_object' )->justReturn( new \WP_Term( 3, 'Eye drops', 2 ) );
		foreach ( array( 'is_front_page', 'is_order_received_page', 'is_cart', 'is_checkout', 'is_shop', 'is_product_taxonomy' ) as $conditional ) {
			Functions\when( $conditional )->justReturn( false );
		}
		Functions\when( 'get_term' )->alias( fn ( $id ) => array( 1 => new \WP_Term( 1, 'Health' ), 2 => new \WP_Term( 2, 'Allergy', 1 ) )[ $id ] ?? null );

		$data = Events::page_data( Settings::load() );

		$this->assertTrue( $data['sensitive'] );
		$this->assertSame( 'CATEGORY', $data['pageType'], 'its ads are still requested, without identifiers' );

		Functions\when( 'get_queried_object' )->justReturn( new \WP_Term( 4, 'Sports' ) );
		$this->assertArrayNotHasKey( 'sensitive', Events::page_data( Settings::load() ) );
	}

	// ---- Cart events (server) ------------------------------------------------------------------------------------

	public function test_a_cart_event_keeps_only_the_products_that_are_not_sensitive(): void {
		$this->given_consent();
		$this->given_marked( '1' );
		$this->given_products( new \WC_Product( 10, 12.5 ), new \WC_Product( 20, 30.0 ) );
		Functions\when( 'is_cart' )->justReturn( true );
		$this->wc->cart->cart_contents = array(
			'a' => array( 'data' => new \WC_Product( 10, 12.5 ), 'quantity' => 1 ),
			'b' => array( 'data' => new \WC_Product( 20, 30.0 ), 'quantity' => 2 ),
		);

		Events::on_add_to_cart( 'a', 10, 1, 0 );
		Events::on_template_redirect();

		$pending = $this->pending_for( 'visitor-1' );
		$this->assertCount( 1, $pending, 'adding a sensitive product is no event' );
		$this->assertSame( 'cart-view', $pending[0]['id'] );
		$this->assertSame( array( '20' ), array_column( $pending[0]['products'], 'id' ) );
	}

	public function test_a_single_result_search_leaves_no_search_event_when_searches_are_sensitive(): void {
		$this->given_consent();
		$this->given_marked( '', 'yes' );
		Functions\when( 'get_search_query' )->justReturn( 'eye drops' );

		$this->assertTrue( Events::on_single_search_result( true ) );
		$this->assertSame( array(), $this->pending_for( 'visitor-1' ) );
	}

	// ---- Orders feed for the sync service ------------------------------------------------------------------------

	/** An order without consent (no personal data to mock) with lines of those products. */
	private function order( int $id, int ...$products ): \WC_Order {
		$order = new class() extends \WC_Order {
			public int $id = 0;
			public array $items = array();

			public function __call( string $name, array $args ) {
				return array(
					'get_type'  => 'shop_order',
					'get_total' => '10.00',
				)[ $name ] ?? null;
			}

			public function get_id() {
				return $this->id;
			}

			public function get_items() {
				return $this->items;
			}
		};
		$order->id = $id;
		foreach ( $products as $product ) {
			$order->items[] = new \WC_Order_Item_Product( array( 'product_id' => $product, 'variation_id' => 0, 'quantity' => 1 ) );
		}
		return $order;
	}

	public function test_the_feed_leaves_out_the_orders_with_a_sensitive_product(): void {
		$this->given_marked( '2' );
		Functions\when( 'wc_format_decimal' )->alias( fn ( $v ) => (string) $v );
		Functions\when( 'wc_get_orders' )->justReturn(
			(object) array(
				'orders'        => array( $this->order( 1, 20 ), $this->order( 2, 20, 10 ), $this->order( 3, 30 ), $this->order( 4, 99 ), $this->order( 5, 21 ) ),
				'total'         => 5,
				'max_num_pages' => 1,
			)
		);

		$response = Feed::orders( new \WP_REST_Request( array( 'status' => 'processing', 'per_page' => 50, 'page' => 1 ) ) );

		$this->assertSame( array( 1, 5 ), array_column( $response->get_data(), 'id' ) );
		$this->assertSame( '5', $response->headers['X-WP-Total'], 'the pages keep their size and number' );
	}

	public function test_an_order_with_a_sensitive_product_does_not_exist_for_the_sync_service(): void {
		$this->given_marked( '2' );
		Functions\when( 'wc_format_decimal' )->alias( fn ( $v ) => (string) $v );
		$orders = array( 1 => $this->order( 1, 20 ), 2 => $this->order( 2, 10 ) );
		Functions\when( 'wc_get_order' )->alias( fn ( $id ) => $orders[ $id ] ?? false );

		$this->assertSame( 1, Feed::order( new \WP_REST_Request( array( 'id' => 1 ) ) )->get_data()['id'] );
		$this->assertInstanceOf( \WP_Error::class, Feed::order( new \WP_REST_Request( array( 'id' => 2 ) ) ) );
	}

	// ---- Settings ------------------------------------------------------------------------------------------------

	public function test_by_default_nothing_is_sensitive(): void {
		$settings = new Settings( array() );

		$this->assertSame( array(), $settings->sensitive_categories() );
		$this->assertFalse( $settings->sensitive_searches() );
	}

	public function test_only_existing_product_categories_can_be_marked(): void {
		$result = Settings::sanitize_sensitive_categories( array( '3', '1', '3', '77', 'abc', '' ), array( 1, 2, 3, 4 ) );

		$this->assertSame( array( 3, 1 ), $result['ids'] );
		$this->assertCount( 2, $result['errors'] );
		$this->assertStringContainsString( '"77"', $result['errors'][0] );
	}

	public function test_the_settings_screen_lists_every_category_with_its_path_and_the_marked_ones_selected(): void {
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		$integration           = ( new \ReflectionClass( Integration::class ) )->newInstanceWithoutConstructor();
		$integration->id       = 'irios';
		$integration->settings = array( 'sensitive_categories' => '2' );

		$html = $integration->generate_irios_categories_html( 'sensitive_categories', array( 'title' => 'Sensitive categories' ) );

		$this->assertStringContainsString( 'name="woocommerce_irios_sensitive_categories[]"', $html );
		$this->assertStringContainsString( '<option value="2" selected="selected">Health > Allergy</option>', $html );
		$this->assertStringContainsString( '<option value="3">Health > Allergy > Eye drops</option>', $html );
		$this->assertStringContainsString( '<option value="4">Sports</option>', $html );
	}

	public function test_saving_keeps_the_ids_of_existing_categories_and_reports_the_others(): void {
		\WC_Admin_Settings::$errors = array();
		$integration                = ( new \ReflectionClass( Integration::class ) )->newInstanceWithoutConstructor();

		$this->assertSame( '3,4', $integration->validate_sensitive_categories_field( 'sensitive_categories', array( '3', '4', '88' ) ) );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
		$this->assertSame( '', $integration->validate_sensitive_categories_field( 'sensitive_categories', null ), 'none chosen' );
	}
}

<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Integration;

/**
 * The "Sensitive categories" field: a folded tree of checkboxes with the store's product categories. What PHP draws is
 * the state of what is saved (closed, branches with a mark unfolded, descendants of a mark included); the folding and
 * the filter after that are tests/js/admin-categories.test.mjs.
 */
final class IntegrationCategoriesTest extends WordPressTestCase {

	private const FIELD = 'woocommerce_irios_sensitive_categories';

	private Integration $integration;

	protected function setUp(): void {
		parent::setUp();
		\WC_Admin_Settings::$errors = array();
		// Real escaping, to see that a category name cannot break the page
		$escape = static fn ( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		Functions\when( 'esc_attr' )->alias( $escape );
		Functions\when( 'esc_html' )->alias( $escape );
		Functions\when( 'esc_attr__' )->alias( $escape );
		Functions\when( 'esc_html__' )->alias( $escape );
		// In the order WooCommerce gives them (Products → Categories), not alphabetical
		$this->given_categories(
			new \WP_Term( 1, 'Health' ),
			new \WP_Term( 4, 'Eye drops', 1 ),
			new \WP_Term( 5, 'Allergy', 1 ),
			new \WP_Term( 2, 'Beauty' ),
			new \WP_Term( 3, 'Skin', 2 ),
			new \WP_Term( 6, 'Face', 3 ),
			new \WP_Term( 7, 'Sports' )
		);
		$this->integration = ( new \ReflectionClass( Integration::class ) )->newInstanceWithoutConstructor();
		$this->integration->id = 'irios';
	}

	private function given_categories( \WP_Term ...$terms ): void {
		Functions\when( 'get_terms' )->justReturn( $terms );
	}

	private function html( string $marked ): string {
		$this->integration->settings = array( 'sensitive_categories' => $marked );
		return $this->integration->generate_irios_categories_html(
			'sensitive_categories',
			array(
				'title'       => 'Sensitive categories',
				'description' => 'Each category includes all its subcategories.',
			)
		);
	}

	/** The <input> of a category. */
	private function box( string $html, int $id ): string {
		$this->assertSame( 1, preg_match( '/<input type="checkbox" id="' . self::FIELD . '-' . $id . '"[^>]*>/', $html, $m ), "checkbox of $id" );
		return $m[0];
	}

	/** The <li> of a category up to its own children (or its end). */
	private function item( string $html, int $id ): string {
		$this->assertSame( 1, preg_match( '/<li class="irios-cat-item[^"]*" data-path="[^"]*"><span class="irios-cat-row">(?:(?!<\/span><\/span>).)*?id="' . self::FIELD . '-' . $id . '".*?<\/span><\/span>/s', $html, $m ), "item of $id" );
		return $m[0];
	}

	private function summary( string $html ): string {
		$this->assertSame( 1, preg_match( '/<span class="irios-cat-summary"[^>]*>(.*?)<\/span>/', $html, $m ) );
		return html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
	}

	public function test_every_category_is_a_labelled_checkbox_nested_under_its_parent_in_the_stores_order(): void {
		$html = $this->html( '' );

		foreach ( array( 1 => 'Health', 4 => 'Eye drops', 5 => 'Allergy', 2 => 'Beauty', 3 => 'Skin', 6 => 'Face', 7 => 'Sports' ) as $id => $name ) {
			$this->assertStringContainsString( 'name="' . self::FIELD . '[]" value="' . $id . '"', $this->box( $html, $id ) );
			$this->assertStringContainsString( '<label for="' . self::FIELD . '-' . $id . '">' . $name . '</label>', $html );
		}
		$positions = array_map( fn ( int $id ) => strpos( $html, 'id="' . self::FIELD . '-' . $id . '"' ), array( 1, 4, 5, 2, 3, 6, 7 ) );
		$sorted    = $positions;
		sort( $sorted );
		$this->assertSame( $sorted, $positions, 'same order as WooCommerce, each child right after its parent' );
		// Skin and its Face inside Beauty's list; Face inside Skin's
		$this->assertMatchesRegularExpression( '/<ul class="irios-cat-list" id="' . self::FIELD . '-children-2"[^>]*>.*-3".*-6".*<\/ul>/s', $html );
		$this->assertMatchesRegularExpression( '/<ul class="irios-cat-list" id="' . self::FIELD . '-children-3"[^>]*><li[^>]*data-path="Beauty &gt; Skin &gt; Face"/', $html );
	}

	public function test_with_nothing_marked_it_is_closed_and_folded_and_says_so(): void {
		$html = $this->html( '' );

		$this->assertSame( 'No sensitive categories', $this->summary( $html ) );
		$this->assertMatchesRegularExpression( '/<button type="button" class="button-link irios-cat-toggle" aria-expanded="false" aria-controls="' . self::FIELD . '-panel">Show categories<\/button>/', $html );
		$this->assertMatchesRegularExpression( '/<div class="irios-cat-panel" id="' . self::FIELD . '-panel" hidden>/', $html );
		$this->assertStringNotContainsString( 'aria-expanded="true"', $html );
		$this->assertMatchesRegularExpression( '/id="' . self::FIELD . '-children-1" hidden>/', $html );
		$this->assertStringNotContainsString( 'checked', $html );
		$this->assertStringNotContainsString( 'disabled', $html );
	}

	public function test_saving_with_nothing_checked_submits_an_empty_value_that_clears_the_setting(): void {
		$html = $this->html( '2' );

		$this->assertStringContainsString( '<input type="hidden" name="' . self::FIELD . '[]" value="">', $html );
		Functions\when( 'is_wp_error' )->justReturn( false );
		$this->assertSame( '', $this->integration->validate_sensitive_categories_field( 'sensitive_categories', array( '' ) ) );
		$this->assertSame( array(), \WC_Admin_Settings::$errors );
	}

	public function test_a_marked_category_is_checked_and_its_descendants_are_included(): void {
		$html = $this->html( '2' );

		$beauty = $this->box( $html, 2 );
		$this->assertStringContainsString( 'checked="checked"', $beauty );
		$this->assertStringContainsString( 'data-marked="1"', $beauty );
		$this->assertStringNotContainsString( 'disabled', $beauty );
		foreach ( array( 3, 6 ) as $descendant ) {
			$box = $this->box( $html, $descendant );
			$this->assertStringContainsString( 'checked="checked"', $box );
			$this->assertStringContainsString( 'disabled="disabled"', $box, 'disabled, so it is not submitted: only the mark is stored' );
			$this->assertStringContainsString( 'data-marked="0"', $box );
			$item = $this->item( $html, $descendant );
			$this->assertStringContainsString( 'is-included', $item );
			$this->assertStringContainsString( '<span class="irios-cat-included">included</span>', $item );
		}
		$this->assertStringContainsString( '<span class="irios-cat-included" hidden>included</span>', $this->item( $html, 2 ) );
		$this->assertStringNotContainsString( 'checked', $this->box( $html, 1 ) );
		$this->assertStringNotContainsString( 'checked', $this->box( $html, 7 ) );
	}

	public function test_only_the_branches_that_hold_a_marked_category_are_unfolded(): void {
		$html = $this->html( '6' );

		$this->assertMatchesRegularExpression( '/aria-expanded="true" aria-controls="' . self::FIELD . '-children-2"/', $html );
		$this->assertMatchesRegularExpression( '/aria-expanded="true" aria-controls="' . self::FIELD . '-children-3"/', $html );
		$this->assertMatchesRegularExpression( '/id="' . self::FIELD . '-children-2">/', $html );
		$this->assertMatchesRegularExpression( '/id="' . self::FIELD . '-children-3">/', $html );
		$this->assertMatchesRegularExpression( '/aria-expanded="false" aria-controls="' . self::FIELD . '-children-1"/', $html );
		$this->assertMatchesRegularExpression( '/id="' . self::FIELD . '-children-1" hidden>/', $html );
		$this->assertMatchesRegularExpression( '/<div class="irios-cat-panel" id="' . self::FIELD . '-panel" hidden>/', $html, 'the tree itself stays closed' );
	}

	public function test_a_marked_branch_with_nothing_marked_below_stays_folded(): void {
		$html = $this->html( '2' );

		$this->assertMatchesRegularExpression( '/aria-expanded="false" aria-controls="' . self::FIELD . '-children-2"/', $html );
	}

	public function test_the_summary_names_the_marked_categories_with_their_path_in_the_trees_order(): void {
		$this->assertSame( '1 category marked: Beauty > Skin', $this->summary( $this->html( '3' ) ) );
		$this->assertSame( '2 categories marked: Health, Beauty > Skin', $this->summary( $this->html( '3,1' ) ) );
		$this->assertSame( '4 categories marked: Health > Eye drops, Health > Allergy, Beauty…', $this->summary( $this->html( '7,2,5,4' ) ) );
		$this->assertSame( '1 category marked: Beauty', $this->summary( $this->html( '6,2' ) ), 'Face is included in Beauty' );
	}

	public function test_a_marked_category_that_no_longer_exists_is_not_counted(): void {
		$this->assertSame( 'No sensitive categories', $this->summary( $this->html( '99' ) ) );
	}

	public function test_category_names_are_escaped(): void {
		$this->given_categories( new \WP_Term( 1, '<b>Tea & "Herbs"</b>' ), new \WP_Term( 2, "O'Neil", 1 ) );

		$html = $this->html( '2' );

		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Tea &amp; &quot;Herbs&quot;&lt;/b&gt;</label>', $html );
		$this->assertStringContainsString( 'data-path="&lt;b&gt;Tea &amp; &quot;Herbs&quot;&lt;/b&gt; &gt; O&#039;Neil"', $html );
		$this->assertStringContainsString( 'Subcategories of &lt;b&gt;', $html );
		$this->assertSame( '1 category marked: <b>Tea & "Herbs"</b> > O\'Neil', $this->summary( $html ) );
	}

	public function test_a_store_without_categories_says_so(): void {
		$this->given_categories();

		$html = $this->html( '' );

		$this->assertStringContainsString( 'The store has no product categories yet.', $html );
		$this->assertStringNotContainsString( 'type="checkbox"', $html );
		$this->assertSame( 'No sensitive categories', $this->summary( $html ) );
	}

	public function test_a_category_whose_parent_is_missing_or_loops_is_still_shown(): void {
		$this->given_categories( new \WP_Term( 1, 'Orphan', 50 ), new \WP_Term( 2, 'A', 3 ), new \WP_Term( 3, 'B', 2 ) );

		$html = $this->html( '' );

		foreach ( array( 1, 2, 3 ) as $id ) {
			$this->box( $html, $id );
		}
	}

	public function test_unknown_ids_are_rejected_and_known_ones_kept(): void {
		$saved = $this->integration->validate_sensitive_categories_field( 'sensitive_categories', array( '', '6', '99', '2' ) );

		$this->assertSame( '6,2', $saved );
		$this->assertCount( 1, \WC_Admin_Settings::$errors );
		$this->assertStringContainsString( '"99"', \WC_Admin_Settings::$errors[0] );
	}

	public function test_the_admin_css_and_js_are_loaded_on_this_settings_screen_only(): void {
		$enqueued = array();
		Functions\when( 'sanitize_key' )->alias( fn ( $key ) => strtolower( (string) $key ) );
		Functions\when( 'plugins_url' )->alias( fn ( string $path ) => 'https://shop.test/wp-content/plugins/irios-woocommerce/' . $path );
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( string $handle, string $src ) use ( &$enqueued ) {
				$enqueued[] = $src;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( string $handle, string $src ) use ( &$enqueued ) {
				$enqueued[] = $src;
			}
		);
		$screens = array(
			array( 'woocommerce_page_wc-settings', array( 'tab' => 'integration', 'section' => 'irios' ), true ),
			array( 'woocommerce_page_wc-settings', array( 'tab' => 'integration' ), true ),
			array( 'woocommerce_page_wc-settings', array( 'tab' => 'integration', 'section' => 'other' ), false ),
			array( 'woocommerce_page_wc-settings', array( 'tab' => 'products' ), false ),
			array( 'edit.php', array( 'tab' => 'integration', 'section' => 'irios' ), false ),
		);
		foreach ( $screens as [ $hook, $query, $loaded ] ) {
			$enqueued = array();
			$_GET     = $query;
			Integration::enqueue_admin_assets( $hook );
			$this->assertSame(
				$loaded ? array(
					'https://shop.test/wp-content/plugins/irios-woocommerce/assets/css/admin-categories.css',
					'https://shop.test/wp-content/plugins/irios-woocommerce/assets/js/admin-categories.js',
					'https://shop.test/wp-content/plugins/irios-woocommerce/assets/js/admin-display.js',
				) : array(),
				$enqueued,
				$hook . ' ' . http_build_query( $query )
			);
		}
		$_GET = array();
	}
}

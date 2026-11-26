<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Irios\WooCommerce\Cards;
use Irios\WooCommerce\Catalog;
use Irios\WooCommerce\Languages;
use Irios\WooCommerce\Stock;
use Irios\WooCommerce\Translations;

/**
 * A WPML store: each translation is another product with its own id. IRIOS Media only knows the default-language
 * (original) id, so that is what the plugin sends, and its sponsored products are shown in the page's language.
 *
 * Product 7237 (es, default) has its English translation 9120; its variation 7240 is 9123 in English. Product 500 has
 * no English translation.
 */
final class LanguagesWpmlTest extends WordPressTestCase {

	private const TRANSLATIONS = array(
		7237 => array( 'es' => 7237, 'en' => 9120 ),
		9120 => array( 'es' => 7237, 'en' => 9120 ),
		7240 => array( 'es' => 7240, 'en' => 9123 ),
		9123 => array( 'es' => 7240, 'en' => 9123 ),
		500  => array( 'es' => 500 ),
	);

	private string $current = 'en';

	protected function setUp(): void {
		parent::setUp();
		Filters\expectApplied( 'wpml_default_language' )->zeroOrMoreTimes()->andReturn( 'es' );
		Filters\expectApplied( 'wpml_current_language' )->zeroOrMoreTimes()->andReturnUsing( fn () => $this->current );
		Filters\expectApplied( 'wpml_active_languages' )->zeroOrMoreTimes()->andReturn(
			array(
				'es' => array( 'default_locale' => 'es_ES' ),
				'en' => array( 'default_locale' => 'en_GB' ),
			)
		);
		Filters\expectApplied( 'wpml_object_id' )->zeroOrMoreTimes()->andReturnUsing(
			fn ( $id, $type, $original, $language ) => self::TRANSLATIONS[ (int) $id ][ $language ] ?? null
		);
		Filters\expectApplied( 'wpml_post_language_details' )->zeroOrMoreTimes()->andReturnUsing(
			fn ( $details, $id ) => array( 'language_code' => in_array( (int) $id, array( 9120, 9123 ), true ) ? 'en' : 'es' )
		);
		Functions\when( 'get_post_type' )->justReturn( 'product' );
	}

	protected function tearDown(): void {
		Translations::$fetch = null;
		parent::tearDown();
	}

	public function test_the_store_languages(): void {
		$this->assertSame( Languages::WPML, Languages::plugin() );
		$this->assertSame( 'es', Languages::default_language() );
		$this->assertSame( array( 'es' => 'es_ES', 'en' => 'en_GB' ), Languages::languages() );
	}

	public function test_a_translation_is_sent_with_the_original_id(): void {
		$this->assertSame( 7237, Languages::original_id( 9120 ) );
		$this->assertSame( 7240, Languages::original_id( 9123 ) );
		$this->assertSame( 7237, Languages::original_id( 7237 ) );
		$this->assertSame( 500, Languages::original_id( 500 ) );
	}

	public function test_an_ad_is_shown_with_the_translation_of_the_page_language(): void {
		$this->assertSame( 9120, Languages::local_id( 7237 ) );
		$this->assertSame( 500, Languages::local_id( 500 ) ); // no English translation: the original

		$this->current = 'es';
		$this->assertSame( 7237, Languages::local_id( 7237 ) );
	}

	public function test_an_event_line_carries_the_original_id(): void {
		Functions\when( 'wc_get_price_to_display' )->justReturn( 12.5 );

		$this->assertSame( '7240', Catalog::line( ( new \WC_Product( 9123, 12.5, 'variation' ) ) )['id'] );
	}

	public function test_a_stock_change_of_a_translation_notifies_the_original(): void {
		$this->assertSame( 7237, Stock::product_id( ( new \WC_Product( 9123, 10.0, 'variation' ) )->with( 'publish', true, 9120 ) ) );
	}

	public function test_a_sponsored_card_shows_the_translation_and_keeps_the_irios_id(): void {
		Functions\when( 'wp_strip_all_tags' )->alias( fn ( string $s ) => strip_tags( $s ) );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'date_i18n' )->justReturn( '' );
		Functions\when( 'wc_price' )->alias( fn ( $v ) => '<span>' . $v . '</span>' );
		$this->given_products( new \WC_Product( 7237 ), new \WC_Product( 9120 ) );

		$card = Cards::render( array( 7237 ), Cards::LAYOUT_DATA )[0];

		$this->assertSame( '7237', $card['id'] ); // the ad IRIOS Media chose
		$this->assertSame( 9120, $card['productId'] ); // the product on this page (to hide its organic duplicate)
	}

	public function test_the_sync_service_gets_the_translations_of_an_original(): void {
		$fetched             = array();
		Translations::$fetch = function ( string $route ) use ( &$fetched ) {
			$fetched[] = $route;
			return '/wc/v3/products/9120' === $route
				? array(
					'id'         => 9120,
					'name'       => 'Stick X (EN)',
					'type'       => 'variable',
					'variations' => array( 9123 ),
				)
				: array(
					'id'         => 9123,
					'attributes' => array( array( 'name' => 'Size', 'option' => 'Senior' ) ),
				);
		};

		$product = Translations::product( 7237, 'es', array( 'es', 'en' ) );

		$this->assertSame( 'es', $product['language'] );
		$this->assertSame( 7237, $product['original'] );
		$english = $product['translations']->en;
		$this->assertSame( 'Stick X (EN)', $english['product']['name'] );
		// The translated variation is keyed by the original variation id, the one the sync service maps
		$this->assertSame( 9123, $english['variations']->{'7240'}['id'] );
		$this->assertSame( array( '/wc/v3/products/9120', '/wc/v3/products/9120/variations/9123' ), $fetched );
	}

	public function test_a_translation_only_points_to_its_original(): void {
		$product = Translations::product( 9120, 'es', array( 'es', 'en' ) );

		$this->assertSame( array( 'language' => 'en', 'original' => 7237 ), $product );
	}

	public function test_a_product_without_translations_has_none(): void {
		$product = Translations::product( 500, 'es', array( 'es', 'en' ) );

		$this->assertEquals( new \stdClass(), $product['translations'] );
	}
}

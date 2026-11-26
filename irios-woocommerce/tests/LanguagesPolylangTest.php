<?php

namespace Irios\WooCommerce\Tests;

use Brain\Monkey\Functions;
use Irios\WooCommerce\Languages;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A Polylang store (with Polylang for WooCommerce). In separate processes: the pll_* functions defined here would
 * make every later test look like a Polylang store.
 */
#[RunTestsInSeparateProcesses]
final class LanguagesPolylangTest extends WordPressTestCase {

	private const TRANSLATIONS = array(
		7237 => array( 'es' => 7237, 'en' => 9120 ),
		9120 => array( 'es' => 7237, 'en' => 9120 ),
		500  => array( 'es' => 500 ),
	);

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'pll_default_language' )->justReturn( 'es' );
		Functions\when( 'pll_current_language' )->justReturn( 'en' );
		Functions\when( 'pll_languages_list' )->alias(
			fn ( array $args ) => 'locale' === $args['fields'] ? array( 'es_ES', 'en_GB' ) : array( 'es', 'en' )
		);
		Functions\when( 'pll_get_post' )->alias( fn ( $id, $language ) => self::TRANSLATIONS[ (int) $id ][ $language ] ?? 0 );
		Functions\when( 'pll_get_post_language' )->alias( fn ( $id ) => 9120 === (int) $id ? 'en' : 'es' );
		Functions\when( 'pll_get_term' )->alias( fn ( $id ) => 31 === (int) $id ? 30 : (int) $id );
	}

	public function test_the_store_languages(): void {
		$this->assertSame( Languages::POLYLANG, Languages::plugin() );
		$this->assertSame( 'es', Languages::default_language() );
		$this->assertSame( array( 'es' => 'es_ES', 'en' => 'en_GB' ), Languages::languages() );
		$this->assertSame( 'en', Languages::language_of( 9120 ) );
	}

	public function test_ids_go_to_irios_as_the_original_and_are_shown_translated(): void {
		$this->assertSame( 7237, Languages::original_id( 9120 ) );
		$this->assertSame( 9120, Languages::local_id( 7237 ) );
		$this->assertSame( 500, Languages::local_id( 500 ) ); // no translation (Polylang answers 0)
	}

	public function test_a_translated_category_is_not_in_the_default_language(): void {
		$this->assertTrue( Languages::is_default_language_term( 30 ) );
		$this->assertFalse( Languages::is_default_language_term( 31 ) );
	}
}

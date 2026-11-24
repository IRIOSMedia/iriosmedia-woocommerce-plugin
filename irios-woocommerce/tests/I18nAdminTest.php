<?php

namespace Irios\WooCommerce\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The admin is always in English (IRIOS Media standard): only what shoppers see goes through a translation function,
 * so neither the bundled .po nor translate.wordpress.org can translate the settings page or the admin notices.
 */
final class I18nAdminTest extends TestCase {

	private const TRANSLATION_FUNCTIONS = array(
		'__', '_e', '_x', '_ex', '_n', '_nx', '_n_noop', '_nx_noop',
		'esc_html__', 'esc_html_e', 'esc_html_x', 'esc_attr__', 'esc_attr_e', 'esc_attr_x', 'translate',
	);

	/** Where shopper-facing texts may live: the «About this ad» dialog and the default sponsored label. */
	private const STOREFRONT_FILES = array( 'src/Assets.php', 'src/Settings.php' );

	private static function root(): string {
		return dirname( __DIR__ );
	}

	/** @return array<string, list<string>> file => msgids passed to a translation function */
	private static function translated_strings(): array {
		$files = array_merge( glob( self::root() . '/src/*.php' ), array( self::root() . '/irios-woocommerce.php', self::root() . '/uninstall.php' ) );
		$found = array();
		foreach ( $files as $file ) {
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $file ) ),
					static fn( $t ) => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
				)
			);
			foreach ( $tokens as $i => $token ) {
				if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], self::TRANSLATION_FUNCTIONS, true ) ) {
					continue;
				}
				$prev = $tokens[ $i - 1 ] ?? null;
				if ( is_array( $prev ) && in_array( $prev[0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
					continue;
				}
				if ( '(' !== ( $tokens[ $i + 1 ] ?? null ) ) {
					continue;
				}
				$arg = $tokens[ $i + 2 ] ?? null;
				$key = substr( $file, strlen( self::root() ) + 1 );
				if ( is_array( $arg ) && T_CONSTANT_ENCAPSED_STRING === $arg[0] && "'irios-woocommerce'" !== $arg[1] ) {
					$found[ $key ][] = stripcslashes( substr( $arg[1], 1, -1 ) );
				} elseif ( ! ( is_array( $arg ) && "'irios-woocommerce'" === $arg[1] ) ) {
					$found[ $key ][] = '(non-literal) ' . $token[1];
				}
			}
		}
		return $found;
	}

	/** @return list<string> msgids of a .po/.pot file, without the header */
	private static function msgids( string $file ): array {
		preg_match_all( '/^msgid "(.*)"$/m', (string) file_get_contents( $file ), $m );
		return array_values( array_filter( $m[1], static fn( $id ) => '' !== $id ) );
	}

	public function test_no_admin_file_uses_a_translation_function(): void {
		$admin = array_diff_key( self::translated_strings(), array_flip( self::STOREFRONT_FILES ) );
		$this->assertSame( array(), $admin, 'Admin texts must be plain English strings, not passed through __() and the like' );
	}

	public function test_settings_only_translates_the_default_sponsored_label(): void {
		$this->assertSame( array( 'Sponsored' ), self::translated_strings()['src/Settings.php'] ?? array() );
	}

	public function test_the_pot_holds_only_the_storefront_strings(): void {
		$expected = array_merge( ...array_values( array_intersect_key( self::translated_strings(), array_flip( self::STOREFRONT_FILES ) ) ) );
		$pot      = self::msgids( self::root() . '/languages/irios-woocommerce.pot' );
		sort( $expected );
		sort( $pot );
		$this->assertSame( array_values( array_unique( $expected ) ), $pot );
		foreach ( array( 'Enable IRIOS Media', 'Store ID', 'Dismiss', 'Release notes', 'Show categories' ) as $admin ) {
			$this->assertNotContains( $admin, $pot );
		}
	}

	public function test_the_spanish_po_matches_the_pot_and_translates_everything(): void {
		$po  = self::msgids( self::root() . '/languages/irios-woocommerce-es_ES.po' );
		$pot = self::msgids( self::root() . '/languages/irios-woocommerce.pot' );
		sort( $po );
		sort( $pot );
		$this->assertSame( $pot, $po );
		$content = (string) file_get_contents( self::root() . '/languages/irios-woocommerce-es_ES.po' );
		$this->assertStringNotContainsString( '#~', $content, 'no obsolete entries' );
		$this->assertStringContainsString( "msgid \"Sponsored\"\nmsgstr \"Patrocinado\"", $content );
		$this->assertSame( 1, preg_match_all( '/^msgstr ""$/m', $content ), 'only the header has an empty msgstr' );
	}
}

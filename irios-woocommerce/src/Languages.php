<?php

namespace Irios\WooCommerce;

/**
 * Multi-language stores (WPML with WooCommerce Multilingual, or Polylang with Polylang for WooCommerce).
 *
 * Both plugins make each translation a separate product, with its own id, linked to the product in the default
 * language. IRIOS Media has one product per item, with its content in every language of the catalog, under the id of
 * the default-language product (the "original"). So everything this plugin sends to IRIOS Media carries the original
 * id (events, purchases, stock notices), and the sponsored products IRIOS Media returns (original ids) are shown with
 * the translation of the page's language.
 *
 * Without a multi-language plugin every method leaves the ids as they are.
 */
final class Languages {

	public const WPML     = 'wpml';
	public const POLYLANG = 'polylang';

	/** The multi-language plugin in use (wpml or polylang), or null. */
	public static function plugin(): ?string {
		if ( function_exists( 'pll_default_language' ) && function_exists( 'pll_get_post' ) ) {
			return self::POLYLANG;
		}
		$default = apply_filters( 'wpml_default_language', null );
		return is_string( $default ) && '' !== $default ? self::WPML : null;
	}

	/** Code of the store's default language (es, en…), or null without a multi-language plugin. */
	public static function default_language(): ?string {
		switch ( self::plugin() ) {
			case self::POLYLANG:
				$code = pll_default_language( 'slug' );
				break;
			case self::WPML:
				$code = apply_filters( 'wpml_default_language', null );
				break;
			default:
				return null;
		}
		return is_string( $code ) && '' !== $code ? $code : null;
	}

	/**
	 * Languages of the store: code => WordPress locale (es => es_ES, en => en_GB). Empty without a multi-language plugin.
	 *
	 * @return array<string,string>
	 */
	public static function languages(): array {
		$languages = array();
		switch ( self::plugin() ) {
			case self::POLYLANG:
				$codes   = (array) pll_languages_list( array( 'fields' => 'slug' ) );
				$locales = (array) pll_languages_list( array( 'fields' => 'locale' ) );
				foreach ( array_values( $codes ) as $i => $code ) {
					$languages[ (string) $code ] = (string) ( array_values( $locales )[ $i ] ?? $code );
				}
				break;
			case self::WPML:
				foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $language ) {
					$languages[ (string) $code ] = (string) ( $language['default_locale'] ?? $code );
				}
				break;
		}
		return $languages;
	}

	/** Language of the page being viewed (code), or null. */
	public static function current_language(): ?string {
		switch ( self::plugin() ) {
			case self::POLYLANG:
				$code = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : null;
				break;
			case self::WPML:
				$code = apply_filters( 'wpml_current_language', null );
				break;
			default:
				return null;
		}
		return is_string( $code ) && '' !== $code ? $code : null;
	}

	/** Language of a product or variation (code), or null. */
	public static function language_of( int $id ): ?string {
		switch ( self::plugin() ) {
			case self::POLYLANG:
				$code = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $id, 'slug' ) : null;
				break;
			case self::WPML:
				$details = apply_filters( 'wpml_post_language_details', null, $id );
				$code    = is_array( $details ) ? ( $details['language_code'] ?? null ) : null;
				break;
			default:
				return null;
		}
		return is_string( $code ) && '' !== $code ? $code : null;
	}

	/**
	 * Id of a product or variation in a language: its translation, or null if it has none in that language.
	 */
	public static function translated_id( int $id, string $language ): ?int {
		switch ( self::plugin() ) {
			case self::POLYLANG:
				$translated = pll_get_post( $id, $language );
				break;
			case self::WPML:
				$type       = get_post_type( $id );
				$translated = apply_filters( 'wpml_object_id', $id, $type ? $type : 'product', false, $language );
				break;
			default:
				return $id;
		}
		return is_numeric( $translated ) && (int) $translated > 0 ? (int) $translated : null;
	}

	/**
	 * Whether a product category is in the default language. Its translations are separate terms: the sync service
	 * leaves them out, so that IRIOS Media does not get each category once per language.
	 */
	public static function is_default_language_term( int $term_id ): bool {
		$default = self::default_language();
		if ( null === $default ) {
			return true;
		}
		switch ( self::plugin() ) {
			case self::POLYLANG:
				$translated = function_exists( 'pll_get_term' ) ? pll_get_term( $term_id, $default ) : $term_id;
				break;
			case self::WPML:
				$translated = apply_filters( 'wpml_object_id', $term_id, 'product_cat', false, $default );
				break;
			default:
				return true;
		}
		return is_numeric( $translated ) && (int) $translated === $term_id;
	}

	/** The id IRIOS Media knows: that of the default-language product (the id itself if it has no translation). */
	public static function original_id( int $id ): int {
		$default = self::default_language();
		if ( null === $default || $id <= 0 ) {
			return $id;
		}
		return self::translated_id( $id, $default ) ?? $id;
	}

	/** The translation of an original id in the page's language, to show it (the id itself if there is none). */
	public static function local_id( int $id ): int {
		$current = self::current_language();
		if ( null === $current || $id <= 0 ) {
			return $id;
		}
		return self::translated_id( $id, $current ) ?? $id;
	}
}

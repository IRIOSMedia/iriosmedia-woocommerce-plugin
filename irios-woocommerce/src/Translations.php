<?php

namespace Irios\WooCommerce;

/**
 * Translations of a multi-language store for the IRIOS Media sync service (Languages):
 *
 *   GET /wp-json/irios/v1/translations             the store's languages and the product categories that are
 *                                                  translations (to leave out)
 *   GET /wp-json/irios/v1/translations?ids=1,2,3   also, for each product id: its language, its original (the
 *                                                  default-language product) and, for an original, its translations
 *                                                  as the wc/v3 API returns them (with their variations, keyed by the
 *                                                  original variation id)
 *
 * The sync service sends the original with the content of every language of the IRIOS Media catalog, and leaves out
 * the translations as products of their own. Without a multi-language plugin, "plugin" is null and nothing else
 * comes. Authenticated like the rest of the feed (Feed): a WooCommerce REST API key with read access.
 */
final class Translations {

	public const MAX_IDS = 50;

	/** The wc/v3 fields the sync service reads from a translation: WooCommerce only prepares these. */
	private const PRODUCT_FIELDS   = 'id,name,type,status,catalog_visibility,permalink,short_description,images,attributes,variations';
	private const VARIATION_FIELDS = 'id,status,permalink,image,attributes,description';

	/**
	 * Reads a wc/v3 resource inside this request (no HTTP), with the API key's user. Replaceable in the tests.
	 *
	 * @var callable(string,string):?array|null
	 */
	public static $fetch = null;

	public static function response( \WP_REST_Request $request ): \WP_REST_Response {
		$plugin  = Languages::plugin();
		$default = Languages::default_language();
		$data    = array(
			'plugin'                => $plugin,
			'default_language'      => $default,
			'languages'             => (object) Languages::languages(),
			'translated_categories' => array(),
			'products'              => new \stdClass(),
		);
		if ( null === $plugin || null === $default ) {
			return new \WP_REST_Response( $data );
		}
		$data['translated_categories'] = self::translated_categories();
		$products                      = array();
		foreach ( Cards::parse_ids( (string) $request['ids'], self::MAX_IDS ) as $id ) {
			$products[ (string) $id ] = self::product( $id, $default, array_keys( Languages::languages() ) );
		}
		$data['products'] = (object) $products;
		return new \WP_REST_Response( $data );
	}

	/**
	 * @param string[] $languages
	 * @return array{language:?string,original:int,translations?:object}
	 */
	public static function product( int $id, string $default, array $languages ): array {
		$original = Languages::original_id( $id );
		$entry    = array(
			'language' => Languages::language_of( $id ),
			'original' => $original,
		);
		if ( $original !== $id ) {
			return $entry; // a translation: the sync service reads its original instead
		}
		$translations = array();
		foreach ( $languages as $language ) {
			$translated_id = $language === $default ? null : Languages::translated_id( $id, $language );
			if ( null === $translated_id || $translated_id === $id ) {
				continue;
			}
			$product = self::fetch( '/wc/v3/products/' . $translated_id, self::PRODUCT_FIELDS );
			if ( null === $product ) {
				continue;
			}
			$variations = array();
			foreach ( (array) ( $product['variations'] ?? array() ) as $variation_id ) {
				$variation = self::fetch( '/wc/v3/products/' . $translated_id . '/variations/' . (int) $variation_id, self::VARIATION_FIELDS );
				if ( null !== $variation ) {
					$variations[ (string) Languages::original_id( (int) $variation_id ) ] = $variation;
				}
			}
			$translations[ $language ] = array(
				'product'    => $product,
				'variations' => (object) $variations,
			);
		}
		$entry['translations'] = (object) $translations;
		return $entry;
	}

	/** @return int[] Ids of the product categories that are translations (not in the default language). */
	private static function translated_categories(): array {
		$all = Languages::WPML === Languages::plugin() ? self::wpml_all_terms() : get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
				'lang'       => '', // Polylang: every language
			)
		);
		$translated = array();
		foreach ( is_array( $all ) ? $all : array() as $term_id ) {
			if ( ! Languages::is_default_language_term( (int) $term_id ) ) {
				$translated[] = (int) $term_id;
			}
		}
		return $translated;
	}

	/** WPML filters the terms by the current language: every language, then back to the current one. */
	private static function wpml_all_terms() {
		$current = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', 'all' );
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		do_action( 'wpml_switch_language', $current );
		return $terms;
	}

	private static function fetch( string $route, string $fields ): ?array {
		if ( null !== self::$fetch ) {
			return ( self::$fetch )( $route, $fields );
		}
		$request = new \WP_REST_Request( 'GET', $route );
		$request->set_param( '_fields', $fields );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) {
			return null;
		}
		$data = $response->get_data();
		return is_array( $data ) ? $data : null;
	}
}

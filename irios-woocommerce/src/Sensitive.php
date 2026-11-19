<?php

namespace Irios\WooCommerce;

/**
 * Sensitive data: what someone looks at or buys can reveal their health, which the GDPR (art. 9) treats as a special
 * category, and the DSA (art. 26.3) does not allow ads based on profiles built with it. The store marks product
 * categories as sensitive (and, optionally, every search); then:
 *
 * - sensitive are those categories and all their subcategories (also the ones created later), and the products in any
 *   of them (a variation, through its parent product);
 * - no event of a sensitive page or product is sent to IRIOS Media; a cart event keeps only its other products, and
 *   nothing is sent if none is left (Events, and the storefront JS for the page events);
 * - the ads of a sensitive page (or search) are requested without identifiers, as without consent (storefront JS);
 * - the orders with a sensitive product are not in the feed of the sync service (Feed), so the purchase never reaches
 *   IRIOS Media. Customers and the catalog are not affected.
 *
 * Whatever cannot be checked (a product that no longer exists, an error reading its categories) counts as sensitive.
 */
final class Sensitive {

	/**
	 * Pure rule: a category is sensitive if it or any of its ancestors is marked.
	 *
	 * @param int[] $path   The category and its ancestors.
	 * @param int[] $marked Categories marked as sensitive.
	 */
	public static function path_is_marked( array $path, array $marked ): bool {
		return array() !== array_intersect( array_map( 'intval', $path ), array_map( 'intval', $marked ) );
	}

	/** Whether a product category is sensitive: marked, or under a marked one. */
	public static function category( int $term_id, ?Settings $settings = null ): bool {
		$marked = ( $settings ?? Settings::load() )->sensitive_categories();
		if ( array() === $marked ) {
			return false;
		}
		$ancestors = get_ancestors( $term_id, 'product_cat', 'taxonomy' );
		return self::path_is_marked( array_merge( array( $term_id ), is_array( $ancestors ) ? $ancestors : array() ), $marked );
	}

	/**
	 * Whether a product (or variation, by its id) is sensitive: any of its categories is. A product that does not exist
	 * or whose categories cannot be read counts as sensitive; one without categories, not.
	 */
	public static function product( int $product_id, ?Settings $settings = null ): bool {
		$settings = $settings ?? Settings::load();
		if ( array() === $settings->sensitive_categories() ) {
			return false;
		}
		if ( $product_id <= 0 ) {
			return true;
		}
		$type = get_post_type( $product_id );
		if ( 'product_variation' === $type ) {
			$product_id = (int) wp_get_post_parent_id( $product_id );
			$type       = $product_id > 0 ? get_post_type( $product_id ) : false;
		}
		if ( 'product' !== $type ) {
			return true;
		}
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( is_wp_error( $terms ) ) {
			return true;
		}
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof \WP_Term && self::category( (int) $term->term_id, $settings ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Event lines without the sensitive products.
	 *
	 * @param array<int,array{id:string}> $lines
	 * @return array<int,array{id:string}>
	 */
	public static function without_sensitive( array $lines, ?Settings $settings = null ): array {
		$settings = $settings ?? Settings::load();
		return array_values(
			array_filter(
				$lines,
				static fn ( array $line ): bool => ! self::product( (int) ( $line['id'] ?? 0 ), $settings )
			)
		);
	}

	/** Whether an order has a sensitive product (a line whose product no longer exists counts as one). */
	public static function order( \WC_Order $order, ?Settings $settings = null ): bool {
		$settings = $settings ?? Settings::load();
		if ( array() === $settings->sensitive_categories() ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && self::product( (int) $item->get_product_id(), $settings ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the page being viewed is sensitive: the page of a sensitive product or category, or a product search
	 * when every search is sensitive or the search is filtered by a sensitive category. Page data, the same for every
	 * visitor: it can go in the (cacheable) HTML.
	 */
	public static function page( Settings $settings ): bool {
		if ( is_search() && 'product' === get_query_var( 'post_type' ) ) {
			if ( $settings->sensitive_searches() ) {
				return true;
			}
			$filter = (string) get_query_var( 'product_cat' );
			if ( '' === $filter || array() === $settings->sensitive_categories() ) {
				return false;
			}
			foreach ( (array) preg_split( '/[,+\s]+/', $filter, -1, PREG_SPLIT_NO_EMPTY ) as $slug ) {
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( ! $term instanceof \WP_Term || self::category( (int) $term->term_id, $settings ) ) {
					return true;
				}
			}
			return false;
		}
		if ( array() === $settings->sensitive_categories() ) {
			return false;
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			return ! $term instanceof \WP_Term || self::category( (int) $term->term_id, $settings );
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return self::product( (int) get_queried_object_id(), $settings );
		}
		return false;
	}
}

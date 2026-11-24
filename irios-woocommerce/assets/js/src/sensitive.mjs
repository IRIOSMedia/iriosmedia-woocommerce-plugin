import { cfg, page } from './config.mjs';

// ---- Sensitive data (health…) -------------------------------------------------------------------------------------

/**
 * What can reveal the shopper's health (GDPR, art. 9; DSA, art. 26.3) never reaches IRIOS Media tied to them. PHP
 * marks the sensitive pages (page.sensitive: a product or category the store marked as sensitive, or a search when
 * searches are) and says whether every search is (cfg.sensitiveSearches); both are about the store, never about the
 * visitor. Then:
 *   · the page events of a sensitive page are not sent, nor any search event when searches are sensitive;
 *   · its ads are requested and tracked without identifiers, as without consent (adUser).
 * The cart events (add-to-cart, remove-from-cart, cart-view) come from the server already without the sensitive
 * products, so they are sent on any page, sensitive or not, with whatever products are left (none left, no event). The
 * checkout steps carry no products and are never sensitive. The orders with sensitive products never leave the store
 * (Sensitive.php).
 */

/** The page being viewed is sensitive. */
export function sensitivePage() {
	return !! page.sensitive;
}

/** Every search is sensitive (setting "Treat all searches as sensitive"). */
export function sensitiveSearches() {
	return !! cfg.sensitiveSearches;
}

/**
 * Whether ads requested for an IRIOS pageType go without identifiers: the live search (SEARCH_BAR) when searches are
 * sensitive, the results page also when it is sensitive itself (filtered by a sensitive category), any other type
 * when the page is. Without a pageType (push subscription) the ad is not about the page.
 */
export function sensitiveAds( pageType ) {
	if ( ! pageType ) {
		return false;
	}
	if ( pageType === 'SEARCH_BAR' ) {
		return sensitiveSearches();
	}
	if ( pageType === 'SEARCH' ) {
		return sensitiveSearches() || sensitivePage();
	}
	return sensitivePage();
}

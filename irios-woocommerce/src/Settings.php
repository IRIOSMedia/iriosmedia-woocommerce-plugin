<?php

namespace Irios\WooCommerce;

/**
 * Plugin settings (option `woocommerce_irios_settings`, the one managed by the WooCommerce integration).
 * Validation is pure so it can be tested without WordPress.
 */
final class Settings {

	public const OPTION = 'woocommerce_irios_settings';

	public const CONSENT_WP_CONSENT_API = 'wp_consent_api';
	public const CONSENT_ALWAYS         = 'always';
	/** "Asesor de Cookies RGPD" banner (slug asesor-cookies-para-la-ley-en-espana): does not use the WP Consent API. */
	public const CONSENT_ASESOR_COOKIES = 'asesor_cookies';

	/**
	 * Automatic positions of a Display placement: the common catalog of every IRIOS Media integration (WooCommerce,
	 * Magento, Salesforce), in the order the settings screen offers them. Any placement can also be placed by hand with
	 * the shortcode, whatever its position. The home page has no automatic position: every home page is different, so
	 * its placements (home-main, home-secondary) are always placed by hand.
	 */
	public const POSITION_NONE                       = '';
	public const POSITION_CATEGORY_BEFORE_PRODUCTS   = 'category_before_products';
	public const POSITION_CATEGORY_IN_GRID           = 'category_in_grid';
	public const POSITION_CATEGORY_AFTER_PRODUCTS    = 'category_after_products';
	public const POSITION_CATEGORY_SIDEBAR           = 'category_sidebar';
	public const POSITION_PRODUCT_BEFORE_DESCRIPTION = 'product_before_description';
	public const POSITION_PRODUCT_BEFORE_RELATED     = 'product_before_related';
	public const POSITIONS                           = array(
		self::POSITION_NONE,
		self::POSITION_CATEGORY_BEFORE_PRODUCTS,
		self::POSITION_CATEGORY_IN_GRID,
		self::POSITION_CATEGORY_AFTER_PRODUCTS,
		self::POSITION_CATEGORY_SIDEBAR,
		self::POSITION_PRODUCT_BEFORE_DESCRIPTION,
		self::POSITION_PRODUCT_BEFORE_RELATED,
	);
	/**
	 * Name of the IRIOS ad unit of each automatic position, the same in every integration: the ad units are created in
	 * IRIOS Media with these names (and "-mobile" at the end for the mobile one), and they are also the tag suggested for
	 * the row (with "-mobile" for a Mobile row).
	 */
	public const AD_UNIT_NAMES      = array(
		self::POSITION_CATEGORY_BEFORE_PRODUCTS   => 'plp-top',
		self::POSITION_CATEGORY_IN_GRID           => 'plp-in-grid',
		self::POSITION_CATEGORY_AFTER_PRODUCTS    => 'plp-bottom',
		self::POSITION_CATEGORY_SIDEBAR           => 'plp-sidebar',
		self::POSITION_PRODUCT_BEFORE_DESCRIPTION => 'pdp-main',
		self::POSITION_PRODUCT_BEFORE_RELATED     => 'pdp-secondary',
	);
	/** Home page positions of earlier versions, now read as "Manual only" (the placement keeps working by its tag). */
	public const FORMER_HOME_POSITIONS = array( 'home_main', 'home_secondary' );
	public const CATEGORY_POSITIONS = array( self::POSITION_CATEGORY_SIDEBAR, self::POSITION_CATEGORY_BEFORE_PRODUCTS, self::POSITION_CATEGORY_IN_GRID, self::POSITION_CATEGORY_AFTER_PRODUCTS );
	public const PRODUCT_POSITIONS  = array( self::POSITION_PRODUCT_BEFORE_DESCRIPTION, self::POSITION_PRODUCT_BEFORE_RELATED );

	/**
	 * Format of a Display placement and the IRIOS ad unit type it is requested as: the IRIOS loader renders an image
	 * banner or a video player depending on it. It must match the ad unit's format in IRIOS Media.
	 */
	public const FORMAT_AD_UNIT_TYPE = array(
		'image' => 'banner',
		'video' => 'video',
	);
	/** Device a Display placement is shown on (desktop: from 768 px wide). */
	public const DEVICES = array( 'all', 'desktop', 'mobile' );

	/** When the push permission prompt may be shown (always with consent). */
	public const PUSH_AFTER_PURCHASE = 'after_purchase';
	public const PUSH_AFTER_PAGES    = 'after_pages';

	private const DEFAULTS = array(
		// "Enable IRIOS Media": on by default so that 1.0 installs (which had no such switch) keep working; nothing is
		// loaded anyway until the domain and the Store ID are set
		'enabled'                  => 'yes',
		'js_domain'                => '',
		'store_id'                 => '',
		'sponsored_enabled'        => 'no',
		'banners_enabled'          => 'no',
		'banners'                  => '[]', // Display placements (the option keeps its 1.0 name)
		'display_in_grid_after'    => '6',
		'push_enabled'             => 'no',
		'push_ad_unit'             => '',
		'push_auth'                => '',
		'push_trigger'             => self::PUSH_AFTER_PURCHASE,
		'push_after_pages'         => '2',
		'push_repeat_days'         => '30',
		'sponsored_slots'          => '', // JSON; empty = never saved (the legacy configuration is used)
		// Legacy configuration (a single ad unit at the top): still read if no slots have been set up.
		'search_ad_unit'           => '',
		'search_ad_unit_auth'      => '',
		'sponsored_label'          => '', // empty: "Sponsored" in the store's language
		'keep_single_result'       => 'yes',
		'max_ads'                  => '4',
		'consent_mode'             => self::CONSENT_WP_CONSENT_API,
		// Sensitive data (health…): ids of the product categories marked as sensitive, comma separated, and whether
		// every search is sensitive too
		'sensitive_categories'     => '',
		'sensitive_searches'       => 'no',
		// IRIOS Media sync service: product webhooks created by the plugin (SyncWebhooks). The secret never reaches the
		// browser: only the admin settings page shows it
		'sync_webhook_url'         => '',
		'sync_webhook_secret'      => '',
	);

	/** @var array<string,string> */
	/** The settings of this request; the option does not change while a page is being built. */
	private static ?self $loaded = null;

	private array $values;
	/** Decoded and checked once: both are read several times while building a page. */
	private ?array $banners = null;
	private ?array $slots = null;

	/**
	 * @param array<string,mixed> $values
	 */
	public function __construct( array $values ) {
		$this->values = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$value                = $values[ $key ] ?? $default;
			$this->values[ $key ] = is_scalar( $value ) ? trim( (string) $value ) : $default;
		}
	}

	/**
	 * The store's settings. Kept for the rest of the request: they are read from a dozen places while a page is
	 * built, and each read used to decode and check the JSON of the tables again.
	 */
	public static function load(): self {
		if ( null === self::$loaded ) {
			$stored       = get_option( self::OPTION, array() );
			self::$loaded = new self( is_array( $stored ) ? $stored : array() );
		}
		return self::$loaded;
	}

	/** Forget what was loaded, so the next read sees what was just saved. */
	public static function forget(): void {
		self::$loaded = null;
	}

	/** @return array<string,string> */
	public static function defaults(): array {
		return self::DEFAULTS;
	}

	/** "Enable IRIOS Media" switch (General). */
	public function enabled(): bool {
		return 'no' !== $this->values['enabled'];
	}

	/** "Enable IRIOS Media" on, with a domain and a Store ID: otherwise nothing is loaded. */
	public function is_configured(): bool {
		return 'no' !== $this->values['enabled'] && '' !== $this->js_domain() && null !== $this->store_id();
	}

	public function js_domain(): string {
		return self::normalize_domain( $this->values['js_domain'] );
	}

	public function store_id(): ?int {
		return ctype_digit( $this->values['store_id'] ) ? (int) $this->values['store_id'] : null;
	}

	/** Delivery URL of the product webhooks to the IRIOS Media sync service; empty: no webhooks. */
	public function sync_webhook_url(): string {
		return $this->values['sync_webhook_url'];
	}

	public function sync_webhook_secret(): string {
		return $this->values['sync_webhook_secret'];
	}

	/**
	 * A delivery URL the plugin accepts for the webhooks: https, or http only for a development host (localhost,
	 * *.localhost, *.test, host.docker.internal), as WooCommerce itself only delivers to public addresses.
	 */
	public static function valid_webhook_url( string $url ): bool {
		$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		if ( 'https' === strtolower( (string) $parts['scheme'] ) ) {
			return true;
		}
		return 'http' === strtolower( (string) $parts['scheme'] )
			&& ( 'localhost' === $host || 'host.docker.internal' === $host
				|| 1 === preg_match( '/\.(localhost|test)$/', $host ) );
	}


	public function search_ad_unit(): ?int {
		return ctype_digit( $this->values['search_ad_unit'] ) ? (int) $this->values['search_ad_unit'] : null;
	}

	/** The ad unit's `auth` key (required by irios-loader.js). Not a secret: it travels in the browser. */
	public function search_ad_unit_auth(): string {
		return $this->values['search_ad_unit_auth'];
	}

	/**
	 * Display placements set up in the settings (tag → ad unit), stored as JSON in the `banners` option.
	 *
	 * @return array<int,array{tag:string,format:string,type:string,device:string,id:int,auth:string,position:string}>
	 */
	public function banners(): array {
		if ( null === $this->banners ) {
			$rows          = json_decode( $this->values['banners'], true );
			$this->banners = is_array( $rows ) ? self::sanitize_banners( $rows )['banners'] : array();
		}
		return $this->banners;
	}

	/** Banner by its tag ([irios_banner tag="home"]), case-insensitive; null if it does not exist. */
	public function banner( string $tag ): ?array {
		$tag = strtolower( trim( $tag ) );
		foreach ( $this->banners() as $banner ) {
			if ( $banner['tag'] === $tag ) {
				return $banner;
			}
		}
		return null;
	}

	/**
	 * Banners with an automatic position on a page (e.g. at the end of a category listing).
	 *
	 * @return array<int,array{tag:string,id:int,auth:string,position:string}>
	 */
	public function banners_at( string $position ): array {
		if ( self::POSITION_NONE === $position ) {
			return array();
		}
		return array_values(
			array_filter(
				$this->banners(),
				static function ( array $banner ) use ( $position ): bool {
					return $banner['position'] === $position;
				}
			)
		);
	}

	/**
	 * Validates the Display rows from the settings screen: short (a-z, 0-9, - and _) unique tag, format (image or
	 * video; image when missing, as rows saved by 1.0), device (all when missing), required numeric id and auth, and
	 * optional automatic position. Empty rows are ignored; invalid ones are dropped with their error. The category
	 * sidebar is a desktop-only spot (on phones it is usually a filter panel or far below the products): a sidebar row
	 * is for desktop. When saving, any other device is an error; a stored row for all devices, saved before this rule,
	 * is read as desktop.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @param bool                           $for_save Validating what is about to be saved.
	 * @return array{banners:array<int,array{tag:string,format:string,type:string,device:string,id:int,auth:string,position:string}>,errors:string[]}
	 */
	public static function sanitize_banners( array $rows, bool $for_save = false ): array {
		$banners = array();
		$errors  = array();
		foreach ( $rows as $row ) {
			$tag  = strtolower( trim( (string) ( $row['tag'] ?? '' ) ) );
			$id   = trim( (string) ( $row['id'] ?? '' ) );
			$auth = trim( (string) ( $row['auth'] ?? '' ) );
			$pos    = trim( (string) ( $row['position'] ?? '' ) );
			$pos    = in_array( $pos, self::FORMER_HOME_POSITIONS, true ) ? self::POSITION_NONE : $pos;
			$format = trim( (string) ( $row['format'] ?? '' ) );
			$format = '' === $format ? 'image' : $format;
			$device = trim( (string) ( $row['device'] ?? '' ) );
			$device = '' === $device ? 'all' : $device;
			if ( '' === $tag && '' === $id && '' === $auth ) {
				continue;
			}
			if ( 1 !== preg_match( '/^[a-z0-9_-]{1,40}$/', $tag ) ) {
				$errors[] = sprintf(
					'Invalid tag "%s": use lowercase letters, numbers, - or _',
					$tag
				);
				continue;
			}
			if ( isset( $banners[ $tag ] ) ) {
				$errors[] = sprintf(
					'Duplicate tag "%s"',
					$tag
				);
				continue;
			}
			if ( ! isset( self::FORMAT_AD_UNIT_TYPE[ $format ] ) ) {
				$errors[] = sprintf(
					'Placement "%1$s": unknown format "%2$s"',
					$tag,
					$format
				);
				continue;
			}
			if ( ! in_array( $device, self::DEVICES, true ) ) {
				$errors[] = sprintf(
					'Placement "%1$s": unknown device "%2$s"',
					$tag,
					$device
				);
				continue;
			}
			if ( ! self::is_positive_int( $id ) || '' === $auth ) {
				$errors[] = sprintf(
					'Banner "%s": the numeric ad unit id or its auth is missing',
					$tag
				);
				continue;
			}
			if ( ! in_array( $pos, self::POSITIONS, true ) ) {
				$errors[] = sprintf(
					'Banner "%1$s": unknown position "%2$s"',
					$tag,
					$pos
				);
				continue;
			}
			if ( self::POSITION_CATEGORY_SIDEBAR === $pos && 'desktop' !== $device ) {
				if ( $for_save || 'mobile' === $device ) {
					$errors[] = sprintf(
						'Placement "%s": the category sidebar is only shown on desktop, set its device to Desktop',
						$tag
					);
					continue;
				}
				$device = 'desktop';
			}
			$banners[ $tag ] = array(
				'tag'      => $tag,
				'format'   => $format,
				'type'     => self::FORMAT_AD_UNIT_TYPE[ $format ],
				'device'   => $device,
				'id'       => (int) $id,
				'auth'     => $auth,
				'position' => $pos,
			);
		}
		return array(
			'banners' => array_values( $banners ),
			'errors'  => $errors,
		);
	}

	/** Product of the category listing after which the "inside the grid" placements go (the full width of the grid). */
	public function display_in_grid_after(): int {
		return self::is_positive_int( $this->values['display_in_grid_after'] ) ? (int) $this->values['display_in_grid_after'] : 6;
	}

	/**
	 * Push notifications: the ad unit, when the browser permission prompt may be shown (always with consent: after a
	 * purchase, on the order received page, or after a number of pages of the visit) and, if the shopper did not
	 * answer, after how many days it may be shown again (0: on every visit). Null when off or incomplete.
	 *
	 * @return array{id:int,auth:string,trigger:string,afterPages:int,repeatDays:int}|null
	 */
	public function push(): ?array {
		if ( 'yes' !== $this->values['push_enabled'] || ! self::is_positive_int( $this->values['push_ad_unit'] ) || '' === $this->values['push_auth'] ) {
			return null;
		}
		return array(
			'id'         => (int) $this->values['push_ad_unit'],
			'auth'       => $this->values['push_auth'],
			'trigger'    => self::PUSH_AFTER_PAGES === $this->values['push_trigger'] ? self::PUSH_AFTER_PAGES : self::PUSH_AFTER_PURCHASE,
			'afterPages' => self::is_positive_int( $this->values['push_after_pages'] ) ? (int) $this->values['push_after_pages'] : 2,
			'repeatDays' => ctype_digit( $this->values['push_repeat_days'] ) ? (int) $this->values['push_repeat_days'] : 30,
		);
	}

	/** "Enable" checkbox of the Display section: without it, [irios_display] shortcodes render nothing. */
	public function banners_enabled(): bool {
		return 'yes' === $this->values['banners_enabled'];
	}

	/** "Enable" checkbox of the Sponsored Products section. */
	public function sponsored_enabled(): bool {
		return 'yes' === $this->values['sponsored_enabled'];
	}

	/** Search ads: enabled and with at least one slot set up. */
	public function search_ads_enabled(): bool {
		return $this->sponsored_enabled() && array() !== $this->sponsored_slots();
	}

	/** Maximum number of Sponsored Products slots on a page. */
	public const MAX_SLOTS = 50;

	/**
	 * Sponsored Products slots in the results grid: each one is an ad from an ad unit at a position (1 = top left,
	 * following the grid's reading order). Sorted by position. If the slots have never been saved, the legacy
	 * configuration's: its ad unit at positions 1…"Maximum ads". If saved with no rows ("[]") there are none, even if
	 * the legacy configuration is still stored.
	 *
	 * @return array<int,array{position:int,id:int,auth:string}>
	 */
	public function sponsored_slots(): array {
		if ( null !== $this->slots ) {
			return $this->slots;
		}
		$rows  = json_decode( $this->values['sponsored_slots'], true );
		$slots = is_array( $rows ) ? self::sanitize_sponsored_slots( $rows )['slots'] : array();
		if ( '' !== $this->values['sponsored_slots'] || null === $this->search_ad_unit() || '' === $this->search_ad_unit_auth() ) {
			$this->slots = $slots;
			return $this->slots;
		}
		$legacy = array();
		for ( $position = 1; $position <= $this->max_ads(); $position++ ) {
			$legacy[] = array(
				'position' => $position,
				'id'       => $this->search_ad_unit(),
				'auth'     => $this->search_ad_unit_auth(),
			);
		}
		$this->slots = $legacy;
		return $this->slots;
	}

	/**
	 * Slots grouped by ad unit, for the auction: each ad unit requests as many ads as it has slots, and its winners
	 * fill those positions in order.
	 *
	 * @return array<int,array{adUnit:int,auth:string,positions:int[]}>
	 */
	public function sponsored_units(): array {
		$units = array();
		foreach ( $this->sponsored_slots() as $slot ) {
			if ( ! isset( $units[ $slot['id'] ] ) ) {
				$units[ $slot['id'] ] = array(
					'adUnit'    => $slot['id'],
					'auth'      => $slot['auth'],
					'positions' => array(),
				);
			}
			$units[ $slot['id'] ]['positions'][] = $slot['position'];
		}
		return array_values( $units );
	}

	/**
	 * Validates the slot rows from the settings screen: unique position (1…MAX_SLOTS), numeric ad unit id and its
	 * auth. Empty rows are ignored; invalid ones are dropped with their error.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{slots:array<int,array{position:int,id:int,auth:string}>,errors:string[]}
	 */
	public static function sanitize_sponsored_slots( array $rows ): array {
		$slots  = array();
		$errors = array();
		foreach ( $rows as $row ) {
			$position = trim( (string) ( $row['position'] ?? '' ) );
			$id       = trim( (string) ( $row['id'] ?? '' ) );
			$auth     = trim( (string) ( $row['auth'] ?? '' ) );
			if ( '' === $position && '' === $id && '' === $auth ) {
				continue;
			}
			if ( ! self::is_positive_int( $position ) || (int) $position > self::MAX_SLOTS ) {
				$errors[] = sprintf(
					'Invalid position "%1$s": a number from 1 to %2$d',
					$position,
					self::MAX_SLOTS
				);
				continue;
			}
			if ( isset( $slots[ (int) $position ] ) ) {
				$errors[] = sprintf(
					'Duplicate position %d',
					(int) $position
				);
				continue;
			}
			if ( ! self::is_positive_int( $id ) || '' === $auth ) {
				$errors[] = sprintf(
					'Position %d: the numeric ad unit id or its auth is missing',
					(int) $position
				);
				continue;
			}
			$slots[ (int) $position ] = array(
				'position' => (int) $position,
				'id'       => (int) $id,
				'auth'     => $auth,
			);
		}
		ksort( $slots );
		return array(
			'slots'  => array_values( $slots ),
			'errors' => $errors,
		);
	}

	/** Text that tells an ad apart from organic results (required for advertising transparency). */
	public function sponsored_label(): string {
		if ( '' !== $this->values['sponsored_label'] ) {
			return $this->values['sponsored_label'];
		}
		return self::default_sponsored_label();
	}

	/** Default ad label in the store's language: shown to shoppers, so it stays translatable (the admin is English). */
	public static function default_sponsored_label(): string {
		return function_exists( '__' ) ? __( 'Sponsored', 'irios-woocommerce' ) : 'Sponsored';
	}

	/**
	 * WooCommerce redirects to the product page when a search returns a single result: no results page, no ads.
	 * With ads configured and this setting on, the results page is kept.
	 */
	public function keep_single_result(): bool {
		return 'no' !== $this->values['keep_single_result'] && $this->search_ads_enabled();
	}

	public function max_ads(): int {
		$max = (int) $this->values['max_ads'];
		return $max > 0 ? min( $max, 20 ) : 4;
	}


	public function consent_mode(): string {
		$mode = $this->values['consent_mode'];
		return in_array( $mode, array( self::CONSENT_ALWAYS, self::CONSENT_ASESOR_COOKIES ), true ) ? $mode : self::CONSENT_WP_CONSENT_API;
	}



	/**
	 * Product categories marked as sensitive (health…): their pages and products, and those of all their
	 * subcategories, never reach IRIOS Media tied to a person (see Sensitive).
	 *
	 * @return int[]
	 */
	public function sensitive_categories(): array {
		$ids = array();
		foreach ( explode( ',', $this->values['sensitive_categories'] ) as $id ) {
			if ( self::is_positive_int( $id ) ) {
				$ids[ (int) $id ] = (int) $id;
			}
		}
		return array_values( $ids );
	}

	/** "Treat all searches as sensitive": no search events, and search ads without identifiers. */
	public function sensitive_searches(): bool {
		return 'yes' === $this->values['sensitive_searches'];
	}

	/**
	 * Validates the sensitive categories chosen on the settings screen against the store's product categories. An id
	 * that is not one of them (deleted while the screen was open) is dropped with its error.
	 *
	 * @param mixed $value    Ids submitted (array, or a comma-separated string).
	 * @param int[] $existing Ids of the store's product categories.
	 * @return array{ids:int[],errors:string[]}
	 */
	public static function sanitize_sensitive_categories( $value, array $existing ): array {
		$values   = is_array( $value ) ? $value : explode( ',', (string) $value );
		$existing = array_map( 'intval', $existing );
		$ids      = array();
		$errors   = array();
		foreach ( $values as $id ) {
			$id = is_scalar( $id ) ? trim( (string) $id ) : '';
			if ( '' === $id ) {
				continue;
			}
			if ( ! self::is_positive_int( $id ) || ! in_array( (int) $id, $existing, true ) ) {
				$errors[] = sprintf(
					'Sensitive categories: there is no product category with id "%s"',
					$id
				);
				continue;
			}
			$ids[ (int) $id ] = (int) $id;
		}
		return array(
			'ids'    => array_values( $ids ),
			'errors' => $errors,
		);
	}

	// ---- Validation (used by the settings screen) ------------------------------------------------------------------

	/** Domain without scheme or path: `example.iriosmedia.online`. Returns '' if invalid. */
	public static function normalize_domain( string $value ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '#^https?://#', '', $value );
		$value = rtrim( (string) $value, '/' );
		return 1 === preg_match( '/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $value ) ? $value : '';
	}

	/**
	 * WordPress locale (es_ES, pt_PT_ao90, de_DE_formal…) as BCP 47 with language and region (es-ES, pt-PT, de-DE),
	 * the format required by irios-loader.js; null if it cannot be converted.
	 */
	public static function bcp47_language( string $wp_locale ): ?string {
		$parts    = explode( '_', trim( $wp_locale ) );
		$language = strtolower( $parts[0] );
		$region   = isset( $parts[1] ) ? strtoupper( $parts[1] ) : '';
		$tag      = '' === $region ? $language : $language . '-' . $region;
		return self::is_valid_language( $tag ) ? $tag : null;
	}

	/**
	 * Language of every IRIOS auction (banners, video and Sponsored Products): the locale of the page being viewed
	 * (with WPML or Polylang, that of the store version the visitor is on), then the site language set in Settings >
	 * General. Each one as language and region (es-ES) or, if the region cannot be used, the language alone (es).
	 * Null only if neither gives a usable language: then no ads are requested (irios-loader.js rejects an auction
	 * without a valid language).
	 */
	public static function store_language(): ?string {
		$locales = array( function_exists( 'determine_locale' ) ? (string) determine_locale() : '' );
		if ( function_exists( 'get_locale' ) ) {
			$locales[] = (string) get_locale();
		}
		foreach ( $locales as $locale ) {
			$tag = self::bcp47_language( $locale ) ?? self::locale_language( $locale );
			if ( null !== $tag ) {
				return $tag;
			}
		}
		return null;
	}

	/** Language part of a WordPress locale (es_419 → es); null if it is not a language. */
	private static function locale_language( string $wp_locale ): ?string {
		$language = strtolower( explode( '_', trim( $wp_locale ) )[0] );
		return self::is_valid_language( $language ) ? $language : null;
	}

	public static function is_valid_language( string $value ): bool {
		// Same format that irios-loader.js validates: language, optionally with its region (es, es-ES).
		return 1 === preg_match( '/^[a-z]{2,3}(-[A-Z]{2,3})?$/', $value );
	}

	public static function is_positive_int( string $value ): bool {
		return 1 === preg_match( '/^[1-9][0-9]*$/', trim( $value ) );
	}
}

<?php

namespace Irios\WooCommerce;

/**
 * Settings screen in WooCommerce → Settings → Integration → Irios Media. Only loaded when WooCommerce is active.
 */
final class Integration extends \WC_Integration {

	public function __construct() {
		$this->id                 = 'irios';
		$this->method_title       = 'IRIOS Media';
		$this->method_description = sprintf(
			'IRIOS Media visitor events and identity and, optionally, Sponsored Products, display ads and push notifications. Version %s.',
			IRIOS_WC_VERSION
		);

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** Fields of the Sponsored Products section: hidden while the enable checkbox is unchecked. */
	private const SPONSORED_FIELDS = array(
		'sponsored_label',
		'keep_single_result',
	);

	/** Fields of the Display section: hidden while the enable checkbox is unchecked. */
	private const DISPLAY_FIELDS = array( 'display_in_grid_after' );

	/** Fields of the Push section: hidden while the enable checkbox is unchecked. */
	private const PUSH_FIELDS = array( 'push_ad_unit', 'push_auth', 'push_trigger', 'push_after_pages', 'push_repeat_days' );

	public function init_form_fields(): void {
		$defaults          = Settings::defaults();
		$this->form_fields = array(
			// ---- General: connection with IRIOS Media and consent ------------------------------------------------
			'section_general'          => array(
				'title' => 'General',
				'type'  => 'title',
			),
			'enabled'                  => array(
				'title'       => 'Enable IRIOS Media',
				'type'        => 'checkbox',
				'label'       => 'Enable IRIOS Media',
				'description' => 'Sends the shopper events to IRIOS Media (only with consent) and shows its ads. Without it, or without the IRIOS Media domain and the Store ID, the store stays exactly as without the integration.',
				'default'     => $defaults['enabled'],
			),
			'js_domain'                => array(
				'title'       => 'IRIOS Media domain',
				'type'        => 'text',
				'description' => 'Without https:// or path, e.g. example.iriosmedia.online. irios-tracker.js and irios-loader.js are loaded from it.',
				'default'     => $defaults['js_domain'],
			),
			'store_id'                 => array(
				'title'       => 'Store ID',
				'type'        => 'text',
				'description' => 'Numeric ID of the store in IRIOS Media.',
				'default'     => $defaults['store_id'],
			),
			'consent_mode'             => array(
				'title'       => 'Consent',
				'type'        => 'select',
				'options'     => array(
					Settings::CONSENT_WP_CONSENT_API => 'WP Consent API (recommended)',
					Settings::CONSENT_ASESOR_COOKIES => 'Asesor de Cookies RGPD (webartesanal.com banner)',
					Settings::CONSENT_ALWAYS         => 'Always (the store handles consent another way)',
				),
				'description' => 'Until the visitor accepts marketing cookies, only contextual ads are requested: no identifiers, no IRIOS Media cookies and no events.',
				'default'     => $defaults['consent_mode'],
			),

			// ---- Sensitive data (health…): kept away from the shopper's profile ------------------------------------
			'section_sensitive'        => array(
				'title'       => 'Sensitive Data',
				'type'        => 'title',
				'description' => 'What a shopper looks at or buys can reveal their health (GDPR, art. 9), and ads cannot be based on profiles built with it (DSA, art. 26.3). On sensitive pages and searches no events are sent to IRIOS Media and the ads are requested without identifiers, as without consent; cart events leave out the sensitive products, and orders with a sensitive product are not sent to IRIOS Media.',
			),
			'sensitive_categories'     => array(
				'title'       => 'Sensitive categories',
				'type'        => 'irios_categories',
				'description' => 'Each category includes all its subcategories, also those created later. A product is sensitive if any of its categories is.',
				'default'     => $defaults['sensitive_categories'],
			),
			'sensitive_searches'       => array(
				'title'       => 'Searches',
				'type'        => 'checkbox',
				'label'       => 'Treat all searches as sensitive',
				'description' => 'A search is free text and can reveal a health condition from any page. When checked, no search events are sent and the ads of the search results and of the live search are requested without identifiers.',
				'default'     => $defaults['sensitive_searches'],
			),

			// ---- Sponsored Products (optional) ---------------------------------------------------------------------
			'section_sponsored'        => array(
				'title'       => 'Sponsored Products',
				'type'        => 'title',
				'description' => 'Product ads in the search results, with the same card as the other products and the ad label.',
			),
			'sponsored_enabled'        => array(
				'title'   => 'Enable',
				'type'    => 'checkbox',
				'label'   => 'Show Sponsored Products in the search results',
				'default' => $defaults['sponsored_enabled'],
			),
			'sponsored_slots'          => array(
				'title'   => 'Search results positions',
				'type'    => 'irios_slots',
				'default' => $defaults['sponsored_slots'],
			),
			'sponsored_label'          => array(
				'title'       => 'Sponsored label',
				'type'        => 'text',
				'description' => 'Text added to the sponsored cards. For advertising transparency, do not leave it empty.',
				'default'     => Settings::default_sponsored_label(),
			),
			'keep_single_result'       => array(
				'title'       => 'Single-result search',
				'type'        => 'checkbox',
				'label'       => 'Show the results page instead of going straight to the product page',
				'description' => 'Without a results page, Sponsored Products cannot be shown.',
				'default'     => $defaults['keep_single_result'],
			),

			// ---- Display: image and video (optional) -------------------------------------------------------------
			'section_banners'          => array(
				'title'       => 'Display (Image and Video)',
				'type'        => 'title',
				'description' => 'Image and video ads in placements of the site: in an automatic position or with [irios_display tag="TAG"] wherever you want to show it (a page, the home page, a WPBakery element, a block, a widget or a category description). When disabled, the shortcodes show nothing.',
			),
			'banners_enabled'          => array(
				'title'   => 'Enable',
				'type'    => 'checkbox',
				'label'   => 'Show IRIOS Media display ads',
				'default' => $defaults['banners_enabled'],
			),
			'banners'                  => array(
				'title'   => 'Placements',
				'type'    => 'irios_banners',
				'default' => $defaults['banners'],
			),
			'display_in_grid_after'    => array(
				'title'       => 'Grid: after product',
				'type'        => 'number',
				'description' => 'For the "Category: in grid" position: the placement goes after this product of the listing, the full width of the grid.',
				'default'     => $defaults['display_in_grid_after'],
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			),

			// ---- Push notifications (optional) ---------------------------------------------------------------------
			'section_push'             => array(
				'title'       => 'Push Notifications',
				'type'        => 'title',
				'description' => sprintf(
					'Brand notifications for the shoppers who subscribe in their browser, only with consent. The service worker is served at %s: send IRIOS Media that path, with the leading slash, to set it as the service worker of your store.',
					Push::url()
				),
			),
			'push_enabled'             => array(
				'title'   => 'Enable',
				'type'    => 'checkbox',
				'label'   => 'Ask shoppers to subscribe to IRIOS Media push notifications',
				'default' => $defaults['push_enabled'],
			),
			'push_ad_unit'             => array(
				'title'   => 'Ad unit ID',
				'type'    => 'text',
				'default' => $defaults['push_ad_unit'],
			),
			'push_auth'                => array(
				'title'   => 'Ad unit auth',
				'type'    => 'text',
				'default' => $defaults['push_auth'],
			),
			'push_trigger'             => array(
				'title'       => 'When to ask for permission',
				'type'        => 'select',
				'options'     => array(
					Settings::PUSH_AFTER_PURCHASE => 'After a purchase',
					Settings::PUSH_AFTER_PAGES    => 'After a number of pages',
				),
				'description' => 'The browser permission prompt is shown only with consent: after a purchase (on the order received page) or after a number of pages of the visit.',
				'default'     => $defaults['push_trigger'],
			),
			'push_after_pages'         => array(
				'title'             => 'Number of pages',
				'type'              => 'number',
				'default'           => $defaults['push_after_pages'],
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			),
			'push_repeat_days'         => array(
				'title'             => 'Ask again after (days)',
				'type'              => 'number',
				'description'       => 'If the shopper did not answer the browser prompt, it is not shown again until these days have passed (0: on every visit).',
				'default'           => $defaults['push_repeat_days'],
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
			),
		);

		foreach ( array( 'irios-sponsored-field' => self::SPONSORED_FIELDS, 'irios-banners-field' => self::DISPLAY_FIELDS, 'irios-push-field' => self::PUSH_FIELDS ) as $class => $keys ) {
			foreach ( $keys as $key ) {
				$this->form_fields[ $key ]['class'] = trim( ( $this->form_fields[ $key ]['class'] ?? '' ) . ' ' . $class );
			}
		}
	}

	/** Renders the settings and hides each optional section (Sponsored Products, Banners) until it is enabled. */
	public function admin_options(): void {
		echo Compatibility::warning_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in warning_html()
		$this->form_fields['store_id']['description'] .= ' ' . SyncWebhooks::status( new Settings( $this->settings ) );
		parent::admin_options();
		wc_enqueue_js(
			"( function ( $ ) {
				[ [ '#woocommerce_irios_sponsored_enabled', '.irios-sponsored-field' ], [ '#woocommerce_irios_banners_enabled', '.irios-banners-field' ], [ '#woocommerce_irios_push_enabled', '.irios-push-field' ] ]
					.forEach( function ( pair ) {
						var toggle = $( pair[0] );
						var rows = $( pair[1] ).closest( 'tr' );
						var sync = function () { rows.toggle( toggle.is( ':checked' ) ); };
						toggle.on( 'change', sync );
						sync();
					} );
				// \"Number of pages\" only with \"After a number of pages\"
				var trigger = $( '#woocommerce_irios_push_trigger' );
				var pages = $( '#woocommerce_irios_push_after_pages' ).closest( 'tr' );
				var syncPages = function () {
					pages.toggle( $( '#woocommerce_irios_push_enabled' ).is( ':checked' ) && trigger.val() === 'after_pages' );
				};
				trigger.add( '#woocommerce_irios_push_enabled' ).on( 'change', syncPages );
				syncPages();
				$( document ).on( 'click', '.irios-rows-add', function ( e ) {
					e.preventDefault();
					var body = $( this ).closest( '.irios-rows' ).find( 'tbody' );
					var index = 'n' + Date.now() + Math.floor( Math.random() * 1000 );
					var row = body.find( 'tr.irios-row-template' ).clone().removeClass( 'irios-row-template' ).show();
					row.find( 'input, select' ).each( function () { this.name = this.name.replace( '[__i__]', '[' + index + ']' ); } );
					body.append( row );
				} );
				$( document ).on( 'click', '.irios-rows-remove', function ( e ) {
					e.preventDefault();
					var form = $( this ).closest( 'form' );
					$( this ).closest( 'tr' ).remove();
					// WooCommerce enables \"Save changes\" on a change event; removing a row does not fire one.
					form.find( ':input' ).first().trigger( 'change' );
				} );
			} )( jQuery );"
		);
	}

	/**
	 * Display placements table (tag, format, device, ad unit id and auth, position), with rows that can be added and
	 * removed. Submitted as woocommerce_irios_banners[n][tag|format|device|id|auth|position].
	 *
	 * @param string $key @param array<string,mixed> $data
	 */
	public function generate_irios_banners_html( $key, $data ): string {
		$field   = $this->get_field_key( $key );
		$banners = ( new Settings( array( 'banners' => (string) $this->get_option( $key, '[]' ) ) ) )->banners();
		$select  = static function ( string $index, string $name, array $options, string $value ) use ( $field ): string {
			$html = '';
			foreach ( $options as $option => $label ) {
				$html .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( (string) $option ), selected( $value, (string) $option, false ), esc_html( $label ) );
			}
			return sprintf( '<select name="%s[%s][%s]">%s</select>', esc_attr( $field ), esc_attr( $index ), esc_attr( $name ), $html );
		};
		$cells   = static function ( string $index, array $banner ) use ( $field, $select ): array {
			$positions = array(
				Settings::POSITION_NONE                       => 'Manual only',
				Settings::POSITION_CATEGORY_BEFORE_PRODUCTS   => 'Category: top',
				Settings::POSITION_CATEGORY_IN_GRID           => 'Category: in grid',
				Settings::POSITION_CATEGORY_AFTER_PRODUCTS    => 'Category: bottom',
				Settings::POSITION_CATEGORY_SIDEBAR           => 'Category: sidebar',
				Settings::POSITION_PRODUCT_BEFORE_DESCRIPTION => 'Product: description',
				Settings::POSITION_PRODUCT_BEFORE_RELATED     => 'Product: related',
			);
			return array(
				self::text_input( $field, $index, 'tag', $banner['tag'] ?? '', 'home' ),
				$select( $index, 'format', array( 'image' => 'Image', 'video' => 'Video' ), (string) ( $banner['format'] ?? 'image' ) ),
				$select( $index, 'device', array( 'all' => 'All', 'desktop' => 'Desktop', 'mobile' => 'Mobile' ), (string) ( $banner['device'] ?? 'all' ) ),
				self::text_input( $field, $index, 'id', isset( $banner['id'] ) ? (string) $banner['id'] : '', '1234' ),
				self::text_input( $field, $index, 'auth', $banner['auth'] ?? '', 'ad unit auth' ),
				self::position_select( $field, $index, $positions, (string) ( $banner['position'] ?? '' ) ),
			);
		};
		return self::rows_table(
			$data['title'],
			'irios-banners irios-banners-field',
			array(
				array( 'Tag', 14 ),
				array( 'Format', 11 ),
				array( 'Device', 12 ),
				array( 'Ad unit ID', 11 ),
				array( 'Auth', 17 ),
				array( 'Position', 29 ),
			),
			$cells,
			$banners,
			'Add placement',
			'Tag in lowercase, without spaces (e.g. home, category-sticks); choosing a position with the tag empty fills it with the name of that position\'s ad unit (with -mobile for a Mobile row). Format: that of the ad unit in IRIOS Media. Device: the same spot can have one row per device (desktop from 768 px wide). Position: Manual only, with [irios_display tag="TAG"] anywhere on the site, or automatic: at the start or the end of the home page content, above the category grid, inside it, after the category products, category sidebar (desktop only), before the product description or before the related products. In brackets, the name of the ad unit to create in IRIOS Media for that position (with -mobile at the end for the mobile one).'
		);
	}

	/**
	 * Sponsored Products slots table (position in the grid, ad unit id and auth). Submitted as
	 * woocommerce_irios_sponsored_slots[n][position|id|auth]. With no saved slots it shows those from the legacy
	 * configuration (one ad unit in the first positions), which are stored as slots on save.
	 *
	 * @param string $key @param array<string,mixed> $data
	 */
	public function generate_irios_slots_html( $key, $data ): string {
		$field = $this->get_field_key( $key );
		$slots = ( new Settings( $this->settings ) )->sponsored_slots();
		$cells = static function ( string $index, array $slot ) use ( $field ): array {
			return array(
				sprintf(
					'<input type="number" min="1" max="%d" step="1" name="%s[%s][position]" value="%s" placeholder="1">',
					Settings::MAX_SLOTS,
					esc_attr( $field ),
					esc_attr( $index ),
					esc_attr( isset( $slot['position'] ) ? (string) $slot['position'] : '' )
				),
				self::text_input( $field, $index, 'id', isset( $slot['id'] ) ? (string) $slot['id'] : '', '1234' ),
				self::text_input( $field, $index, 'auth', $slot['auth'] ?? '', 'ad unit auth' ),
			);
		};
		return self::rows_table(
			$data['title'],
			'irios-slots irios-sponsored-field',
			array(
				array( 'Position', 16 ),
				array( 'Ad unit ID', 22 ),
				array( 'Auth', 56 ),
			),
			$cells,
			$slots,
			'Add position',
			'One ad per row. The position is the place in the results grid: 1 is top left, counted in reading order (left to right, row by row). The same ad unit can take several positions. In the live search dropdown there is at most one ad, at the top: the one of the lowest position.'
		);
	}

	/** Paths of marked categories named in the one-line summary; the rest are left as "…". */
	private const SUMMARY_NAMES = 3;

	/**
	 * The sensitive categories: a folded tree of checkboxes with the store's product categories, nested and in the
	 * store's order. Closed it is one line (how many are marked and which); open, each branch folds on its own and a
	 * filter box finds a category by name. A marked category covers its whole subtree, so its descendants are drawn
	 * checked, disabled and "included": a disabled box is not submitted, so only the explicit marks are stored. The
	 * folding, the filter and the "included" state after a click are done by assets/js/admin-categories.js; what is
	 * drawn here is the state of what is saved, so the screen is right before that script runs.
	 *
	 * Submitted as woocommerce_irios_sensitive_categories[] (with an empty value first, so that unchecking them all
	 * clears the setting) and stored as their ids, comma separated. The categories are read here, when the screen is
	 * drawn: the integration is built before WordPress registers the taxonomies.
	 *
	 * @param string $key @param array<string,mixed> $data
	 */
	public function generate_irios_categories_html( $key, $data ): string {
		$field  = $this->get_field_key( $key );
		$marked = array_flip( ( new Settings( array( 'sensitive_categories' => (string) $this->get_option( $key, '' ) ) ) )->sensitive_categories() );
		$tree   = self::category_tree();
		$paths  = array();
		self::marked_paths( $tree, $marked, $paths );
		$title = (string) ( $data['title'] ?? '' );
		$one  = '%1$d category marked: %2$s';
		$many = '%1$d categories marked: %2$s';
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><span id="<?php echo esc_attr( $field ); ?>-label"><?php echo esc_html( $title ); ?></span></th>
			<td class="forminp">
				<div class="irios-cat-tree" role="group" aria-labelledby="<?php echo esc_attr( $field ); ?>-label"
					data-none="<?php echo esc_attr( 'No sensitive categories' ); ?>"
					data-one="<?php echo esc_attr( $one ); ?>"
					data-many="<?php echo esc_attr( $many ); ?>"
					data-show="<?php echo esc_attr( 'Show categories' ); ?>"
					data-hide="<?php echo esc_attr( 'Hide categories' ); ?>"
					data-limit="<?php echo (int) self::SUMMARY_NAMES; ?>">
					<input type="hidden" name="<?php echo esc_attr( $field ); ?>[]" value="">
					<p class="irios-cat-head">
						<span class="irios-cat-summary" aria-live="polite"><?php echo esc_html( self::categories_summary( $paths, $one, $many ) ); ?></span>
						<button type="button" class="button-link irios-cat-toggle" aria-expanded="false" aria-controls="<?php echo esc_attr( $field ); ?>-panel"><?php echo esc_html( 'Show categories' ); ?></button>
					</p>
					<div class="irios-cat-panel" id="<?php echo esc_attr( $field ); ?>-panel" hidden>
						<?php if ( array() === $tree ) : ?>
							<p class="irios-cat-empty"><?php echo esc_html( 'The store has no product categories yet.' ); ?></p>
						<?php else : ?>
							<p class="irios-cat-search">
								<label class="screen-reader-text" for="<?php echo esc_attr( $field ); ?>-filter"><?php echo esc_html( 'Filter categories' ); ?></label>
								<input type="search" class="irios-cat-filter" id="<?php echo esc_attr( $field ); ?>-filter" placeholder="<?php echo esc_attr( 'Filter categories' ); ?>" autocomplete="off">
							</p>
							<p class="irios-cat-nomatch" hidden><?php echo esc_html( 'No category matches the filter.' ); ?></p>
							<?php echo self::category_list( $tree, $marked, $field, false, $field . '-root', true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in category_list() ?>
						<?php endif; ?>
					</div>
				</div>
				<p class="description"><?php echo esc_html( (string) ( $data['description'] ?? '' ) ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One level of the tree, and below each category its own. $covered: an ancestor is marked, so these are included.
	 * $open: the level is unfolded (the top one, and a branch that holds a marked category).
	 *
	 * @param array<int,array{id:int,name:string,path:string,children:array}> $nodes
	 * @param array<int,int>                                                    $marked Marked ids (as keys).
	 */
	private static function category_list( array $nodes, array $marked, string $field, bool $covered, string $id, bool $open ): string {
		$html = sprintf( '<ul class="irios-cat-list" id="%s"%s>', esc_attr( $id ), $open ? '' : ' hidden' );
		foreach ( $nodes as $node ) {
			$own      = isset( $marked[ $node['id'] ] );
			$box      = $field . '-' . $node['id'];
			$children = $field . '-children-' . $node['id'];
			$branch   = '<span class="irios-cat-leaf" aria-hidden="true"></span>';
			$unfold   = self::contains_marked( $node['children'], $marked );
			if ( array() !== $node['children'] ) {
				$branch = sprintf(
					'<button type="button" class="irios-cat-branch" aria-expanded="%s" aria-controls="%s"><span class="screen-reader-text">%s</span></button>',
					$unfold ? 'true' : 'false',
					esc_attr( $children ),
					esc_html( sprintf( 'Subcategories of %s', $node['name'] ) )
				);
			}
			$html .= sprintf(
				'<li class="irios-cat-item%s" data-path="%s"><span class="irios-cat-row">%s<input type="checkbox" id="%s" name="%s[]" value="%d" data-marked="%s"%s%s> <label for="%s">%s</label> <span class="irios-cat-included"%s>%s</span></span>%s</li>',
				$covered ? ' is-included' : '',
				esc_attr( $node['path'] ),
				$branch,
				esc_attr( $box ),
				esc_attr( $field ),
				(int) $node['id'],
				$own ? '1' : '0',
				$own || $covered ? ' checked="checked"' : '',
				$covered ? ' disabled="disabled"' : '',
				esc_attr( $box ),
				esc_html( $node['name'] ),
				$covered ? '' : ' hidden',
				esc_html( 'included' ),
				array() === $node['children'] ? '' : self::category_list( $node['children'], $marked, $field, $covered || $own, $children, $unfold )
			);
		}
		return $html . '</ul>';
	}

	/**
	 * @param array<int,array{id:int,name:string,path:string,children:array}> $nodes
	 * @param array<int,int>                                                    $marked
	 */
	private static function contains_marked( array $nodes, array $marked ): bool {
		foreach ( $nodes as $node ) {
			if ( isset( $marked[ $node['id'] ] ) || self::contains_marked( $node['children'], $marked ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Paths of the marked categories, in the order of the tree. A mark below another one is left out: it is included in
	 * that one, and it is not submitted again, so it goes away on the next save.
	 *
	 * @param array<int,array{id:int,name:string,path:string,children:array}> $nodes
	 * @param array<int,int>                                                    $marked
	 * @param string[]                                                          $paths
	 */
	private static function marked_paths( array $nodes, array $marked, array &$paths ): void {
		foreach ( $nodes as $node ) {
			if ( isset( $marked[ $node['id'] ] ) ) {
				$paths[] = $node['path'];
			} else {
				self::marked_paths( $node['children'], $marked, $paths );
			}
		}
	}

	/**
	 * "2 categories marked: Health, Beauty > Skin" (the first few, then "…"), or "No sensitive categories".
	 *
	 * @param string[] $paths
	 * @param string   $one  Format for one category (count, names).
	 * @param string   $many Format for several.
	 */
	private static function categories_summary( array $paths, string $one, string $many ): string {
		if ( array() === $paths ) {
			return 'No sensitive categories';
		}
		$names = implode( ', ', array_slice( $paths, 0, self::SUMMARY_NAMES ) ) . ( count( $paths ) > self::SUMMARY_NAMES ? '…' : '' );
		return sprintf( 1 === count( $paths ) ? $one : $many, count( $paths ), $names );
	}

	/**
	 * The store's product categories as a tree: each with its name, its path (Parent > Child) and its children, in the
	 * order WooCommerce gives them (the order set in Products → Categories). A category whose parent is missing is a
	 * root; a loop in the parents is cut.
	 *
	 * @return array<int,array{id:int,name:string,path:string,children:array}>
	 */
	private static function category_tree(): array {
		// No orderby: WooCommerce then sorts the categories by their order in Products → Categories
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$by_id = array();
		foreach ( $terms as $term ) {
			if ( $term instanceof \WP_Term ) {
				$by_id[ (int) $term->term_id ] = $term;
			}
		}
		$children = array();
		foreach ( $by_id as $id => $term ) {
			$parent = (int) $term->parent;
			$children[ isset( $by_id[ $parent ] ) && $parent !== $id ? $parent : 0 ][] = $id;
		}
		$seen  = array();
		$build = static function ( int $parent, array $names ) use ( &$build, &$seen, $children, $by_id ): array {
			$nodes = array();
			foreach ( $children[ $parent ] ?? array() as $id ) {
				if ( isset( $seen[ $id ] ) ) {
					continue;
				}
				$seen[ $id ] = true;
				$path        = array_merge( $names, array( $by_id[ $id ]->name ) );
				$nodes[]     = array(
					'id'       => $id,
					'name'     => (string) $by_id[ $id ]->name,
					'path'     => Catalog::join_path( $path ),
					'children' => $build( $id, $path ),
				);
			}
			return $nodes;
		};
		$tree = $build( 0, array() );
		// Categories in a loop of parents (never reached from a root) are shown as roots
		foreach ( array_keys( $by_id ) as $id ) {
			if ( ! isset( $seen[ $id ] ) ) {
				$seen[ $id ] = true;
				$tree[]      = array(
					'id'       => $id,
					'name'     => (string) $by_id[ $id ]->name,
					'path'     => Catalog::join_path( array( $by_id[ $id ]->name ) ),
					'children' => array(),
				);
			}
		}
		return $tree;
	}

	/**
	 * Ids of every product category in the tree.
	 *
	 * @param array<int,array{id:int,name:string,path:string,children:array}> $nodes
	 * @return int[]
	 */
	private static function category_ids( array $nodes ): array {
		$ids = array();
		foreach ( $nodes as $node ) {
			$ids[] = $node['id'];
			array_push( $ids, ...self::category_ids( $node['children'] ) );
		}
		return $ids;
	}

	/**
	 * Admin CSS and JS of the sensitive categories tree and of the Display placements table, only on this settings
	 * screen (WooCommerce → Settings → Integration → IRIOS Media; with no section in the URL, WooCommerce shows the
	 * first integration, which may be it).
	 */
	public static function enqueue_admin_assets( $hook = '' ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only reads which screen this is
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'woocommerce_page_wc-settings' !== $hook || 'integration' !== $tab || ! in_array( $section, array( '', 'irios' ), true ) ) {
			return;
		}
		$dir = dirname( IRIOS_WC_FILE );
		wp_enqueue_style(
			'irios-woocommerce-admin-categories',
			plugins_url( 'assets/css/admin-categories.css', IRIOS_WC_FILE ),
			array(),
			IRIOS_WC_VERSION . '.' . (string) filemtime( $dir . '/assets/css/admin-categories.css' )
		);
		wp_enqueue_script(
			'irios-woocommerce-admin-categories',
			plugins_url( 'assets/js/admin-categories.js', IRIOS_WC_FILE ),
			array(),
			IRIOS_WC_VERSION . '.' . (string) filemtime( $dir . '/assets/js/admin-categories.js' ),
			array( 'in_footer' => true )
		);
		wp_enqueue_script(
			'irios-woocommerce-admin-display',
			plugins_url( 'assets/js/admin-display.js', IRIOS_WC_FILE ),
			array(),
			IRIOS_WC_VERSION . '.' . (string) filemtime( $dir . '/assets/js/admin-display.js' ),
			array( 'in_footer' => true )
		);
	}

	/** @param string $key @param mixed $value Ids of the categories chosen as sensitive. */
	public function validate_sensitive_categories_field( $key, $value ): string {
		$result = Settings::sanitize_sensitive_categories( is_array( $value ) ? $value : array(), self::category_ids( self::category_tree() ) );
		foreach ( $result['errors'] as $error ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: ' . $error );
		}
		return implode( ',', $result['ids'] );
	}

	/**
	 * Position select of a Display row. Each automatic position shows the name of its IRIOS ad unit, which
	 * assets/js/admin-display.js copies into an empty Tag when the position is chosen.
	 *
	 * @param array<string,string> $positions Position → label.
	 */
	private static function position_select( string $field, string $index, array $positions, string $value ): string {
		$html = '';
		foreach ( $positions as $position => $label ) {
			$name  = Settings::AD_UNIT_NAMES[ $position ] ?? '';
			$html .= sprintf(
				'<option value="%s"%s%s>%s</option>',
				esc_attr( (string) $position ),
				'' !== $name ? ' data-ad-unit-name="' . esc_attr( $name ) . '"' : '',
				selected( $value, (string) $position, false ),
				esc_html( '' !== $name ? $label . ' (' . $name . ')' : $label )
			);
		}
		return sprintf( '<select class="irios-position" name="%s[%s][position]">%s</select>', esc_attr( $field ), esc_attr( $index ), $html );
	}

	private static function text_input( string $field, string $index, string $name, string $value, string $placeholder ): string {
		return sprintf(
			'<input type="text" name="%s[%s][%s]" value="%s" placeholder="%s">',
			esc_attr( $field ),
			esc_attr( $index ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * Table of rows that can be added and removed (banners, Sponsored Products slots). $columns: [header, % width];
	 * the last column (✕) takes the rest.
	 *
	 * @param array<int,array{0:string,1:int}> $columns
	 * @param callable(string,array):string[]   $cells
	 * @param array<int,array<string,mixed>>    $rows
	 */
	private static function rows_table( string $title, string $class, array $columns, callable $cells, array $rows, string $add_label, string $description ): string {
		$row = static function ( string $index, array $values, bool $template ) use ( $cells ): string {
			$html = '<tr' . ( $template ? ' class="irios-row-template" style="display:none"' : '' ) . '>';
			foreach ( $cells( $index, $values ) as $cell ) {
				$html .= '<td>' . $cell . '</td>';
			}
			return $html . '<td><a href="#" class="irios-rows-remove" aria-label="' . esc_attr( 'Remove' ) . '">✕</a></td></tr>';
		};
		$body = '';
		foreach ( array_values( $rows ) as $i => $values ) {
			$body .= $row( (string) $i, $values, false );
		}
		if ( '' === $body ) {
			$body = $row( '0', array(), false );
		}
		// Template for "Add": the JS replaces __i__ with a unique index in each new row.
		$body .= $row( '__i__', array(), true );

		$colgroup = '';
		$headers  = '';
		$used     = 0;
		foreach ( $columns as $column ) {
			$colgroup .= '<col style="width:' . (int) $column[1] . '%">';
			$headers  .= '<th>' . esc_html( $column[0] ) . '</th>';
			$used     += (int) $column[1];
		}
		$colgroup .= '<col style="width:' . max( 4, 100 - $used ) . '%">';
		$headers  .= '<th></th>';

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $title ); ?></th>
			<td class="forminp">
				<div class="irios-rows <?php echo esc_attr( $class ); ?>">
					<style>
						/* WooCommerce form-table styles (th and input widths) break the table layout: same selectors + .irios-rows */
						.woocommerce table.form-table .irios-rows-scroll { overflow-x: auto; max-width: 960px; }
						.woocommerce table.form-table .irios-rows table { table-layout: fixed; width: 100%; min-width: 720px; }
						.woocommerce table.form-table .irios-rows table th,
						.woocommerce table.form-table .irios-rows table td { width: auto; padding: 8px 10px; vertical-align: middle; }
						.woocommerce table.form-table .irios-rows table thead th { padding-top: 12px; padding-bottom: 12px; font-weight: 600; }
						.woocommerce table.form-table .irios-rows table input[type="text"],
						.woocommerce table.form-table .irios-rows table input[type="number"],
						.woocommerce table.form-table .irios-rows table select { box-sizing: border-box; width: 100%; min-width: 0; max-width: none; margin: 0; }
						.woocommerce table.form-table .irios-rows .irios-rows-remove { text-decoration: none; }
					</style>
					<div class="irios-rows-scroll">
					<table class="widefat striped">
						<colgroup><?php echo $colgroup; // phpcs:ignore ?></colgroup>
						<thead><tr><?php echo $headers; // phpcs:ignore ?></tr></thead>
						<tbody><?php echo $body; // phpcs:ignore ?></tbody>
					</table>
					</div>
					<p><a href="#" class="button irios-rows-add"><?php echo esc_html( $add_label ); ?></a></p>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				</div>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/** @param string $key @param mixed $value Rows of the Sponsored Products slots table. */
	public function validate_sponsored_slots_field( $key, $value ): string {
		$rows   = is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
		$result = Settings::sanitize_sponsored_slots( wp_unslash( $rows ) );
		foreach ( $result['errors'] as $error ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: ' . $error );
		}
		return (string) wp_json_encode( $result['slots'] );
	}

	/** @param string $key @param mixed $value Rows of the banners table. */
	public function validate_banners_field( $key, $value ): string {
		$rows   = is_array( $value ) ? array_values( array_filter( $value, 'is_array' ) ) : array();
		$result = Settings::sanitize_banners( wp_unslash( $rows ), true );
		foreach ( $result['errors'] as $error ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: ' . $error );
		}
		return (string) wp_json_encode( $result['banners'] );
	}

	/** On save: with Sponsored Products enabled, at least one slot (position, ad unit and auth) is required. */
	public function process_admin_options(): bool {
		$saved = parent::process_admin_options();
		$settings = new Settings( $this->settings );
		if ( $settings->sponsored_enabled() && ! $settings->search_ads_enabled() ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: to show Sponsored Products, add at least one position with its ad unit and auth. Until then, no ads are shown.' );
		}
		if ( 'yes' === ( $this->settings['push_enabled'] ?? 'no' ) && null === $settings->push() ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: to ask for push notifications, add the push ad unit and its auth. Until then, nothing is asked.' );
		}
		// The product webhooks to the sync service follow the domain and the Store ID just saved (SyncWebhooks)
		Settings::forget();
		try {
			SyncWebhooks::maintain();
		} catch ( \Exception $e ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: the product webhooks to the sync service could not be set up: ' . $e->getMessage() );
		}
		return $saved;
	}

	/** @param string $key @param mixed $value */
	public function validate_js_domain_field( $key, $value ): string {
		$value  = (string) $value;
		$domain = Settings::normalize_domain( $value );
		if ( '' !== trim( $value ) && '' === $domain ) {
			\WC_Admin_Settings::add_error( 'IRIOS Media: the IRIOS Media domain is not valid.' );
			return (string) $this->get_option( $key );
		}
		return $domain;
	}

	/** @param string $key @param mixed $value */
	public function validate_push_ad_unit_field( $key, $value ): string {
		return $this->positive_int_or_keep( $key, (string) $value, 'IRIOS Media: the push Ad unit ID must be a number.' );
	}

	/** @param string $key @param mixed $value */
	public function validate_display_in_grid_after_field( $key, $value ): string {
		$value = trim( (string) $value );
		return Settings::is_positive_int( $value ) ? $value : '6';
	}

	/** @param string $key @param mixed $value */
	public function validate_push_after_pages_field( $key, $value ): string {
		$value = trim( (string) $value );
		return Settings::is_positive_int( $value ) ? $value : '2';
	}

	/** @param string $key @param mixed $value */
	public function validate_push_repeat_days_field( $key, $value ): string {
		$value = trim( (string) $value );
		return ctype_digit( $value ) ? (string) (int) $value : '30';
	}

	/** @param string $key @param mixed $value */
	public function validate_store_id_field( $key, $value ): string {
		return $this->positive_int_or_keep( $key, (string) $value, 'IRIOS Media: the Store ID must be a number.' );
	}

	private function positive_int_or_keep( string $key, string $value, string $error ): string {
		$value = trim( $value );
		if ( '' === $value || Settings::is_positive_int( $value ) ) {
			return $value;
		}
		\WC_Admin_Settings::add_error( $error );
		return (string) $this->get_option( $key );
	}
}

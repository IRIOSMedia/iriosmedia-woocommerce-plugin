# IRIOS Media for WooCommerce

WordPress plugin that connects a WooCommerce store with **IRIOS Media**:

- **Events and identity**: `page-view`, `category-view`, `search`, `product-view` (including the selected variation),
  `checkout-step`, `add-to-cart`, `remove-from-cart` and `cart-view`. The browser is identified by the IRIOS Media
  cookie (`irs1P`), which is also stored in the order with the consent answer; for logged-in customers, also their id
  and their hashed email and phone (SHA-256). Purchases are not sent by the plugin: IRIOS Media's synchronization
  service reads them, only with consent, through the plugin's own API (see
  [Access for the IRIOS Media sync service](#sync-service)).
- **Sponsored Products** (optional): product ads in the search results and in the theme's live search, rendered with
  the store's own product card and an ad label ("Sponsored").
- **Display** (optional): image and video ads through a shortcode or at automatic positions (category: sidebar, above
  the grid, inside it, after the products; product page: before the description, before the related products), per device.
- **Push Notifications** (optional): brand notifications for the shoppers who subscribe in their browser, asked after
  a purchase or after a number of pages, only with consent.
- **Ad transparency** (EU Digital Services Act, art. 26): an info button next to every ad label opens "About this
  ad", with why the ad is shown and on whose behalf it is shown and who paid for it.

**Privacy.** Without the visitor's marketing consent, only contextual ads are shown (based on the search or the page):
no identifiers, no IRIOS Media cookies and no events. With consent, events, identifiers and personalised ads as well.

**Sensitive data.** What a shopper looks at or buys can reveal their health (GDPR, art. 9), and ads cannot be based on
profiles built with it (DSA, art. 26.3). The store can mark product categories as sensitive (and, optionally, every
search); then, even with consent, nothing about them reaches IRIOS Media tied to the shopper: no events of those pages
or products, their ads requested without identifiers (as without consent; the ads are still shown), cart events without
those products, and no orders that include them. See [Sensitive Data](#sensitive-data).

Requirements: WordPress 6.4+, PHP 8.1+ and WooCommerce 8.5+, served over HTTPS (push notifications and the sync
service need it).

## Setup checklist

Before starting, ask IRIOS Media for: the **IRIOS Media domain** of the store's network, the **Store ID**, and an
**ad unit ID and its auth** for each Sponsored Products position, Display placement and the push ad unit you are going
to use (each Display ad unit also has a format: image or video). For the sync service, IRIOS Media also gives you,
through a secure channel, a webhook **delivery URL** and **secret** ([Webhooks](#webhooks)).

1. **Install** the plugin and activate it ([Installation](#installation)). WooCommerce must be active.
2. **Connect the cookie banner**: choose the consent mode and check that the banner gives the `marketing` category
   ([Consent and the cookie banner](#consent)).
3. **General settings**: in **WooCommerce → Settings → Integration → IRIOS Media**, enter the IRIOS Media domain and
   the Store ID and save. From now on events (with consent) are sent ([General](#general)).
4. **Sensitive Data**: mark the health-related product categories and decide whether every search is sensitive
   ([Sensitive Data](#sensitive-data)). Do it before going live: nothing is filtered until then.
5. **Sponsored Products** (optional): enable it and add the search results positions with their ad units
   ([Sponsored Products](#sponsored-products)).
6. **Display** (optional): enable it, add the placements and put the `[irios_display]` shortcodes where needed
   ([Display](#display)).
7. **Push Notifications** (optional): enable it, add the push ad unit, check that the service worker answers at
   `/irios-sw.js` and send that path to IRIOS Media ([Push Notifications](#push-notifications)).
8. **Sync service access**: create a read-only WooCommerce REST API key and send it to IRIOS Media
   ([Access for the IRIOS Media sync service](#sync-service)), and create the product webhooks with the delivery
   URL and secret given by IRIOS Media ([Webhooks](#webhooks)).
9. **Caching and optimization**: exclude what must not be cached or delayed ([Caching, optimization and
   CDN](#caching)).
10. **Verify** everything in a browser ([Verification](#verification)).

**What to send IRIOS Media**: the store URL, the theme in use (and whether it is a child theme), the service worker
path (step 7), the REST API key and confirmation that the webhooks are active (step 8) and, when Display uses
`page_type` on WordPress pages, the page types used.

## Installation

1. Download `irios-woocommerce.zip` from the [latest release](../../releases/latest).
2. In WordPress, **Plugins → Add New → Upload Plugin**, choose the zip, **Install Now** → **Activate**. WooCommerce
   must be active.
3. Go to **WooCommerce → Settings → Integration → IRIOS Media** and fill in the settings (below) with the details
   provided by IRIOS Media. Until the domain and the Store ID are set, a notice on the admin pages says the plugin is
   not configured, and the store stays exactly as without it.
4. Make sure the store has a cookie banner compatible with the selected consent mode.

### Updates

The plugin never updates itself. When a new version is published, administrators who can update plugins see a notice
on the Dashboard, Plugins and WooCommerce settings pages with links to the release notes and the zip. To update,
upload the new zip the same way: WordPress detects it is already installed and offers **Replace current with
uploaded**. Settings are kept. The notice can be dismissed per version. What changes in each version is in the
[changelog](CHANGELOG.md).

To know about new versions, the store checks the public list of releases on GitHub at most every 12 hours, only when
one of those admin pages is opened; no store data is sent. To turn the check off, add to the theme or a custom plugin:

```php
add_filter( 'irios_woocommerce_check_updates', '__return_false' );
```

After updating, purge the page cache and the CDN if the store has them ([Caching](#caching)).

#### Updating from "Irios for WooCommerce"

The first version of the plugin was called "Irios for WooCommerce" and lived in the `irios-for-woocommerce` folder.
WordPress sees this one as a different plugin, so it does not replace it:

1. **Plugins → Deactivate** "Irios for WooCommerce". Do not delete it yet, so it can be reactivated if needed.
2. Upload and activate `irios-woocommerce.zip` as above. The settings are shared, so they are already there: check
   them in **WooCommerce → Settings → Integration → IRIOS Media**, as this version adds settings the old one did not
   have (page types are sent on their own, Display placements and push notifications).
3. Once it works, **Delete** the old plugin.

While both are active only the old one runs, and the new one shows a notice asking to deactivate it. The old plugin's
uninstall deletes the shared settings, but this one saves them and writes them back, so deleting it does not lose the
configuration.

Shoppers get a new IRIOS Media identifier: the old plugin used its own `_irios_uid` cookie, and this one uses the
tracker's `irs1P`, the same as every other IRIOS Media integration.

### Uninstall

**Plugins → Deactivate** and then **Delete**. Once deactivated it stops loading and sending anything (the service
worker path stops answering, so no notification can be subscribed); deleting it removes its settings. The browser identifier stored in
past orders is kept as part of the order history. If the dashboard does not respond, delete the
`wp-content/plugins/irios-woocommerce` folder over FTP/SSH. Also revoke the REST API key created for IRIOS Media
(**WooCommerce → Settings → Advanced → REST API**).

## Settings

**WooCommerce → Settings → Integration → IRIOS Media.** Nothing configured here is secret: it all reaches the browser.
The settings are in English, as in every IRIOS Media integration. Every ad unit ID and auth comes from IRIOS Media: an
ad unit is created there for each position, placement or push, and its auth is the key that goes with it. If a value
is not valid, saving shows an error at the top of the page and keeps the previous value.

<a id="general"></a>**General**:

| Setting | Description |
|---|---|
| Enable IRIOS Media | Main switch (on by default; nothing is loaded until the domain and the Store ID are set) |
| IRIOS Media domain | Domain of the store's network, given by IRIOS Media, without `https://` or path (e.g. `example.iriosmedia.online`). The IRIOS Media scripts are loaded from it |
| Store ID | Numeric id of the store in IRIOS Media, given by IRIOS Media |
| Consent | How the plugin knows whether the visitor accepted marketing cookies: **WP Consent API** (recommended, with a compatible banner), **Asesor de Cookies RGPD** (reads its `cdp_cookie_consent` cookie) or **Always** (the store handles consent another way). See below |

<a id="consent"></a>**Consent and the cookie banner.** The plugin needs the visitor's consent to the **marketing**
category. It notices when the visitor accepts or withdraws it, without reloading: on acceptance it starts sending
events; on withdrawal it stops, and cancels the push subscription of that browser.

- **WP Consent API** (recommended): install the free **WP Consent API** plugin and a cookie banner that supports it
  (Complianz, CookieYes, Cookiebot…), with the integration with the WP Consent API turned on in the banner if it has a
  switch for it. The banner must ask for the **marketing** category (the banner may call it "Marketing" or
  "Advertising"). Without the API or without a banner that uses it there is no consent, and an admin notice says so.
- **Asesor de Cookies RGPD**: for stores with that banner (webartesanal.com). Only `"marketing": true` in its
  `cdp_cookie_consent` cookie counts as consent. If the banner is not active, an admin notice says so.
- **Always**: every visitor counts as having consented. Use it only when the store obtains consent another way, for
  example a banner that blocks the plugin's script (`irios-woocommerce-js`) until marketing consent; then no ads are
  shown at all before consent, not even contextual ones.

<a id="sensitive-data"></a>**Sensitive Data**:

| Setting | Description |
|---|---|
| Sensitive categories | Product categories whose pages and products are sensitive (health…). Each one covers all its subcategories, also those created later: mark the parent category only. A product is sensitive if any of its categories is; a variation, if its product is. Empty by default |
| Searches | **Treat all searches as sensitive** (off by default): a search is free text and can reveal a health condition from any page |

Nothing changes until a category is marked or searches are set as sensitive. With something sensitive, also with
consent:

- **Events**: no `page-view`, `category-view` or `product-view` of a sensitive category or product page, nor
  `search` events when searches are sensitive (nor on a search filtered by a sensitive category). `add-to-cart`,
  `remove-from-cart` and `cart-view` leave out the sensitive products, and are not sent if none is left.
  `checkout-step` carries no products and is sent.
- **Ads**: on a sensitive page, and in the search results and the live search when searches are sensitive, ads are
  requested and tracked without identifiers, exactly as without consent (contextual). They are still shown.
- **Purchases**: the plugin's API does not give the orders that include a sensitive product, so they never reach IRIOS
  Media. Customers and the catalog are not affected. Marking a category later also hides the past orders that include
  it from then on.
- When it cannot be known (a product that no longer exists, categories that cannot be read), it counts as sensitive.

<a id="sponsored-products"></a>**Sponsored Products** (the fields appear once enabled):

| Setting | Description |
|---|---|
| Enable | Shows ads in the search results and in the live search |
| Search results positions | One row per ad: **Position**, **Ad unit ID** and **Auth** (see below). At least one row is needed: without it, saving shows an error and no ads are shown |
| Sponsored label | Text of the sponsored cards ("Sponsored" by default, translated to the store language); do not leave it empty |
| Single-result search | Keeps the results page instead of redirecting to the product, so ads can be shown |

**Search results positions**: each row is a place in the results grid (1 to 50, unique). Position 1 is top left and
positions follow reading order (left to right, row by row); with 4 columns, position 5 is the first of the second
row. An ad unit can take several positions (e.g. 1001 at 1 and 2, and 1002 at 6):

- All ad units go in a single auction and each one asks for as many ads as positions it has. Its winners take its
  positions from lowest to highest. If it wins fewer ads, its last positions keep the organic results.
- A product that already won in another ad unit is not repeated. The organic result for the same product is hidden.
- Positions count the visible results; if the page has fewer, the ad goes at the end. In a search with no results,
  the sponsored products are shown above the "no products found" notice.
- The live search shows at most one ad, at the top of the dropdown: the one of the ad unit of the lowest position.
- Ads are shown in **product** searches (`/?s=…&post_type=product`, what the WooCommerce search form and block and
  most shop themes use). A search form that searches posts and pages does not get them.

<a id="display"></a>**Display (Image and Video)** (the table appears once enabled): one row per placement with **Tag**
(lowercase, digits, `-` and `_`; unique), **Format** (image or video, as the ad unit in IRIOS Media), **Device** (all,
desktop from 768 px wide, or mobile: the same spot can have one row per device, with ad units of different sizes),
**Ad unit ID**, **Auth** and **Position**:

- **Manual only**: shown wherever `[irios_display tag="TAG"]` is placed (a page, the home page, a page-builder
  element, a block, a widget or a category description). `[irios_banner]`, its name in 1.0, still works. In the block
  editor, use a **Shortcode** block.
- **Category: sidebar**: at the end of the shop sidebar, below its widgets (the filters). Desktop only: on phones the
  sidebar is a filter panel or far below the products, so a sidebar placement must have the device Desktop.
- **Category: top**: right above the product grid, after the result count and the sorting, centred.
- **Category: in grid**: inside the product grid, the full width of it, after the product set in
  **Grid: after product** (6 by default).
- **Category: bottom**: below the product grid and its sorting and pagination controls, centred.
- **Product: description**: before the product page tabs.
- **Product: related**: before the related products (or the upsells).

The **Category** positions are used on product category pages, and the **Product** positions on product pages. For
anywhere else (the Shop page, the home page, landing pages) use a manual placement. Unchecking the box turns all
display ads off without touching the pages: the shortcodes show nothing.

<a id="push-notifications"></a>**Push Notifications** (the fields appear once enabled): the push **Ad unit ID** and
its **Ad unit auth** (both required: without them, saving shows an error and nothing is asked), **When to ask for
permission** (after a purchase, on the order received page, or after a **Number of pages** of the visit, 2 by
default) and **Ask again after (days)** if the shopper did not answer (30 by default; 0: on every visit). The browser
prompt is only shown with consent, at most once per visit. If consent is withdrawn, the subscription is cancelled.

*The service worker.* Browsers deliver push notifications to a service worker, a script of the store's own domain.
The plugin serves it at the root of the site, **`/irios-sw.js`** (with the site's subfolder if WordPress is installed
in one, e.g. `/shop/irios-sw.js`; the exact URL is shown in the Push Notifications section of the settings):

- WordPress answers it itself: there is no file to upload, no rewrite rule and no need to save the permalinks. It
  answers `404` until push notifications are enabled with their ad unit and auth.
- **Send IRIOS Media that exact path, with the leading slash** (`/irios-sw.js`), to be set as the service worker of
  your store in IRIOS Media. A name without the slash (`irios-sw.js`) is resolved against each page's URL
  (`/product/x/irios-sw.js`…) and fails.
- **It must not be redirected.** Browsers refuse a service worker served through a redirect. Check that nothing
  redirects it (a forced trailing slash, a language prefix, a security or maintenance plugin, a CDN rule) and that
  it is not answered by the web server as a static file:
  - **Apache** with the standard WordPress `.htaccess`: nothing to do.
  - **nginx**: a rule for static `.js` files (e.g. `location ~* \.js$ { try_files $uri =404; }`) answers `404`
    without reaching WordPress. Add before it:
    `location = /irios-sw.js { try_files $uri /index.php?$args; }`.
  - There must be no real `irios-sw.js` file at the site root.
- It is sent with `no-cache` headers: page caches and CDNs must not cache it (exclude `/irios-sw.js`).
- A site has one service worker per scope: if another plugin (a PWA, another push service) already registers one for
  the whole site, tell IRIOS Media before enabling push.

To check it, open `https://<store>/irios-sw.js`: the browser must show JavaScript (it starts with
`self.IRIOS_DEFAULTS`), the address must not change, and the browser's network panel must show `200` with
`Content-Type: application/javascript`, with no `301`/`302` before it. From a terminal:
`curl -sI https://<store>/irios-sw.js` must answer `200` with no `Location` header.

<a id="page-type"></a>**Page type**: every ad request tells IRIOS Media the type of page it is shown on (`pageType`), which campaigns can
target. The plugin sets it from the WooCommerce page: home (`HOMEPAGE`), product search (`SEARCH`), live search
(`SEARCH_BAR`), category (`CATEGORY`, with its path), product (`PRODUCT_DETAIL`, with its deepest category), shop,
tag, brand and attribute listings (`MERCHANDISING`), cart and checkout (`CHECKOUT`) and order received
(`CONFIRMATION`). On other pages (WordPress pages, blog), a manual placement can set it with `page_type`, e.g.
`[irios_display tag="promo" page_type="PROMOTIONS"]` (`HOMEPAGE`, `MERCHANDISING`, `CHECKOUT`, `CONFIRMATION`,
`AI_ASSISTANT` or `PROMOTIONS`); without it, no ads are requested there. Nor on a category or product page without
a category, which IRIOS Media requires. In IRIOS Media, the store must have those page types associated.

<a id="sync-service"></a>
## Access for the IRIOS Media sync service

IRIOS Media's synchronization service reads the store's catalog, its paid orders and its customers from the store,
over HTTPS. Orders and customers are read through the plugin's API, which gives the shopper's identifiers already
hashed in the store (SHA-256 of the normalized email and of the phone in E.164, the same rules as the storefront):
names, emails, phones, addresses and IP addresses never leave WooCommerce.

| Endpoint (`/wp-json/irios/v1/...`) | Returns |
|---|---|
| `orders?status=&modified_after=&created_after=&created_before=&page=&per_page=` | Paid orders (`processing`, `completed`): amounts, lines and whether there was consent. Only with consent: the hashed identifiers, the IRIOS browser ID and the billing country, state and postcode. Orders with a product of a [sensitive category](#sensitive-data) are left out (a page can bring fewer orders than `per_page`; `X-WP-Total` and `X-WP-TotalPages` stay WooCommerce's). |
| `orders/<id>` | One order (`404` if it has a sensitive product) |
| `customers?page=&per_page=`, `customers/<id>` | Registered customers with an order placed with consent: customer ID, hashed identifiers and billing country, state and postcode |

To give access:

1. **WooCommerce → Settings → Advanced → REST API → Add key**.
2. **Description**: e.g. "IRIOS Media". **User**: an Administrator or Shop manager (the key acts as that user, and
   the plugin's API requires one who can read the shop's orders). **Permissions**: **Read**.
3. **Generate API key** and send IRIOS Media the **Consumer key** and **Consumer secret** together with the store URL,
   through the secure channel agreed with IRIOS Media. WooCommerce shows the secret only once.

The same key is used for WooCommerce's own API, which the sync service uses only for the catalog. The key is read
only: it is not used to create webhooks, which the store creates by hand ([Webhooks](#webhooks)). To revoke access,
delete the key.

The REST API must be reachable: a security plugin or firewall that blocks `/wp-json/` for visitors who are not logged
in, or by IP, must allow `/wp-json/irios/v1/` and `/wp-json/wc/v3/` with the key. To check the key from a terminal:
`curl -s -u <consumer key>:<consumer secret> "https://<store>/wp-json/irios/v1/orders?per_page=1"` must answer a JSON
list (`[]` if there is no paid order yet), not an error.

<a id="webhooks"></a>
### Webhooks

Webhooks tell the sync service when a product changes, so it reaches IRIOS Media within minutes instead of at the
next periodic read. IRIOS Media sends you, through a secure channel (not plain email), a **delivery URL** and a
**secret** (a random string WooCommerce uses to sign each delivery). For each topic below:

1. **WooCommerce → Settings → Advanced → Webhooks → Add webhook**.
2. **Name**: e.g. `IRIOS Media – Product updated`. **Status**: **Active**. **Topic**: one from the table.
   **Delivery URL** and **Secret**: the ones given by IRIOS Media (the same for every webhook). **API version**:
   **WP REST API Integration v3**.
3. **Save webhook**. WooCommerce sends a test delivery, which must succeed.

| Topic |
|---|
| Product created |
| Product updated |
| Product deleted |

Do not create order or customer webhooks pointing to the sync service, and delete any that exist: WooCommerce sends
the full order or customer in them (names, emails, addresses), which the plugin's API exists to avoid. Orders and
customers are read through the plugin's API instead. The sync service rejects them (HTTP 410) without reading them, so
WooCommerce shows a delivery error when one is saved and disables it after a few failed deliveries.

**If WooCommerce disables a webhook.** After 5 failed deliveries in a row, WooCommerce sets the webhook to
**Disabled**. Changes still reach IRIOS Media through the periodic read, with more delay. Once the cause is fixed (for
example a firewall blocking the delivery, or the delivery URL changed), edit the webhook, set **Status** back to
**Active** and save. The delivery log of each webhook is in **WooCommerce → Status → Logs** (sources starting with
`webhooks-delivery`).

## Languages

The plugin is written in English, as every IRIOS Media integration, and follows the store language. Everything is
translated into Spanish: the settings page, the admin notices and what shoppers see (the "About this ad" dialog and
the default "Sponsored" label). Any other language shows the English texts. The sponsored label can also be set in
the settings.

## Theme compatibility

Events, identity and the sponsored product card rely on standard WooCommerce hooks and templates, so they work with any
theme. Where ads are placed depends on the theme: the results grid, the live search dropdown and the automatic banner
positions. If an ad cannot be placed, it is simply not shown and the store keeps working.

A theme that names those parts differently can be adapted from the site itself, without waiting for a version of the
plugin. In the theme's `functions.php` or a small plugin:

```php
add_filter( 'irios_woocommerce_selectors', function ( array $selectors ) {
    $selectors['grid'] = '.my-results';   // where the results are
    $selectors['sidebar'] = '.my-aside';  // where the "Category: sidebar" placement goes
    return $selectors;
} );
```

The names are `grid`, `title`, `listingControls`, `productTabs`, `related`, `sidebar`, `notResults` and
`liveDropdown`; what is not given keeps the tested default (`src/Selectors.php`).

<!-- compat:start -->
Tested on 2026-10-21. ✅ works · ❌ fails · — not applicable, e.g. the theme has no live search.

| Theme | Sponsored in search | Label + “About this ad” | Card like the results | Add to cart on the ad | Live search | Category banner | Product page banner |
|---|---|---|---|---|---|---|---|
| `astra` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `blocksy` | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `generatepress` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `kadence` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `neve` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `oceanwp` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `ridestyle` | ✅ | ✅ | ✅ | — | ✅ | ✅ | ✅ |
| `storefront` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `twentytwentyfive` | ✅ | ✅ | ✅ | ✅ | — | ✅ | ✅ |
| `storefront` + FiboSearch plugin | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ |
| `storefront` + YITH WooCommerce Ajax Search plugin | ✅ | ✅ | ✅ | ✅ | ❌ | ✅ | ✅ |
<!-- compat:end -->

- A child theme of a supported theme is supported too.
- Live search: besides the theme's own live search, ads are placed in the dropdown of the **FiboSearch** plugin. The
  **YITH WooCommerce Ajax Search** plugin is not supported.
- With any other theme, the settings page shows an "unsupported theme" warning: contact IRIOS Media support.
- **Page builders** (Elementor, WPBakery, Divi…): the manual placement works in any of their text or shortcode
  elements. If the builder replaces the WooCommerce category or product templates, the automatic positions and the
  Sponsored Products grid may not be found: use manual placements, or adapt the selectors above.

<a id="caching"></a>
## Caching, optimization and CDN

The pages can be cached: what the plugin prints on them does not depend on the visitor. What does is fetched by the
browser from the plugin's own routes, which are sent as not cacheable. In the caching plugin, the server cache
(Varnish, nginx) and the CDN:

- Do not cache `/wp-json/irios/v1/` (the visitor's identifiers and cart events, and the cards of the sponsored
  products with their current price and stock) nor `/irios-sw.js`.
- Do not "delay JavaScript until user interaction" for `irios-woocommerce.js` nor for the scripts loaded from the
  IRIOS Media domain (`irios-tracker.js`, `irios-loader.js`): ads would wait for the first click or scroll, and events
  of visitors who do not interact would be lost. If ads or events stop after turning on combining or minifying
  JavaScript, exclude those scripts too.
- With a Content-Security-Policy, allow the IRIOS Media domain for scripts, connections, images and media.
- After changing the settings or updating the plugin, purge the page cache and the CDN.

<a id="verification"></a>
## Verification

In a private window (no previous consent, push or storage), with `?irios_debug=1` added to the URL (see
[Troubleshooting](#troubleshooting)) and the browser's console and network panel open:

1. **Before consent**: on the home page, no `irios-tracker.js` loaded, no `irs1P` cookie and no `[irios] events`
   lines. Ads, if any, are contextual.
2. **Consent**: accept the marketing cookies in the banner. Without reloading, `irios-tracker.js` is loaded from the
   IRIOS Media domain and `[irios] events` shows a `page-view`. Navigate a category, a product, add to cart, open the
   cart and the checkout: each one logs its event. Withdraw consent from the banner: events stop.
3. **Sponsored Products**: search a term that IRIOS Media has campaigns for, with the store's product search. The
   console shows one `[irios-woo] ad unit …` line per ad unit; the ads appear at their positions with the "Sponsored"
   label and the info button. Type the term in the live search: at most one ad, at the top.
4. **Display**: open a page with a manual placement, a product category and a product page; each placement logs a
   line, also when there are no ads (then nothing is shown). Check desktop and mobile placements with a real device
   or a narrow window.
5. **Sensitive Data**: open a product of a sensitive category: no `page-view` nor `product-view` is logged, and its
   ads are requested without identifiers.
6. **Push Notifications**: check the service worker path as in [Push Notifications](#push-notifications). With
   consent, reach the moment set (the order received page, or the number of pages): the browser asks for permission.
   Accept it and ask IRIOS Media to send a test notification.
7. **Sync service**: ask IRIOS Media to confirm it reads the orders and customers with the key.

To see the push prompt again while testing, either use a new private window, or reset the notification permission
of the site in the browser (the padlock next to the address) and clear the plugin's keys: `irios_push_asked` in
localStorage (when the prompt was last shown; it is not shown again for **Ask again after (days)** if it was not
answered) and `irios_push_visit` and `irios_push_pages` in sessionStorage (asked in this visit, pages seen). In the
console:

```js
localStorage.removeItem( 'irios_push_asked' );
sessionStorage.removeItem( 'irios_push_visit' );
sessionStorage.removeItem( 'irios_push_pages' );
```

Once the permission is blocked in the browser, the plugin never asks again in that browser.

<a id="troubleshooting"></a>
## Troubleshooting

With `?irios_debug=1` in the URL (or `localStorage.irios_debug = '1'` in the browser console), the console shows what
is sent (`[irios] events`, `[irios] ad request`, `[irios] ad response`), one line per ad request with its timing, and
a warning when an ad cannot be placed on the theme:

```
[irios-woo] ad unit 1001 · search "running shoes" · Irios answered in 411 ms with 1 ad · rendered in 95 ms · ad visible 967 ms after the page started loading
[irios-woo] ad unit 1002 · banner · Irios answered in 350 ms with no ads
[irios-woo] live search: no dropdown with product links found next to the search field, no ads shown there (theme: …)
```

- **Nothing at all**: check the admin notices (WooCommerce inactive, plugin not configured, no consent banner) and
  that the main switch is on. The page source must include `iriosWooConfig`; if not, a page cache is serving an old
  copy: purge it.
- **No events**: nothing is sent and only contextual ads are shown until the cookie banner is accepted for the
  marketing category: accept it to test everything. With the WP Consent API, the admin notice says if the API or a
  banner that uses it is missing. Pages of [sensitive categories](#sensitive-data) send no events by design.
- The browser's mobile emulation is seen as a mobile device, so desktop-only campaigns are not served.
- **No ads, with "answered … with no ads"**: the plugin works; there is no campaign for that ad unit, search, page
  type or device. Check with IRIOS Media.
- **No ads on a WordPress page**: a manual placement there needs `page_type` ([Page type](#page-type)).
- **Sponsored Products do not show although an ad is returned**: WordPress may not be returning the product cards,
  for example because another plugin breaks the request. The plugin then renders the ad with the catalog data from
  IRIOS Media and says so in the console. To find out which plugin breaks it without access to the server logs, open
  `https://<store>/wp-json/irios/v1/cards?layout=data&ids=<product id>&irios_debug=1` (logged in as an administrator
  or shop manager): the response lists the failing fields and why (e.g. `product 6172 · priceHtml: Error: …
  (file.php:123)`).
- **Search with one result goes to the product page**: turn on **Single-result search**.
- **No push prompt**: no consent, the browser blocked notifications, the prompt was already shown in this visit or
  within **Ask again after (days)** (see [Verification](#verification)), or the service worker path does not answer
  JavaScript without a redirect. Safari on iPhone and iPad only allows push for sites added to the home screen.
- **Ads or events stop after enabling a caching or optimization plugin**: see [Caching](#caching).

## License

[GPL-2.0-or-later](LICENSE).

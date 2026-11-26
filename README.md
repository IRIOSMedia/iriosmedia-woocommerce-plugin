# IRIOS Media for WooCommerce

This WordPress plugin connects a WooCommerce store with **IRIOS Media**, a retail media network. It does these things:

- **Events and identity.** The plugin tells IRIOS Media what shoppers do in the store. It sends these events:
  `page-view`, `category-view`, `search`, `product-view` (including the selected variation), `checkout-step`,
  `add-to-cart`, `remove-from-cart` and `cart-view`.
  Each browser is identified by the IRIOS Media cookie, `irs1P`. This cookie is also stored in the order, together with
  the shopper's consent answer. For logged-in customers, the plugin also sends their customer id and their email and
  phone, hashed with SHA-256.
  The plugin does not send purchases itself. Instead, IRIOS Media's synchronization service reads them through the
  plugin's own API, and only when the shopper gave consent (see
  [Access for the IRIOS Media sync service](#sync-service)).
- **Sponsored Products** (optional). Product ads in the search results and in the theme's live search. They are shown
  with the store's own product card and an ad label ("Sponsored").
- **Display** (optional). Image and video ads. You can place them with a shortcode, or let the plugin put them at
  automatic positions. On the home page these are: at the start and at the end of its content. On category pages: the
  sidebar, above the grid, inside it, and after the products. On product pages: before the description and before the
  related products. Each ad can be set per device.
- **Push Notifications** (optional). Notifications from brands, for shoppers who subscribe in their browser. The
  browser asks the shopper after a purchase or after a number of pages, and only with consent.
- **Ad transparency** (EU Digital Services Act, art. 26). Next to every ad label there is an info button. It opens
  "About this ad", which explains why the ad is shown, on whose behalf it is shown, and who paid for it.

**Privacy.** The plugin needs the visitor's consent to marketing cookies. Without it, the plugin shows only
contextual ads, which are based on the search or the page, not on the visitor. It sends no identifiers and no events,
and sets no IRIOS Media cookies. With consent, it also sends events and identifiers and shows personalised ads.

**Sensitive data.** What a shopper looks at or buys can reveal their health (GDPR, art. 9). Ads cannot be based on
profiles built with that kind of data (DSA, art. 26.3). For this reason, the store can mark product categories as
sensitive, and can optionally treat every search as sensitive. Then, even with consent, nothing about them reaches
IRIOS Media tied to the shopper:

- No events are sent from those pages or products.
- Their ads are requested without identifiers, as without consent. The ads are still shown.
- Cart events leave those products out.
- Orders that include them are not given to IRIOS Media.

See [Sensitive Data](#sensitive-data) for the details.

**Requirements:** WordPress 6.4+, PHP 8.1+ and WooCommerce 8.5+. The store must be served over HTTPS, because push
notifications and the sync service need it.

## Setup checklist

This is the full path from installing the plugin to going live, in order. Each step links to the section that
explains it. The optional steps (5 to 7) are only for the features you are going to use.

Before you start, ask IRIOS Media for these details:

- The **IRIOS Media domain** of your store's network.
- The **Store ID**.
- An **ad unit ID and its auth** for each place where ads will appear: each Sponsored Products position, each Display
  placement, and the push ad unit. An *ad unit* is what IRIOS Media creates for each of those places, and its *auth*
  is the key that goes with it. Each Display ad unit also has a format: image or video.
- For the sync service, a **webhook URL** and **secret**. IRIOS Media gives you these through a secure
  channel ([Webhooks](#webhooks)).

Then follow these steps:

1. **Install** the plugin and activate it ([Installation](#installation)). WooCommerce must be active.
2. **Connect the cookie banner.** Choose the consent mode and check that your banner gives the `marketing` category
   ([Consent and the cookie banner](#consent)).
3. **General settings.** In **WooCommerce → Settings → Integration → IRIOS Media**, enter the IRIOS Media domain and
   the Store ID, and save. From this moment, events are sent for visitors who give consent ([General](#general)).
4. **Sensitive Data.** Mark the health-related product categories, and decide whether every search is sensitive
   ([Sensitive Data](#sensitive-data)). Do this before going live: until you do it, nothing is filtered.
5. **Sponsored Products** (optional). Enable it and add the search results positions with their ad units
   ([Sponsored Products](#sponsored-products)).
6. **Display** (optional). Enable it, add the placements, and put the `[irios_display]` shortcodes where you need them
   ([Display](#display)).
7. **Push Notifications** (optional). Enable it and add the push ad unit. Check that the service worker answers at
   `/irios-sw.js`, and send that path to IRIOS Media ([Push Notifications](#push-notifications)).
8. **Sync service access.** Create a read-only WooCommerce REST API key and send it to IRIOS Media
   ([Access for the IRIOS Media sync service](#sync-service)). Then paste the webhook URL and secret that IRIOS Media
   gave you in the settings: the plugin creates the product webhooks ([Webhooks](#webhooks)).
9. **Caching and optimization.** Exclude what must not be cached or delayed ([Caching, optimization and
   CDN](#caching)).
10. **Verify** everything in a browser ([Verification](#verification)).

**What to send IRIOS Media** when you finish:

- The store URL.
- The theme in use, and whether it is a child theme.
- The service worker path (step 7).
- The REST API key, and confirmation that the webhooks are active (step 8).
- If Display uses `page_type` on WordPress pages, the page types you used.

## Installation

1. Download `irios-woocommerce.zip` from the [latest release](../../releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip, and click **Install Now** and then
   **Activate**. WooCommerce must be active.
3. Go to **WooCommerce → Settings → Integration → IRIOS Media** and fill in the settings (described below) with the
   details provided by IRIOS Media. Until the domain and the Store ID are set, a notice on the admin pages says that the
   plugin is not configured. Meanwhile, the store stays exactly as it was without the plugin.
4. Make sure the store has a cookie banner that is compatible with the selected consent mode.

### Updates

The plugin never updates itself. You update it by hand, by uploading the new version.

When a new version is published, administrators who can update plugins see a notice on the Dashboard, the Plugins page
and the WooCommerce settings pages. The notice links to the release notes and to the zip. You can dismiss it for each
version. What changes in each version is in the [changelog](CHANGELOG.md).

To update:

1. Upload the new zip the same way as when you installed it.
2. WordPress detects that the plugin is already installed and offers **Replace current with uploaded**. Choose it.
   Your settings are kept.
3. Purge the page cache and the CDN, if the store has them ([Caching](#caching)).

To know about new versions, the store checks the public list of releases on GitHub. It does this at most every 12
hours, and only when one of those admin pages is opened. No store data is sent. To turn the check off, add this to
your theme or to a custom plugin:

```php
add_filter( 'irios_woocommerce_check_updates', '__return_false' );
```

#### Updating from "Irios for WooCommerce"

You only need this if your store used the first version of the plugin. It was called "Irios for WooCommerce" and lived
in the `irios-for-woocommerce` folder. WordPress sees the current plugin as a different plugin, so installing it does
not replace the old one. Do it like this:

1. Go to **Plugins → Deactivate** "Irios for WooCommerce". Do not delete it yet, so you can reactivate it if needed.
2. Upload and activate `irios-woocommerce.zip` as described above. Both plugins share their settings, so yours are
   already there. Still, check them in **WooCommerce → Settings → Integration → IRIOS Media**, because this version adds
   settings the old one did not have: page types are sent on their own, and there are Display placements and push
   notifications.
3. Once everything works, **Delete** the old plugin.

While both plugins are active, only the old one runs, and the new one shows a notice asking you to deactivate the old
one.

Deleting the old plugin is safe. Its uninstall deletes the shared settings, but the new plugin saves them and writes
them back, so you do not lose the configuration.

Shoppers get a new IRIOS Media identifier. The old plugin used its own `_irios_uid` cookie. This one uses the
tracker's `irs1P` cookie, the same as every other IRIOS Media integration.

### Uninstall

1. Go to **Plugins → Deactivate**. Once deactivated, the plugin stops loading and sending anything. The service worker
   path also stops answering, so no notification can be subscribed.
2. Click **Delete**. This removes the plugin's settings and the list of consent withdrawals not yet read by IRIOS Media.
   The browser identifier and the consent answer stored in past orders are kept, as part of the order history. So is
   each customer's last consent answer, so a customer who withdrew consent doesn't count as consenting if the plugin
   is installed again.
3. Revoke the REST API key you created for IRIOS Media, in **WooCommerce → Settings → Advanced → REST API**.

If the dashboard does not respond, delete the `wp-content/plugins/irios-woocommerce` folder over FTP/SSH instead.

## Settings

All the settings are in **WooCommerce → Settings → Integration → IRIOS Media**. This section explains each one.

A few things apply to all of them:

- Nothing configured here is secret, except the webhook secret: all the rest reaches the browser.
- The settings page is in English, as in every IRIOS Media integration.
- Every ad unit ID and auth comes from IRIOS Media. An ad unit is created there for each position, placement or push,
  and its auth is the key that goes with it.
- If a value is not valid, saving shows an error at the top of the page and keeps the previous value.

<a id="general"></a>
### General

These are the settings every store needs.

| Setting | Description |
|---|---|
| Enable IRIOS Media | The main switch. It is on by default, but nothing is loaded until the domain and the Store ID are set. |
| IRIOS Media domain | The domain of your store's network, given by IRIOS Media. Write it without `https://` and without a path (e.g. `example.iriosmedia.online`). The IRIOS Media scripts are loaded from it. |
| Store ID | The numeric id of your store in IRIOS Media, given by IRIOS Media. |
| Webhook URL and Webhook secret | Under **IRIOS Media sync service**. Given by IRIOS Media. With them, the plugin creates the WooCommerce webhooks that tell the sync service at once when a product changes or runs out of stock. Leave the URL empty to remove them. See [Webhooks](#webhooks). |
| Consent | How the plugin knows whether the visitor accepted marketing cookies. The options are **WP Consent API** (recommended, with a compatible banner), **Asesor de Cookies RGPD** (reads that banner's `cdp_cookie_consent` cookie) and **Always** (when the store handles consent another way). See below. |

<a id="consent"></a>
### Consent and the cookie banner

The plugin needs the visitor's consent to the **marketing** category before it sends events or identifiers. It reads
that consent from your cookie banner, so the two must be connected.

The plugin notices when the visitor accepts or withdraws consent, without reloading the page. When the visitor
accepts, it starts sending events. When the visitor withdraws, it stops, and cancels the push subscription of that
browser.

Withdrawing consent also asks IRIOS Media to erase what it holds about that shopper. Before removing the IRIOS cookie
of the browser, the plugin tells the store about the withdrawal. The store notes it down for that browser and, if the
shopper is logged in, for their customer account too, with the same hashed identifiers the sync service sends. No
email or phone is stored in clear. IRIOS Media's sync service then reads these withdrawals from the store and erases
the data. A shopper who withdraws twice without accepting in between is only noted once. Nothing is noted for someone
who never accepted.

The store only keeps these notes for 90 days. They are a mailbox for the sync service, which reads them every few
minutes, so after that they have no use: once a day the plugin deletes the older ones by itself (with WordPress's own
scheduled tasks, WP-Cron). Deleting them doesn't change the "noted only once" rule above.

For a registered customer, what counts is their **last** answer. It becomes "yes" when they browse the store logged in
with consent, "no" when they withdraw it, and at checkout it becomes whatever they answered for that order. Only
customers whose last answer is "yes" are given to IRIOS Media, and they are given as soon as it is "yes": a customer
who signs up and accepts is sent even if they never buy anything. Customers whose last answer is "no" are never sent.
Each order keeps its own answer, given at checkout.

Choose the **Consent** mode that matches your banner:

- **WP Consent API** (recommended). Use it if your cookie banner supports the WP Consent API.
  1. Install the free **WP Consent API** plugin.
  2. Use a cookie banner that supports it (Complianz, CookieYes, Cookiebot…). If the banner has a switch for the
     integration with the WP Consent API, turn it on.
  3. Make sure the banner asks for the **marketing** category. The banner may call it "Marketing" or "Advertising".

  Without the API, or without a banner that uses it, there is no consent, and an admin notice tells you so.
- **Asesor de Cookies RGPD**. Use it if your store has that banner (webartesanal.com). Only `"marketing": true` in its
  `cdp_cookie_consent` cookie counts as consent. If the banner is not active, an admin notice tells you so.
- **Always**. Every visitor counts as having consented. Use it only when the store obtains consent another way, for
  example with a banner that blocks the plugin's script (`irios-woocommerce-js`) until the visitor gives marketing
  consent. In that case no ads are shown at all before consent, not even contextual ones.

<a id="sensitive-data"></a>
### Sensitive Data

Use these settings if your store sells products that can reveal something sensitive about a shopper, such as their health.
They make sure that nothing about those products reaches IRIOS Media tied to the shopper, even with consent. Until you
mark a category or make searches sensitive, nothing changes.

| Setting | Description |
|---|---|
| Sensitive categories | The product categories whose pages and products are sensitive (health…). Each one covers all its subcategories, including the ones created later, so mark the parent category only. A product is sensitive if any of its categories is sensitive. A variation is sensitive if its product is. Empty by default. |
| Searches | **Treat all searches as sensitive** (off by default). Turn it on if needed: a search is free text, and it can reveal a health condition from any page. |

**How to mark the categories.** The setting takes one line: how many categories are marked and which ones (for
example "2 categories marked: Health, Beauty > Skin"), or "No sensitive categories". Click **Show categories** to see
all your product categories as a tree, in the same order as in Products → Categories, and tick the ones that are
sensitive. Then **Save changes**.

- Each category with subcategories has a small arrow to unfold or fold it. Branches start folded, except the ones
  that hold a marked category, so you see your marks straight away.
- With many categories, type part of a name in the filter box: only the matching categories (and the branches that
  lead to them) stay on screen. Capitals and accents don't matter.
- When you tick a category, its subcategories show up ticked, greyed out and marked "included": they are covered by
  the parent, also any you create later, so there is nothing to tick there. Untick the parent and they go back to how
  they were.
- To stop treating any category as sensitive, untick them all and save.

Once something is sensitive, this is what happens, also for visitors who gave consent:

- **Events.**
  - No `page-view`, `category-view` or `product-view` is sent from the page of a sensitive category or product.
  - When searches are sensitive, no `search` events are sent. They are also not sent for a search filtered by a
    sensitive category.
  - `add-to-cart`, `remove-from-cart` and `cart-view` leave out the sensitive products. If no product is left, the
    event is not sent. These events don't depend on the page they happen on: adding a product that isn't sensitive is
    sent even from a sensitive page.
  - `checkout-step` carries no products, so it is sent.
- **Ads.** Ads are requested and tracked without identifiers, exactly as without consent (contextual), in these places:
  on a sensitive page, and in the search results and the live search when searches are sensitive. The ads are still
  shown.
- **Purchases.** The plugin's API does not give out orders that include a sensitive product, so they never reach IRIOS
  Media. Customers and the catalog are not affected. If you mark a category later, the past orders that include it
  are also hidden from then on.
- **When in doubt.** If the plugin cannot know whether something is sensitive (for example, a product that no longer
  exists, or categories that cannot be read), it treats it as sensitive.

<a id="sponsored-products"></a>
### Sponsored Products

Sponsored Products are product ads shown among the search results. You only need this section if you use them. The
fields appear once you enable the feature.

| Setting | Description |
|---|---|
| Enable | Shows ads in the search results and in the live search. |
| Search results positions | One row per ad, with its **Position**, **Ad unit ID** and **Auth** (see below). You need at least one row. Without it, saving shows an error and no ads are shown. |
| Sponsored label | The text shown on the sponsored cards. It is "Sponsored" by default, translated to the store language. Do not leave it empty. |
| Single-result search | Keeps the results page when a search has a single result, instead of redirecting to the product, so that ads can be shown. |

**Search results positions.** Each row is a place in the results grid, from 1 to 50, and each position can be used
only once. Position 1 is the top left. The positions follow reading order: left to right, row by row. For example,
with 4 columns, position 5 is the first one of the second row.

One ad unit can take several positions. For example, ad unit 1001 can take positions 1 and 2, and ad unit 1002
position 6. This is how the ads are filled:

- All ad units go into a single auction. Each one asks for as many ads as positions it has. Its winning ads take its
  positions from lowest to highest. If it wins fewer ads than positions, its last positions keep the organic (normal)
  results.
- A product that already won in another ad unit is not repeated. The organic result for the same product is hidden.
- Positions count the visible results. If the page has fewer results than the position, the ad goes at the end. In a
  search with no results, the sponsored products are shown above the "no products found" notice.
- The live search shows at most one ad, at the top of the dropdown. It is the ad of the ad unit with the lowest
  position.
- Ads are shown in **product** searches only (`/?s=…&post_type=product`). This is what the WooCommerce search form and
  block, and most shop themes, use. A search form that searches posts and pages does not get ads.

<a id="display"></a>
### Display (Image and Video)

Display ads are image and video banners. You only need this section if you use them. The table of placements appears
once you enable the feature.

Each row of the table is one placement, with these fields:

| Field | Description |
|---|---|
| **Tag** | The name of the placement, used in the shortcode. Lowercase letters, digits, `-` and `_`. Each tag must be unique. When you choose a position and the tag is empty, it is filled in for you with the name of the position's ad unit (see below), with `-mobile` at the end if the device is Mobile. You can change it. |
| **Format** | Image or video, the same as the ad unit in IRIOS Media. |
| **Device** | All, desktop (from 768 px wide), or mobile. The same spot can have one row per device, with ad units of different sizes. |
| **Ad unit ID** and **Auth** | Given by IRIOS Media. |
| **Position** | Where the banner goes. See the table below. |

#### Positions

The positions are the same in every IRIOS Media integration (WooCommerce, Magento, Salesforce), and so are their names.
The ad units you create in IRIOS Media are named after the position they are for, so anyone can tell which one goes
where. A position shown on phones too usually has two ad units, because a phone needs a smaller banner: the desktop one
has the position's name, and the mobile one the same name followed by `-mobile`. The tag of each row is usually that
same name, so the table and IRIOS Media read alike. The settings screen shows each position's name in brackets, for
example *Category: top (plp-top)*.

| Position in the settings | Ad unit in IRIOS Media | Where the banner goes |
|---|---|---|
| **Category: top** | `plp-top` (`plp-top-mobile`) | Right above the product grid, after the result count and the sorting. |
| **Category: in grid** | `plp-in-grid` (`plp-in-grid-mobile`) | Inside the product grid, taking its full width, after the product set in **Grid: after product** (6 by default). |
| **Category: bottom** | `plp-bottom` (`plp-bottom-mobile`) | Below the product grid and its sorting and pagination controls. |
| **Category: sidebar** | `plp-sidebar` | At the end of the shop sidebar, below its widgets (the filters). Desktop only: on phones the sidebar is a filter panel or sits far below the products, so the row's device must be Desktop. |
| **Product: description** | `pdp-main` (`pdp-main-mobile`) | Product page, before the tabs (the description is the first one). |
| **Product: related** | `pdp-secondary` (`pdp-secondary-mobile`) | Product page, before the related products (or the upsells). |
| **Manual only** | `home-main` and `home-secondary` (`-mobile`) on the home page; any name you like elsewhere | Wherever you put `[irios_display tag="TAG"]`: a page, a page-builder element, a block, a widget or a category description. In the block editor, use a **Shortcode** block. `[irios_banner]`, the shortcode's name in 1.0, still works. |

Every banner is centred and spaced from the content around it.

The **Category** positions are used on product category pages, and the **Product** positions on product pages. Every
other page (the home page, the Shop page, landing pages, the blog) uses manual placements.

#### The home page

There is no automatic position for the home page. Every home page is different (a big photo, a slider, a few blocks
of offers…), so you choose where its banners go and put the shortcode there. The ad units are still named after their
spot, the same as in every IRIOS Media integration: `home-main` for the main banner, near the top, and
`home-secondary` for a second one, further down.

**Example: the main home banner, on desktop and on phones.** You want a banner right below the photo at the top of
your home page: a wide one on computers and a smaller one on phones. In IRIOS Media you create two ad units,
`home-main` and `home-main-mobile`. In the table you add two rows, one per device, both **Manual only**:

| Tag | Format | Device | Ad unit ID | Auth | Position |
|---|---|---|---|---|---|
| `home-main` | Image | Desktop | ID of `home-main` | its auth | Manual only |
| `home-main-mobile` | Image | Mobile | ID of `home-main-mobile` | its auth | Manual only |

Then edit the home page and, right below the photo, add a **Shortcode** block with both shortcodes next to each other:

```
[irios_display tag="home-main"][irios_display tag="home-main-mobile"]
```

Each visitor gets only the one for their device. The ads are requested as the home page, so IRIOS Media knows where
they are shown. The same works for any other spot of any page: two rows, one per device, and both shortcodes side by
side.

To turn all display ads off without editing your pages, uncheck the box. The shortcodes then show nothing.

<a id="push-notifications"></a>
### Push Notifications

Push notifications let brands send notifications to shoppers who subscribe in their browser. You only need this
section if you use them. The fields appear once you enable the feature.

| Setting | Description |
|---|---|
| **Ad unit ID** and **Ad unit auth** | The push ad unit, given by IRIOS Media. Both are required: without them, saving shows an error and shoppers are not asked. |
| **When to ask for permission** | After a purchase (on the order received page), or after a **Number of pages** of the visit (2 by default). |
| **Ask again after (days)** | When to ask again if the shopper did not answer. 30 by default. With 0, the shopper is asked on every visit. |

The browser prompt is only shown with consent, and at most once per visit. If the shopper withdraws consent, the
subscription is cancelled.

#### The service worker

Browsers deliver push notifications to a *service worker*: a script served from the store's own domain. The plugin
serves it at the root of the site, at **`/irios-sw.js`**. If WordPress is installed in a subfolder, the path includes
it, e.g. `/shop/irios-sw.js`. The exact URL is shown in the Push Notifications section of the settings.

What you need to know about it:

- WordPress answers it itself. There is no file to upload, no rewrite rule, and no need to save the permalinks. It
  answers `404` until push notifications are enabled with their ad unit and auth.
- **Send IRIOS Media that exact path, with the leading slash** (`/irios-sw.js`). IRIOS Media sets it as the service
  worker of your store. A name without the slash (`irios-sw.js`) fails, because the browser resolves it against each
  page's URL (`/product/x/irios-sw.js`…).
- **It must not be redirected.** Browsers refuse a service worker served through a redirect. Check that nothing
  redirects it, such as a forced trailing slash, a language prefix, a security or maintenance plugin, or a CDN rule.
  Also check that the web server does not answer it as a static file:
  - **Apache** with the standard WordPress `.htaccess`: nothing to do.
  - **nginx**: a rule for static `.js` files (e.g. `location ~* \.js$ { try_files $uri =404; }`) answers `404`
    without reaching WordPress. Add this before it:
    `location = /irios-sw.js { try_files $uri /index.php?$args; }`.
  - There must be no real `irios-sw.js` file at the site root.
- It is sent with `no-cache` headers. Page caches and CDNs must not cache it, so exclude `/irios-sw.js` from them.
- A site has one service worker per scope. If another plugin (a PWA, another push service) already registers one for
  the whole site, tell IRIOS Media before you enable push.

To check it:

1. Open `https://<store>/irios-sw.js`. The browser must show JavaScript that starts with `self.IRIOS_DEFAULTS`, and
   the address must not change.
2. In the browser's network panel, the request must show `200` with `Content-Type: application/javascript`, with no
   `301`/`302` before it.

Or, from a terminal: `curl -sI https://<store>/irios-sw.js` must answer `200` with no `Location` header.

<a id="page-type"></a>
### Page type

This is not a setting, but it matters if you use Display on WordPress pages or on the blog.

Every ad request tells IRIOS Media the type of page the ad is shown on (`pageType`). Campaigns can target page types.
The plugin sets the type from the WooCommerce page:

| Page | `pageType` |
|---|---|
| Home | `HOMEPAGE` |
| Product search | `SEARCH` |
| Live search | `SEARCH_BAR` |
| Category | `CATEGORY`, with its path |
| Product | `PRODUCT_DETAIL`, with its deepest category |
| Shop, tag, brand and attribute listings | `MERCHANDISING` |
| Cart and checkout | `CHECKOUT` |
| Order received | `CONFIRMATION` |

On other pages (WordPress pages, the blog), the plugin cannot know the type, so no ads are requested there by default.
To show ads there, give the type in the manual placement with `page_type`, for example
`[irios_display tag="promo" page_type="PROMOTIONS"]`. The allowed values are `HOMEPAGE`, `MERCHANDISING`,
`CHECKOUT`, `CONFIRMATION`, `AI_ASSISTANT` and `PROMOTIONS`.

No ads are requested either on a category or product page without a category, because IRIOS Media requires one.

In IRIOS Media, the store must have those page types associated.

<a id="sync-service"></a>
## Access for the IRIOS Media sync service

Every store needs this section. IRIOS Media's synchronization service reads the store's catalog, its paid orders, its
customers and the consent withdrawals from the store, over HTTPS. It needs a read-only API key to do it.

Your shoppers' personal data stays in the store. Orders and customers are read through the plugin's own API, which
gives the shopper's identifiers already hashed in the store: SHA-256 of the normalized email, and of the phone in E.164
format, with the same rules as the storefront. Names, emails, phones, addresses and IP addresses never leave
WooCommerce.

This is what the plugin's API gives:

| Endpoint (`/wp-json/irios/v1/...`) | Returns |
|---|---|
| `orders?status=&modified_after=&created_after=&created_before=&page=&per_page=` | Paid orders (`processing`, `completed`), with their amounts, their lines, and whether there was consent. Only when there was consent, it also gives the hashed identifiers, the IRIOS browser ID, and the billing country, state and postcode. Orders with a product of a [sensitive category](#sensitive-data) are left out. Because of that, a page can bring fewer orders than `per_page`, while `X-WP-Total` and `X-WP-TotalPages` keep WooCommerce's values. |
| `orders/<id>` | One order. It answers `404` if the order has a sensitive product. |
| `customers?updated_since=&page=&per_page=`, `customers/<id>` | Registered customers whose last consent answer is "yes" ([Consent](#consent)), whether they have ordered or not: their customer ID, their hashed identifiers, and their billing country, state and postcode. For customers with no answer of their own yet, the answer of their most recent paid order counts. `updated_since` (date in GMT) returns only the customers whose answer changed after it or, for those without an answer of their own, whose paid orders changed after it. So a customer who has just said "yes" shows up in the next read. |
| `revocations?revoked_after=&after_id=&page=&per_page=` | Consent withdrawals, oldest first, so IRIOS Media erases that data: `{id, revoked_at, uids}`, where `uids` has only the identifiers known for that withdrawal (`crm`, `email_sha256`, `phone_sha256`, `browser_id`). `revoked_after` (date in GMT) and `after_id` return only the later ones. Withdrawals older than 90 days are deleted. |

To give access:

1. Go to **WooCommerce → Settings → Advanced → REST API → Add key**.
2. Fill in the form:
   - **Description**: for example "IRIOS Media".
   - **User**: an Administrator or Shop manager. The key acts as that user, and the plugin's API requires a user who
     can read the shop's orders.
   - **Permissions**: **Read**.
3. Click **Generate API key**. WooCommerce shows the secret only once, so copy it now.
4. Send IRIOS Media the **Consumer key** and the **Consumer secret**, together with the store URL, through the secure
   channel agreed with IRIOS Media.

The sync service also uses the same key for WooCommerce's own API, but only to read the catalog. The key is read only,
so it is not used to create webhooks: the plugin creates them from its settings ([Webhooks](#webhooks)). To revoke
access, delete the key.

The REST API must be reachable. Some security plugins or firewalls block `/wp-json/` for visitors who are not logged
in, or by IP. If yours does, it must allow `/wp-json/irios/v1/` and `/wp-json/wc/v3/` with the key. Shoppers' browsers
also call `/wp-json/irios/v1/context` and, when they withdraw consent, `POST /wp-json/irios/v1/revocations`, so those
must stay open to visitors.

To check the key from a terminal, run
`curl -s -u <consumer key>:<consumer secret> "https://<store>/wp-json/irios/v1/orders?per_page=1"`. It must answer a
JSON list (`[]` if there is no paid order yet), not an error.

<a id="webhooks"></a>
### Webhooks

Webhooks tell the sync service when a product changes. Without them, changes reach IRIOS Media at the next periodic
read. With them, they arrive within minutes.

IRIOS Media sends you a **webhook URL** and a **secret** through a secure channel (not plain email). The secret is a
random string that WooCommerce uses to sign each delivery.

You do not create the webhooks by hand: the plugin does it.

1. Go to **WooCommerce → Settings → Integration → IRIOS Media**, section **IRIOS Media sync service**.
2. Paste the **Webhook URL** and the **Webhook secret**, and click **Save changes**.
3. Below the URL, the page shows `Webhooks active: product.created, product.updated, product.deleted.`

The plugin creates one webhook for each product topic (created, updated and deleted), named
`IRIOS Media sync service: <topic>`. You can see them in **WooCommerce → Settings → Advanced → Webhooks**, but do not
edit them there: the next save of the IRIOS Media settings puts them back as the settings say. Each save also updates
the URL and the secret, and turns back on any webhook that WooCommerce disabled. To remove them, empty the URL and
save. Uninstalling the plugin removes them too. The store's other webhooks are never touched.

If you created these webhooks by hand with an earlier version, delete them once the plugin has created its own:
otherwise each change is sent twice.

The **Product updated** webhook also covers stock changes: when an order (or anything else) changes a product's stock
or stock status, the plugin makes WooCommerce send it too, so a product that runs out stops being advertised within
minutes. No other webhook is needed for that.

**Do not create order or customer webhooks** pointing to the sync service, and delete any that exist. In those
webhooks, WooCommerce sends the full order or customer, with names, emails and addresses. The plugin's API exists to
avoid exactly that, so orders and customers are read through it instead. The sync service rejects order and customer
webhooks (HTTP 410) without reading them. WooCommerce then shows a delivery error when one is saved, and disables it
after a few failed deliveries.

**If WooCommerce disables a webhook.** After 5 failed deliveries in a row, WooCommerce sets the webhook to
**Disabled**, and the settings page says so below the URL. Changes still reach IRIOS Media through the periodic read,
but with more delay. To fix it:

1. Fix the cause, for example a firewall that blocks the delivery, or a URL that changed.
2. Go to **WooCommerce → Settings → Integration → IRIOS Media** and click **Save changes**: the plugin turns the
   webhooks back on.

The delivery log of each webhook is in **WooCommerce → Status → Logs**, in the sources that start with
`webhooks-delivery`.

## Languages

The admin is always in English, whatever language the store uses: the settings page, the notices at the top of the
admin and the plugin links. This is the same in every IRIOS Media integration, so the setup steps, the screenshots and
IRIOS support always talk about the same words.

What shoppers see follows the store language: the "About this ad" dialog and the default "Sponsored" label on the ads.
These are in English by default, and the plugin includes the Spanish translation, so a store in Spanish shows
"Patrocinado" and "Acerca de este anuncio" without doing anything. Any other language shows the English texts. If you
want a different label on the ads (for example "Publicidad"), write it in **Sponsored label** in the settings.

The ads are requested in the store language too: IRIOS Media uses it to choose ads meant for that language. You do
not set it anywhere. The plugin takes the language of the page being viewed (with WPML or Polylang, the version of the
store the shopper is on) as language and region, for example `es-ES`, or just `es` when the region is not a usable
one. If that page has no usable language, it takes the site language from **Settings > General**. Banners, videos and
Sponsored Products all use the same one. If there is no usable language at all, the store simply shows no ads (it
never breaks the page); push notifications work all the same.

### Multi-language stores (WPML and Polylang)

With WPML (and WooCommerce Multilingual) or Polylang (and Polylang for WooCommerce), each translation of a product is
a separate WooCommerce product with its own ID, linked to the product in the default language. In IRIOS Media it is
one product, with the ID of the default-language product and its content in every language of your IRIOS Media
catalog. The plugin takes care of this, with nothing to set up:

- **Events, purchases and stock changes** always carry the ID of the default-language product, also when the shopper
  is on a translated page. Otherwise IRIOS Media would not recognize the product.
- **Sponsored Products** come from IRIOS Media with that ID, and the plugin shows the translation in the language of
  the page.
- **The IRIOS Media sync service** reads each product with its translations through the plugin
  (`GET /wp-json/irios/v1/translations`, with the same API key as the rest of its access), and sends the content of
  each language of the catalog. A language the product is not translated into gets the default-language content.

The languages sent are the ones of your IRIOS Media catalog, chosen when it is created; other languages of the store
are left out. Translation plugins that translate the page on the fly without creating products (TranslatePress,
Weglot) are not supported: the store has no separate content for each language.

## Theme compatibility

Events, identity and the look of the sponsored product card use standard WooCommerce hooks and templates, so they work
with any theme. What depends on the theme is **where** the ads go: the plugin has to find certain parts of your pages
(the home page content, the product grid, the live search dropdown, the sidebar, the product tabs…). It finds them by their CSS class, and
out of the box it knows the classes of the themes in the table below. If it can't find a part, that ad simply isn't
shown: the store keeps working as usual.

If your theme names those parts differently, you have two ways to fix it, and you can combine them.

### Option 1: tell the plugin what your theme calls each part

This is done in code, without waiting for a new version of the plugin: a few lines in your theme's `functions.php` (or
in a small plugin of your own) using the `irios_woocommerce_selectors` filter. You only give the parts that are
different; the rest keep the default (`src/Selectors.php`).

```php
add_filter( 'irios_woocommerce_selectors', function ( array $selectors ) {
    $selectors['grid'] = '.my-results';   // where the search results are
    $selectors['sidebar'] = '.my-aside';  // where the "Category: sidebar" placement goes
    return $selectors;
} );
```

What each name is for:

- `grid` and `title`: Sponsored Products are inserted *inside* the search results, so the plugin needs to know where
  the results grid is and how the product names in it are marked.
- `liveDropdown`: the dropdown of your live search, where the sponsored row goes. `notResults`: links that sit in
  that area but are not results, such as a header mini-cart, so they are not mistaken for them.
- `listingControls`, `sidebar`, `productTabs` and `related`: only the automatic banner positions (*Category:
  bottom*, which goes after the sorting and pagination; *Category: sidebar*, *Product: description* and *Product:
  related*).

To find a selector, open the page in your browser, right-click the part and choose *Inspect*. Look for a class or an
id that only that part has, for example `.my-results`. You can check it in the browser console with
`document.querySelectorAll('.my-results')`: it should find exactly that part.

### Option 2: place the banners by hand

Display ads don't need the automatic positions. Set the placement's position to **Manual only** and put the
`[irios_display tag="…"]` shortcode wherever you want it ([Display](#display)). Then `listingControls`, `sidebar`,
`productTabs` and `related` are not needed.

This doesn't work for Sponsored Products: they have to go inside the search results and the live search, so for them
option 1 is the only one.

### Tested themes

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
  Sponsored Products grid may not be found: place the banners by hand (option 2 above) and give the plugin the
  selectors of the results (option 1).

<a id="caching"></a>
## Caching, optimization and CDN

Read this if your store uses a caching plugin, a server cache (Varnish, nginx), a CDN, or a plugin that optimizes
JavaScript. A wrong setting there can stop ads or events.

Your pages can be cached safely, because what the plugin prints on them does not depend on the visitor. What does
depend on the visitor is fetched by the browser from the plugin's own routes, and those are sent as not cacheable.
Still, check these points in the caching plugin, the server cache and the CDN:

- **Do not cache** `/wp-json/irios/v1/` nor `/irios-sw.js`. The first one gives the visitor's identifiers and cart
  events, and the cards of the sponsored products with their current price and stock.
- **Do not "delay JavaScript until user interaction"** for `irios-woocommerce.js`, nor for the scripts loaded from the
  IRIOS Media domain (`irios-tracker.js`, `irios-loader.js`). If you do, ads wait for the visitor's first click or
  scroll, and the events of visitors who do not interact are lost.
- If ads or events stop after you turn on combining or minifying JavaScript, **exclude those scripts** from it too.
- If the store sends a **Content-Security-Policy**, allow the IRIOS Media domain for scripts, connections, images and
  media.
- After you change the settings or update the plugin, **purge** the page cache and the CDN.

<a id="verification"></a>
## Verification

Use this checklist to confirm that everything works before going live.

First, prepare the browser:

- Open a private window, so there is no previous consent, push subscription or storage.
- Add `?irios_debug=1` to the URL. This makes the plugin write what it does in the console (see
  [Troubleshooting](#troubleshooting)).
- Open the browser's console and network panel.

Then check each part:

1. **Before consent.** On the home page, `irios-tracker.js` is not loaded, there is no `irs1P` cookie, and there are no
   `[irios] events` lines. Ads, if any, are contextual.
2. **Consent.** Accept the marketing cookies in the banner. Without reloading, `irios-tracker.js` is loaded from the
   IRIOS Media domain and `[irios] events` shows a `page-view`. Visit a category and a product, add to cart, and open
   the cart and the checkout: each one logs its event. Then withdraw consent from the banner: the events stop.
3. **Sponsored Products.** With the store's product search, search for a term that IRIOS Media has campaigns for. The
   console shows one `[irios-woo] ad unit …` line per ad unit. The ads appear at their positions, with the "Sponsored"
   label and the info button. Then type the term in the live search: you should see at most one ad, at the top.
4. **Display.** Open a page with a manual placement, a product category and a product page. Each placement logs a
   line, also when there are no ads (in that case nothing is shown). Check the desktop and mobile placements with a
   real device or a narrow window.
5. **Sensitive Data.** Open a product of a sensitive category. No `page-view` or `product-view` is logged, and its ads
   are requested without identifiers.
6. **Push Notifications.** Check the service worker path as described in
   [Push Notifications](#push-notifications). With consent, reach the moment you set (the order received page, or the
   number of pages): the browser asks for permission. Accept it, and ask IRIOS Media to send a test notification.
7. **Sync service.** Ask IRIOS Media to confirm that it can read the orders and customers with the key.

**To see the push prompt again while testing**, you have two options:

- **Option 1:** use a new private window.
- **Option 2:** reset the site's notification permission in the browser (the padlock next to the address), and clear
  the plugin's keys. These are `irios_push_asked` in localStorage, which records when the prompt was last shown (if
  the shopper did not answer, it is not shown again for **Ask again after (days)**), and `irios_push_visit` and
  `irios_push_pages` in sessionStorage, which record whether the prompt was shown in this visit and the pages seen. To
  clear them, run this in the console:

```js
localStorage.removeItem( 'irios_push_asked' );
sessionStorage.removeItem( 'irios_push_visit' );
sessionStorage.removeItem( 'irios_push_pages' );
```

Once the permission is blocked in the browser, the plugin never asks again in that browser.

<a id="troubleshooting"></a>
## Troubleshooting

Start by turning on debug mode. Add `?irios_debug=1` to the URL, or run `localStorage.irios_debug = '1'` in the
browser console. The console then shows:

- what is sent (`[irios] events`, `[irios] ad request`, `[irios] ad response`);
- one line per ad request, with its timing;
- a warning when an ad cannot be placed on the theme.

For example:

```
[irios-woo] ad unit 1001 · search "running shoes" · Irios answered in 411 ms with 1 ad · rendered in 95 ms · ad visible 967 ms after the page started loading
[irios-woo] ad unit 1002 · banner · Irios answered in 350 ms with no ads
[irios-woo] live search: no dropdown with product links found next to the search field, no ads shown there (theme: …)
```

Common problems:

- **Nothing works at all.** Check the admin notices (WooCommerce inactive, plugin not configured, no consent banner),
  and check that the main switch is on. The page source must include `iriosWooConfig`. If it does not, a page cache is
  serving an old copy: purge it.
- **No events.** Until the cookie banner is accepted for the marketing category, nothing is sent and only contextual
  ads are shown. Accept it to test everything. With the WP Consent API, the admin notice tells you if the API, or a
  banner that uses it, is missing. Pages of [sensitive categories](#sensitive-data) send no events by design.
- **Desktop campaigns do not show in mobile emulation.** The browser's mobile emulation is seen as a mobile device,
  so desktop-only campaigns are not served.
- **No ads, and the console says "answered … with no ads".** The plugin works: there is no campaign for that ad unit,
  search, page type or device. Check with IRIOS Media.
- **No ads on a WordPress page.** A manual placement there needs `page_type` ([Page type](#page-type)).
- **Sponsored Products do not show, although an ad is returned.** WordPress may not be returning the product cards,
  for example because another plugin breaks the request. In that case the plugin renders the ad with the catalog data
  from IRIOS Media, and says so in the console. To find out which plugin breaks it without access to the server logs,
  log in as an administrator or shop manager and open
  `https://<store>/wp-json/irios/v1/cards?layout=data&ids=<product id>&irios_debug=1`. The response lists the failing
  fields and why (e.g. `product 6172 · priceHtml: Error: … (file.php:123)`).
- **A search with one result goes to the product page.** Turn on **Single-result search**.
- **No push prompt.** Possible causes: there is no consent; the browser blocked notifications; the prompt was already
  shown in this visit or within **Ask again after (days)** (see [Verification](#verification)); or the service worker
  path does not answer JavaScript without a redirect. Also note that Safari on iPhone and iPad only allows push for
  sites added to the home screen.
- **Ads or events stop after you enable a caching or optimization plugin.** See [Caching](#caching).

## License

[GPL-2.0-or-later](LICENSE).

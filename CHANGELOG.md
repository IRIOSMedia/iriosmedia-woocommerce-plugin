# Changelog

Changes in each version of IRIOS Media for WooCommerce. Download any version from the [releases](../../releases).

## 1.2.0 - 2026-11-26

### Added

- **Multi-language stores with WPML or Polylang.** Each translation of a product is another WooCommerce product, but
  IRIOS Media has one product per item. Events, purchases and stock changes now carry the ID of the default-language
  product, also on translated pages, and Sponsored Products are shown with the translation of the page's language.
  The new `GET /wp-json/irios/v1/translations` endpoint gives the IRIOS Media sync service each product's translations,
  so the catalog gets the content of every language.
- **One catalog of positions with the IRIOS Media ad unit names.** The positions are the same in every IRIOS Media
  integration, and the ad units are named after them (`plp-top`, `pdp-main`…, with `-mobile` for the mobile ones).
  The settings show each position's ad unit name, in the shared order, and choosing a position with an empty Tag
  fills it in with that name (with `-mobile` for a Mobile row). The home page has no automatic position, because every
  home page is different: its banners, `home-main` and `home-secondary` (and their `-mobile` ones), are placed by hand
  with the shortcode, wherever the shop wants them.
- **Consent withdrawal erases the shopper's data in IRIOS Media.** When a shopper withdraws consent, the storefront
  tells the store before removing the IRIOS browser cookie. The store notes the withdrawal for that browser and, if the
  shopper is logged in, for their customer account, with hashed identifiers only, and their last answer becomes "no".
  The sync service reads them from the new `GET /wp-json/irios/v1/revocations` (same key as orders and customers,
  oldest first, with `revoked_after` and `after_id`) and asks IRIOS Media to erase them. A repeated withdrawal with no
  consent in between is noted once. The withdrawals are kept in a table of their own, removed on uninstall. Once a
  day (WP-Cron) the store deletes the withdrawals older than 90 days: the sync service reads them every few minutes,
  so they are only a mailbox. A repeated withdrawal is still noted once after that.

- **Sensitive Data** (General): the store can mark product categories as sensitive (health…), and optionally **treat
  all searches as sensitive** (off by default). A category covers all its subcategories, also those created later, and
  a product is sensitive if any of its categories is. Then, even with consent, nothing about them reaches IRIOS Media
  tied to the shopper (GDPR, art. 9; DSA, art. 26.3):
  - no page events of a sensitive category or product page (nor search events when searches are sensitive); cart
    events leave out the sensitive products, and are not sent if none is left, but are still sent from a sensitive
    page with the products that are not sensitive; `checkout-step` is never sensitive;
  - the ads of those pages (and of the search results and live search, when searches are sensitive) are requested
    without identifiers, as without consent; they are still shown;
  - orders with a sensitive product are left out of the plugin's API, so the purchase never reaches IRIOS Media.
    Customers and the catalog are not affected.

  Whatever cannot be checked (a product that no longer exists) counts as sensitive. Nothing changes until a category
  is marked or searches are set as sensitive.

- **Stock changes from orders now notify the Product updated webhook.** When a sale (or anything else that changes
  the stock directly) changes a product's stock or stock status, WooCommerce did not send the **Product updated**
  webhook, so a product that ran out kept being advertised until the next periodic read. Now it does, through the
  same webhook, with its usual signature, retries and logs: there is no new webhook to create. A variation counts as
  its parent product, and each product is sent at most once per order, however many of its variations were bought.

### Changed

- **Ads show sooner.** The page opens the connection to the IRIOS Media script domain in the `<head>` (preconnect), and
  when the consent is already known there, the shopper's context (`/wp-json/irios/v1/context`) is asked from the
  `<head>` as soon as the page starts loading, instead of when the plugin script runs at the end of the page; the
  script then uses that answer. The ads of a logged-in customer, which wait for it, start sooner. If that request
  fails (an expired nonce on a cached page…), the script asks again, as before.
- **The admin is always in English**, whatever the store language, as in every IRIOS Media integration: the settings
  page, the admin notices and the plugin links are no longer translated (until now they showed in Spanish in a store
  in Spanish). Only what shoppers see is translated: the "About this ad" dialog and the default "Sponsored" label, in
  English by default and in Spanish in a store in Spanish, exactly as before.
- **Sensitive categories are chosen from a folded tree**, the same control as in the other IRIOS Media integrations,
  instead of a multiple select. Closed, it is one line: how many categories are marked and which ("2 categories
  marked: Health, Beauty > Skin…") or "No sensitive categories", with **Show categories** to open it. Open, every
  product category is a checkbox, nested under its parent and in the store's order; each branch folds on its own
  (folded unless it holds a marked category), and a filter box shows only the categories whose name matches. Ticking
  a category shows its subcategories ticked, greyed out and "included", since it covers them all; unticking it gives
  them back their own state. What is stored does not change: the ids of the marked categories only, comma separated,
  and unknown ids are refused. Unticking them all and saving clears the setting. The small CSS and JS of the tree are
  loaded on the plugin's settings screen only.
- **Customers follow their last answer.** A registered customer is in the customers API as soon as their last consent
  answer is "yes", even if they have never ordered, and never while it is "no": it becomes "yes" when they browse
  logged in with consent, "no" when they withdraw it, and at checkout it becomes that order's answer. Customers with
  no answer of their own yet keep the answer of their most recent paid order, not of any order. Each order keeps its
  own answer. New `updated_since` on `GET /customers`: only the customers whose answer (or, without one, whose paid
  orders) changed after that date, so a customer who just said "yes" comes in the next incremental read.

### Fixed

- **The plugin's author is IRIOS Media** in the WordPress plugin list, and its Composer package is
  `iriosmedia/irios-woocommerce`.
- **Banners and videos carry the store language**, as IRIOS Media now requires for every banner, video and Sponsored
  Products ad (an ad request without it is rejected and nothing is shown). Every ad request takes the same language:
  that of the page being viewed (with WPML or Polylang, the store version the shopper is on), as language and region
  (`es-ES`); if the region cannot be used, the language alone (`es`); if the page has no usable locale, the site
  language from **Settings > General**. If there is still no usable language, the page shows no ads instead of
  failing. Push notifications do not need it.

## 1.1.3 - 2026-11-18

### Added

- Display: new automatic position **Category: top**, right above the product grid (after the result count and the
  sorting), the opposite of "Category: bottom". It is where most retailers place their banners in a listing.

### Changed

- The repository moved to the **IRIOSMedia** organization on GitHub: the update check and the `Update URI` header point
  to `IRIOSMedia/iriosmedia-woocommerce-plugin`. Earlier versions keep finding updates through GitHub's redirect from
  the old address.
- Display: the "Category: sidebar" placement goes at the end of the shop sidebar, below its widgets, instead of at
  the top: the filters stay where the shopper expects them.
- Display: "Category: sidebar" is desktop only. A sidebar placement for all devices or for mobile is not saved (on
  phones the sidebar is a filter panel or far below the products, so the ad would be requested and not seen); one
  saved for all devices before this version keeps working, on desktop only.

## 1.1.2 - 2026-11-17

### Fixed

- 1.1.1 was published without the plugin's own PHP classes, and installing it stopped the whole site with a fatal
  error (`Class "Irios\WooCommerce\Plugin" not found`), the admin included. If you installed 1.1.1, delete the
  `irios-woocommerce` folder from `wp-content/plugins` (by FTP or your host's file manager) and install this version.
  The packaging now refuses to build a zip without them.

## 1.1.1 - 2026-11-13

### Added

- `irios_woocommerce_selectors`: a theme that names its results grid, its sidebar or its live search dropdown
  differently can now be adapted from the site itself, without waiting for a version of the plugin.

### Fixed

- The live search no longer watches the whole page: it starts watching only after the shopper types in a search box,
  and only that box's own corner of the page. Until now, from the first keystroke on, every change anywhere made the
  plugin walk every link of the document, for the rest of the page's life.
- The settings are read and checked once per request instead of on every one of the dozen places that ask for them
  while a page is built.
- Events already too old to be duplicates are dropped, so the list does not grow for the whole visit.
- The visitor's own data is served only to pages of the store itself: `/context` and `/cards` now refuse a request
  made from another site. WordPress answers REST requests with credentials, so until now a third-party page could read
  the visitor's cart or make it lose the cart events waiting to be sent.
- The `/cards` diagnostics (`?irios_debug=1`) are only for whoever can configure the store: they used to give file
  names and line numbers to anyone.
- `/customers` no longer loads every order of the store into memory on each call: it asks the database for the page it
  needs. On a store with many orders the call could run out of memory or time. It also counts only customers with a
  paid order, as `/orders` already did.

## 1.1.0 - 2026-11-13

### Added

- Every ad request tells IRIOS Media the type of page it is shown on (`pageType`), required by IRIOS Media from now
  on: home, search, live search, category, product, shop listings, cart and checkout, and order received. Category
  and product pages send their category path; search pages, the search. Manual placements on other pages can set it
  with `[irios_display tag="…" page_type="PROMOTIONS"]`; without it, no ads are requested on those pages.
- "About this ad": reason for ads targeted by page type ("The section of the store you are in").
- Display: video ads. Each placement has a format (image or video, as its ad unit in IRIOS Media), and the IRIOS
  loader renders an image or a video player.
- Display: each placement has a device (all, desktop from 768 px wide, or mobile), so the same spot can show ad units
  of different sizes on desktop and mobile.
- Display: new automatic positions: category sidebar, inside the category grid (after the product set in "Grid: after
  product", 6 by default) and before the related products on the product page.
- API for the IRIOS Media sync service (`/wp-json/irios/v1/orders`, `/wp-json/irios/v1/customers`): orders and
  customers with the shopper's identifiers already hashed in the store, so emails, phones, addresses and IP
  addresses never leave WooCommerce. Authenticated with a read-only WooCommerce REST API key.
- Orders keep the consent answer (`_irios_consent`), so purchases are sent only with consent.
- Push notifications: the shoppers who consent can subscribe to brand notifications, asked after a purchase (default)
  or after a number of pages, and not again for a number of days if they did not answer. The plugin serves the service
  worker at `/irios-sw.js`.
- Updating from the earlier "Irios for WooCommerce" (folder `irios-for-woocommerce`): this version keeps its
  settings, stays off with a notice while the old one is active (deactivate it first), and deleting the old one from
  Plugins no longer wipes the shared settings.

### Changed

- "Banners" is now "Display (Image and Video)", with the `[irios_display]` shortcode. `[irios_banner]` and the saved
  banners keep working (as images for all devices).
- The settings follow the names shared by every IRIOS Media integration, written in English: General (with a new
  "Enable IRIOS Media" switch, on for existing installs), Sponsored Products, Display (Image and Video) and Push
  Notifications.
- Spanish translation complete: the settings, the admin notices and what shoppers see are all translated on stores
  in Spanish.
- The plugin is now called "IRIOS Media for WooCommerce".
- Live search: at most one Sponsored Product, at the top of the dropdown (the ad unit of the lowest search position),
  as in every IRIOS Media integration.
- Banners on product pages send the product's category, and search pages no longer send a category (IRIOS Media
  ignores it there).
- "About this ad": clearer reason for ads that use no targeting criteria ("No information about you was used. It is
  shown to everyone who visits this part of the store.").

### Fixed

- Sponsored Products on themes whose search results are not product cards (blog-style lists): the ad's image now takes
  the size the theme shows the other results' images at, instead of a taller box that broke the row.
- "About this ad": the advertiser name now shows the brand the ad is shown on behalf of, and "Who paid for this ad"
  shows the advertiser. Before, both lines showed the advertiser, even when IRIOS Media returns a different brand.

## 1.0.0 - 2026-10-22

First public version.

- Store events and identity: page, category, search, product (including the selected variation), cart and checkout
  events, with the visitor's consent.
- Sponsored Products in the search results and in the theme's live search, with configurable positions and several
  ad units in a single auction. In a search with no results, sponsored products are shown above the "no products
  found" notice.
- Banners through the `[irios_banner]` shortcode or at automatic positions on category and product pages.
- Contextual ads without consent: no identifiers, no cookies and no events.
- "About this ad" information next to every ad label (EU Digital Services Act).
- Notice in the WordPress dashboard when a new version is available.
- Warning in the settings when the active theme is not among the supported themes.
- English and Spanish.

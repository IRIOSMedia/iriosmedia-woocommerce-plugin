// Sensitive pages and searches (health…): with consent, still no events about them and their ads without identifiers,
// exactly as without consent. The ads are still shown.
import { afterEach, describe, expect, it } from "vitest";
import { card, grid, settle, storefront, winner } from "./harness.mjs";

let store;
const open = (options) => (store = storefront(options));
afterEach(() => store && store.close());

const UNIT = 9001;
const UIDS = [{ namespace: "CRM", uid: "7" }];
const ids = (events) => events.map((e) => e.id);
const product = { id: "10", price: 12.5, quantity: 1 };

/** Contextual, as without consent: no uids, no session. */
function expectContextual(unit) {
  expect(unit.consent).toEqual({ gdpr: false });
  expect(unit).not.toHaveProperty("uids");
  expect(unit).not.toHaveProperty("sessionId");
}

describe("a sensitive page", () => {
  it("sends none of its events, but still delivers the cart events the server left", async () => {
    const cart = { id: "add-to-cart", products: [{ id: "20", price: 30, quantity: 1 }] };
    const cases = [
      { type: "product", product, variable: false, pageType: "PRODUCT_DETAIL", category: ["Health"], sensitive: true },
      { type: "category", value: "Health > Allergy", pageType: "CATEGORY", category: ["Health", "Allergy"], sensitive: true },
    ];
    for (const page of cases) {
      const { log, close } = storefront({ page, context: { uids: UIDS, events: [cart] } });
      await settle();
      close();
      expect(ids(log.events)).toEqual(["add-to-cart"]);
    }
  });

  it("a product added to the cart from it is still sent: the server already left out the sensitive ones", async () => {
    // The server leaves only the products that are not sensitive in the event; a cart with only sensitive ones leaves none
    const responses = [{ uids: UIDS, events: [] }, { uids: UIDS, events: [{ id: "add-to-cart", products: [{ id: "20", price: 30, quantity: 1 }] }] }];
    const { log, window, document } = open({
      page: { type: "product", product, variable: false, pageType: "PRODUCT_DETAIL", category: ["Health"], sensitive: true },
      context: () => responses.shift() || { uids: UIDS, events: [] },
    });
    await settle();
    document.body.dispatchEvent(new window.CustomEvent("wc-blocks_added_to_cart"));
    await settle();

    expect(ids(log.events)).toEqual(["add-to-cart"]);
    expect(log.events[0].products).toEqual([{ id: "20", price: 30, quantity: 1 }]);
    expect(log.events[0].uids).toEqual(UIDS);
  });

  it("the variation chosen on a sensitive product is no event either", async () => {
    const { log, window } = open({
      page: { type: "product", product, variable: true, sensitive: true },
      before: (w) => {
        const handlers = {};
        w.jQuery = () => ({
          on: (names, selectorOrFn, fn) => names.split(" ").forEach((n) => (handlers[n] = fn || selectorOrFn)),
        });
        w.__found = (variation) => handlers.found_variation({}, variation);
      },
    });
    await settle();
    window.__found({ variation_id: 21, display_price: 59.9 });
    await settle();
    expect(log.events).toEqual([]);
  });

  it("its display ads are requested without identifiers", async () => {
    const { log } = open({
      page: { type: "category", value: "Health", pageType: "CATEGORY", category: ["Health"], sensitive: true },
      config: { bannersEnabled: "1" },
      body: '<div class="irios-banner" data-ad-unit="1" data-auth="a" data-type="banner" data-device="all"></div>',
      context: { uids: UIDS },
    });
    await settle();
    expect(log.auctions).toHaveLength(1);
    expect(log.auctions[0]).toMatchObject({ pageType: "CATEGORY", category: ["Health"] });
    expectContextual(log.auctions[0]);
  });

  it("the same page, not sensitive, sends its events and personalises its ads", async () => {
    const { log } = open({
      page: { type: "category", value: "Sports", pageType: "CATEGORY", category: ["Sports"] },
      config: { bannersEnabled: "1" },
      body: '<div class="irios-banner" data-ad-unit="1" data-auth="a" data-type="banner" data-device="all"></div>',
      context: { uids: UIDS },
    });
    await settle();
    expect(ids(log.events)).toEqual(["page-view", "category-view"]);
    expect(log.auctions[0].consent).toEqual({ gdpr: true });
  });

  it("a checkout step is sent: it carries no products", async () => {
    const { log } = open({ page: { type: "checkout", value: "checkout", pageType: "CHECKOUT" } });
    await settle();
    expect(ids(log.events)).toEqual(["page-view", "checkout-step"]);
  });
});

describe("with every search sensitive", () => {
  const SENSITIVE = { sensitiveSearches: true };

  function search(options = {}) {
    return open({
      body: grid(1, 2, 3),
      page: {
        type: "search",
        value: "eye drops",
        pageType: "SEARCH",
        sensitive: true,
        ads: { units: [{ adUnit: UNIT, auth: "abc", positions: [1] }], searchQuery: "eye drops", language: "en-GB", results: {}, empty: false },
      },
      config: SENSITIVE,
      winners: [winner(UNIT, 1, 7)],
      context: { uids: UIDS },
      ...options,
    });
  }

  it("the results page sends no events, and its ads are still shown, requested and tracked without identifiers", async () => {
    const { log, document } = search();
    await settle();

    expect(log.events).toEqual([]);
    expect(document.querySelector("ul.products > li.irios-sponsored")).not.toBeNull();
    expect(log.auctions[0]).toMatchObject({ pageType: "SEARCH", searchQuery: "eye drops" });
    expectContextual(log.auctions[0]);
    expect(log.tracked.length).toBeGreaterThan(0);
    log.tracked.forEach(expectContextual);
  });

  it("a search left pending by the live search is not sent on the next page", async () => {
    const { log } = open({ config: SENSITIVE, before: (w) => w.sessionStorage.setItem("irios_pending_search", "eye drops") });
    await settle();
    expect(ids(log.events)).toEqual(["page-view"]);
  });

  it("the live search ads are requested and tracked without identifiers, and clicking a result leaves no search", async () => {
    const { log, document, window } = open({
      body: '<form role="search"><input type="search" name="s"><div id="suggestions"></div></form>',
      config: { ...SENSITIVE, liveSearchAds: { units: [{ adUnit: UNIT, auth: "abc", positions: [1] }], language: "en-GB" } },
      winners: [winner(UNIT, 1, 7)],
      context: { uids: UIDS },
      // jsdom does not lay the page out: everything counts as visible
      before: (w) => Object.defineProperty(w.HTMLElement.prototype, "offsetParent", { get() { return this.ownerDocument.body; } }),
    });
    await settle();
    const input = document.querySelector('input[name="s"]');
    input.value = "eye drops";
    input.dispatchEvent(new window.Event("input", { bubbles: true }));
    await settle();
    document.querySelector("#suggestions").innerHTML = `<ul>${card(1)}${card(2)}</ul>`;
    await settle(120);

    expect(log.auctions.at(-1)).toMatchObject({ pageType: "SEARCH_BAR", searchQuery: "eye drops" });
    expectContextual(log.auctions.at(-1));
    log.tracked.forEach(expectContextual);

    document.querySelector("#suggestions a[href*='/product/p1/']").dispatchEvent(new window.MouseEvent("click", { bubbles: true, cancelable: true }));
    expect(window.sessionStorage.getItem("irios_pending_search")).toBeNull();
  });

  it("on a page that is not sensitive the events are sent and its ads personalised", async () => {
    const { log } = open({
      page: { type: "category", value: "Sports", pageType: "CATEGORY", category: ["Sports"] },
      config: { ...SENSITIVE, bannersEnabled: "1" },
      body: '<div class="irios-banner" data-ad-unit="1" data-auth="a" data-type="banner" data-device="all"></div>',
      context: { uids: UIDS },
    });
    await settle();
    expect(ids(log.events)).toEqual(["page-view", "category-view"]);
    expect(log.auctions[0].consent).toEqual({ gdpr: true });
  });
});

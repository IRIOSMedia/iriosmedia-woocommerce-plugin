// Language of every auction: irios-loader.js rejects a banner, video or product auction without a valid language
// (es or es-ES). Every auction takes the store's (iriosWooConfig.language, from the store locale); a locale that is
// not in that format is fixed and, if there is no language at all, the auction is skipped instead of failing.
import { afterEach, describe, expect, it } from "vitest";
import { grid, settle, storefront } from "./harness.mjs";

let store;
afterEach(() => store && store.close());

const BANNERS =
  '<div class="irios-banner" data-ad-unit="1" data-auth="a" data-device="all"></div>' +
  '<div class="irios-banner" data-ad-unit="2" data-auth="b" data-type="video" data-device="all"></div>';

function banners(language) {
  store = storefront({ page: { type: "page", pageType: "HOMEPAGE" }, config: { bannersEnabled: "1", language }, body: BANNERS });
  return store;
}

function search(language, adsLanguage) {
  store = storefront({
    body: grid(1, 2, 3),
    config: { language },
    page: {
      type: "search",
      value: "stick",
      ads: { units: [{ adUnit: 9001, auth: "abc", positions: [1] }], searchQuery: "stick", language: adsLanguage, results: {}, empty: false },
    },
  });
  return store;
}

describe("auction language", () => {
  it("banner and video auctions carry the store's language", async () => {
    const { log } = banners("es-ES");
    await settle();
    expect(log.auctions).toHaveLength(1);
    expect(log.auctions[0]).toMatchObject({ language: "es-ES", adUnits: [{ type: "banner" }, { type: "video" }] });
  });

  it.each([
    ["es_ES", "es-ES"],
    ["es-es", "es-ES"],
    ["es_419", "es"],
    ["ca", "ca"],
  ])("a store locale %s goes to the auction as %s", async (locale, expected) => {
    const { log } = banners(locale);
    await settle();
    expect(log.auctions[0].language).toBe(expected);
  });

  it.each([[null], [undefined], [""], ["??"], [42]])("without a usable language (%s) banners are skipped, nothing throws", async (language) => {
    const { log, window } = banners(language);
    const errors = [];
    window.console.warn = (...args) => errors.push(args);
    await settle();
    expect(log.auctions).toHaveLength(0);
    expect(log.rejected).toBeUndefined();
    expect(errors).toEqual([]);
  });

  it("Sponsored Products use the same language as the banners", async () => {
    const { log } = search("es-ES", undefined);
    await settle();
    expect(log.auctions[0]).toMatchObject({ language: "es-ES", adUnits: [{ type: "product" }] });
  });

  it("Sponsored Products with an unusable language request no ads instead of failing", async () => {
    const { log } = search(null, "!!");
    await settle();
    expect(log.auctions).toHaveLength(0);
    expect(log.rejected).toBeUndefined();
  });
});

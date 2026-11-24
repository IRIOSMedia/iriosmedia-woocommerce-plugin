// Withdrawing consent: the store is told (POST /irios/v1/revocations) so IRIOS Media erases the shopper's data. The
// store reads the browser from the irs1P cookie of that request, so the cookie must still be there when it is sent.
import { afterEach, describe, expect, it } from "vitest";
import { settle, storefront } from "./harness.mjs";

let store;
const open = (options) => (store = storefront(options));
afterEach(() => store && store.close());

const BROWSER = "irs1P=fc:0f8fad5b-d9cb-469f-a165-70867728950e";
const withBrowserId = (w) => (w.document.cookie = `${BROWSER}; path=/`);
const deny = (window) =>
  window.document.dispatchEvent(new window.CustomEvent("wp_listen_for_consent_change", { detail: { marketing: "deny" } }));

describe("withdrawing consent", () => {
  it("tells the store before removing the browser id, and only then removes it", async () => {
    const { log, document } = open({ before: withBrowserId });
    await settle();
    document.cookie = `cdp_cookie_consent=${encodeURIComponent('{"marketing":false}')}; path=/`;
    await settle(1_100); // the script checks the banner cookie every second

    expect(log.revocations).toHaveLength(1);
    const [revocation] = log.revocations;
    expect(revocation).toMatchObject({ method: "POST", keepalive: true, nonce: "nonce" });
    expect(revocation.body).toBeUndefined(); // nothing about the visitor in the body: the store reads the cookie
    expect(revocation.cookie).toContain(BROWSER);
    expect(document.cookie).not.toContain("irs1P");
  });

  it("with the WP Consent API, a deny after accepting is reported too", async () => {
    const { log, window } = open({
      consent: null,
      config: { consentMode: "wp_consent_api" },
      before: (w) => {
        w.wp_consent_type = "optin";
        w.wp_has_consent = () => true;
        withBrowserId(w);
      },
    });
    await settle();
    deny(window);
    await settle();
    deny(window); // the banner may fire it again: nothing left to erase
    await settle();

    expect(log.revocations).toHaveLength(1);
    expect(log.revocations[0].cookie).toContain(BROWSER);
    expect(window.document.cookie).not.toContain("irs1P");
  });

  it("a deny from someone who never accepted reports nothing", async () => {
    const { log, window } = open({
      consent: null,
      config: { consentMode: "wp_consent_api" },
      before: (w) => {
        w.wp_consent_type = "optin";
        w.wp_has_consent = () => false;
      },
    });
    await settle();
    deny(window);
    await settle();

    expect(log.revocations).toEqual([]);
  });

  it("with an expired nonce (cached page) it is sent again without it, so the browser is still reported", async () => {
    const { log, document } = open({ before: withBrowserId, revocation: (nonce) => (nonce ? 403 : 200) });
    await settle();
    document.cookie = `cdp_cookie_consent=${encodeURIComponent('{"marketing":false}')}; path=/`;
    await settle(1_100);

    expect(log.revocations.map((r) => r.nonce)).toEqual(["nonce", null]);
    expect(log.revocations[1].cookie).toContain(BROWSER);
    expect(document.cookie).not.toContain("irs1P");
  });

  it("if the store cannot be reached the browser id is removed all the same", async () => {
    const { log, document } = open({ before: withBrowserId, revocation: () => 500 });
    await settle();
    document.cookie = `cdp_cookie_consent=${encodeURIComponent('{"marketing":false}')}; path=/`;
    await settle(1_100);

    expect(log.revocations).toHaveLength(1);
    expect(document.cookie).not.toContain("irs1P");
  });
});

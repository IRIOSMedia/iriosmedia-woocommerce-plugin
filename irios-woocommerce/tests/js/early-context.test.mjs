// The context asked from the <head> (Assets::early_context): the plugin JS uses that answer instead of asking again.
import { afterEach, describe, expect, it } from "vitest";
import { settle, storefront } from "./harness.mjs";

let store;
const open = (options) => (store = storefront(options));
afterEach(() => store && store.close());

const CONTEXT = "/wp-json/irios/v1/context";

describe("the context asked from the <head>", () => {
  it("its answer is used: no second request, and the customer's uids reach the events", async () => {
    const uids = [{ namespace: "CRM", uid: "42" }];
    const { log } = open({
      before: (w) => (w.__iriosWooContext = Promise.resolve({ uids, events: [] })),
    });
    await settle();

    expect(log.requests.filter((r) => r.startsWith(CONTEXT))).toEqual([]);
    expect(log.events.length).toBeGreaterThan(0);
    expect(log.events[0].uids).toEqual(uids);
  });

  it("a failed request from the <head> (an expired nonce…) is asked again, as before", async () => {
    const uids = [{ namespace: "CRM", uid: "42" }];
    const { log } = open({
      context: { uids },
      before: (w) => (w.__iriosWooContext = Promise.resolve(null)),
    });
    await settle();

    expect(log.requests.filter((r) => r.startsWith(CONTEXT))).toHaveLength(1);
    expect(log.events[0].uids).toEqual(uids);
  });

  it("without consent on load it is never used: the context is asked when consent is given", async () => {
    const { log, window } = open({
      consent: false,
      context: { uids: [{ namespace: "CRM", uid: "from-the-store" }] },
      before: (w) => (w.__iriosWooContext = Promise.resolve({ uids: [{ namespace: "CRM", uid: "stale" }], events: [] })),
    });
    await settle();
    expect(log.requests).toEqual([]);

    window.document.cookie = `cdp_cookie_consent=${encodeURIComponent(JSON.stringify({ necessary: true, marketing: true }))}; path=/`;
    await settle(1300);

    expect(log.requests.filter((r) => r.startsWith(CONTEXT))).toHaveLength(1);
    expect(log.events[0].uids).toEqual([{ namespace: "CRM", uid: "from-the-store" }]);
  });
});

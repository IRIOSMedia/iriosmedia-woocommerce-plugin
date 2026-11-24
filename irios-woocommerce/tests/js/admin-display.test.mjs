// The Display placements table of the settings screen (assets/js/admin-display.js): choosing a position fills an
// empty Tag with the name of the position's IRIOS ad unit (-mobile for a Mobile row). The rows are the markup PHP draws
// (Integration::generate_irios_banners_html, tested in tests/IntegrationFormTest.php).
import { readFileSync } from "node:fs";
import { JSDOM } from "jsdom";
import { describe, expect, it } from "vitest";

const SCRIPT = readFileSync(new URL("../../assets/js/admin-display.js", import.meta.url), "utf8");
const FIELD = "woocommerce_irios_banners";

const POSITIONS = [
  ["", "Manual only", ""],
  ["category_before_products", "Category: top", "plp-top"],
  ["category_in_grid", "Category: in grid", "plp-in-grid"],
  ["category_after_products", "Category: bottom", "plp-bottom"],
  ["category_sidebar", "Category: sidebar", "plp-sidebar"],
  ["product_before_description", "Product: description", "pdp-main"],
  ["product_before_related", "Product: related", "pdp-secondary"],
];

function row(index, { tag = "", device = "all" } = {}) {
  const options = POSITIONS.map(
    ([value, label, name]) => `<option value="${value}"${name ? ` data-ad-unit-name="${name}"` : ""}>${name ? `${label} (${name})` : label}</option>`,
  ).join("");
  const devices = ["all", "desktop", "mobile"].map((d) => `<option value="${d}"${d === device ? " selected" : ""}>${d}</option>`).join("");
  return (
    `<tr><td><input type="text" name="${FIELD}[${index}][tag]" value="${tag}" placeholder="home"></td>` +
    `<td><select name="${FIELD}[${index}][device]">${devices}</select></td>` +
    `<td><select class="irios-position" name="${FIELD}[${index}][position]">${options}</select></td></tr>`
  );
}

function page(rows) {
  const dom = new JSDOM(`<!doctype html><form id="mainform"><table><tbody>${rows.join("")}</tbody></table></form>`, { runScripts: "outside-only" });
  dom.window.eval(SCRIPT);
  const { document } = dom.window;
  const tag = (i) => document.querySelector(`[name="${FIELD}[${i}][tag]"]`);
  const choose = (i, position) => {
    const select = document.querySelector(`[name="${FIELD}[${i}][position]"]`);
    select.value = position;
    select.dispatchEvent(new dom.window.Event("change", { bubbles: true }));
  };
  const setDevice = (i, device) => {
    const select = document.querySelector(`[name="${FIELD}[${i}][device]"]`);
    select.value = device;
    select.dispatchEvent(new dom.window.Event("change", { bubbles: true }));
  };
  const typeTag = (i, text) => {
    tag(i).value = text;
    tag(i).dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  };
  return { dom, document, tag, choose, setDevice, typeTag };
}

describe("Display placements: tag suggested from the position", () => {
  it("an empty tag gets the name of the position's IRIOS ad unit", () => {
    const { tag, choose } = page([row(0)]);
    choose(0, "category_before_products");
    expect(tag(0).value).toBe("plp-top");
  });

  it("every automatic position suggests its ad unit name", () => {
    const { tag, choose } = page([row(0)]);
    for (const [value, , name] of POSITIONS.filter(([, , n]) => n)) {
      choose(0, value);
      expect(tag(0).value).toBe(name);
    }
  });

  it("a mobile row gets the name of the mobile ad unit, with -mobile", () => {
    const { tag, choose } = page([row(0, { device: "desktop" }), row(1, { device: "mobile" }), row(2)]);
    choose(0, "category_before_products");
    choose(1, "category_before_products");
    choose(2, "category_before_products");
    expect(tag(0).value).toBe("plp-top");
    expect(tag(1).value).toBe("plp-top-mobile");
    expect(tag(2).value).toBe("plp-top");
  });

  it("changing the device of a row with a suggested tag changes the suggestion", () => {
    const { tag, choose, setDevice } = page([row(0)]);
    choose(0, "category_after_products");
    setDevice(0, "mobile");
    expect(tag(0).value).toBe("plp-bottom-mobile");
    setDevice(0, "desktop");
    expect(tag(0).value).toBe("plp-bottom");
  });

  it("changing the device without a position suggests nothing", () => {
    const { tag, setDevice } = page([row(0)]);
    setDevice(0, "mobile");
    expect(tag(0).value).toBe("");
  });

  it("a tag the shop manager typed is never changed", () => {
    const { tag, choose } = page([row(0, { tag: "summer-hero" }), row(1)]);
    choose(0, "category_before_products");
    expect(tag(0).value).toBe("summer-hero");

    const after = page([row(0)]);
    after.choose(0, "category_before_products");
    after.typeTag(0, "my-home");
    after.choose(0, "category_after_products");
    after.setDevice(0, "mobile");
    expect(after.tag(0).value).toBe("my-home");
    expect(tag(1).value).toBe("");
  });

  it("a suggested tag follows the position until it is edited; Manual only leaves it as it is", () => {
    const { tag, choose } = page([row(0)]);
    choose(0, "category_before_products");
    choose(0, "product_before_related");
    expect(tag(0).value).toBe("pdp-secondary");
    choose(0, "");
    expect(tag(0).value).toBe("pdp-secondary");
  });

  it("rows added later (Add placement) work too", () => {
    const { document, tag, choose } = page([row(0)]);
    document.querySelector("tbody").insertAdjacentHTML("beforeend", row("n123"));
    choose("n123", "category_sidebar");
    expect(tag("n123").value).toBe("plp-sidebar");
  });
});

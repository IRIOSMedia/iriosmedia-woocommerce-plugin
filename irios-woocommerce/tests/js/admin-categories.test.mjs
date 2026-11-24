// The "Sensitive categories" tree of the settings screen (assets/js/admin-categories.js): opening and closing it,
// folding each branch, the filter, and the "included" state of the descendants of a marked category. The page is the
// markup PHP draws (Integration::generate_irios_categories_html, tested in tests/IntegrationCategoriesTest.php).
import { readFileSync } from "node:fs";
import { JSDOM } from "jsdom";
import { describe, expect, it } from "vitest";

const SCRIPT = readFileSync(new URL("../../assets/js/admin-categories.js", import.meta.url), "utf8");
const FIELD = "woocommerce_irios_sensitive_categories";

// Health > (Eye drops, Allergy); Beauty > Skin > Face; Óptica
const TREE = [
  { id: 1, name: "Health", children: [{ id: 4, name: "Eye drops" }, { id: 5, name: "Allergy" }] },
  { id: 2, name: "Beauty", children: [{ id: 3, name: "Skin", children: [{ id: 6, name: "Face" }] }] },
  { id: 7, name: "Óptica" },
];

function holds(nodes, marked) {
  return (nodes || []).some((n) => marked.includes(n.id) || holds(n.children, marked));
}

/** Same markup as PHP, for the given marks. */
function list(nodes, marked, covered, id, open, parents = []) {
  let html = `<ul class="irios-cat-list" id="${id}"${open ? "" : " hidden"}>`;
  for (const node of nodes) {
    const own = marked.includes(node.id);
    const path = [...parents, node.name];
    const unfold = holds(node.children, marked);
    const branch = node.children
      ? `<button type="button" class="irios-cat-branch" aria-expanded="${unfold}" aria-controls="${FIELD}-children-${node.id}"><span class="screen-reader-text">Subcategories of ${node.name}</span></button>`
      : '<span class="irios-cat-leaf" aria-hidden="true"></span>';
    html +=
      `<li class="irios-cat-item${covered ? " is-included" : ""}" data-path="${path.join(" &gt; ")}"><span class="irios-cat-row">${branch}` +
      `<input type="checkbox" id="${FIELD}-${node.id}" name="${FIELD}[]" value="${node.id}" data-marked="${own ? 1 : 0}"${own || covered ? ' checked="checked"' : ""}${covered ? ' disabled="disabled"' : ""}>` +
      ` <label for="${FIELD}-${node.id}">${node.name}</label> <span class="irios-cat-included"${covered ? "" : " hidden"}>included</span></span>` +
      (node.children ? list(node.children, marked, covered || own, `${FIELD}-children-${node.id}`, unfold, path) : "") +
      "</li>";
  }
  return html + "</ul>";
}

async function page(marked = []) {
  const dom = new JSDOM(
    `<!doctype html><form id="mainform"><table><tr><th><span id="${FIELD}-label">Sensitive categories</span></th><td>
      <div class="irios-cat-tree" role="group" aria-labelledby="${FIELD}-label" data-none="No sensitive categories"
        data-one="%1$d category marked: %2$s" data-many="%1$d categories marked: %2$s"
        data-show="Show categories" data-hide="Hide categories" data-limit="3">
        <input type="hidden" name="${FIELD}[]" value="">
        <p class="irios-cat-head"><span class="irios-cat-summary" aria-live="polite">No sensitive categories</span>
          <button type="button" class="button-link irios-cat-toggle" aria-expanded="false" aria-controls="${FIELD}-panel">Show categories</button></p>
        <div class="irios-cat-panel" id="${FIELD}-panel" hidden>
          <p class="irios-cat-search"><label for="${FIELD}-filter">Filter categories</label><input type="search" class="irios-cat-filter" id="${FIELD}-filter"></p>
          <p class="irios-cat-nomatch" hidden>No category matches the filter.</p>
          ${list(TREE, marked, false, `${FIELD}-root`, true)}
        </div>
      </div></td></tr></table></form>`,
    { runScripts: "outside-only" },
  );
  const { document } = dom.window;
  if (document.readyState === "loading") {
    await new Promise((resolve) => document.addEventListener("DOMContentLoaded", resolve));
  }
  dom.window.eval(SCRIPT);
  const $ = (selector) => document.querySelector(selector);
  const box = (id) => document.getElementById(`${FIELD}-${id}`);
  const item = (id) => box(id).closest(".irios-cat-item");
  const branch = (id) => $(`[aria-controls="${FIELD}-children-${id}"]`);
  const children = (id) => document.getElementById(`${FIELD}-children-${id}`);
  const click = (el) => el.dispatchEvent(new dom.window.MouseEvent("click", { bubbles: true }));
  const check = (id) => {
    box(id).checked = !box(id).checked;
    box(id).dispatchEvent(new dom.window.Event("change", { bubbles: true }));
  };
  const type = (text) => {
    $(".irios-cat-filter").value = text;
    $(".irios-cat-filter").dispatchEvent(new dom.window.Event("input", { bubbles: true }));
  };
  const submitted = () => new dom.window.FormData($("#mainform")).getAll(`${FIELD}[]`);
  const visible = () =>
    [...document.querySelectorAll(".irios-cat-item")]
      // as seen with the tree open
      .filter((li) => !li.closest("[hidden]:not(.irios-cat-panel)"))
      .map((li) => li.querySelector("label").textContent);
  const included = (id) => item(id).classList.contains("is-included") && !item(id).querySelector(".irios-cat-included").hidden;
  return { window: dom.window, $, box, item, branch, children, click, check, type, submitted, visible, included };
}

describe("folding", () => {
  it("opens and closes the tree with its toggle, which says what it will do", async () => {
    const p = await page();
    const toggle = p.$(".irios-cat-toggle");
    expect(p.$(".irios-cat-panel").hidden).toBe(true);

    p.click(toggle);
    expect(p.$(".irios-cat-panel").hidden).toBe(false);
    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    expect(toggle.textContent).toBe("Hide categories");

    p.click(toggle);
    expect(p.$(".irios-cat-panel").hidden).toBe(true);
    expect(toggle.getAttribute("aria-expanded")).toBe("false");
    expect(toggle.textContent).toBe("Show categories");
  });

  it("unfolds and folds each branch on its own", async () => {
    const p = await page();
    p.click(p.$(".irios-cat-toggle"));
    expect(p.visible()).toEqual(["Health", "Beauty", "Óptica"]);

    p.click(p.branch(2));
    expect(p.branch(2).getAttribute("aria-expanded")).toBe("true");
    expect(p.visible()).toEqual(["Health", "Beauty", "Skin", "Óptica"]);

    p.click(p.branch(3));
    expect(p.visible()).toEqual(["Health", "Beauty", "Skin", "Face", "Óptica"]);

    p.click(p.branch(2));
    expect(p.branch(2).getAttribute("aria-expanded")).toBe("false");
    expect(p.children(2).hidden).toBe(true);
    expect(p.visible()).toEqual(["Health", "Beauty", "Óptica"]);
  });

  it("a branch button does not submit the form", async () => {
    const p = await page();
    const event = new p.window.MouseEvent("click", { bubbles: true, cancelable: true });
    p.branch(1).dispatchEvent(event);
    expect(event.defaultPrevented).toBe(true);
  });
});

describe("filter", () => {
  it("shows the matches and the branches that lead to them, and hides the rest", async () => {
    const p = await page();
    p.click(p.$(".irios-cat-toggle"));

    p.type("fac");
    expect(p.visible()).toEqual(["Beauty", "Skin", "Face"]);
    expect(p.branch(2).getAttribute("aria-expanded")).toBe("true");
    expect(p.branch(3).getAttribute("aria-expanded")).toBe("true");
    expect(p.$(".irios-cat-nomatch").hidden).toBe(true);
  });

  it("ignores case and accents", async () => {
    const p = await page();
    p.type("OPTICA");
    expect(p.visible()).toEqual(["Óptica"]);
  });

  it("says when nothing matches", async () => {
    const p = await page();
    p.type("zzz");
    expect(p.visible()).toEqual([]);
    expect(p.$(".irios-cat-nomatch").hidden).toBe(false);
  });

  it("cleared, shows everything again with the branches as they were", async () => {
    const p = await page();
    p.click(p.branch(1));
    p.type("face");
    expect(p.branch(1).getAttribute("aria-expanded")).toBe("false");

    p.type("");
    expect(p.visible()).toEqual(["Health", "Eye drops", "Allergy", "Beauty", "Óptica"]);
    expect(p.branch(1).getAttribute("aria-expanded")).toBe("true");
    expect(p.branch(2).getAttribute("aria-expanded")).toBe("false");
    expect(p.$(".irios-cat-nomatch").hidden).toBe(true);
  });

  it("typing in it does not tell WooCommerce that something changed, and Enter does not submit", async () => {
    const p = await page();
    let changed = 0;
    p.$("#mainform").addEventListener("change", () => changed++);
    p.$(".irios-cat-filter").dispatchEvent(new p.window.Event("change", { bubbles: true }));
    expect(changed).toBe(0);
    const enter = new p.window.KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true });
    p.$(".irios-cat-filter").dispatchEvent(enter);
    expect(enter.defaultPrevented).toBe(true);
  });

  it("checking a category still tells WooCommerce, so Save is enabled", async () => {
    const p = await page();
    let changed = 0;
    p.$("#mainform").addEventListener("change", () => changed++);
    p.check(7);
    expect(changed).toBe(1);
  });
});

describe("included", () => {
  it("checking a category includes its whole subtree, and only the mark is submitted", async () => {
    const p = await page();
    p.check(2);

    for (const id of [3, 6]) {
      expect(p.box(id).checked).toBe(true);
      expect(p.box(id).disabled).toBe(true);
      expect(p.included(id)).toBe(true);
    }
    expect(p.included(2)).toBe(false);
    expect(p.box(1).checked).toBe(false);
    expect(p.submitted()).toEqual(["", "2"]);
    expect(p.$(".irios-cat-summary").textContent).toBe("1 category marked: Beauty");
  });

  it("unchecking it gives the descendants back their own state", async () => {
    const p = await page([3]);
    expect(p.included(6)).toBe(true);

    p.check(2);
    expect(p.box(3).disabled).toBe(true);
    expect(p.included(3)).toBe(true);

    p.check(2);
    expect(p.box(3).checked).toBe(true);
    expect(p.box(3).disabled).toBe(false);
    expect(p.included(3)).toBe(false);
    expect(p.box(6).disabled).toBe(true);
    expect(p.included(6)).toBe(true); // still included by Skin
    expect(p.submitted()).toEqual(["", "3"]);
  });

  it("with nothing checked only the empty value is submitted, which clears the setting", async () => {
    const p = await page([7]);
    p.check(7);
    expect(p.submitted()).toEqual([""]);
    expect(p.$(".irios-cat-summary").textContent).toBe("No sensitive categories");
  });

  it("the summary names the first marks with their path, in the tree's order", async () => {
    const p = await page();
    p.check(7);
    p.check(5);
    expect(p.$(".irios-cat-summary").textContent).toBe("2 categories marked: Health > Allergy, Óptica");
    p.check(4);
    p.check(2);
    expect(p.$(".irios-cat-summary").textContent).toBe("4 categories marked: Health > Eye drops, Health > Allergy, Beauty…");
    p.check(1);
    expect(p.$(".irios-cat-summary").textContent).toBe("3 categories marked: Health, Beauty, Óptica"); // the marks below Health are included in it
    p.check(1);
    expect(p.$(".irios-cat-summary").textContent).toBe("4 categories marked: Health > Eye drops, Health > Allergy, Beauty…");
  });
});

/**
 * IRIOS Media for WooCommerce — the "Sensitive categories" tree of the settings screen.
 *
 * The page is drawn by PHP already in the state of what is saved (Integration::generate_irios_categories_html): the
 * tree closed, branches with a marked category unfolded, descendants of a marked category checked, disabled and
 * "included". This script only keeps that state while the shop manager works with it:
 *   - "Show categories" / "Hide categories" opens and closes the tree, and each branch folds on its own;
 *   - the filter box shows the categories whose name contains what is typed (and the branches that lead to them);
 *   - checking a category covers its whole subtree: its descendants are checked, disabled and "included", and they
 *     go back to their own state when it is unchecked. Only the explicit marks are submitted (a disabled box is not).
 *
 * Plain ES5 with no libraries and no build step: WordPress serves this file as it is, on that screen only.
 */
(function () {
  "use strict";

  /** Direct children of an element with the given class. */
  function childrenWith(el, className) {
    var found = [];
    for (var i = 0; i < el.children.length; i++) {
      if ((" " + el.children[i].className + " ").indexOf(" " + className + " ") !== -1) {
        found.push(el.children[i]);
      }
    }
    return found;
  }

  function parts(item) {
    var row = childrenWith(item, "irios-cat-row")[0];
    return {
      row: row,
      box: row.querySelector("input[type=checkbox]"),
      label: row.querySelector("label"),
      included: row.querySelector(".irios-cat-included"),
      branch: row.querySelector(".irios-cat-branch"),
      list: childrenWith(item, "irios-cat-list")[0] || null,
    };
  }

  function setBranch(button, open) {
    if (!button) {
      return;
    }
    button.setAttribute("aria-expanded", open ? "true" : "false");
    var list = document.getElementById(button.getAttribute("aria-controls"));
    if (list) {
      list.hidden = !open;
    }
  }

  /** Lower case and without accents, so "salud" finds "Salud" and "optica" finds "Óptica". */
  function normalize(text) {
    var lower = String(text).toLowerCase();
    return lower.normalize ? lower.normalize("NFD").replace(/[̀-ͯ]/g, "") : lower;
  }

  /** Draws each level from the explicit marks: below a marked category everything is included. */
  function applyMarks(list, covered) {
    var items = childrenWith(list, "irios-cat-item");
    for (var i = 0; i < items.length; i++) {
      var p = parts(items[i]);
      var own = p.box.getAttribute("data-marked") === "1";
      p.box.disabled = covered;
      p.box.checked = covered || own;
      p.included.hidden = !covered;
      if (covered) {
        items[i].classList.add("is-included");
      } else {
        items[i].classList.remove("is-included");
      }
      if (p.list) {
        applyMarks(p.list, covered || own);
      }
    }
  }

  /** Shows the categories that match (and their ancestors) and unfolds the branches that hold one. */
  function applyFilter(list, query) {
    var any = false;
    var items = childrenWith(list, "irios-cat-item");
    for (var i = 0; i < items.length; i++) {
      var p = parts(items[i]);
      var below = p.list ? applyFilter(p.list, query) : false;
      var visible = query === "" || below || normalize(p.label.textContent).indexOf(query) !== -1;
      items[i].hidden = !visible;
      if (query !== "" && p.branch) {
        setBranch(p.branch, below);
      }
      any = any || visible;
    }
    return any;
  }

  function format(template, count, names) {
    return template.replace("%1$d", String(count)).replace("%2$s", names);
  }

  function init(root) {
    if (root.getAttribute("data-ready") === "1") {
      return;
    }
    root.setAttribute("data-ready", "1");
    var toggle = root.querySelector(".irios-cat-toggle");
    var panel = document.getElementById(toggle.getAttribute("aria-controls"));
    var summary = root.querySelector(".irios-cat-summary");
    var filter = root.querySelector(".irios-cat-filter");
    var noMatch = root.querySelector(".irios-cat-nomatch");
    var top = panel.querySelector(".irios-cat-list");
    var limit = parseInt(root.getAttribute("data-limit"), 10) || 3;
    // Which branches were open before filtering, to leave them so when the filter is cleared
    var beforeFilter = null;

    function updateSummary() {
      var paths = [];
      // A mark below another one is included in it, and not submitted: it is not counted
      var marked = root.querySelectorAll('input[type=checkbox][data-marked="1"]:not(:disabled)');
      for (var i = 0; i < marked.length; i++) {
        var item = marked[i].closest(".irios-cat-item");
        paths.push(item.getAttribute("data-path"));
      }
      if (paths.length === 0) {
        summary.textContent = root.getAttribute("data-none");
        return;
      }
      var names = paths.slice(0, limit).join(", ") + (paths.length > limit ? "…" : "");
      summary.textContent = format(root.getAttribute(paths.length === 1 ? "data-one" : "data-many"), paths.length, names);
    }

    toggle.addEventListener("click", function (e) {
      e.preventDefault();
      var open = toggle.getAttribute("aria-expanded") !== "true";
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      toggle.textContent = root.getAttribute(open ? "data-hide" : "data-show");
      panel.hidden = !open;
    });

    panel.addEventListener("click", function (e) {
      var button = e.target.closest ? e.target.closest(".irios-cat-branch") : null;
      if (button && panel.contains(button)) {
        e.preventDefault();
        setBranch(button, button.getAttribute("aria-expanded") !== "true");
      }
    });

    panel.addEventListener("change", function (e) {
      var box = e.target;
      if (box === filter) {
        // Typing in the filter changes nothing that is saved: WooCommerce must not ask to save the changes
        e.stopPropagation();
        return;
      }
      if (box.type !== "checkbox" || box.disabled) {
        return;
      }
      box.setAttribute("data-marked", box.checked ? "1" : "0");
      if (top) {
        applyMarks(top, false);
      }
      updateSummary();
    });

    if (filter && top) {
      filter.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault(); // Enter would submit the settings form
        }
      });
      filter.addEventListener("input", function () {
        var query = normalize(filter.value.replace(/^\s+|\s+$/g, ""));
        var branches = top.querySelectorAll(".irios-cat-branch");
        var i;
        if (query !== "" && beforeFilter === null) {
          beforeFilter = [];
          for (i = 0; i < branches.length; i++) {
            beforeFilter.push(branches[i].getAttribute("aria-expanded") === "true");
          }
        }
        var any = applyFilter(top, query);
        if (query === "" && beforeFilter !== null) {
          for (i = 0; i < branches.length; i++) {
            setBranch(branches[i], beforeFilter[i]);
          }
          beforeFilter = null;
        }
        if (noMatch) {
          noMatch.hidden = any;
        }
      });
    }
  }

  function initAll() {
    var roots = document.querySelectorAll(".irios-cat-tree");
    for (var i = 0; i < roots.length; i++) {
      init(roots[i]);
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAll);
  } else {
    initAll();
  }
})();

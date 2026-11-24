/**
 * IRIOS Media for WooCommerce — the Display placements table of the settings screen.
 *
 * Each automatic position has the name of its IRIOS ad unit (plp-top, pdp-main…; Integration::position_select puts
 * it on the option as data-ad-unit-name). Choosing a position in a row whose Tag is empty fills the Tag with that
 * name, and with "-mobile" at the end when the row's device is Mobile: the tag is then the name of the ad unit in
 * IRIOS Media (plp-top for desktop or all devices, plp-top-mobile for phones), the same as in every integration.
 *
 * A tag the shop manager typed is never changed. One this script suggested follows the position and the device
 * chosen afterwards, as long as nobody has edited it since.
 *
 * Plain ES5 with no libraries and no build step: WordPress serves this file as it is, on that screen only. It listens
 * on the document, so the rows added with "Add placement" work too.
 */
(function () {
  "use strict";

  var SUGGESTED = "data-irios-suggested";

  function suggestTag(row) {
    var tag = row.querySelector('input[name$="[tag]"]');
    var position = row.querySelector("select.irios-position");
    var device = row.querySelector('select[name$="[device]"]');
    if (!tag || !position) {
      return;
    }
    var option = position.options[position.selectedIndex];
    var name = option ? option.getAttribute("data-ad-unit-name") || "" : "";
    if (!name) {
      return;
    }
    if (device && device.value === "mobile") {
      name += "-mobile";
    }
    var current = tag.value.trim();
    if (current !== "" && current !== tag.getAttribute(SUGGESTED)) {
      return; // typed by the shop manager
    }
    tag.value = name;
    tag.setAttribute(SUGGESTED, name);
  }

  document.addEventListener("change", function (event) {
    var select = event.target;
    if (!select.matches || !select.matches('select.irios-position, select[name$="[device]"]')) {
      return;
    }
    var row = select.closest("tr");
    if (row) {
      suggestTag(row);
    }
  });
})();

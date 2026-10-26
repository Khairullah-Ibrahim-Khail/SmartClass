/**
 * SmartClass - Stream tab: search and filter the feed in the browser (no reload).
 * Items carry data-kind (post | work) and data-search (lower case text).
 */
(function () {
  const box = document.getElementById("streamSearch");
  if (!box) return;
  const items = Array.prototype.slice.call(document.querySelectorAll("[data-kind][data-search]"));
  const chips = Array.prototype.slice.call(document.querySelectorAll("[data-sfilter]"));
  const count = document.getElementById("streamCount");
  const none = document.getElementById("streamNone");
  let mode = "all";

  function apply() {
    const q = box.value.trim().toLowerCase();
    let shown = 0;
    items.forEach(function (el) {
      const ok = (mode === "all" || el.dataset.kind === mode) && (q === "" || el.dataset.search.indexOf(q) !== -1);
      el.hidden = !ok;
      if (ok) shown++;
    });
    if (count) count.textContent = shown === items.length ? "" : shown + " of " + items.length;
    if (none) none.hidden = shown > 0;
  }
  box.addEventListener("input", apply);
  chips.forEach(function (c) {
    c.addEventListener("click", function () {
      mode = c.dataset.sfilter;
      chips.forEach(function (x) { x.classList.toggle("active", x === c); });
      apply();
    });
  });
})();

/**
 * SmartClass - People tab: search + filter the student list in the browser (no reload).
 * Rows carry data-search (name + email, lower case) and data-attention / data-missing / data-inactive flags.
 */
(function () {
  const box = document.getElementById("peopleSearch");
  if (!box) return;
  const rows = Array.prototype.slice.call(document.querySelectorAll(".person-row[data-search]"));
  const chips = Array.prototype.slice.call(document.querySelectorAll(".pf-chips .chip"));
  const count = document.getElementById("peopleCount");
  const none = document.getElementById("peopleNone");
  let mode = "all";

  function apply() {
    const q = box.value.trim().toLowerCase();
    let shown = 0;
    rows.forEach(function (r) {
      const ok = (q === "" || r.dataset.search.indexOf(q) !== -1) && (mode === "all" || r.dataset[mode] === "1");
      r.hidden = !ok;
      if (ok) shown++;
    });
    if (count) count.textContent = shown === rows.length ? rows.length + " students" : shown + " of " + rows.length;
    if (none) none.hidden = shown > 0;
  }
  box.addEventListener("input", apply);
  chips.forEach(function (c) {
    c.addEventListener("click", function () {
      mode = c.dataset.filter;
      chips.forEach(function (x) { x.classList.toggle("active", x === c); });
      apply();
    });
  });
  apply();
})();

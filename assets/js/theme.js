/**
 * SmartClass - light / dark theme button (#theme-toggle in the header)
 * The theme is applied before the first paint by a small script in includes/app_header.php; this file only
 * switches it. A click toggles light <-> dark and remembers the choice in this browser (localStorage); a
 * double click goes back to "auto" (follow the system setting). Without storage it still works for the page.
 */
(function () {
  const btn = document.getElementById("theme-toggle");
  if (!btn) return;
  const root = document.documentElement;
  const systemDark = function () { return window.matchMedia && matchMedia("(prefers-color-scheme: dark)").matches; };
  function save(v) { try { if (v) localStorage.setItem("sc-theme", v); else localStorage.removeItem("sc-theme"); } catch (e) {} }
  function apply(dark) { root.setAttribute("data-theme", dark ? "dark" : "light"); btn.setAttribute("aria-pressed", dark ? "true" : "false"); }
  apply(root.getAttribute("data-theme") === "dark");
  btn.addEventListener("click", function () {
    const dark = root.getAttribute("data-theme") !== "dark";
    apply(dark); save(dark ? "dark" : "light");
  });
  btn.addEventListener("dblclick", function () { save(null); apply(systemDark()); });
  // Follow the system when nothing was chosen
  if (window.matchMedia) matchMedia("(prefers-color-scheme: dark)").addEventListener("change", function (e) {
    let chosen = null; try { chosen = localStorage.getItem("sc-theme"); } catch (x) {}
    if (!chosen) apply(e.matches);
  });
})();

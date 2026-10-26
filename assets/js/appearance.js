/** SmartClass - Settings › Appearance: choose light / dark / auto; stored like the header button (localStorage "sc-theme"). */
(function () {
  const wrap = document.getElementById("themePicks");
  if (!wrap) return;
  const root = document.documentElement;
  const systemDark = function () { return window.matchMedia && matchMedia("(prefers-color-scheme: dark)").matches; };
  let chosen = "auto";
  try { chosen = localStorage.getItem("sc-theme") || "auto"; } catch (e) {}
  function mark() { wrap.querySelectorAll(".theme-pick").forEach(function (l) { const on = l.dataset.themeValue === chosen; l.classList.toggle("on", on); l.querySelector("input").checked = on; }); }
  wrap.addEventListener("change", function (e) {
    chosen = e.target.value;
    try { if (chosen === "auto") localStorage.removeItem("sc-theme"); else localStorage.setItem("sc-theme", chosen); } catch (x) {}
    root.setAttribute("data-theme", chosen === "auto" ? (systemDark() ? "dark" : "light") : chosen);
    mark();
  });
  mark();
})();

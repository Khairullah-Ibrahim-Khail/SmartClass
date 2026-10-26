/**
 * SmartClass - accessibility helpers, run on every page and after every in-page navigation (ajax-nav):
 *   - heading levels never skip (an <h3> straight under the <h1> gets aria-level 2) — screen readers read a proper outline
 *   - icon-only buttons / links get an accessible name (from their title, tooltip or icon)
 *   - form fields without a <label> get one from their placeholder / title / name
 *   - links that open a new tab get rel="noopener noreferrer"
 * Nothing is moved or restyled; only ARIA attributes are added where they are missing.
 */
(function () {
  const ICONS = { "fa-trash": "Delete", "fa-trash-alt": "Delete", "fa-pen": "Edit", "fa-edit": "Edit", "fa-pencil-alt": "Edit", "fa-times": "Close", "fa-xmark": "Close",
    "fa-plus": "Add", "fa-paper-plane": "Send", "fa-download": "Download", "fa-eye": "Show", "fa-eye-slash": "Hide", "fa-search": "Search", "fa-copy": "Copy",
    "fa-print": "Print", "fa-save": "Save", "fa-check": "Done", "fa-bell": "Notifications", "fa-bars": "Menu", "fa-cog": "Settings", "fa-gear": "Settings",
    "fa-arrow-left": "Back", "fa-arrow-right": "Next", "fa-chevron-left": "Previous", "fa-chevron-right": "Next", "fa-ellipsis-v": "More", "fa-ellipsis-h": "More",
    "fa-upload": "Upload", "fa-sync": "Refresh", "fa-redo": "Refresh", "fa-rotate-left": "Reset", "fa-lock": "Lock", "fa-link": "Link", "fa-share": "Share" };
  const visible = function (e) { return !!(e.offsetWidth || e.offsetHeight || e.getClientRects().length); };
  const text = function (e) { return (e.getAttribute("aria-label") || e.textContent || e.getAttribute("title") || "").replace(/\s+/g, " ").trim(); };

  function run() {
    const main = document.getElementById("main-content") || document.body;
    // 1. heading outline
    let prev = 1;
    main.querySelectorAll("h1, h2, h3, h4, h5, h6").forEach(function (h) {
      if (!visible(h)) return;
      const natural = +h.tagName[1];
      let level = natural;
      if (h.hasAttribute("aria-level")) level = +h.getAttribute("aria-level");
      if (level > prev + 1) { level = prev + 1; h.setAttribute("role", "heading"); h.setAttribute("aria-level", String(level)); }
      prev = level;
    });
    // 2. names for icon-only controls
    document.querySelectorAll("button, a[href], [role=button]").forEach(function (b) {
      if (text(b) || b.getAttribute("aria-labelledby")) return;
      let name = b.getAttribute("data-tooltip") || b.getAttribute("data-title") || "";
      if (!name) { const i = b.querySelector("i[class*='fa-']"); if (i) for (const c of i.classList) if (ICONS[c]) { name = ICONS[c]; break; } }
      if (name) b.setAttribute("aria-label", name);
    });
    // 3. labels for fields
    document.querySelectorAll("input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=checkbox]):not([type=radio]), select, textarea").forEach(function (f) {
      if (f.getAttribute("aria-label") || f.getAttribute("aria-labelledby") || f.title) return;
      if (f.id && document.querySelector('label[for="' + CSS.escape(f.id) + '"]')) return;
      if (f.closest("label")) return;
      const n = f.getAttribute("placeholder") || f.getAttribute("name") || "";
      if (n) f.setAttribute("aria-label", n.replace(/[_\[\]]+/g, " ").replace(/\s+/g, " ").trim());
    });
    // 4. new-tab links
    document.querySelectorAll('a[target="_blank"]').forEach(function (a) {
      const rel = (a.getAttribute("rel") || "").toLowerCase();
      if (rel.indexOf("noopener") === -1) a.setAttribute("rel", (rel + " noopener noreferrer").trim());
    });
  }
  let t = null;
  const later = function () { clearTimeout(t); t = setTimeout(run, 120); };
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", run); else run();
  window.addEventListener("sc:navigated", later);
  if (window.MutationObserver) new MutationObserver(later).observe(document.body, { childList: true, subtree: true });
})();

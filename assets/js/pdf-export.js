/**
 * SmartClass - "Export PDF" for every page (header button #pdf-export)
 *
 * Opens a dialog to customise the printed page: paper size, orientation, margins, a title, a header (logo, school, title, date),
 * page numbers + footer, colour or black & white, and WHICH SECTIONS of the page to include. It then prints the page area with
 * those settings; in the browser's print window choose "Save as PDF" (the PDF has real, selectable text — Pashto / Dari included).
 * The sidebar, menus, buttons and forms are never printed. Choices are remembered in this browser.
 *   window.scPdf.open()           the dialog
 *   window.scPdf.apply(options)   set up the print layout (used by the dialog and by tests)
 *   window.scPdf.clear()          back to the normal screen layout (runs after printing)
 */
(function () {
  if (window.scPdf) return;
  const PAPERS = { A4: "A4", A3: "A3", A5: "A5", Letter: "letter", Legal: "legal" };
  const MARGINS = { narrow: "8mm", normal: "14mm", wide: "22mm" };
  const DEFAULTS = { paper: "A4", orientation: "portrait", margin: "normal", title: "", header: true, footer: true, color: true, fit: true, sections: null };
  const KEY = "sc-pdf-options";
  let savedTheme = null;

  function load() { try { return Object.assign({}, DEFAULTS, JSON.parse(localStorage.getItem(KEY) || "{}"), { sections: null, title: "" }); } catch (e) { return Object.assign({}, DEFAULTS); } }
  function save(o) { try { localStorage.setItem(KEY, JSON.stringify({ paper: o.paper, orientation: o.orientation, margin: o.margin, header: o.header, footer: o.footer, color: o.color, fit: o.fit })); } catch (e) {} }
  function pageTitle() { return (document.title || "").replace(/\s+-\s+[^-]*$/, "").trim() || document.title; }
  // The parts of the page a person can include / leave out: the direct blocks of the page content (not the page's own
  // navigation or tool bars — those are never printed). Settings pages: the blocks of the page next to the settings menu.
  const UI_ONLY = ".page-toolbar, .tab-pills, .class-tabs, .settings-nav, .stream-filter, .people-filter, .report-filter, .page-head, .conv-new, template, script, style, form[hidden]";
  function container() {
    let c = document.querySelector("#main-content .dashboard-content") || document.getElementById("main-content");
    if (!c) return null;
    const shell = c.querySelector(":scope > .settings-shell > .settings-main");
    return shell || c;
  }
  function blocks() {
    const c = container();
    if (!c) return [];
    return Array.prototype.slice.call(c.children).filter(function (el) {
      return el.offsetHeight > 0 && !el.matches(UI_ONLY) && !el.id.match(/^pdf-/) && !el.matches(".external-dropdown-wrapper, .settings-shell");
    });
  }
  const NAMES = [[".class-banner", "Class banner"], [".class-overview", "Class numbers"], [".class-tabs", "Tabs"], [".stats-grid", "Summary numbers"], [".insight-tiles", "Summary numbers"],
                 [".ins-charts", "Charts"], [".attendance-layout", "Attendance sheet"], [".dash-hero", "Welcome"], [".dash-kpis", "Summary numbers"], [".table-wrap", "Table"], [".app-table", "Table"]];
  function label(el, i) {
    for (let k = 0; k < NAMES.length; k++) if (el.matches(NAMES[k][0])) return NAMES[k][1] + (NAMES[k][1] === "Table" ? "" : "");
    const h = el.querySelector("h1, h2, h3, h4, .lecture-title");
    const t = (h ? h.textContent : (el.getAttribute("aria-label") || "")).replace(/\s+/g, " ").trim();
    if (t) return t.slice(0, 60);
    if (el.querySelector("table")) return el.querySelector(".gradebook, .gb-table") ? "Gradebook table" : "Table";
    const own = (el.textContent || "").replace(/\s+/g, " ").trim();
    return own ? own.slice(0, 50) + (own.length > 50 ? "…" : "") : "Part " + (i + 1);
  }

  function clear() {
    document.body.classList.remove("pdf-printing", "pdf-bw", "pdf-fit");
    document.querySelectorAll(".pdf-skip").forEach(function (e) { e.classList.remove("pdf-skip"); });
    ["pdf-print-style", "pdf-header", "pdf-footer"].forEach(function (id) { const e = document.getElementById(id); if (e) e.remove(); });
    if (savedTheme !== null) { document.documentElement.setAttribute("data-theme", savedTheme); savedTheme = null; }
  }

  function apply(o) {
    clear();
    o = Object.assign({}, DEFAULTS, o || {});
    const paper = PAPERS[o.paper] || "A4";
    const margin = MARGINS[o.margin] || MARGINS.normal;
    const style = document.createElement("style");
    style.id = "pdf-print-style";
    style.textContent = "@page { size: " + paper + " " + (o.orientation === "landscape" ? "landscape" : "portrait") + "; margin: " + margin + ";"
      + (o.footer ? " @bottom-right { content: \"Page \" counter(page) \" of \" counter(pages); font: 9pt sans-serif; color: #667085; }" : "") + " }";
    document.head.appendChild(style);
    savedTheme = document.documentElement.getAttribute("data-theme") || "light";
    document.documentElement.setAttribute("data-theme", "light");   // always print on white
    document.body.classList.add("pdf-printing");
    if (!o.color) document.body.classList.add("pdf-bw");
    if (o.fit) document.body.classList.add("pdf-fit");

    const main = document.getElementById("main-content");
    const school = (document.querySelector(".logo-text") || {}).textContent || "";
    const logo = document.querySelector(".logo .brand-logo");
    const who = ((document.querySelector(".profile-item .header-text") || {}).getAttribute || function () {}).call(document.querySelector(".profile-item .header-text") || {}, "title") || "";
    const when = new Date().toLocaleString();
    const title = (o.title && o.title.trim()) || pageTitle();
    if (o.header && main) {
      const h = document.createElement("div");
      h.id = "pdf-header";
      if (logo) { const im = document.createElement("img"); im.src = logo.src; im.alt = ""; h.appendChild(im); }
      const box = document.createElement("div");
      const s = document.createElement("div"); s.className = "pdf-school"; s.textContent = school.trim();
      const t = document.createElement("div"); t.className = "pdf-title"; t.textContent = title;
      const m = document.createElement("div"); m.className = "pdf-meta"; m.textContent = when + (who ? " · " + who : "");
      box.append(s, t, m); h.appendChild(box);
      main.insertBefore(h, main.firstChild);
    }
    if (o.footer) {
      const f = document.createElement("div");
      f.id = "pdf-footer";
      f.textContent = school.trim() + " · " + title;
      document.body.appendChild(f);
    }
    if (Array.isArray(o.sections)) {
      blocks().forEach(function (el, i) { if (o.sections.indexOf(i) === -1) el.classList.add("pdf-skip"); });
      document.querySelectorAll(UI_ONLY).forEach(function (el) { el.classList.add("pdf-skip"); });
    }
  }

  function open() {
    const o = load();
    const list = blocks();
    const sel = function (name, opts, cur) {
      return '<select id="pdf_' + name + '" class="swal2-select" style="margin:0;width:100%;display:block;">' + opts.map(function (x) {
        return '<option value="' + x[0] + '"' + (x[0] === cur ? " selected" : "") + ">" + x[1] + "</option>";
      }).join("") + "</select>";
    };
    const esc = function (s) { return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/"/g, "&quot;"); };
    const html = '<div style="text-align:left;font-size:.9rem;">'
      + '<label style="display:block;margin:0 0 4px;font-weight:600;">Title</label><input id="pdf_title" class="swal2-input" style="margin:0 0 12px;width:100%;box-sizing:border-box;" value="' + esc(pageTitle()) + '">'
      + '<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-bottom:12px;">'
      + '<div><label style="font-weight:600;">Paper</label>' + sel("paper", Object.keys(PAPERS).map(function (k) { return [k, k]; }), o.paper) + "</div>"
      + '<div><label style="font-weight:600;">Direction</label>' + sel("orientation", [["portrait", "Portrait"], ["landscape", "Landscape"]], o.orientation) + "</div>"
      + '<div><label style="font-weight:600;">Margins</label>' + sel("margin", [["narrow", "Narrow"], ["normal", "Normal"], ["wide", "Wide"]], o.margin) + "</div></div>"
      + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;margin-bottom:12px;">'
      + '<label><input type="checkbox" id="pdf_header"' + (o.header ? " checked" : "") + "> Header (logo, school, title, date)</label>"
      + '<label><input type="checkbox" id="pdf_footer"' + (o.footer ? " checked" : "") + "> Footer and page numbers</label>"
      + '<label><input type="checkbox" id="pdf_color"' + (o.color ? " checked" : "") + "> Colours (off = black &amp; white)</label>"
      + '<label><input type="checkbox" id="pdf_fit"' + (o.fit ? " checked" : "") + "> Fit tables to the page width</label></div>"
      + (list.length > 1 ? '<label style="display:block;margin:0 0 4px;font-weight:600;">Include these parts of the page</label><div id="pdf_sections" style="max-height:150px;overflow:auto;border:1px solid #e4e8f1;border-radius:10px;padding:8px 10px;">'
        + list.map(function (el, i) { return '<label style="display:block;"><input type="checkbox" data-i="' + i + '" checked> ' + esc(label(el, i)) + "</label>"; }).join("") + "</div>" : "")
      + '<p style="margin:10px 0 0;color:#667085;font-size:.8rem;">In the next window choose <strong>Save as PDF</strong> as the printer.</p></div>';
    Swal.fire({ title: "Export PDF", html: html, width: 560, showCancelButton: true, confirmButtonText: "Continue", confirmButtonColor: "#4361ee", focusConfirm: false,
      preConfirm: function () {
        const g = function (id) { return document.getElementById(id); };
        const secs = Array.prototype.slice.call(document.querySelectorAll("#pdf_sections input:checked")).map(function (c) { return Number(c.dataset.i); });
        if (list.length > 1 && !secs.length) { Swal.showValidationMessage("Choose at least one part of the page"); return false; }
        return { paper: g("pdf_paper").value, orientation: g("pdf_orientation").value, margin: g("pdf_margin").value, title: g("pdf_title").value,
                 header: g("pdf_header").checked, footer: g("pdf_footer").checked, color: g("pdf_color").checked, fit: g("pdf_fit").checked, sections: list.length > 1 ? secs : null };
      } }).then(function (r) {
      if (!r.isConfirmed) return;
      save(r.value);
      apply(r.value);
      const done = function () { window.removeEventListener("afterprint", done); clear(); };
      window.addEventListener("afterprint", done);
      setTimeout(function () { window.print(); }, 150);
    });
  }

  window.scPdf = { open: open, apply: apply, clear: clear };
  document.addEventListener("click", function (e) {
    if (e.target.closest && e.target.closest("#pdf-export")) { e.preventDefault(); open(); }
  });
})();

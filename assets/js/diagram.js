/**
 * SmartClass - diagrams and charts in lectures. A fenced block whose language is a drawing language becomes a picture:
 *   mermaid        flowcharts, sequence / class / state / ER diagrams, Gantt, pie, mind map, timeline …                          (Mermaid)
 *   dot            Graphviz graphs                                                                                              (Viz.js)
 *   vega-lite      data charts from inline data: bar, line, area, scatter, heat map, layered …                                  (Vega-Lite)
 *   chart          Chart.js charts written as JSON {type, data, options}: bar, line, pie, doughnut, radar, polar area, scatter  (Chart.js)
 * All libraries are served from this server (assets/vendor/), so nothing is loaded from the internet and the text never leaves the page.
 * The server turns the block into <figure class="lec-diagram" data-kind="…"><pre class="mermaid-src">…</pre></figure>; this file draws every such figure on the
 * page — also those that appear later (editor preview, AI answers) — and redraws them when the light / dark theme changes. A figure with a mistake shows a short
 * note and its text instead. Charts may not load data from a web address; only the numbers written in the block are drawn.
 */
(function () {
  const meta = document.querySelector('meta[name="app-url"]');
  const VENDOR = (meta ? meta.content.replace(/\/$/, "") : "") + "/assets/vendor/";
  const scripts = {}, charts = new WeakMap();
  let counter = 0;
  const cache = {};   // kind + theme + source → drawn svg (an answer that is re-laid-out while it streams does not redraw)

  function FONT() { return getComputedStyle(document.body).fontFamily || "sans-serif"; }   // the page's own font: the libraries measure text with it, so labels are never cut
  function dark() { return document.documentElement.getAttribute("data-theme") === "dark"; }
  function load(file) {
    if (!scripts[file]) scripts[file] = new Promise(function (ok, no) {
      const s = document.createElement("script"); s.src = VENDOR + file; s.async = true;
      s.onload = ok; s.onerror = function () { no(new Error("Library could not be loaded: " + file)); };
      document.head.appendChild(s);
    });
    return scripts[file];
  }
  const fontsReady = function () { return document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve(); };
  const holder = function (inner) { const d = document.createElement("div"); d.className = "lec-diagram-svg"; d.setAttribute("role", "img"); d.setAttribute("aria-label", "Diagram"); if (inner) d.innerHTML = inner; return d; };

  // ---------- Mermaid ----------
  function mermaidConfig() {
    const d = dark();
    return {
      startOnLoad: false, securityLevel: "strict", theme: "base",
      themeVariables: d
        ? { pie1: "#8fb0ff", pie2: "#5ee0cf", pie3: "#ffb272", pie4: "#d3a4ff", pie5: "#ff8a9b", pie6: "#5fd38d", pie7: "#ffd666", pieTitleTextColor: "#e8eaf6", pieSectionTextColor: "#0f1530", pieLegendTextColor: "#e8eaf6", pieStrokeColor: "#1b2140", pieOuterStrokeColor: "transparent", background: "transparent", primaryColor: "#27305c", primaryTextColor: "#e8eaf6", primaryBorderColor: "#8fb0ff", lineColor: "#8fb0ff", secondaryColor: "#1f2a4d", tertiaryColor: "#1b2140", edgeLabelBackground: "#1b2140", textColor: "#e8eaf6", fontFamily: FONT(), fontSize: "15px" }
        : { pie1: "#4361ee", pie2: "#0b9d76", pie3: "#e9a23b", pie4: "#7c3aed", pie5: "#e5484d", pie6: "#0b84c6", pie7: "#c27803", pieSectionTextColor: "#ffffff", pieStrokeColor: "#ffffff", pieOuterStrokeColor: "transparent", background: "transparent", primaryColor: "#eef1ff", primaryTextColor: "#172033", primaryBorderColor: "#4361ee", lineColor: "#4361ee", secondaryColor: "#e3f8f1", tertiaryColor: "#fff4de", edgeLabelBackground: "#ffffff", textColor: "#172033", fontFamily: FONT(), fontSize: "15px" },
      flowchart: { curve: "basis", padding: 16, htmlLabels: true, useMaxWidth: false }, sequence: { useMaxWidth: false }, er: { useMaxWidth: false }, class: { useMaxWidth: false }, state: { useMaxWidth: false }, gantt: { useMaxWidth: false }, pie: { useMaxWidth: true }
    };
  }
  function drawMermaid(src) {
    return fontsReady().then(function () { return load("mermaid/mermaid.min.js"); }).then(function () {
      window.mermaid.initialize(mermaidConfig());
      return window.mermaid.render("mmd" + (++counter), src.trim());
    }).then(function (r) { return r.svg; }, function (e) { const bad = document.getElementById("dmmd" + counter); if (bad) bad.remove(); throw e; });
  }

  // ---------- Graphviz (dot) ----------
  function drawDot(src) {
    return load("charts/viz-standalone.js").then(function () { return window.Viz.instance(); }).then(function (viz) {
      const svg = viz.renderSVGElement(src.trim());
      const vb = (svg.getAttribute("viewBox") || "").split(/\s+/).map(Number);   // a drawing without a width collapses: take its natural size from the viewBox
      svg.removeAttribute("width"); svg.removeAttribute("height");
      if (vb.length === 4 && vb[2] > 0) svg.style.width = Math.round(vb[2] * 1.25) + "px";
      svg.style.maxWidth = "100%"; svg.style.height = "auto";
      const color = dark() ? "#e8eaf6" : "#172033", line = dark() ? "#8fb0ff" : "#4361ee";
      const lum = function (c) { const m = /^#([0-9a-f]{6})$/i.exec((c || "").trim()); if (!m) return null; const n = parseInt(m[1], 16); return (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255; };
      svg.querySelectorAll("text").forEach(function (t) {   // dark text on a light node the writer coloured, light text on dark ones, the theme's text colour on nothing
        const g = t.closest("g.node, g.cluster"), sh = g && g.querySelector("polygon, ellipse, path"), l = sh ? lum(sh.getAttribute("fill")) : null;
        t.setAttribute("fill", l === null ? color : (l > 0.6 ? "#172033" : "#ffffff")); t.setAttribute("font-family", FONT());
      });
      svg.querySelectorAll("polygon").forEach(function (p) { if (p.getAttribute("fill") === "white") p.setAttribute("fill", "transparent"); if (p.getAttribute("stroke") === "transparent") return; });
      svg.querySelectorAll("path, ellipse, polygon, polyline").forEach(function (p) { if (p.getAttribute("stroke") === "black") p.setAttribute("stroke", line); if (p.getAttribute("fill") === "black") p.setAttribute("fill", line); });
      return svg.outerHTML;
    });
  }

  // ---------- Vega-Lite / Vega ----------
  function hasUrl(o) { if (o && typeof o === "object") { for (const k in o) { if (k === "url" || hasUrl(o[k])) return true; } } return false; }
  function drawVega(src, box) {
    const spec = JSON.parse(src);
    if (hasUrl(spec)) throw new Error("A chart may not load data from a web address — write the numbers in the chart itself.");
    return load("charts/vega.min.js").then(function () { return load("charts/vega-lite.min.js"); }).then(function () { return load("charts/vega-embed.min.js"); }).then(function () {
      spec.background = spec.background || "transparent"; if (!spec.width) spec.width = "container";
      const d = dark();
      const config = d ? { axis: { labelAngle: 0, labelColor: "#c9d1ee", titleColor: "#e8eaf6", gridColor: "#3a4270", domainColor: "#6b78b8", tickColor: "#6b78b8" }, legend: { labelColor: "#c9d1ee", titleColor: "#e8eaf6" }, title: { color: "#e8eaf6" }, mark: { color: "#8fb0ff" }, view: { stroke: "transparent" }, range: { category: ["#8fb0ff", "#5ee0cf", "#ffb272", "#d3a4ff", "#ff8a9b", "#5fd38d"] } }
                       : { mark: { color: "#4361ee" }, view: { stroke: "transparent" }, range: { category: ["#4361ee", "#0b9d76", "#c27803", "#7c3aed", "#e5484d", "#0b84c6"] }, axis: { labelAngle: 0, labelFont: FONT(), titleFont: FONT() } };
      return window.vegaEmbed(box, spec, { actions: false, renderer: "svg", config: config, mode: spec.$schema && /vega\/v/.test(spec.$schema) && !/vega-lite/.test(spec.$schema) ? "vega" : "vega-lite" });
    });
  }

  // ---------- Chart.js ----------
  function drawChart(src, box, fig) {
    const cfg = JSON.parse(src);
    return load("charts/chart.umd.js").then(function () {
      const C = window.Chart, d = dark(), text = d ? "#e8eaf6" : "#172033", grid = d ? "rgba(143,176,255,.18)" : "rgba(67,97,238,.14)";
      const palette = d ? ["#8fb0ff", "#5ee0cf", "#ffb272", "#d3a4ff", "#ff8a9b", "#5fd38d", "#ffd666"] : ["#4361ee", "#0b9d76", "#e9a23b", "#7c3aed", "#e5484d", "#0b84c6", "#c27803"];
      C.defaults.color = text; C.defaults.font.family = FONT(); C.defaults.borderColor = grid;
      const type = String(cfg.type || "bar");
      ((cfg.data || {}).datasets || []).forEach(function (ds, i) {   // colours the writer did not choose
        const one = ["pie", "doughnut", "polarArea"].indexOf(type) !== -1;
        if (ds.backgroundColor === undefined) ds.backgroundColor = one ? (ds.data || []).map(function (_, k) { return palette[k % palette.length]; }) : palette[i % palette.length] + (type === "line" || type === "radar" ? "33" : "dd");
        if (ds.borderColor === undefined && !one) ds.borderColor = palette[i % palette.length];
        if (ds.borderWidth === undefined) ds.borderWidth = one ? 2 : (type === "line" || type === "radar" ? 3 : 1);
        if (type === "bar" && ds.borderRadius === undefined) ds.borderRadius = 6;
        if (type === "line" && ds.tension === undefined) ds.tension = 0.3;
      });
      cfg.options = cfg.options || {}; cfg.options.responsive = true; cfg.options.maintainAspectRatio = true; cfg.options.aspectRatio = cfg.options.aspectRatio || 1.8;
      const canvas = document.createElement("canvas"); box.appendChild(canvas);
      const old = charts.get(fig); if (old) old.destroy();
      charts.set(fig, new C(canvas, cfg));
    });
  }

  // ---------- a text version for everyone who cannot see the picture (screen readers, print, copy) ----------
  function table(headers, rows) {
    const t = document.createElement("table"); t.className = "app-table lec-diagram-table";
    const h = t.createTHead().insertRow(); headers.forEach(function (x) { const c = document.createElement("th"); c.scope = "col"; c.textContent = x; h.appendChild(c); });
    const b = t.createTBody(); rows.forEach(function (r) { const tr = b.insertRow(); r.forEach(function (x) { tr.insertCell().textContent = x == null ? "" : String(x); }); });
    return t;
  }
  function textVersion(kind, src) {
    try {
      if (kind === "chart") {
        const c = JSON.parse(src), labels = (c.data && c.data.labels) || [], sets = (c.data && c.data.datasets) || [];
        const rows = labels.length ? labels.map(function (l, i) { return [l].concat(sets.map(function (d) { const v = d.data[i]; return v && typeof v === "object" ? JSON.stringify(v) : v; })); })
                                    : sets.length && sets[0].data ? sets[0].data.map(function (v, i) { return [i + 1].concat(sets.map(function (d) { return d.data[i]; })); }) : [];
        return table([""].concat(sets.map(function (d, i) { return d.label || ("Series " + (i + 1)); })), rows);
      }
      if (kind === "vega") {
        const v = JSON.parse(src), vals = (v.data && v.data.values) || ((v.datasets && Object.values(v.datasets)[0]) || []);
        if (Array.isArray(vals) && vals.length && typeof vals[0] === "object") { const keys = Object.keys(vals[0]); return table(keys, vals.slice(0, 60).map(function (r) { return keys.map(function (k) { return r[k]; }); })); }
      }
    } catch (e) { /* fall through to the source text */ }
    const pre = document.createElement("pre"); pre.textContent = src; return pre;
  }
  function titleOf(kind, src) {
    try { if (kind === "chart") { const c = JSON.parse(src), t = c.options && c.options.plugins && c.options.plugins.title && c.options.plugins.title.text; if (t) return String(t); } if (kind === "vega") { const v = JSON.parse(src); const t = typeof v.title === "string" ? v.title : v.title && v.title.text; if (t) return String(t); } } catch (e) {}
    const m = src.match(/^\s*(?:%%\s*)?(?:title|alt)\s*[:=]\s*(.+)$/im); return m ? m[1].trim() : "";
  }
  function addText(fig, kind, src) {
    if (fig.querySelector(".lec-diagram-src")) return;
    const d = document.createElement("details"); d.className = "lec-diagram-src";
    const sm = document.createElement("summary"); sm.textContent = "Text of this " + (kind === "chart" || kind === "vega" ? "chart" : "diagram"); d.append(sm, textVersion(kind, src)); fig.appendChild(d);
    const t = titleOf(kind, src), box = fig.querySelector(".lec-diagram-svg"); if (box) box.setAttribute("aria-label", (t ? t : (kind === "chart" || kind === "vega" ? "Chart" : "Diagram")) + " — the text version is below");
  }

  // ---------- the figure ----------
  function draw(fig) {
    const src = fig.dataset.src || (fig.querySelector(".mermaid-src") || {}).textContent || "";
    if (!src.trim()) return Promise.resolve();
    const kind = fig.dataset.kind || "mermaid";
    fig.dataset.src = src; fig.dataset.busy = "1";
    const theme = dark() ? "d" : "l", key = kind + ":" + theme + ":" + src;
    const done = function (node, failed) { fig.dataset.busy = ""; fig.dataset.done = theme; fig.classList.toggle("is-error", !!failed); fig.textContent = ""; fig.appendChild(node); };
    if (cache[key]) { done(holder(cache[key])); addText(fig, kind, src); return Promise.resolve(); }
    const box = holder();
    let job;
    try {
      if (kind === "dot") job = drawDot(src).then(function (svg) { cache[key] = svg; box.innerHTML = svg; });
      else if (kind === "vega") { box.classList.add("is-vega"); fig.textContent = ""; fig.appendChild(box); job = drawVega(src, box); }
      else if (kind === "chart") { box.classList.add("is-chart"); fig.textContent = ""; fig.appendChild(box); job = drawChart(src, box, fig); }
      else job = drawMermaid(src).then(function (svg) { cache[key] = svg; box.innerHTML = svg; });
    } catch (e) { job = Promise.reject(e); }
    return job.then(function () { done(box); addText(fig, kind, src); }, function (e) {
      const wrap = document.createElement("div");
      const note = document.createElement("div"); note.className = "lec-diagram-note";
      note.textContent = (e && /web address/.test(e.message || "")) ? e.message : "This drawing could not be made — check its text.";
      const pre = document.createElement("pre"); pre.textContent = src; wrap.append(note, pre); done(wrap, true);
    });
  }
  function scan(root) {
    (root || document).querySelectorAll("figure.lec-diagram").forEach(function (fig) {
      if (fig.dataset.busy === "1") return;
      if (fig.dataset.done === (dark() ? "d" : "l")) return;
      draw(fig);
    });
  }
  window.scRenderDiagrams = scan;

  let timer = null;
  const later = function () { clearTimeout(timer); timer = setTimeout(function () { scan(document); }, 60); };
  new MutationObserver(function (list) {
    for (let i = 0; i < list.length; i++) {
      if (list[i].type === "attributes") { later(); return; }   // the theme changed
      for (let j = 0; j < list[i].addedNodes.length; j++) { const n = list[i].addedNodes[j]; if (n.nodeType === 1 && (n.matches("figure.lec-diagram") || n.querySelector("figure.lec-diagram"))) { later(); return; } }
    }
  }).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ["data-theme"] });
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", later); else later();
})();

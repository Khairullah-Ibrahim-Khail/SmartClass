/**
 * SmartClass - grading scale editor (school default and class): presets, add / remove bands, live "To %",
 * "In marks" and GPA ranges, preview. The server validates again (gradingValidate); this only makes editing quick.
 *   form#gradingForm[data-presets][data-total]   data-total = marks the final total is out of (class page), optional
 */
(function () {
  const form = document.getElementById("gradingForm");
  if (!form) return;
  const body = document.getElementById("gsRows");
  const gpaBox = document.getElementById("showGpa");
  const preview = document.getElementById("gsPreview");
  const presets = JSON.parse(form.dataset.presets || "{}");
  const total = parseFloat(form.dataset.total || "0") || 0;
  const SWATCHES = ["#06a77d", "#2fbf71", "#5cc7a6", "#4cc9f0", "#4361ee", "#7b61ff", "#e9a23b", "#f4a261", "#ef476f", "#d62828", "#8d99ae", "#6b4226"];
  const TONE_COLOR = { good: "#06a77d", ok: "#5cc7a6", mid: "#e9a23b", low: "#ef476f" };
  function rgb(h) { return [parseInt(h.substr(1, 2), 16), parseInt(h.substr(3, 2), 16), parseInt(h.substr(5, 2), 16)]; }
  function ink(h) { const c = rgb(h).map(function (v) { return Math.round(v * 0.55); }); return "rgb(" + c.join(",") + ")"; }
  function tint(h) { const c = rgb(h); return "rgba(" + c.join(",") + ",.17)"; }

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function num(v) { return Math.round(v * 100) / 100; }

  function rowHtml(i, b) {
    return '<tr><td><input type="text" name="bands[' + i + '][label]" maxlength="20" value="' + esc(b.label) + '" placeholder="A+" aria-label="Grade name"></td>' +
      '<td class="gs-bound"><select name="bands[' + i + '][min_op]" aria-label="Greater than or equal"><option value="gte"' + (b.min_op === "gt" ? "" : " selected") + '>≥</option><option value="gt"' + (b.min_op === "gt" ? " selected" : "") + '>&gt;</option></select> ' +
        '<input type="number" name="bands[' + i + '][min]" min="0" max="100" step="0.01" value="' + esc(b.min) + '" aria-label="Starts at percent"></td>' +
      '<td class="gs-bound"><select name="bands[' + i + '][max_op]" aria-label="Less than or equal"><option value="lte"' + (b.max_op === "lt" ? "" : " selected") + '>≤</option><option value="lt"' + (b.max_op === "lt" ? " selected" : "") + '>&lt;</option></select> ' +
        '<input type="number" name="bands[' + i + '][max]" min="0" max="100" step="0.01" value="' + esc(b.max === undefined || b.max === null ? "" : b.max) + '" placeholder="auto" aria-label="Goes up to percent"></td>' + (total ? '<td class="gs-marks">—</td>' : "") +
      '<td class="gs-gpa-col"><input type="number" name="bands[' + i + '][gpa]" min="0" max="10" step="0.01" value="' + esc(b.gpa === undefined ? "" : b.gpa) + '" aria-label="GPA of this grade"></td>' +
      '<td class="gs-color"><input type="color" name="bands[' + i + '][color]" value="' + esc(b.color || TONE_COLOR[b.tone] || "#6b7280") + '" aria-label="Colour of this grade">' +
        '<span class="gs-swatches">' + SWATCHES.map(function (c) { return '<button type="button" class="gs-sw" style="background:' + c + '" data-color="' + c + '" title="' + c + '" aria-label="Use colour ' + c + '"></button>'; }).join("") + "</span></td>" +
      '<td><button type="button" class="icon-btn danger gs-del" title="Remove this band" aria-label="Remove this band"><i class="fas fa-times"></i></button></td></tr>';
  }
  function renumber() {
    Array.prototype.forEach.call(body.rows, function (tr, i) {
      tr.querySelectorAll("[name]").forEach(function (el) { el.name = el.name.replace(/bands\[\d+\]/, "bands[" + i + "]"); });
    });
  }
  function read(tr) {
    const v = function (n) { return tr.querySelector('[name$="[' + n + ']"]').value; };
    return { tr: tr, label: v("label").trim(), min: parseFloat(v("min")), min_op: v("min_op"), max_op: v("max_op"), max: v("max"), gpa: v("gpa"), color: v("color") };
  }
  function rangeText(b, k, unit) {
    const lo = num(b.min * k), hi = num(b.top * k), gt = b.min_op === "gt", lt = b.max_op === "lt";
    if (!gt && !lt) return lo + "–" + hi + unit;
    if (b.min === 0 && !gt) return (lt ? "< " : "≤ ") + hi + unit;
    if (b.top === 100 && !lt) return (gt ? "> " : "≥ ") + lo + unit;
    return (gt ? "> " : "≥ ") + lo + " and " + (lt ? "< " : "≤ ") + hi + unit;
  }
  function draw() {
    const all = Array.prototype.map.call(body.rows, read);
    const list = all.filter(function (b) { return b.label !== "" && !isNaN(b.min); }).sort(function (a, b) { return b.min - a.min; });
    // what "To %" would be when left empty; the marks column follows the written (or automatic) range
    function auto(idx) {
      if (idx === 0) return 100;
      const n = list[idx - 1].min;
      return n === Math.floor(n) ? n - 1 : num(n - 0.01);
    }
    all.forEach(function (b) {
      const idx = list.indexOf(b);
      const inp = b.tr.querySelector('[name$="[max]"]'), mk = b.tr.querySelector(".gs-marks");
      if (idx < 0) { if (mk) mk.textContent = "—"; return; }
      b.top = b.max !== "" && !isNaN(parseFloat(b.max)) ? parseFloat(b.max) : Math.max(b.min, auto(idx));
      inp.placeholder = String(Math.max(b.min, auto(idx)));
      if (mk) mk.textContent = num(b.min / 100 * total) + " – " + num(b.top / 100 * total);
    });
    preview.innerHTML = list.length ? list.map(function (b, i) {
      const top = b.top;
      let t = "<b>" + esc(b.label) + "</b> " + rangeText(b, 1, "%");
      if (total) t += " · " + rangeText(b, total / 100, "") + " marks";
      { const g = b.gpa === "" ? 0 : b.gpa; t += " · GPA " + g + (gpaBox.checked ? "" : " (not shown)"); }
      return '<span class="gs-chip" style="background:' + tint(b.color) + ";color:" + ink(b.color) + '">' + t + "</span>";
    }).join("") : '<span class="form-hint">Add bands to see them here.</span>';
  }
  function load(p) {
    body.innerHTML = p.bands.map(function (b, i) { return rowHtml(i, b); }).join("");
    gpaBox.checked = !!p.show_gpa;
    draw();
  }

  form.addEventListener("click", function (e) {
    const pre = e.target.closest("[data-preset]");
    if (pre && presets[pre.dataset.preset]) { load(presets[pre.dataset.preset]); return; }
    if (e.target.closest("#gsAdd")) {
      body.insertAdjacentHTML("beforeend", rowHtml(body.rows.length, { label: "", min: "", gpa: "", color: "#e9a23b" }));
      body.rows[body.rows.length - 1].querySelector("input").focus();
      draw();
      return;
    }
    const sw = e.target.closest(".gs-sw");
    if (sw) { sw.closest("tr").querySelector('input[type=color]').value = sw.dataset.color; draw(); return; }
    const del = e.target.closest(".gs-del");
    if (del) { del.closest("tr").remove(); renumber(); draw(); }
  });
  form.addEventListener("input", draw);
  form.addEventListener("change", draw);
  draw();
})();

/* Top bar search: the magnifier expands into a search field IN the header; results drop down right under it.
 * Opens with a click on the magnifier or the "/" key; ↑ ↓ choose, Enter opens, Esc / the X / a click elsewhere closes.
 * Asks pages/search.php while you type (after a short pause). Words come from data-* attributes of #scInline (already in the screen language). */
(function () {
  "use strict";
  var wrap = document.getElementById("scInline"); if (!wrap) return;
  var btn = document.getElementById("scSearchBtn"), input = document.getElementById("scSearchInput"), closeBtn = document.getElementById("scSearchClose");
  var drop = null, timer = null, seq = 0, active = -1, isOpen = false;
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function build() {
    drop = document.createElement("div"); drop.className = "sc-drop"; drop.hidden = true; drop.setAttribute("role", "listbox");
    document.body.appendChild(drop);
    drop.addEventListener("mousedown", function (e) { e.preventDefault(); });   // clicking a result must not blur the field first
  }
  function place() {
    if (!drop) return;
    var r = wrap.getBoundingClientRect(), phone = window.innerWidth <= 600, w = phone ? window.innerWidth - 16 : Math.max(r.width, 380), rtl = document.documentElement.dir === "rtl";
    drop.style.width = w + "px"; drop.style.top = Math.round(r.bottom + 6) + "px";
    drop.style.left = Math.round(phone ? 8 : Math.min(Math.max(8, rtl ? r.right - w : r.left), window.innerWidth - w - 8)) + "px";
  }
  function show(html, cls) { if (!drop) build(); drop.innerHTML = html; drop.className = "sc-drop " + (cls || ""); drop.hidden = !html; place(); }
  function say(t) { show(t ? '<div class="sc-status">' + esc(t) + "</div>" : "", "msg"); }
  function open() {
    if (isOpen) { input.focus(); return; }
    isOpen = true; wrap.classList.add("open"); document.documentElement.classList.add("sc-expanded"); input.hidden = false; closeBtn.hidden = false; btn.setAttribute("aria-expanded", "true");
    input.value = ""; active = -1; say(wrap.dataset.hint); setTimeout(function () { input.focus(); }, 30);
  }
  function close() {
    if (!isOpen) return;
    isOpen = false; wrap.classList.remove("open"); document.documentElement.classList.remove("sc-expanded"); input.hidden = true; closeBtn.hidden = true; btn.setAttribute("aria-expanded", "false");
    if (drop) drop.hidden = true; clearTimeout(timer); seq++;
  }
  function mark(items) { items.forEach(function (a, i) { a.classList.toggle("on", i === active); a.setAttribute("aria-selected", i === active ? "true" : "false"); }); if (items[active]) items[active].scrollIntoView({ block: "nearest" }); }
  function run() {
    var q = input.value.trim(), my = ++seq;
    if (q.length < 2) { say(wrap.dataset.hint); return; }
    fetch(wrap.dataset.url + "?q=" + encodeURIComponent(q), { credentials: "same-origin", headers: { Accept: "application/json" } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (my !== seq || !isOpen) return;   // an older answer arriving late
        if (!d.ok) { say(d.error || wrap.dataset.error); return; }
        if (!d.groups.length) { say(wrap.dataset.empty); return; }
        active = -1;
        show(d.groups.map(function (g) {
          return '<div class="sc-group"><div class="sc-group-h"><i class="fas ' + esc(g.icon) + '" aria-hidden="true"></i> ' + esc(g.label) + "</div>"
            + g.items.map(function (it) { return '<a class="sc-hit" role="option" href="' + esc(it.url) + '"><span class="sc-hit-t">' + esc(it.title) + "</span>" + (it.sub ? '<span class="sc-hit-s">' + esc(it.sub) + "</span>" : "") + "</a>"; }).join("") + "</div>";
        }).join(""), "list");
      })
      .catch(function () { if (my === seq) say(wrap.dataset.error); });
  }
  btn.addEventListener("click", function () { isOpen ? close() : open(); });
  closeBtn.addEventListener("click", close);
  input.addEventListener("input", function () { clearTimeout(timer); timer = setTimeout(run, 220); });
  input.addEventListener("keydown", function (e) {
    var items = drop ? drop.querySelectorAll("a.sc-hit") : [];
    if (e.key === "ArrowDown" || e.key === "ArrowUp") { e.preventDefault(); if (!items.length) return; active = (active + (e.key === "ArrowDown" ? 1 : -1) + items.length) % items.length; mark(items); }
    else if (e.key === "Enter" && items.length) { e.preventDefault(); items[active >= 0 ? active : 0].click(); }
  });
  document.addEventListener("keydown", function (e) {
    var tag = (e.target && e.target.tagName) || "";
    if (e.key === "/" && !e.ctrlKey && !e.metaKey && !e.altKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(tag) && !(e.target && e.target.isContentEditable)) { e.preventDefault(); open(); }
    else if (e.key === "Escape" && isOpen) { close(); btn.focus(); }
  });
  document.addEventListener("click", function (e) { if (isOpen && !wrap.contains(e.target) && !(drop && drop.contains(e.target))) close(); });
  window.addEventListener("resize", place); window.addEventListener("scroll", place, true);
})();

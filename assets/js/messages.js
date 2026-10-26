/**
 * SmartClass - class Messages tab without page reloads.
 *   open a conversation   GET  messages.php?...&ajax=open   -> { list, thread, key }
 *   send                  POST messages.php (ajax=1)        -> { bubble, list } | { field, error }
 *   live updates          GET  messages.php?...&ajax=poll&after=ID  every few seconds while the tab is visible
 * The page still works with JavaScript off (plain links and a normal POST).
 */
(function () {
  const layout = document.getElementById("messagesLayout");
  if (!layout) return;
  const listBox = document.getElementById("convItems");
  const threadBox = document.getElementById("convThread");
  const base = layout.dataset.url;
  const drafts = {};          // unsent text per conversation
  let openKey = "";
  let loading = 0;            // counter so a slow answer never overwrites a newer choice
  let sending = false;

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function body() { return document.getElementById("threadBody"); }
  function scrollDown(smooth) { const b = body(); if (b) b.scrollTo({ top: b.scrollHeight, behavior: smooth ? "smooth" : "auto" }); }
  function textarea() { return threadBox.querySelector(".thread-reply textarea"); }

  function getJson(url) {
    return fetch(url, { credentials: "same-origin", headers: { "X-Requested-With": "fetch", "Accept": "application/json" } })
      .then(function (r) { if (!r.ok) throw new Error("http " + r.status); return r.json(); });
  }

  function setList(html) { if (listBox.innerHTML !== html) listBox.innerHTML = html; markActive(); }
  function markActive() {
    listBox.querySelectorAll(".conv-item").forEach(function (a) { a.classList.toggle("active", a.dataset.key === openKey); });
  }

  function autosize(t) { t.style.height = "auto"; t.style.height = Math.min(t.scrollHeight, 150) + "px"; }

  // ---------- open a conversation ----------
  function open(url, keepFocus) {
    const mine = ++loading;
    const t = textarea();
    if (t && openKey) drafts[openKey] = t.value;
    threadBox.classList.add("is-loading");
    return getJson(url + "&ajax=open").then(function (d) {
      if (mine !== loading) return;
      threadBox.classList.remove("is-loading");
      if (!d.ok) return;
      openKey = d.key;
      threadBox.innerHTML = d.thread;
      setList(d.list);
      const ta = textarea();
      if (ta) {
        if (drafts[openKey]) ta.value = drafts[openKey];
        autosize(ta);
        if (keepFocus !== false) ta.focus({ preventScroll: true });
      }
      scrollDown(false);
      if (history.replaceState) history.replaceState(history.state, "", url);
      if (window.innerWidth <= 900) threadBox.scrollIntoView({ behavior: "smooth", block: "start" });
    }).catch(function () {
      threadBox.classList.remove("is-loading");
      window.location.href = url;      // anything unexpected: the normal page
    });
  }

  listBox.addEventListener("click", function (e) {
    const a = e.target.closest("a.conv-item");
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button) return;
    e.preventDefault();
    e.stopPropagation();   // not a page navigation
    if (a.dataset.key === openKey) return;
    open(a.getAttribute("href"));
  });

  // ---------- send ----------
  threadBox.addEventListener("input", function (e) {
    if (e.target.matches(".thread-reply textarea")) {
      autosize(e.target);
      e.target.classList.remove("is-invalid");
    }
  });
  threadBox.addEventListener("keydown", function (e) {
    if (e.target.matches(".thread-reply textarea") && e.key === "Enter" && !e.shiftKey && !e.isComposing) {
      e.preventDefault();
      const f = e.target.form;
      if (f.requestSubmit) f.requestSubmit(); else f.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
    }
  });
  threadBox.addEventListener("submit", function (e) {
    const form = e.target.closest(".thread-reply");
    if (!form) return;
    e.preventDefault();
    e.stopPropagation();   // handled here, not by the page-navigation layer
    if (sending) return;
    const ta = form.querySelector("textarea");
    const fb = form.querySelector(".invalid-feedback");
    const btn = form.querySelector(".send-btn");
    const text = ta.value.trim();
    if (!text) { ta.classList.add("is-invalid"); fb.textContent = "Write a message first"; ta.focus(); return; }
    const data = new FormData(form);
    data.set("ajax", "1");
    sending = true;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Sending';
    fetch(form.getAttribute("action"), { method: "POST", credentials: "same-origin", body: data, headers: { "X-Requested-With": "fetch" } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) {
          ta.classList.add("is-invalid");
          fb.textContent = d.field || d.error || "Not sent";
          return;
        }
        const b = body();
        if (b) {
          const empty = b.querySelector(".thread-empty");
          if (empty) empty.style.display = "none";
          if (!b.querySelector('.bubble-row[data-id="' + d.id + '"]')) b.insertAdjacentHTML("beforeend", d.bubble);
          b.dataset.last = Math.max(+b.dataset.last || 0, d.id);
        }
        ta.value = "";
        delete drafts[openKey];
        autosize(ta);
        setList(d.list);
        scrollDown(true);
        ta.focus();
      })
      .catch(function () { ta.classList.add("is-invalid"); fb.textContent = "Could not send — check your connection and try again."; })
      .then(function () {
        sending = false;
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
      });
  }, true);

  // ---------- live updates ----------
  const timer = setInterval(function () {
    if (!document.body.contains(layout)) { clearInterval(timer); return; }
    const b = body();
    if (document.hidden || !b || sending || threadBox.classList.contains("is-loading")) return;
    const key = openKey;
    const url = base + "&s=" + b.dataset.s + "&c=" + b.dataset.c + "&cid=" + b.dataset.cid + "&ajax=poll&after=" + (b.dataset.last || 0);
    getJson(url).then(function (d) {
      if (!d.ok || key !== openKey || body() !== b) return;
      setList(d.list);
      const atBottom = b.scrollHeight - b.scrollTop - b.clientHeight < 80;
      if (d.bubbles) {
        const empty = b.querySelector(".thread-empty");
        if (empty) empty.style.display = "none";
        b.insertAdjacentHTML("beforeend", d.bubbles);
        b.dataset.last = d.last;
        if (atBottom) scrollDown(true);
      }
      (d.read || []).forEach(function (id) {
        const s = b.querySelector('.bubble-row[data-id="' + id + '"] .seen-state');
        if (s && s.textContent !== "Seen") s.textContent = "Seen";
      });
    }).catch(function () { /* next round */ });
  }, 6000);

  // ---------- "Message a student" picker ----------
  const pick = document.getElementById("newPick");
  if (pick) {
    const btn = pick.querySelector(".pick-btn");
    const panel = pick.querySelector(".pick-panel");
    const search = pick.querySelector("input");
    const items = Array.prototype.slice.call(pick.querySelectorAll("li"));
    const none = pick.querySelector(".pick-none");
    let cursor = -1;

    function visible() { return items.filter(function (li) { return !li.hidden; }); }
    function focusItem(i) {
      const v = visible();
      if (!v.length) { cursor = -1; return; }
      cursor = (i + v.length) % v.length;
      items.forEach(function (li) { li.classList.remove("hover"); });
      v[cursor].classList.add("hover");
      v[cursor].scrollIntoView({ block: "nearest" });
    }
    function setOpen(on) {
      panel.hidden = !on;
      pick.classList.toggle("open", on);
      btn.setAttribute("aria-expanded", on ? "true" : "false");
      if (on) { search.value = ""; filter(); search.focus(); }
    }
    function filter() {
      const q = search.value.trim().toLowerCase();
      items.forEach(function (li) { li.hidden = q !== "" && li.dataset.name.indexOf(q) === -1; });
      none.hidden = visible().length > 0;
      focusItem(0);
    }
    function choose(li) {
      setOpen(false);
      open(pick.dataset.base + li.dataset.s);
    }

    btn.addEventListener("click", function () { setOpen(panel.hidden); });
    search.addEventListener("input", filter);
    search.addEventListener("keydown", function (e) {
      if (e.key === "ArrowDown") { e.preventDefault(); focusItem(cursor + 1); }
      else if (e.key === "ArrowUp") { e.preventDefault(); focusItem(cursor - 1); }
      else if (e.key === "Enter") { e.preventDefault(); const v = visible(); if (v[cursor]) choose(v[cursor]); }
      else if (e.key === "Escape") { setOpen(false); btn.focus(); }
    });
    pick.querySelector("ul").addEventListener("click", function (e) {
      const li = e.target.closest("li");
      if (li) choose(li);
    });
    document.addEventListener("click", function (e) { if (!panel.hidden && !pick.contains(e.target)) setOpen(false); });
  }

  // first paint of the page the server rendered
  const first = body();
  if (first) { openKey = first.dataset.key; scrollDown(false); const t = textarea(); if (t) autosize(t); }
  markActive();
})();

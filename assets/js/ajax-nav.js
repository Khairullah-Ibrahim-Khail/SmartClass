/**
 * SmartClass - instant navigation and form saving (no full page reloads)
 *
 * Links and forms inside the app are fetched in the background and only the page area (<main>) is
 * swapped; the sidebar, header, notifications and theme stay where they are. The server is unchanged:
 * every page still works as a normal page (also with this file missing or failing — any problem falls
 * back to a normal navigation).
 *
 *   - clicks on same-app links (not downloads, not target=_blank, not modified clicks, not [data-no-ajax])
 *   - form submits, including the ones the pages send with form.submit() after a confirm dialog
 *     (the submitter's name/value and uploaded files are included)
 *   - links inside a [data-ajax-frame="id"] menu (Settings) swap only that frame: the menu stays put, only its highlight moves,
 *     and the page keeps its scroll position
 *   - back / forward buttons
 *   - the page's own scripts (<script data-page>) are run again after a swap; listeners they add to
 *     document / window are removed at the next swap, DOMContentLoaded callbacks are run at once
 *   - downloads (Content-Disposition: attachment) are saved as files, not swapped in
 *   - a thin progress bar shows work in progress; the submit button is disabled while sending
 */
(function () {
  if (window.__scNav) return;
  window.__scNav = true;

  const baseMeta = document.querySelector('meta[name="app-url"]');
  if (!baseMeta || !window.fetch || !window.DOMParser || !history.pushState) return;
  const BASE = new URL(baseMeta.content, location.href);
  const PAGES = BASE.pathname.replace(/\/$/, "") + "/pages/";
  const SKIP = /(\/auth\/|logout|download|export|photo\.php|image\.php|images\.php|preview\.php|serve|\.(pdf|csv|zip|png|jpe?g|gif|webp|gz)(\?|$))/i;

  let seq = 0;               // latest navigation wins
  let pageListeners = [];    // [target, type, fn, opts] added by page scripts, removed at the next swap
  let bar = null;

  function progress(on) {
    if (!bar) { bar = document.createElement("div"); bar.id = "navProgress"; document.body.appendChild(bar); }
    bar.className = on ? "on" : "";
    document.documentElement.classList.toggle("nav-busy", !!on);
  }

  function eligible(url) {
    return url.origin === location.origin && url.pathname.indexOf(PAGES) === 0 && !SKIP.test(url.pathname + url.search);
  }

  function fallback(url) { progress(false); window.location.href = url; }

  // ---------- Swap the page area ----------
  function runScripts(doc) {
    // remove what the previous page's scripts added to document / window
    pageListeners.forEach(function (l) { l[0].removeEventListener(l[1], l[2], l[3]); });
    pageListeners = [];
    document.querySelectorAll("script[data-page]").forEach(function (s) { s.remove(); });

    const later = [];   // DOMContentLoaded / load callbacks registered by the page scripts
    const dAdd = document.addEventListener, wAdd = window.addEventListener;
    function patch(target, orig) {
      return function (type, fn, opts) {
        if (type === "DOMContentLoaded" || type === "load") { later.push(fn); return; }
        pageListeners.push([target, type, fn, opts]);
        return orig.call(target, type, fn, opts);
      };
    }
    document.addEventListener = patch(document, dAdd);
    window.addEventListener = patch(window, wAdd);

    const list = Array.prototype.slice.call(doc.querySelectorAll("script[data-page]"));
    return list.reduce(function (p, old) {
      return p.then(function () {
        return new Promise(function (resolve) {
          const s = document.createElement("script");
          s.setAttribute("data-page", "");
          if (old.src) {
            s.src = old.src;
            s.onload = s.onerror = resolve;
          } else {
            // inline page code: own scope, so top-level const / let can be declared again on the next page
            s.textContent = "(function(){\n" + old.textContent + "\n}).call(window);";
          }
          document.body.appendChild(s);
          if (!old.src) resolve();
        });
      });
    }, Promise.resolve()).then(function () {
      document.addEventListener = dAdd;
      window.addEventListener = wAdd;
      later.forEach(function (fn) { try { fn.call(document, new Event("DOMContentLoaded")); } catch (e) { console.error(e); } });
    }, function (e) {
      document.addEventListener = dAdd;
      window.addEventListener = wAdd;
      throw e;
    });
  }

  function syncShell(doc) {
    // sidebar highlighting + open section (same markup on every page, so copy class lists in order)
    const mine = document.querySelectorAll(".sidebar .menu-item, .sidebar .menu-header, .sidebar .dropdown-item");
    const theirs = doc.querySelectorAll(".sidebar .menu-item, .sidebar .menu-header, .sidebar .dropdown-item");
    if (mine.length === theirs.length) {
      mine.forEach(function (el, i) {
        const want = theirs[i].classList.contains("active");
        el.classList.toggle("active", want);
        if (theirs[i].hasAttribute("data-open")) el.setAttribute("data-open", "1");
      });
      const cur = document.querySelector(".sidebar .dropdown-item.active, .sidebar .menu-header.active");
      const item = cur && cur.closest(".menu-item");
      if (item && !item.classList.contains("open") && cur.classList.contains("dropdown-item")) {
        const head = item.querySelector(".menu-header");
        if (head && head.getAttribute("aria-expanded") !== "true") head.click();
      }
    }
    // notifications (bell badge + list); the elements that carry the click handlers stay
    const nd = doc.getElementById("notif-dropdown"), md = document.getElementById("notif-dropdown");
    // only touch the bell when something changed (re-writing it every time made the header icons flash)
    if (nd && md && md.innerHTML !== nd.innerHTML) md.innerHTML = nd.innerHTML;
    const nt = doc.getElementById("notif-trigger"), mt = document.getElementById("notif-trigger");
    if (nt && mt && mt.innerHTML !== nt.innerHTML) mt.innerHTML = nt.innerHTML;
    const tok = doc.querySelector('meta[name="csrf-token"]'), mtok = document.querySelector('meta[name="csrf-token"]');
    if (tok && mtok) mtok.content = tok.content;
    // close the mobile sidebar if it is open
    const ov = document.querySelector(".overlay");
    if (ov && ov.classList.contains("active")) ov.click();
  }

  function swapIn(html, finalUrl, push, scroll) {
    const doc = new DOMParser().parseFromString(html, "text/html");
    const main = doc.getElementById("main-content");
    const cur = document.getElementById("main-content");
    if (!main || !cur || !doc.querySelector(".sidebar")) return false;   // not an app page (login, error): normal navigation
    document.title = doc.title;
    cur.className = main.className;
    cur.innerHTML = main.innerHTML;
    syncShell(doc);
    if (push === "push") history.pushState({ sc: 1 }, "", finalUrl);
    else if (push === "replace") history.replaceState({ sc: 1 }, "", finalUrl);
    const hash = new URL(finalUrl).hash;
    const target = hash ? document.getElementById(decodeURIComponent(hash.slice(1))) : null;
    if (target) target.scrollIntoView();            // e.g. #post-12, #shortmarks
    else if (scroll) window.scrollTo(0, 0);         // a new page starts at the top; saving on the same page keeps your place
    return runScripts(doc).then(function () { window.dispatchEvent(new CustomEvent("sc:navigated")); return true; });
  }

  // A [data-ajax-region] area (e.g. the attendance sheet) is refreshed on its own: the page does not jump,
  // the banner / tabs / scroll position stay. Elements marked [data-ajax-sync="key"] elsewhere on the page are refreshed too.
  function swapRegion(html, finalUrl, region, push) {
    const doc = new DOMParser().parseFromString(html, "text/html");
    const fresh = region.id ? doc.getElementById(region.id) : null;
    if (!fresh || !doc.querySelector(".sidebar")) return false;
    document.title = doc.title;
    region.innerHTML = fresh.innerHTML;
    doc.querySelectorAll("[data-ajax-sync]").forEach(function (n) {
      const mine = document.querySelector('[data-ajax-sync="' + n.getAttribute("data-ajax-sync") + '"]');
      if (mine && mine.innerHTML !== n.innerHTML) mine.innerHTML = n.innerHTML;
    });
    syncShell(doc);
    if (push) history.replaceState({ sc: 1 }, "", finalUrl);
    return runScripts(doc).then(function () { window.dispatchEvent(new CustomEvent("sc:navigated")); return true; });
  }

  // A menu with [data-ajax-frame="id"] (Settings): a click swaps only the element #id (and the page heading); the menu itself
  // is not rebuilt — only its highlight moves — so it never flashes, loses its scroll or sends you back to the top.
  function swapFrame(html, finalUrl, id) {
    const doc = new DOMParser().parseFromString(html, "text/html");
    const fresh = doc.getElementById(id), mine = document.getElementById(id);
    if (!fresh || !mine || !doc.querySelector(".sidebar")) return false;
    document.title = doc.title;
    mine.innerHTML = fresh.innerHTML;
    const h = doc.querySelector(".page-head"), hm = document.querySelector(".page-head");
    if (h && hm) hm.innerHTML = h.innerHTML;
    const menu = document.querySelector('[data-ajax-frame="' + id + '"]');
    if (menu) doc.querySelectorAll('[data-ajax-frame="' + id + '"] a').forEach(function (fa) {
      const mineA = menu.querySelector('a[href="' + fa.getAttribute("href") + '"]');
      if (!mineA) return;
      mineA.className = fa.className;
      if (fa.hasAttribute("aria-current")) mineA.setAttribute("aria-current", "page"); else mineA.removeAttribute("aria-current");
    });
    syncShell(doc);
    history.pushState({ sc: 1 }, "", finalUrl);
    const top = mine.getBoundingClientRect().top;
    if (top < 0) window.scrollBy(0, top - 90);   // only when the start of the new page is above the screen
    return runScripts(doc).then(function () { window.dispatchEvent(new CustomEvent("sc:navigated")); return true; });
  }

  // ---------- Requests ----------
  function saveBlob(res, finalUrl) {
    const cd = res.headers.get("Content-Disposition") || "";
    const m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
    return res.blob().then(function (b) {
      const a = document.createElement("a");
      a.href = URL.createObjectURL(b);
      a.download = m ? decodeURIComponent(m[1]) : "download";
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
    });
  }

  function go(url, opts) {
    opts = opts || {};
    const my = ++seq;
    progress(true);
    const init = { credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" }, redirect: "follow" };
    if (opts.body !== undefined) { init.method = "POST"; init.body = opts.body; }
    return fetch(url, init).then(function (res) {
      if (my !== seq) return;   // a newer navigation took over
      const finalUrl = res.url || url;
      const type = res.headers.get("Content-Type") || "";
      if (/attachment/i.test(res.headers.get("Content-Disposition") || "")) { progress(false); return saveBlob(res, finalUrl); }
      if (!/text\/html/i.test(type) || !eligible(new URL(finalUrl, location.href))) { fallback(finalUrl); return; }
      return res.text().then(function (html) {
        if (my !== seq) return;
        const how = opts.noHistory ? "none" : (opts.body !== undefined && finalUrl === new URL(url, location.href).href ? "replace" : "push");
        if (opts.region && opts.region.isConnected && new URL(finalUrl, location.href).pathname === location.pathname) {
          const part = swapRegion(html, finalUrl, opts.region, !opts.noHistory);
          if (part !== false) return part.then(function () { progress(false); });
        }
        if (opts.frame) {
          const fr = swapFrame(html, finalUrl, opts.frame);
          if (fr !== false) return fr.then(function () { progress(false); });
        }
        const samePage = new URL(finalUrl, location.href).pathname === location.pathname;
        const keepPlace = opts.body !== undefined && samePage;   // a form saved and came back to the same page
        const done = swapIn(html, finalUrl, how === "none" ? "" : how, !opts.noScroll && !keepPlace);
        if (done === false) { fallback(finalUrl); return; }
        return done.then(function () { progress(false); const m = document.getElementById("main-content"); if (m) { m.setAttribute("tabindex", "-1"); m.focus({ preventScroll: true }); } });
      });
    }).catch(function (e) {
      console.error("ajax-nav:", e);
      if (my === seq) fallback(url);
    });
  }

  // ---------- Links ----------
  document.addEventListener("click", function (e) {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target.closest && e.target.closest("a[href]");
    if (!a || a.target || a.hasAttribute("download") || a.hasAttribute("data-no-ajax") || a.hasAttribute("data-toggle-target") || a.hasAttribute("data-copy")) return;
    const href = a.getAttribute("href");
    if (!href || href.charAt(0) === "#" || /^(javascript|mailto|tel):/i.test(href)) return;
    const url = new URL(a.href, location.href);
    if (!eligible(url)) return;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return;   // same page anchor
    e.preventDefault(); e.stopPropagation();
    const rg = a.closest("[data-ajax-region]");
    const fm = a.closest("[data-ajax-frame]");
    go(url.href, fm ? { frame: fm.getAttribute("data-ajax-frame") } : (rg && url.pathname === location.pathname ? { region: rg } : undefined));
  }, true);

  // ---------- Forms ----------
  function formEligible(form) {
    if (form.hasAttribute("data-no-ajax") || form.target || form.closest("[data-no-ajax]")) return null;
    const action = new URL(form.getAttribute("action") || location.href, location.href);
    if (!eligible(action)) return null;
    return action;
  }
  function send(form, submitter) {
    if (!form.isConnected) return true;   // a form of a page we already left (e.g. a quiz timer firing late): do nothing
    const action = formEligible(form);
    if (!action) return false;
    const method = (form.getAttribute("method") || "GET").toUpperCase();
    const rg = action.pathname === location.pathname ? form.closest("[data-ajax-region]") : null;
    let fd;
    try { fd = submitter ? new FormData(form, submitter) : new FormData(form); } catch (err) { fd = new FormData(form); }
    const btns = Array.prototype.slice.call(form.querySelectorAll("button[type=submit], input[type=submit]"));
    btns.forEach(function (b) { b.disabled = true; });
    const restore = function () { btns.forEach(function (b) { b.disabled = false; }); };
    if (method === "GET") {
      const q = new URLSearchParams();
      fd.forEach(function (v, k) { if (typeof v === "string") q.append(k, v); });
      action.search = q.toString();
      go(action.href, rg ? { region: rg } : undefined).then(restore, restore);
    } else {
      go(action.href, rg ? { body: fd, region: rg } : { body: fd }).then(restore, restore);
    }
    return true;
  }
  // Bubbling phase (not capture): a page's own handler on the form runs FIRST — it can ask "are you sure?" or show an inline error and call
  // preventDefault(); we only send what is still going to be submitted. (In the capture phase we sent first, so a Cancel came too late.)
  document.addEventListener("submit", function (e) {
    if (e.defaultPrevented) return;
    // A form that must be confirmed first (data-confirm: class.js asks; data-guard: the page's own script asks and then sets data-guard-ok)
    // is left alone here — the dialog opens, and the page's script sends it afterwards with form.submit() / requestSubmit(), which comes back through this path.
    var f = e.target;
    if (f.hasAttribute && ((f.hasAttribute("data-confirm") && !f.dataset.confirmed) || (f.hasAttribute("data-guard") && !f.dataset.guardOk))) return;
    if (send(e.target, e.submitter)) { e.preventDefault(); }
  }, false);
  // form.submit() (used after confirm dialogs) does not fire "submit": route it through the same path
  const nativeSubmit = HTMLFormElement.prototype.submit;
  HTMLFormElement.prototype.submit = function () {
    if (!send(this, null)) nativeSubmit.call(this);
  };

  // ---------- Back / forward ----------
  window.addEventListener("popstate", function () {
    const url = new URL(location.href);
    if (eligible(url)) go(url.href, { noHistory: true, noScroll: false }); else location.reload();
  });
  history.replaceState({ sc: 1 }, "", location.href);
})();

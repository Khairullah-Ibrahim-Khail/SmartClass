/* Lists that search and filter while you type, and "records per page" on every paged list.
   A form with data-live (and data-no-ajax) drives the results area marked data-live-results: after a short pause only that area is replaced,
   so the cursor stays in the box and the page does not jump. The address is kept up to date (typing replaces it; tabs, pages and sizes add a step).
   Without JavaScript the form still works with the Search button. */
(function () {
    var ctrl = null, timer = null;
    function boxes() { return Array.prototype.slice.call(document.querySelectorAll('[data-live-results]')); }
    function inBoxes(el) { return boxes().some(function (b) { return b.contains(el); }); }
    function load(url, mode) {
        var bs = boxes(); if (!bs.length) { location.href = url; return; }
        if (ctrl) ctrl.abort(); ctrl = new AbortController(); bs.forEach(function (b) { b.classList.add('is-loading'); });
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' }, signal: ctrl.signal })
            .then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
            .then(function (html) {
                var fresh = new DOMParser().parseFromString(html, 'text/html').querySelectorAll('[data-live-results]');
                if (fresh.length !== bs.length) { location.href = url; return; }   // the page changed shape: do a normal visit
                bs.forEach(function (b, i) { b.innerHTML = fresh[i].innerHTML; b.classList.remove('is-loading'); });
                if (mode === 'push') history.pushState({ sc: 1 }, '', url); else history.replaceState({ sc: 1 }, '', url);
            })
            .catch(function (e) { if (e.name !== 'AbortError') { bs.forEach(function (b) { b.classList.remove('is-loading'); }); location.href = url; } });
    }
    /* The address of the list as the form shows it now: what is already in the address stays (tab, size), the form's own fields replace theirs, the page number resets. */
    function urlFor(form) {
        var u = new URL(location.href);
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return;
            var v = (el.value || '').trim(); if (v === '') u.searchParams.delete(el.name); else u.searchParams.set(el.name, v);
        });
        u.searchParams.delete('p'); return u.toString();
    }
    function sameList(a) { return a.origin === location.origin && a.pathname === location.pathname; }

    document.addEventListener('input', function (e) {
        var f = e.target.form; if (!f || !f.hasAttribute('data-live') || !/^(search|text)$/.test(e.target.type)) return;
        clearTimeout(timer); timer = setTimeout(function () { load(urlFor(f), 'replace'); }, 320);
    });
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.classList && t.classList.contains('pager-per')) {
            var u = new URL(location.href); u.searchParams.set('per', t.value); u.searchParams.delete('p');
            if (inBoxes(t)) load(u.toString(), 'push'); else { var a = document.createElement('a'); a.href = u.toString(); document.body.appendChild(a); a.click(); a.remove(); }
            return;
        }
        var f = t.form; if (f && f.hasAttribute('data-live') && t.tagName === 'SELECT') load(urlFor(f), 'push');
    });
    document.addEventListener('submit', function (e) {
        var f = e.target; if (!f.hasAttribute || !f.hasAttribute('data-live')) return;
        e.preventDefault(); clearTimeout(timer); load(urlFor(f), 'push');
    });
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href]');
        if (!a || !inBoxes(a) || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 || a.target) return;
        var u = new URL(a.href, location.href); if (!sameList(u)) return;
        e.preventDefault(); load(u.toString(), 'push');
    });
})();

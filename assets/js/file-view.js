/* "View" on a submitted file: PDFs, pictures and text open in a viewer inside the page (Download stays beside it; Esc, × or a click outside closes).
   Without JavaScript the link opens the file in a new tab. */
(function () {
    var layer = null, prev = null;
    function close() { if (!layer) return; layer.remove(); layer = null; document.removeEventListener('keydown', onKey, true); if (prev && prev.focus) prev.focus(); }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); } }
    function open(a) {
        close(); prev = document.activeElement;
        var kind = a.getAttribute('data-file-view'), url = a.href, name = a.getAttribute('data-name') || '';
        layer = document.createElement('div'); layer.className = 'file-view'; layer.setAttribute('role', 'dialog'); layer.setAttribute('aria-modal', 'true'); layer.setAttribute('aria-label', name || 'File');
        var bar = document.createElement('div'); bar.className = 'file-view-bar';
        var t = document.createElement('strong'); t.textContent = name; bar.appendChild(t);
        var dl = document.createElement('a'); dl.className = 'btn-app btn-sm btn-outline'; dl.href = a.getAttribute('data-download') || url; dl.innerHTML = '<i class="fas fa-download"></i> ' + (window.__scDownloadLabel || 'Download'); bar.appendChild(dl);
        var nt = document.createElement('a'); nt.className = 'btn-app btn-sm btn-outline'; nt.href = url; nt.target = '_blank'; nt.rel = 'noopener'; nt.innerHTML = '<i class="fas fa-up-right-from-square"></i> ' + (window.__scNewTabLabel || 'Open in a new tab'); bar.appendChild(nt);
        var x = document.createElement('button'); x.type = 'button'; x.className = 'file-view-close'; x.setAttribute('aria-label', 'Close'); x.innerHTML = '&times;'; bar.appendChild(x);
        var body = document.createElement('div'); body.className = 'file-view-body';
        if (kind === 'image') { var im = document.createElement('img'); im.src = url; im.alt = name; body.appendChild(im); }
        else { var fr = document.createElement('iframe'); fr.src = url; fr.title = name; fr.setAttribute('referrerpolicy', 'no-referrer'); body.appendChild(fr); }
        layer.appendChild(bar); layer.appendChild(body);
        layer.addEventListener('click', function (e) { if (e.target === layer || e.target === body) close(); });
        x.addEventListener('click', close);
        document.body.appendChild(layer); document.addEventListener('keydown', onKey, true); x.focus();
    }
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[data-file-view]');
        if (!a || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
        e.preventDefault(); open(a);
    });
})();

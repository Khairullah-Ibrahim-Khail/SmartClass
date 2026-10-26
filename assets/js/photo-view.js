/* Click a person's picture to see it large (like WhatsApp / Facebook): full-size photo over a dark layer; Esc, the × button or a click outside closes it.
   Pictures inside links, buttons or list options keep their own click. Works after in-page navigation (delegated). */
(function () {
    var layer = null, prevFocus = null;
    function close() { if (!layer) return; layer.remove(); layer = null; document.removeEventListener('keydown', onKey, true); if (prevFocus && prevFocus.focus) prevFocus.focus(); }
    function onKey(e) { if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); } }
    function open(img) {
        close(); prevFocus = document.activeElement;
        var src = img.currentSrc || img.src; src = src.replace(/([?&])s=\d+&?/, '$1').replace(/[?&]$/, '');   // the original, not the thumbnail
        layer = document.createElement('div'); layer.className = 'photo-view'; layer.setAttribute('role', 'dialog'); layer.setAttribute('aria-modal', 'true'); layer.setAttribute('aria-label', img.alt || 'Photo');
        var big = document.createElement('img'); big.src = src; big.alt = img.alt || '';
        var x = document.createElement('button'); x.type = 'button'; x.className = 'photo-view-close'; x.setAttribute('aria-label', 'Close'); x.innerHTML = '&times;';
        layer.appendChild(big); layer.appendChild(x);
        if (img.alt) { var cap = document.createElement('div'); cap.className = 'photo-view-cap'; cap.textContent = img.alt; layer.appendChild(cap); }
        layer.addEventListener('click', function (e) { if (e.target !== big) close(); });
        document.body.appendChild(layer); document.addEventListener('keydown', onKey, true); x.focus();
    }
    document.addEventListener('click', function (e) {
        var img = e.target.closest && e.target.closest('img.avatar-img, img.zoomable, img.req-photo, img.review-photo');
        if (!img || img.closest('a, button, [role="option"], label')) return;
        e.preventDefault(); open(img);
    });
})();

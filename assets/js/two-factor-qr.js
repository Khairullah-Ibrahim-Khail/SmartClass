/* Draws the two-step setup QR code in the browser (library served from this server — the setup key never leaves the page).
   Reads the otpauth:// address from #tfQr[data-uri]; if anything fails the typed key beside it still works. */
(function () {
    function draw() {
        var box = document.getElementById('tfQr');
        if (!box || box.dataset.done || typeof qrcode !== 'function') return;
        try {
            var q = qrcode(0, 'M'); q.addData(box.dataset.uri); q.make();
            box.innerHTML = q.createSvgTag({ cellSize: 5, margin: 4, scalable: true });
            var svg = box.querySelector('svg'); if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', box.dataset.label || 'QR code'); }
            box.dataset.done = '1';
        } catch (e) { box.hidden = true; }
    }
    document.addEventListener('DOMContentLoaded', draw); window.addEventListener('sc:navigated', draw); draw();
})();

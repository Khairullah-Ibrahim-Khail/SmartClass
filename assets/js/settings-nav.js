/* Settings search: type to list matching settings from every group (arrow keys + Enter, Esc closes). The tabs and pills work without this script. */
(function () {
    function init() {
        var box = document.getElementById('snFind'), out = document.getElementById('snResults'), all = document.getElementById('snAll');
        if (!box || !out || !all || box.dataset.ready) return; box.dataset.ready = '1';
        var items = Array.prototype.slice.call(all.querySelectorAll('a')), sel = -1;
        function render() {
            var q = box.value.trim().toLowerCase(); sel = -1;
            if (!q) { out.hidden = true; out.innerHTML = ''; return; }
            var hits = items.filter(function (a) { return a.dataset.find.indexOf(q) !== -1; }).slice(0, 8);
            out.innerHTML = hits.length ? hits.map(function (a) { return '<a href="' + a.getAttribute('href') + '" class="sn-hit"><i class="fas ' + a.dataset.icon + '"></i><span>' + a.dataset.label.replace(/</g, '&lt;') + '</span><small>' + a.dataset.group.replace(/</g, '&lt;') + '</small></a>'; }).join('') : '<p class="sn-none">' + (box.getAttribute('data-none') || 'No setting matches.') + '</p>';
            out.hidden = false;
        }
        function mark() { var h = out.querySelectorAll('.sn-hit'); h.forEach(function (x, i) { x.classList.toggle('on', i === sel); }); }
        box.addEventListener('input', render);
        box.addEventListener('keydown', function (e) {
            var h = out.querySelectorAll('.sn-hit');
            if (e.key === 'Escape') { box.value = ''; render(); }
            else if (e.key === 'ArrowDown' && h.length) { e.preventDefault(); sel = (sel + 1) % h.length; mark(); }
            else if (e.key === 'ArrowUp' && h.length) { e.preventDefault(); sel = (sel - 1 + h.length) % h.length; mark(); }
            else if (e.key === 'Enter' && h.length) { e.preventDefault(); (h[sel >= 0 ? sel : 0]).click(); }
        });
        document.addEventListener('click', function (e) { if (!e.target.closest('.sn-find')) { out.hidden = true; } });
        box.addEventListener('focus', render);
    }
    document.addEventListener('DOMContentLoaded', init); window.addEventListener('sc:navigated', init); init();
})();

/* Attendance › Edit a student: each day is its own small form. Save stays off until something changed; changing an existing mark
 * asks first and shows "Present → Absent". Reason shows for Late / Absent, minutes for Late. #attEdit carries data-labels (screen language). */
(function () {
  var box = document.getElementById('attEdit'); if (!box) return;
  var L = {}; try { L = JSON.parse(box.dataset.labels || '{}'); } catch (e) {}
  var who = (box.querySelector('.card-head h2') || {}).textContent || '';
  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  function chosen(f) { var r = f.querySelector('input[type=radio]:checked'); return r ? r.value : ''; }
  function labelOf(f) { var r = f.querySelector('input[type=radio]:checked'); return r ? r.closest('label').querySelector('span').textContent : (L.notMarked || '—'); }
  function state(f) {
    var st = chosen(f), q = function (s) { var el = f.querySelector(s); return el ? el.value.trim() : ''; };
    return st + '|' + (st === 'late' || st === 'absent' ? q('.att-reason') : '') + '|' + (st === 'late' ? q('.att-late-min') : '') + '|' + q('.att-note');
  }
  box.querySelectorAll('.att-edit-form').forEach(function (f) {
    var orig = state(f), origLabel = labelOf(f), btn = f.querySelector('button[type=submit]'), has = f.dataset.has === '1', go = false;
    function refresh() {
      var st = chosen(f), rs = f.querySelector('.att-reason'), lm = f.querySelector('.att-late-min');
      if (rs) rs.style.display = (st === 'late' || st === 'absent') ? '' : 'none';
      if (lm) lm.style.display = st === 'late' ? '' : 'none';
      var dirty = state(f) !== orig && st !== '';
      f.classList.toggle('dirty', dirty);
      if (btn) btn.disabled = !dirty;
    }
    f.addEventListener('change', refresh); f.addEventListener('input', refresh); refresh();
    f.addEventListener('submit', function (e) {
      if (go || !has) return;
      e.preventDefault(); e.stopImmediatePropagation();
      Swal.fire({ icon: 'question', titleText: L.confirmTitle, html: '<p><strong>' + esc(who) + '</strong> — ' + esc(f.dataset.date) + '</p><p>' + esc(origLabel) + ' → <strong>' + esc(labelOf(f)) + '</strong></p>',
        showCancelButton: true, confirmButtonText: L.confirmButton, cancelButtonText: L.cancelButton, confirmButtonColor: '#4361ee' }).then(function (r) {
        if (r.isConfirmed) { go = true; f.dataset.guardOk = '1'; if (f.requestSubmit) f.requestSubmit(); else f.submit(); }
      });
    }, true);
  });
})();

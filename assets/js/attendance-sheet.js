/* Attendance sheet — safe editing. #attendanceSheet carries data-editing ("1" when the day was already saved) and data-labels (JSON, already in the screen language).
 *   - a row that differs from what was loaded gets the "edited" mark; the Save button counts the changes
 *   - saving an already-marked day first shows what will change ("Ahmed Khan: Present → Absent"); nothing changed → says so, no save
 *   - leaving the page with unsaved changes asks first
 *   - "Find a student" hides rows by name (hidden rows are still saved) */
(function () {
  var sheet = document.getElementById('attendanceSheet'); if (!sheet) return;
  var L = {}; try { L = JSON.parse(sheet.dataset.labels || '{}'); } catch (e) {}
  var editing = sheet.dataset.editing === '1', submitting = false;
  var rows = Array.prototype.slice.call(sheet.querySelectorAll('.att-row'));
  function stateOf(row) {
    var r = row.querySelector('input[type=radio]:checked'), q = function (s) { var el = row.querySelector(s); return el ? el.value.trim() : ''; };
    var st = r ? r.value : '';
    return st + '|' + (st === 'late' || st === 'absent' ? q('.att-reason') : '') + '|' + (st === 'late' ? q('.att-late-min') : '') + '|' + q('.att-note');
  }
  function label(row) { var r = row.querySelector('input[type=radio]:checked'); return r ? (r.closest('label').querySelector('span') || {}).textContent : (L.notMarked || '—'); }
  rows.forEach(function (row) { row.dataset.orig = stateOf(row); row.dataset.origLabel = label(row); });

  function changed() { return rows.filter(function (row) { return stateOf(row) !== row.dataset.orig; }); }
  var btn = document.getElementById('attSave'), btnText = btn && btn.querySelector('.att-save-text'), base = btnText ? btnText.textContent : '';
  function refresh() {
    var ch = changed();
    rows.forEach(function (row) { row.classList.toggle('edited', editing && ch.indexOf(row) !== -1); });
    if (btnText) btnText.textContent = editing && ch.length ? (L.saveChanges || base) + ' (' + ch.length + ')' : base;
    var note = document.getElementById('attChangedNote'); if (note) { note.hidden = !(editing && ch.length); note.textContent = ch.length + ' ' + (L.changed || ''); }
    return ch;
  }
  sheet.addEventListener('change', refresh); sheet.addEventListener('input', refresh);
  ['markAllPresent', 'copyPrev'].forEach(function (id) { var b = document.getElementById(id); if (b) b.addEventListener('click', function () { setTimeout(refresh, 0); }); });
  refresh();

  sheet.addEventListener('submit', function (e) {
    if (submitting || !editing) { submitting = true; sheet.dataset.guardOk = '1'; return; }
    var ch = changed();
    e.preventDefault(); e.stopImmediatePropagation();
    if (!ch.length) { Swal.fire({ icon: 'info', titleText: L.nothingTitle, text: L.nothingText, confirmButtonColor: '#4361ee' }); return; }
    var list = ch.slice(0, 10).map(function (row) {
      var n = row.querySelector('.att-name strong'), esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
      return '<li><strong>' + esc(n ? n.textContent : '') + '</strong>: ' + esc(row.dataset.origLabel) + ' → ' + esc(label(row)) + '</li>';
    }).join('') + (ch.length > 10 ? '<li>… +' + (ch.length - 10) + '</li>' : '');
    Swal.fire({ icon: 'question', titleText: L.confirmTitle, html: '<ul class="att-change-list">' + list + '</ul>', showCancelButton: true,
      confirmButtonText: L.confirmButton, cancelButtonText: L.cancelButton, confirmButtonColor: '#4361ee' }).then(function (r) {
      if (r.isConfirmed) { submitting = true; sheet.dataset.guardOk = '1'; if (sheet.requestSubmit) sheet.requestSubmit(); else sheet.submit(); }
    });
  }, true);

  window.addEventListener('beforeunload', function (e) {
    if (submitting) return;
    if (editing ? changed().length : rows.some(function (row) { return stateOf(row) !== row.dataset.orig; })) { e.preventDefault(); e.returnValue = ''; }
  });

  var find = document.getElementById('attFind');
  if (find) find.addEventListener('input', function () {
    var q = find.value.trim().toLowerCase();
    rows.forEach(function (row) { var n = (row.querySelector('.att-name strong') || {}).textContent || ''; row.hidden = q !== '' && n.toLowerCase().indexOf(q) === -1; });
  });
  var want = new URLSearchParams(location.search).get('student');   // opened from a student's page: jump to that student
  if (want) { var tgt = sheet.querySelector('.att-row[data-student="' + CSS.escape(want) + '"]'); if (tgt) { tgt.classList.add('att-focus'); tgt.scrollIntoView({ block: 'center' }); } }
})();

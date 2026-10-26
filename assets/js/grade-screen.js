/* Grading screen (pages/assignments/grade.php): find a student in the list, J / K move between students, Ctrl+Enter saves and goes on,
   rubric points add up to the marks, "Save" (stay) vs "Save & next". Nothing here changes data by itself — the teacher always presses a button. */
(function () {
  var find = document.getElementById('gradeFind'), list = document.getElementById('gradeStudents');
  if (find && list) find.addEventListener('input', function () {
    var q = find.value.trim().toLowerCase();
    list.querySelectorAll('.grade-student').forEach(function (a) { a.hidden = q !== '' && a.dataset.name.indexOf(q) === -1; });
  });

  var form = document.getElementById('gradeForm'), then = document.getElementById('gradeThen');
  var marks = document.getElementById('gradeMarks');
  if (form) {
    document.getElementById('saveStay').addEventListener('click', function () { then.value = 'stay'; });
    document.getElementById('saveNext').addEventListener('click', function () { then.value = 'next'; });
    form.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); then.value = 'next'; form.requestSubmit(document.getElementById('saveNext')); }
    });
    // rubric: the points of the criteria add up to the marks
    var pts = Array.prototype.slice.call(document.querySelectorAll('.rub-pt'));
    function sum() {
      var t = 0, any = false;
      pts.forEach(function (p) { if (p.value !== '') { any = true; t += Math.min(parseFloat(p.value) || 0, parseFloat(p.dataset.max)); } });
      if (any && marks) marks.value = String(Math.round(t * 100) / 100);
    }
    pts.forEach(function (p) { p.addEventListener('input', sum); });
    if (marks) marks.addEventListener('input', function () { marks.classList.remove('is-invalid'); });
  }

  // J / K: next / previous student (not while typing)
  document.addEventListener('keydown', function (e) {
    if (e.ctrlKey || e.metaKey || e.altKey) return;
    var t = e.target; if (t && /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
    var a = e.key === 'j' || e.key === 'J' ? document.getElementById('gradeNext') : (e.key === 'k' || e.key === 'K' ? document.getElementById('gradePrev') : null);
    if (a && a.getAttribute('href')) { e.preventDefault(); a.click(); }
  });
})();

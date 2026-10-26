/* Assignment list: the group chips and the search box only show / hide what is already on the page. */
(function () {
  var find = document.getElementById('asgFind');   // the teacher's student table: hide rows that do not match
  if (find) find.addEventListener('input', function () {
    var q = find.value.trim().toLowerCase();
    document.querySelectorAll('#asgTable tbody tr[data-name]').forEach(function (r) { r.hidden = q !== '' && r.dataset.name.indexOf(q) === -1; });
  });
  var chips = document.getElementById('asgGroups'), box = document.getElementById('asgSearch');
  if (!chips && !box) return;
  var group = 'all';
  function apply() {
    var q = box ? box.value.trim().toLowerCase() : '', any = false;
    document.querySelectorAll('.asg-group').forEach(function (g) {
      var show = group === 'all' || g.dataset.g === group, seen = false;
      g.querySelectorAll('.asg-card-row').forEach(function (r) { var m = show && (q === '' || r.dataset.find.indexOf(q) !== -1); r.hidden = !m; if (m) seen = true; });
      g.hidden = !seen; if (seen) any = true;
    });
    var none = document.getElementById('asgNone'); if (none) none.hidden = any;
  }
  if (chips) chips.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-g]'); if (!a) return; e.preventDefault();
    group = a.dataset.g; chips.querySelectorAll('a').forEach(function (x) { x.classList.toggle('on', x === a); }); apply();
  });
  if (box) box.addEventListener('input', apply);
})();

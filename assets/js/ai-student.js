/* A student's AI writing help (only where the class teacher switched it on): "Check my language" fixes spelling and grammar of the student's OWN text
   (the old text is kept for Undo), "Give me hints" shows pointers under the box. It never writes the answer. Everything from the server goes in with textContent. */
(function () {
  var csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  var self = document.querySelector("script[src*='ai-student.js']");
  var URL_ = self ? self.src.replace(/assets\/js\/ai-student\.js.*$/, 'pages/ai/student_assist.php') : 'pages/ai/student_assist.php';
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('[data-sai]'); if (!btn || btn.disabled) return;
    var row = btn.closest('.sai-row'); var box = row && document.querySelector(row.dataset.target); if (!box) return;
    var task = btn.dataset.sai, before = box.value, out = row.querySelector('.sai-out'), label = btn.textContent;
    var body = new URLSearchParams({ task: task, class_id: row.dataset.class, text: before }); if (row.dataset.assignment) body.set('assignment_id', row.dataset.assignment);
    btn.disabled = true; btn.textContent = 'Thinking…'; out.hidden = true;
    fetch(URL_, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || 'No answer.');
        out.replaceChildren(); out.hidden = false; out.className = 'sai-out';
        if (task === 'check') {
          box.value = d.text; box.dispatchEvent(new Event('input', { bubbles: true }));
          var p = document.createElement('span'); p.textContent = 'Language checked — read it.';
          var u = document.createElement('button'); u.type = 'button'; u.className = 'link-btn'; u.textContent = 'Undo';
          u.addEventListener('click', function () { box.value = before; box.dispatchEvent(new Event('input', { bubbles: true })); out.hidden = true; });
          out.appendChild(p); out.appendChild(u);
        } else {
          var ul = document.createElement('ul'); d.text.split(/\n+/).forEach(function (l) { l = l.replace(/^\s*[-•*]\s*/, '').trim(); if (l) { var li = document.createElement('li'); li.textContent = l; ul.appendChild(li); } });
          out.appendChild(ul);
        }
      })
      .catch(function (err) { out.replaceChildren(); out.hidden = false; out.className = 'sai-out is-error'; out.textContent = err.message || 'The AI could not help right now.'; })
      .finally(function () { btn.disabled = false; btn.textContent = label; });
  });
})();

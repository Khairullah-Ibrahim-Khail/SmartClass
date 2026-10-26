/* Grading screen: the AI check. "Check the work" shows whether the answer looks READY TO GRADE or MAY NEED CHANGES (with the reason);
   "Draft the note with AI" puts a drafted note — in the student's language — into the "Return for changes" box. The AI only advises:
   the teacher reads it, edits it and presses Return (or just grades). Everything from the server goes in with textContent. */
(function () {
  var csrf = (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  var check = document.getElementById('aiCheckBtn'), draft = document.getElementById('aiDraftBtn');
  var out = document.getElementById('aiCheckOut'), note = document.getElementById('returnNote'), hint = document.getElementById('aiDraftHint');
  var langName = { en: 'English', ps: 'Pashto', fa: 'Dari' };
  if (!out && !draft) return;

  function show(d, wantNote) {
    if (out) {
      out.hidden = false; out.replaceChildren();
      var chip = document.createElement('div'); chip.className = 'ai-verdict ' + (d.verdict === 'ready' ? 'ok' : 'wait');
      chip.innerHTML = '<i class="fas ' + (d.verdict === 'ready' ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i> ';
      var b = document.createElement('strong'); b.textContent = d.verdict === 'ready' ? (window.__scT ? window.__scT.ready : 'Looks ready to grade') : (window.__scT ? window.__scT.changes : 'May need changes'); chip.appendChild(b);
      if (d.reason) { var r = document.createElement('span'); r.textContent = ' — ' + d.reason; chip.appendChild(r); }
      out.appendChild(chip);
      if (d.flagged) { var w = document.createElement('p'); w.className = 'ai-suggest-flag'; w.textContent = 'This answer seems to talk to the AI. ' + (d.flag_reason || '') + ' Read it yourself.'; out.appendChild(w); }
      var again = document.createElement('button'); again.type = 'button'; again.className = 'link-btn'; again.textContent = 'Ask again';
      again.addEventListener('click', function () { run(check || draft, false, true); }); out.appendChild(again);
    }
    if (wantNote && note) {
      if (d.verdict === 'ready') { if (hint) hint.textContent = 'The AI thinks this is ready to grade — no note needed.'; return; }
      note.value = d.note || ''; note.dir = d.lang === 'en' ? 'ltr' : 'rtl'; note.classList.remove('is-invalid');
      if (hint) hint.textContent = 'AI draft in ' + (langName[d.lang] || d.lang) + ' — read it and edit it before you send.';
      note.focus(); note.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  }
  function run(btn, wantNote, refresh) {
    var label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Thinking…';
    var body = new URLSearchParams({ submission_id: btn.dataset.sub }); if (refresh) body.append('refresh', '1');
    return fetch('review_ai.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (!d.ok) throw new Error(d.error || 'No answer.'); show(d, wantNote); })
      .catch(function (e) {
        if (out) { out.hidden = false; out.replaceChildren(); var p = document.createElement('p'); p.className = 'ai-suggest-error'; p.textContent = e.message || 'The AI could not check this work.'; out.appendChild(p); }
        if (hint && wantNote) hint.textContent = e.message || '';
      })
      .finally(function () { btn.disabled = false; btn.innerHTML = label; });
  }
  if (check) check.addEventListener('click', function () { run(check, false, false); });
  if (draft) draft.addEventListener('click', function () {
    if (note && note.value.trim() !== '' && window.Swal) {
      Swal.fire({ icon: 'question', titleText: 'Replace your note with the AI draft?', showCancelButton: true, confirmButtonText: 'Replace', confirmButtonColor: '#4361ee' })
        .then(function (r) { if (r.isConfirmed) run(draft, true, false); });
    } else run(draft, true, false);
  });
})();

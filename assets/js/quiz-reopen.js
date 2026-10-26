/**
 * SmartClass - quiz page (teacher): choose students and reactivate the quiz for them.
 *   #reopenForm   checkboxes .reopen-box, chips [data-pick=all|unfinished|none], #reopenAllBox,
 *                 "Reactivate N selected" (#reopenBtn) and "Everyone" (#reopenAll) — both ask first
 */
(function () {
  const form = document.getElementById("reopenForm");
  if (!form) return;
  const boxes = Array.prototype.slice.call(form.querySelectorAll(".reopen-box"));
  const head = document.getElementById("reopenAllBox");
  const btn = document.getElementById("reopenBtn");
  const count = document.getElementById("reopenCount");
  const scope = form.querySelector('[name=scope]');
  const noDeadline = form.querySelector('[name=no_deadline]');
  const until = form.querySelector('[name=until]');
  const keep = form.querySelector('[name=keep_attempt]');

  function refresh() {
    const n = boxes.filter(function (b) { return b.checked; }).length;
    count.textContent = n + " selected";
    btn.disabled = n === 0;
    if (head) { head.checked = n === boxes.length && n > 0; head.indeterminate = n > 0 && n < boxes.length; }
  }
  function syncDeadline() { until.disabled = noDeadline.checked; }

  form.addEventListener("change", function (e) {
    if (e.target === head) boxes.forEach(function (b) { b.checked = head.checked; });
    if (e.target === noDeadline) syncDeadline();
    refresh();
  });
  form.addEventListener("click", function (e) {
    const p = e.target.closest("[data-pick]");
    if (!p) return;
    boxes.forEach(function (b) {
      b.checked = p.dataset.pick === "all" ? true : p.dataset.pick === "unfinished" ? b.dataset.finished === "0" : false;
    });
    refresh();
  });

  function ask(text, go) {
    const body = (noDeadline.checked ? "with no deadline" : "until " + until.value.replace("T", " ")) + (keep && keep.checked
      ? ". Nobody's attempt is cleared — they just get more time — and they are notified."
      : ". Their earlier attempt is cleared (the old score stays in the history) and they are notified.");
    if (!window.Swal) { if (confirm(text + "\n" + body)) go(); return; }
    Swal.fire({ icon: "question", titleText: text, text: "They can take the quiz again " + body, showCancelButton: true, confirmButtonText: "Reactivate", confirmButtonColor: "#4361ee" })
      .then(function (r) { if (r.isConfirmed) go(); });
  }
  function submitWith(sc) { scope.value = sc; form.dataset.ok = "1"; form.submit(); }

  form.addEventListener("submit", function (e) {
    if (form.dataset.ok) return;
    e.preventDefault();
    const n = boxes.filter(function (b) { return b.checked; }).length;
    if (!n) return;
    ask("Reactivate the quiz for " + n + " student" + (n === 1 ? "" : "s") + "?", function () { submitWith("selected"); });
  });
  document.getElementById("reopenAll").addEventListener("click", function () {
    ask("Reactivate the quiz for every student?", function () { submitWith("all"); });
  });
  syncDeadline();
  refresh();
})();

/**
 * SmartClass - AI grading assistant on the assignment page (pages/assignments/view.php)
 *   .ai-suggest-btn   asks pages/assignments/suggest.php for a suggestion for that submission, fills
 *                     the grade form (marks + feedback) and shows the breakdown. The AI only suggests:
 *                     the teacher reads it, edits if needed and presses Grade / Update.
 *   #aiSuggestAll     does that for every turned-in answer that is not graded yet, one after another.
 * Everything coming back from the server is put in with textContent (never as HTML).
 */
(function () {
  const csrf = (document.querySelector("meta[name=csrf-token]") || {}).content || "";

  function fmt(n) { return String(Math.round(n * 100) / 100); }

  function suggest(btn, refresh) {
    const form = btn.closest("form.grade-form");
    const panel = form.parentElement.querySelector(".ai-suggest");
    const label = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Thinking…';
    const body = new URLSearchParams({ submission_id: btn.dataset.sub });
    if (refresh) body.append("refresh", "1");
    return fetch("suggest.php", { method: "POST", headers: { "X-CSRF-Token": csrf, "Content-Type": "application/x-www-form-urlencoded" }, body: body })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) throw new Error(d.error || "No suggestion.");
        const marks = form.querySelector("input[name=marks]");
        const feedback = form.querySelector("input[name=feedback], textarea[name=feedback]");
        marks.value = fmt(d.marks);
        feedback.value = d.feedback || "";
        marks.classList.remove("is-invalid");
        feedback.classList.remove("is-invalid");
        panel.hidden = false;
        panel.replaceChildren();
        const head = document.createElement("div");
        head.className = "ai-suggest-head";
        head.innerHTML = '<i class="fas fa-robot"></i> ';
        head.append("AI suggestion — check it, then press Grade");
        panel.appendChild(head);
        if (d.flagged) {
          const warn = document.createElement("p");
          warn.className = "ai-suggest-flag";
          warn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> ';
          warn.append("This answer seems to talk to the grader. " + (d.flag_reason || "") + " Read it yourself before accepting.");
          panel.appendChild(warn);
        }
        (d.breakdown || []).forEach(function (c) {
          const row = document.createElement("div");
          row.className = "ai-suggest-row";
          const t = document.createElement("strong");
          t.textContent = c.title + " — " + fmt(c.points) + " / " + c.max;
          row.appendChild(t);
          if (c.comment) { const s = document.createElement("span"); s.textContent = " " + c.comment; row.appendChild(s); }
          panel.appendChild(row);
        });
        const again = document.createElement("button");
        again.type = "button"; again.className = "link-btn"; again.textContent = "Ask again";
        again.addEventListener("click", function () { suggest(btn, true); });
        panel.appendChild(again);
        return true;
      })
      .catch(function (e) {
        panel.hidden = false;
        panel.replaceChildren();
        const p = document.createElement("p");
        p.className = "ai-suggest-error";
        p.textContent = e.message || "The AI could not suggest a grade.";
        panel.appendChild(p);
        return false;
      })
      .finally(function () { btn.disabled = false; btn.innerHTML = label; });
  }

  document.querySelectorAll(".ai-suggest-btn").forEach(function (btn) {
    btn.addEventListener("click", function () { suggest(btn, false); });
  });

  const all = document.getElementById("aiSuggestAll");
  if (all) {
    all.addEventListener("click", function () {
      const todo = Array.from(document.querySelectorAll(".ai-suggest-btn[data-ungraded='1']"));
      if (!todo.length) {
        Swal.fire({ icon: "info", titleText: "Nothing to suggest", text: "No typed answers are waiting for a grade.", confirmButtonColor: "#4361ee" });
        return;
      }
      Swal.fire({ icon: "question", titleText: "Suggest grades for " + todo.length + " answer" + (todo.length === 1 ? "" : "s") + "?",
                  text: "The AI fills in each grade form. Nothing is saved: you read each one and press Grade.", showCancelButton: true,
                  confirmButtonText: "Suggest", confirmButtonColor: "#4361ee" })
        .then(function (r) {
          if (!r.isConfirmed) return;
          all.disabled = true;
          let done = 0, failed = 0;
          const label = all.innerHTML;
          (function next() {
            if (!todo.length) {
              all.disabled = false; all.innerHTML = label;
              Swal.fire({ icon: failed ? "warning" : "success", titleText: done + " suggestion" + (done === 1 ? "" : "s") + " ready",
                          text: (failed ? failed + " could not be made (see the message under each). " : "") + "Review each one and press Grade to save it.", confirmButtonColor: "#4361ee" });
              return;
            }
            all.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (done + failed + 1) + " of " + (done + failed + todo.length);
            suggest(todo.shift(), false).then(function (ok) { if (ok) done++; else failed++; next(); });
          })();
        });
    });
  }
})();

/**
 * SmartClass - bulk actions on assignment / quiz pages (teachers)
 *   #bulkAll + .bulk-pick   tick submissions (checkboxes belong to #bulkGradeForm through their form attribute)
 *   #bulkGradeBtn           ask for marks + feedback in a dialog, then submit #bulkGradeForm
 *   #remindBtn              ask for an optional note, then submit #remindForm
 * The server re-checks everything (class, ids, marks); this only collects the input.
 */
(function () {
  const all = document.getElementById("bulkAll");
  const picks = Array.from(document.querySelectorAll(".bulk-pick"));
  const gradeBtn = document.getElementById("bulkGradeBtn");
  const remindBtn = document.getElementById("remindBtn");
  const count = document.getElementById("bulkCount");

  function update() {
    const n = picks.filter(function (p) { return p.checked; }).length;
    if (count) count.textContent = n ? n + " selected" : (picks.length ? "Tick submissions to grade them together" : "Nothing handed in yet");
    if (gradeBtn) gradeBtn.disabled = n === 0;
    if (all) { all.checked = n > 0 && n === picks.length; all.indeterminate = n > 0 && n < picks.length; }
  }
  if (all) all.addEventListener("change", function () { picks.forEach(function (p) { p.checked = all.checked; }); update(); });
  picks.forEach(function (p) { p.addEventListener("change", update); });
  update();

  if (gradeBtn) gradeBtn.addEventListener("click", function () {
    const max = Number(gradeBtn.dataset.max);
    const n = picks.filter(function (p) { return p.checked; }).length;
    Swal.fire({
      title: "Give the same marks to " + n + " submission" + (n === 1 ? "" : "s") + "?",
      html: '<input id="bgMarks" class="swal2-input" style="width:calc(100% - 2em);box-sizing:border-box" type="number" min="0" max="' + max + '" step="0.5" placeholder="Marks (0–' + max + ')">'
          + '<textarea id="bgFeedback" class="swal2-textarea" style="width:calc(100% - 2em);box-sizing:border-box" maxlength="1000" placeholder="Feedback for all of them (optional)"></textarea>',
      showCancelButton: true, confirmButtonText: "Grade them", confirmButtonColor: "#4361ee", focusConfirm: false,
      preConfirm: function () {
        const m = document.getElementById("bgMarks").value;
        if (m === "" || Number(m) < 0 || Number(m) > max) { Swal.showValidationMessage("Enter marks from 0 to " + max); return false; }
        return { marks: m, feedback: document.getElementById("bgFeedback").value };
      }
    }).then(function (r) {
      if (!r.isConfirmed) return;
      const f = document.getElementById("bulkGradeForm");
      f.querySelector("[name=marks]").value = r.value.marks;
      f.querySelector("[name=feedback]").value = r.value.feedback;
      f.submit();
    });
  });

  if (remindBtn) remindBtn.addEventListener("click", function () {
    const n = remindBtn.dataset.missing;
    Swal.fire({
      title: "Remind " + n + " student" + (n === "1" ? "" : "s") + "?",
      text: "They get a notification (and an email if they chose that). Anyone reminded in the last 6 hours is skipped.",
      input: "textarea", inputAttributes: { maxlength: 500, "aria-label": "Optional note" }, inputPlaceholder: "Add a note (optional)",
      showCancelButton: true, confirmButtonText: "Send reminders", confirmButtonColor: "#4361ee"
    }).then(function (r) {
      if (!r.isConfirmed) return;
      const f = document.getElementById("remindForm");
      f.querySelector("[name=note]").value = r.value || "";
      f.submit();
    });
  });
})();

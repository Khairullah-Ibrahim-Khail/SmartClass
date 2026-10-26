/**
 * SmartClass - teacher gradebook (pages/classes/grades.php)
 *   .gb-grade cell buttons   open the grade dialog for that submission (marks + feedback)
 *   .gb-sort header buttons  sort the rows by that column (td[data-sort]); the footer stays put
 *   gradebookInit(err)       err = server validation error → re-open the dialog with it inline
 */
function gradebookInit(err) {
  const dialog = document.getElementById("gradeDialog");
  const form = document.getElementById("gradeForm");
  if (!dialog || !form) return;
  const marks = document.getElementById("gdMarks");
  const feedback = document.getElementById("gdFeedback");
  let max = 0;

  function fieldError(input, message) {
    input.classList.toggle("is-invalid", !!message);
    input.closest(".form-group").querySelector(".invalid-feedback").textContent = message || "";
  }

  function open(btn, values) {
    max = Number(btn.dataset.max);
    document.getElementById("gdSub").value = btn.dataset.sub;
    document.getElementById("gradeDialogTitle").textContent = btn.dataset.marks ? "Change grade" : "Grade";
    document.getElementById("gdWhat").textContent = btn.dataset.student + " · " + btn.dataset.title;
    document.getElementById("gdOutOf").textContent = "out of " + max;
    document.getElementById("gdOpen").href = btn.dataset.url;
    marks.max = max;
    marks.value = values ? values.marks : btn.dataset.marks;
    feedback.value = values ? values.feedback : btn.dataset.feedback;
    fieldError(marks, "");
    fieldError(feedback, "");
    if (typeof dialog.showModal === "function") dialog.showModal(); else dialog.setAttribute("open", "");
    marks.focus();
    marks.select();
  }

  document.querySelectorAll(".gb-grade").forEach(function (btn) {
    btn.addEventListener("click", function () { open(btn, null); });
  });
  document.getElementById("gdClose").addEventListener("click", function () { dialog.close(); });
  dialog.addEventListener("click", function (e) { if (e.target === dialog) dialog.close(); });   // click on the backdrop
  [marks, feedback].forEach(function (input) {
    input.addEventListener("input", function () { fieldError(input, ""); });
  });

  form.addEventListener("submit", function (e) {
    const v = marks.value.trim();
    let ok = true;
    if (v === "" || isNaN(Number(v)) || Number(v) < 0 || Number(v) > max) {
      fieldError(marks, "Enter marks from 0 to " + max);
      ok = false;
    }
    if (feedback.value.length > 2000) {
      fieldError(feedback, "Feedback must be under 2,000 characters");
      ok = false;
    }
    if (!ok) e.preventDefault();
  });

  // Server-side error: re-open the dialog on the same cell with what was typed
  if (err) {
    const btn = document.querySelector('.gb-grade[data-sub="' + Number(err.sub_id) + '"]');
    if (btn) {
      open(btn, { marks: err.marks, feedback: err.feedback });
      fieldError(err.field === "feedback" ? feedback : marks, err.message);
    }
  }

  // ---------- Sort by column ----------
  const table = document.querySelector("table.gradebook");
  if (!table) return;
  const tbody = table.tBodies[0];
  table.querySelectorAll(".gb-sort").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const col = Number(btn.dataset.col);
      const th = btn.closest("th");
      const asc = th.getAttribute("aria-sort") !== "ascending";
      table.querySelectorAll("thead th").forEach(function (h) { h.setAttribute("aria-sort", "none"); });
      th.setAttribute("aria-sort", asc ? "ascending" : "descending");
      table.querySelectorAll(".gb-sort i").forEach(function (i) { i.className = "fas fa-sort"; });
      btn.querySelector("i").className = "fas " + (asc ? "fa-sort-up" : "fa-sort-down");
      const rows = Array.prototype.slice.call(tbody.rows);
      rows.sort(function (a, b) {
        const x = a.cells[col].dataset.sort, y = b.cells[col].dataset.sort;
        const r = btn.dataset.type === "num" ? Number(x) - Number(y) : x.localeCompare(y);
        return (asc ? r : -r) || a.cells[0].dataset.sort.localeCompare(b.cells[0].dataset.sort);
      });
      rows.forEach(function (row) { tbody.appendChild(row); });
    });
  });
}

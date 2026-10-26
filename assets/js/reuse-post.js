/**
 * SmartClass - "Reuse in another class" on stream posts (class owner)
 * A click on [data-reuse] asks which class (a dialog), then submits #reuseForm; the server posts a copy
 * to that class (it must be one of the teacher's own active classes) and notifies its students.
 */
(function () {
  const form = document.getElementById("reuseForm");
  if (!form) return;
  const targets = JSON.parse(form.dataset.targets || "{}");
  document.querySelectorAll("[data-reuse]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      Swal.fire({
        title: "Post this again in…", input: "select", inputOptions: targets, inputPlaceholder: "Choose a class",
        text: "A copy is posted now and the students of that class are notified.",
        showCancelButton: true, confirmButtonText: "Post it", confirmButtonColor: "#4361ee",
        inputValidator: function (v) { return v ? null : "Choose a class"; }
      }).then(function (r) {
        if (!r.isConfirmed) return;
        form.querySelector("[name=announcement_id]").value = btn.dataset.reuse;
        form.querySelector("[name=target_class_id]").value = r.value;
        form.submit();
      });
    });
  });
})();

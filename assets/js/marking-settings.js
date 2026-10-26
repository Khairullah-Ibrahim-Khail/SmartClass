/** SmartClass - Settings › Marking scheme: live total of the weights. */
(function () {
  const form = document.getElementById("markingForm");
  if (!form) return;
  const total = document.getElementById("mkTotal");
  function sum() {
    let t = 0;
    form.querySelectorAll("[data-weight]").forEach(function (i) { const v = parseFloat(i.value); if (!isNaN(v)) t += v; });
    total.textContent = Math.round(t * 100) / 100;
  }
  form.addEventListener("input", sum);
  sum();
})();

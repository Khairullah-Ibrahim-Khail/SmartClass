/**
 * SmartClass - "Report a wrong answer" under AI answers.
 *   scAiReport(answerElement, feature, classId)   adds a small "Report" button under the answer (once); the click asks for an optional note and
 *                                                 sends it to pages/ai/report.php — the school administrators review the list in Settings › AI providers.
 * feature: tutor | study | explain_code. The answer text is read from the element when the button is pressed (first 600 characters).
 */
(function () {
  const meta = document.querySelector('meta[name="app-url"]');
  const url = meta ? meta.content.replace(/\/$/, "") + "/pages/ai/report.php" : "report.php";
  const csrf = function () { return (document.querySelector("meta[name=csrf-token]") || {}).content || ""; };
  window.scAiReport = function (answerEl, feature, classId) {
    if (!answerEl || answerEl.dataset.reportable === "1") return;
    answerEl.dataset.reportable = "1";
    const b = document.createElement("button");
    b.type = "button"; b.className = "ai-report-btn"; b.innerHTML = '<i class="fas fa-flag" aria-hidden="true"></i> Report a wrong answer';
    b.addEventListener("click", function () {
      const send = function (note) {
        const body = new URLSearchParams({ feature: feature, class_id: classId || "", answer: (answerEl.textContent || "").trim().slice(0, 600), note: note || "" });
        b.disabled = true;
        fetch(url, { method: "POST", credentials: "same-origin", headers: { "X-CSRF-Token": csrf(), "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded" }, body: body })
          .then(function (r) { return r.json().catch(function () { return { ok: false, error: "Something went wrong." }; }); })
          .then(function (d) { b.textContent = d.ok ? "Reported — thank you" : (d.error || "Could not report"); if (!d.ok) b.disabled = false; })
          .catch(function () { b.disabled = false; b.textContent = "No connection — try again"; });
      };
      if (window.Swal) Swal.fire({ titleText: "Report this answer", text: "What is wrong with it? (optional)", input: "text", inputAttributes: { maxlength: 255 }, showCancelButton: true, confirmButtonText: "Send report", confirmButtonColor: "#4361ee" })
        .then(function (r) { if (r.isConfirmed) send(r.value); });
      else send("");
    });
    answerEl.insertAdjacentElement("afterend", b);
  };
})();

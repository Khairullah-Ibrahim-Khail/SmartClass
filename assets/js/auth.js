/**
 * SmartClass - Auth pages shared JS
 * - Password show/hide toggles
 * - Inline under-field validation helpers (field errors stay inline;
 *   SweetAlert2 is only used for task-level results, never field errors)
 */

// ---------- Password show / hide ----------
// One listener on the document, so the eye buttons also work on pages that were swapped in without a full reload (in-app navigation).
document.addEventListener("click", function (e) {
  const btn = e.target.closest && e.target.closest(".password-toggle");
  if (!btn) return;
  const input = document.getElementById(btn.dataset.target);
  if (!input) return;
  e.preventDefault();
  const show = input.type === "password";
  input.type = show ? "text" : "password";
  btn.setAttribute("aria-label", show ? "Hide password" : "Show password");
  const icon = btn.querySelector("i");
  if (icon) icon.className = show ? "fas fa-eye-slash" : "fas fa-eye";
  input.focus();
});
function initPasswordToggles() {}   // kept so older calls do nothing harmful

// ---------- Inline field errors ----------
function setFieldError(input, message) {
  input.classList.add("is-invalid");
  const feedback = input
    .closest(".form-floating, .form-group, .mb-3")
    ?.querySelector(".invalid-feedback");
  if (feedback) feedback.textContent = message;
}

function clearFieldError(input) {
  input.classList.remove("is-invalid");
}

// Clear a field's error as soon as the user types in it (or picks a value)
function bindLiveClear(form) {
  form.querySelectorAll("input, textarea, select").forEach(function (input) {
    ["input", "change"].forEach(function (evt) {
      input.addEventListener(evt, function () {
        clearFieldError(input);
      });
    });
  });
}

function isValidEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

document.addEventListener("DOMContentLoaded", initPasswordToggles);

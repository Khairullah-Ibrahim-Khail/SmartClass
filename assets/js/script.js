/**
 * SmartClass - Main JavaScript
 * Core functionality and utilities
 */

// ==================== DOM Ready ====================
document.addEventListener("DOMContentLoaded", function () {
  initializeApp();
});

function initializeApp() {
  initializeSidebar();
  initializeTooltips();
  initializeDropdowns();
  initializeModals();
  initializeForms();
  initializeDataTables();
}

// ==================== Sidebar ====================
function initializeSidebar() {
  const sidebar = document.getElementById("sidebar");
  const toggleBtn = document.getElementById("sidebarToggle");
  const overlay = document.querySelector(".offcanvas-overlay");

  if (toggleBtn && sidebar) {
    toggleBtn.addEventListener("click", function () {
      sidebar.classList.toggle("collapsed");
      sidebar.classList.toggle("show");
      if (overlay) {
        overlay.classList.toggle("show");
      }
    });
  }

  // Close sidebar on overlay click
  if (overlay) {
    overlay.addEventListener("click", function () {
      sidebar.classList.remove("show");
      overlay.classList.remove("show");
    });
  }

  // Handle responsive sidebar
  function handleResize() {
    if (window.innerWidth >= 992) {
      sidebar.classList.remove("show");
      if (overlay) {
        overlay.classList.remove("show");
      }
    }
  }

  window.addEventListener("resize", handleResize);
  handleResize();
}

// ==================== Tooltips ====================
function initializeTooltips() {
  // Check if Bootstrap is available
  if (typeof bootstrap !== "undefined") {
    const tooltipTriggerList = [].slice.call(
      document.querySelectorAll('[data-bs-toggle="tooltip"]'),
    );
    tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl);
    });
  }
}

// ==================== Dropdowns ====================
function initializeDropdowns() {
  // Check if Bootstrap is available
  if (typeof bootstrap !== "undefined") {
    const dropdownElementList = [].slice.call(
      document.querySelectorAll('[data-bs-toggle="dropdown"]'),
    );
    dropdownElementList.map(function (dropdownEl) {
      return new bootstrap.Dropdown(dropdownEl);
    });
  }
}

// ==================== Modals ====================
function initializeModals() {
  // Check if Bootstrap is available
  if (typeof bootstrap !== "undefined") {
    const modalElementList = [].slice.call(document.querySelectorAll(".modal"));
    modalElementList.map(function (modalEl) {
      return new bootstrap.Modal(modalEl);
    });
  }
}

// ==================== Forms ====================
function initializeForms() {
  // Auto-hide alerts after 5 seconds
  const alerts = document.querySelectorAll(".alert");
  alerts.forEach(function (alert) {
    setTimeout(function () {
      const bsAlert = new bootstrap.Alert(alert);
      bsAlert.close();
    }, 5000);
  });

  // Form validation
  const forms = document.querySelectorAll(".needs-validation");
  forms.forEach(function (form) {
    form.addEventListener(
      "submit",
      function (event) {
        if (!form.checkValidity()) {
          event.preventDefault();
          event.stopPropagation();
        }
        form.classList.add("was-validated");
      },
      false,
    );
  });

  // Confirm dialog for delete actions
  const deleteButtons = document.querySelectorAll("[data-confirm]");
  deleteButtons.forEach(function (button) {
    button.addEventListener("click", function (e) {
      const message = this.getAttribute("data-confirm") || "Are you sure?";
      if (!confirm(message)) {
        e.preventDefault();
        return false;
      }
    });
  });
}

// ==================== Data Tables ====================
function initializeDataTables() {
  // Simple table search
  const tableSearchInputs = document.querySelectorAll("[data-table-search]");
  tableSearchInputs.forEach(function (input) {
    const tableId = input.getAttribute("data-table-search");
    const table = document.getElementById(tableId);
    if (!table) return;

    input.addEventListener("keyup", function () {
      const searchText = this.value.toLowerCase();
      const rows = table.querySelectorAll("tbody tr");

      rows.forEach(function (row) {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchText) ? "" : "none";
      });
    });
  });
}

// ==================== AJAX Utilities ====================
function ajaxRequest(url, data, successCallback, errorCallback) {
  $.ajax({
    url: url,
    type: "POST",
    data: data,
    dataType: "json",
    success: successCallback,
    error:
      errorCallback ||
      function (xhr, status, error) {
        showError("An error occurred: " + error);
      },
  });
}

function submitForm(formId, url, successCallback) {
  $(formId).on("submit", function (e) {
    e.preventDefault();
    var formData = new FormData(this);

    $.ajax({
      url: url,
      type: "POST",
      data: formData,
      processData: false,
      contentType: false,
      dataType: "json",
      success: successCallback,
      error: function (xhr) {
        showError(xhr.responseJSON?.message || "An error occurred");
      },
    });
  });
}

// ==================== Toast Notifications ====================
function showToast(type, message, duration) {
  if (duration === undefined) duration = 3000;

  const toastContainer = document.getElementById("toast-container");
  if (!toastContainer) return;

  const toastId = "toast-" + Date.now();

  const toastClass =
    type === "success"
      ? "bg-success"
      : type === "error"
        ? "bg-danger"
        : type === "warning"
          ? "bg-warning"
          : "bg-info";

  const icon =
    type === "success"
      ? "check-circle"
      : type === "error"
        ? "exclamation-circle"
        : type === "warning"
          ? "exclamation-triangle"
          : "info-circle";

  const toastHtml =
    '<div id="' +
    toastId +
    '" class="toast ' +
    toastClass +
    ' text-white" role="alert">' +
    '<div class="toast-header ' +
    toastClass +
    ' text-white">' +
    '<i class="fas fa-' +
    icon +
    ' me-2"></i>' +
    '<strong class="me-auto">' +
    type.charAt(0).toUpperCase() +
    type.slice(1) +
    "</strong>" +
    '<button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>' +
    "</div>" +
    '<div class="toast-body">' +
    message +
    "</div>" +
    "</div>";

  toastContainer.insertAdjacentHTML("beforeend", toastHtml);

  const toastEl = document.getElementById(toastId);
  if (typeof bootstrap !== "undefined") {
    const toast = new bootstrap.Toast(toastEl, {
      autohide: true,
      delay: duration,
    });
    toast.show();
  }

  toastEl.addEventListener("hidden.bs.toast", function () {
    toastEl.remove();
  });
}

function showSuccess(message) {
  showToast("success", message);
}
function showError(message) {
  showToast("error", message);
}
function showWarning(message) {
  showToast("warning", message);
}
function showInfo(message) {
  showToast("info", message);
}

// ==================== Confirmation Dialog ====================
function confirmAction(message, callback) {
  if (confirm(message)) {
    callback();
  }
}

// ==================== Loading State ====================
function setLoading(button, isLoading, originalText) {
  if (isLoading) {
    button.prop("disabled", true);
    button.data("original-text", button.html());
    button.html(
      '<span class="spinner-border spinner-border-sm me-2"></span>Loading...',
    );
  } else {
    button.prop("disabled", false);
    button.html(button.data("original-text"));
  }
}

// ==================== Format Date ====================
function formatDate(dateString, format) {
  if (format === undefined) format = "MMM dd, yyyy";

  const date = new Date(dateString);
  const options = { year: "numeric", month: "short", day: "numeric" };

  return date.toLocaleDateString("en-US", options);
}

function formatDateTime(dateString) {
  const date = new Date(dateString);
  return date.toLocaleString("en-US", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

function timeAgo(dateString) {
  const date = new Date(dateString);
  const now = new Date();
  const diff = now - date;

  const seconds = Math.floor(diff / 1000);
  const minutes = Math.floor(seconds / 60);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);

  if (seconds < 60) return "Just now";
  if (minutes < 60) return minutes + " minutes ago";
  if (hours < 24) return hours + " hours ago";
  if (days < 7) return days + " days ago";

  return formatDate(dateString);
}

// ==================== Export Functions ====================
function exportToCSV(tableId, filename) {
  const table = document.getElementById(tableId);
  if (!table) return;

  let csv = [];
  const rows = table.querySelectorAll("tr");

  rows.forEach(function (row) {
    const cols = row.querySelectorAll("td, th");
    const rowData = [];
    cols.forEach(function (col) {
      let data = col.innerText.replace(/"/g, '""');
      rowData.push('"' + data + '"');
    });
    csv.push(rowData.join(","));
  });

  downloadCSV(csv.join("\n"), filename + ".csv");
}

function downloadCSV(csv, filename) {
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
  const link = document.createElement("a");
  const url = URL.createObjectURL(blob);

  link.setAttribute("href", url);
  link.setAttribute("download", filename);
  link.style.visibility = "hidden";

  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

function printPage() {
  window.print();
}

// ==================== File Upload ====================
function handleFileUpload(inputId, previewId) {
  const input = document.getElementById(inputId);
  const preview = document.getElementById(previewId);

  if (!input || !preview) return;

  input.addEventListener("change", function () {
    const file = this.files[0];
    if (!file) return;

    // Show file info
    const fileSize = formatFileSize(file.size);
    preview.innerHTML =
      '<div class="alert alert-info mb-0"><i class="fas fa-file me-2"></i>' +
      file.name +
      " (" +
      fileSize +
      ")</div>";

    // Preview image if applicable
    if (file.type.startsWith("image/")) {
      const reader = new FileReader();
      reader.onload = function (e) {
        preview.innerHTML =
          '<img src="' +
          e.target.result +
          '" class="img-fluid rounded" alt="Preview">';
      };
      reader.readAsDataURL(file);
    }
  });
}

function formatFileSize(bytes) {
  if (bytes === 0) return "0 Bytes";
  const k = 1024;
  const sizes = ["Bytes", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
}

// ==================== Character Counter ====================
function initCharCounter(inputId, counterId, maxLength) {
  const input = document.getElementById(inputId);
  const counter = document.getElementById(counterId);

  if (!input || !counter) return;

  input.addEventListener("input", function () {
    const current = this.value.length;
    counter.textContent = current + "/" + maxLength;

    if (current > maxLength) {
      counter.classList.add("text-danger");
    } else {
      counter.classList.remove("text-danger");
    }
  });
}

// ==================== Search/Filter ====================
function filterTable(tableId, searchText) {
  const table = document.getElementById(tableId);
  if (!table) return;

  const rows = table.querySelectorAll("tbody tr");
  searchText = searchText.toLowerCase();

  rows.forEach(function (row) {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(searchText) ? "" : "none";
  });
}

// ==================== URL Parameters ====================
function getUrlParam(param) {
  const urlParams = new URLSearchParams(window.location.search);
  return urlParams.get(param);
}

function setUrlParam(key, value) {
  const url = new URL(window.location);
  url.searchParams.set(key, value);
  window.history.pushState({}, "", url);
}

function removeUrlParam(key) {
  const url = new URL(window.location);
  url.searchParams.delete(key);
  window.history.pushState({}, "", url);
}

// ==================== Scroll to Top ====================
function scrollToTop() {
  window.scrollTo({ top: 0, behavior: "smooth" });
}

function initScrollToTop(buttonId) {
  const button = document.getElementById(buttonId);
  if (!button) return;

  window.addEventListener("scroll", function () {
    if (window.pageYOffset > 300) {
      button.style.display = "block";
    } else {
      button.style.display = "none";
    }
  });

  button.addEventListener("click", scrollToTop);
}

// ==================== Debounce ====================
function debounce(func, wait) {
  let timeout;
  return function executedFunction() {
    const context = this;
    const args = arguments;
    const later = function () {
      timeout = null;
      func.apply(context, args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

// ==================== Local Storage Helpers ====================
function setStorage(key, value) {
  try {
    localStorage.setItem(key, JSON.stringify(value));
    return true;
  } catch (e) {
    console.error("Error saving to localStorage:", e);
    return false;
  }
}

function getStorage(key, defaultValue) {
  try {
    const item = localStorage.getItem(key);
    return item ? JSON.parse(item) : defaultValue;
  } catch (e) {
    console.error("Error reading from localStorage:", e);
    return defaultValue;
  }
}

function removeStorage(key) {
  localStorage.removeItem(key);
}

// ==================== Session Storage ====================
function setSession(key, value) {
  try {
    sessionStorage.setItem(key, JSON.stringify(value));
    return true;
  } catch (e) {
    console.error("Error saving to sessionStorage:", e);
    return false;
  }
}

function getSession(key, defaultValue) {
  try {
    const item = sessionStorage.getItem(key);
    return item ? JSON.parse(item) : defaultValue;
  } catch (e) {
    console.error("Error reading from sessionStorage:", e);
    return defaultValue;
  }
}

// ==================== Copy to Clipboard ====================
function copyToClipboard(text, successMessage) {
  navigator.clipboard
    .writeText(text)
    .then(function () {
      if (successMessage) {
        showSuccess(successMessage);
      }
    })
    .catch(function (err) {
      console.error("Failed to copy:", err);
      showError("Failed to copy to clipboard");
    });
}

// ==================== Password Strength ====================
function checkPasswordStrength(password) {
  let strength = 0;
  let feedback = [];

  if (password.length >= 8) {
    strength += 1;
  } else {
    feedback.push("At least 8 characters");
  }

  if (/[A-Z]/.test(password)) {
    strength += 1;
  } else {
    feedback.push("One uppercase letter");
  }

  if (/[a-z]/.test(password)) {
    strength += 1;
  } else {
    feedback.push("One lowercase letter");
  }

  if (/[0-9]/.test(password)) {
    strength += 1;
  } else {
    feedback.push("One number");
  }

  if (/[^A-Za-z0-9]/.test(password)) {
    strength += 1;
  } else {
    feedback.push("One special character");
  }

  const labels = ["Very Weak", "Weak", "Fair", "Strong", "Very Strong"];
  const colors = ["danger", "warning", "info", "success", "success"];

  return {
    score: strength,
    label: labels[Math.min(strength, 4)],
    color: colors[Math.min(strength, 4)],
    feedback: feedback,
  };
}

function updatePasswordStrengthMeter(password, meterId, labelId) {
  const meter = document.getElementById(meterId);
  const label = document.getElementById(labelId);
  if (!meter || !label) return;

  const result = checkPasswordStrength(password);

  meter.style.width = result.score * 20 + "%";
  meter.className = "progress-bar bg-" + result.color;
  label.textContent = result.label;
  label.className = "text-" + result.color;
}

// ==================== Initialize Common Components ====================
document.addEventListener("DOMContentLoaded", function () {
  // Initialize scroll to top button
  initScrollToTop("scrollToTop");

  // Initialize file upload previews
  const fileInputs = document.querySelectorAll("[data-file-upload]");
  fileInputs.forEach(function (input) {
    handleFileUpload(input.id, input.getAttribute("data-file-upload"));
  });

  // Initialize character counters
  const charCounters = document.querySelectorAll("[data-char-counter]");
  charCounters.forEach(function (input) {
    initCharCounter(
      input.id,
      input.getAttribute("data-char-counter"),
      parseInt(input.getAttribute("maxlength")),
    );
  });
});

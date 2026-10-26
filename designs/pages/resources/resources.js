// DOM Elements
const form = document.getElementById("createResourceForm");
const sections = document.querySelectorAll(".form-section");
const nextButtons = document.querySelectorAll(".next-section");
const prevButtons = document.querySelectorAll(".prev-section");
const progressSteps = document.querySelectorAll(".step");
const uploadZone = document.getElementById("uploadZone");
const fileInput = document.getElementById("resourceFile");
const removeFileBtn = document.getElementById("removeFile");
const filePreview = document.getElementById("filePreview");
const fileDetailsForm = document.getElementById("fileDetailsForm");
const browseBtn = document.querySelector(".browse-btn");
const descriptionTextarea = document.getElementById("resourceDescription");
const charCount = document.getElementById("charCount");
const tagsInput = document.getElementById("resourceTags");
const tagsList = document.getElementById("tagsList");
const visibilityRadios = document.querySelectorAll('input[name="visibility"]');
const courseSelection = document.getElementById("courseSelection");
const publishBtn = document.querySelector(".publish-btn");

// Review elements
const reviewElements = {
  title: document.getElementById("reviewTitle"),
  category: document.getElementById("reviewCategory"),
  type: document.getElementById("reviewType"),
  description: document.getElementById("reviewDescription"),
  file: document.getElementById("reviewFile"),
  version: document.getElementById("reviewVersion"),
  language: document.getElementById("reviewLanguage"),
  visibility: document.getElementById("reviewVisibility"),
  courses: document.getElementById("reviewCourses"),
  releaseDate: document.getElementById("reviewReleaseDate"),
  permissions: document.getElementById("reviewPermissions"),
};

// Current section index
let currentSectionIndex = 0;

// Initialize
document.addEventListener("DOMContentLoaded", () => {
  // Show first section
  showSection(0);

  // Initialize event listeners
  initEventListeners();

  // Initialize tags
  initTags();

  // Update character count
  updateCharCount();
});

// Event Listeners
function initEventListeners() {
  // Next section buttons
  nextButtons.forEach((button) => {
    button.addEventListener("click", (e) => {
      const nextSection = e.target.dataset.next;
      if (validateCurrentSection()) {
        navigateToSection(nextSection);
      }
    });
  });

  // Previous section buttons
  prevButtons.forEach((button) => {
    button.addEventListener("click", (e) => {
      const prevSection = e.target.dataset.prev;
      navigateToSection(prevSection);
    });
  });

  // File upload
  uploadZone.addEventListener("click", () => fileInput.click());
  uploadZone.addEventListener("dragover", handleDragOver);
  uploadZone.addEventListener("dragleave", handleDragLeave);
  uploadZone.addEventListener("drop", handleDrop);
  fileInput.addEventListener("change", handleFileSelect);
  removeFileBtn.addEventListener("click", removeFile);
  browseBtn.addEventListener("click", () => fileInput.click());

  // Character count for description
  descriptionTextarea.addEventListener("input", updateCharCount);

  // Tags input
  tagsInput.addEventListener("keydown", handleTagInput);

  // Visibility radios
  visibilityRadios.forEach((radio) => {
    radio.addEventListener("change", handleVisibilityChange);
  });

  // Form submission
  form.addEventListener("submit", handleSubmit);
}

// Section Navigation
function showSection(index) {
  sections.forEach((section, i) => {
    if (i === index) {
      section.classList.add("active");
      updateProgress(i);
    } else {
      section.classList.remove("active");
    }
  });
  currentSectionIndex = index;
}

function navigateToSection(sectionId) {
  const sectionIndex = Array.from(sections).findIndex(
    (s) => s.id === sectionId
  );
  if (sectionIndex !== -1) {
    showSection(sectionIndex);
    window.scrollTo({ top: 0, behavior: "smooth" });

    // Update review section if navigating to review
    if (sectionId === "section-review") {
      updateReviewSummary();
    }
  }
}

function updateProgress(activeIndex) {
  progressSteps.forEach((step, index) => {
    if (index < activeIndex) {
      step.classList.remove("active");
      step.classList.add("completed");
    } else if (index === activeIndex) {
      step.classList.add("active");
      step.classList.remove("completed");
    } else {
      step.classList.remove("active", "completed");
    }
  });
}

// Section Validation
function validateCurrentSection() {
  const currentSection = sections[currentSectionIndex];
  const requiredFields = currentSection.querySelectorAll("[required]");
  let isValid = true;

  requiredFields.forEach((field) => {
    if (!field.value.trim()) {
      isValid = false;
      highlightError(field);
    } else {
      removeError(field);
    }
  });

  // Special validation for file section
  if (currentSection.id === "section-file") {
    const file = fileInput.files[0];
    if (!file) {
      isValid = false;
      showNotification("Please select a file to upload", "error");
    }
  }

  return isValid;
}

function highlightError(element) {
  element.style.borderColor = "#e53e3e";
  element.style.boxShadow = "0 0 0 3px rgba(229, 62, 62, 0.1)";

  // Add error message if not exists
  if (!element.nextElementSibling?.classList.contains("error-message")) {
    const errorMsg = document.createElement("div");
    errorMsg.className = "error-message";
    errorMsg.style.color = "#e53e3e";
    errorMsg.style.fontSize = "0.75rem";
    errorMsg.style.marginTop = "0.25rem";
    errorMsg.textContent = "This field is required";
    element.parentNode.insertBefore(errorMsg, element.nextSibling);
  }
}

function removeError(element) {
  element.style.borderColor = "";
  element.style.boxShadow = "";

  // Remove error message if exists
  const errorMsg = element.nextElementSibling;
  if (errorMsg?.classList.contains("error-message")) {
    errorMsg.remove();
  }
}

// File Upload Handling
function handleDragOver(e) {
  e.preventDefault();
  e.stopPropagation();
  uploadZone.classList.add("drag-over");
}

function handleDragLeave(e) {
  e.preventDefault();
  e.stopPropagation();
  uploadZone.classList.remove("drag-over");
}

function handleDrop(e) {
  e.preventDefault();
  e.stopPropagation();
  uploadZone.classList.remove("drag-over");

  const files = e.dataTransfer.files;
  if (files.length > 0) {
    handleFiles(files[0]);
  }
}

function handleFileSelect(e) {
  if (e.target.files.length > 0) {
    handleFiles(e.target.files[0]);
  }
}

function handleFiles(file) {
  // Validate file
  if (!validateFile(file)) {
    return;
  }

  // Simulate upload progress
  simulateUpload(file);

  // Show file preview
  showFilePreview(file);

  // Show file details form
  fileDetailsForm.style.display = "block";
}

function validateFile(file) {
  const maxSize = 100 * 1024 * 1024; // 100MB
  const allowedTypes = [
    "application/pdf",
    "application/msword",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    "application/vnd.ms-powerpoint",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    "application/vnd.ms-excel",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    "application/zip",
    "video/mp4",
    "image/jpeg",
    "image/png",
    "text/plain",
  ];

  if (file.size > maxSize) {
    showNotification("File size exceeds 100MB limit", "error");
    return false;
  }

  if (!allowedTypes.includes(file.type)) {
    showNotification("File type not supported", "error");
    return false;
  }

  return true;
}

function simulateUpload(file) {
  const progressFill = document.getElementById("progressFill");
  const progressText = document.getElementById("progressText");

  let progress = 0;
  const interval = setInterval(() => {
    progress += Math.random() * 10;
    if (progress >= 100) {
      progress = 100;
      clearInterval(interval);
      progressText.textContent = "Upload complete!";
      showNotification("File uploaded successfully", "success");
    } else {
      progressFill.style.width = `${progress}%`;
      progressText.textContent = `Uploading... ${Math.round(progress)}%`;
    }
  }, 200);
}

function showFilePreview(file) {
  // Update file info
  document.getElementById("fileName").textContent = file.name;
  document.getElementById("fileSize").textContent = formatFileSize(file.size);

  // Set appropriate icon
  const fileIcon = document.getElementById("fileIcon");
  fileIcon.textContent = getFileIcon(file.type);

  // Show preview
  filePreview.style.display = "block";
  uploadZone.style.display = "none";
}

function getFileIcon(mimeType) {
  const icons = {
    "application/pdf": "📄",
    "application/msword": "📝",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document":
      "📝",
    "application/vnd.ms-powerpoint": "📊",
    "application/vnd.openxmlformats-officedocument.presentationml.presentation":
      "📊",
    "application/vnd.ms-excel": "📈",
    "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": "📈",
    "application/zip": "📁",
    "video/mp4": "🎬",
    "image/jpeg": "🖼️",
    "image/png": "🖼️",
    "text/plain": "📃",
  };

  return icons[mimeType] || "📄";
}

function formatFileSize(bytes) {
  if (bytes === 0) return "0 Bytes";
  const k = 1024;
  const sizes = ["Bytes", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + " " + sizes[i];
}

function removeFile() {
  fileInput.value = "";
  filePreview.style.display = "none";
  fileDetailsForm.style.display = "none";
  uploadZone.style.display = "block";
}

// Character Count
function updateCharCount() {
  const count = descriptionTextarea.value.length;
  charCount.textContent = count;

  if (count > 900) {
    charCount.style.color = "#e53e3e";
  } else if (count > 800) {
    charCount.style.color = "#ed8936";
  } else {
    charCount.style.color = "#a0aec0";
  }
}

// Tags Management
function initTags() {
  // Load saved tags from localStorage or start empty
  const savedTags = JSON.parse(localStorage.getItem("resourceTags")) || [];
  savedTags.forEach((tag) => addTag(tag));
}

function handleTagInput(e) {
  if (e.key === "Enter") {
    e.preventDefault();
    const tag = tagsInput.value.trim();
    if (tag && !tagExists(tag)) {
      addTag(tag);
      tagsInput.value = "";
    }
  }
}

function tagExists(tag) {
  return Array.from(tagsList.querySelectorAll(".tag-text")).some(
    (tagElement) => tagElement.textContent === tag
  );
}

function addTag(tag) {
  const tagElement = document.createElement("div");
  tagElement.className = "tag";
  tagElement.innerHTML = `
        <span class="tag-text">${tag}</span>
        <button type="button" class="tag-remove" onclick="removeTag(this)">×</button>
    `;
  tagsList.appendChild(tagElement);

  // Save to localStorage
  saveTags();
}

function removeTag(button) {
  button.parentElement.remove();
  saveTags();
}

function saveTags() {
  const tags = Array.from(tagsList.querySelectorAll(".tag-text")).map(
    (span) => span.textContent
  );
  localStorage.setItem("resourceTags", JSON.stringify(tags));
}

// Visibility Settings
function handleVisibilityChange(e) {
  if (e.target.value === "selected") {
    courseSelection.style.display = "block";
  } else {
    courseSelection.style.display = "none";
  }
}

// Review Summary
function updateReviewSummary() {
  // Basic Information
  reviewElements.title.textContent =
    document.getElementById("resourceTitle").value || "-";
  reviewElements.category.textContent =
    getSelectedText("resourceCategory") || "-";
  reviewElements.type.textContent = getSelectedText("resourceType") || "-";
  reviewElements.description.textContent =
    document.getElementById("resourceDescription").value || "-";

  // File Details
  const file = fileInput.files[0];
  reviewElements.file.textContent = file ? file.name : "No file selected";
  reviewElements.version.textContent =
    document.getElementById("fileVersion").value || "1.0";
  reviewElements.language.textContent =
    getSelectedText("fileLanguage") || "English";

  // Sharing Settings
  const visibility = document.querySelector(
    'input[name="visibility"]:checked'
  )?.value;
  reviewElements.visibility.textContent = getVisibilityLabel(visibility);

  const selectedCourses = Array.from(
    document.querySelectorAll('input[name="courses[]"]:checked')
  ).map((cb) => cb.parentElement.textContent.trim());
  reviewElements.courses.textContent =
    selectedCourses.length > 0 ? selectedCourses.join(", ") : "All students";

  reviewElements.releaseDate.textContent =
    document.getElementById("releaseDate").value || "Immediately";

  const permissions = Array.from(
    document.querySelectorAll('input[name="permissions[]"]:checked')
  ).map((cb) => cb.value.replace(/-/g, " "));
  reviewElements.permissions.textContent =
    permissions.length > 0 ? permissions.join(", ") : "No permissions";
}

function getSelectedText(selectId) {
  const select = document.getElementById(selectId);
  return select.options[select.selectedIndex].text;
}

function getVisibilityLabel(value) {
  const labels = {
    public: "Public (All Students)",
    selected: "Selected Courses/Groups",
    private: "Private (Draft)",
  };
  return labels[value] || "-";
}

// Form Submission
function handleSubmit(e) {
  e.preventDefault();

  if (!validateCurrentSection()) {
    showNotification("Please fill all required fields", "error");
    return;
  }

  // Show loading state
  publishBtn.innerHTML = '<span class="btn-icon">⏳</span> Publishing...';
  publishBtn.disabled = true;

  // Simulate API call
  setTimeout(() => {
    // Success
    showNotification("Resource published successfully!", "success");

    // Reset form
    setTimeout(() => {
      window.location.href = "teacher-resources.html";
    }, 2000);
  }, 2000);
}

// Notification System
function showNotification(message, type) {
  // Remove existing notifications
  const existingNotifications = document.querySelectorAll(".notification");
  existingNotifications.forEach((notification) => notification.remove());

  // Create notification
  const notification = document.createElement("div");
  notification.className = `notification notification-${type}`;
  notification.innerHTML = `
        <span class="notification-icon">${
          type === "success" ? "✓" : "⚠️"
        }</span>
        <span class="notification-message">${message}</span>
    `;

  // Style notification
  notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        background: ${type === "success" ? "#c6f6d5" : "#fed7d7"};
        color: ${type === "success" ? "#22543d" : "#c53030"};
        display: flex;
        align-items: center;
        gap: 0.75rem;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        z-index: 10000;
        animation: slideIn 0.3s ease;
    `;

  document.body.appendChild(notification);

  // Auto remove after 5 seconds
  setTimeout(() => {
    notification.style.animation = "slideOut 0.3s ease";
    setTimeout(() => notification.remove(), 300);
  }, 5000);
}

// Add animation styles
const style = document.createElement("style");
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

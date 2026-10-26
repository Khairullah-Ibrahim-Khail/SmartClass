const menuToggle = document.querySelector("#menu-toggle");
const sidebar = document.querySelector(".sidebar");
const mainContent = document.querySelector(".main-content");

// Toggle sidebar
menuToggle.addEventListener("click", (e) => {
  e.stopPropagation();
  sidebar.classList.toggle("active");
  mainContent.classList.toggle("shifted"); // push content when sidebar opens
});

// Close sidebar when clicking outside (mobile)
document.addEventListener("click", (e) => {
  if (
    window.innerWidth <= 1024 &&
    !sidebar.contains(e.target) &&
    e.target !== menuToggle
  ) {
    sidebar.classList.remove("active");
    mainContent.classList.remove("shifted");
  }
});

// Sidebar dropdowns
const dropdownHeaders = document.querySelectorAll(".sidebar h3");
dropdownHeaders.forEach((header) => {
  header.addEventListener("click", () => {
    const dropdown = header.nextElementSibling;
    dropdown.classList.toggle("active");
    header.classList.toggle("active");
  });
});

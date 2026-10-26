document.addEventListener("DOMContentLoaded", () => {
  const mainContent = document.getElementById("main-content");
  const myClassLink = document.getElementById("nav-myclass");

  myClassLink.addEventListener("click", (e) => {
    e.preventDefault();
    loadPage("../HTML/class.html"); // adjust path if needed
  });

  function loadPage(path) {
    fetch(path)
      .then((res) => {
        if (!res.ok) throw new Error("Page not found");
        return res.text();
      })
      .then((html) => {
        mainContent.innerHTML = html;
      })
      .catch((err) => {
        console.error(err);
        mainContent.innerHTML = "<p>Failed to load page</p>";
      });
  }
});

(function () {
  window.initDashbordResearch = function () {
    function showSection(filePath, targetId) {
      const container = document.getElementById(targetId);

      if (!container) {
        console.error("Container not found:", targetId);
        return;
      }

      // Hide all sections
      document
        .querySelectorAll(".section")
        .forEach((s) => s.classList.remove("active"));

      // Show target section
      container.classList.add("active");

      // Fetch the external HTML
      fetch(filePath)
        .then((res) => {
          if (!res.ok) throw new Error(res.status);
          return res.text();
        })
        .then((html) => {
          // Parse the HTML and extract only the body content
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, "text/html");
          container.innerHTML = doc.body.innerHTML;
        })
        .catch((err) => {
          console.error("Load failed:", err);
          container.innerHTML = "<p>Failed to load content</p>";
        });
    }
  };
})();

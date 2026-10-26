/**
 * SmartClass - shared behaviour for class pages (pages/classes/*)
 *   [data-copy]                 copy text to the clipboard (data-copy-label for the toast)
 *   form[data-confirm]          SweetAlert2 confirm before submitting (title is plain text)
 *   select[data-autosubmit]     submit the form when the value changes
 *   [data-toggle-target]        show/hide the element with that id (focus its first input)
 *   textarea in .comment-form / .stream-composer / .post-edit: clear the inline error while typing
 *   Classwork: #cwSearch filters items by title; .topic-toggle collapses a topic
 *              (collapsed topics remembered per class in localStorage), #cwCollapseAll
 */
(function () {
  // One listener on the document for each of these (registered once, even when this file runs again after in-app navigation),
  // so they also work on content that is swapped in later — a live-search list, for instance. A delete button must never lose its confirmation.
  if (!window.__scClassDelegated) {
    window.__scClassDelegated = true;
    document.addEventListener("click", function (e) {
      const cp = e.target.closest && e.target.closest("[data-copy]");
      if (cp) {
        e.preventDefault();
        navigator.clipboard.writeText(cp.dataset.copy).then(function () {
          Swal.fire({ icon: "success", titleText: (cp.dataset.copyLabel || "Text") + " copied", timer: 1200, showConfirmButton: false });
        });
        return;
      }
      const tg = e.target.closest && e.target.closest("[data-toggle-target]");
      if (tg) {
        const target = document.getElementById(tg.dataset.toggleTarget);
        if (!target) return;
        target.hidden = !target.hidden;
        const input = target.querySelector("input[type=text], textarea");
        if (!target.hidden && input) input.focus();
      }
    });
    document.addEventListener("submit", function (e) {
      const form = e.target;
      if (!form.matches || !form.matches("form[data-confirm]") || form.dataset.confirmed) return;
      e.preventDefault();
      Swal.fire({
        icon: "warning",
        titleText: form.dataset.confirm,
        text: form.dataset.confirmText || "",
        showCancelButton: true,
        confirmButtonText: form.dataset.confirmButton || "Yes",
        confirmButtonColor: "#ef476f",
      }).then(function (r) {
        if (r.isConfirmed) {
          form.dataset.confirmed = "1";
          form.submit();
        }
      });
    });
    document.addEventListener("change", function (e) {
      const sel = e.target;
      if (sel.matches && sel.matches("select[data-autosubmit]") && sel.form && !sel.form.hasAttribute("data-live")) sel.form.submit();
    });
  }

  document.querySelectorAll(".stream-composer textarea, .comment-form textarea, .post-edit textarea").forEach(function (t) {
    t.addEventListener("input", function () { t.classList.remove("is-invalid"); });
  });
  // ---------- Classwork: collapsible topics + search ----------
  const tools = document.querySelector(".cw-tools");
  const storeKey = tools ? "sc-collapsed-" + tools.dataset.classId : null;
  function loadCollapsed() {
    try { return JSON.parse(localStorage.getItem(storeKey) || "[]"); } catch (e) { return []; }
  }
  function saveCollapsed() {
    const ids = [];
    document.querySelectorAll(".topic-toggle[aria-expanded=false]").forEach(function (b) { ids.push(b.closest(".topic-group").dataset.topic); });
    try { localStorage.setItem(storeKey, JSON.stringify(ids)); } catch (e) { /* private mode: not remembered */ }
  }
  function setTopic(btn, open) {
    btn.setAttribute("aria-expanded", open ? "true" : "false");
    const body = document.getElementById(btn.getAttribute("aria-controls"));
    if (body) body.hidden = !open;
  }
  function syncCollapseAll() {
    const all = document.getElementById("cwCollapseAll");
    if (!all) return;
    const anyOpen = document.querySelector(".topic-toggle[aria-expanded=true]");
    all.textContent = anyOpen ? all.dataset.collapseLabel : all.dataset.expandLabel;
  }
  const toggles = document.querySelectorAll(".topic-toggle");
  if (storeKey) {
    const collapsed = loadCollapsed();
    toggles.forEach(function (b) { if (collapsed.indexOf(b.closest(".topic-group").dataset.topic) !== -1) setTopic(b, false); });
  }
  toggles.forEach(function (b) {
    b.addEventListener("click", function () {
      setTopic(b, b.getAttribute("aria-expanded") !== "true");
      if (storeKey) saveCollapsed();
      syncCollapseAll();
    });
  });
  const collapseAll = document.getElementById("cwCollapseAll");
  if (collapseAll) {
    syncCollapseAll();
    collapseAll.addEventListener("click", function () {
      const open = !document.querySelector(".topic-toggle[aria-expanded=true]");
      toggles.forEach(function (b) { setTopic(b, open); });
      if (storeKey) saveCollapsed();
      syncCollapseAll();
    });
  }

  const search = document.getElementById("cwSearch");
  if (search) {
    search.addEventListener("input", function () {
      const q = search.value.trim().toLowerCase();
      let shown = 0;
      document.querySelectorAll(".topic-group").forEach(function (group) {
        let groupShown = 0;
        group.querySelectorAll(".classwork-item").forEach(function (item) {
          const title = (item.querySelector(".classwork-title") || item).textContent.toLowerCase();
          const match = !q || title.indexOf(q) !== -1;
          item.hidden = !match;
          if (match) groupShown++;
        });
        shown += groupShown;
        // While searching, show matching topics even if collapsed; hide topics with no match
        const body = group.querySelector(".topic-body");
        const btn = group.querySelector(".topic-toggle");
        if (q) {
          group.hidden = groupShown === 0;
          if (body) body.hidden = false;
        } else {
          group.hidden = false;
          if (body && btn) body.hidden = btn.getAttribute("aria-expanded") !== "true";
        }
      });
      document.querySelectorAll(".due-row").forEach(function (row) {
        row.hidden = q && row.textContent.toLowerCase().indexOf(q) === -1;
      });
      const none = document.getElementById("cwNoMatch");
      if (none) none.hidden = !q || shown > 0;
    });
  }
})();

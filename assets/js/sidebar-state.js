/**
 * SmartClass - sidebar position between pages (after dashboard.js).
 * The section of the current page is opened, and the scroll position restored, by the small
 * script right after the sidebar in includes/app_header.php — before the first paint, so
 * nothing jumps. Here: remember the scroll position for the next page (sessionStorage, this
 * tab only), and switch the open/close animation back on once the page is shown.
 */
(function () {
  const KEY = "sc-sidebar-scroll";
  const nav = document.querySelector(".sidebar-nav");
  if (!nav) return;

  const remember = function () {
    try { sessionStorage.setItem(KEY, String(nav.scrollTop)); } catch (e) { /* storage blocked */ }
  };
  nav.addEventListener("click", remember, true);   // before the link navigates
  window.addEventListener("pagehide", remember);

  // The pre-opened section was shown without animation; later clicks animate as usual
  requestAnimationFrame(function () {
    requestAnimationFrame(function () {
      nav.querySelectorAll(".dropdown-content").forEach(function (d) { d.style.transition = ""; });
    });
  });
})();

          <?php if (!empty($settings_page)) echo '</div></div>'; ?>
          </div>
        </main>

        <footer role="contentinfo">
          <div class="footer-content">
            <p>&copy; <?php echo date('Y'); ?> Smart Class Management System. All rights reserved.</p>
            <div class="footer-links">
              <a href="<?php echo APP_URL; ?>/pages/dashboard.php">Dashboard</a>
              <a href="<?php echo APP_URL; ?>/pages/classes/my-classes.php">My Classes</a>
            </div>
          </div>
        </footer>
      </div>
    </div>

    <div id="soonToast" class="soon-toast" role="status"></div>

    <script src="<?php echo asset('assets/js/dashboard.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/sidebar-state.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/theme.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/pdf-export.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/ajax-nav.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/a11y.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/ai-report.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/ai-stream.js'); ?>"></script>
    <script src="<?php echo asset('assets/js/diagram.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/search.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/photo-view.js'); ?>" defer></script>
    <script src="<?php echo asset('assets/js/live-list.js'); ?>" defer></script>
    <script>window.__scDownloadLabel = <?php echo json_encode(t('Download')); ?>; window.__scNewTabLabel = <?php echo json_encode(t('Open in a new tab')); ?>;</script>
    <script src="<?php echo asset('assets/js/file-view.js'); ?>" defer></script>
    <?php if (!empty($GLOBALS['sc_ai_write_used']) && !in_array('assets/js/ai-assist.js', $page_scripts ?? [], true)): ?><script src="<?php echo asset('assets/js/ai-assist.js'); ?>" defer></script><?php endif; ?>
    <script src="<?php echo asset('assets/js/settings-nav.js'); ?>" defer></script>
    <script>
      // Installable app + offline page (sw.js never stores signed-in pages)
      if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
        window.addEventListener('load', function () { navigator.serviceWorker.register('<?php echo APP_URL; ?>/sw.js').catch(function () {}); });
      }
    </script>
    <script src="<?php echo asset('assets/js/auth.js'); ?>"></script>
    <script>
      // Notification bell dropdown
      (function () {
        const trigger = document.getElementById("notif-trigger");
        const panel = document.getElementById("notif-dropdown");
        if (!trigger || !panel) return;
        function place() {
          const r = trigger.getBoundingClientRect();
          panel.style.top = (r.bottom + 10) + "px";
          if (document.documentElement.dir === "rtl") {   // right-to-left: the panel hangs from the bell's left edge
            panel.style.right = "auto";
            panel.style.left = Math.max(10, Math.min(r.left, window.innerWidth - panel.offsetWidth - 10)) + "px";
          } else panel.style.right = Math.max(10, window.innerWidth - r.right - 10) + "px";
        }
        function setOpen(open) {
          if (open) place();
          panel.classList.toggle("open", open);
          trigger.setAttribute("aria-expanded", open ? "true" : "false");
        }
        trigger.addEventListener("click", function (e) {
          e.stopPropagation();
          setOpen(!panel.classList.contains("open"));
        });
        document.addEventListener("click", function (e) {
          if (!panel.contains(e.target)) setOpen(false);
        });
        document.addEventListener("keydown", function (e) {
          if (e.key === "Escape" && panel.classList.contains("open")) { setOpen(false); trigger.focus(); }
        });
        window.addEventListener("resize", function () { if (panel.classList.contains("open")) place(); });
      })();

      // Coming-soon links: block navigation, show toast
      document.addEventListener("click", function (e) {
        const link = e.target.closest(".coming-soon");
        if (!link) return;
        e.preventDefault();
        const toast = document.getElementById("soonToast");
        toast.textContent = link.textContent.replace(/soon\s*$/i, "").trim() + " is coming soon";
        toast.classList.add("show");
        clearTimeout(window.__soonTimer);
        window.__soonTimer = setTimeout(() => toast.classList.remove("show"), 2200);
      });
    </script>
    <?php foreach (($page_scripts ?? []) as $page_script_file): ?>
    <script data-page src="<?php echo asset($page_script_file); ?>"></script>
    <?php endforeach; ?>
    <?php if (!empty($page_script)): ?>
    <script data-page>
    <?php echo $page_script; ?>
    </script>
    <?php endif; ?>
  </body>
</html>

<?php
/**
 * Shared module-page layout (top): main.html dashboard design.
 * Requires: includes/auth.php already included; $page_title set.
 * Optional: $page_subtitle, $active_menu (section id),
 *           $hide_page_head (page draws its own heading, e.g. the class banner).
 */
require_once __DIR__ . '/menu.php';

$role          = $current_user_role;
$page_subtitle = $page_subtitle ?? ($role === 'teacher' ? 'Teacher workspace' : 'Student workspace');
$active_menu   = $active_menu ?? '';

// Header bell: unread count + latest notifications (every page)
$notif_unread = countUnreadNotifications($current_user_id);
$notif_latest = getNotifications($current_user_id, 8);
i18nStart();   // Pashto / Dari: the page is translated on its way out
?>
<!DOCTYPE html>
<html <?php echo i18nHtmlAttrs(); ?>>
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <link rel="icon" type="image/svg+xml" href="<?php echo APP_URL; ?>/assets/img/favicon.svg" />
    <link rel="manifest" href="<?php echo APP_URL; ?>/manifest.php" />
    <link rel="apple-touch-icon" href="<?php echo APP_URL; ?>/assets/img/icon-192.png" />
    <meta name="theme-color" content="<?php echo htmlspecialchars((string) preg_match('/^#[0-9a-f]{6}$/i', (string) getSetting('brand_color', '')) ? getSetting('brand_color') : '#4361ee'); ?>" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrfToken()); ?>" />
    <meta name="app-url" content="<?php echo htmlspecialchars(APP_URL); ?>/" />
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="<?php echo asset('assets/vendor/fonts/fonts.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo asset('assets/css/dashboard.css'); ?>" />
    <link rel="stylesheet" href="<?php echo asset('assets/css/app.css'); ?>" />
    <link rel="stylesheet" href="<?php echo asset('assets/css/polish.css'); ?>" />
    <?php $brand = strtolower((string) getSetting('brand_color', '#4361ee'));
          if (preg_match('/^#[0-9a-f]{6}$/', $brand) && $brand !== '#4361ee'):
              $dark = '#' . implode('', array_map(fn($c) => str_pad(dechex((int) (hexdec($c) * 0.84)), 2, '0', STR_PAD_LEFT), str_split(substr($brand, 1), 2))); ?>
    <style>:root { --primary: <?php echo $brand; ?>; --primary-dark: <?php echo $dark; ?>; }</style>
    <?php endif; ?>
    <link rel="stylesheet" href="<?php echo asset('assets/css/theme-dark.css'); ?>" />
    <?php if (i18nRtl()): ?>
    <link href="<?php echo asset('assets/vendor/fonts/fonts.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo asset('assets/css/rtl.css'); ?>" />
    <?php endif; ?>
    <?php echo i18nLiveScripts(); ?>
    <script>
      /* Theme before the first paint (no flash): saved choice, else the system setting */
      (function () {
        var t = 'auto';
        try { t = localStorage.getItem('sc-theme') || 'auto'; } catch (e) {}
        var dark = t === 'dark' || (t === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
      })();
    </script>
    <link
      rel="stylesheet"
      href="<?php echo asset('assets/vendor/fontawesome/css/all.min.css'); ?>"
    />
    <script src="<?php echo asset('assets/vendor/jquery/jquery-3.7.1.min.js'); ?>"></script>
    <script src="<?php echo asset('assets/vendor/sweetalert2/sweetalert2.all.min.js'); ?>"></script>
  </head>
  <body>
<?php if (defined('SC_STAGING') && SC_STAGING): ?><div class="staging-strip" role="status">STAGING COPY — test data only · no e-mail is sent · AI is off</div><?php endif; ?>
    <a href="#main-content" class="skip-to-main">Skip to main content</a>
    <div class="overlay" aria-hidden="true"></div>
    <div class="tooltip" role="tooltip" aria-hidden="true"></div>

    <?php if (!empty($_SESSION['support_until'])): ?>
    <div class="support-banner" role="status"><i class="fas fa-life-ring"></i> Provider support session — ends at <?php echo date('H:i', (int) $_SESSION['support_until']); ?>. Everything done here is recorded.
        <?php if (!empty($_SESSION['support_reason'])): ?><span>(<?php echo htmlspecialchars($_SESSION['support_reason']); ?>)</span><?php endif; ?></div>
    <?php endif; ?>
    <?php if (isStudent() && strpos($_SERVER['SCRIPT_NAME'] ?? '', 'settings/whatsapp.php') === false && waNumberRequired() && empty((waPerson($current_user_id) ?: [])['whatsapp_number'])): ?>
    <div class="support-banner wa-needbanner" role="status"><i class="fa-brands fa-whatsapp"></i> <?php echo t('Please add a WhatsApp number — yours, or your father\'s, uncle\'s or elder brother\'s. The school sends attendance and result messages to it.'); ?>
        <a href="<?php echo APP_URL; ?>/pages/settings/whatsapp.php"><?php echo t('Add it now'); ?></a></div>
    <?php endif; ?>
    <div class="container">
      <!-- Sidebar (from the shared menu registry) -->
      <aside class="sidebar" role="navigation" aria-label="Main navigation">
        <div class="sidebar-inner">
          <div class="logo-area">
            <div class="logo">
              <?php if ((string) getSetting('school_logo', '') !== ''): ?><img class="brand-logo" src="<?php echo APP_URL; ?>/pages/branding/logo.php?v=<?php echo urlencode(basename((string) getSetting('school_logo', ''))); ?>" alt="">
              <?php else: ?><i class="fas fa-graduation-cap" aria-hidden="true"></i><?php endif; ?>
              <span class="logo-text"><?php echo htmlspecialchars(APP_NAME === 'SmartClass' ? 'Smart Class' : APP_NAME); ?></span>
            </div>
            <button class="sidebar-toggle" aria-label="Toggle sidebar" aria-expanded="true">
              <i class="fa-solid fa-angles-right" aria-hidden="true"></i>
            </button>
          </div>

          <nav class="sidebar-nav" role="navigation" aria-label="Sidebar menu">
            <ul class="nav-list">
              <li class="menu-item">
                <a class="menu-header direct-link<?php echo $active_menu === 'dashboard' ? ' active' : ''; ?>" style="text-decoration:none;display:flex;"
                   href="<?php echo APP_URL; ?>/pages/dashboard.php" data-tooltip="Dashboard">
                  <div class="menu-icon" aria-hidden="true"><i class="fas fa-home"></i></div>
                  <div class="menu-text"><h3>Dashboard</h3></div>
                </a>
              </li>
              <?php
              // The page being shown, relative to the app ("pages/settings/my_ai.php") — marks its menu item
              $hdr_here = ltrim(substr((string) $_SERVER['SCRIPT_NAME'], strlen((string) parse_url(APP_URL, PHP_URL_PATH))), '/');
              foreach (appMenu() as $hdr_m):
                  if (!in_array($role, $hdr_m['roles'])) continue;
                  $hdr_items = menuVisibleItems($hdr_m, $role);
                  if (!$hdr_items) continue;
                  if (!empty($hdr_m['direct'])): ?>
              <li class="menu-item">
                <a class="menu-header direct-link<?php echo $active_menu === $hdr_m['id'] ? ' active' : ''; ?>" style="text-decoration:none;display:flex;"
                   href="<?php echo APP_URL . '/' . $hdr_m['direct']; ?>" data-tooltip="<?php echo htmlspecialchars($hdr_m['tooltip']); ?>"<?php echo $active_menu === $hdr_m['id'] ? ' aria-current="page"' : ''; ?>>
                  <div class="menu-icon" aria-hidden="true"><i class="fas <?php echo $hdr_m['icon']; ?>"></i></div>
                  <div class="menu-text"><h3><?php echo $hdr_m['title']; ?></h3></div>
                </a>
              </li>
              <?php continue; endif;
                  $hdr_open = in_array($hdr_here, array_column($hdr_items, 'url'), true); ?>
              <li class="menu-item"<?php echo $hdr_open ? ' data-open="1"' : ''; ?>>
                <button class="menu-header" aria-expanded="false"
                        aria-controls="<?php echo $hdr_m['id']; ?>-dropdown"
                        data-tooltip="<?php echo htmlspecialchars($hdr_m['tooltip']); ?>">
                  <div class="menu-icon" aria-hidden="true"><i class="fas <?php echo $hdr_m['icon']; ?>"></i></div>
                  <div class="menu-text">
                    <h3><?php echo $hdr_m['title']; ?></h3>
                    <i class="fas fa-chevron-right arrow" aria-hidden="true"></i>
                  </div>
                </button>
                <div id="<?php echo $hdr_m['id']; ?>-dropdown" class="dropdown-content" role="region"
                     aria-label="<?php echo htmlspecialchars($hdr_m['title']); ?> submenu" aria-hidden="true">
                  <?php foreach ($hdr_items as $hdr_item): ?>
                  <?php if (!empty($hdr_item['url'])): ?>
                  <a href="<?php echo APP_URL . '/' . $hdr_item['url']; ?>" class="dropdown-item<?php echo $hdr_item['url'] === $hdr_here ? ' active' : ''; ?>"<?php echo $hdr_item['url'] === $hdr_here ? ' aria-current="page"' : ''; ?>>
                    <i class="fas <?php echo $hdr_item['icon']; ?>" aria-hidden="true"></i>
                    <span><?php echo $hdr_item['label']; ?></span>
                  </a>
                  <?php else: ?>
                  <a href="#" class="dropdown-item coming-soon">
                    <i class="fas <?php echo $hdr_item['icon']; ?>" aria-hidden="true"></i>
                    <span><?php echo $hdr_item['label']; ?><span class="soon-tag">soon</span></span>
                  </a>
                  <?php endif; ?>
                  <?php endforeach; ?>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>
          </nav>
        </div>
      </aside>
      <script>
        // Runs before the first paint, so the sidebar never jumps: open the section of the current
        // page at its full height without animation, then put the sidebar back where it was scrolled.
        // Not on narrow screens or a collapsed sidebar (those use floating menus / a drawer).
        (function () {
          var nav = document.querySelector(".sidebar-nav");
          if (!nav) return;
          var collapsed = false;
          try { collapsed = !!(JSON.parse(localStorage.getItem("dashboardState") || "{}").sidebarCollapsed); } catch (e) {}
          var item = nav.querySelector(".menu-item[data-open]");
          if (item && !collapsed && window.innerWidth > 1024) {
            var list = item.querySelector(".dropdown-content");
            item.classList.add("active");
            item.querySelector(".menu-header").setAttribute("aria-expanded", "true");
            list.setAttribute("aria-hidden", "false");
            list.style.transition = "none";
            list.style.maxHeight = list.scrollHeight + "px";
          }
          var saved = null;
          try { saved = sessionStorage.getItem("sc-sidebar-scroll"); } catch (e) {}
          if (saved !== null) nav.scrollTop = Number(saved) || 0;
          var current = nav.querySelector(".dropdown-item.active, .menu-header.active");
          if (current) {
            var r = current.getBoundingClientRect(), n = nav.getBoundingClientRect();
            if (r.height > 0 && (r.top < n.top || r.bottom > n.bottom)) current.scrollIntoView({ block: "nearest" });
          }
        })();
      </script>

      <!-- Main -->
      <div class="main-wrapper">
        <header class="main-header" role="banner">
          <button class="mobile-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">
            <i class="fas fa-bars" aria-hidden="true"></i>
          </button>

          <nav class="header-nav" role="navigation" aria-label="Quick links">
            <ul class="header-nav-list">
              <li class="hn-link">
                <a href="<?php echo APP_URL; ?>/pages/dashboard.php" class="header-item<?php echo ($active_menu ?? '') === 'dashboard' ? ' is-current' : ''; ?>"<?php echo ($active_menu ?? '') === 'dashboard' ? ' aria-current="page"' : ''; ?> data-tooltip="Home">
                  <i class="fas fa-home" aria-hidden="true"></i>
                  <span class="header-text">Home</span>
                </a>
              </li>
              <li class="hn-link">
                <a href="<?php echo APP_URL; ?>/pages/classes/my-classes.php" class="header-item<?php echo ($active_menu ?? '') === 'classes' ? ' is-current' : ''; ?>"<?php echo ($active_menu ?? '') === 'classes' ? ' aria-current="page"' : ''; ?> data-tooltip="My Classes">
                  <i class="fas fa-chalkboard" aria-hidden="true"></i>
                  <span class="header-text">My Classes</span>
                </a>
              </li>
              <li class="hn-link">
                <a href="<?php echo APP_URL; ?>/pages/resources/view.php" class="header-item<?php echo ($active_menu ?? '') === 'resources' ? ' is-current' : ''; ?>"<?php echo ($active_menu ?? '') === 'resources' ? ' aria-current="page"' : ''; ?> data-tooltip="Resources">
                  <i class="fas fa-folder" aria-hidden="true"></i>
                  <span class="header-text">Resources</span>
                </a>
              </li>
              <li class="hn-link">
                <a href="<?php echo APP_URL; ?>/pages/assignments/view.php" class="header-item<?php echo ($active_menu ?? '') === 'assignments' ? ' is-current' : ''; ?>"<?php echo ($active_menu ?? '') === 'assignments' ? ' aria-current="page"' : ''; ?> data-tooltip="Assignments">
                  <i class="fas fa-tasks" aria-hidden="true"></i>
                  <span class="header-text">Assignments</span>
                </a>
              </li>
              <li class="hn-link">
                <a href="<?php echo APP_URL; ?>/pages/quizzes/view.php" class="header-item<?php echo ($active_menu ?? '') === 'quizzes' ? ' is-current' : ''; ?>"<?php echo ($active_menu ?? '') === 'quizzes' ? ' aria-current="page"' : ''; ?> data-tooltip="Quizzes">
                  <i class="fas fa-question-circle" aria-hidden="true"></i>
                  <span class="header-text">Quizzes</span>
                </a>
              </li>
              <li class="hn-link">
                <a href="<?php echo APP_URL; ?>/pages/attendance/view.php" class="header-item<?php echo ($active_menu ?? '') === 'attendance' ? ' is-current' : ''; ?>"<?php echo ($active_menu ?? '') === 'attendance' ? ' aria-current="page"' : ''; ?> data-tooltip="Attendance">
                  <i class="fas fa-clipboard-check" aria-hidden="true"></i>
                  <span class="header-text">Attendance</span>
                </a>
              </li>
              <li class="hn-search">
                <div class="sc-inline" id="scInline" data-url="<?php echo htmlspecialchars(APP_URL . '/pages/search.php'); ?>" data-hint="<?php echo htmlspecialchars(t('Type at least 2 letters. Press Esc to close.')); ?>" data-empty="<?php echo htmlspecialchars(t('Nothing found')); ?>" data-error="<?php echo htmlspecialchars(t('Search did not work. Try again.')); ?>">
                  <button class="header-item" id="scSearchBtn" type="button" aria-label="<?php echo htmlspecialchars(t('Search')); ?>" aria-expanded="false" data-tooltip="<?php echo htmlspecialchars(t('Search')); ?>"><i class="fas fa-magnifying-glass" aria-hidden="true"></i></button>
                  <input type="search" id="scSearchInput" autocomplete="off" spellcheck="false" hidden aria-label="<?php echo htmlspecialchars(t('Search')); ?>" placeholder="<?php echo htmlspecialchars(t('Search classes, lectures, files, students…')); ?>">
                  <button class="header-item sc-close" id="scSearchClose" type="button" hidden aria-label="<?php echo htmlspecialchars(t('Close')); ?>"><i class="fas fa-xmark" aria-hidden="true"></i></button>
                </div>
              </li>
              <li class="hn-tool">
                <button class="header-item pdf-export" id="pdf-export" type="button" aria-label="Export this page as PDF" data-tooltip="Export PDF">
                  <i class="fas fa-file-pdf" aria-hidden="true"></i>
                  <span class="header-text sr-only">Export PDF</span>
                </button>
              </li>
              <li class="lang-item">
                <details class="hl-dd" id="langDropdown">
                  <summary class="header-item" aria-label="Language" data-tooltip="Language">
                    <i class="fas fa-language" aria-hidden="true"></i>
                    <span class="hl-current"><?php $__ln = i18nLanguages()[i18nLang()]; echo htmlspecialchars($__ln[0]); ?></span>
                    <i class="fas fa-chevron-down hl-caret" aria-hidden="true"></i>
                  </summary>
                  <ul class="hl-menu" role="listbox" aria-label="Language">
                    <?php foreach (i18nLanguages() as $lc => [$native, $english]): ?>
                    <li role="option" aria-selected="<?php echo i18nLang() === $lc ? 'true' : 'false'; ?>"><a href="<?php echo htmlspecialchars(i18nSwitchUrl($lc)); ?>" lang="<?php echo $lc; ?>" hreflang="<?php echo $lc; ?>" data-no-ajax
                        class="<?php echo i18nLang() === $lc ? 'on' : ''; ?>"><span><?php echo htmlspecialchars($native); ?></span><?php echo i18nLang() === $lc ? '<i class="fas fa-check" aria-hidden="true"></i>' : ''; ?></a></li>
                    <?php endforeach; ?>
                  </ul>
                </details>
                <script>
                  (function () {   // the language list closes on a click elsewhere or Esc
                    var d = document.getElementById("langDropdown"); if (!d) return;
                    var menu = d.querySelector(".hl-menu"), home = d;
                    function place() {   // the list is moved under <body> while open: the translucent header would otherwise trap a fixed element
                      var r = d.querySelector("summary").getBoundingClientRect(), rtl = document.documentElement.dir === "rtl", w = Math.max(menu.offsetWidth, 170);
                      menu.style.top = Math.round(r.bottom + 6) + "px";
                      menu.style.left = Math.round(Math.min(Math.max(8, rtl ? r.left : r.right - w), window.innerWidth - w - 8)) + "px";
                    }
                    d.addEventListener("toggle", function () {
                      if (d.open) { document.body.appendChild(menu); menu.classList.add("is-open"); place(); }
                      else { menu.classList.remove("is-open"); home.appendChild(menu); }
                    });
                    menu.addEventListener("click", function (e) {   // pages are swapped in place, so this header can be older than the page: always switch the page you are on NOW
                      var a = e.target.closest("a"); if (!a) return;
                      var code = new URL(a.href, location.href).searchParams.get("lang"), u = new URL(location.href);
                      if (code) { u.searchParams.set("lang", code); a.setAttribute("href", u.pathname + u.search + u.hash); }
                    });
                    window.addEventListener("resize", function () { if (d.open) place(); });
                    document.addEventListener("click", function (e) { if (d.open && !d.contains(e.target) && !menu.contains(e.target)) d.open = false; });
                    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && d.open) { d.open = false; d.querySelector("summary").focus(); } });
                  })();
                </script>
              </li>
              <li>
                <button class="header-item refresh-btn" id="refresh-btn" type="button" aria-label="Refresh this page" data-tooltip="Refresh">
                  <i class="fas fa-rotate-right" aria-hidden="true"></i>
                  <span class="header-text sr-only">Refresh</span>
                </button>
                <script>
                  document.getElementById("refresh-btn").addEventListener("click", function () {
                    this.classList.add("spinning"); window.location.reload();
                  });
                </script>
              </li>
              <li>
                <button class="header-item theme-toggle" id="theme-toggle" type="button" aria-label="Switch between light and dark" data-tooltip="Light / dark">
                  <i class="fas fa-moon" aria-hidden="true"></i><i class="fas fa-sun" aria-hidden="true"></i>
                  <span class="header-text sr-only">Theme</span>
                </button>
              </li>
              <li class="notif-container">
                <button class="header-item notif-trigger" id="notif-trigger" type="button"
                        aria-label="Notifications<?php echo $notif_unread ? ' (' . $notif_unread . ' unread)' : ''; ?>"
                        aria-expanded="false" aria-controls="notif-dropdown" data-tooltip="Notifications">
                  <i class="fas fa-bell" aria-hidden="true"></i>
                  <?php if ($notif_unread): ?>
                  <span class="notif-badge"><?php echo $notif_unread > 99 ? '99+' : $notif_unread; ?></span>
                  <?php endif; ?>
                </button>
                <div id="notif-dropdown" class="notif-dropdown" role="dialog" aria-label="Notifications">
                  <div class="notif-dropdown-head">
                    <strong>Notifications</strong>
                    <?php if ($notif_unread): ?>
                    <form method="POST" action="<?php echo APP_URL; ?>/pages/notifications/index.php">
                      <?php echo csrfField(); ?>
                      <input type="hidden" name="action" value="read_all">
                      <button type="submit" class="link-btn">Mark all read</button>
                    </form>
                    <?php endif; ?>
                  </div>
                  <div class="notif-dropdown-list">
                    <?php if (empty($notif_latest)): ?>
                    <p class="notif-empty"><i class="fas fa-bell-slash"></i> No notifications yet</p>
                    <?php endif; ?>
                    <?php foreach ($notif_latest as $hdr_n): [$n_icon, $n_tone] = notificationIcon($hdr_n['type']); ?>
                    <a class="notif-item<?php echo $hdr_n['is_read'] ? '' : ' unread'; ?>"
                       href="<?php echo APP_URL; ?>/pages/notifications/open.php?id=<?php echo (int) $hdr_n['id']; ?>">
                      <div class="activity-icon <?php echo $n_tone; ?>"><i class="fas <?php echo $n_icon; ?>" aria-hidden="true"></i></div>
                      <div class="notif-body">
                        <p class="notif-title"><?php echo htmlspecialchars($hdr_n['title']); ?></p>
                        <span class="notif-meta"><?php echo timeAgo($hdr_n['created_at']); ?></span>
                      </div>
                      <?php if (!$hdr_n['is_read']): ?><span class="unread-dot" aria-label="Unread"></span><?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                  </div>
                  <a class="notif-dropdown-foot" href="<?php echo APP_URL; ?>/pages/notifications/index.php">View all notifications</a>
                </div>
              </li>
              <li class="profile-container">
                <button class="header-item profile-item" id="profile-dropdown-trigger"
                        aria-label="Profile menu" aria-expanded="false" aria-controls="profile-dropdown">
                  <?php if (!empty($user['photo'])): ?>
                  <img class="header-avatar" src="<?php echo APP_URL; ?>/pages/profile/photo.php?u=<?php echo (int) $user['id']; ?>&amp;s=128&amp;v=<?php echo urlencode(basename($user['photo'])); ?>" alt="">
                  <?php else: ?>
                  <i class="fas fa-user-circle" aria-hidden="true"></i>
                  <?php endif; ?>
                  <span class="header-who"><span class="header-text" title="<?php echo htmlspecialchars($current_user_name); ?>"><?php echo htmlspecialchars($current_user_name); ?></span><small class="header-role"><?php echo isTeacher() ? (isAdmin() ? (($adm_title = trim((string) getSetting('admin_title', ''))) !== '' ? htmlspecialchars($adm_title) : 'School admin') : 'Teacher') : 'Student'; ?></small></span>
                  <i class="fas fa-chevron-down header-caret" aria-hidden="true"></i>
                </button>
                <div id="profile-dropdown" class="profile-dropdown" role="menu">
                  <div class="profile-card" role="presentation">
                    <strong><?php echo htmlspecialchars($current_user_name); ?></strong>
                    <span><?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?></span>
                    <span class="role-chip"><?php echo $role === 'teacher' ? 'Teacher' : 'Student'; ?></span>
                  </div>
                  <a href="<?php echo APP_URL; ?>/pages/settings/profile.php" role="menuitem">
                    <i class="fas fa-user" aria-hidden="true"></i> View Profile
                  </a>
                  <a href="<?php echo APP_URL; ?>/pages/classes/my-classes.php" role="menuitem">
                    <i class="fas fa-chalkboard" aria-hidden="true"></i> My Classes
                  </a>
                  <a href="<?php echo APP_URL; ?>/pages/notifications/index.php" role="menuitem">
                    <i class="fas fa-bell" aria-hidden="true"></i> Notifications
                  </a>
                  <div class="divider" aria-hidden="true"></div>
                  <form method="POST" action="<?php echo APP_URL; ?>/pages/auth/logout.php" class="logout-form">
                    <?php echo csrfField(); ?>
                    <button type="submit" class="logout" role="menuitem">
                      <i class="fas fa-sign-out-alt" aria-hidden="true"></i> Logout
                    </button>
                  </form>
                </div>
              </li>
            </ul>
          </nav>
        </header>

        <main id="main-content" class="dashboard" role="main">
          <div class="external-dropdown-wrapper" aria-live="polite"></div>
          <div class="dashboard-content">
            <?php if (empty($hide_page_head)): ?>
            <div class="page-head">
              <div class="ph-text">
                <h1><?php echo htmlspecialchars($page_title); ?></h1>
                <p class="page-subtitle"><?php echo htmlspecialchars($page_subtitle); ?></p>
              </div>
              <?php if (!empty($page_actions)): ?><div class="ph-actions"><?php echo $page_actions; ?></div><?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($settings_page)) settingsShellOpen($role, $settings_active ?? ($hdr_here ?? '')); ?>

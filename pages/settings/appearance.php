<?php
/**
 * Settings › Appearance — light or dark theme (or follow the device). Remembered in this browser (localStorage),
 * the same value the header's sun / moon button uses.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$page_title = 'Appearance';
$page_subtitle = 'Light or dark — remembered in this browser';
$active_menu = 'settings';
$settings_page = true;
$page_scripts[] = 'assets/js/appearance.js';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="form-card">
    <h2 class="profile-section-title"><i class="fas fa-palette"></i> Theme</h2>
    <div class="theme-picks" id="themePicks" role="radiogroup" aria-label="Theme">
        <label class="theme-pick" data-theme-value="light"><input type="radio" name="theme" value="light"><span class="tp-prev tp-light"><i></i><i></i><i></i></span><strong>Light</strong></label>
        <label class="theme-pick" data-theme-value="dark"><input type="radio" name="theme" value="dark"><span class="tp-prev tp-dark"><i></i><i></i><i></i></span><strong>Dark</strong></label>
        <label class="theme-pick" data-theme-value="auto"><input type="radio" name="theme" value="auto"><span class="tp-prev tp-auto"><i></i><i></i><i></i></span><strong>Same as my device</strong></label>
    </div>
    <p class="form-hint">The choice applies to this browser. The sun / moon button in the top bar changes it too.</p>
</section>
<section class="set-section" id="language">
    <header><h3>Interface language</h3><p>Choose the language of menus, buttons and pages.</p></header>
    <div class="body">
        <div class="lang-picks" role="radiogroup" aria-label="Interface language">
            <?php foreach (i18nLanguages() as $code => [$native, $english]): ?>
            <a class="lang-pick<?php echo i18nLang() === $code ? ' on' : ''; ?>" href="?lang=<?php echo $code; ?>#language" role="radio" aria-checked="<?php echo i18nLang() === $code ? 'true' : 'false'; ?>" lang="<?php echo $code; ?>" data-no-ajax>
                <strong><?php echo htmlspecialchars($native); ?></strong><small><?php echo htmlspecialchars($english); ?></small></a>
            <?php endforeach; ?>
        </div>
        <p class="form-hint">Your choice is saved on your account. Pashto and Dari are written right to left. Texts typed by teachers and students (lectures, posts, messages) are never translated.</p>
    </div>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>

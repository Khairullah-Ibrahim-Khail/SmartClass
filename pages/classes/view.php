<?php
/**
 * Old class URL — view.php?id=N[&tab=...]
 * Each class section is its own page now (stream / classwork / people / grades / insights).
 * Forwards old links and notifications to the right page; the #fragment is kept by the browser.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';

$pages = ['stream', 'classwork', 'calendar', 'people', 'grades', 'attendance', 'messages', 'insights'];
$tab = $_GET['tab'] ?? 'stream';
$page = in_array($tab, $pages, true) ? $tab : 'stream';
$extra = isset($_GET['type']) && is_string($_GET['type']) ? '&type=' . rawurlencode($_GET['type']) : '';

redirect(APP_URL . '/pages/classes/' . $page . '.php?id=' . (int) ($_GET['id'] ?? 0) . $extra);

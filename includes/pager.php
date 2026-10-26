<?php
/**
 * One page control for every long list (loaded by includes/functions.php).
 *   [$page, $pages, $offset] = pagerWindow($total, $per)      the page asked for with ?p=N, kept inside 1..last
 *   pagerHtml($total, $page, $per)                            "Showing 21–40 of 87" + Previous / 1 2 … 9 / Next; every other filter in the address is kept
 * Use it with  LIMIT {$per} OFFSET {$offset}  on the list query and a COUNT(*) with the same WHERE.
 */
/** Records per page the person can choose; remembered in a cookie so every list uses it. Default 10. */
function pagerPerOptions() { return [10, 25, 50, 100]; }
function pagerPerPage($default = 10) {
    $ok = pagerPerOptions(); $want = (int) ($_GET['per'] ?? 0);
    if (in_array($want, $ok, true)) {
        if (!headers_sent()) setcookie('sc_per', (string) $want, ['expires' => time() + 86400 * 365, 'path' => '/', 'samesite' => 'Lax', 'httponly' => true]);
        return $want;
    }
    $c = (int) ($_COOKIE['sc_per'] ?? 0);
    return in_array($c, $ok, true) ? $c : $default;
}

/** "Show [10] per page" — put it at the TOP of a list, beside its search and filters (never under the list). Shown only when the list is longer than the smallest choice. */
function pagerPerSelect($per, $total) {
    if ((int) $total <= min(pagerPerOptions())) return '';
    $h = '<label class="pager-per-l"><span>' . t('Show') . '</span><select class="pager-per" aria-label="' . htmlspecialchars(t('Records per page')) . '">';
    foreach (pagerPerOptions() as $o) $h .= '<option value="' . $o . '"' . ($o === (int) $per ? ' selected' : '') . '>' . $o . '</option>';
    return $h . '</select><span>' . t('per page') . '</span></label>';
}

function pagerWindow($total, $per) {
    $per = max(1, (int) $per); $total = max(0, (int) $total);
    $pages = max(1, (int) ceil($total / $per));
    $page = min($pages, max(1, (int) ($_GET['p'] ?? 1)));
    return [$page, $pages, ($page - 1) * $per];
}

function pagerUrl($page) {
    $q = $_GET; unset($q['p']);
    if ($page > 1) $q['p'] = $page;
    return strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?') . ($q ? '?' . http_build_query($q) : '');
}

/** The numbers to show: first, last, and two either side of the current page, with gaps as null. */
function pagerNumbers($page, $pages) {
    $show = array_unique(array_filter([1, $pages, $page - 2, $page - 1, $page, $page + 1, $page + 2], fn($n) => $n >= 1 && $n <= $pages));
    sort($show); $out = []; $prev = 0;
    foreach ($show as $n) { if ($n - $prev > 1) $out[] = null; $out[] = $n; $prev = $n; }
    return $out;
}

function pagerHtml($total, $page, $per) {
    $total = (int) $total; $per = max(1, (int) $per);
    if ($total < 1) return '';
    $pages = max(1, (int) ceil($total / $per));
    if ($pages <= 1) return '<p class="pager-info">Showing all ' . $total . '</p>';
    $from = ($page - 1) * $per + 1; $to = min($total, $page * $per);
    $h = '<nav class="pager" aria-label="Pages"><span class="pager-info">Showing ' . $from . '–' . $to . ' of ' . $total . '</span>';
    $h .= $page > 1 ? '<a href="' . htmlspecialchars(pagerUrl($page - 1)) . '" rel="prev" aria-label="Previous page"><i class="fas fa-chevron-left"></i></a>' : '<span class="pager-off" aria-hidden="true"><i class="fas fa-chevron-left"></i></span>';
    foreach (pagerNumbers($page, $pages) as $n) {
        $h .= $n === null ? '<span class="pager-gap">…</span>' : '<a href="' . htmlspecialchars(pagerUrl($n)) . '" class="' . ($n === $page ? 'active' : '') . '"' . ($n === $page ? ' aria-current="page"' : '') . '>' . $n . '</a>';
    }
    $h .= $page < $pages ? '<a href="' . htmlspecialchars(pagerUrl($page + 1)) . '" rel="next" aria-label="Next page"><i class="fas fa-chevron-right"></i></a>' : '<span class="pager-off" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>';
    return $h . '</nav>';
}

<?php
/**
 * SmartClass - grading scale (loaded by includes/functions.php)
 *
 * The CLASS TEACHER decides how marks are described in their class (Grades tab → Grading scale); a class without its own
 * scale uses the school's default (Settings › Grading scale, set by the administrator). Every school decides how a percentage is described: plain ratings (Excellent / Good …), letters (A+, A, B …),
 * with or without a GPA. Stored as JSON in app_settings 'grading_scale' (editable in Settings › Grading scale):
 *   { "show_gpa": true, "basis": "graded"|"all", "bands": [ {"label":"A+","min":95,"gpa":4.0,"tone":"good"}, … ] }
 * A band covers every percentage from its "min" up to the next band's "min". Tone (good / ok / mid / low) only
 * picks the colour. Marks themselves are never changed — this only labels them.
 *   gradingUseClass($id)  make the following calls use this class's scale (class pages and the marks / grade functions do it)
 *   gradingScale()        the bands, highest first (always valid: the lowest band starts at 0)
 *   gradeBand($percent)   the band of a percentage (null percent -> null)
 *   gradingShowGpa()      whether GPA is shown
 *   gradingBasis()        'graded' = overall % is marks earned ÷ marks of the work marked so far (default);
 *                         'all'    = final % — marks earned ÷ marks of ALL work given (not marked / missing = 0)
 *   gradingPresets()      starting points offered on the settings page
 */

/** Colour a band gets when none was chosen (by its old "tone"). */
function gradingToneColor($tone) {
    return ['good' => '#06a77d', 'ok' => '#5cc7a6', 'mid' => '#e9a23b', 'low' => '#ef476f'][$tone] ?? '#6b7280';
}

/** Colours teachers can pick with one click (any other colour can be chosen with the colour box). */
function gradingSwatches() {
    return ['#06a77d', '#2fbf71', '#5cc7a6', '#4cc9f0', '#4361ee', '#7b61ff', '#e9a23b', '#f4a261', '#ef476f', '#d62828', '#8d99ae', '#6b4226'];
}

function gradingPresets() {
    $presets = [
        'rating' => ['name' => 'Ratings (Excellent … Needs improvement)', 'show_gpa' => false, 'bands' => [
            ['label' => 'Excellent', 'min' => 85, 'gpa' => 4.0, 'tone' => 'good'],
            ['label' => 'Good', 'min' => 70, 'gpa' => 3.0, 'tone' => 'ok'],
            ['label' => 'Satisfactory', 'min' => 50, 'gpa' => 2.0, 'tone' => 'mid'],
            ['label' => 'Needs improvement', 'min' => 0, 'gpa' => 0.0, 'tone' => 'low'],
        ]],
        'letters' => ['name' => 'Letters A – F with GPA', 'show_gpa' => true, 'bands' => [
            ['label' => 'A', 'min' => 90, 'gpa' => 4.0, 'tone' => 'good'],
            ['label' => 'B', 'min' => 80, 'gpa' => 3.0, 'tone' => 'ok'],
            ['label' => 'C', 'min' => 70, 'gpa' => 2.0, 'tone' => 'mid'],
            ['label' => 'D', 'min' => 60, 'gpa' => 1.0, 'tone' => 'low'],
            ['label' => 'F', 'min' => 0, 'gpa' => 0.0, 'tone' => 'low'],
        ]],
        'letters_plus' => ['name' => 'Letters with plus / minus (A+ … F)', 'show_gpa' => true, 'bands' => [
            ['label' => 'A+', 'min' => 97, 'gpa' => 4.0, 'tone' => 'good'], ['label' => 'A', 'min' => 93, 'gpa' => 4.0, 'tone' => 'good'], ['label' => 'A-', 'min' => 90, 'gpa' => 3.7, 'tone' => 'good'],
            ['label' => 'B+', 'min' => 87, 'gpa' => 3.3, 'tone' => 'ok'], ['label' => 'B', 'min' => 83, 'gpa' => 3.0, 'tone' => 'ok'], ['label' => 'B-', 'min' => 80, 'gpa' => 2.7, 'tone' => 'ok'],
            ['label' => 'C+', 'min' => 77, 'gpa' => 2.3, 'tone' => 'mid'], ['label' => 'C', 'min' => 73, 'gpa' => 2.0, 'tone' => 'mid'], ['label' => 'C-', 'min' => 70, 'gpa' => 1.7, 'tone' => 'mid'],
            ['label' => 'D+', 'min' => 67, 'gpa' => 1.3, 'tone' => 'low'], ['label' => 'D', 'min' => 63, 'gpa' => 1.0, 'tone' => 'low'], ['label' => 'D-', 'min' => 60, 'gpa' => 0.7, 'tone' => 'low'],
            ['label' => 'F', 'min' => 0, 'gpa' => 0.0, 'tone' => 'low'],
        ]],
    ];
    foreach ($presets as &$pr) foreach ($pr['bands'] as &$bd) $bd['color'] = gradingToneColor($bd['tone']);
    return $presets;
}

/**
 * Check a scale submitted from the settings page. Returns [clean bands, error message ('' = fine)].
 * $rows: list of ['label' =>, 'min' =>, 'gpa' =>, 'tone' =>] (raw strings).
 */
function gradingValidate(array $rows) {
    $bands = []; $mins = [];
    foreach ($rows as $r) {
        $label = trim((string) ($r['label'] ?? ''));
        $min = trim((string) ($r['min'] ?? ''));
        if ($label === '' && $min === '') continue;   // an empty row is ignored
        if ($label === '' || mb_strlen($label) > 20) return [[], 'Every band needs a name of up to 20 characters.'];
        if (!is_numeric($min) || $min < 0 || $min > 100) return [[], "\"{$label}\": the starting percentage must be between 0 and 100."];
        $gpa = trim((string) ($r['gpa'] ?? ''));
        if ($gpa !== '' && (!is_numeric($gpa) || $gpa < 0 || $gpa > 10)) return [[], "\"{$label}\": GPA must be a number between 0 and 10."];
        $tone = in_array($r['tone'] ?? '', ['good', 'ok', 'mid', 'low'], true) ? $r['tone'] : 'mid';
        $color = strtolower(trim((string) ($r['color'] ?? '')));
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) $color = gradingToneColor($tone);
        $min = round((float) $min, 2);
        if (isset($mins[(string) $min])) return [[], "Two bands start at {$min}% — each band needs its own starting percentage."];
        $mins[(string) $min] = true;
        $min_op = ($r['min_op'] ?? 'gte') === 'gt' ? 'gt' : 'gte';   // "greater than" or "greater than or equal to" the start
        $max_op = ($r['max_op'] ?? 'lte') === 'lt' ? 'lt' : 'lte';   // "less than" or "less than or equal to" the end
        $maxin = trim((string) ($r['max'] ?? ''));
        if ($maxin !== '' && (!is_numeric($maxin) || $maxin < 0 || $maxin > 100)) return [[], "\"{$label}\": the \"To %\" must be between 0 and 100."];
        if ($maxin !== '' && (float) $maxin < $min) return [[], "\"{$label}\": \"To {$maxin}%\" is below its start of {$min}%."];
        $glow = $gpa === '' ? 0.0 : round((float) $gpa, 2);
        $bands[] = ['label' => $label, 'min' => $min, 'gpa' => $glow, 'gpa_max' => $glow, 'tone' => $tone, 'color' => $color,
                   'max' => $maxin === '' ? null : round((float) $maxin, 2), 'min_op' => $min_op, 'max_op' => $max_op];
    }
    if (count($bands) < 2) return [[], 'Add at least two bands.'];
    if (count($bands) > 20) return [[], 'Use at most 20 bands.'];
    if (!isset($mins['0']) && !isset($mins['0.0'])) return [[], 'One band must start at 0% so every mark has a grade.'];
    foreach ($bands as $bb) if ($bb['min'] == 0 && $bb['min_op'] === 'gt') return [[], '"' . $bb['label'] . '" starts at 0% — use "greater than or equal" so 0% has a grade.'];
    usort($bands, fn($a, $b) => $b['min'] <=> $a['min']);
    // "To %": filled in when left empty; written ranges must not overlap and must leave no mark without a grade
    $n = count($bands);
    foreach ($bands as $i => &$b) {
        $next_min = $i === 0 ? null : $bands[$i - 1]['min'];   // the band above starts here
        if ($b['max'] === null) {
            $b['max'] = $next_min === null ? 100.0 : ($next_min == floor($next_min) ? $next_min - 1 : round($next_min - 0.01, 2));
            if ($b['max'] < $b['min']) $b['max'] = $b['min'];
        }
        if ($b['max'] == $b['min'] && ($b['min_op'] === 'gt' || $b['max_op'] === 'lt')) return [[], "\"{$b['label']}\" matches no mark: it starts at {$b['min']}% and ends at {$b['max']}% with a strict comparison."];
        if ($next_min === null) {
            if ($b['max'] < 100 || ($b['max'] == 100 && $b['max_op'] === 'lt')) return [[], "The highest grade (\"{$b['label']}\") must include 100%."];
        } else {
            $above = $bands[$i - 1];
            if ($b['max'] > $next_min || ($b['max'] == $next_min && $b['max_op'] === 'lte' && $above['min_op'] === 'gte'))
                return [[], "\"{$b['label']}\" reaches {$b['max']}% but the next grade up (\"{$above['label']}\") starts at {$next_min}% — the ranges overlap."];
            if ($next_min - $b['max'] > 1.0001) return [[], "Marks between {$b['max']}% and {$next_min}% would have no grade — make \"{$b['label']}\" reach {$next_min}% or change the next start."];
            if ($b['max'] == $next_min && $b['max_op'] === 'lt' && $above['min_op'] === 'gt') return [[], "Exactly {$next_min}% would have no grade (\"{$b['label']}\" is below it, \"{$above['label']}\" is above it)."];
        }
    }
    unset($b);
    return [$bands, ''];
}

/** How a decimal mark is treated when its grade is looked up: nearest (86.5 → 87), down (86.9 → 86), up (86.1 → 87) or none. */
function gradingRoundModes() {
    return ['nearest' => 'Round to the nearest whole number (86.5 counts as 87)', 'down' => 'Round down (86.9 counts as 86)', 'up' => 'Round up (86.1 counts as 87)', 'none' => 'Use the exact mark (no rounding)'];
}

function gradingRoundMode() {
    return gradingScale()['round'];
}

/** The percentage a grade is looked up with — rounded as the teacher decided (the mark itself is never changed). */
function gradingRound($percent) {
    switch (gradingRoundMode()) {
        case 'down': return floor($percent + 1e-9);
        case 'up': return ceil($percent - 1e-9);
        case 'none': return $percent;
        default: return floor($percent + 0.5 + 1e-9);   // nearest, .5 goes up
    }
}

/** Parse a stored scale (JSON); null when it is missing or invalid. */
function gradingParse($json) {
    $stored = json_decode((string) $json, true);
    if (is_array($stored) && !empty($stored['bands'])) {
        [$bands, $err] = gradingValidate($stored['bands']);
        if (!$err) return ['show_gpa' => !empty($stored['show_gpa']), 'basis' => ($stored['basis'] ?? '') === 'all' ? 'all' : 'graded',
                           'round' => isset(gradingRoundModes()[$stored['round'] ?? '']) ? $stored['round'] : 'nearest', 'bands' => $bands];
    }
    return null;
}

/** Which class the grading functions work for (null = the school's default scale). */
function gradingUseClass($class_id = null) {
    $GLOBALS['sc_grading_class'] = $class_id ? (int) $class_id : null;
}

/** The school's default scale (ratings when nothing valid is stored). */
function gradingSchoolScale() {
    static $cache = null;
    if ($cache === null) {
        $cache = gradingParse(getSetting('grading_scale', '')) ?? ['show_gpa' => false, 'basis' => 'graded', 'round' => 'nearest', 'bands' => gradingValidate(gradingPresets()['rating']['bands'])[0]];   // validated: fills in each band's "To %" 
    }
    return $cache;
}

/** The class's own scale, or null when it follows the school's. */
function gradingClassScale($class_id) {
    global $db;
    static $cache = [];
    $class_id = (int) $class_id;
    if (!array_key_exists($class_id, $cache)) {
        $row = $db->fetchOne("SELECT scale FROM class_grading_scales WHERE class_id = ?", [$class_id]);
        $cache[$class_id] = $row ? gradingParse($row['scale']) : null;
    }
    return $cache[$class_id];
}

/** The bands, highest first, for the current class (or the school default). */
function gradingScale() {
    $cid = $GLOBALS['sc_grading_class'] ?? null;
    return ($cid ? gradingClassScale($cid) : null) ?? gradingSchoolScale();
}

function gradingShowGpa() {
    return gradingScale()['show_gpa'];
}

function gradingBasis() {
    return gradingScale()['basis'];
}

/** Band of a percentage: ['label', 'min', 'top' (highest % it covers), 'gpa' (lowest), 'gpa_max', 'tone'], or null when there is no percentage yet. */
function gradeBand($percent) {
    if ($percent === null || $percent === '') return null;
    $percent = gradingRound($percent);   // 86.5 is graded as 87 (when the teacher chose rounding)
    $bands = gradingScale()['bands'];
    $n = count($bands);
    $hit = null;
    foreach ($bands as $i => $b) {   // the written range first: "greater than" / "less than" or equal, as the teacher chose
        $lo = ($b['min_op'] ?? 'gte') === 'gt' ? $percent > $b['min'] : $percent >= $b['min'];
        $hi = ($b['max_op'] ?? 'lte') === 'lt' ? $percent < $b['max'] : $percent <= $b['max'];
        if ($lo && $hi) { $hit = $i; break; }
    }
    if ($hit === null) {   // a mark between two written ranges (e.g. 89.5 between 89 and 90) goes to the grade whose start it has reached
        foreach ($bands as $i => $b) if ($percent >= $b['min']) { $hit = $i; break; }
        if ($hit === null) $hit = $n - 1;
    }
    $b = $bands[$hit];
    return $b + ['top' => $b['max'], 'next' => $hit === 0 ? 100.0 : $bands[$hit - 1]['min'], 'cls' => gradingClass($hit), 'index' => $hit];
}

/** CSS class of the i-th band (highest = 0) of the current class's scale: it sets --gc-bg / --gc-ink (see gradingCss()). */
function gradingClass($i) {
    return 'gcv ' . gradingRawClass($i);
}

function gradingRawClass($i) {
    return 'gc' . (int) ($GLOBALS['sc_grading_class'] ?? 0) . '-' . (int) $i;
}

/** Mix a #rrggbb colour with black ($t < 0) or white ($t > 0): $t in -1 … 1. */
function gradingMix($hex, $t) {
    $c = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    foreach ($c as &$v) $v = (int) round($t < 0 ? $v * (1 + $t) : $v + (255 - $v) * $t);
    return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
}

/** <style> body for the current scale: every band class defines its tint and text colour (light and dark theme). */
function gradingCss() {
    $out = '';
    foreach (gradingScale()['bands'] as $i => $b) {
        $cls = gradingRawClass($i);
        $rgb = [hexdec(substr($b['color'], 1, 2)), hexdec(substr($b['color'], 3, 2)), hexdec(substr($b['color'], 5, 2))];
        $out .= ".{$cls}{--gc-color:{$b['color']};--gc-bg:rgba({$rgb[0]},{$rgb[1]},{$rgb[2]},.17);--gc-ink:" . gradingMix($b['color'], -0.45) . "}"
              . "html[data-theme=\"dark\"] .{$cls}{--gc-bg:rgba({$rgb[0]},{$rgb[1]},{$rgb[2]},.26);--gc-ink:" . gradingMix($b['color'], 0.5) . "}\n";
    }
    return $out;
}

/** GPA of a percentage: inside its band the GPA moves from the band's lowest to its highest GPA as the percentage rises. */
function gradeGpa($percent) {
    $b = gradeBand($percent);
    if (!$b) return null;
    $percent = gradingRound($percent);
    $span = $b['next'] - $b['min'];   // the grade covers everything from its start up to where the next grade starts
    if ($b['gpa_max'] <= $b['gpa'] || $span <= 0) return round($b['gpa'], 2);
    $f = max(0, min(1, ($percent - $b['min']) / $span));
    return round($b['gpa'] + ($b['gpa_max'] - $b['gpa']) * $f, 2);
}

/** "90–100%" for the usual range, or "> 89% and ≤ 100%" when the teacher chose greater / less than. */
function gradingRangeText(array $b, $unit = '%', $scale = 1.0) {
    $f = fn($v) => rtrim(rtrim(number_format((float) $v * $scale, 2, '.', ''), '0'), '.');
    if (($b['min_op'] ?? 'gte') === 'gte' && ($b['max_op'] ?? 'lte') === 'lte') return $f($b['min']) . '–' . $f($b['max']) . $unit;
    if ($b['min'] == 0 && ($b['min_op'] ?? 'gte') === 'gte') return ($b['max_op'] === 'lt' ? '< ' : '≤ ') . $f($b['max']) . $unit;
    if ($b['max'] == 100 && ($b['max_op'] ?? 'lte') === 'lte') return (($b['min_op'] ?? 'gte') === 'gt' ? '> ' : '≥ ') . $f($b['min']) . $unit;
    return (($b['min_op'] ?? 'gte') === 'gt' ? '> ' : '≥ ') . $f($b['min']) . ' and ' . ($b['max_op'] === 'lt' ? '< ' : '≤ ') . $f($b['max']) . $unit;
}

/** Every band as text for keys and legends: [label, pct '95–100%', marks '95–100' (when a total is given), gpa '3.5–4' (when GPA is on)]. */
function gradingRanges($total_marks = null) {
    $bands = gradingScale()['bands'];
    $f = fn($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    $out = [];
    foreach ($bands as $i => $b) {
        $top = $b['max'];
        $out[] = ['cls' => gradingClass($i), 'color' => $b['color'], 'label' => $b['label'], 'pct' => gradingRangeText($b),
                  'marks' => $total_marks ? gradingRangeText($b, '', $total_marks / 100) : null,
                  'gpa' => gradingShowGpa() ? ($b['gpa_max'] > $b['gpa'] ? $f($b['gpa']) . '–' . $f($b['gpa_max']) : $f($b['gpa'])) : null];
    }
    return $out;
}

/**
 * The scale editor (presets, GPA switch, "grade is based on", band table, preview) — shared by the school page and the
 * class page. $scale = ['show_gpa', 'basis', 'bands']. Wrap it in <form id="gradingForm" data-presets="gradingPresetsAttr()">
 * with a csrf field and a submit button; assets/js/grading-settings.js drives it.
 */
function gradingPresetsAttr() {
    return htmlspecialchars(json_encode(array_map(fn($p) => ['show_gpa' => $p['show_gpa'], 'bands' => $p['bands']], gradingPresets())));
}

function gradingEditorHtml(array $scale, $error = '', $total_marks = null) {
    ob_start(); ?>
    <div class="form-group">
        <label>Start from</label>
        <div class="gs-presets">
            <?php foreach (gradingPresets() as $key => $p): ?>
            <button type="button" class="chip" data-preset="<?php echo $key; ?>"><?php echo htmlspecialchars($p['name']); ?></button>
            <?php endforeach; ?>
        </div>
        <span class="form-hint">Pick one, then change anything below — names (A+, A, B …), the percentage each starts at, and the colour. Nothing is saved until you press Save.</span>
    </div>

    <div class="form-group">
        <label class="switch-row"><input type="checkbox" name="show_gpa" id="showGpa" value="1" <?php echo !empty($scale['show_gpa']) ? 'checked' : ''; ?>> <span>Show GPA in grades and reports (leave it off to show only marks and grades — the GPA numbers below are kept either way; each grade has one GPA, set by its marks)</span></label>
    </div>

    <div class="form-group">
        <label for="round">Decimal marks</label>
        <select id="round" name="round" style="max-width:520px;">
            <?php foreach (gradingRoundModes() as $rk => $rl): ?><option value="<?php echo $rk; ?>" <?php echo ($scale['round'] ?? 'nearest') === $rk ? 'selected' : ''; ?>><?php echo htmlspecialchars($rl); ?></option><?php endforeach; ?>
        </select>
        <span class="form-hint">Only decides which grade a mark falls in — the mark itself is shown unchanged.</span>
    </div>

    <div class="form-group">
        <label for="basis">Grade is based on</label>
        <select id="basis" name="basis" style="max-width:520px;">
            <option value="graded" <?php echo ($scale['basis'] ?? 'graded') === 'graded' ? 'selected' : ''; ?>>Marked work only — marks earned ÷ marks of the work marked so far</option>
            <option value="all" <?php echo ($scale['basis'] ?? '') === 'all' ? 'selected' : ''; ?>>All work (final marks) — work not marked or not handed in counts as 0</option>
        </select>
    </div>

    <div class="table-wrap">
        <table class="app-table gs-table">
            <thead><tr><th>Grade / name</th><th>From % <small>(≥ or &gt;)</small></th><th>To % <small>(≤ or &lt;)</small></th><?php if ($total_marks): ?><th>In marks<small>of <?php echo rtrim(rtrim(number_format((float) $total_marks, 2, '.', ''), '0'), '.'); ?></small></th><?php endif; ?><th class="gs-gpa-col">GPA<small>for this grade</small></th><th>Colour</th><th></th></tr></thead>
            <tbody id="gsRows">
                <?php foreach ($scale['bands'] as $i => $b): ?>
                <tr>
                    <td><input type="text" name="bands[<?php echo $i; ?>][label]" maxlength="20" value="<?php echo htmlspecialchars((string) $b['label']); ?>" placeholder="A+" aria-label="Grade name"></td>
                    <td class="gs-bound"><select name="bands[<?php echo $i; ?>][min_op]" aria-label="Greater than or equal"><option value="gte" <?php echo ($b['min_op'] ?? 'gte') === 'gte' ? 'selected' : ''; ?>>≥</option><option value="gt" <?php echo ($b['min_op'] ?? '') === 'gt' ? 'selected' : ''; ?>>&gt;</option></select>
                        <input type="number" name="bands[<?php echo $i; ?>][min]" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) $b['min']); ?>" aria-label="Starts at percent"></td>
                    <td class="gs-bound"><select name="bands[<?php echo $i; ?>][max_op]" aria-label="Less than or equal"><option value="lte" <?php echo ($b['max_op'] ?? 'lte') === 'lte' ? 'selected' : ''; ?>>≤</option><option value="lt" <?php echo ($b['max_op'] ?? '') === 'lt' ? 'selected' : ''; ?>>&lt;</option></select>
                        <input type="number" name="bands[<?php echo $i; ?>][max]" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars((string) ($b['max'] ?? '')); ?>" placeholder="auto" aria-label="Goes up to percent"></td>
                    <?php if ($total_marks): ?><td class="gs-marks">—</td><?php endif; ?>
                    <td class="gs-gpa-col"><input type="number" name="bands[<?php echo $i; ?>][gpa]" min="0" max="10" step="0.01" value="<?php echo htmlspecialchars((string) $b['gpa']); ?>" aria-label="GPA of this grade"></td>
                    <td class="gs-color"><input type="color" name="bands[<?php echo $i; ?>][color]" value="<?php echo htmlspecialchars($b['color'] ?? gradingToneColor($b['tone'] ?? 'mid')); ?>" aria-label="Colour of this grade">
                        <span class="gs-swatches"><?php foreach (gradingSwatches() as $sw): ?><button type="button" class="gs-sw" style="background:<?php echo $sw; ?>" data-color="<?php echo $sw; ?>" title="<?php echo $sw; ?>" aria-label="Use colour <?php echo $sw; ?>"></button><?php endforeach; ?></span></td>
                    <td><button type="button" class="icon-btn danger gs-del" title="Remove this band" aria-label="Remove this band"><i class="fas fa-times"></i></button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="invalid-feedback <?php echo $error ? 'show' : ''; ?>" id="gsError"><?php echo htmlspecialchars($error); ?></div>
    <p><button type="button" class="btn-app btn-sm btn-outline" id="gsAdd"><i class="fas fa-plus"></i> Add a band</button></p>
    <p class="form-hint">Write each grade's range yourself, e.g. A+ from 90 to 100, A from 80 to 89 — and choose for each end whether it is "greater than / less than" (&gt; &lt;) or "greater / less than or equal" (≥ ≤), e.g. F: below 50 (&lt; 50). The highest grade must reach 100 and the lowest start at 0; ranges must not overlap or leave a gap bigger than 1 (a mark such as 89.5 counts in the grade whose start it has reached). Leave "To %" empty to fill it in automatically.</p>

    <div class="form-group">
        <label>Preview</label>
        <div class="gs-preview" id="gsPreview" aria-live="polite"></div>
    </div>
<?php
    return ob_get_clean();
}

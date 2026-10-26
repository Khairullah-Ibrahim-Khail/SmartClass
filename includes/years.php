<?php
/**
 * SmartClass - academic years (loaded by includes/functions.php)
 * Every class belongs to a year. Closing a year starts the next one: the classes you choose are copied forward
 * (copyClass: topics, lectures as drafts, assignments / quizzes without dates, resources — optionally the students too),
 * and the old classes are archived. Archived classes stay READABLE (stream, grades, attendance, reports and PDFs) as the
 * school's record, but can no longer be changed (includes/class_header.php blocks every POST on them).
 *   currentAcademicYear()          the year new classes join (created on first use when none exists)
 *   academicYears()                every year, newest first, with class / student counts
 *   rolloverAcademicYear(...)      close a year and start the next one (see below)
 */

function currentAcademicYear() {
    global $db;
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $id = (int) getSetting('current_academic_year', 0);
        $row = $id ? $db->fetchOne("SELECT * FROM academic_years WHERE id = ?", [$id]) : null;
        if (!$row) $row = $db->fetchOne("SELECT * FROM academic_years WHERE closed_at IS NULL ORDER BY starts_on DESC LIMIT 1");
        if (!$row) {   // a new installation: start with this school year (September – June)
            $y = (int) date('Y') - ((int) date('n') < 8 ? 1 : 0);
            $db->insert('academic_years', ['name' => $y . '–' . ($y + 1), 'starts_on' => "{$y}-09-01", 'ends_on' => ($y + 1) . '-06-30']);
            $row = $db->fetchOne("SELECT * FROM academic_years ORDER BY id DESC LIMIT 1");
        }
        return $cache = $row;
    } catch (Throwable $e) {
        error_log('SmartClass: academic years unavailable: ' . $e->getMessage());
        return $cache = ['id' => null, 'name' => '', 'starts_on' => null, 'ends_on' => null, 'closed_at' => null];
    }
}

function currentAcademicYearId() {
    $y = currentAcademicYear();
    return $y['id'] ? (int) $y['id'] : null;
}

/** Every year with how many classes (active / archived) and students it has. */
function academicYears() {
    global $db;
    return $db->fetchAll(
        "SELECT y.*, (SELECT COUNT(*) FROM classes c WHERE c.year_id = y.id AND c.is_active = 1) AS active_classes,
                (SELECT COUNT(*) FROM classes c WHERE c.year_id = y.id AND c.is_active = 0) AS archived_classes,
                (SELECT COUNT(DISTINCT cm.student_id) FROM class_members cm INNER JOIN classes c ON cm.class_id = c.id WHERE c.year_id = y.id) AS students
         FROM academic_years y ORDER BY y.starts_on DESC, y.id DESC");
}

/**
 * Close the current year and start the next one.
 *   $new      ['name', 'starts_on', 'ends_on']   the new year
 *   $classes  [class_id => ['parts' => [lectures, assignments, quizzes, resources], 'students' => bool, 'name' => string]]
 *             the classes to carry forward (each copied with copyClass for its owner; students re-added when 'students')
 *   $archive  archive the old classes (every class of the closing year that is still active, carried forward or not)
 * Returns ['year_id', 'copied' => n, 'students' => n, 'archived' => n, 'failed' => [name => message]].
 */
function rolloverAcademicYear(array $new, array $classes, $archive = true) {
    global $db;
    $old = currentAcademicYear();
    $year_id = $db->insert('academic_years', ['name' => $new['name'], 'starts_on' => $new['starts_on'], 'ends_on' => $new['ends_on']]);
    $out = ['year_id' => $year_id, 'copied' => 0, 'students' => 0, 'archived' => 0, 'failed' => []];
    $failed_ids = [];
    foreach ($classes as $cid => $opt) {
        $src = $db->fetchOne("SELECT * FROM classes WHERE id = ? AND year_id <=> ? AND is_active = 1", [(int) $cid, $old['id']]);
        if (!$src) continue;
        try {
            $r = copyClass($src, (int) $src['teacher_id'], $opt['name'] !== '' ? $opt['name'] : $src['class_name'], $opt['parts'], $year_id);
            $out['copied']++;
            // grading scale and marking scheme carry over
            $db->query("INSERT INTO class_grading_scales (class_id, scale, updated_by) SELECT ?, scale, updated_by FROM class_grading_scales WHERE class_id = ?", [$r['class_id'], $src['id']]);
            $db->query("INSERT INTO class_marking_scheme (class_id, component, weight) SELECT ?, component, weight FROM class_marking_scheme WHERE class_id = ?", [$r['class_id'], $src['id']]);
            // the teachers of the old class stay teachers of the new one
            foreach ($db->fetchAll("SELECT teacher_id, role FROM class_teachers WHERE class_id = ? AND role <> 'owner'", [$src['id']]) as $t) {
                $db->query("INSERT IGNORE INTO class_teachers (class_id, teacher_id, role, added_by) VALUES (?, ?, 'co_teacher', ?)", [$r['class_id'], $t['teacher_id'], $src['teacher_id']]);
            }
            if (!empty($opt['students'])) {
                foreach ($db->fetchAll("SELECT student_id FROM class_members WHERE class_id = ?", [$src['id']]) as $m) {
                    $db->query("INSERT IGNORE INTO class_members (class_id, student_id) VALUES (?, ?)", [$r['class_id'], $m['student_id']]);
                    $out['students']++;
                }
            }
        } catch (Throwable $e) {
            $out['failed'][$src['class_name']] = $e->getMessage();
            $failed_ids[] = (int) $cid;
        }
    }
    if ($archive) {
        $out['archived'] = $db->query("UPDATE classes SET is_active = 0 WHERE year_id <=> ? AND is_active = 1 AND id NOT IN (" . implode(',', array_merge([0], $failed_ids)) . ")", [$old['id']])->rowCount();
    }
    if ($old['id']) $db->query("UPDATE academic_years SET closed_at = NOW() WHERE id = ? AND closed_at IS NULL", [$old['id']]);
    setSetting('current_academic_year', $year_id);
    return $out;
}

<?php
/**
 * Search in the top bar (loaded by includes/functions.php): one query over everything THIS person can already see.
 *   searchEverything($q, $user_id, $role, $per = 5)  =>  [['kind', 'label', 'icon', 'items' => [['title', 'sub', 'url']]]]  (groups with no hit are left out)
 * Always limited to the person's own classes. Students never get other people (no student search); teachers get the students of their classes.
 * Students see only published lectures and announcements. Words are matched as plain text (% and _ are not wildcards).
 */
function searchEverything($q, $user_id, $role, $per = 5) {
    global $db;
    $q = trim(preg_replace('/\s+/u', ' ', (string) $q));
    if (mb_strlen($q) < 2) return [];
    $q = mb_substr($q, 0, 60);
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $per = max(1, min(8, (int) $per));
    $classes = getUserClasses($user_id, $role);
    $ids = array_map('intval', array_column($classes, 'id'));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $teacher = $role === 'teacher';
    $u = fn($p) => APP_URL . '/' . $p;
    $groups = [];
    $add = function ($kind, $label, $icon, array $items) use (&$groups) { if ($items) $groups[] = ['kind' => $kind, 'label' => $label, 'icon' => $icon, 'items' => $items]; };

    $add('class', 'Classes', 'fa-chalkboard', array_values(array_map(fn($c) => ['title' => $c['class_name'], 'sub' => '', 'url' => $u('pages/classes/stream.php?id=' . (int) $c['id'])],
        array_slice(array_filter($classes, fn($c) => mb_stripos($c['class_name'], $q) !== false), 0, $per))));

    if ($teacher) {
        $add('student', 'Students', 'fa-user-graduate', array_map(fn($r) => ['title' => $r['name'], 'sub' => $r['class_name'], 'url' => $u('pages/classes/student.php?class_id=' . (int) $r['class_id'] . '&student_id=' . (int) $r['id'])],
            $db->fetchAll("SELECT u.id, u.name, c.class_name, c.id AS class_id FROM class_members cm INNER JOIN users u ON u.id = cm.student_id INNER JOIN classes c ON c.id = cm.class_id
                           WHERE cm.class_id IN ({$in}) AND (u.name LIKE ? OR u.email LIKE ?) ORDER BY u.name LIMIT {$per}", array_merge($ids, [$like, $like]))));
    }
    $vis = $teacher ? '' : " AND l.status = 'published' AND (l.publish_at IS NULL OR l.publish_at <= NOW())";
    $add('lecture', 'Lectures', 'fa-video', array_map(fn($r) => ['title' => $r['title'], 'sub' => $r['class_name'], 'url' => $u('pages/lectures/view.php?id=' . (int) $r['id'])],
        $db->fetchAll("SELECT l.id, l.title, c.class_name FROM lectures l INNER JOIN classes c ON c.id = l.class_id WHERE l.class_id IN ({$in}){$vis} AND (l.title LIKE ? OR l.summary LIKE ?) ORDER BY l.created_at DESC LIMIT {$per}", array_merge($ids, [$like, $like]))));
    $add('assignment', 'Assignments', 'fa-tasks', array_map(fn($r) => ['title' => $r['title'], 'sub' => $r['class_name'], 'url' => $u('pages/assignments/view.php?id=' . (int) $r['id'])],
        $db->fetchAll("SELECT a.id, a.title, c.class_name FROM assignments a INNER JOIN classes c ON c.id = a.class_id WHERE a.class_id IN ({$in}) AND a.title LIKE ? ORDER BY a.created_at DESC LIMIT {$per}", array_merge($ids, [$like]))));
    $add('quiz', 'Quizzes', 'fa-question-circle', array_map(fn($r) => ['title' => $r['title'], 'sub' => $r['class_name'], 'url' => $u('pages/quizzes/view.php?class_id=' . (int) $r['class_id'])],
        $db->fetchAll("SELECT z.id, z.title, c.class_name, c.id AS class_id FROM quizzes z INNER JOIN classes c ON c.id = z.class_id WHERE z.class_id IN ({$in}) AND z.title LIKE ? ORDER BY z.created_at DESC LIMIT {$per}", array_merge($ids, [$like]))));
    $add('resource', 'Resources', 'fa-folder-open', array_map(fn($r) => ['title' => $r['title'], 'sub' => $r['class_name'], 'url' => $u('pages/resources/view.php?class_id=' . (int) $r['class_id'] . '&q=' . urlencode($r['title']))],
        $db->fetchAll("SELECT r.id, r.title, c.class_name, c.id AS class_id FROM resources r INNER JOIN classes c ON c.id = r.class_id WHERE r.class_id IN ({$in}) AND (r.title LIKE ? OR r.description LIKE ? OR r.original_name LIKE ?) ORDER BY r.created_at DESC LIMIT {$per}", array_merge($ids, [$like, $like, $like]))));
    $visa = $teacher ? '' : ' AND (a.publish_at IS NULL OR a.publish_at <= NOW())';
    $add('announcement', 'Announcements', 'fa-bullhorn', array_map(fn($r) => ['title' => mb_strimwidth(preg_replace('/\s+/u', ' ', $r['body']), 0, 90, '…'), 'sub' => $r['class_name'], 'url' => $u('pages/classes/stream.php?id=' . (int) $r['class_id'] . '#post-' . (int) $r['id'])],
        $db->fetchAll("SELECT a.id, a.body, c.class_name, c.id AS class_id FROM announcements a INNER JOIN classes c ON c.id = a.class_id WHERE a.class_id IN ({$in}){$visa} AND a.body LIKE ? ORDER BY a.created_at DESC LIMIT {$per}", array_merge($ids, [$like]))));
    $add('forum', 'Forum topics', 'fa-comments', array_map(fn($r) => ['title' => $r['title'], 'sub' => $r['class_name'], 'url' => $u('pages/forums/thread.php?id=' . (int) $r['id'])],
        $db->fetchAll("SELECT t.id, t.title, c.class_name FROM forum_threads t INNER JOIN classes c ON c.id = t.class_id WHERE t.class_id IN ({$in}) AND t.title LIKE ? ORDER BY t.last_post_at DESC LIMIT {$per}", array_merge($ids, [$like]))));
    $add('event', 'Events', 'fa-calendar-check', array_map(fn($r) => ['title' => $r['title'], 'sub' => $r['class_name'] . ' · ' . date('M j', strtotime($r['starts_at'])), 'url' => $u('pages/events/view.php?class_id=' . (int) $r['class_id'])],
        $db->fetchAll("SELECT e.id, e.title, e.starts_at, c.class_name, c.id AS class_id FROM class_events e INNER JOIN classes c ON c.id = e.class_id WHERE e.class_id IN ({$in}) AND (e.title LIKE ? OR e.location LIKE ?) ORDER BY e.starts_at DESC LIMIT {$per}", array_merge($ids, [$like, $like]))));
    $own = $teacher ? 'p.class_id IN (' . $in . ')' : 'p.student_id = ?';
    $add('research', 'Research projects', 'fa-flask', array_map(fn($r) => ['title' => $r['title'], 'sub' => ($teacher ? $r['student_name'] . ' · ' : '') . $r['class_name'], 'url' => $u(($r['status'] === 'draft' && !$teacher ? 'pages/research/start.php?id=' : 'pages/research/project.php?id=') . (int) $r['id'])],
        $db->fetchAll("SELECT p.id, p.title, p.status, c.class_name, s.name AS student_name FROM research_projects p INNER JOIN classes c ON c.id = p.class_id INNER JOIN users s ON s.id = p.student_id
                       WHERE {$own} AND (p.title LIKE ? OR p.question LIKE ?) ORDER BY p.updated_at DESC LIMIT {$per}", array_merge($teacher ? $ids : [(int) $user_id], [$like, $like]))));
    return $groups;
}

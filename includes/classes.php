<?php
/**
 * SmartClass - Classes module helpers (loaded by includes/functions.php)
 *
 *   Access     getAccessibleClass(), getUserClasses()
 *   Settings   classThemes(), classTheme(), streamPermissionLabels(), canPostToStream(), canCommentOnStream()
 *   Topics     getClassTopics(), getTopicsByClass(), validTopicId(), topicSelectField()
 *   Gradebook  getClassGradebook()
 *   Attendance attendanceStatuses(), getAttendanceSummary()
 *   Joining    requestToJoinClass(), getJoinRequests(), decideJoinRequest()
 *   Messages   messageContext(), postPrivateMessage(), getThreadMessages(), markThreadRead(),
 *              unreadMessageCount(), getConversations(), messageThreadUrl()
 *
 * Class pages (pages/classes/*) also use includes/class_header.php for the
 * class banner + section tabs.
 */

// ==================== CLASS ACCESS ====================

/**
 * The active class row if the user may access it, else null.
 * Teachers: classes they teach (owner or co-teacher, class_teachers). Students: classes they are enrolled in.
 * Adds to the row:
 *   'is_owner'     true for ANY teacher of the class (the name predates co-teachers: "may teach here")
 *   'is_primary'   true only for the class owner (archive / restore, manage co-teachers)
 *   'teacher_role' 'owner' | 'co_teacher' | null (student)
 *   'teacher_name' the owner's name
 */
function getAccessibleClass($class_id, $user_id, $role) {
    global $db;
    $class_id = (int) $class_id;
    if ($class_id <= 0) return null;
    // Class pages (includes/class_header.php) may open an ARCHIVED class — a past year's record — read-only
    $arch = !empty($GLOBALS['sc_view_archived']) ? 1 : 0;

    if ($role === 'teacher') {
        $class = $db->fetchOne(
            "SELECT c.*, u.name AS teacher_name, ct.role AS teacher_role FROM classes c
             INNER JOIN users u ON c.teacher_id = u.id
             INNER JOIN class_teachers ct ON ct.class_id = c.id AND ct.teacher_id = ?
             WHERE c.id = ? AND (c.is_active = 1 OR ? = 1)",
            [$user_id, $class_id, $arch]
        );
    } else {
        $class = $db->fetchOne(
            "SELECT c.*, u.name AS teacher_name FROM classes c
             INNER JOIN users u ON c.teacher_id = u.id
             INNER JOIN class_members cm ON cm.class_id = c.id AND cm.student_id = ?
             WHERE c.id = ? AND (c.is_active = 1 OR ? = 1)",
            [$user_id, $class_id, $arch]
        );
    }
    if (!$class) return null;
    $class['is_archived'] = empty($class['is_active']);
    $class['is_owner'] = ($role === 'teacher');
    $class['teacher_role'] = $class['teacher_role'] ?? null;
    $class['is_primary'] = $class['teacher_role'] === 'owner';
    return $class;
}

/** SQL condition (one ? = teacher id): the teacher teaches class alias $alias, as owner or co-teacher. */
function teachesClassSql($alias = 'c') {
    return "EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = {$alias}.id AND ct.teacher_id = ?)";
}

/** Where a class's attendance lives: the Attendance module (?id=N and any extra query like '&date=…'). */
function attendanceUrl($class_id, $extra = '') {
    return APP_URL . '/pages/attendance/class.php?id=' . (int) $class_id . $extra;
}

/** The attendance statuses that count as "attended" (present, late and official present) — one definition for every report and percentage. */
const ATTENDED_STATUSES = ['present', 'late', 'official'];

/** SQL condition: the attendance row's status counts as attended. $col may carry a table alias ('a.status'). */
function attendedSql($col = 'status') {
    return "{$col} IN ('" . implode("', '", ATTENDED_STATUSES) . "')";
}

/** Attendance percentage with one decimal (null when nothing was marked) — the same rounding everywhere. */
function attendancePct($attended, $marked) {
    return (int) $marked > 0 ? round($attended / $marked * 100, 1) : null;
}

/** Active classes the user can access (taught — owner or co-teacher — for teachers, enrolled for students), by name. */
function getUserClasses($user_id, $role) {
    global $db;
    if ($role === 'teacher') {
        return $db->fetchAll(
            "SELECT c.id, c.class_name FROM classes c WHERE " . teachesClassSql('c') . " AND c.is_active = 1 ORDER BY c.class_name",
            [$user_id]
        );
    }
    return $db->fetchAll(
        "SELECT c.id, c.class_name FROM classes c
         INNER JOIN class_members cm ON cm.class_id = c.id
         WHERE cm.student_id = ? AND c.is_active = 1 ORDER BY c.class_name",
        [$user_id]
    );
}

// ==================== CLASS SETTINGS ====================

/** Class colour themes (key stored in classes.theme => label, CSS background). */
function classThemes() {
    return [
        'indigo'  => ['Indigo',  'linear-gradient(135deg, #4361ee, #7209b7)'],
        'teal'    => ['Teal',    'linear-gradient(135deg, #0f9b8e, #4cc9f0)'],
        'sunset'  => ['Sunset',  'linear-gradient(135deg, #f4a261, #e76f51)'],
        'royal'   => ['Royal',   'linear-gradient(135deg, #3a0ca3, #4361ee)'],
        'emerald' => ['Emerald', 'linear-gradient(135deg, #06a77d, #2a9d8f)'],
        'berry'   => ['Berry',   'linear-gradient(135deg, #b5179e, #7209b7)'],
        'slate'   => ['Slate',   'linear-gradient(135deg, #334155, #64748b)'],
        'crimson' => ['Crimson', 'linear-gradient(135deg, #c9184a, #ff4d6d)'],
    ];
}

/** CSS background for a class: its chosen theme, or a stable automatic one. */
function classTheme($class) {
    $themes = classThemes();
    $key = $class['theme'] ?? 'auto';
    if (!isset($themes[$key])) {
        $keys = array_keys($themes);
        $key = $keys[((int) $class['id']) % 6];   // auto: first six, stable per class
    }
    return $themes[$key][1];
}

/** What students may do on the stream: 'post' (post + comment), 'comment', or 'teacher_only'. */
function streamPermissionLabels() {
    return [
        'post'         => 'Students can post and comment',
        'comment'      => 'Students can only comment',
        'teacher_only' => 'Only the teacher can post or comment',
    ];
}

function canPostToStream($class) {
    return !empty($class['is_owner']) || ($class['stream_permission'] ?? 'comment') === 'post';
}

function canCommentOnStream($class) {
    return !empty($class['is_owner']) || in_array($class['stream_permission'] ?? 'comment', ['post', 'comment'], true);
}

// ==================== TOPICS ====================

/** Topics of one class, in order. */
function getClassTopics($class_id) {
    global $db;
    return $db->fetchAll("SELECT id, name FROM class_topics WHERE class_id = ? ORDER BY position, id", [(int) $class_id]);
}

/** Topics of several classes (for create forms with a class picker): [class_id => [topic, ...]]. */
function getTopicsByClass(array $class_ids) {
    global $db;
    $out = [];
    if (!$class_ids) return $out;
    $class_ids = array_map('intval', $class_ids);
    $in = implode(',', array_fill(0, count($class_ids), '?'));
    foreach ($db->fetchAll("SELECT id, class_id, name FROM class_topics WHERE class_id IN ({$in}) ORDER BY position, id", $class_ids) as $t) {
        $out[$t['class_id']][] = ['id' => (int) $t['id'], 'name' => $t['name']];
    }
    return $out;
}

/** A posted topic id if it belongs to the class, else null ("No topic"). */
function validTopicId($topic_id, $class_id) {
    global $db;
    $topic_id = (int) $topic_id;
    if ($topic_id <= 0) return null;
    return $db->fetchOne("SELECT id FROM class_topics WHERE id = ? AND class_id = ?", [$topic_id, (int) $class_id]) ? $topic_id : null;
}

/**
 * "Topic" picker for create forms that also have a #class_id select.
 * Options carry their class id; the inline script shows only the selected class's topics.
 */
function topicSelectField(array $classes, $selected_topic) {
    $by_class = getTopicsByClass(array_column($classes, 'id'));
    $html = '<div class="form-group"><label for="topic_id">Topic</label>'
          . '<select id="topic_id" name="topic_id"><option value="0">No topic</option>';
    foreach ($by_class as $cid => $topics) {
        foreach ($topics as $t) {
            $html .= '<option value="' . $t['id'] . '" data-class="' . (int) $cid . '"'
                   . ((int) $selected_topic === $t['id'] ? ' selected' : '') . '>' . htmlspecialchars($t['name']) . '</option>';
        }
    }
    $html .= '</select><small class="form-hint">Optional. Groups this under a unit in the class Classwork tab.</small></div>'
           . '<script>(function () {'
           . 'var c = document.getElementById("class_id"), t = document.getElementById("topic_id");'
           . 'function sync() { var any = false;'
           . ' Array.prototype.forEach.call(t.options, function (o) { if (!o.dataset.class) return;'
           . '  var show = o.dataset.class === c.value; o.hidden = !show; o.disabled = !show; if (show) any = true;'
           . '  if (!show && o.selected) t.value = "0"; });'
           . ' t.closest(".form-group").style.display = any ? "" : "none"; }'
           . 'c.addEventListener("change", sync); sync(); })();</script>';
    return $html;
}

// ==================== GRADEBOOK ====================

/**
 * Gradebook for one class: every assignment and quiz as a column, every
 * student (or just one) as a row.
 *
 * Cell per (student, column): ['state' => graded|submitted|late|missing|pending|done|timeout|open,
 *                              'score' => float|null, 'max' => int,
 *   $student_view = true (the viewer is a student): results the teacher has not released yet come back
 *   as state 'held' with no score / feedback (and are left out of the overall %).
 *                              'sub_id' => submission id|null, 'feedback' => string|null]  (assignments only)
 * Row 'scored' / 'possible' only count graded work, so the percentage is
 * "of what has been marked so far" (like Google Classroom's overall grade).
 */
function getClassGradebook($class_id, $only_student_id = null, $student_view = false) {
    global $db;
    gradingUseClass($class_id);   // grades in this class follow its teacher's grading scale
    $now = time();

    $columns = [];
    foreach ($db->fetchAll(
        "SELECT id, title, max_marks, due_date, created_at, release_mode, results_released_at FROM assignments WHERE class_id = ?", [$class_id]) as $a) {
        $columns[] = ['type' => 'assignment', 'id' => (int) $a['id'], 'title' => $a['title'], 'held' => !resultsReleased($a),
                      'max' => (int) $a['max_marks'], 'due' => $a['due_date'], 'created' => $a['created_at'],
                      'url' => 'pages/assignments/view.php?id=' . (int) $a['id']];
    }
    foreach ($db->fetchAll(
        "SELECT q.id, q.title, q.due_date, q.created_at, q.release_mode, q.results_released_at,
                (SELECT COALESCE(SUM(qq.marks), 0) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS max_marks
         FROM quizzes q WHERE q.class_id = ?", [$class_id]) as $q) {
        $columns[] = ['type' => 'quiz', 'id' => (int) $q['id'], 'title' => $q['title'], 'held' => !resultsReleased($q),
                      'max' => (int) $q['max_marks'], 'due' => $q['due_date'], 'created' => $q['created_at'],
                      'url' => 'pages/quizzes/view.php?id=' . (int) $q['id']];
    }
    // Oldest first (by due date, else creation), like a gradebook timeline
    usort($columns, fn($a, $b) => strcmp($a['due'] ?? $a['created'], $b['due'] ?? $b['created']));

    $params = [$class_id];
    $only = '';
    if ($only_student_id !== null) {
        $only = ' AND u.id = ?';
        $params[] = $only_student_id;
    }
    $students = $db->fetchAll(
        "SELECT u.id, u.name, u.email FROM class_members cm
         INNER JOIN users u ON cm.student_id = u.id
         WHERE cm.class_id = ?{$only} ORDER BY u.name", $params);

    $subs = [];
    foreach ($db->fetchAll(
        "SELECT s.id, s.assignment_id, s.student_id, s.status, s.marks_obtained, s.feedback FROM submissions s
         INNER JOIN assignments a ON s.assignment_id = a.id WHERE a.class_id = ?", [$class_id]) as $s) {
        $subs[$s['assignment_id']][$s['student_id']] = $s;
    }
    $attempts = [];
    $reopened = [];   // quiz => student => true: reopened by the teacher and still open, so not "missing"
    foreach ($db->fetchAll(
        "SELECT r.quiz_id, r.student_id FROM quiz_reopens r INNER JOIN quizzes q ON r.quiz_id = q.id
         WHERE q.class_id = ? AND (r.until IS NULL OR r.until > NOW())", [$class_id]) as $ro) {
        $reopened[$ro['quiz_id']][$ro['student_id']] = true;
    }
    foreach ($db->fetchAll(
        "SELECT a.quiz_id, a.student_id, a.submitted_at, a.score, a.timed_out FROM quiz_attempts a
         INNER JOIN quizzes q ON a.quiz_id = q.id WHERE q.class_id = ?", [$class_id]) as $t) {
        $attempts[$t['quiz_id']][$t['student_id']] = $t;
    }

    $rows = [];
    foreach ($students as $st) {
        $cells = [];
        $scored = 0.0;
        $possible = 0;
        $all_possible = 0;   // marks of ALL work given (for the "final" figure); results still held back from a student are left out
        foreach ($columns as $col) {
            if (!($student_view && $col['held'])) $all_possible += $col['max'];
            $past_due = !empty($col['due']) && $now > strtotime($col['due']);
            $cell = ['state' => $past_due ? 'missing' : 'pending', 'score' => null, 'max' => $col['max'], 'sub_id' => null, 'feedback' => null];
            if ($col['type'] === 'assignment') {
                $s = $subs[$col['id']][$st['id']] ?? null;
                if ($s) {
                    $cell['state'] = $s['status'];   // submitted | late | graded
                    $cell['sub_id'] = (int) $s['id'];
                    $cell['feedback'] = $s['feedback'];
                    if ($s['status'] === 'graded') $cell['score'] = (float) $s['marks_obtained'];
                }
            } else {
                $t = $attempts[$col['id']][$st['id']] ?? null;
                if ($t && $t['submitted_at'] !== null) {
                    $cell['state'] = $t['timed_out'] ? 'timeout' : 'done';
                    $cell['score'] = (float) $t['score'];
                } elseif ($t) {
                    $cell['state'] = 'open';
                } elseif (!empty($reopened[$col['id']][$st['id']])) {
                    $cell['state'] = 'pending';   // the teacher reopened it for this student
                }
            }
            if ($student_view && $col['held'] && in_array($cell['state'], ['graded', 'done', 'timeout'], true)) {
                $cell = ['state' => 'held', 'score' => null, 'max' => $col['max'], 'sub_id' => null, 'feedback' => null];   // result not released yet
            }
            if ($cell['score'] !== null) {
                $scored += $cell['score'];
                $possible += $col['max'];
            }
            $cells[] = $cell;
        }
        $graded_pct = $possible > 0 ? round($scored / $possible * 100, 1) : null;
        // Final = marks earned out of ALL the work given (work not marked or not handed in counts as 0)
        $final_pct = ($possible > 0 && $all_possible > 0) ? round($scored / $all_possible * 100, 1) : null;
        $rows[] = ['id' => (int) $st['id'], 'name' => $st['name'], 'email' => $st['email'], 'cells' => $cells,
                   'scored' => $scored, 'possible' => $possible, 'all_possible' => $all_possible,
                   'graded_percent' => $graded_pct, 'final_percent' => $final_pct,
                   // the percentage the school's grade is based on (Settings › Grading scale › "Grade is based on")
                   'percent' => gradingBasis() === 'all' ? $final_pct : $graded_pct];
    }

    return ['columns' => $columns, 'rows' => $rows];
}

// ==================== ATTENDANCE ====================

/** Attendance statuses (per designs/pages/createAttendence.html): key => [label, badge class, icon]. */
function attendanceStatuses() {
    return [
        'present'  => ['Present', 'graded', 'fa-check'],
        'late'     => ['Late', 'late', 'fa-clock'],
        'absent'   => ['Absent', 'missing', 'fa-times'],
        'official' => ['Official Present', 'submitted', 'fa-briefcase'],
    ];
}

/**
 * Attendance summary per student for a class (or one student):
 * [student_id => ['present','late','absent','official','total','rate']].
 * rate = attended (present + late + official) / days marked, in %; null when nothing marked.
 */
function getAttendanceSummary($class_id, $only_student_id = null, $from = null, $to = null) {
    global $db;
    $params = [(int) $class_id];
    $only = '';
    if ($from !== null) { $only .= ' AND date >= ?'; $params[] = $from; }
    if ($to !== null) { $only .= ' AND date <= ?'; $params[] = $to; }
    if ($only_student_id !== null) {
        $only .= ' AND student_id = ?';
        $params[] = (int) $only_student_id;
    }
    $out = [];
    foreach ($db->fetchAll(
        "SELECT student_id, status, COUNT(*) AS n, SUM(excused = 1) AS ex, SUM(late_minutes) AS lm FROM attendance
         WHERE class_id = ?{$only} GROUP BY student_id, status", $params) as $r) {
        $sid = (int) $r['student_id'];
        $out[$sid] = $out[$sid] ?? ['present' => 0, 'late' => 0, 'absent' => 0, 'official' => 0, 'total' => 0, 'rate' => null, 'excused' => 0, 'late_minutes' => 0];
        $out[$sid][$r['status']] = (int) $r['n'];
        $out[$sid]['total'] += (int) $r['n'];
        if ($r['status'] === 'absent') $out[$sid]['excused'] = (int) $r['ex'];   // absences with an accepted reason
        if ($r['status'] === 'late') $out[$sid]['late_minutes'] = (int) $r['lm'];   // minutes late in total (where the teacher wrote them)
    }
    foreach ($out as &$s) {
        $s['rate'] = $s['total'] ? round(($s['present'] + $s['late'] + $s['official']) / $s['total'] * 100, 1) : null;
    }
    return $out;
}

// ==================== JOINING A CLASS (teacher approval) ====================

/**
 * A student uses a class code (join page, registration, AJAX — all go through here).
 * Returns: 'member' (already in), 'joined' (class does not require approval),
 *          'requested' (new or renewed request), 'pending' (request already waiting).
 * The class teacher is notified of a request / a join.
 */
function requestToJoinClass($class, $student_id, $student_name) {
    global $db;
    $class_id = (int) $class['id'];
    $student_id = (int) $student_id;

    if ($db->fetchOne("SELECT id FROM class_members WHERE class_id = ? AND student_id = ?", [$class_id, $student_id])) {
        return 'member';
    }

    if (empty($class['require_approval'])) {
        $db->insert('class_members', ['class_id' => $class_id, 'student_id' => $student_id]);
        logActivity('join_class', 'classes', "Joined class {$class['class_name']} ({$class['class_code']})", $student_id);
        notifyClassTeachers($class['id'], 'student_joined', 'New student joined your class',
            "{$student_name} joined {$class['class_name']}.", 'pages/classes/people.php?id=' . $class_id, $student_id, $class_id);
        return 'joined';
    }

    $existing = $db->fetchOne("SELECT id, status FROM class_join_requests WHERE class_id = ? AND student_id = ?", [$class_id, $student_id]);
    if ($existing && $existing['status'] === 'pending') {
        return 'pending';
    }
    if ($existing) {
        // Declined (or an old approved row of a student who later left): ask again
        $db->update('class_join_requests', ['status' => 'pending', 'created_at' => date('Y-m-d H:i:s'), 'decided_by' => null, 'decided_at' => null],
            'id = ?', [$existing['id']]);
    } else {
        $db->insert('class_join_requests', ['class_id' => $class_id, 'student_id' => $student_id]);
    }
    logActivity('request_join', 'classes', "Asked to join {$class['class_name']} ({$class['class_code']})", $student_id);
    notifyClassTeachers($class['id'], 'join_requested', 'Request to join ' . $class['class_name'],
        "{$student_name} asked to join {$class['class_name']}.", 'pages/classes/people.php?id=' . $class_id . '#requests', $student_id, $class_id);
    return 'requested';
}

/**
 * Pending join requests of a class, with what the teacher needs to judge the student before
 * approving (anti-scam): account state, photo, sign-up date and how many classes they are in.
 */
function getJoinRequests($class_id) {
    global $db;
    return $db->fetchAll(
        "SELECT r.id, r.student_id, r.created_at, u.name, u.email, u.is_approved, u.is_active, u.photo,
                u.created_at AS signed_up, u.email_verified_at,
                (SELECT COUNT(*) FROM class_members cm WHERE cm.student_id = u.id) AS class_count
         FROM class_join_requests r INNER JOIN users u ON r.student_id = u.id
         WHERE r.class_id = ? AND r.status = 'pending' AND u.is_active = 1
         ORDER BY r.created_at", [(int) $class_id]);
}

/**
 * Class teacher approves or declines one request of their class.
 * Approving also activates a new (not yet approved) student account — one step for the teacher.
 * Returns the student row, or null if there is no pending request.
 */
function decideJoinRequest($class, $request_id, $approve, $teacher_id) {
    global $db;
    $req = $db->fetchOne(
        "SELECT r.id, r.student_id, u.name, u.is_approved FROM class_join_requests r
         INNER JOIN users u ON r.student_id = u.id AND u.role = 'student' AND u.is_active = 1
         WHERE r.id = ? AND r.class_id = ? AND r.status = 'pending'", [(int) $request_id, (int) $class['id']]);
    if (!$req) return null;

    $db->update('class_join_requests', ['status' => $approve ? 'approved' : 'declined', 'decided_by' => $teacher_id, 'decided_at' => date('Y-m-d H:i:s')],
        'id = ?', [$req['id']]);

    if ($approve) {
        if (!(int) $req['is_approved']) {
            $db->update('users', ['is_approved' => 1], "id = ? AND role = 'student'", [$req['student_id']]);
        }
        if (!$db->fetchOne("SELECT id FROM class_members WHERE class_id = ? AND student_id = ?", [$class['id'], $req['student_id']])) {
            $db->insert('class_members', ['class_id' => $class['id'], 'student_id' => $req['student_id']]);
        }
        logActivity('approve_join', 'classes', "Approved {$req['name']} for {$class['class_name']}");
        notify($req['student_id'], 'join_approved', 'You joined ' . $class['class_name'],
            'Your teacher approved your request. The class is now in My Classes.', 'pages/classes/stream.php?id=' . $class['id'],
            $teacher_id, $class['id']);
    } else {
        logActivity('decline_join', 'classes', "Declined {$req['name']} for {$class['class_name']}");
        notify($req['student_id'], 'join_declined', 'Request to join ' . $class['class_name'] . ' was declined',
            'Ask your teacher if you think this is a mistake.', null, $teacher_id, $class['id']);
    }
    return $req;
}

// ==================== PRIVATE MESSAGES ====================

/**
 * Conversation context check: 'class' (general), or an assignment / quiz of THIS class.
 * Returns [type, id, title] or null.
 */
function messageContext($class_id, $type, $context_id) {
    global $db;
    if ($type === 'class') return ['class', null, 'General'];
    $tables = ['assignment' => 'assignments', 'quiz' => 'quizzes', 'lecture' => 'lectures'];
    if (!isset($tables[$type])) return null;
    $row = $db->fetchOne("SELECT id, title FROM {$tables[$type]} WHERE id = ? AND class_id = ?", [(int) $context_id, (int) $class_id]);
    return $row ? [$type, (int) $row['id'], $row['title']] : null;
}

/** URL of a conversation on the class Messages page. */
function messageThreadUrl($class_id, $student_id, $type, $context_id) {
    return 'pages/classes/messages.php?id=' . (int) $class_id . '&s=' . (int) $student_id . '&c=' . $type
        . ($context_id ? '&cid=' . (int) $context_id : '');
}

/**
 * Add a private message to a student's conversation with the class teacher and
 * notify the other side. $author_id is the student or the teacher.
 */
function postPrivateMessage($class, $student_id, $author_id, $author_name, array $ctx, $body) {
    global $db;
    [$type, $cid, $title] = $ctx;
    $id = $db->insert('private_messages', [
        'class_id' => $class['id'], 'student_id' => (int) $student_id, 'user_id' => (int) $author_id,
        'context_type' => $type, 'context_id' => $cid, 'body' => $body,
    ]);
    $title_text = $author_name . ' sent you a private message';
    $msg = ($type === 'class' ? $class['class_name'] : $title) . ': ' . mb_strimwidth($body, 0, 140, '…');
    $link = messageThreadUrl($class['id'], $student_id, $type, $cid);
    if ((int) $author_id === (int) $student_id) {
        notifyClassTeachers($class['id'], 'private_message', $title_text, $msg, $link, $author_id);   // every teacher of the class
    } else {
        notify((int) $student_id, 'private_message', $title_text, $msg, $link, $author_id, $class['id']);
    }
    return $id;
}

/** Messages of one conversation, oldest first. */
function getThreadMessages($class_id, $student_id, $type, $context_id) {
    global $db;
    return $db->fetchAll(
        "SELECT m.*, u.name AS author, u.role FROM private_messages m INNER JOIN users u ON m.user_id = u.id
         WHERE m.class_id = ? AND m.student_id = ? AND m.context_type = ? AND m.context_id <=> ?
         ORDER BY m.created_at, m.id", [(int) $class_id, (int) $student_id, $type, $context_id]);
}

/**
 * Mark everything the other side wrote in a conversation as read.
 * The teachers of a class are one side: a teacher opening the thread marks the STUDENT's
 * messages read (never another teacher's — that would show "Seen" to the student wrongly).
 */
function markThreadRead($class_id, $student_id, $type, $context_id, $viewer_id) {
    global $db;
    $other_side = (int) $viewer_id === (int) $student_id ? 'user_id <> student_id' : 'user_id = student_id';
    $db->query(
        "UPDATE private_messages SET is_read = 1
         WHERE class_id = ? AND student_id = ? AND context_type = ? AND context_id <=> ? AND {$other_side} AND is_read = 0",
        [(int) $class_id, (int) $student_id, $type, $context_id]);
}

/** Unread messages for the viewer in a class (teacher: from all students; student: from the teacher). */
function unreadMessageCount($class_id, $viewer_id, $is_owner) {
    global $db;
    return $is_owner
        ? (int) $db->fetchOne("SELECT COUNT(*) AS c FROM private_messages WHERE class_id = ? AND is_read = 0 AND user_id = student_id",
            [(int) $class_id])['c']
        : (int) $db->fetchOne("SELECT COUNT(*) AS c FROM private_messages WHERE class_id = ? AND student_id = ? AND is_read = 0 AND user_id <> ?",
            [(int) $class_id, (int) $viewer_id, (int) $viewer_id])['c'];
}

/**
 * Conversations of a class, newest activity first (teacher: all students; student: their own).
 * Each: student_id, student_name, context_type, context_id, context_title, last_at, last_body, last_author, total, unread.
 */
function getConversations($class_id, $viewer_id, $is_owner) {
    global $db;
    $params = [(int) $viewer_id, (int) $class_id];
    $only = '';
    if (!$is_owner) {
        $only = ' AND m.student_id = ?';
        $params[] = (int) $viewer_id;
    }
    $rows = $db->fetchAll(
        "SELECT m.student_id, m.context_type, m.context_id, MAX(m.created_at) AS last_at, COUNT(*) AS total,
                SUM(m.is_read = 0 AND m.user_id <> ?) AS unread, MAX(m.id) AS last_id
         FROM private_messages m
         WHERE m.class_id = ?{$only}
         GROUP BY m.student_id, m.context_type, m.context_id
         ORDER BY last_at DESC", $params);
    foreach ($rows as &$r) {
        $last = $db->fetchOne("SELECT m.body, u.name FROM private_messages m INNER JOIN users u ON m.user_id = u.id WHERE m.id = ?", [$r['last_id']]);
        $r['last_body'] = $last['body'] ?? '';
        $r['last_author'] = $last['name'] ?? '';
        $r['student_name'] = $db->fetchOne("SELECT name FROM users WHERE id = ?", [$r['student_id']])['name'] ?? '';
        $ctx = messageContext($class_id, $r['context_type'], $r['context_id']);
        $r['context_title'] = $ctx ? $ctx[2] : '(deleted)';
        $r['unread'] = (int) $r['unread'];
    }
    return $rows;
}

// ==================== OVERVIEW STRIP ====================

/**
 * Numbers for the overview strip at the top of every class page.
 * Uses the same gradebook / attendance helpers as Grades and Insights, so the figures match.
 * Teacher: students, join requests, class average, today's attendance, work to grade.
 * Student: my grade, my attendance %, missing work, next due item.
 */
function getClassOverview($class, $user_id, $is_owner) {
    global $db;
    $class_id = (int) $class['id'];
    $book = getClassGradebook($class_id, $is_owner ? null : (int) $user_id, !$is_owner);

    if ($is_owner) {
        $pcts = array_values(array_filter(array_column($book['rows'], 'percent'), fn($p) => $p !== null));
        $to_grade = 0;
        foreach ($book['rows'] as $r) {
            foreach ($r['cells'] as $c) {
                if (in_array($c['state'], ['submitted', 'late'], true)) $to_grade++;
            }
        }
        $marked_today = (int) ($db->fetchOne(
            "SELECT COUNT(*) AS c FROM attendance WHERE class_id = ? AND date = CURDATE()", [$class_id])['c'] ?? 0);
        return [
            'students' => count($book['rows']),
            'requests' => count(getJoinRequests($class_id)),
            'average'  => $pcts ? round(array_sum($pcts) / count($pcts), 1) : null,
            'marked_today' => $marked_today,
            'to_grade' => $to_grade,
        ];
    }

    $row = $book['rows'][0] ?? null;
    $missing = 0;
    $next = null;
    $now = time();
    foreach ($book['columns'] as $i => $col) {
        $state = $row ? $row['cells'][$i]['state'] : 'pending';
        if ($state === 'missing') $missing++;
        if (in_array($state, ['pending', 'open'], true) && !empty($col['due']) && strtotime($col['due']) > $now
            && ($next === null || strtotime($col['due']) < strtotime($next['due']))) {
            $next = $col;
        }
    }
    $att = getAttendanceSummary($class_id, (int) $user_id)[(int) $user_id] ?? null;
    return [
        'grade'   => $row['percent'] ?? null,
        'attendance' => $att['rate'] ?? null,
        'att_days'   => $att['total'] ?? 0,
        'missing' => $missing,
        'next'    => $next,
    ];
}

// ==================== CLASSWORK PROGRESS (opened / done) ====================

/** Kinds of classwork a student can open / mark done: type => [table, label]. */
function progressItemTypes() {
    return ['lecture' => ['lectures', 'Lecture'], 'resource' => ['resources', 'Resource']];
}

/** The lecture / resource row if it belongs to the class, else null. */
function progressItem($class_id, $type, $item_id) {
    global $db;
    $types = progressItemTypes();
    if (!isset($types[$type])) return null;
    return $db->fetchOne("SELECT id, title, class_id FROM {$types[$type][0]} WHERE id = ? AND class_id = ?", [(int) $item_id, (int) $class_id]);
}

/** A student opened a lecture / downloaded a resource (teachers are not tracked). */
function recordContentOpen(array $class, $user_id, $type, $item_id) {
    global $db;
    if (!empty($class['is_owner']) || !isset(progressItemTypes()[$type])) return;
    $db->query(
        "INSERT INTO content_progress (class_id, student_id, item_type, item_id, first_opened_at, last_opened_at, open_count)
         VALUES (?, ?, ?, ?, NOW(), NOW(), 1)
         ON DUPLICATE KEY UPDATE first_opened_at = COALESCE(first_opened_at, NOW()), last_opened_at = NOW(), open_count = open_count + 1",
        [(int) $class['id'], (int) $user_id, $type, (int) $item_id]);
}

/** A student marks a lecture / resource done (or not done). Marking done also counts as opened. */
function setContentDone($class_id, $student_id, $type, $item_id, $done) {
    global $db;
    $db->query(
        "INSERT INTO content_progress (class_id, student_id, item_type, item_id, first_opened_at, last_opened_at, done_at)
         VALUES (?, ?, ?, ?, " . ($done ? 'NOW(), NOW(), NOW()' : 'NULL, NULL, NULL') . ")
         ON DUPLICATE KEY UPDATE done_at = " . ($done ? 'NOW()' : 'NULL') . ($done ? ', first_opened_at = COALESCE(first_opened_at, NOW())' : ''),
        [(int) $class_id, (int) $student_id, $type, (int) $item_id]);
}

/** One student's progress in a class: "type:id" => row. */
function getStudentProgress($class_id, $student_id) {
    global $db;
    $out = [];
    foreach ($db->fetchAll("SELECT * FROM content_progress WHERE class_id = ? AND student_id = ?", [(int) $class_id, (int) $student_id]) as $r) {
        $out[$r['item_type'] . ':' . $r['item_id']] = $r;
    }
    return $out;
}

/** Per item, how many CURRENT members opened / finished it: "type:id" => ['opened' => n, 'done' => n]. */
function getClassReach($class_id) {
    global $db;
    $out = [];
    foreach ($db->fetchAll(
        "SELECT p.item_type, p.item_id, SUM(p.first_opened_at IS NOT NULL) AS opened, SUM(p.done_at IS NOT NULL) AS done
         FROM content_progress p
         INNER JOIN class_members cm ON cm.class_id = p.class_id AND cm.student_id = p.student_id
         WHERE p.class_id = ? GROUP BY p.item_type, p.item_id", [(int) $class_id]) as $r) {
        $out[$r['item_type'] . ':' . $r['item_id']] = ['opened' => (int) $r['opened'], 'done' => (int) $r['done']];
    }
    return $out;
}

/** Every member of the class with their progress on one item (teacher roster), not-opened first. */
function getItemRoster($class_id, $type, $item_id) {
    global $db;
    return $db->fetchAll(
        "SELECT u.id, u.name, u.email, p.first_opened_at, p.last_opened_at, p.open_count, p.done_at
         FROM class_members cm
         INNER JOIN users u ON cm.student_id = u.id
         LEFT JOIN content_progress p ON p.student_id = u.id AND p.item_type = ? AND p.item_id = ?
         WHERE cm.class_id = ?
         ORDER BY (p.done_at IS NOT NULL), (p.first_opened_at IS NOT NULL), u.name", [$type, (int) $item_id, (int) $class_id]);
}

/** Remove progress rows of a deleted lecture / resource. */
function deleteItemProgress($type, $item_id) {
    global $db;
    $db->query("DELETE FROM content_progress WHERE item_type = ? AND item_id = ?", [$type, (int) $item_id]);
}

// ==================== ASSIGNMENT MATERIALS (links + class resources) ====================

/** Most links an assignment can carry. */
const MAX_ASSIGNMENT_LINKS = 5;

/** Materials of an assignment, in order: rows with kind, url/title (link) or resource_id + resource title/type. */
function getAssignmentMaterials($assignment_id) {
    global $db;
    return $db->fetchAll(
        "SELECT m.id, m.kind, m.url, m.title, m.resource_id, r.title AS resource_title, r.file_type, r.file_size
         FROM assignment_materials m LEFT JOIN resources r ON m.resource_id = r.id
         WHERE m.assignment_id = ? ORDER BY m.position, m.id", [(int) $assignment_id]);
}

/**
 * Validate the materials part of the assignment form.
 * $urls / $titles: parallel arrays from link_url[] / link_title[]; blank rows are skipped.
 * Returns [links, resource_ids, errors] — errors keyed by row index (shown under that row).
 * Only http(s) links; only resources of $class_id are kept (others are silently dropped).
 */
function validateAssignmentMaterials($class_id, $urls, $titles, $resource_ids) {
    global $db;
    $links = [];
    $errors = [];
    $urls = is_array($urls) ? array_values($urls) : [];
    $titles = is_array($titles) ? array_values($titles) : [];
    foreach (array_slice($urls, 0, MAX_ASSIGNMENT_LINKS, true) as $i => $url) {
        $url = trim((string) $url);
        $title = trim((string) ($titles[$i] ?? ''));
        if ($url === '' && $title === '') continue;
        if ($url === '') {
            $errors[$i] = 'Add the link address, or clear the title';
        } elseif (mb_strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $errors[$i] = 'Enter a full web address starting with http:// or https://';
        } elseif (mb_strlen($title) > 200) {
            $errors[$i] = 'Link title must be under 200 characters';
        } else {
            $links[] = ['url' => $url, 'title' => $title];
        }
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', is_array($resource_ids) ? $resource_ids : []))));
    $valid = [];
    if ($ids && $class_id) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $valid = array_map('intval', array_column($db->fetchAll(
            "SELECT id FROM resources WHERE class_id = ? AND id IN ({$in})", array_merge([(int) $class_id], $ids)), 'id'));
        $valid = array_values(array_filter($ids, fn($id) => in_array($id, $valid, true)));   // keep the teacher's order
    }
    return [$links, $valid, $errors];
}

/** Replace an assignment's materials (resources first, then links). */
function saveAssignmentMaterials($assignment_id, array $links, array $resource_ids) {
    global $db;
    $db->query("DELETE FROM assignment_materials WHERE assignment_id = ?", [(int) $assignment_id]);
    $pos = 0;
    foreach ($resource_ids as $rid) {
        $db->insert('assignment_materials', ['assignment_id' => (int) $assignment_id, 'kind' => 'resource', 'resource_id' => (int) $rid, 'position' => $pos++]);
    }
    foreach ($links as $l) {
        $db->insert('assignment_materials', ['assignment_id' => (int) $assignment_id, 'kind' => 'link', 'url' => $l['url'],
                                             'title' => $l['title'] !== '' ? $l['title'] : null, 'position' => $pos++]);
    }
}

// ==================== PEOPLE: roster details ====================

/**
 * Each member's latest action in THIS class: student_id => datetime (members with none are absent).
 * Counts: handing in work, starting / finishing a quiz, opening a lecture or resource,
 * a private message, a stream post or a class comment.
 */
function getClassLastActivity($class_id) {
    global $db;
    $cid = (int) $class_id;
    $rows = $db->fetchAll(
        "SELECT uid, MAX(t) AS last_at FROM (
            SELECT s.student_id AS uid, s.submitted_at AS t FROM submissions s INNER JOIN assignments a ON s.assignment_id = a.id WHERE a.class_id = ?
            UNION ALL SELECT qa.student_id, GREATEST(qa.started_at, COALESCE(qa.submitted_at, qa.started_at)) FROM quiz_attempts qa INNER JOIN quizzes q ON qa.quiz_id = q.id WHERE q.class_id = ?
            UNION ALL SELECT student_id, last_opened_at FROM content_progress WHERE class_id = ?
            UNION ALL SELECT user_id, created_at FROM private_messages WHERE class_id = ?
            UNION ALL SELECT user_id, created_at FROM announcements WHERE class_id = ?
            UNION ALL SELECT c.user_id, c.created_at FROM announcement_comments c INNER JOIN announcements a ON c.announcement_id = a.id WHERE a.class_id = ?
         ) x
         WHERE uid IN (SELECT student_id FROM class_members WHERE class_id = ?) AND t IS NOT NULL
         GROUP BY uid", array_fill(0, 7, $cid));
    return array_column($rows, 'last_at', 'uid');
}

/**
 * Roster with numbers for the teacher (People tab + roster CSV):
 * id, name, email, joined_at, last_active, percent (overall grade), attendance (rate %), missing (past-due work not turned in).
 */
function getClassRoster($class_id) {
    global $db;
    $book = getClassGradebook($class_id);
    $att = getAttendanceSummary($class_id);
    $last = getClassLastActivity($class_id);
    $joined = array_column($db->fetchAll("SELECT student_id, joined_at FROM class_members WHERE class_id = ?", [(int) $class_id]), 'joined_at', 'student_id');
    $out = [];
    foreach ($book['rows'] as $r) {
        $out[] = [
            'id' => $r['id'], 'name' => $r['name'], 'email' => $r['email'],
            'joined_at' => $joined[$r['id']] ?? null,
            'last_active' => $last[$r['id']] ?? null,
            'percent' => $r['percent'],
            'attendance' => $att[$r['id']]['rate'] ?? null,
            'missing' => count(array_filter($r['cells'], fn($c) => $c['state'] === 'missing')),
        ];
    }
    return $out;
}

// ==================== GRADING ====================

/**
 * Grade (or re-grade) one submission — used by the assignment page and the gradebook.
 * $submission must already be checked to belong to $assignment (and the teacher to own the class).
 * Returns null on success, or ['marks' | 'feedback' => message] for the inline error.
 * On success: saves marks + feedback, logs it and notifies the student.
 */
function gradeSubmission(array $assignment, array $submission, $marks_raw, $feedback, $teacher_id, $class_id) {
    global $db;
    $marks_raw = trim((string) $marks_raw);
    $feedback = trim((string) $feedback);
    if (!is_numeric($marks_raw) || (float) $marks_raw < 0 || (float) $marks_raw > (float) $assignment['max_marks']) {
        return ['marks' => 'Enter marks from 0 to ' . (int) $assignment['max_marks']];
    }
    if (mb_strlen($feedback) > 2000) {
        return ['feedback' => 'Feedback must be under 2,000 characters'];
    }
    $marks = round((float) $marks_raw, 2);
    $db->update('submissions', [
        'marks_obtained' => $marks,
        'feedback'       => $feedback !== '' ? $feedback : null,
        'graded_by'      => $teacher_id,
        'graded_at'      => date('Y-m-d H:i:s'),
        'grade_source'   => gradeSourceFor($submission['id'], $marks, $feedback),   // teacher / ai_accepted / ai_edited
        'status'         => 'graded',
    ], 'id = ?', [$submission['id']]);
    logActivity('grade_submission', 'assignments', "Graded {$submission['student_name']} on assignment #{$assignment['id']}: {$marks}/{$assignment['max_marks']}");
    // Held results (manual release) are announced by releaseResults() instead
    if (resultsReleased($assignment)) notify(
        $submission['student_id'],
        'submission_graded',
        'Graded: ' . $assignment['title'],
        "You got {$marks}/{$assignment['max_marks']}" . ($feedback !== '' ? ' — see the teacher feedback.' : '.'),
        'pages/assignments/view.php?id=' . $assignment['id'],
        $teacher_id,
        $class_id
    );
    return null;
}

/**
 * Send a submission back to the student for changes: not graded, not waiting for the teacher — waiting for the student, with the teacher's note.
 * Any earlier mark is cleared (the student hands it in again and it is graded again). Returns an error array (field => message) or null.
 */
function returnSubmission(array $assignment, array $submission, $note, $teacher_id, $class_id) {
    global $db;
    $note = trim((string) $note);
    if ($note === '') return ['note' => 'Say what the student should change'];
    if (mb_strlen($note) > 2000) return ['note' => 'The note must be under 2,000 characters'];
    if (!in_array($submission['status'], ['submitted', 'late', 'graded'], true)) return ['note' => 'This work is not waiting for you'];
    $db->update('submissions', ['status' => 'returned', 'marks_obtained' => null, 'graded_by' => null, 'graded_at' => null, 'grade_source' => null,
        'returned_at' => date('Y-m-d H:i:s'), 'return_note' => $note, 'return_source' => returnSourceFor($submission['id'], $note)], 'id = ?', [$submission['id']]);
    logActivity('return_submission', 'assignments', "Returned {$submission['student_name']}'s work on assignment #{$assignment['id']} for changes");
    notify($submission['student_id'], 'submission_returned', 'Changes requested: ' . $assignment['title'], $note,
        'pages/assignments/view.php?id=' . $assignment['id'], $teacher_id, $class_id);
    return null;
}

/** "Due in 3 days" / "Due today" / "Closed 16 days ago" / "No due date" — the due date in words (translated by rules). */
function assignmentDueText($due) {
    if (empty($due)) return 'No due date';
    $diff = strtotime($due) - time(); $d = (int) floor(abs($diff) / 86400);
    if ($diff >= 0) return $d === 0 ? 'Due today' : ($d === 1 ? 'Due tomorrow' : 'Due in ' . $d . ' days');
    return $d === 0 ? 'Closed today' : ($d === 1 ? 'Closed yesterday' : 'Closed ' . $d . ' days ago');
}

// ==================== SCHEDULED STREAM POSTS ====================

/** SQL condition: the post is visible to $viewer_id (published, or their own). Use with alias a. */
function announcementVisibleSql() {
    return '(a.publish_at IS NULL OR a.publish_at <= NOW() OR a.user_id = ?)';
}

/**
 * Notify the class about teacher posts whose publish time has come (once per post).
 * $class_id = null: every class (cron). Each post is claimed with an UPDATE first,
 * so two requests at the same moment never notify twice.
 * Returns how many posts were published.
 */
function publishDueAnnouncements($class_id = null) {
    global $db;
    $params = [];
    $only = '';
    if ($class_id !== null) {
        $only = ' AND a.class_id = ?';
        $params[] = (int) $class_id;
    }
    $due = $db->fetchAll(
        "SELECT a.id, a.class_id, a.user_id, a.body, c.class_name FROM announcements a INNER JOIN classes c ON a.class_id = c.id
         WHERE a.notified_at IS NULL AND (a.publish_at IS NULL OR a.publish_at <= NOW()){$only}", $params);
    $n = 0;
    foreach ($due as $a) {
        if (!$db->query("UPDATE announcements SET notified_at = NOW() WHERE id = ? AND notified_at IS NULL", [$a['id']])->rowCount()) {
            continue;   // another request got there first
        }
        notifyClassStudents($a['class_id'], 'announcement_posted', 'New announcement in ' . $a['class_name'],
            mb_strimwidth($a['body'], 0, 140, '…'), 'pages/classes/stream.php?id=' . (int) $a['class_id'] . '#post-' . (int) $a['id'], $a['user_id']);
        $n++;
    }
    return $n;
}

// ==================== CLASS TEACHERS (owner + co-teachers) ====================

/** Make $teacher_id the owner row of a new class (every place that creates a class calls this). */
function addClassOwner($class_id, $teacher_id) {
    global $db;
    $db->query("INSERT IGNORE INTO class_teachers (class_id, teacher_id, role, added_by) VALUES (?, ?, 'owner', ?)",
        [(int) $class_id, (int) $teacher_id, (int) $teacher_id]);
}

/** Teachers of a class, owner first: id, name, email, role, added_at. */
function getClassTeachers($class_id) {
    global $db;
    return $db->fetchAll(
        "SELECT u.id, u.name, u.email, ct.role, ct.created_at AS added_at FROM class_teachers ct
         INNER JOIN users u ON ct.teacher_id = u.id
         WHERE ct.class_id = ? ORDER BY ct.role = 'owner' DESC, u.name", [(int) $class_id]);
}

/** Notify every teacher of a class (owner + co-teachers), except $sender_id. */
function notifyClassTeachers($class_id, $type, $title, $message = '', $link = null, $sender_id = null) {
    global $db;
    foreach ($db->fetchAll("SELECT teacher_id FROM class_teachers WHERE class_id = ?", [(int) $class_id]) as $t) {
        if ((int) $t['teacher_id'] !== (int) $sender_id) {
            notify($t['teacher_id'], $type, $title, $message, $link, $sender_id, $class_id);
        }
    }
}

// ==================== PRIVACY: who may see whom ====================

/**
 * Student accounts waiting for approval that $viewer may see:
 *   super admin → every pending account;  teacher → only accounts that asked to join
 *   one of THEIR classes. 'wants' lists only the viewer's own classes (admin: all, with teacher).
 * Each: id, name, email, photo, created_at, wants, my_requests (count of pending requests to my classes).
 */
function getPendingStudentsFor($viewer_id, $is_admin) {
    global $db;
    $mine = "EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.class_id = r.class_id AND ct.teacher_id = ?)";
    return $db->fetchAll(
        "SELECT u.id, u.name, u.email, u.photo, u.created_at,
                (SELECT GROUP_CONCAT(" . ($is_admin ? "CONCAT(c.class_name, ' · ', t.name)" : "c.class_name") . " SEPARATOR '; ')
                   FROM class_join_requests r INNER JOIN classes c ON r.class_id = c.id INNER JOIN users t ON c.teacher_id = t.id
                  WHERE r.student_id = u.id AND r.status = 'pending'" . ($is_admin ? '' : " AND {$mine}") . ") AS wants,
                (SELECT COUNT(*) FROM class_join_requests r WHERE r.student_id = u.id AND r.status = 'pending' AND {$mine}) AS my_requests
         FROM users u
         WHERE u.role = 'student' AND u.is_approved = 0 AND u.is_active = 1" . ($is_admin ? '' : "
           AND EXISTS (SELECT 1 FROM class_join_requests r WHERE r.student_id = u.id AND r.status = 'pending' AND {$mine})") . "
         ORDER BY u.created_at ASC",
        // placeholders: admin → my_requests only; teacher → wants + my_requests + WHERE
        array_fill(0, $is_admin ? 1 : 3, (int) $viewer_id));
}

/**
 * May $viewer see $target's profile details (photo)? Yes for yourself, the super admin, and
 * people who share a class with you (teacher ↔ member, member ↔ member, co-teachers), or a
 * student who asked to join one of your classes.
 */
function canSeeUser($viewer_id, $target_id, $viewer_is_admin = false) {
    global $db;
    $v = (int) $viewer_id;
    $t = (int) $target_id;
    if ($v === $t || $viewer_is_admin) return true;
    $people = "SELECT class_id, teacher_id AS uid FROM class_teachers UNION SELECT class_id, student_id FROM class_members";
    // shares a class (teacher / co-teacher / member on both sides)
    if ($db->fetchOne("SELECT 1 FROM ({$people}) a INNER JOIN ({$people}) b ON a.class_id = b.class_id WHERE a.uid = ? AND b.uid = ? LIMIT 1", [$v, $t])) {
        return true;
    }
    // the target asked to join a class the viewer teaches (teachers only — never other students)
    return (bool) $db->fetchOne(
        "SELECT 1 FROM class_join_requests r INNER JOIN class_teachers ct ON ct.class_id = r.class_id
         WHERE ct.teacher_id = ? AND r.student_id = ? AND r.status = 'pending' LIMIT 1", [$v, $t]);
}

// ==================== ATTENDANCE REPORTS ====================

/** A valid Y-m-d date, else null. */
function attValidDate($value) {
    $d = DateTime::createFromFormat('Y-m-d', (string) $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/**
 * The reporting period from ?from= / ?to= (Y-m-d). Missing or invalid: first / last marked day of the class.
 * Returns [from, to] — both null when the class has no attendance yet.
 */
function attendancePeriod($class_id, $from, $to) {
    global $db;
    $row = $db->fetchOne("SELECT MIN(date) AS a, MAX(date) AS b FROM attendance WHERE class_id = ?", [(int) $class_id]);
    $from = attValidDate($from) ?? $row['a'];
    $to = attValidDate($to) ?? $row['b'];
    if ($from && $to && $from > $to) [$from, $to] = [$to, $from];
    return [$from, $to];
}

/** Reasons a teacher can pick for a late / absent mark (key => label). "Unexcused" is stored as excused = 0. */
function attendanceReasons() {
    return ['sick' => 'Sick', 'family' => 'Family matter', 'travel' => 'Travel', 'transport' => 'Transport / weather', 'other' => 'Other reason'];
}

/** Days after which only the school's super admin may change attendance (0 = never lock). */
function attendanceLockDays() {
    return max(0, (int) getSetting('attendance_lock_days', 0));
}

/** True when this day can no longer be edited by the signed-in user. */
function attendanceLocked($date) {
    $n = attendanceLockDays();
    return $n > 0 && $date < date('Y-m-d', strtotime("-{$n} days")) && !(function_exists('isAdmin') && isAdmin());
}

/** Weekdays (ISO 1 = Monday … 7 = Sunday) with normally no class, e.g. [5] for Friday. */
function attendanceOffWeekdays() {
    $out = [];
    foreach (explode(',', (string) getSetting('attendance_off_weekdays', '')) as $d) if ($d >= 1 && $d <= 7) $out[] = (int) $d;
    return $out;
}

/**
 * Today's attendance for a teacher's active classes (the Attendance module's "Today" list).
 * Each: id, class_name, theme …, students, marked_today, last_marked and state —
 *   todo (nobody marked yet) · partial (some marked) · done (every student marked) · noclass (the day is marked as a no-class day) ·
 *   off (a weekly day off, nothing marked) · empty (no students yet). Needs-attention states (todo, partial) come first.
 */
function attendanceTodayList($teacher_id) {
    global $db;
    $today = date('Y-m-d');
    $off = in_array((int) date('N'), attendanceOffWeekdays(), true);
    $rows = $db->fetchAll(
        "SELECT c.*,
                (SELECT COUNT(*) FROM class_members cm WHERE cm.class_id = c.id) AS students,
                (SELECT COUNT(*) FROM attendance a WHERE a.class_id = c.id AND a.date = ?) AS marked_today,
                (SELECT COUNT(*) FROM attendance_days d WHERE d.class_id = c.id AND d.date = ?) AS noclass_today,
                (SELECT MAX(a.date) FROM attendance a WHERE a.class_id = c.id) AS last_marked
         FROM classes c WHERE " . teachesClassSql('c') . " AND c.is_active = 1 ORDER BY c.class_name", [$today, $today, (int) $teacher_id]);
    foreach ($rows as &$r) {
        $students = (int) $r['students']; $marked = (int) $r['marked_today'];
        if (!$students) $r['state'] = 'empty';
        elseif ($marked >= $students) $r['state'] = 'done';
        elseif ($marked > 0) $r['state'] = 'partial';
        elseif ($r['noclass_today']) $r['state'] = 'noclass';
        elseif ($off) $r['state'] = 'off';
        else $r['state'] = 'todo';
    }
    unset($r);
    usort($rows, fn($a, $b) => (int) in_array($b['state'], ['todo', 'partial'], true) <=> (int) in_array($a['state'], ['todo', 'partial'], true));
    return $rows;
}

/** No-class days of a class in a period: date => label. */
function attendanceNoClassDays($class_id, $from, $to) {
    global $db;
    $out = [];
    foreach ($db->fetchAll("SELECT date, label FROM attendance_days WHERE class_id = ? AND date BETWEEN ? AND ?", [(int) $class_id, $from, $to]) as $r) $out[$r['date']] = $r['label'];
    return $out;
}

/** Write one line of edit history. $old / $new are [status, note] or null. */
/** A session (period) number of the day: 1-8, default 1. */
function attendanceSession($v) {
    $n = (int) $v;
    return $n >= 1 && $n <= 8 ? $n : 1;
}

/** Sessions that have marks on one day (always at least [1] so an empty day still shows Session 1). */
function attendanceSessionsOn($class_id, $date) {
    global $db;
    $r = array_map('intval', array_column($db->fetchAll("SELECT DISTINCT session FROM attendance WHERE class_id = ? AND date = ? ORDER BY session", [(int) $class_id, $date]), 'session'));
    return $r ?: [1];
}

/** Every (date, session) with marks in a period, oldest first — the columns of a register. Days with one session look exactly as before. */
function attendanceSlots($class_id, $from, $to) {
    global $db;
    return $db->fetchAll("SELECT DISTINCT date, session FROM attendance WHERE class_id = ? AND date BETWEEN ? AND ? ORDER BY date, session", [(int) $class_id, $from, $to]);
}

function attendanceLogChange($class_id, $student_id, $date, $old, $new, $user_id, $session = 1) {
    global $db;
    $db->insert('attendance_log', [
        'class_id' => (int) $class_id, 'student_id' => (int) $student_id, 'date' => $date, 'session' => attendanceSession($session),
        'old_status' => $old[0] ?? null, 'new_status' => $new[0] ?? null,
        'old_note' => $old[1] ?? null, 'new_note' => $new[1] ?? null, 'changed_by' => (int) $user_id,
    ]);
}

// ==================== QUIZ REACTIVATION ====================

/** The student's current "quiz reopened for you" permission (latest, not past its deadline), or null. */
function quizActiveReopen($quiz_id, $student_id) {
    global $db;
    return $db->fetchOne(
        "SELECT * FROM quiz_reopens WHERE quiz_id = ? AND student_id = ? AND (until IS NULL OR until > NOW())
         ORDER BY reopened_at DESC, id DESC LIMIT 1", [(int) $quiz_id, (int) $student_id]) ?: null;
}

/** Is the quiz closed for this student? The due date passed and the teacher has not reopened it for them. */
function quizClosedFor(array $quiz, $student_id) {
    return !empty($quiz['due_date']) && time() > strtotime($quiz['due_date']) && !quizActiveReopen($quiz['id'], $student_id);
}

/**
 * Reactivate a quiz for chosen students of the class: their previous attempt (finished, timed out or half done)
 * is cleared so they can take it again — the old score is kept in quiz_reopens — and the due date no longer
 * stops them until $until (a 'Y-m-d H:i:s' string, or null for no deadline). Students who never started simply
 * get access again. Each student is notified. With $keep ("only give more time") nobody's attempt is cleared: a half-done attempt simply
 * stays open until $until, students with a finished attempt are left alone. Returns ['done' => n, 'skipped' => n (not in the class), 'finished' => n].
 */
function quizReopen(array $quiz, array $class, array $student_ids, $until, $teacher_id, $keep = false) {
    global $db;
    $done = 0; $skipped = 0; $finished = 0;
    foreach (array_unique(array_map('intval', $student_ids)) as $sid) {
        if (!$sid || !$db->fetchOne("SELECT id FROM class_members WHERE class_id = ? AND student_id = ?", [(int) $class['id'], $sid])) { $skipped++; continue; }
        $att = $db->fetchOne("SELECT * FROM quiz_attempts WHERE quiz_id = ? AND student_id = ?", [(int) $quiz['id'], $sid]);
        $state = !$att ? 'none' : ($att['submitted_at'] === null ? 'open' : ($att['timed_out'] ? 'timeout' : 'done'));
        if ($keep && $state !== 'none' && $state !== 'open') { $finished++; continue; }   // "only more time": a finished attempt has nothing to continue
        $db->insert('quiz_reopens', [
            'quiz_id' => (int) $quiz['id'], 'student_id' => $sid, 'reopened_by' => (int) $teacher_id, 'until' => $until,
            'prev_state' => $state, 'prev_score' => $att && $att['submitted_at'] !== null ? $att['score'] : null,
            'prev_max' => $att ? (int) $att['max_score'] : null,
        ]);
        if ($att && !$keep) $db->delete('quiz_attempts', 'id = ?', [$att['id']]);   // its answers go with it (ON DELETE CASCADE); with $keep the attempt stays
        notify($sid, 'quiz_reopened', 'Quiz reopened: ' . $quiz['title'],
            $class['class_name'] . ($keep ? ' — your teacher gave you more time for this quiz' : ' — your teacher reopened this quiz for you') . ($until ? ' until ' . date('M d, h:i A', strtotime($until)) : '') . '.',
            'pages/quizzes/view.php?id=' . (int) $quiz['id'], (int) $teacher_id, (int) $class['id']);
        $done++;
    }
    return ['done' => $done, 'skipped' => $skipped, 'finished' => $finished];
}

// ==================== LIMITS (Settings › At-risk limits) ====================

/** Attendance below this percentage marks a student as "at risk" (default 75). */
function attLow() { static $v = null; return $v ?? ($v = max(1, min(100, (int) getSetting('att_low', 75)))); }
/** This many absences in a row also mark a student (default 3). */
function attStreak() { static $v = null; return $v ?? ($v = max(2, min(30, (int) getSetting('att_streak', 3)))); }
/** A grade (percent) below this is "low" (default 50). */
function lowGradePct() { static $v = null; return $v ?? ($v = max(1, min(100, (int) getSetting('low_grade', 50)))); }

// ==================== ATTENDANCE SELF CHECK-IN ====================

/** The class's open check-in (code not closed or expired), or null. */
function attendanceActiveCheckin($class_id) {
    global $db;
    return $db->fetchOne("SELECT * FROM attendance_checkin WHERE class_id = ? AND closed_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 1", [(int) $class_id]) ?: null;
}

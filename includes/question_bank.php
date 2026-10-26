<?php
/**
 * Question bank: a teacher keeps quiz questions to reuse in later quizzes. Private — a teacher sees and uses only their own questions.
 * The same question (same text and options) is stored once per teacher. Used by pages/quizzes/create.php (save when publishing, insert
 * from the bank) and pages/quizzes/bank.php (list / delete as JSON).
 */
const QB_MAX_PER_LIST = 200;

/** A question as the quiz builder sends it: ['type', 'text', 'marks', 'model', 'options' => [...], 'correct' => index]. Returns the number newly kept. */
function qbSaveQuestions($teacherId, array $questions, $tag = '') {
    global $db;
    $tag = mb_substr(trim((string) $tag), 0, 60); $new = 0;
    foreach ($questions as $q) {
        $text = trim((string) ($q['text'] ?? '')); if ($text === '') continue;
        $short = ($q['type'] ?? '') === 'short'; $opts = [];
        if (!$short) foreach ((array) ($q['options'] ?? []) as $i => $o) $opts[] = ['t' => (string) $o, 'c' => ((int) ($q['correct'] ?? -1) === (int) $i) ? 1 : 0];
        $hash = sha1(mb_strtolower($text) . '|' . implode('|', array_map(fn($o) => mb_strtolower($o['t']), $opts)));
        if ($db->fetchOne("SELECT id FROM question_bank WHERE teacher_id = ? AND question_hash = ?", [(int) $teacherId, $hash])) continue;
        $db->insert('question_bank', ['teacher_id' => (int) $teacherId, 'tag' => $tag !== '' ? $tag : null, 'question_type' => $short ? 'short' : 'choice', 'question' => $text,
            'model_answer' => $short ? (string) ($q['model'] ?? '') : null, 'marks' => max(1, min(100, (int) ($q['marks'] ?? 1))), 'options' => $short ? null : json_encode($opts, JSON_UNESCAPED_UNICODE), 'question_hash' => $hash]);
        $new++;
    }
    return $new;
}

/** The teacher's questions, newest first; $search looks in the question text and the tag, $tag filters by tag. Each item is ready for the builder. */
function qbList($teacherId, $search = '', $tag = '') {
    global $db;
    $sql = "SELECT * FROM question_bank WHERE teacher_id = ?"; $p = [(int) $teacherId];
    if ($tag !== '') { $sql .= " AND tag = ?"; $p[] = $tag; }
    if ($search !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%'; $sql .= " AND (question LIKE ? OR tag LIKE ?)"; array_push($p, $like, $like); }
    $out = [];
    foreach ($db->fetchAll($sql . " ORDER BY id DESC LIMIT " . QB_MAX_PER_LIST, $p) as $r) {
        $opts = $r['options'] ? (json_decode($r['options'], true) ?: []) : [];
        $correct = 0; foreach ($opts as $i => $o) if (!empty($o['c'])) $correct = $i;
        $out[] = ['id' => (int) $r['id'], 'tag' => $r['tag'], 'type' => $r['question_type'], 'text' => $r['question'], 'marks' => (int) $r['marks'], 'model' => (string) $r['model_answer'],
                  'options' => array_column($opts, 't'), 'correct' => $correct];
    }
    return $out;
}

function qbTags($teacherId) {
    global $db;
    return array_column($db->fetchAll("SELECT tag, COUNT(*) n FROM question_bank WHERE teacher_id = ? AND tag IS NOT NULL GROUP BY tag ORDER BY tag", [(int) $teacherId]), 'n', 'tag');
}

function qbDelete($teacherId, $id) {
    global $db;
    return $db->delete('question_bank', 'id = ? AND teacher_id = ?', [(int) $id, (int) $teacherId]) > 0;
}

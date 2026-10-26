<?php
/**
 * SmartClass - copy a class (loaded by includes/functions.php)
 *   copyClass($source, $teacher_id, $name, $parts)   a new class with the same settings and, as chosen, the same
 *                                                    topics, lectures, assignments, quizzes and resources
 * What is copied and what is not:
 *   - students, join requests, submissions, grades, attempts, messages, progress, the stream: never
 *   - lectures arrive as DRAFTS (the teacher reads and publishes them; no students are notified)
 *   - assignments and quizzes arrive without a due date and with results not released; rubrics, questions,
 *     options, expected answers and release settings are kept
 *   - uploaded files (lecture pictures, resources) are copied under new random names; one that cannot be copied
 *     is left out and counted, never shared with the original (deleting one class never breaks the other)
 *   - lecture pictures used in the text are re-pointed to the copies; attachments of a lecture / assignment that
 *     are resources follow the copied resources, links are kept
 * The new class has a new join code and the copier as owner.
 */

/** Copy an uploaded file to a new random name in the same folder; returns the new relative path or null. */
function copyUploadedFile($rel_path) {
    $rel_path = (string) $rel_path;
    if (!preg_match('#^uploads/(lectures|resources)/[A-Za-z0-9._-]+$#', $rel_path)) return null;
    $from = STORAGE_ROOT . '/' . $rel_path;
    if (!is_file($from)) return null;
    $ext = strtolower(pathinfo($rel_path, PATHINFO_EXTENSION));
    $new = dirname($rel_path) . '/' . bin2hex(random_bytes(16)) . ($ext !== '' ? '.' . $ext : '');
    return @copy($from, STORAGE_ROOT . '/' . $new) ? $new : null;
}

/**
 * @param array $parts  any of: lectures, assignments, quizzes, resources
 * @return array ['class_id', 'code', 'counts' => [lectures, assignments, quizzes, resources, images], 'skipped_files' => n]
 */
function copyClass(array $source, $teacher_id, $name, array $parts, $year_id = null) {
    global $db;
    $want = fn($p) => in_array($p, $parts, true);
    $pdo = $db->getConnection();
    $counts = ['lectures' => 0, 'assignments' => 0, 'quizzes' => 0, 'resources' => 0, 'images' => 0];
    $skipped = 0;
    $new_files = [];   // copied files, removed again if the copy fails half-way

    $pdo->beginTransaction();
    try {
        do { $code = generateClassCode(8); } while ($db->exists('classes', 'class_code', $code));
        $new_id = $db->insert('classes', [
            'teacher_id' => $teacher_id, 'class_name' => $name, 'class_code' => $code, 'description' => $source['description'],
            'tags' => $source['tags'], 'image' => $source['image'], 'stream_permission' => $source['stream_permission'],
            'require_approval' => $source['require_approval'], 'theme' => $source['theme'], 'ai_students' => $source['ai_students'], 'ai_language' => $source['ai_language'] ?? 'screen',
            'year_id' => $year_id ?: currentAcademicYearId(),
        ]);
        addClassOwner($new_id, $teacher_id);

        // Topics (kept whenever anything that can belong to a topic is copied)
        $topic_map = [];
        if ($parts) {
            foreach ($db->fetchAll("SELECT * FROM class_topics WHERE class_id = ? ORDER BY position, id", [$source['id']]) as $t) {
                $topic_map[(int) $t['id']] = $db->insert('class_topics', ['class_id' => $new_id, 'name' => $t['name'], 'position' => $t['position']]);
            }
        }
        $topic = fn($id) => $id && isset($topic_map[(int) $id]) ? $topic_map[(int) $id] : null;

        // Resources first: attachments of lectures / assignments may point to them
        $res_map = [];
        if ($want('resources')) {
            foreach ($db->fetchAll("SELECT * FROM resources WHERE class_id = ? ORDER BY id", [$source['id']]) as $r) {
                $path = copyUploadedFile($r['file_path']);
                if ($path === null) { $skipped++; continue; }
                $new_files[] = $path;
                $res_map[(int) $r['id']] = $db->insert('resources', [
                    'class_id' => $new_id, 'topic_id' => $topic($r['topic_id']), 'uploaded_by' => $teacher_id, 'title' => $r['title'],
                    'description' => $r['description'], 'category' => $r['category'], 'file_path' => $path, 'original_name' => $r['original_name'],
                    'file_type' => $r['file_type'], 'file_size' => $r['file_size'],
                ]);
                $counts['resources']++;
            }
        }
        $copyMaterials = function ($table, $col, $old_id, $new_item) use ($db, $res_map) {
            foreach ($db->fetchAll("SELECT * FROM {$table} WHERE {$col} = ? ORDER BY position, id", [$old_id]) as $m) {
                if ($m['kind'] === 'resource' && !isset($res_map[(int) $m['resource_id']])) continue;   // its file was not copied
                $db->insert($table, [$col => $new_item, 'kind' => $m['kind'], 'resource_id' => $m['kind'] === 'resource' ? $res_map[(int) $m['resource_id']] : null,
                                     'url' => $m['url'], 'title' => $m['title'], 'position' => $m['position']]);
            }
        };

        if ($want('lectures')) {
            // Pictures of the class's lectures are copied once and shared by the copied lectures
            $img_map = [];
            foreach ($db->fetchAll("SELECT * FROM lecture_images WHERE class_id = ? ORDER BY id", [$source['id']]) as $im) {
                $path = copyUploadedFile($im['file_path']);
                if ($path === null) { $skipped++; continue; }
                $new_files[] = $path;
                $img_map[(int) $im['id']] = $db->insert('lecture_images', [
                    'class_id' => $new_id, 'uploaded_by' => $teacher_id, 'file_path' => $path, 'mime' => $im['mime'], 'width' => $im['width'],
                    'height' => $im['height'], 'file_size' => $im['file_size'], 'original_name' => $im['original_name']]);
                $counts['images']++;
            }
            foreach ($db->fetchAll("SELECT * FROM lectures WHERE class_id = ? ORDER BY id", [$source['id']]) as $l) {
                // Re-point "(img:ID" at the copies; a picture that could not be copied is dropped from the text
                $content = preg_replace_callback('/^!\[[^\]\n]{0,200}\]\(img:(\d{1,10})[^)\n]*\)[ \t]*$/m',
                    fn($m) => isset($img_map[(int) $m[1]]) ? str_replace('(img:' . $m[1], '(img:' . $img_map[(int) $m[1]], $m[0]) : '', (string) $l['content']);
                $lid = $db->insert('lectures', [
                    'class_id' => $new_id, 'topic_id' => $topic($l['topic_id']), 'teacher_id' => $teacher_id, 'title' => $l['title'], 'summary' => $l['summary'],
                    'content' => $content, 'notes' => $l['notes'], 'video_url' => $l['video_url'], 'status' => 'draft', 'publish_at' => null, 'notified_at' => null]);
                $copyMaterials('lecture_materials', 'lecture_id', $l['id'], $lid);
                $counts['lectures']++;
            }
        }
        if ($want('assignments')) {
            foreach ($db->fetchAll("SELECT * FROM assignments WHERE class_id = ? ORDER BY id", [$source['id']]) as $a) {
                $aid = $db->insert('assignments', [
                    'class_id' => $new_id, 'topic_id' => $topic($a['topic_id']), 'teacher_id' => $teacher_id, 'title' => $a['title'], 'description' => $a['description'],
                    'due_date' => null, 'max_marks' => $a['max_marks'], 'rubric' => $a['rubric'], 'release_mode' => $a['release_mode'], 'results_released_at' => null]);
                $copyMaterials('assignment_materials', 'assignment_id', $a['id'], $aid);
                $counts['assignments']++;
            }
        }
        if ($want('quizzes')) {
            foreach ($db->fetchAll("SELECT * FROM quizzes WHERE class_id = ? ORDER BY id", [$source['id']]) as $q) {
                $qid = $db->insert('quizzes', [
                    'class_id' => $new_id, 'topic_id' => $topic($q['topic_id']), 'teacher_id' => $teacher_id, 'title' => $q['title'], 'description' => $q['description'],
                    'time_limit' => $q['time_limit'], 'due_date' => null, 'release_mode' => $q['release_mode'], 'results_released_at' => null]);
                foreach ($db->fetchAll("SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position, id", [$q['id']]) as $qq) {
                    $nq = $db->insert('quiz_questions', ['quiz_id' => $qid, 'question' => $qq['question'], 'question_type' => $qq['question_type'],
                                                         'model_answer' => $qq['model_answer'], 'marks' => $qq['marks'], 'position' => $qq['position']]);
                    foreach ($db->fetchAll("SELECT * FROM quiz_options WHERE question_id = ? ORDER BY position, id", [$qq['id']]) as $o) {
                        $db->insert('quiz_options', ['question_id' => $nq, 'option_text' => $o['option_text'], 'is_correct' => $o['is_correct'], 'position' => $o['position']]);
                    }
                }
                $counts['quizzes']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($new_files as $f) @unlink(STORAGE_ROOT . '/' . $f);
        throw $e;
    }
    return ['class_id' => $new_id, 'code' => $code, 'counts' => $counts, 'skipped_files' => $skipped];
}

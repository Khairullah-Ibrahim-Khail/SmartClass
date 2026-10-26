<?php
test('AI answer language: the screen language by default, always English or the question language when the teacher chooses; the teacher can set it on the class page', function () {
    $db = TW('db'); $t = mkUser('AlT', 'teacher'); $o = mkUser('AlO', 'teacher'); $c = mkClass($t, 'AlClass');
    eq('screen', $db->fetchOne('SELECT ai_language FROM classes WHERE id = ?', [$c])['ai_language'], 'default');
    $r = aiReplyLanguageRule($c, 'ps'); contains('Answer in Pashto', $r); contains('Arabic-script', $r); contains('Dari (Afghan Persian)', aiReplyLanguageRule($c, 'fa')); contains('Answer in English', aiReplyLanguageRule($c, 'en'));
    eq('ps', aiAnswerLang($c, 'ps'));
    $db->update('classes', ['ai_language' => 'en'], 'id = ?', [$c]);
    $c2 = mkClass($t, 'AlEnglish'); $db->update('classes', ['ai_language' => 'en'], 'id = ?', [$c2]);
    contains('Answer in English', aiReplyLanguageRule($c2, 'ps'), 'an English class answers in English on a Pashto screen'); eq('en', aiAnswerLang($c2, 'fa'));
    $c3 = mkClass($t, 'AlQuestion'); $db->update('classes', ['ai_language' => 'question'], 'id = ?', [$c3]);
    contains("language of the student's question", aiReplyLanguageRule($c3, 'ps')); contains('answer in Pashto', aiReplyLanguageRule($c3, 'ps'), 'and falls back to the screen when the question shows no language'); eq('question', aiAnswerLang($c3, 'ps'));
    eq('screen', aiLanguageMode(0), 'no class → screen');

    $h = client($t); $h->get("pages/classes/edit.php?id=$c3"); contains('AI answers in', $h->body); contains('value="question" selected', $h->body);
    $tok = $h->token("pages/classes/edit.php?id=$c3");
    $h->post("pages/classes/edit.php?id=$c3", ['csrf_token' => $tok, 'action' => 'save', 'class_name' => 'AlQuestion', 'description' => 'A class for the language test', 'ai_students' => '1', 'ai_language' => 'en', 'theme' => 'auto', 'stream_permission' => 'comment']);
    eq('en', $db->fetchOne('SELECT ai_language FROM classes WHERE id = ?', [$c3])['ai_language'], 'saved from the class settings');
    $h->post("pages/classes/edit.php?id=$c3", ['csrf_token' => $h->token("pages/classes/edit.php?id=$c3"), 'action' => 'save', 'class_name' => 'AlQuestion', 'description' => 'A class for the language test', 'ai_language' => 'klingon', 'theme' => 'auto', 'stream_permission' => 'comment']);
    eq('screen', $db->fetchOne('SELECT ai_language FROM classes WHERE id = ?', [$c3])['ai_language'], 'an unknown value falls back to the default');
});

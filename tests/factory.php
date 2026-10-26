<?php
/** Test data makers (all inside the throw-away database). */
const TEST_PW = 'Test#Pass2026xyz';
function TW($k) { return $GLOBALS['T'][$k]; }
function mkUser($name, $role = 'student', $admin = 0) {
    static $n = 0; $n++;
    $db = TW('db');
    return (int) $db->insert('users', ['name' => $name, 'email' => 'u' . $n . '_' . bin2hex(random_bytes(2)) . '@example.invalid', 'password' => password_hash(TEST_PW, PASSWORD_DEFAULT),
        'role' => $role, 'is_admin' => $admin, 'is_approved' => 1]);
}
function userEmail($id) { return TW('db')->fetchOne('SELECT email FROM users WHERE id = ?', [$id])['email']; }
function mkClass($teacher_id, $name = 'Test class', array $students = []) {
    $db = TW('db');
    $c = (int) $db->insert('classes', ['class_name' => $name, 'teacher_id' => $teacher_id, 'is_active' => 1, 'ai_students' => 1, 'class_code' => strtoupper(bin2hex(random_bytes(4)))]);
    $db->insert('class_teachers', ['class_id' => $c, 'teacher_id' => $teacher_id, 'role' => 'owner']);
    foreach ($students as $s) $db->insert('class_members', ['class_id' => $c, 'student_id' => $s, 'joined_at' => date('Y-m-d H:i:s', strtotime('-60 days'))]);
    return $c;
}
function client($user_id) { $h = new Http(TW('base')); $h->login(userEmail($user_id), TEST_PW); return $h; }
function anon() { return new Http(TW('base')); }

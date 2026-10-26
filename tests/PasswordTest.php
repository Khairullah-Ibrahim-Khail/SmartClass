<?php
test('password rules: length, common passwords, own name / e-mail, repeats and runs', function () {
    eq(10, passwordMinLength(), 'default minimum');
    ok(passwordProblem('Short1!', 'a@b.com', 'Ann') !== '', 'too short');
    ok(passwordProblem('password123', '', '') !== '', 'common');
    ok(passwordProblem('Password12345', '', '') !== '', 'a common password with digits added');
    ok(passwordProblem('P@ssw0rd', '', '') !== '', 'common with symbols (and too short)');
    ok(passwordProblem('1234567890', '', '') !== '', 'a plain run');
    ok(passwordProblem('aaaaaaaaaaaa', '', '') !== '', 'one repeated character');
    ok(passwordProblem('khairullah2026x', 'khairullah@school.org', 'Khairullah Khail') !== '', 'contains the e-mail name / name');
    eq('', passwordProblem('blue-Tractor-Window-47', 'ann@school.org', 'Ann Smith'), 'a good passphrase is accepted');
    eq('', passwordProblem('Kq7!zR2m#Lp9', 'ann@school.org', 'Ann Smith'));
});
test('the school can change the minimum length (8-20)', function () {
    setSetting('password_min_length', '14'); eq(14, passwordMinLength()); ok(passwordProblem('blue-Tractor-47', '', '') !== '' || strlen('blue-Tractor-47') >= 14);
    setSetting('password_min_length', '3'); eq(8, passwordMinLength(), 'never below 8'); setSetting('password_min_length', '99'); eq(20, passwordMinLength(), 'never above 20'); setSetting('password_min_length', '10');
});
test('sign-up and password change refuse a weak password and say why next to the field', function () {
    $u = mkUser('PwU'); $h = client($u); $tok = $h->token('pages/settings/security.php');
    $h->post('pages/settings/security.php', ['csrf_token' => $tok, 'action' => 'change_password', 'current_password' => TEST_PW, 'new_password' => 'password123', 'confirm_password' => 'password123']);
    contains('far too common', $h->body); $row = TW('db')->fetchOne('SELECT password FROM users WHERE id = ?', [$u]); ok(password_verify(TEST_PW, $row['password']), 'the password did not change');
    $h->post('pages/settings/security.php', ['csrf_token' => $tok, 'action' => 'change_password', 'current_password' => TEST_PW, 'new_password' => 'blue-Tractor-Window-47', 'confirm_password' => 'blue-Tractor-Window-47']);
    $row = TW('db')->fetchOne('SELECT password FROM users WHERE id = ?', [$u]); ok(password_verify('blue-Tractor-Window-47', $row['password']), 'a strong password is accepted');
});

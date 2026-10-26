<?php
/**
 * SmartClass - password rules (loaded by includes/functions.php)
 *   passwordMinLength()                       the school's minimum (Settings › Security rules), 8-20, default 10
 *   passwordProblem($pw, $email, $name)       '' when the password is acceptable, else the reason to show next to the field
 * Applies to new passwords (sign-up, change, reset, invitation, first sign-in). Existing passwords keep working until their owner changes them.
 * Refused: too short, too long (200), on the list of common passwords (also with digits / symbols added), the person's own e-mail name or
 * name, one repeated character, a plain run such as 12345678 or abcdefgh.
 */
function passwordMinLength() {
    return max(8, min(20, (int) getSetting('password_min_length', 10)));
}

function passwordProblem($pw, $email = '', $name = '') {
    $pw = (string) $pw;
    $min = passwordMinLength();
    if (mb_strlen($pw) < $min) return "Use at least {$min} characters";
    if (mb_strlen($pw) > 200) return 'Password is too long';
    $low = mb_strtolower($pw);
    $stripped = preg_replace('/[^a-z]+/u', '', strtr($low, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '@' => 'a', '$' => 's']));
    static $common = null; if ($common === null) $common = require __DIR__ . '/common_passwords.php';
    $base = rtrim($low, '0123456789!@#$%^&*._-');
    if (isset($common[$low]) || isset($common[$base]) || ($stripped !== '' && strlen($stripped) >= 5 && isset($common[$stripped]))) return 'That password is far too common — choose something less guessable';
    if (preg_match('/^(.)\1+$/u', $pw)) return 'Do not repeat one character';
    $seq = '0123456789 abcdefghijklmnopqrstuvwxyz qwertyuiop asdfghjkl zxcvbnm';
    foreach (explode(' ', $seq) as $run) {
        if (strlen($low) >= 6 && (strpos($run, $low) !== false || strpos(strrev($run), $low) !== false)) return 'Do not use a plain run of letters or numbers';
    }
    $local = mb_strtolower((string) strstr((string) $email, '@', true));
    if (mb_strlen($local) >= 4 && strpos($low, $local) !== false) return 'The password must not contain your e-mail name';
    foreach (preg_split('/\s+/', mb_strtolower(trim((string) $name))) as $part) {
        if (mb_strlen($part) >= 4 && strpos($low, $part) !== false) return 'The password must not contain your name';
    }
    return '';
}

<?php
/**
 * Running code in lectures. Three ways, chosen by the language of the code block:
 *   in the browser, nothing leaves the page   python (Pyodide), javascript, html, css      (assets/js/code-run.js, always on)
 *   on a runner you set up                    c, c++, c#, java, php, go, rust, ruby, kotlin, swift, bash, sql, r, lua, perl, typescript …
 *      a Piston-compatible code runner (https://github.com/engineer-man/piston — run it yourself with Docker) whose address the administrator
 *      enters in Settings › Code runner. Without an address those blocks have no Run button. The code is sent to the runner only when a person
 *      presses Run, from this server (pages/lectures/run.php), with limits on size, time, output and how often.
 */
const CODE_RUN_MAX_CODE = 20000;      // characters of source per run
const CODE_RUN_MAX_OUTPUT = 20000;    // characters of output shown
const CODE_RUN_PER_MINUTE = 12;       // runs per person per minute

/** Language tag (as written after ```) → the runner's language name and the file name its compiler expects. */
function codeRunnerLanguages() {
    return [
        'c' => ['c', 'main.c'], 'cpp' => ['c++', 'main.cpp'], 'c++' => ['c++', 'main.cpp'], 'cs' => ['csharp', 'Main.cs'], 'csharp' => ['csharp', 'Main.cs'], 'c#' => ['csharp', 'Main.cs'],
        'java' => ['java', 'Main.java'], 'php' => ['php', 'main.php'], 'go' => ['go', 'main.go'], 'golang' => ['go', 'main.go'], 'rust' => ['rust', 'main.rs'], 'rs' => ['rust', 'main.rs'],
        'ruby' => ['ruby', 'main.rb'], 'rb' => ['ruby', 'main.rb'], 'kotlin' => ['kotlin', 'Main.kt'], 'swift' => ['swift', 'main.swift'], 'bash' => ['bash', 'main.sh'], 'sh' => ['bash', 'main.sh'],
        'sql' => ['sqlite3', 'main.sql'], 'sqlite' => ['sqlite3', 'main.sql'], 'r' => ['r', 'main.r'], 'lua' => ['lua', 'main.lua'], 'perl' => ['perl', 'main.pl'],
        'typescript' => ['typescript', 'main.ts'], 'ts' => ['typescript', 'main.ts'], 'dart' => ['dart', 'main.dart'], 'scala' => ['scala', 'Main.scala'], 'haskell' => ['haskell', 'main.hs'],
    ];
}

/** The runner's address (no trailing slash) or '' when none is set. */
function codeRunnerUrl() { return rtrim(trim((string) getSetting('code_runner_url', '')), '/'); }

/** What a Run button does for this language tag: 'python' | 'javascript' | 'html' | 'css' | 'remote' | null (no Run button). */
function codeRunKind($lang) {
    $l = strtolower(trim((string) $lang));
    if (in_array($l, ['python', 'py'], true)) return 'python';
    if (in_array($l, ['javascript', 'js'], true)) return 'javascript';
    if (in_array($l, ['html', 'htm', 'xhtml'], true)) return 'html';
    if ($l === 'css') return 'css';
    return (isset(codeRunnerLanguages()[$l]) && codeRunnerUrl() !== '' && getSetting('code_runner_on', '1') === '1') ? 'remote' : null;
}

/** Compile / run limits per language: the JVM and big compilers need more room than C or Lua (the runner may cap these lower; it is told the most it will get). */
function codeRunLimits($language) {
    $big = ['kotlin' => [30000, 1024, 512], 'java' => [20000, 768, 512], 'csharp' => [20000, 768, 512], 'rust' => [20000, 768, 256], 'swift' => [20000, 768, 256], 'typescript' => [15000, 512, 256]];
    [$ms, $cmb, $rmb] = $big[$language] ?? [10000, 256, 256];
    return ['compile_ms' => $ms, 'compile_mb' => $cmb, 'run_mb' => $rmb];
}

/** Is this a plausible runner address (http / https, no credentials in it)? */
function codeRunnerUrlValid($url) {
    $p = parse_url($url);
    return $p && in_array($p['scheme'] ?? '', ['http', 'https'], true) && !empty($p['host']) && empty($p['user']) && empty($p['pass']) && strlen($url) <= 200;
}

/**
 * Run one program on the runner. Returns ['ok' => true, 'stdout', 'stderr', 'compile', 'code', 'signal'] or ['ok' => false, 'error'].
 * $http (optional) = fn($url, $payload, $headers) → [status, body] — used by tests.
 */
function codeRunRemote($lang, $code, $stdin = '', ?callable $http = null) {
    $tag = strtolower(trim((string) $lang)); $map = codeRunnerLanguages();
    if (!isset($map[$tag])) return ['ok' => false, 'error' => 'This language cannot be run here.'];
    $url = codeRunnerUrl(); if ($url === '' || !codeRunnerUrlValid($url)) return ['ok' => false, 'error' => 'Running this language is not set up on this school\'s server.'];
    if (mb_strlen($code) > CODE_RUN_MAX_CODE) return ['ok' => false, 'error' => 'The code is too long to run (' . number_format(CODE_RUN_MAX_CODE) . ' characters at most).'];
    [$name, $file] = $map[$tag];
    $lim = codeRunLimits($name);
    $payload = ['language' => $name, 'version' => '*', 'files' => [['name' => $file, 'content' => $code]], 'stdin' => mb_substr((string) $stdin, 0, 2000),
                'compile_timeout' => $lim['compile_ms'], 'run_timeout' => 5000, 'compile_memory_limit' => $lim['compile_mb'] * 1024 * 1024, 'run_memory_limit' => $lim['run_mb'] * 1024 * 1024];
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (($tok = trim((string) getSetting('code_runner_token', ''))) !== '') $headers[] = 'Authorization: ' . decryptSecret($tok);
    if ($http) { [$status, $body] = $http($url . '/api/v2/execute', $payload, $headers); }
    else {
        $ch = curl_init($url . '/api/v2/execute');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch);
        if ($body === false) return ['ok' => false, 'error' => 'The code runner could not be reached (' . ($err ?: 'network error') . '). Try again later.'];
    }
    $j = json_decode((string) $body, true);
    if ($status === 429) return ['ok' => false, 'error' => 'The code runner is busy. Please wait a moment and run it again.'];
    if ($status < 200 || $status >= 300 || !is_array($j)) return ['ok' => false, 'error' => 'The code runner did not accept this (' . $status . ')' . (is_array($j) && !empty($j['message']) ? ': ' . mb_strimwidth((string) $j['message'], 0, 160, '…') : '.')];
    $cut = fn($s) => mb_substr((string) $s, 0, CODE_RUN_MAX_OUTPUT);
    return ['ok' => true, 'stdout' => $cut($j['run']['stdout'] ?? ''), 'stderr' => $cut($j['run']['stderr'] ?? ''), 'compile' => $cut(($j['compile']['output'] ?? '')),
            'code' => isset($j['run']['code']) ? (int) $j['run']['code'] : null, 'signal' => $j['run']['signal'] ?? null];
}

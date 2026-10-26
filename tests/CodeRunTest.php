<?php
require_once __DIR__ . '/AiStreamTest.php';   // aiMockStart(): the fake provider also answers like a code runner

test('code blocks: HTML, CSS, Python and JavaScript always get a Run button; other languages only when a code runner is set up; untagged HTML / CSS is recognised', function () {
    setSetting('code_runner_url', ''); $md = "```html\n<h1>Hi</h1>\n```\n\n```css\nh1 { color: red; }\n```\n\n```java\nclass Main {}\n```\n\n```python\nprint(1)\n```\n\n```js\nconsole.log(1)\n```";
    $h = renderLecture($md, ['run' => true])['html'];
    foreach (['html', 'css', 'python', 'javascript'] as $k) contains('data-lang="' . $k . '"', $h, "$k runs in the browser");
    notContains('data-lang="remote"', $h, 'Java has no Run button without a runner');
    $port = aiMockStart()['port']; setSetting('code_runner_url', "http://127.0.0.1:$port"); setSetting('code_runner_on', '1');
    $h2 = renderLecture($md, ['run' => true])['html']; contains('data-lang="remote" data-tag="java"', $h2, 'Java gets a Run button once a runner is set');
    setSetting('code_runner_on', '0'); notContains('data-lang="remote"', renderLecture($md, ['run' => true])['html'], 'and loses it when the runner is switched off'); setSetting('code_runner_on', '1');
    contains('data-lang="html"', renderLecture("```\n<div class=\"card\"><p>Hello</p></div>\n```", ['run' => true])['html'], 'an untagged block that is clearly HTML');
    contains('data-lang="css"', renderLecture("```\n.card { padding: 8px; color: #333; }\n```", ['run' => true])['html'], 'an untagged block that is clearly CSS');
    notContains('code-run-btn', renderLecture($md)['html'], 'no Run buttons where running is not offered');
    aiMockReset();
});

test('code runner: a Run on the runner returns output, compile errors and busy messages; people who cannot open the lecture, wrong tokens and unknown languages are refused', function () {
    $port = aiMockStart()['port']; setSetting('code_runner_url', "http://127.0.0.1:$port"); setSetting('code_runner_on', '1');
    $t = mkUser('CrT', 'teacher'); $s = mkUser('CrS'); $o = mkUser('CrO'); $c = mkClass($t, 'CrClass', [$s]);
    $lec = (int) TW('db')->insert('lectures', ['class_id' => $c, 'teacher_id' => $t, 'title' => 'C', 'status' => 'published', 'content' => "```c\nint main(){}\n```"]);
    $h = client($s); $tok = $h->token('pages/dashboard.php');
    $run = fn($hh, $tt, $code, $lang = 'c') => $hh->post('pages/lectures/run.php', ['lecture_id' => $lec, 'lang' => $lang, 'code' => $code], [], ['X-CSRF-Token' => $tt]);
    $run($h, $tok, 'int main(){return 0;}'); $j = json_decode($h->body, true); ok($j['ok'], $h->body); contains('Hello from c file main.c', $j['stdout']);
    $run($h, $tok, 'COMPILEERR', 'c'); $j = json_decode($h->body, true); contains("expected ';'", $j['compile']);
    $run($h, $tok, 'BUSY'); $j = json_decode($h->body, true); ok(!$j['ok']); contains('busy', $j['error']);
    $run($h, $tok, 'x', 'brainfuck'); eq(422, $h->code, 'a language that is not on the list');
    $run($h, 'wrong', 'x'); eq(403, $h->code, 'wrong token');
    $ho = client($o); $run($ho, $ho->token('pages/dashboard.php'), 'x'); eq(404, $ho->code, 'someone outside the class cannot run it');
    $run(anon(), '', 'x'); ok($h->code !== 0);
    $run($h, $tok, str_repeat('a', CODE_RUN_MAX_CODE + 1)); $j = json_decode($h->body, true); ok(!$j['ok']); contains('too long', $j['error']);
    setSetting('code_runner_url', ''); $run($h, $tok, 'x'); eq(422, $h->code, 'no runner set up → refused');
    setSetting('code_runner_url', "http://127.0.0.1:$port");
    $r = codeRunRemote('java', 'class Main {}'); contains('Main.java', $r['stdout'], 'Java is compiled from Main.java'); contains('java', $r['stdout']);
    ok(!codeRunnerUrlValid('ftp://x') && !codeRunnerUrlValid('http://user:pw@host') && codeRunnerUrlValid('https://runner.example.com'), 'only http(s) addresses without credentials');
    aiMockReset();
});

test('code runner settings: only the administrator opens the page and saves; the token is stored encrypted and never shown', function () {
    $a = mkUser('CrA', 'teacher', 1); $t = mkUser('CrT2', 'teacher'); $h = client($a); $ht = client($t);
    $ht->get('pages/settings/code_runner.php'); ok(!$ht->shown(), 'a teacher cannot open it');
    $h->get('pages/settings/code_runner.php'); contains('Runner address', $h->body); $tok = $h->token('pages/settings/code_runner.php');
    $h->post('pages/settings/code_runner.php', ['csrf_token' => $tok, 'action' => 'save', 'url' => 'ftp://nope', 'on' => '1']); contains('Enter a full address', $h->body);
    $port = aiMockStart()['port'];
    $h->post('pages/settings/code_runner.php', ['csrf_token' => $h->token('pages/settings/code_runner.php'), 'action' => 'save', 'url' => "http://127.0.0.1:$port/", 'token' => 'secret-token-123', 'on' => '1']);
    $row = fn($k) => (TW('db')->fetchOne('SELECT setting_value v FROM app_settings WHERE setting_key = ?', [$k])['v'] ?? null);
    eq("http://127.0.0.1:$port", $row('code_runner_url')); ok($row('code_runner_token') !== '' && strpos($row('code_runner_token'), 'secret-token') === false, 'stored encrypted');
    $h->get('pages/settings/code_runner.php'); notContains('secret-token-123', $h->body, 'never shown again'); contains('saved — type to replace', $h->body);
    $h->post('pages/settings/code_runner.php', ['csrf_token' => $h->token('pages/settings/code_runner.php'), 'action' => 'test']); contains('The runner did not answer correctly', $h->body, 'the fake runner answers "Hello from python", not the expected text — the test says so');
    TW('db')->query("DELETE FROM app_settings WHERE setting_key IN ('code_runner_url','code_runner_token','code_runner_on')");
    aiMockReset();
});

test('diagrams: a ```mermaid block becomes a diagram figure (no Run button, no code runner), labels with brackets get their quotes, nothing from it can become HTML', function () {
    $md = "```mermaid\ngraph TD\n    A[Source Code (.java)] -->|Compiled by javac| B[Bytecode (.class)]\n    B --> C[<img src=x onerror=alert(1)>]\n```";
    $h = renderLecture($md, ['run' => true, 'explain' => true])['html'];
    contains('<figure class="lec-diagram" data-kind="mermaid">', $h); contains('A[&quot;Source Code (.java)&quot;]', $h, 'quotes added around a label with brackets (shown escaped in the page)'); contains('B[&quot;Bytecode (.class)&quot;]', $h);
    notContains('code-run-btn', $h); notContains('code-explain-btn', $h); notContains('<img src=x', $h, 'the text is escaped'); contains('&lt;img src=x', $h);
    eq('A["x (y)"] --> B["ok"]', lectureMermaidFix('A[x (y)] --> B["ok"]'), 'a label that is already quoted is left alone');
    ok(is_file(dirname(__DIR__) . '/assets/vendor/mermaid/mermaid.min.js') && is_file(dirname(__DIR__) . '/assets/vendor/mermaid/LICENSE'), 'the drawing library is served from this server, with its licence');
});

test('charts: every drawing language a lecture may use is recognised (also when the tag is forgotten), bad or unsafe blocks stay plain code, libraries are on this server', function () {
    $k = fn($lang, $src) => lectureChartKind($lang, $src);
    eq('mermaid', $k('mermaid', 'graph TD; A-->B')); eq('mermaid', $k('', "flowchart LR\n A --> B")); eq('mermaid', $k('text', "sequenceDiagram\n A->>B: hi")); eq('mermaid', $k('', "pie title Pets\n \"Dogs\": 3")); eq('mermaid', $k('', "gantt\n title A"));
    eq('dot', $k('dot', 'digraph G { a -> b }')); eq('dot', $k('graphviz', 'graph { a -- b }')); eq('dot', $k('', "digraph G {\n a -> b;\n}")); eq('dot', $k('', "strict graph {\n a -- b\n}"));
    $bar = '{"type":"bar","data":{"labels":["a","b"],"datasets":[{"label":"x","data":[1,2]}]}}';
    eq('chart', $k('chart', $bar)); eq('chart', $k('chartjs', $bar)); eq('chart', $k('', $bar), 'an untagged block that is clearly a chart'); eq(null, $k('json', $bar), 'a block explicitly tagged json stays code');
    eq(null, $k('chart', '{not json'), 'invalid JSON stays plain code'); eq(null, $k('vega-lite', 'nope'));
    $vl = '{"$schema":"https://vega.github.io/schema/vega-lite/v5.json","data":{"values":[{"a":"A","b":1}]},"mark":"bar","encoding":{"x":{"field":"a"},"y":{"field":"b"}}}';
    eq('vega', $k('vega-lite', $vl)); eq('vega', $k('', $vl));
    eq(null, $k('python', "graph TD\n A"), 'a Python block is never turned into a drawing'); eq(null, $k('', "print('graph')"), 'ordinary code'); eq(null, $k('', "int graph = 3;")); eq(null, $k('javascript', 'digraph G { }'));
    $h = renderLecture("```dot\ndigraph G { a -> b }\n```\n\n```chart\n$bar\n```\n\n```vega-lite\n$vl\n```\n\n```\nflowchart TD\n A --> B\n```\n\n```chart\n{broken\n```", ['run' => true])['html'];
    foreach (['dot', 'chart', 'vega', 'mermaid'] as $kind) contains('data-kind="' . $kind . '"', $h, "a $kind figure"); contains('<pre><code class="language-chart">', $h, 'the broken chart is shown as code');
    foreach (['charts/viz-standalone.js', 'charts/vega.min.js', 'charts/vega-lite.min.js', 'charts/vega-embed.min.js', 'charts/chart.umd.js', 'charts/README.txt', 'mermaid/mermaid.min.js'] as $f) ok(is_file(dirname(__DIR__) . '/assets/vendor/' . $f), "$f is served from this server");
});

test('lecture code preview: the frame is sandboxed and closed to scripts and data from the internet; big libraries are kept by the service worker without background downloads', function () {
    $js = file_get_contents(dirname(__DIR__) . '/assets/js/code-run.js');
    contains('sandbox", "allow-scripts"', $js); contains("script-src 'unsafe-inline';", $js); contains("connect-src 'none'", $js); notContains("script-src 'unsafe-inline' https:", $js); notContains('allow-same-origin', $js, 'the frame never gets this site\'s cookies');
    $sw = file_get_contents(dirname(__DIR__) . '/sw.js'); contains("/assets/vendor/", $sw); contains('NOT downloaded again in the background', $sw);
});

test('the AI is told to draw every flowchart as a mermaid block and never as text art (text art cannot be shown as a picture)', function () {
    $rules = aiRichFormatRules('full');
    contains('NEVER draw a flowchart', $rules); contains('```mermaid', $rules); contains('text art', $rules);
});

test('the code runner is given more room for the big compilers (Kotlin, Java, C#, Rust, Swift) and the normal limits for the rest', function () {
    eq(['compile_ms' => 30000, 'compile_mb' => 1024, 'run_mb' => 512], codeRunLimits('kotlin'), 'Kotlin needs a JVM-sized compile');
    ok(codeRunLimits('java')['compile_mb'] >= 768 && codeRunLimits('rust')['compile_ms'] >= 20000, 'the big ones get more');
    eq(['compile_ms' => 10000, 'compile_mb' => 256, 'run_mb' => 256], codeRunLimits('c'), 'C keeps the normal limits'); eq(10000, codeRunLimits('lua')['compile_ms']);
});

<?php
// Router for `php -S`: a fake OpenAI-compatible provider for the tests. POST /chat/completions → streams (server-sent events) when "stream" is true.
// A question containing FAIL401 is answered with an authentication error before any text.
$b = json_decode(file_get_contents('php://input'), true) ?: [];
if (strpos($_SERVER['REQUEST_URI'], '/api/v2/execute') !== false) {   // a Piston-like code runner
    header('Content-Type: application/json');
    $code = (string) ($b['files'][0]['content'] ?? '');
    if (strpos($code, 'BUSY') !== false) { http_response_code(429); echo json_encode(['message' => 'rate limited']); exit; }
    $compile = strpos($code, 'COMPILEERR') !== false ? ['output' => "main.c:1: error: expected ';'", 'code' => 1] : ['output' => '', 'code' => 0];
    echo json_encode(['language' => $b['language'], 'version' => '1.0', 'compile' => $compile, 'run' => ['stdout' => 'Hello from ' . $b['language'] . ' file ' . $b['files'][0]['name'] . "\n", 'stderr' => '', 'code' => 0, 'signal' => null]]);
    exit;
}
if (strpos($_SERVER['REQUEST_URI'], '/chat/completions') === false) { http_response_code(404); exit; }
$ask = (string) (end($b['messages'])['content'] ?? '');
if (strpos($ask, 'FAIL401') !== false) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['error' => ['message' => 'Incorrect API key provided']]); exit; }
$sys = (string) ($b['messages'][0]['content'] ?? '');
if (strpos($sys, 'ready to be graded or should first be sent back') !== false) {   // the teacher's "check the work" question
    $changes = strpos($ask, 'NEEDCHANGES') !== false;
    $note = strpos($sys, 'Pashto') !== false ? 'ps' : (strpos($sys, 'Dari') !== false ? 'fa' : 'en');
    header('Content-Type: application/json');
    echo json_encode(['choices' => [['message' => ['content' => json_encode(['verdict' => $changes ? 'changes' : 'ready', 'reason' => $changes ? 'Question 2 has no working.' : 'Every question is answered.',
        'note' => $changes ? 'MOCKNOTE ' . $note : '', 'suspicious' => strpos($ask, 'INJECTME') !== false, 'suspicious_reason' => 'It tells the AI what to answer.'])]]], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7]]);
    exit;
}
$pieces = ["Hello ", "**world**", ".\n\n", "- first\n", "- {green}second{/green}\n", "\n> Tip: slowly."];
if (strpos($ask, 'Topics of this class: ') !== false) $pieces[] = ' CTXSEEN';   // the draft was told the class's own topics
if (strpos($ask, 'Facts:') !== false) $pieces[] = ' FACTSEEN';
if (!empty($b['stream'])) {
    header('Content-Type: text/event-stream'); header('Cache-Control: no-cache');
    foreach ($pieces as $p) { echo 'data: ' . json_encode(['choices' => [['delta' => ['content' => $p]]]]) . "\n\n"; @ob_flush(); flush(); usleep(strpos($ask, 'SLOW') !== false ? 700000 : 40000); }
    echo 'data: ' . json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7]]) . "\n\n";
    echo "data: [DONE]\n\n";
    exit;
}
header('Content-Type: application/json');
echo json_encode(['choices' => [['message' => ['content' => implode('', $pieces)]]], 'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7]]);

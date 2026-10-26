<?php
/**
 * SmartClass - AI module (loaded by includes/functions.php)
 *
 * One way to call any AI provider, switchable in Settings › AI Settings (never in files).
 *   aiProviderRegistry()   every supported provider: label, API style, default address
 *   aiFeatures()           AI features; each can use its own provider
 *   aiEnabled()            AI switched on and a working default provider set
 *   aiChat($feature, $system, $user_text, $opts)
 *       → ['ok' => true, 'text', 'json', 'provider', 'model', 'input_tokens', 'output_tokens']
 *       | ['ok' => false, 'error' => message for the user]
 *     Feature provider (or the default) first, then the fallback provider. One retry on
 *     network errors / 429 / 5xx; with $opts['json'] the answer must be valid JSON.
 *     Every call is logged in ai_usage; the monthly token limits are enforced here.
 *   aiListModels($row)      model ids from the provider (models endpoint)
 *
 * Whose key is used (aiSources): a person's own provider (Settings › My AI) first; for a
 * student inside a class (only if the teacher switched "AI for students" on for that class)
 * then a teacher's shared provider (monthly limit per student), then the school's provider
 * (super admin, AI Settings — only if students are allowed). Teachers: own, then school.
 * School limits count only school-key usage.
 *
 * Three API styles cover every provider: OpenAI-compatible chat completions,
 * Anthropic Messages, Google Gemini generateContent. Keys are stored encrypted
 * (encryptSecret) and never leave this file except in the request header.
 */

/** Supported providers: key => label, API style, default base URL, key needed, where to get a key. */
function aiProviderRegistry() {
    return [
        'openai'     => ['label' => 'OpenAI',                     'api' => 'openai',    'base' => 'https://api.openai.com/v1',                        'key' => true,  'site' => 'https://platform.openai.com/api-keys'],
        'anthropic'  => ['label' => 'Anthropic Claude',           'api' => 'anthropic', 'base' => 'https://api.anthropic.com/v1',                     'key' => true,  'site' => 'https://console.anthropic.com/settings/keys'],
        'gemini'     => ['label' => 'Google Gemini',              'api' => 'gemini',    'base' => 'https://generativelanguage.googleapis.com/v1beta', 'key' => true,  'site' => 'https://aistudio.google.com/apikey'],
        'groq'       => ['label' => 'Groq',                       'api' => 'openai',    'base' => 'https://api.groq.com/openai/v1',                   'key' => true,  'site' => 'https://console.groq.com/keys'],
        'mistral'    => ['label' => 'Mistral',                    'api' => 'openai',    'base' => 'https://api.mistral.ai/v1',                        'key' => true,  'site' => 'https://console.mistral.ai/api-keys'],
        'deepseek'   => ['label' => 'DeepSeek',                   'api' => 'openai',    'base' => 'https://api.deepseek.com/v1',                      'key' => true,  'site' => 'https://platform.deepseek.com/api_keys'],
        'openrouter' => ['label' => 'OpenRouter',                 'api' => 'openai',    'base' => 'https://openrouter.ai/api/v1',                     'key' => true,  'site' => 'https://openrouter.ai/keys'],
        'ollama'     => ['label' => 'Ollama (self-hosted)',       'api' => 'openai',    'base' => 'http://localhost:11434/v1',                        'key' => false, 'site' => 'https://ollama.com'],
        'custom'     => ['label' => 'OpenAI-compatible (custom)', 'api' => 'openai',    'base' => '',                                                 'key' => false, 'site' => null],
    ];
}

/** AI features: key => [label, who uses it]. Each can be sent to its own provider. */
function aiFeatures() {
    return [
        'quiz_generate' => ['Generate quiz questions', 'teachers'],
        'grading'       => ['Grading assistant + rubrics', 'teachers'],
        'insights'      => ['Class insights summary', 'teachers'],
        'writing'       => ['Writers, feedback rewriter, translation, reply ideas', 'teachers'],
        'explain'       => ['Explain my mistakes / my feedback', 'students'],
        'study'         => ['Lecture study helper, AI Tutor + practice quiz', 'students'],
    ];
}

/** The SCHOOL's configured provider rows, keyed by provider (cached per request; $refresh after changing them). */
function aiProviderRows($refresh = false) {
    global $db;
    static $rows = null;
    if ($rows === null || $refresh) {
        $rows = [];
        try {
            foreach ($db->fetchAll("SELECT * FROM ai_providers WHERE owner_id IS NULL") as $r) $rows[$r['provider']] = $r;
        } catch (PDOException $e) {
            error_log('SmartClass: ai_providers unavailable: ' . $e->getMessage());   // migration 015 not run
        }
    }
    return $rows;
}

/** A provider row is usable: enabled, has a model, an address and (when needed) a readable key. */
function aiProviderUsable($row) {
    if (!$row || !$row['is_enabled'] || trim((string) $row['model']) === '') return false;
    $reg = aiProviderRegistry()[$row['provider']] ?? null;
    if (!$reg || aiBaseUrl($row) === '') return false;
    return !$reg['key'] || decryptSecret($row['api_key']) !== '';
}

/** Providers you run yourself (Ollama, any OpenAI-compatible server): they need an API address field. */
function aiIsLocalProvider($slug) {
    return in_array($slug, ['ollama', 'custom'], true);
}

function aiBaseUrl($row) {
    $reg = aiProviderRegistry()[$row['provider']] ?? ['base' => ''];
    return rtrim(trim((string) ($row['base_url'] ?: $reg['base'])), '/');
}

/** AI is on and the default provider is usable. Students also need "students may use AI". */
function aiEnabled($for_student = false) {
    if (getSetting('ai_enabled', '0') !== '1') return false;
    if ($for_student && getSetting('ai_students', '0') !== '1') return false;
    return aiProviderUsable(aiProviderRows()[getSetting('ai_default_provider', '')] ?? null);
}

/** A person's own provider (Settings › My AI), or null. */
function aiPersonalProvider($user_id) {
    global $db;
    if (!$user_id) return null;
    try {
        return $db->fetchOne("SELECT * FROM ai_providers WHERE owner_id = ? ORDER BY id DESC LIMIT 1", [(int) $user_id]) ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/** Tokens used this month on SCHOOL keys: by one user, or by everyone (null). */
function aiTokensThisMonth($user_id = null) {
    global $db;
    $params = [date('Y-m-01 00:00:00')];
    $only = '';
    if ($user_id !== null) {
        $only = ' AND user_id = ?';
        $params[] = (int) $user_id;
    }
    return (int) ($db->fetchOne("SELECT COALESCE(SUM(input_tokens + output_tokens), 0) AS t FROM ai_usage
        WHERE key_owner IS NULL AND created_at >= ?{$only}", $params)['t'] ?? 0);
}

/** null when the user may use the SCHOOL key now, else the reason (monthly limit reached). */
function aiLimitReason($user_id) {
    $per_user = (int) getSetting('ai_limit_user', '0');
    $school = (int) getSetting('ai_limit_school', '0');
    if ($per_user > 0 && aiTokensThisMonth($user_id) >= $per_user) {
        return 'You reached your monthly AI limit on the school account (' . number_format($per_user) . ' tokens). It resets on the 1st — or add your own provider in Settings › My AI.';
    }
    if ($school > 0 && aiTokensThisMonth(null) >= $school) {
        return 'The school reached its monthly AI limit (' . number_format($school) . ' tokens). It resets on the 1st.';
    }
    return null;
}

/** Tokens a student used this month on one teacher's shared key. */
function aiSharedTokensThisMonth($student_id, $teacher_id) {
    global $db;
    return (int) ($db->fetchOne("SELECT COALESCE(SUM(input_tokens + output_tokens), 0) AS t FROM ai_usage
        WHERE key_owner = ? AND user_id = ? AND created_at >= ?", [(int) $teacher_id, (int) $student_id, date('Y-m-01 00:00:00')])['t'] ?? 0);
}

/**
 * Where an AI call for this person may go, in order. Each: ['row', 'key_owner' (null = school)].
 * Returns [sources, reason] — reason explains an empty list.
 *   student: needs a class with "AI for students" on (else nothing — even their own key);
 *            own provider → teachers' shared providers (under the per-student limit) → school
 *            (if AI on, students allowed, and under the school limits)
 *   teacher: own provider → school (if AI on and under the school limits)
 */
function aiSources($feature, $user_id, $class_id = null) {
    global $db;
    if (SC_STAGING) return [[], 'AI is switched off on the staging copy.'];
    if (!serviceEnabled('ai')) return [[], 'AI is switched off for this installation by the service provider.'];
    $user = $user_id ? $db->fetchOne("SELECT id, role FROM users WHERE id = ?", [(int) $user_id]) : null;
    if (!$user) return [[], 'Sign in to use AI.'];
    $sources = [];
    $reason = null;
    $student = $user['role'] === 'student';

    if ($student) {
        $class = $class_id ? $db->fetchOne(
            "SELECT c.id, c.ai_students FROM classes c INNER JOIN class_members cm ON cm.class_id = c.id AND cm.student_id = ?
             WHERE c.id = ? AND c.is_active = 1", [(int) $user_id, (int) $class_id]) : null;
        if (!$class) return [[], 'AI helpers are only available inside your classes.'];
        if (!$class['ai_students']) return [[], 'Your teacher has switched AI off for this class.'];
    }

    $own = aiPersonalProvider($user_id);
    if (aiProviderUsable($own)) $sources[] = ['row' => $own, 'key_owner' => (int) $user_id];

    if ($student) {
        foreach ($db->fetchAll(
            "SELECT p.* FROM ai_providers p INNER JOIN class_teachers ct ON ct.teacher_id = p.owner_id
             WHERE ct.class_id = ? AND p.share_students = 1 AND p.is_enabled = 1 ORDER BY ct.role = 'owner' DESC", [(int) $class_id]) as $shared) {
            if (!aiProviderUsable($shared)) continue;
            if ((int) $shared['share_limit'] > 0 && aiSharedTokensThisMonth($user_id, $shared['owner_id']) >= (int) $shared['share_limit']) {
                $reason = 'You used your monthly AI allowance from your teacher (' . number_format((int) $shared['share_limit']) . ' tokens). It resets on the 1st.';
                continue;
            }
            $sources[] = ['row' => $shared, 'key_owner' => (int) $shared['owner_id']];
        }
    }

    $school_ok = getSetting('ai_enabled', '0') === '1' && (!$student || getSetting('ai_students', '0') === '1');
    if ($school_ok) {
        if ($limit = aiLimitReason($user_id)) {
            $reason = $reason ?? $limit;
        } else {
            $rows = aiProviderRows();
            foreach (array_unique(array_filter([getSetting('ai_feature_' . $feature, '') ?: getSetting('ai_default_provider', ''),
                                                getSetting('ai_fallback_provider', '')])) as $slug) {
                if (aiProviderUsable($rows[$slug] ?? null)) $sources[] = ['row' => $rows[$slug], 'key_owner' => null];
            }
        }
    }
    if (!$sources && !$reason) {
        $reason = $student ? 'No AI is available for this class yet. Ask your teacher, or add your own provider in Settings › My AI.'
                           : 'No AI provider is available. Add your own in Settings › My AI, or ask the school admin to set one up.';
    }
    return [$sources, $reason];
}

/** Can this person use AI right now (for showing / hiding AI buttons)? */
function aiAvailableFor($feature, $user_id, $class_id = null) {
    return (bool) aiSources($feature, $user_id, $class_id)[0];
}

/**
 * Ask the AI. $system = instructions; $user_text = the content (student answers etc. go
 * here as data, never into $system). Options:
 *   json        true → the answer must be a JSON object/array (returned decoded in 'json')
 *   max_tokens  default 1500        temperature  default 0.4
 *   class_id    the class it is used in (required for students — the teacher's switch)
 *   provider    force one SCHOOL provider (AI Settings › Test connection)
 *   row         force one provider row (My AI › Test connection)
 *   user_id     who is asking (usage + limits); defaults to the signed-in user
 */
function aiChat($feature, $system, $user_text, array $opts = []) {
    $user_id = $opts['user_id'] ?? ($_SESSION['user_id'] ?? null);
    $class_id = isset($opts['class_id']) ? (int) $opts['class_id'] : null;
    if (!empty($opts['row'])) {
        $sources = [['row' => $opts['row'], 'key_owner' => $opts['row']['owner_id'] !== null ? (int) $opts['row']['owner_id'] : null]];
        $reason = null;
    } elseif (!empty($opts['provider'])) {
        $sources = [['row' => aiProviderRows()[$opts['provider']] ?? null, 'key_owner' => null]];
        $reason = null;
    } else {
        [$sources, $reason] = aiSources($feature, $user_id, $class_id);
        if (!$sources) return ['ok' => false, 'error' => $reason];
        // 30 AI calls a minute per person — a stuck button or a script cannot run up the bill
        $guard = rateGuard('ai_user', (string) $user_id, 30, 60);
        if (!$guard['ok']) return ['ok' => false, 'error' => 'You are asking very fast. Please wait ' . rateWaitText($guard['wait']) . ' and try again.'];
    }

    // Live answer: when a page asked for streaming ($opts['stream'] or aiSseOpen()), the text reaches the browser piece by piece while it is written
    $stream = empty($opts['json']) ? ($opts['stream'] ?? ($GLOBALS['sc_ai_stream'] ?? null)) : null;
    $sent = false;
    $onDelta = $stream ? function ($d) use (&$sent, $stream) { $sent = true; $stream($d); } : null;

    $last_error = $reason ?? 'No AI provider is set up.';
    foreach ($sources as $src) {
        $row = $src['row'];
        $slug = $row['provider'] ?? '';
        if (!aiProviderUsable($row)) {
            $last_error = (aiProviderRegistry()[$slug]['label'] ?? 'The provider') . ' is not set up completely.';
            continue;
        }
        $model = trim((string) ($opts['model'] ?? $row['model']));
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $t0 = microtime(true);
            try {
                $r = aiCallProvider($row, $model, $system, $user_text, (int) ($opts['max_tokens'] ?? 1500),
                    (float) ($opts['temperature'] ?? 0.4), !empty($opts['json']), $onDelta);
                $json = null;
                if (!empty($opts['json'])) {
                    $json = aiExtractJson($r['text']);
                    if ($json === null) throw new AiError('The AI answer was not in the expected format.', 0, false);
                }
                aiLogUsage($user_id, $feature, $row, $model, $r['in'], $r['out'], 'ok', null, $t0, $src['key_owner'], $class_id);
                return ['ok' => true, 'text' => $r['text'], 'json' => $json, 'provider' => $slug, 'model' => $model,
                        'key_owner' => $src['key_owner'], 'input_tokens' => $r['in'], 'output_tokens' => $r['out']];
            } catch (AiError $e) {
                aiLogUsage($user_id, $feature, $row, $model, $e->in, $e->out, 'error', $e->getMessage(), $t0, $src['key_owner'], $class_id);
                $last_error = (aiProviderRegistry()[$slug]['label'] ?? $slug) . ': ' . $e->getMessage();
                if ($sent) return ['ok' => false, 'error' => $last_error];   // text was already shown: never start the answer again somewhere else
                if (!$e->retryable || $attempt === 2) break;
                usleep(800000);
            }
        }
    }
    return ['ok' => false, 'error' => $last_error];
}

/** An AI call failed. $retryable = worth trying again (network, 429, 5xx). */
class AiError extends Exception {
    public $retryable;
    public $in = 0;
    public $out = 0;
    public function __construct($message, $code = 0, $retryable = false) {
        parent::__construct($message, $code);
        $this->retryable = $retryable;
    }
}

/** One request to one provider. Returns ['text', 'in', 'out']; throws AiError. */
function aiCallProvider(array $row, $model, $system, $user_text, $max_tokens, $temperature, $json, $onDelta = null) {
    if ($onDelta) return aiStreamProvider($row, $model, $system, $user_text, $max_tokens, $temperature, $onDelta);
    $reg = aiProviderRegistry()[$row['provider']];
    $base = aiBaseUrl($row);
    $key = decryptSecret($row['api_key']);
    $timeout = max(10, min(300, (int) getSetting('ai_timeout', '60')));

    if ($reg['api'] === 'anthropic') {
        $body = ['model' => $model, 'max_tokens' => $max_tokens, 'temperature' => $temperature, 'system' => $system,
                 'messages' => [['role' => 'user', 'content' => $user_text]]];
        $r = aiHttp('POST', $base . '/messages', ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'], $body, $timeout);
        $text = '';
        foreach ($r['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        return ['text' => $text, 'in' => (int) ($r['usage']['input_tokens'] ?? 0), 'out' => (int) ($r['usage']['output_tokens'] ?? 0)];
    }

    if ($reg['api'] === 'gemini') {
        $config = ['maxOutputTokens' => $max_tokens, 'temperature' => $temperature];
        if ($json) $config['responseMimeType'] = 'application/json';
        $body = ['systemInstruction' => ['parts' => [['text' => $system]]],
                 'contents' => [['role' => 'user', 'parts' => [['text' => $user_text]]]],
                 'generationConfig' => $config];
        $r = aiHttp('POST', $base . '/models/' . rawurlencode(preg_replace('#^models/#', '', $model)) . ':generateContent',
            ['x-goog-api-key: ' . $key], $body, $timeout);
        $text = '';
        foreach ($r['candidates'][0]['content']['parts'] ?? [] as $p) $text .= $p['text'] ?? '';
        if ($text === '' && !empty($r['promptFeedback']['blockReason'])) {
            throw new AiError('The request was blocked by the provider (' . $r['promptFeedback']['blockReason'] . ').');
        }
        return ['text' => $text, 'in' => (int) ($r['usageMetadata']['promptTokenCount'] ?? 0), 'out' => (int) ($r['usageMetadata']['candidatesTokenCount'] ?? 0)];
    }

    // OpenAI-compatible chat completions (OpenAI, Groq, Mistral, DeepSeek, OpenRouter, Ollama, custom)
    $body = ['model' => $model, 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user_text]],
             'temperature' => $temperature];
    // Newer OpenAI models only accept max_completion_tokens; most others use max_tokens
    $body[$row['provider'] === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $max_tokens;
    if ($json && in_array($row['provider'], ['openai', 'groq', 'mistral', 'deepseek'], true)) {
        $body['response_format'] = ['type' => 'json_object'];
    }
    $headers = $key !== '' ? ['Authorization: Bearer ' . $key] : [];
    if ($row['provider'] === 'openrouter') {
        $headers[] = 'X-Title: ' . APP_NAME;
    }
    try {
        $r = aiHttp('POST', $base . '/chat/completions', $headers, $body, $timeout);
    } catch (AiError $e) {
        // Some models refuse a parameter (e.g. temperature, max_tokens, response_format): adapt once
        $msg = strtolower($e->getMessage());
        if ($e->getCode() !== 400) throw $e;
        if (strpos($msg, 'temperature') !== false) unset($body['temperature']);
        elseif (strpos($msg, 'max_completion_tokens') !== false || strpos($msg, 'max_tokens') !== false) {
            // the provider refused the token-limit name we used: switch to the other one
            $sent = isset($body['max_completion_tokens']) ? 'max_completion_tokens' : 'max_tokens';
            $n = $body[$sent];
            unset($body[$sent]);
            $body[$sent === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens'] = $n;
        } elseif (strpos($msg, 'response_format') !== false) unset($body['response_format']);
        else throw $e;
        $r = aiHttp('POST', $base . '/chat/completions', $headers, $body, $timeout);
    }
    $text = (string) ($r['choices'][0]['message']['content'] ?? '');
    return ['text' => $text, 'in' => (int) ($r['usage']['prompt_tokens'] ?? 0), 'out' => (int) ($r['usage']['completion_tokens'] ?? 0)];
}

/**
 * The same request as aiCallProvider(), but the answer is read while it is written (server-sent events) and every new piece of text is handed
 * to $onDelta($text). Returns ['text', 'in', 'out'] when it is finished; throws AiError (before any text → the caller may try again / another provider).
 */
function aiStreamProvider(array $row, $model, $system, $user_text, $max_tokens, $temperature, callable $onDelta) {
    $reg = aiProviderRegistry()[$row['provider']];
    $base = aiBaseUrl($row);
    $key = decryptSecret($row['api_key']);
    $timeout = max(30, min(300, (int) getSetting('ai_timeout', '60') * 2));
    $api = $reg['api'];
    $text = ''; $in = 0; $out = 0;

    // one line of the event stream ("data: {...}") → text pieces
    $handle = function ($payload) use ($api, &$text, &$in, &$out, $onDelta) {
        if ($payload === '' || $payload === '[DONE]') return;
        $j = json_decode($payload, true);
        if (!is_array($j)) return;
        $piece = '';
        if ($api === 'anthropic') {
            $type = $j['type'] ?? '';
            if ($type === 'content_block_delta' && ($j['delta']['type'] ?? '') === 'text_delta') $piece = (string) ($j['delta']['text'] ?? '');
            elseif ($type === 'message_start') $in = (int) ($j['message']['usage']['input_tokens'] ?? 0);
            elseif ($type === 'message_delta') $out = (int) ($j['usage']['output_tokens'] ?? $out);
            elseif ($type === 'error') throw new AiError('The provider stopped the answer: ' . mb_strimwidth((string) ($j['error']['message'] ?? 'error'), 0, 200, '…'));
        } elseif ($api === 'gemini') {
            foreach ($j['candidates'][0]['content']['parts'] ?? [] as $part) $piece .= $part['text'] ?? '';
            if (!empty($j['usageMetadata'])) { $in = (int) ($j['usageMetadata']['promptTokenCount'] ?? $in); $out = (int) ($j['usageMetadata']['candidatesTokenCount'] ?? $out); }
        } else {
            $piece = (string) ($j['choices'][0]['delta']['content'] ?? '');
            if (!empty($j['usage'])) { $in = (int) ($j['usage']['prompt_tokens'] ?? $in); $out = (int) ($j['usage']['completion_tokens'] ?? $out); }
            if (isset($j['error']['message'])) throw new AiError('The provider stopped the answer: ' . mb_strimwidth((string) $j['error']['message'], 0, 200, '…'));
        }
        if ($piece !== '') { $text .= $piece; $onDelta($piece); }
    };

    if ($api === 'anthropic') {
        $url = $base . '/messages'; $headers = ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'];
        $body = ['model' => $model, 'max_tokens' => $max_tokens, 'temperature' => $temperature, 'system' => $system, 'stream' => true,
                 'messages' => [['role' => 'user', 'content' => $user_text]]];
    } elseif ($api === 'gemini') {
        $url = $base . '/models/' . rawurlencode(preg_replace('#^models/#', '', $model)) . ':streamGenerateContent?alt=sse'; $headers = ['x-goog-api-key: ' . $key];
        $body = ['systemInstruction' => ['parts' => [['text' => $system]]], 'contents' => [['role' => 'user', 'parts' => [['text' => $user_text]]]],
                 'generationConfig' => ['maxOutputTokens' => $max_tokens, 'temperature' => $temperature]];
    } else {
        $url = $base . '/chat/completions'; $headers = $key !== '' ? ['Authorization: Bearer ' . $key] : [];
        if ($row['provider'] === 'openrouter') $headers[] = 'X-Title: ' . APP_NAME;
        $body = ['model' => $model, 'stream' => true, 'temperature' => $temperature,
                 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user_text]]];
        $body[$row['provider'] === 'openai' ? 'max_completion_tokens' : 'max_tokens'] = $max_tokens;
        if ($row['provider'] === 'openai') $body['stream_options'] = ['include_usage' => true];
    }
    for ($try = 1; $try <= 2; $try++) {
        try {
            aiStreamHttp($url, $headers, $body, $timeout, $handle);
            break;
        } catch (AiError $e) {   // a model that refuses a parameter: adapt once (only possible while nothing was sent yet)
            if ($try === 2 || $text !== '' || $e->getCode() !== 400 || $api !== 'openai') throw $e;
            $msg = strtolower($e->getMessage());
            if (strpos($msg, 'temperature') !== false) unset($body['temperature']);
            elseif (strpos($msg, 'max_completion_tokens') !== false || strpos($msg, 'max_tokens') !== false) {
                $sent = isset($body['max_completion_tokens']) ? 'max_completion_tokens' : 'max_tokens'; $n = $body[$sent]; unset($body[$sent]);
                $body[$sent === 'max_tokens' ? 'max_completion_tokens' : 'max_tokens'] = $n;
            } elseif (strpos($msg, 'stream_options') !== false) unset($body['stream_options']);
            else throw $e;
        }
    }
    if ($text === '') throw new AiError('The provider sent no text.', 0, true);
    if ($in === 0) $in = (int) ceil((mb_strlen($system) + mb_strlen($user_text)) / 4);   // providers that do not report usage while streaming: an estimate
    if ($out === 0) $out = (int) ceil(mb_strlen($text) / 4);
    return ['text' => $text, 'in' => $in, 'out' => $out];
}

/** POST JSON and feed the answer, line by line, to $onData(payload of every "data:" line). Throws AiError. */
function aiStreamHttp($url, array $headers, array $body, $timeout, callable $onData) {
    if (!function_exists('curl_init')) throw new AiError('The PHP curl extension is not installed on this server.');
    $ch = curl_init($url);
    $headers[] = 'Accept: text/event-stream'; $headers[] = 'Content-Type: application/json';
    $status = 0; $buf = ''; $errRaw = ''; $retryAfter = null;
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => APP_NAME . ' (PHP)', CURLOPT_BUFFERSIZE => 1024,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$status, &$retryAfter) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int) $m[1];
            elseif (stripos($h, 'retry-after:') === 0) $retryAfter = (int) trim(substr($h, 12));
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$status, &$buf, &$errRaw, $onData) {
            if ($status < 200 || $status >= 300) { $errRaw .= $chunk; return strlen($chunk); }   // an error answer is plain JSON, not events
            $buf .= $chunk;
            while (($nl = strpos($buf, "\n")) !== false) {
                $line = rtrim(substr($buf, 0, $nl), "\r"); $buf = substr($buf, $nl + 1);
                if (strncmp($line, 'data:', 5) === 0) $onData(ltrim(substr($line, 5)));
            }
            return strlen($chunk);
        },
    ]);
    try {
        $ok = curl_exec($ch);
    } catch (AiError $e) { curl_close($ch); throw $e; }
    $err = curl_error($ch); curl_close($ch);
    if ($ok === false && $status < 300) throw new AiError('Could not reach the provider (' . ($err ?: 'network error') . ').', 0, true);
    if ($status < 200 || $status >= 300) throw aiHttpError($status, json_decode($errRaw, true), $errRaw, $retryAfter);
    if (trim($buf) !== '' && strncmp($buf, 'data:', 5) === 0) $onData(ltrim(substr(trim($buf), 5)));
}

/**
 * Start a live (server-sent events) answer for an AI page: headers, no buffering, the session lock released. From here on everything the
 * AI writes is sent piece by piece as {"t": "text"} events; finish with aiSseSend() — {"done": true, ...} or {"error": "..."}.
 */
function aiSseOpen() {
    if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) session_write_close();   // do not block the person's other pages while the answer is written
    while (ob_get_level()) @ob_end_clean();
    @ini_set('zlib.output_compression', '0'); @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    @set_time_limit(300);
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store'); header('X-Accel-Buffering: no'); header('X-Content-Type-Options: nosniff');
    echo ':' . str_repeat(' ', 2048) . "\n\n"; @flush();   // a first block of padding pushes through proxies that hold small answers back
    $GLOBALS['sc_ai_stream'] = function ($piece) { aiSseSend(['t' => $piece]); };
}
function aiSseSend(array $data) {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
    @ob_flush(); @flush();
}

/** JSON over HTTPS with curl. Returns the decoded body; throws AiError with a clear message. */
function aiHttp($method, $url, array $headers, $body, $timeout) {
    if (!function_exists('curl_init')) throw new AiError('The PHP curl extension is not installed on this server.');
    $ch = curl_init($url);
    $headers[] = 'Accept: application/json';
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => APP_NAME . ' (PHP)',
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$retryAfter) { if (stripos($h, 'retry-after:') === 0) $retryAfter = (int) trim(substr($h, 12)); return strlen($h); },
    ]);
    $retryAfter = null;
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        throw new AiError('Could not reach the provider (' . ($err ?: 'network error') . ').', 0, true);
    }
    $data = json_decode($raw, true);
    if ($status >= 200 && $status < 300) {
        if (!is_array($data)) throw new AiError('The provider sent an answer that is not JSON.');
        return $data;
    }
    throw aiHttpError($status, $data, (string) $raw, $retryAfter);
}

/** The AiError for a non-2xx answer: the provider's own message (never with our key), shortened, plus what the status usually means. */
function aiHttpError($status, $data, $raw, $retryAfter = null) {
    $detail = $data['error']['message'] ?? ($data['error'] ?? null);
    $detail = is_string($detail) ? $detail : (is_array($data) ? '' : substr(trim(strip_tags((string) $raw)), 0, 120));
    // Providers echo a masked copy of the key ("sk-ab…****…xyz"): never show any part of it
    $detail = preg_replace('/[A-Za-z0-9_\-.]{2,}\*{3,}[A-Za-z0-9_\-.]*/', 'your key', $detail);
    $detail = mb_strimwidth(trim($detail), 0, 300, '…');
    if ($status === 429 || ($status === 402)) return aiLimitError($status, $detail, $retryAfter);
    $why = [401 => 'The API key was rejected', 403 => 'The API key is not allowed to do this', 404 => 'Model or address not found'][$status] ?? ($status >= 500 ? 'The provider had a problem' : 'Request refused');
    return new AiError($why . ' (' . $status . ')' . ($detail !== '' ? ': ' . $detail : ''), $status, $status >= 500);
}

/**
 * The model is out of rate or out of credit: a clear sentence for the person (never a raw provider message), with how long to wait when the provider says.
 * Three cases — no credit / quota used up (nothing helps until it is topped up), a daily limit, or a short rate limit (a minute or so). Only the short one is worth retrying at once.
 */
function aiLimitError($status, $detail, $retryAfter = null) {
    $d = strtolower((string) $detail);
    if ($status === 402 || preg_match('/insufficient[_ ]?(quota|funds|credit|balance)|billing|exceeded your current quota|credit|out of (funds|quota)|quota.*(exceed|exhaust|used)|payment/', $d)) {
        return new AiError('The AI account has run out of credit (or its quota is used up). Ask the school administrator to add credit or to choose another AI provider.', $status, false);
    }
    $wait = $retryAfter;
    if ($wait === null && preg_match('/try again in\s+(?:(\d+)h)?\s*(?:(\d+)m(?!s))?\s*(?:(\d+(?:\.\d+)?)s)?/i', (string) $detail, $m)) $wait = (int) ceil(((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (float) ($m[3] ?? 0));
    if (preg_match('/per day|daily|tokens per day|TPD|RPD/i', (string) $detail) && ($wait === null || $wait > 600)) {
        return new AiError('The AI model has reached its daily limit. Try again later today or tomorrow, or ask your teacher or school administrator to choose another model.', $status, false);
    }
    $when = $wait === null ? 'a minute' : ($wait <= 20 ? 'a few seconds' : ($wait <= 90 ? 'a minute' : ($wait <= 900 ? 'a few minutes' : 'a while')));
    return new AiError('The AI model is busy right now — its request limit was reached. Please wait ' . $when . ' and try again.', $status, $wait !== null && $wait <= 5);
}

/** Model ids offered by a provider, sorted (empty list when it has no models endpoint). Throws AiError. */
function aiListModels(array $row) {
    $reg = aiProviderRegistry()[$row['provider']];
    $base = aiBaseUrl($row);
    $key = decryptSecret($row['api_key']);
    if ($reg['api'] === 'anthropic') {
        $r = aiHttp('GET', $base . '/models?limit=1000', ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'], null, 20);
        $ids = array_column($r['data'] ?? [], 'id');
    } elseif ($reg['api'] === 'gemini') {
        $r = aiHttp('GET', $base . '/models?pageSize=1000', ['x-goog-api-key: ' . $key], null, 20);
        $ids = [];
        foreach ($r['models'] ?? [] as $m) {
            if (in_array('generateContent', $m['supportedGenerationMethods'] ?? ['generateContent'], true)) {
                $ids[] = preg_replace('#^models/#', '', (string) $m['name']);
            }
        }
    } else {
        $r = aiHttp('GET', $base . '/models', $key !== '' ? ['Authorization: Bearer ' . $key] : [], null, 20);
        $ids = array_column($r['data'] ?? [], 'id');
    }
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
    $ids = array_values(array_filter($ids, fn($id) =>
        !preg_match('/(embed|whisper|tts|dall-e|moderation|transcribe|audio|realtime|image|speech|babbage|davinci-002|sora|rerank|guard)/i', $id)));
    sort($ids, SORT_NATURAL | SORT_FLAG_CASE);
    return array_slice($ids, 0, 1000);
}

/** The first JSON object / array in an AI answer (``` fences and chatter around it are ignored), else null. */
function aiExtractJson($text) {
    $text = trim((string) $text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;
    $start = strcspn($text, '{[');
    if ($start >= strlen($text)) return null;
    $end = max(strrpos($text, '}'), strrpos($text, ']'));
    if ($end === false || $end <= $start) return null;
    $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
    return is_array($decoded) ? $decoded : null;
}

/** One row in ai_usage (cost only when the provider has prices). Never throws. */
function aiLogUsage($user_id, $feature, array $row, $model, $in, $out, $status, $error, $t0, $key_owner = null, $class_id = null) {
    global $db;
    try {
        $cost = null;   // prices are not entered (the client decided key + model is enough)
        $db->insert('ai_usage', [
            'user_id' => $user_id ? (int) $user_id : null, 'feature' => mb_substr((string) $feature, 0, 40),
            'provider' => $row['provider'], 'key_owner' => $key_owner, 'class_id' => $class_id,
            'model' => mb_substr((string) $model, 0, 150),
            'input_tokens' => (int) $in, 'output_tokens' => (int) $out, 'cost' => $cost,
            'status' => $status, 'error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
        ]);
        if ($key_owner === null && $status === 'ok') aiBudgetAlert();
    } catch (Throwable $e) {
        error_log('SmartClass: ai_usage log failed: ' . $e->getMessage());
    }
}

/** The school's monthly budget on school keys: ['limit', 'used', 'pct'] (limit 0 = none). */
function aiBudgetStatus() {
    $limit = (int) getSetting('ai_limit_school', '0');
    $used = aiTokensThisMonth(null);
    return ['limit' => $limit, 'used' => $used, 'pct' => $limit > 0 ? (int) floor($used / $limit * 100) : 0];
}

/** Tell the administrators once per month at 80 % and once at 100 % of the school's AI budget. */
function aiBudgetAlert() {
    global $db;
    $b = aiBudgetStatus();
    if ($b['limit'] <= 0 || $b['pct'] < 80) return;
    $key = 'ai_budget_alert_' . date('Ym');
    $level = $b['pct'] >= 100 ? 100 : 80;
    if ((int) getSetting($key, '0') >= $level) return;
    setSetting($key, (string) $level);
    foreach ($db->fetchAll("SELECT id FROM users WHERE is_admin = 1 AND role = 'teacher' AND is_active = 1") as $a) {
        notify((int) $a['id'], 'ai_budget', $level === 100 ? 'The school\'s monthly AI budget is used up' : 'The school has used 80% of its monthly AI budget',
            number_format($b['used']) . ' of ' . number_format($b['limit']) . ' tokens' . ($level === 100 ? ' — AI on the school account stops until the 1st. Raise the limit in Settings › AI providers if needed.' : '. At 100% AI on the school account stops until the 1st.'), 'pages/settings/ai.php');
    }
}

/**
 * Validate a provider form (AI Settings and My AI share it).
 * Returns [values, errors]; the API key may be left empty to keep the saved one.
 */
function aiValidateProviderForm($slug, array $post, $existing) {
    $reg = aiProviderRegistry()[$slug];
    $f = [
        'api_key'  => trim((string) ($post['api_key'] ?? '')),
        'base_url' => rtrim(trim((string) ($post['base_url'] ?? '')), '/'),
        'model'    => trim((string) ($post['model'] ?? '')),
        'is_enabled' => !empty($post['is_enabled']),
    ];
    $e = [];
    if ($reg['key'] && $f['api_key'] === '' && (!$existing || $existing['provider'] !== $slug || decryptSecret($existing['api_key']) === '')) {
        $e['api_key'] = 'Paste the API key from ' . $reg['label'];
    }
    if (mb_strlen($f['api_key']) > 500 || preg_match('/\s/', $f['api_key'])) $e['api_key'] = 'That does not look like an API key (no spaces, up to 500 characters)';
    if ($f['base_url'] !== '') {
        $scheme = strtolower((string) parse_url($f['base_url'], PHP_URL_SCHEME));
        if (!filter_var($f['base_url'], FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true) || mb_strlen($f['base_url']) > 255) {
            $e['base_url'] = 'Enter a full address starting with http:// or https://';
        }
    } elseif ($reg['base'] === '') {
        $e['base_url'] = 'Enter the address of the API, e.g. https://api.example.com/v1';
    }
    // The model may be empty on the first save: the model list is loaded right after the key is saved
    if ($f['model'] !== '' && (mb_strlen($f['model']) > 150 || !preg_match('#^[A-Za-z0-9._:/@+-]+$#', $f['model']))) {
        $e['model'] = 'Model names use letters, numbers and . _ : / @ + -';
    }
    return [$f, $e];
}

/**
 * Save a validated provider form. $owner_id null = school provider (one row per provider);
 * a user id = that person's own provider (one row per person — choosing another provider replaces it).
 * $extra: more columns (share_students, share_limit).
 * Returns ['id', 'models', 'error'] — the model list is loaded right away.
 */
function aiSaveProvider($owner_id, $slug, array $f, $existing, $updated_by, array $extra = []) {
    global $db;
    $reg = aiProviderRegistry()[$slug];
    $data = [
        'base_url' => $f['base_url'] !== '' && $f['base_url'] !== $reg['base'] ? $f['base_url'] : null,
        'model' => $f['model'] !== '' ? $f['model'] : null,
        'is_enabled' => $f['is_enabled'] ? 1 : 0,
        'updated_by' => (int) $updated_by,
    ] + $extra;
    if ($f['api_key'] !== '') {
        $data['api_key'] = encryptSecret($f['api_key']);
        $data['key_hint'] = substr($f['api_key'], -4);
    }
    if ($existing && $existing['provider'] === $slug) {
        $db->update('ai_providers', $data, 'id = ?', [$existing['id']]);
        $id = (int) $existing['id'];
    } else {
        if ($existing && $owner_id !== null) {
            $db->delete('ai_providers', 'owner_id = ?', [(int) $owner_id]);   // one personal provider per person
        }
        $id = (int) $db->insert('ai_providers', $data + ['provider' => $slug, 'owner_id' => $owner_id]);
    }
    aiProviderRows(true);
    [$models, $error] = aiRefreshModels($id);   // the model list loads by itself — the person just picks one
    return ['id' => $id, 'models' => $models, 'error' => $error];
}

/**
 * Load the provider's model list into models_cache. Returns [models, error|null] — never throws.
 * If no model was chosen yet and the provider offers exactly one, it is chosen.
 */
function aiRefreshModels($provider_id) {
    global $db;
    $row = $db->fetchOne("SELECT * FROM ai_providers WHERE id = ?", [(int) $provider_id]);
    if (!$row) return [[], 'Provider not found'];
    $reg = aiProviderRegistry()[$row['provider']] ?? null;
    if (!$reg || ($reg['key'] && decryptSecret($row['api_key']) === '')) return [[], 'Save the API key first'];
    try {
        $models = aiListModels($row);
    } catch (AiError $e) {
        return [[], $e->getMessage()];
    }
    $update = ['models_cache' => json_encode($models)];
    if (trim((string) $row['model']) === '' && count($models) === 1) $update['model'] = $models[0];
    $db->update('ai_providers', $update, 'id = ?', [$row['id']]);
    aiProviderRows(true);
    return [$models, null];
}

/**
 * The Model control (AI Settings + My AI): a dropdown of the provider's models when the list is
 * loaded (the saved model stays selectable even if the provider stopped listing it), otherwise a
 * text field. $saved = the provider row exists (else: "save the key first").
 */
function aiModelField($id, array $models, $current, $error, $label, $saved) {
    $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    $cls = $error !== '' ? ' class="is-invalid"' : '';
    $out = '<div class="form-group"><label for="' . $h($id) . '">Model</label>';
    if ($models) {
        if ($current !== '' && !in_array($current, $models, true)) array_unshift($models, $current);
        $out .= '<select id="' . $h($id) . '" name="model"' . $cls . '><option value="">Choose a model…</option>';
        foreach ($models as $m) {
            $out .= '<option value="' . $h($m) . '"' . ($m === $current ? ' selected' : '') . '>' . $h($m) . '</option>';
        }
        $out .= '</select><div class="invalid-feedback">' . $h($error) . '</div>'
              . '<small class="form-hint">' . count($models) . ' models from ' . $h($label) . ', loaded automatically.</small>';
    } else {
        $out .= '<input type="text" id="' . $h($id) . '" name="model" maxlength="150" spellcheck="false" value="' . $h($current) . '"'
              . ' placeholder="' . ($saved ? 'Type the model name' : 'Save your key — the models load by themselves') . '"' . $cls . '>'
              . '<div class="invalid-feedback">' . $h($error) . '</div>'
              . '<small class="form-hint">' . ($saved ? $h($label) . ' did not send a model list — type the model name.' : 'Paste the key and press Save: the list of models appears here.') . '</small>';
    }
    return $out . '</div>';
}

/** Task result after saving a provider: pick a model next, or the error loading models. */
function aiSavedMessage($label, $chosen_model, array $saved) {
    if ($saved['error'] !== null) {
        return ['warning', $label . ' saved', 'But the model list could not be loaded: ' . $saved['error']];
    }
    if ($chosen_model === '' && $saved['models']) {
        return ['success', $label . ' saved', count($saved['models']) . ' models loaded — now choose one in the Model list and press Save.'];
    }
    return ['success', $label . ' saved', 'Press "Test connection" to check it.'];
}


/** The class's choice of AI answer language: 'screen' (default), 'en' or 'question'. */
function aiLanguageMode($classId) {
    global $db; static $cache = [];
    $classId = (int) $classId; if (!$classId) return 'screen';
    if (!isset($cache[$classId])) $cache[$classId] = ($db->fetchOne("SELECT ai_language FROM classes WHERE id = ?", [$classId])['ai_language'] ?? 'screen');
    return $cache[$classId];
}

/** The language code an answer is written in for this class and this screen: 'ps', 'fa', 'en', or 'question' (follow the question). */
function aiAnswerLang($classId = 0, $screen = null) {
    $mode = aiLanguageMode($classId);
    if ($mode === 'en') return 'en';
    if ($mode === 'question') return 'question';
    return $screen ?: (function_exists('i18nLang') ? i18nLang() : 'en');
}

/**
 * The sentence added to every student-facing AI prompt: which language to answer in.
 * Default = the language of the student's screen (so a Pashto screen gets Pashto), unless the student asks for another language;
 * the teacher can switch a class to "always English" (language classes) or "the language of the question". Pashto and Dari are asked
 * to be written in their own Arabic-script spelling; technical terms and code stay as they are.
 */
function aiReplyLanguageRule($classId = 0, $screen = null) {
    $names = ['en' => 'English', 'ps' => 'Pashto', 'fa' => 'Dari (Afghan Persian)'];
    $l = aiAnswerLang($classId, $screen);
    if ($l === 'question') $rule = "Answer in the language of the student's question; if it shows no language (a number, one word, code), answer in " . ($names[$screen ?: (function_exists('i18nLang') ? i18nLang() : 'en')] ?? 'English') . ". ";
    else $rule = "Answer in " . $names[$l] . ", even when the class material or the question is in another language, unless the student clearly asks for a different language. ";
    return $rule . "When you write Pashto or Dari use correct Arabic-script spelling in that language (not Urdu, not Arabic) and keep technical terms, names and code in their original form. ";
}


/**
 * The writing markup the lecture page understands (see lectureInline / renderLecture) — added to the prompts so AI text arrives already laid out:
 * headings, bold key terms, a few colours, tip boxes, lists and code. 'full' = a whole lecture, 'chat' = a short answer.
 */
function aiRichFormatRules($level = 'chat') {
    $basic = "FORMAT the text with this simple markup (never HTML): **bold** for key terms and important words, *italic* for emphasis, `backticks` for code words, "
        . "lines starting with \"- \" for bullet lists and \"1. \" for steps, {blue}word{/blue} for a new term, {green}text{/green} for what to do or a good example, "
        . "{red}text{/red} for a warning or a common mistake, {mark}text{/mark} to highlight the single most important sentence (use colours sparingly — a few per answer), "
        . "and code in fenced blocks (three backticks, then the language name). ";
    if ($level === 'chat') return $basic . "Use a short heading line starting with \"## \" only when the answer has several parts. ";
    return $basic . "Use \"## \" for section headings and \"### \" for sub-headings, \"> \" at the start of a line for a tip or note box (for example \"> Tip: ...\" or \"> Remember: ...\"), "
        . "\"---\" on its own line between big parts, and after a code block that prints something add a line \"Output:\" followed by a fenced block with the output. "
        . "When a picture explains better than words, draw it in a fenced block: ```mermaid for flowcharts and processes (graph TD), sequence, class, state, ER diagrams, Gantt, pie and mind maps — put a label that contains brackets in double quotes; "
        . "```dot for Graphviz graphs; ```chart for a Chart.js chart written as JSON {\"type\": \"bar|line|pie|doughnut|radar\", \"data\": {\"labels\": [...], \"datasets\": [{\"label\": \"...\", \"data\": [...]}]}} with the numbers inside it; "
        . "```vega-lite for other data charts, also with the data inside (never a web address). Use a picture only when it helps. "
        . "NEVER draw a flowchart or any diagram with plain characters (+---+ boxes, | and v arrows, [ Start ] text art in a ```text block): it cannot be shown as a picture and stays as ugly text. Always write it as a ```mermaid block. ";
}

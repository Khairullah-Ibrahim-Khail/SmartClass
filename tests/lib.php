<?php
/**
 * Tiny test kit (no framework needed):  test('name', function () { eq(1, 1); });
 * Helpers: eq / ok / contains / notContains / statusIs, a throw-away database, a PHP built-in server and an HTTP client with a cookie jar.
 */
$GLOBALS['__tests'] = []; $GLOBALS['__pass'] = 0; $GLOBALS['__fail'] = [];
function test($name, callable $fn) { $GLOBALS['__tests'][] = [$name, $fn]; }
class AssertFail extends Exception {}
function fail($msg) { throw new AssertFail($msg); }
function ok($cond, $msg = 'expected true') { if (!$cond) fail($msg); }
function eq($expected, $actual, $msg = '') { if ($expected !== $actual) fail(($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
function near($expected, $actual, $eps = 0.01, $msg = '') { if (abs($expected - $actual) > $eps) fail(($msg ? $msg . ': ' : '') . "expected ~{$expected}, got {$actual}"); }
function contains($needle, $hay, $msg = '') { if (strpos((string) $hay, (string) $needle) === false) fail(($msg ? $msg . ': ' : '') . 'missing "' . $needle . '" in ' . substr(preg_replace('/\s+/', ' ', strip_tags((string) $hay)), 0, 160)); }
function notContains($needle, $hay, $msg = '') { if (strpos((string) $hay, (string) $needle) !== false) fail(($msg ? $msg . ': ' : '') . 'found "' . $needle . '" — it must not be there'); }

/** The HTTP client: one cookie jar per person. */
class Http {
    public $jar; public $base; public $code = 0; public $redirect = ''; public $body = ''; public $headers = '';
    public function __construct($base) { $this->base = rtrim($base, '/'); $this->jar = tempnam(sys_get_temp_dir(), 'sctj'); }
    public function __destruct() { @unlink($this->jar); }
    public function req($method, $path, array $post = [], array $files = [], array $headers = []) {
        $ch = curl_init(strpos($path, 'http') === 0 ? $path : $this->base . '/' . ltrim($path, '/'));
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30];
        if ($method === 'POST') {
            $opt[CURLOPT_POST] = true;
            foreach ($files as $k => $f) $post[$k] = new CURLFile($f);
            $opt[CURLOPT_POSTFIELDS] = $files ? $post : http_build_query($post);
        }
        $h = []; foreach ($headers as $k => $v) $h[] = "$k: $v"; if ($h) $opt[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opt);
        $raw = (string) curl_exec($ch);
        $this->code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $this->headers = substr($raw, 0, $size); $this->body = substr($raw, $size);
        $this->redirect = preg_match('/^Location:\s*(.+)$/mi', $this->headers, $m) ? trim($m[1]) : '';
        curl_close($ch);
        return $this;
    }
    public function get($p) { return $this->req('GET', $p); }
    public function post($p, array $d = [], array $f = [], array $h = []) { return $this->req('POST', $p, $d, $f, $h); }
    public function token($page = 'pages/dashboard.php') {
        $this->get($page);
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $this->body, $m)) return $m[1];
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $this->body, $m)) return $m[1];
        return '';
    }
    public function login($email, $password) {
        $this->get('pages/auth/login.php');
        preg_match('/name="csrf_token" value="([^"]+)"/', $this->body, $m);
        $this->post('pages/auth/login.php', ['csrf_token' => $m[1] ?? '', 'email' => $email, 'password' => $password]);
        return $this;
    }
    /** Is the page really shown (200 with content, not a redirect or an error)? */
    public function shown() { return $this->code === 200 && strlen($this->body) > 200; }
}

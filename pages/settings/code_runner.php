<?php
/**
 * Settings › Code runner (super admin): which languages the Run button of a lecture can run.
 *   Always on, inside the reader's browser:   Python, JavaScript, HTML, CSS (nothing leaves the page)
 *   With a runner (this page):                C, C++, C#, Java, PHP, Go, Rust, Ruby, Kotlin, Swift, Bash, SQL, R, Lua, Perl, TypeScript …
 *   code_runner_url   address of a Piston-compatible runner (https://github.com/engineer-man/piston — run it yourself with Docker)
 *   code_runner_token optional Authorization value the runner wants (stored encrypted)   code_runner_on  switch
 *   POST save | test   CSRF; Post/Redirect/Get. "Test" runs a tiny program and reports what came back.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

$task_result = Session::flash('runner_msg');
$errors = ['url' => ''];
$vals = ['url' => codeRunnerUrl(), 'on' => getSetting('code_runner_on', '1') === '1'];
$has_token = trim((string) getSetting('code_runner_token', '')) !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'save') {
        $vals['url'] = rtrim(trim((string) ($_POST['url'] ?? '')), '/'); $vals['on'] = !empty($_POST['on']);
        if ($vals['url'] !== '' && !codeRunnerUrlValid($vals['url'])) $errors['url'] = 'Enter a full address starting with http:// or https://';
        if (!$errors['url']) {
            setSetting('code_runner_url', $vals['url'], $current_user_id); setSetting('code_runner_on', $vals['on'] ? '1' : '0', $current_user_id);
            $tok = trim((string) ($_POST['token'] ?? ''));
            if (!empty($_POST['clear_token'])) setSetting('code_runner_token', '', $current_user_id);
            elseif ($tok !== '') setSetting('code_runner_token', encryptSecret($tok), $current_user_id);
            logActivity('code_runner', 'settings', $vals['url'] === '' ? 'Code runner address removed' : 'Code runner set to ' . $vals['url']);
            Session::flash('runner_msg', ['success', 'Saved', $vals['url'] === '' ? 'Only Python, JavaScript, HTML and CSS can be run now.' : 'Lecture code in the languages below now has a Run button.']);
            redirect(APP_URL . '/pages/settings/code_runner.php');
        }
        $task_result = ['error', 'Not saved', 'Fix the highlighted field.'];
    } elseif ($action === 'test') {
        if (codeRunnerUrl() === '') $task_result = ['error', 'No runner yet', 'Enter the runner\'s address and save first.'];
        else {
            $r = codeRunRemote('python', "print('runner ok')");
            $task_result = $r['ok'] && strpos($r['stdout'], 'runner ok') !== false ? ['success', 'The runner works', 'It ran a small program and answered correctly.']
                : ['error', 'The runner did not answer correctly', $r['ok'] ? 'It answered, but not with the expected text. Is Python installed on the runner?' : $r['error']];
        }
    }
}

$page_title = 'Code runner';
$page_subtitle = 'Which languages the Run button of a lecture can run';
$active_menu = 'settings';
$settings_page = true;
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';
include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<section class="set-section">
    <header><h3>Runs in the browser — always on</h3><p>Python, JavaScript, HTML and CSS run inside the reader's own browser. HTML and CSS show a live page; nothing is sent anywhere.</p></header>
</section>
<section class="set-section">
    <header><h3>Other languages — needs a code runner</h3><p>C, C++, C#, Java, PHP, Go, Rust, Ruby, Kotlin, Swift, Bash, SQL, R, Lua, Perl, TypeScript. A web page cannot run these, so the code is sent from this server to a runner you set up (Piston — one Docker command) only when someone presses Run, with limits on size, time, output and how often.</p></header>
    <div class="body">
        <form method="POST" novalidate>
            <?php echo csrfField(); ?><input type="hidden" name="action" value="save">
            <div class="form-group">
                <label for="url">Runner address</label>
                <input type="url" id="url" name="url" maxlength="200" value="<?php echo htmlspecialchars($vals['url']); ?>" placeholder="http://localhost:2000" class="<?php echo $errors['url'] ? 'is-invalid' : ''; ?>">
                <div class="invalid-feedback <?php echo $errors['url'] ? 'show' : ''; ?>"><?php echo htmlspecialchars($errors['url']); ?></div>
                <small class="form-hint">Leave empty to run only Python, JavaScript, HTML and CSS. The runner should be on your own server or network.</small>
            </div>
            <div class="form-group">
                <label for="token">Access token <span class="form-hint" style="display:inline;margin:0;">optional — only if your runner asks for one</span></label>
                <input type="password" id="token" name="token" autocomplete="off" placeholder="<?php echo $has_token ? '•••••••• (saved — type to replace)' : ''; ?>">
                <?php if ($has_token): ?><label class="chk" style="margin-top:6px;"><input type="checkbox" name="clear_token" value="1"> Remove the saved token</label><?php endif; ?>
            </div>
            <div class="form-group"><label class="switch-row"><input type="checkbox" name="on" value="1" <?php echo $vals['on'] ? 'checked' : ''; ?>><span><strong>Runner switched on</strong><small class="form-hint" style="display:block;margin:2px 0 0;">Switch off to hide the Run button of these languages without losing the address.</small></span></label></div>
            <div class="form-actions"><button type="submit" class="btn-app"><i class="fas fa-save"></i> Save</button></div>
        </form>
        <form method="POST" style="margin-top:10px;"><?php echo csrfField(); ?><input type="hidden" name="action" value="test"><button type="submit" class="btn-app btn-outline btn-sm"><i class="fas fa-vial"></i> Test the runner</button></form>
    </div>
</section>
<section class="set-section">
    <header><h3>How to set one up</h3></header>
    <div class="body"><p class="form-hint" style="margin:0 0 8px;">On a server with Docker:</p>
        <pre style="white-space:pre-wrap;margin:0 0 10px;">docker run -d --privileged -v piston:/piston -p 2000:2000 --name piston ghcr.io/engineer-man/piston</pre>
        <p class="form-hint" style="margin:0;">Then install the languages you need (see the Piston documentation: <code>ppman install gcc java php go rust ...</code>), enter <code>http://localhost:2000</code> above and press "Test the runner".</p></div>
</section>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>

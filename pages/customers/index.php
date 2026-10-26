<?php
/**
 * Customers (original system, super admin only) — the installations you host.
 *   list     name, domain, status, services, teachers / students / classes, last activity, terms, disk (read from each
 *            customer's own database — counts only, never their content)
 *   POST create   a new customer (own database, clean schema, first admin; the temporary password is shown ONCE)
 *   POST suspend / resume   pause a customer (their admin can still sign in and download a backup)
 *   POST services           switch AI / email / uploads / sign-ups off or on for a customer (kept in YOUR database)
 *   POST support            a one-time link that signs you in to the customer's admin side for a limited time (logged on both sides)
 *   POST delete             final backup to instances/_archive/, then remove (type the domain to confirm)
 * Every action is CSRF-checked and written to customer_events.
 */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();
if (!SC_IS_MASTER) redirect(APP_URL . '/pages/dashboard.php');
require_once dirname(__DIR__, 2) . '/includes/customers.php';

$root = customerRootPdo();
$self = APP_URL . '/pages/customers/index.php';
$task_result = Session::flash('cust_msg');
$created = Session::flash('cust_created');   // [name, domain, email, password] — shown once
$support = Session::flash('cust_support');   // [name, link, reason]
$errors = ['name' => '', 'domain' => '', 'admin_email' => ''];
$form = ['name' => '', 'domain' => '', 'admin_email' => '', 'admin_name' => ''];

$find = function ($id) use ($root) { $q = $root->prepare("SELECT * FROM customers WHERE id = ?"); $q->execute([(int) $id]); return $q->fetch() ?: null; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $row = $find($_POST['customer_id'] ?? 0);
    if (!verifyCsrf()) {
        $task_result = ['error', 'Session expired', 'Please reload the page and try again.'];
    } elseif ($action === 'create') {
        foreach ($form as $k => $_) $form[$k] = trim((string) ($_POST[$k] ?? ''));
        if ($form['name'] === '') $errors['name'] = 'Give the customer a name';
        if (!preg_match('/^[a-z0-9]([a-z0-9.-]{0,180}[a-z0-9])?$/i', $form['domain'])) $errors['domain'] = 'Enter the domain, e.g. school.example.com';
        if (!filter_var($form['admin_email'], FILTER_VALIDATE_EMAIL)) $errors['admin_email'] = 'Enter the admin\'s email address';
        if (!array_filter($errors)) {
            try {
                $r = customerCreate($form + ['admin_email' => $form['admin_email'], 'by' => $current_user_id]);
                logActivity('customer_created', 'customers', "Created customer {$r['name']} ({$r['domain']})");
                Session::flash('cust_created', [$r['name'], $r['domain'], $r['admin_email'], $r['password']]);
                redirect($self);
            } catch (InvalidArgumentException $e) {
                $errors['domain'] = $e->getMessage();
            } catch (Throwable $e) {
                error_log('SmartClass: create customer failed: ' . $e->getMessage());
                $task_result = ['error', 'Not created', 'Something went wrong and nothing was created: ' . $e->getMessage()];
            }
        }
        if (!$task_result) $task_result = ['error', 'Not created', 'Fix the highlighted fields.'];
    } elseif (!$row) {
        $task_result = ['error', 'Not found', 'That customer does not exist.'];
    } elseif ($action === 'suspend') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        customerSetStatus($root, $row, 'suspended', $reason !== '' ? $reason : 'Paused by the provider', $current_user_id);
        Session::flash('cust_msg', ['success', 'Paused', $row['name'] . ' is paused. Their admin can still sign in and download a backup. It takes up to a minute to reach their site.']);
        redirect($self);
    } elseif ($action === 'resume') {
        customerSetStatus($root, $row, 'active', '', $current_user_id);
        Session::flash('cust_msg', ['success', 'Resumed', $row['name'] . ' is active again.']);
        redirect($self);
    } elseif ($action === 'services') {
        customerSetServices($root, $row, array_map('strval', (array) ($_POST['off'] ?? [])), $current_user_id);
        Session::flash('cust_msg', ['success', 'Saved', 'The service switches of ' . $row['name'] . ' were updated.']);
        redirect($self);
    } elseif ($action === 'billing') {
        customerSetBilling($root, $row, $_POST, $current_user_id);
        Session::flash('cust_msg', ['success', 'Saved', 'The billing notes of ' . $row['name'] . ' were saved.']);
        redirect($self);
    } elseif ($action === 'limits') {
        customerSetLimits($root, $row, (array) ($_POST['limit'] ?? []), $current_user_id);
        Session::flash('cust_msg', ['success', 'Saved', 'The limits of ' . $row['name'] . ' were updated (empty = no limit).']);
        redirect($self);
    } elseif ($action === 'support') {
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '') {
            $task_result = ['error', 'Reason needed', 'Write why you need to open their admin side — it is recorded for them and for you.'];
        } else {
            $link = customerSupportLink($row, $reason, !empty($_SERVER['HTTPS']));
            customerLog($root, $row['id'], $row['name'], 'support_link', $reason, $current_user_id);
            logActivity('customer_support_link', 'customers', "Support link for {$row['name']}: {$reason}");
            Session::flash('cust_support', [$row['name'], $link, $reason]);
            redirect($self);
        }
    } elseif ($action === 'delete') {
        if (trim((string) ($_POST['confirm'] ?? '')) !== $row['domain']) {
            $task_result = ['error', 'Not deleted', 'Type the customer\'s domain exactly to confirm.'];
        } else {
            try {
                $dump = customerDelete($row['domain'], $current_user_id);
                logActivity('customer_deleted', 'customers', "Deleted customer {$row['name']} ({$row['domain']})");
                Session::flash('cust_msg', ['success', 'Deleted', $row['name'] . ' was removed. The final backup is in instances/_archive/' . basename($dump) . '.']);
                redirect($self);
            } catch (Throwable $e) {
                $task_result = ['error', 'Not deleted', $e->getMessage()];
            }
        }
    }
}

$customers = $root->query("SELECT * FROM customers ORDER BY name")->fetchAll();
$stats = [];
foreach ($customers as $c) { $cfg = instanceConfig($c['domain']); $stats[$c['id']] = $cfg ? customerStats($cfg) : ['ok' => false, 'error' => 'Config file missing', 'disk' => 0]; }
$events = $root->query("SELECT * FROM customer_events ORDER BY id DESC LIMIT 25")->fetchAll();

$page_title = 'Customers';
$page_subtitle = 'The installations you host';
$active_menu = 'customers';
$page_scripts = ['assets/js/class.js', 'assets/js/customers.js'];
$page_script = $task_result ? "
    Swal.fire({ icon: " . json_encode($task_result[0]) . ", titleText: " . json_encode($task_result[1]) . ", text: " . json_encode($task_result[2]) . ", confirmButtonColor: '#4361ee' });" : '';

include dirname(__DIR__, 2) . '/includes/app_header.php';
?>
<?php $prov_cfg = is_file(dirname(__DIR__, 2) . '/config/provider.php') ? (include dirname(__DIR__, 2) . '/config/provider.php') : [];
      if (empty($prov_cfg['master_hosts'])): ?>
<div class="alert-box warn" style="margin-bottom:16px;"><i class="fas fa-shield-alt"></i> <span><strong>Any domain that reaches this server and is not a customer opens your own system.</strong>
    To restrict it, add your own domain(s) to <code>config/provider.php</code>, e.g. <code>'master_hosts' =&gt; ['yourdomain.com', 'localhost']</code> — every other unknown domain then gets "Unknown site".</span></div>
<?php endif; ?>
<?php if ($created): ?>
<div class="content-card" style="border-color:#06d6a0;">
    <div class="card-head"><h2><i class="fas fa-check-circle" style="color:#06d6a0;"></i> <?php echo htmlspecialchars($created[0]); ?> is ready</h2></div>
    <div class="card-inner">
        <p>Give the customer's admin these details — <strong>the password is shown only now</strong>. They must accept the terms and choose their own password at the first sign-in.</p>
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Address</div><div class="value"><?php echo htmlspecialchars($created[1]); ?></div></div>
            <div class="detail-item"><div class="label">Admin email</div><div class="value"><?php echo htmlspecialchars($created[2]); ?></div></div>
            <div class="detail-item"><div class="label">Temporary password</div><div class="value"><code><?php echo htmlspecialchars($created[3]); ?></code></div></div>
        </div>
        <p class="form-hint">The domain must point at this server and its web-server entry must be set up — see the steps printed by <code>bin/create_customer.php</code> or in docs/CUSTOMERS.md.</p>
    </div>
</div>
<?php endif; ?>
<?php if ($support): ?>
<div class="content-card" style="border-color:#4361ee;">
    <div class="card-head"><h2><i class="fas fa-life-ring"></i> Support link for <?php echo htmlspecialchars($support[0]); ?></h2></div>
    <div class="card-inner">
        <p>Open this link <strong>within <?php echo SUPPORT_LINK_MINUTES; ?> minutes</strong>. It works once, signs you in to their admin side for <?php echo SUPPORT_SESSION_MINUTES; ?> minutes, and is written to their activity log and to yours. Reason: <em><?php echo htmlspecialchars($support[2]); ?></em></p>
        <input type="text" readonly value="<?php echo htmlspecialchars($support[1]); ?>" aria-label="Support link" onclick="this.select()" style="width:100%;">
        <p style="margin-top:10px;"><a class="btn-app btn-sm" href="<?php echo htmlspecialchars($support[1]); ?>" target="_blank" rel="noopener noreferrer" data-no-ajax><i class="fas fa-external-link-alt"></i> Open now</a></p>
    </div>
</div>
<?php endif; ?>

<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-plus-circle"></i> New customer</h2></div>
    <div class="card-inner">
        <form method="POST" id="newCustomer" novalidate>
            <?php echo csrfField(); ?><input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-group"><label for="c_name">Customer name <span class="required-mark">*</span></label>
                    <input type="text" id="c_name" name="name" maxlength="120" value="<?php echo htmlspecialchars($form['name']); ?>" class="<?php echo $errors['name'] ? 'is-invalid' : ''; ?>"><div class="invalid-feedback"><?php echo htmlspecialchars($errors['name']); ?></div></div>
                <div class="form-group"><label for="c_domain">Domain <span class="required-mark">*</span></label>
                    <input type="text" id="c_domain" name="domain" placeholder="school.example.com" value="<?php echo htmlspecialchars($form['domain']); ?>" class="<?php echo $errors['domain'] ? 'is-invalid' : ''; ?>"><div class="invalid-feedback"><?php echo htmlspecialchars($errors['domain']); ?></div></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label for="c_email">Their admin's email <span class="required-mark">*</span></label>
                    <input type="email" id="c_email" name="admin_email" value="<?php echo htmlspecialchars($form['admin_email']); ?>" class="<?php echo $errors['admin_email'] ? 'is-invalid' : ''; ?>"><div class="invalid-feedback"><?php echo htmlspecialchars($errors['admin_email']); ?></div></div>
                <div class="form-group"><label for="c_aname">Their admin's name <span class="form-hint" style="display:inline;margin:0;">optional</span></label>
                    <input type="text" id="c_aname" name="admin_name" maxlength="100" value="<?php echo htmlspecialchars($form['admin_name']); ?>"></div>
            </div>
            <button type="submit" class="btn-app"><i class="fas fa-server"></i> Create customer</button>
        </form>
    </div>
</div>

<?php if (!$customers): ?>
<div class="empty-state"><i class="fas fa-building"></i><h3>No customers yet</h3><p style="margin-top:6px;">Create the first one above.</p></div>
<?php endif; ?>
<?php foreach ($customers as $c): $st = $stats[$c['id']]; $off = array_filter(explode(',', $c['services_off'])); $susp = $c['status'] === 'suspended'; ?>
<div class="content-card customer-card" data-domain="<?php echo htmlspecialchars($c['domain']); ?>">
    <div class="card-head">
        <h2><i class="fas fa-building"></i> <?php echo htmlspecialchars($c['name']); ?> <span class="form-hint" style="display:inline;margin:0;">· <?php echo htmlspecialchars($c['domain']); ?></span></h2>
        <div class="action-buttons">
            <span class="status-badge <?php echo $susp ? 'missing' : 'graded'; ?>"><?php echo $susp ? 'Paused' : 'Active'; ?></span>
            <?php foreach ($off as $s): ?><span class="status-badge late"><?php echo htmlspecialchars(SC_SERVICES[$s] ?? $s); ?> off</span><?php endforeach; ?>
            <?php $paid = customerPaidState($c['paid_until'] ?? null); if ($paid !== 'none'): ?><span class="status-badge <?php echo $paid === 'overdue' ? 'missing' : ($paid === 'soon' ? 'late' : 'graded'); ?>"><?php echo $paid === 'overdue' ? 'Payment overdue' : 'Paid until ' . formatDate($c['paid_until'], 'M d, Y'); ?></span><?php endif; ?>
        </div>
    </div>
    <div class="card-inner">
        <?php if (!$st['ok']): ?><p class="form-hint" style="color:#e5484d;">Cannot read this customer's database: <?php echo htmlspecialchars((string) $st['error']); ?></p><?php endif; ?>
        <div class="detail-grid">
            <div class="detail-item"><div class="label">Teachers</div><div class="value"><?php echo $st['teachers'] ?? '—'; ?></div></div>
            <div class="detail-item"><div class="label">Students</div><div class="value"><?php echo $st['students'] ?? '—'; ?></div></div>
            <div class="detail-item"><div class="label">Classes</div><div class="value"><?php echo $st['classes'] ?? '—'; ?></div></div>
            <div class="detail-item"><div class="label">Last activity</div><div class="value"><?php echo !empty($st['last_activity']) ? timeAgo($st['last_activity']) : '—'; ?></div></div>
            <div class="detail-item"><div class="label">Last seen by provider</div><div class="value"><?php echo $c['last_seen_at'] ? timeAgo($c['last_seen_at']) : '—'; ?></div></div>
            <div class="detail-item"><div class="label">Terms accepted</div><div class="value"><?php echo !empty($st['terms_at']) ? formatDate($st['terms_at'], 'M d, Y') : 'Not yet'; ?></div></div>
            <div class="detail-item"><div class="label">Disk</div><div class="value"><?php echo formatBytes($st['disk']); ?></div></div>
        </div>
        <?php if ($susp && $c['suspended_reason']): ?><p class="form-hint">Paused <?php echo timeAgo($c['suspended_at']); ?> — <?php echo htmlspecialchars($c['suspended_reason']); ?></p><?php endif; ?>

        <form method="POST" class="cust-limits" style="margin-bottom:14px;">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="limits"><input type="hidden" name="customer_id" value="<?php echo (int) $c['id']; ?>">
            <strong style="font-size:.85rem;">Limits <span class="form-hint" style="display:inline;margin:0;">empty = no limit</span></strong>
            <div class="form-row" style="margin:6px 0 8px;">
                <?php foreach (['teachers' => 'Teachers', 'students' => 'Students', 'classes' => 'Classes'] as $k => $label): ?>
                <div class="form-group" style="margin:0;"><label for="lim_<?php echo $k . $c['id']; ?>" style="font-size:.8rem;"><?php echo $label; ?> (now <?php echo $st[$k] ?? '—'; ?>)</label>
                    <input type="number" min="1" max="100000" id="lim_<?php echo $k . $c['id']; ?>" name="limit[<?php echo $k; ?>]" value="<?php echo $c['max_' . $k] !== null ? (int) $c['max_' . $k] : ''; ?>"></div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-save"></i> Save limits</button>
        </form>

        <form method="POST" class="cust-billing" style="margin-bottom:14px;">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="billing"><input type="hidden" name="customer_id" value="<?php echo (int) $c['id']; ?>">
            <strong style="font-size:.85rem;">Billing notes <span class="form-hint" style="display:inline;margin:0;">only a record — nothing is charged here</span></strong>
            <div class="form-row" style="margin:6px 0 8px;">
                <div class="form-group" style="margin:0;"><label for="bp<?php echo (int) $c['id']; ?>" style="font-size:.8rem;">Plan</label><input type="text" id="bp<?php echo (int) $c['id']; ?>" name="plan" maxlength="60" value="<?php echo htmlspecialchars((string) ($c['plan'] ?? '')); ?>" placeholder="e.g. Standard"></div>
                <div class="form-group" style="margin:0;"><label for="bpr<?php echo (int) $c['id']; ?>" style="font-size:.8rem;">Price</label><input type="text" id="bpr<?php echo (int) $c['id']; ?>" name="price" maxlength="40" value="<?php echo htmlspecialchars((string) ($c['price'] ?? '')); ?>" placeholder="e.g. 50 USD / month"></div>
                <div class="form-group" style="margin:0;"><label for="bu<?php echo (int) $c['id']; ?>" style="font-size:.8rem;">Paid until</label><input type="date" id="bu<?php echo (int) $c['id']; ?>" name="paid_until" value="<?php echo htmlspecialchars((string) ($c['paid_until'] ?? '')); ?>"></div>
            </div>
            <div class="form-group" style="margin:0 0 8px;"><label for="bn<?php echo (int) $c['id']; ?>" style="font-size:.8rem;">Note</label><input type="text" id="bn<?php echo (int) $c['id']; ?>" name="billing_note" maxlength="500" value="<?php echo htmlspecialchars((string) ($c['billing_note'] ?? '')); ?>" placeholder="e.g. invoice sent, pays by bank transfer"></div>
            <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-save"></i> Save billing notes</button>
        </form>

        <form method="POST" class="cust-services">
            <?php echo csrfField(); ?><input type="hidden" name="action" value="services"><input type="hidden" name="customer_id" value="<?php echo (int) $c['id']; ?>">
            <strong style="font-size:.85rem;">Services switched OFF for this customer</strong>
            <div class="ann-classes" style="margin:6px 0 8px;">
                <?php foreach (SC_SERVICES as $k => $label): ?>
                <label class="material-check"><input type="checkbox" name="off[]" value="<?php echo $k; ?>" <?php echo in_array($k, $off, true) ? 'checked' : ''; ?>><span><?php echo $label; ?></span></label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn-app btn-sm btn-outline"><i class="fas fa-save"></i> Save switches</button>
        </form>

        <div class="action-buttons" style="margin-top:14px;">
            <?php if ($susp): ?>
            <form method="POST"><?php echo csrfField(); ?><input type="hidden" name="action" value="resume"><input type="hidden" name="customer_id" value="<?php echo (int) $c['id']; ?>">
                <button type="submit" class="btn-app btn-sm"><i class="fas fa-play"></i> Resume</button></form>
            <?php else: ?>
            <button type="button" class="btn-app btn-sm btn-outline cust-act" data-act="suspend" data-id="<?php echo (int) $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['name']); ?>"><i class="fas fa-pause"></i> Pause</button>
            <?php endif; ?>
            <button type="button" class="btn-app btn-sm btn-outline cust-act" data-act="support" data-id="<?php echo (int) $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['name']); ?>"><i class="fas fa-life-ring"></i> Support access</button>
            <button type="button" class="btn-app btn-sm btn-danger cust-act" data-act="delete" data-id="<?php echo (int) $c['id']; ?>" data-name="<?php echo htmlspecialchars($c['name']); ?>" data-domain="<?php echo htmlspecialchars($c['domain']); ?>"><i class="fas fa-trash"></i> Delete</button>
        </div>
    </div>
</div>
<?php endforeach; ?>

<form method="POST" id="custActionForm" hidden>
    <?php echo csrfField(); ?><input type="hidden" name="action"><input type="hidden" name="customer_id"><input type="hidden" name="reason"><input type="hidden" name="confirm">
</form>

<?php if ($events): ?>
<div class="content-card">
    <div class="card-head"><h2><i class="fas fa-history"></i> What happened</h2></div>
    <div class="table-wrap"><table class="app-table"><thead><tr><th>When</th><th>Customer</th><th>Event</th><th>Details</th></tr></thead><tbody>
        <?php foreach ($events as $e): ?>
        <tr><td><?php echo formatDate($e['created_at'], 'M d, h:i A'); ?></td><td><?php echo htmlspecialchars((string) $e['customer_name']); ?></td><td><span class="status-badge submitted"><?php echo htmlspecialchars($e['event']); ?></span></td><td><?php echo htmlspecialchars((string) $e['details']); ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
</div>
<?php endif; ?>
<?php include dirname(__DIR__, 2) . '/includes/app_footer.php'; ?>

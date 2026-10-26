<?php
require_once dirname(__DIR__) . '/includes/customers.php';

test('customers: billing notes are kept as a record, cleaned, and the paid-until state is worked out', function () {
    $pdo = TW('pdo'); $db = TW('db');
    $id = (int) $db->insert('customers', ['name' => 'Bill Test', 'domain' => 'bill-' . bin2hex(random_bytes(3)) . '.test', 'slug' => 'bill' . bin2hex(random_bytes(3)), 'db_name' => 'x', 'db_user' => 'x', 'support_secret' => str_repeat('a', 64)]);
    $row = $db->fetchOne('SELECT * FROM customers WHERE id = ?', [$id]);
    $r = customerSetBilling($pdo, $row, ['plan' => '  Standard ', 'price' => '50 USD / month', 'paid_until' => '2027-03-31', 'billing_note' => 'bank transfer'], 1);
    eq('Standard', $r['plan']); $saved = $db->fetchOne('SELECT plan, price, paid_until, billing_note FROM customers WHERE id = ?', [$id]);
    eq('2027-03-31', $saved['paid_until']); eq('50 USD / month', $saved['price']); eq('bank transfer', $saved['billing_note']);
    $r = customerSetBilling($pdo, $row, ['plan' => '', 'paid_until' => '31/03/2027', 'billing_note' => str_repeat('x', 900)], 1);
    eq(null, $db->fetchOne('SELECT paid_until FROM customers WHERE id = ?', [$id])['paid_until'], 'a date that is not YYYY-MM-DD is ignored');
    eq(500, mb_strlen($db->fetchOne('SELECT billing_note FROM customers WHERE id = ?', [$id])['billing_note']), 'the note is cut at 500');
    ok($db->fetchOne("SELECT id FROM customer_events WHERE customer_id = ? AND event = 'billing'", [$id]) !== null, 'written to the customer log');
    eq('none', customerPaidState(null)); eq('overdue', customerPaidState('2026-01-01', '2026-02-01')); eq('soon', customerPaidState('2026-02-10', '2026-02-01')); eq('ok', customerPaidState('2026-06-01', '2026-02-01'));
    $db->query('DELETE FROM customers WHERE id = ?', [$id]); $db->query('DELETE FROM customer_events WHERE customer_id = ?', [$id]);
});

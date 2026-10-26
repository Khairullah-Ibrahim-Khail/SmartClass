-- SmartClass migration 051 — billing notes per customer (written by the provider on the Customers page; nothing is charged by the system)
-- Run ONCE (bin/update_all.php / bin/migrate.php do it).

ALTER TABLE customers
    ADD COLUMN plan VARCHAR(60) DEFAULT NULL AFTER notes,
    ADD COLUMN price VARCHAR(40) DEFAULT NULL AFTER plan,
    ADD COLUMN paid_until DATE DEFAULT NULL AFTER price,
    ADD COLUMN billing_note VARCHAR(500) DEFAULT NULL AFTER paid_until;

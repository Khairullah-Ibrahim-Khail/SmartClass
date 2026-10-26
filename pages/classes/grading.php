<?php
/** The class grading scale moved into Settings › Grading scale. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
redirect(APP_URL . '/pages/settings/grading_class.php?class_id=' . (int) ($_GET['id'] ?? 0));

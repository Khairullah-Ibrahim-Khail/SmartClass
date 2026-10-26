<?php
/** Settings — opens the first page of the Settings area (Profile); every setting has its own page in the navigation. */
require_once dirname(__DIR__, 2) . '/includes/auth.php';
redirect(APP_URL . '/pages/settings/profile.php');

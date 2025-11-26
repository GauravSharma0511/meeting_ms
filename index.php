<?php
// mms/index.php

// index.php is in /mms, so lib is /mms/lib
require_once __DIR__ . '/lib/auth.php';

if (isLoggedIn()) {
    // redirect inside app root (mms)
    header('Location: /mms/admin/dashboard.php');   // http://localhost/mms/admin/dashboard.php
} else {
    header('Location: /mms/auth/login.php');        // http://localhost/mms/auth/login.php
}
exit;

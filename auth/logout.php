<?php
// src/public/auth/logout.php
require_once(__DIR__ . '/../lib/auth.php');

logout();
header('Location: /mms/auth/login.php');
exit;

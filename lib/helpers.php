<?php
// src/lib/helpers.php
if (session_status() === PHP_SESSION_NONE) session_start();
function csrf_token() { if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(16)); return $_SESSION['csrf_token']; }
function verify_csrf($t){ return !empty($t) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'],$t); }
function flash_set($k,$m){ $_SESSION['flash'][$k]=$m; }
function flash_get($k){ $v=$_SESSION['flash'][$k]??null; unset($_SESSION['flash'][$k]); return $v; }

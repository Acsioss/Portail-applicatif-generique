<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/helpers.php';
require_once __DIR__ . '/core/auth.php';
logoutUser();
header('Location: /login.php');
exit;

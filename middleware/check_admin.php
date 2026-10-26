<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// chưa đăng nhập
if (!isset($_SESSION['user'])) {
    header("Location: ../auth/login.php");
    exit;
}

if ($_SESSION['user']['vai_tro'] !== 'admin') {
    header("Location: ../user/index.php");
    exit;
}

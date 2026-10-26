<?php
session_start();

// if (!isset($_SESSION['user'])) {
//     header("Location: auth/login.php");
//     exit;
// }

if ($_SESSION['user']['vai_tro'] === 'admin') {
    header("Location: admin/index.php");
    exit;
}

if ($_SESSION['user']['vai_tro'] === 'user') {
    header("Location: user/index.php");
    exit;
}

// header("Location: auth/login.php");
header("Location: user/index.php");
exit;

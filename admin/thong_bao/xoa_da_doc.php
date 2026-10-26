<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /iPhoneStore/admin/thong_bao/index.php");
    exit;
}

$userId =
    (int)($_SESSION['user']['id'] ?? 0);

$csrfToken =
    (string)($_POST['csrf_token'] ?? '');

if (
    $userId <= 0 ||
    empty($_SESSION['csrf_notification']) ||
    $csrfToken === '' ||
    !hash_equals(
        (string)$_SESSION['csrf_notification'],
        $csrfToken
    )
) {
    header("Location: /iPhoneStore/admin/thong_bao/index.php");
    exit;
}

$stmt = $conn->prepare("
    DELETE FROM thong_bao
    WHERE nguoi_dung_id = ?
    AND da_doc = 1
");

if ($stmt) {
    $stmt->bind_param(
        "i",
        $userId
    );

    $stmt->execute();
}

$_SESSION['csrf_notification'] =
    bin2hex(random_bytes(32));

header("Location: /iPhoneStore/admin/thong_bao/index.php");
exit;

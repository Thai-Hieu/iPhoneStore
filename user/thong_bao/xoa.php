<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /iPhoneStore/user/thong_bao/index.php");
    exit;
}

$userId =
    (int)($_SESSION['user']['id'] ?? 0);

$notificationId =
    (int)($_POST['id'] ?? 0);

$csrfToken =
    (string)($_POST['csrf_token'] ?? '');

$returnTo =
    trim(
        (string)($_POST['return_to'] ?? '')
    );

if (
    $userId <= 0 ||
    $notificationId <= 0 ||
    empty($_SESSION['csrf_notification']) ||
    $csrfToken === '' ||
    !hash_equals(
        (string)$_SESSION['csrf_notification'],
        $csrfToken
    )
) {
    header("Location: /iPhoneStore/user/thong_bao/index.php");
    exit;
}

$stmt = $conn->prepare("
    DELETE FROM thong_bao
    WHERE id = ?
    AND nguoi_dung_id = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        "ii",
        $notificationId,
        $userId
    );

    $stmt->execute();
}

/*
    Chỉ cho quay lại URL nội bộ của project.
*/
if (
    $returnTo !== '' &&
    str_starts_with(
        $returnTo,
        '/iPhoneStore/'
    ) &&
    !str_contains($returnTo, "\r") &&
    !str_contains($returnTo, "\n")
) {
    header(
        "Location: " . $returnTo
    );
    exit;
}

header("Location: /iPhoneStore/user/thong_bao/index.php");
exit;

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once __DIR__ . "/ham_ho_tro.php";

$id =
    (int)($_POST['id'] ?? 0);

$status =
    (int)($_POST['trang_thai'] ?? -1);

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    $id <= 0 ||
    !in_array(
        $status,
        [0, 1],
        true
    )
) {
    header(
        'Location: /iPhoneStore/admin/danh_gia/index.php'
    );
    exit;
}

$meta = adgMeta($conn);

$idColumn =
    $meta['id'];

$statusColumn =
    $meta['status'];

if (
    $idColumn === null ||
    $statusColumn === null
) {
    header(
        'Location: /iPhoneStore/admin/danh_gia/index.php'
    );
    exit;
}

$stmt = $conn->prepare("
    UPDATE danh_gia
    SET `$statusColumn` = ?
    WHERE `$idColumn` = ?
    LIMIT 1
");

if ($stmt) {
    $stmt->bind_param(
        "ii",
        $status,
        $id
    );

    $stmt->execute();
}

header(
    'Location: /iPhoneStore/admin/danh_gia/index.php'
);
exit;

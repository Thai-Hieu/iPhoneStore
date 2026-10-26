<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../config/database.php';

function out(bool $ok, string $msg, int $status = 200): never {
    http_response_code($status);
    echo json_encode(
        $ok ? ['success' => true, 'message' => $msg] : ['success' => false, 'error' => $msg],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(false, 'Phương thức không hợp lệ.', 405);
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) {
    out(false, 'Bạn chưa đăng nhập.', 401);
}

$csrf = (string)($_POST['csrf_token'] ?? '');
$sessionCsrf = (string)($_SESSION['notification_toast_csrf'] ?? '');

if ($csrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $csrf)) {
    out(false, 'Phiên thông báo không hợp lệ.', 403);
}

$id = (int)($_POST['notification_id'] ?? 0);
if ($id <= 0) {
    out(false, 'Thông báo không hợp lệ.', 422);
}

try {
    $stmt = $conn->prepare("UPDATE thong_bao SET da_doc = 1, ngay_doc = COALESCE(ngay_doc, NOW()) WHERE id = ? AND nguoi_dung_id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Không thể chuẩn bị cập nhật.');
    }

    $stmt->bind_param('ii', $id, $userId);
    $stmt->execute();
    $stmt->close();

    out(true, 'Đã đánh dấu thông báo là đã đọc.');
} catch (Throwable $e) {
    error_log('Toast mark read error: ' . $e->getMessage());
    out(false, 'Không thể cập nhật thông báo.', 500);
}
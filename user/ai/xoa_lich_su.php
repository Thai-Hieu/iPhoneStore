<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header(
    'Content-Type: application/json; charset=utf-8'
);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/ai_helper.php';

$csrf =
    (string)(
        $_POST['csrf_token']
        ?? ''
    );

$sessionCsrf =
    (string)(
        $_SESSION['ai_chat_csrf']
        ?? ''
    );

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    $csrf === '' ||
    $sessionCsrf === '' ||
    !hash_equals(
        $sessionCsrf,
        $csrf
    )
) {
    http_response_code(403);

    echo json_encode(
        [
            'success' => false,
            'error' =>
                'Phiên không hợp lệ.',
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

try {
    aiChatEnsureHistoryTable($conn);
    aiChatClearHistory($conn);

    echo json_encode(
        [
            'success' => true,
            'message' =>
                'Đã xóa lịch sử trò chuyện.',
        ],
        JSON_UNESCAPED_UNICODE
    );
} catch (Throwable $e) {
    error_log(
        'AI clear history: ' .
        $e->getMessage()
    );

    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'error' =>
                'Không thể xóa lịch sử.',
        ],
        JSON_UNESCAPED_UNICODE
    );
}

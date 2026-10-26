<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header(
    'Content-Type: application/json; charset=utf-8'
);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/ai_helper.php';

function aiDeleteJson(
    bool $success,
    string $message,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $success
            ? [
                'success' => true,
                'message' => $message,
            ]
            : [
                'success' => false,
                'error' => $message,
            ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aiDeleteJson(
        false,
        'Phương thức không hợp lệ.',
        405
    );
}

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
    $csrf === '' ||
    $sessionCsrf === '' ||
    !hash_equals(
        $sessionCsrf,
        $csrf
    )
) {
    aiDeleteJson(
        false,
        'Phiên trò chuyện không hợp lệ.',
        403
    );
}

$messageId =
    (int)(
        $_POST['message_id']
        ?? 0
    );

if ($messageId <= 0) {
    aiDeleteJson(
        false,
        'Tin nhắn không hợp lệ.',
        422
    );
}

try {
    aiChatEnsureHistoryTable($conn);

    if (
        !aiChatDeleteMessage(
            $conn,
            $messageId
        )
    ) {
        aiDeleteJson(
            false,
            'Không tìm thấy tin nhắn hoặc bạn không có quyền xóa.',
            404
        );
    }

    aiDeleteJson(
        true,
        'Đã xóa tin nhắn.'
    );
} catch (Throwable $e) {
    error_log(
        'AI delete message: ' .
        $e->getMessage()
    );

    aiDeleteJson(
        false,
        'Không thể xóa tin nhắn lúc này.',
        500
    );
}

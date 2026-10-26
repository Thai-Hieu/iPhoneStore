<?php

/*
    =========================================================
    LỊCH SỬ CHAT AI - BẢN PERSISTENT + PRODUCTS_JSON
    =========================================================

    Bảng ưu tiên: ai_chat_lich_su

    Helper tự dò các tên cột phổ biến:
    - user: nguoi_dung_id / user_id
    - role: vai_tro / role
    - content: noi_dung / content / message
    - time: ngay_tao / created_at
    - products: products_json

    Guest:
    - cookie riêng tồn tại 180 ngày
    - đóng trình duyệt/mở lại vẫn còn lịch sử

    Sau đăng nhập:
    - lịch sử guest trong cùng trình duyệt tự nhập vào user
*/

function aiChatTableExists(mysqli $conn, string $table): bool
{
    $safe = $conn->real_escape_string($table);

    $rs = $conn->query(
        "SHOW TABLES LIKE '{$safe}'"
    );

    return $rs && $rs->num_rows > 0;
}

function aiChatColumns(mysqli $conn, string $table): array
{
    $columns = [];

    $safeTable =
        str_replace('`', '``', $table);

    $rs = $conn->query(
        "SHOW COLUMNS FROM `{$safeTable}`"
    );

    if (!$rs) {
        return [];
    }

    while ($row = $rs->fetch_assoc()) {
        $name = (string)$row['Field'];
        $columns[$name] = $row;
    }

    return $columns;
}

function aiChatPickColumn(
    array $columns,
    array $candidates
): ?string {
    foreach ($candidates as $candidate) {
        if (isset($columns[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

function aiChatEnsureHistoryTable(mysqli $conn): void
{
    if (!aiChatTableExists($conn, 'ai_chat_lich_su')) {
        $sql = "
            CREATE TABLE ai_chat_lich_su (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                nguoi_dung_id INT NULL,
                session_key VARCHAR(128) NOT NULL,
                vai_tro VARCHAR(20) NOT NULL,
                noi_dung MEDIUMTEXT NOT NULL,
                products_json LONGTEXT NULL,
                ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                INDEX idx_ai_history_user (
                    nguoi_dung_id,
                    id
                ),
                INDEX idx_ai_history_session (
                    session_key,
                    id
                )
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci
        ";

        if (!$conn->query($sql)) {
            throw new RuntimeException(
                'Không thể tạo bảng ai_chat_lich_su: ' .
                $conn->error
            );
        }
    }

    $columns =
        aiChatColumns(
            $conn,
            'ai_chat_lich_su'
        );

    if (!isset($columns['products_json'])) {
        if (
            !$conn->query("
                ALTER TABLE ai_chat_lich_su
                ADD COLUMN products_json LONGTEXT NULL
            ")
        ) {
            throw new RuntimeException(
                'Không thể thêm cột products_json: ' .
                $conn->error
            );
        }

        $columns =
            aiChatColumns(
                $conn,
                'ai_chat_lich_su'
            );
    }

    /*
        session_key cần cho guest.
        Nếu bảng cũ chưa có thì tự bổ sung.
    */
    if (!isset($columns['session_key'])) {
        if (
            !$conn->query("
                ALTER TABLE ai_chat_lich_su
                ADD COLUMN session_key VARCHAR(128) NULL
            ")
        ) {
            throw new RuntimeException(
                'Không thể thêm cột session_key: ' .
                $conn->error
            );
        }
    }
}

function aiChatMeta(mysqli $conn): array
{
    aiChatEnsureHistoryTable($conn);

    $columns =
        aiChatColumns(
            $conn,
            'ai_chat_lich_su'
        );

    $meta = [
        'table' => 'ai_chat_lich_su',

        'id' =>
            aiChatPickColumn(
                $columns,
                ['id']
            ),

        'user' =>
            aiChatPickColumn(
                $columns,
                [
                    'nguoi_dung_id',
                    'user_id'
                ]
            ),

        'session' =>
            aiChatPickColumn(
                $columns,
                ['session_key']
            ),

        'role' =>
            aiChatPickColumn(
                $columns,
                [
                    'vai_tro',
                    'role'
                ]
            ),

        'content' =>
            aiChatPickColumn(
                $columns,
                [
                    'noi_dung',
                    'content',
                    'message'
                ]
            ),

        'products' =>
            aiChatPickColumn(
                $columns,
                ['products_json']
            ),

        'time' =>
            aiChatPickColumn(
                $columns,
                [
                    'ngay_tao',
                    'created_at'
                ]
            ),
    ];

    foreach (
        [
            'id',
            'user',
            'session',
            'role',
            'content',
            'products',
            'time',
        ]
        as $required
    ) {
        if ($meta[$required] === null) {
            throw new RuntimeException(
                'Bảng ai_chat_lich_su thiếu cột cần thiết: ' .
                $required
            );
        }
    }

    return $meta;
}

function aiChatGuestToken(): string
{
    $cookieName =
        'iphone_ai_guest_id';

    $current =
        trim(
            (string)(
                $_COOKIE[$cookieName]
                ?? ''
            )
        );

    if (
        preg_match(
            '/^[a-f0-9]{64}$/',
            $current
        )
    ) {
        return $current;
    }

    $token =
        bin2hex(
            random_bytes(32)
        );

    $_COOKIE[$cookieName] =
        $token;

    if (!headers_sent()) {
        setcookie(
            $cookieName,
            $token,
            [
                'expires' =>
                    time() +
                    (180 * 24 * 60 * 60),

                'path' =>
                    '/iPhoneStore/',

                'secure' =>
                    !empty($_SERVER['HTTPS']) &&
                    $_SERVER['HTTPS'] !== 'off',

                'httponly' =>
                    true,

                'samesite' =>
                    'Lax',
            ]
        );
    }

    return $token;
}

function aiChatSessionKey(): string
{
    return hash(
        'sha256',
        'iphone-store-ai-guest:' .
        aiChatGuestToken()
    );
}

function aiChatUserId(): ?int
{
    $id =
        (int)(
            $_SESSION['user']['id']
            ?? 0
        );

    return $id > 0
        ? $id
        : null;
}

function aiChatRole(): string
{
    return (string)(
        $_SESSION['user']['vai_tro']
        ?? 'guest'
    );
}

function aiChatMergeGuestHistoryToUser(
    mysqli $conn
): void {
    $userId =
        aiChatUserId();

    if ($userId === null) {
        return;
    }

    $meta =
        aiChatMeta($conn);

    $table =
        $meta['table'];

    $userCol =
        $meta['user'];

    $sessionCol =
        $meta['session'];

    $sessionKey =
        aiChatSessionKey();

    $sql = "
        UPDATE `{$table}`
        SET `{$userCol}` = ?
        WHERE
            `{$userCol}` IS NULL
            AND `{$sessionCol}` = ?
    ";

    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {
        return;
    }

    $stmt->bind_param(
        'is',
        $userId,
        $sessionKey
    );

    $stmt->execute();
    $stmt->close();
}

/*
    Trả về ID của tin vừa lưu.
*/
function aiChatSaveMessage(
    mysqli $conn,
    string $role,
    string $content,
    array $products = []
): int {
    $content =
        trim($content);

    if ($content === '') {
        return 0;
    }

    aiChatMergeGuestHistoryToUser($conn);

    $meta =
        aiChatMeta($conn);

    $table =
        $meta['table'];

    $userCol =
        $meta['user'];

    $sessionCol =
        $meta['session'];

    $roleCol =
        $meta['role'];

    $contentCol =
        $meta['content'];

    $productsCol =
        $meta['products'];

    $userId =
        aiChatUserId();

    $sessionKey =
        aiChatSessionKey();

    $productsJson =
        !empty($products)
            ? json_encode(
                array_values($products),
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
            : null;

    $sql = "
        INSERT INTO `{$table}` (
            `{$userCol}`,
            `{$sessionCol}`,
            `{$roleCol}`,
            `{$contentCol}`,
            `{$productsCol}`
        )
        VALUES (?, ?, ?, ?, ?)
    ";

    $stmt =
        $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Không thể chuẩn bị lưu lịch sử AI: ' .
            $conn->error
        );
    }

    $stmt->bind_param(
        'issss',
        $userId,
        $sessionKey,
        $role,
        $content,
        $productsJson
    );

    if (!$stmt->execute()) {
        $error =
            $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            'Không thể lưu lịch sử AI: ' .
            $error
        );
    }

    $id =
        (int)$conn->insert_id;

    $stmt->close();

    return $id;
}

function aiChatLoadHistory(
    mysqli $conn,
    int $limit = 50
): array {
    $limit =
        max(
            1,
            min(
                150,
                $limit
            )
        );

    aiChatMergeGuestHistoryToUser($conn);

    $meta =
        aiChatMeta($conn);

    $table =
        $meta['table'];

    $idCol =
        $meta['id'];

    $userCol =
        $meta['user'];

    $sessionCol =
        $meta['session'];

    $roleCol =
        $meta['role'];

    $contentCol =
        $meta['content'];

    $productsCol =
        $meta['products'];

    $timeCol =
        $meta['time'];

    $userId =
        aiChatUserId();

    $sessionKey =
        aiChatSessionKey();

    $select = "
        SELECT
            `{$idCol}` AS id,
            `{$roleCol}` AS vai_tro,
            `{$contentCol}` AS noi_dung,
            `{$productsCol}` AS products_json,
            `{$timeCol}` AS ngay_tao
        FROM `{$table}`
    ";

    if ($userId !== null) {
        $sql =
            $select .
            "
            WHERE `{$userCol}` = ?
            ORDER BY `{$idCol}` DESC
            LIMIT ?
            ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param(
            'ii',
            $userId,
            $limit
        );
    } else {
        $sql =
            $select .
            "
            WHERE
                `{$userCol}` IS NULL
                AND `{$sessionCol}` = ?
            ORDER BY `{$idCol}` DESC
            LIMIT ?
            ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param(
            'si',
            $sessionKey,
            $limit
        );
    }

    $stmt->execute();

    $rows =
        $stmt
            ->get_result()
            ->fetch_all(
                MYSQLI_ASSOC
            );

    $stmt->close();

    $rows =
        array_reverse($rows);

    foreach ($rows as &$row) {
        $products = [];

        $json =
            trim(
                (string)(
                    $row['products_json']
                    ?? ''
                )
            );

        if ($json !== '') {
            $decoded =
                json_decode(
                    $json,
                    true
                );

            if (is_array($decoded)) {
                $products =
                    array_values($decoded);
            }
        }

        $row['products'] =
            $products;
    }

    unset($row);

    return $rows;
}

function aiChatClearHistory(
    mysqli $conn
): void {
    aiChatMergeGuestHistoryToUser($conn);

    $meta =
        aiChatMeta($conn);

    $table =
        $meta['table'];

    $userCol =
        $meta['user'];

    $sessionCol =
        $meta['session'];

    $userId =
        aiChatUserId();

    $sessionKey =
        aiChatSessionKey();

    if ($userId !== null) {
        $sql = "
            DELETE FROM `{$table}`
            WHERE `{$userCol}` = ?
        ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {
            return;
        }

        $stmt->bind_param(
            'i',
            $userId
        );
    } else {
        $sql = "
            DELETE FROM `{$table}`
            WHERE
                `{$userCol}` IS NULL
                AND `{$sessionCol}` = ?
        ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {
            return;
        }

        $stmt->bind_param(
            's',
            $sessionKey
        );
    }

    $stmt->execute();
    $stmt->close();
}

function aiChatDeleteMessage(
    mysqli $conn,
    int $messageId
): bool {
    if ($messageId <= 0) {
        return false;
    }

    aiChatMergeGuestHistoryToUser($conn);

    $meta =
        aiChatMeta($conn);

    $table =
        $meta['table'];

    $idCol =
        $meta['id'];

    $userCol =
        $meta['user'];

    $sessionCol =
        $meta['session'];

    $userId =
        aiChatUserId();

    $sessionKey =
        aiChatSessionKey();

    if ($userId !== null) {
        $sql = "
            DELETE FROM `{$table}`
            WHERE
                `{$idCol}` = ?
                AND `{$userCol}` = ?
            LIMIT 1
        ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'ii',
            $messageId,
            $userId
        );
    } else {
        $sql = "
            DELETE FROM `{$table}`
            WHERE
                `{$idCol}` = ?
                AND `{$userCol}` IS NULL
                AND `{$sessionCol}` = ?
            LIMIT 1
        ";

        $stmt =
            $conn->prepare($sql);

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param(
            'is',
            $messageId,
            $sessionKey
        );
    }

    $stmt->execute();

    $deleted =
        $stmt->affected_rows > 0;

    $stmt->close();

    return $deleted;
}

function aiNormalizeText(string $text): string
{
    $text =
        mb_strtolower(
            trim($text),
            'UTF-8'
        );

    $text =
        strtr(
            $text,
            [
                'đ' => 'd',
                'Đ' => 'd',
            ]
        );

    if (
        class_exists(
            'Transliterator'
        )
    ) {
        $trans =
            Transliterator::create(
                'NFD; [:Nonspacing Mark:] Remove; NFC'
            );

        if ($trans) {
            $text =
                $trans->transliterate(
                    $text
                );
        }
    }

    $text =
        preg_replace(
            '/[^\p{L}\p{N}\s\.\-]+/u',
            ' ',
            $text
        );

    return trim(
        preg_replace(
            '/\s+/u',
            ' ',
            $text
        )
    );
}

function aiKnowledgeToText(array $data): string
{
    $lines = [];

    $walk =
        function (
            mixed $value,
            string $prefix = ''
        ) use (
            &$walk,
            &$lines
        ): void {
            if (is_array($value)) {
                foreach (
                    $value
                    as $key => $item
                ) {
                    $label =
                        is_int($key)
                            ? $prefix
                            : trim(
                                $prefix .
                                ' ' .
                                str_replace(
                                    '_',
                                    ' ',
                                    (string)$key
                                )
                            );

                    $walk(
                        $item,
                        $label
                    );
                }

                return;
            }

            $value =
                trim(
                    (string)$value
                );

            if ($value !== '') {
                $lines[] =
                    (
                        $prefix !== ''
                            ? $prefix . ': '
                            : ''
                    ) .
                    $value;
            }
        };

    $walk($data);

    return implode(
        "\n",
        $lines
    );
}

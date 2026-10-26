<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

$isCli = PHP_SAPI === 'cli';

$isAdmin =
    (string)(
        $_SESSION['user']['vai_tro']
        ?? ''
    ) === 'admin';

if (!$isCli && !$isAdmin) {
    http_response_code(403);
    exit(
        'Chỉ admin hoặc PHP CLI mới được cài nhật ký AI.'
    );
}

$conn->query("
    CREATE TABLE IF NOT EXISTS ai_audit_log (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        bang VARCHAR(100) NOT NULL,
        ban_ghi_id VARCHAR(100) NULL,
        hanh_dong ENUM('INSERT','UPDATE','DELETE') NOT NULL,
        du_lieu_cu JSON NULL,
        du_lieu_moi JSON NULL,
        ngay_tao DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        INDEX idx_ai_audit_table (bang, id),
        INDEX idx_ai_audit_time (ngay_tao)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");

$tables = [
    'san_pham',
    'san_pham_bien_the',
    'san_pham_thong_so',
    'khuyen_mai',
    'danh_muc',
    'mau_sac',
];

function auditJsonExpr(
    mysqli $conn,
    string $table,
    string $prefix
): string {
    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}`"
    );

    if (!$result) {
        return 'JSON_OBJECT()';
    }

    $parts = [];

    while ($col = $result->fetch_assoc()) {
        $name = (string)$col['Field'];

        $key =
            str_replace(
                "'",
                "''",
                $name
            );

        $parts[] = "'" . $key . "'";
        $parts[] =
            $prefix .
            '.`' .
            str_replace('`', '``', $name) .
            '`';
    }

    return
        'JSON_OBJECT(' .
        implode(', ', $parts) .
        ')';
}

$messages = [];

foreach ($tables as $table) {
    $exists = $conn->query(
        "SHOW TABLES LIKE '" .
        $conn->real_escape_string($table) .
        "'"
    );

    if (
        !$exists ||
        $exists->num_rows === 0
    ) {
        $messages[] =
            "Bỏ qua {$table}: bảng không tồn tại.";
        continue;
    }

    $hasId = $conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE 'id'"
    );

    $idNew =
        ($hasId && $hasId->num_rows > 0)
            ? 'CAST(NEW.`id` AS CHAR)'
            : 'NULL';

    $idOld =
        ($hasId && $hasId->num_rows > 0)
            ? 'CAST(OLD.`id` AS CHAR)'
            : 'NULL';

    $newJson =
        auditJsonExpr(
            $conn,
            $table,
            'NEW'
        );

    $oldJson =
        auditJsonExpr(
            $conn,
            $table,
            'OLD'
        );

    $safeTable =
        preg_replace(
            '/[^a-zA-Z0-9_]/',
            '_',
            $table
        );

    $triggerInsert =
        "ai_audit_{$safeTable}_ins";

    $triggerUpdate =
        "ai_audit_{$safeTable}_upd";

    $triggerDelete =
        "ai_audit_{$safeTable}_del";

    foreach (
        [
            $triggerInsert,
            $triggerUpdate,
            $triggerDelete,
        ]
        as $trigger
    ) {
        $conn->query(
            "DROP TRIGGER IF EXISTS `{$trigger}`"
        );
    }

    $sqlInsert = "
        CREATE TRIGGER `{$triggerInsert}`
        AFTER INSERT ON `{$table}`
        FOR EACH ROW
        INSERT INTO ai_audit_log (
            bang,
            ban_ghi_id,
            hanh_dong,
            du_lieu_cu,
            du_lieu_moi
        )
        VALUES (
            '{$table}',
            {$idNew},
            'INSERT',
            NULL,
            {$newJson}
        )
    ";

    $sqlUpdate = "
        CREATE TRIGGER `{$triggerUpdate}`
        AFTER UPDATE ON `{$table}`
        FOR EACH ROW
        INSERT INTO ai_audit_log (
            bang,
            ban_ghi_id,
            hanh_dong,
            du_lieu_cu,
            du_lieu_moi
        )
        VALUES (
            '{$table}',
            {$idNew},
            'UPDATE',
            {$oldJson},
            {$newJson}
        )
    ";

    $sqlDelete = "
        CREATE TRIGGER `{$triggerDelete}`
        AFTER DELETE ON `{$table}`
        FOR EACH ROW
        INSERT INTO ai_audit_log (
            bang,
            ban_ghi_id,
            hanh_dong,
            du_lieu_cu,
            du_lieu_moi
        )
        VALUES (
            '{$table}',
            {$idOld},
            'DELETE',
            {$oldJson},
            NULL
        )
    ";

    foreach (
        [
            'INSERT' => $sqlInsert,
            'UPDATE' => $sqlUpdate,
            'DELETE' => $sqlDelete,
        ]
        as $type => $sql
    ) {
        if (!$conn->query($sql)) {
            $messages[] =
                "{$table} {$type}: LỖI - " .
                $conn->error;
        } else {
            $messages[] =
                "{$table} {$type}: OK";
        }
    }
}

header('Content-Type: text/plain; charset=utf-8');

echo "CÀI NHẬT KÝ AI\n";
echo "====================\n";
echo implode("\n", $messages);
echo "\n\nHoàn tất.\n";

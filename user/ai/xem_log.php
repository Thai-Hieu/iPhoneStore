<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role =
    (string)(
        $_SESSION['user']['vai_tro']
        ?? ''
    );

if ($role !== 'admin') {
    http_response_code(403);

    exit(
        'Bạn không có quyền xem log AI.'
    );
}

require_once __DIR__ . '/ai_logger.php';

if (empty($_SESSION['ai_log_csrf'])) {
    $_SESSION['ai_log_csrf'] =
        bin2hex(
            random_bytes(32)
        );
}

$csrf =
    (string)$_SESSION['ai_log_csrf'];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['clear_log'])
) {
    $postedCsrf =
        (string)(
            $_POST['csrf_token']
            ?? ''
        );

    if (
        $postedCsrf !== '' &&
        hash_equals(
            $csrf,
            $postedCsrf
        )
    ) {
        $path =
            aiErrorLogPath();

        if (is_file($path)) {
            @file_put_contents(
                $path,
                '',
                LOCK_EX
            );
        }

        header(
            'Location: xem_log.php'
        );

        exit;
    }
}

$path =
    aiErrorLogPath();

$lines = [];

if (is_file($path)) {
    $all =
        @file(
            $path,
            FILE_IGNORE_NEW_LINES |
            FILE_SKIP_EMPTY_LINES
        );

    if (is_array($all)) {
        $lines =
            array_slice(
                array_reverse($all),
                0,
                200
            );
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/navbar.php';
?>

<style>
.ai-log-page {
    padding: 28px 12px 50px;
    background: #f5f6f8;
    min-height: 70vh;
}

.ai-log-box {
    width: min(1200px, 100%);
    margin: 0 auto;
    padding: 20px;
    border: 1px solid #e7e9ee;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 12px 35px rgba(15,23,42,.06);
}

.ai-log-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    margin-bottom: 15px;
}

.ai-log-title {
    margin: 0;
    font-size: 20px;
    font-weight: 900;
}

.ai-log-note {
    margin-top: 5px;
    color: #7d8490;
    font-size: 12px;
}

.ai-log-clear {
    padding: 9px 12px;
    border: 0;
    border-radius: 10px;
    background: #d70018;
    color: #fff;
    cursor: pointer;
}

.ai-log-table-wrap {
    overflow-x: auto;
}

.ai-log-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.ai-log-table th,
.ai-log-table td {
    padding: 10px;
    border-bottom: 1px solid #eceef2;
    text-align: left;
    vertical-align: top;
}

.ai-log-table th {
    background: #fafbfc;
    white-space: nowrap;
}

.ai-log-json {
    margin: 8px 0 0;
    padding: 10px;
    max-width: 620px;
    max-height: 220px;
    overflow: auto;
    border-radius: 9px;
    background: #f7f8fa;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: #4d5560;
    font-size: 10px;
}
.ai-log-badge { display:inline-block; padding:5px 8px; border-radius:999px; background:#fff5d8; color:#946200; font-weight:800; white-space:nowrap; }
.ai-log-wait { white-space:nowrap; font-weight:700; color:#59616d; }

.ai-log-empty {
    padding: 30px;
    text-align: center;
    color: #8c929c;
}
</style>

<div class="ai-log-page">
    <div class="ai-log-box">

        <div class="ai-log-head">
            <div>
                <h1 class="ai-log-title">
                    🧾 Log lỗi iPhoneStore AI
                </h1>

                <div class="ai-log-note">
                    Hiển thị tối đa 200 lỗi gần nhất. API key không được ghi vào log.
                </div>
            </div>

            <form
                method="post"
                onsubmit="return confirm('Xóa toàn bộ log AI?');"
            >
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"
                >

                <button
                    class="ai-log-clear"
                    type="submit"
                    name="clear_log"
                    value="1"
                >
                    Xóa log
                </button>
            </form>
        </div>

        <?php if (empty($lines)): ?>

            <div class="ai-log-empty">
                Chưa có lỗi AI nào được ghi nhận ✅
            </div>

        <?php else: ?>

            <div class="ai-log-table-wrap">
                <table class="ai-log-table">
                    <thead>
                        <tr>
                            <th>Thời gian</th>
                            <th>Loại lỗi</th>
                            <th>HTTP</th>
                            <th>API code</th>
                            <th>Chờ</th>
                            <th>Request ID</th>
                            <th>Chi tiết</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php foreach ($lines as $line): ?>
                            <?php
                            $row =
                                json_decode(
                                    $line,
                                    true
                                );

                            if (!is_array($row)) {
                                $row = [
                                    'time' => '',
                                    'type' => 'raw_log',
                                    'raw' => $line,
                                ];
                            }

                            $type = (string)($row['type'] ?? '');
                            $label = match ($type) {
                                'rate_limit_tpm' => 'Rate limit Token (TPM)',
                                'rate_limit_rpm' => 'Rate limit Request (RPM)',
                                'rate_limit' => 'Rate limit',
                                'rate_limit_unknown' => 'Giới hạn sử dụng',
                                'quota_or_billing_exhausted' => 'Hết quota / billing',
                                default => $type,
                            };

                            $waitHuman = trim((string)($row['retry_after_human'] ?? ''));
                            if ($waitHuman === '' && !empty($row['retry_after'])) {
                                $waitHuman = aiSecondsToHuman($row['retry_after']);
                            }
                            ?>

                            <tr>
                                <td>
                                    <?= htmlspecialchars(
                                        (string)($row['time'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <span class="ai-log-badge">
                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        (string)($row['http_status'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        (string)($row['api_code'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td class="ai-log-wait">
                                    <?= $waitHuman !== ''
                                        ? htmlspecialchars($waitHuman, ENT_QUOTES, 'UTF-8')
                                        : '—' ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars(
                                        (string)($row['request_id'] ?? ''),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </td>

                                <td>
                                    <?php if (!empty($row['api_message'])): ?>
                                        <div style="max-width:620px;line-height:1.55;">
                                            <?= nl2br(htmlspecialchars(
                                                (string)$row['api_message'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>

                                    <details>
                                        <summary style="cursor:pointer;margin-top:7px;">
                                            Xem dữ liệu kỹ thuật
                                        </summary>
                                        <pre class="ai-log-json"><?= htmlspecialchars(
                                            json_encode(
                                                $row,
                                                JSON_UNESCAPED_UNICODE |
                                                JSON_PRETTY_PRINT |
                                                JSON_UNESCAPED_SLASHES
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php endif; ?>

    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>

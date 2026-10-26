<?php

/*
    IphoneStore - EMAIL WORKER ONE-SHOT

    File này KHÔNG cần mở thủ công.
    notification_helper.php tự gọi worker cho từng job.

    Worker:
    - đọc 1 job JSON;
    - gửi SMTP;
    - retry tối đa 3 lần;
    - thành công: xóa job;
    - thất bại: đổi thành *.failed.json và ghi log.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/email_helper.php';

$jobPath =
    trim(
        (string)($argv[1] ?? '')
    );

if (
    $jobPath === '' ||
    !is_file($jobPath)
) {
    exit(1);
}

$projectRoot =
    dirname(__DIR__);

$queueDir =
    $projectRoot .
    DIRECTORY_SEPARATOR .
    'storage' .
    DIRECTORY_SEPARATOR .
    'email_queue';

$logDir =
    $projectRoot .
    DIRECTORY_SEPARATOR .
    'storage' .
    DIRECTORY_SEPARATOR .
    'logs';

if (
    !is_dir($queueDir) ||
    (
        !is_dir($logDir) &&
        !@mkdir(
            $logDir,
            0775,
            true
        ) &&
        !is_dir($logDir)
    )
) {
    exit(1);
}

$realQueueDir =
    realpath($queueDir);

$realJobPath =
    realpath($jobPath);

if (
    $realQueueDir === false ||
    $realJobPath === false ||
    !str_starts_with(
        $realJobPath,
        $realQueueDir .
        DIRECTORY_SEPARATOR
    )
) {
    exit(1);
}

$logFile =
    $logDir .
    DIRECTORY_SEPARATOR .
    'email_worker.log';

function ghiLogEmailWorker(
    string $logFile,
    string $message
): void {
    @file_put_contents(
        $logFile,
        '[' .
        date('Y-m-d H:i:s') .
        '] ' .
        $message .
        PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

/*
    Claim job để không có 2 worker xử lý cùng 1 email.
*/
$sendingPath =
    preg_replace(
        '/\.json$/i',
        '.sending.json',
        $realJobPath
    );

if (
    !is_string($sendingPath) ||
    $sendingPath === $realJobPath ||
    !@rename(
        $realJobPath,
        $sendingPath
    )
) {
    exit(0);
}

$maxAttempts = 3;

for (
    $attempt = 1;
    $attempt <= $maxAttempts;
    $attempt++
) {
    $raw =
        @file_get_contents(
            $sendingPath
        );

    $data =
        $raw !== false
            ? json_decode(
                $raw,
                true
            )
            : null;

    if (!is_array($data)) {
        $failedPath =
            str_replace(
                '.sending.json',
                '.failed.json',
                $sendingPath
            );

        @rename(
            $sendingPath,
            $failedPath
        );

        ghiLogEmailWorker(
            $logFile,
            'Job JSON không hợp lệ: ' .
            basename(
                $sendingPath
            )
        );

        exit(1);
    }

    try {
        $result =
            guiEmailThongBao(
                trim(
                    (string)(
                        $data['email']
                        ?? ''
                    )
                ),
                trim(
                    (string)(
                        $data['ho_ten']
                        ?? ''
                    )
                ),
                trim(
                    (string)(
                        $data['tieu_de']
                        ?? ''
                    )
                ),
                trim(
                    (string)(
                        $data['noi_dung']
                        ?? ''
                    )
                ),
                isset(
                    $data['lien_ket']
                )
                    ? (string)$data['lien_ket']
                    : null
            );

        $success =
            is_array($result)
                ? (bool)(
                    $result['success']
                    ?? false
                )
                : (bool)$result;

        if ($success) {
            @unlink(
                $sendingPath
            );

            ghiLogEmailWorker(
                $logFile,
                'Đã gửi: ' .
                (
                    string
                )(
                    $data['email']
                    ?? ''
                ) .
                ' | ' .
                (
                    string
                )(
                    $data['tieu_de']
                    ?? ''
                )
            );

            exit(0);
        }

        $error =
            is_array($result)
                ? trim(
                    (string)(
                        $result['error']
                        ?? ''
                    )
                )
                : '';

        if ($error === '') {
            $error =
                'Không gửi được email.';
        }

    } catch (Throwable $e) {
        $error =
            $e->getMessage();
    }

    ghiLogEmailWorker(
        $logFile,
        'Gửi lỗi lần ' .
        $attempt .
        '/' .
        $maxAttempts .
        ': ' .
        (
            string
        )(
            $data['email']
            ?? ''
        ) .
        ' | ' .
        $error
    );

    if ($attempt < $maxAttempts) {
        sleep(
            $attempt * 2
        );
    }
}

$failedPath =
    str_replace(
        '.sending.json',
        '.failed.json',
        $sendingPath
    );

@rename(
    $sendingPath,
    $failedPath
);

exit(1);

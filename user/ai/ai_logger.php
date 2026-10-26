<?php

/*
    Logger riêng cho iPhoneStore AI.
    Không ghi API key và không ghi toàn bộ prompt/câu hỏi người dùng.
*/

function aiErrorLogPath(): string
{
    return __DIR__ .
        '/../../storage/logs/ai_error.log';
}

function aiEnsureLogDirectory(): void
{
    $dir =
        dirname(
            aiErrorLogPath()
        );

    if (!is_dir($dir)) {
        @mkdir(
            $dir,
            0775,
            true
        );
    }
}

function aiRotateLogIfNeeded(): void
{
    $path =
        aiErrorLogPath();

    if (
        !is_file($path) ||
        filesize($path) < 2 * 1024 * 1024
    ) {
        return;
    }

    $backup =
        $path .
        '.' .
        date('Ymd_His') .
        '.bak';

    @rename(
        $path,
        $backup
    );
}

function aiLogError(
    string $type,
    array $context = []
): void {
    aiEnsureLogDirectory();
    aiRotateLogIfNeeded();

    /*
        Tuyệt đối không cho phép các trường nhạy cảm
        vô tình lọt vào log.
    */
    foreach (
        [
            'api_key',
            'authorization',
            'prompt',
            'input',
            'instructions',
        ]
        as $forbidden
    ) {
        unset(
            $context[$forbidden]
        );
    }

    $record = [
        'time' =>
            date('Y-m-d H:i:s'),
        'type' =>
            $type,
        'user_id' =>
            isset($_SESSION['user']['id'])
                ? (int)$_SESSION['user']['id']
                : null,
        'role' =>
            (string)(
                $_SESSION['user']['vai_tro']
                ?? 'guest'
            ),
    ];

    foreach ($context as $key => $value) {
        if (is_string($value)) {
            /*
                Giới hạn để log không phình quá lớn.
            */
            $value =
                mb_substr(
                    $value,
                    0,
                    1200,
                    'UTF-8'
                );
        }

        $record[$key] = $value;
    }

    @file_put_contents(
        aiErrorLogPath(),
        json_encode(
            $record,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        ) .
        PHP_EOL,
        FILE_APPEND |
        LOCK_EX
    );
}


function aiSecondsToHuman(string|int|float|null $value): string
{
    if ($value === null || $value === '') {
        return '';
    }

    $seconds = (int)ceil((float)$value);

    if ($seconds <= 0) {
        return '';
    }

    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $seconds %= 60;

    $parts = [];

    if ($days > 0) {
        $parts[] = $days . ' ngày';
    }

    if ($hours > 0) {
        $parts[] = $hours . ' giờ';
    }

    if ($minutes > 0) {
        $parts[] = $minutes . ' phút';
    }

    if ($days === 0 && $hours === 0 && $seconds > 0) {
        $parts[] = $seconds . ' giây';
    }

    return implode(' ', $parts);
}

function aiClassifyApiError(
    int $httpStatus,
    array $data,
    array $headers = []
): array {
    $error =
        is_array($data['error'] ?? null)
            ? $data['error']
            : [];

    $apiCode =
        trim(
            (string)(
                $error['code']
                ?? ''
            )
        );

    $apiType =
        trim(
            (string)(
                $error['type']
                ?? ''
            )
        );

    $apiMessage =
        trim(
            (string)(
                $error['message']
                ?? ''
            )
        );

    $retryAfter =
        trim(
            (string)(
                $headers['retry-after']
                ?? ''
            )
        );

    $normalized =
        strtolower(
            $apiCode .
            ' ' .
            $apiType .
            ' ' .
            $apiMessage
        );

    $kind = 'unknown_api_error';
    $userMessage =
        'AI hiện chưa thể xử lý yêu cầu 😥. Bạn thử lại sau nhé.';

    if ($httpStatus === 401) {
        $kind = 'invalid_api_key';

        $userMessage =
            'API key OpenAI không hợp lệ hoặc đã hết hiệu lực 🔑. Admin cần kiểm tra lại API key.';
    } elseif ($httpStatus === 403) {
        $kind = 'permission_denied';

        $userMessage =
            'API key hiện không có quyền dùng chức năng/model này 🔒. Admin cần kiểm tra quyền của project/API key.';
    } elseif ($httpStatus === 404) {
        $kind = 'model_or_endpoint_not_found';

        $userMessage =
            'Không tìm thấy model hoặc endpoint AI đang cấu hình ⚙️. Admin cần kiểm tra lại tên model.';
    } elseif ($httpStatus === 429) {
        /*
            Ưu tiên nhận diện rate_limit_exceeded trước.
            Message của OpenAI có thể chứa chữ billing/payment method
            dù bản chất chỉ là rate limit, nên không được dùng từ
            "billing" trong message để kết luận hết tiền.
        */
        $codeLower = strtolower($apiCode);
        $typeLower = strtolower($apiType);
        $messageLower = strtolower($apiMessage);

        $isRateLimit =
            $codeLower === 'rate_limit_exceeded' ||
            str_contains($codeLower, 'rate_limit') ||
            in_array($typeLower, ['tokens', 'requests', 'rate_limit_error'], true) ||
            str_contains($messageLower, 'rate limit reached') ||
            str_contains($messageLower, 'tokens per min') ||
            str_contains($messageLower, 'requests per min');

        $isInsufficientQuota =
            $codeLower === 'insufficient_quota' ||
            str_contains($codeLower, 'insufficient_quota') ||
            str_contains($typeLower, 'insufficient_quota');

        if ($isRateLimit) {
            if (
                $typeLower === 'tokens' ||
                str_contains($messageLower, 'tokens per min')
            ) {
                $kind = 'rate_limit_tpm';
            } elseif (
                $typeLower === 'requests' ||
                str_contains($messageLower, 'requests per min')
            ) {
                $kind = 'rate_limit_rpm';
            } else {
                $kind = 'rate_limit';
            }

            $userMessage =
                'OpenAI đang giới hạn tốc độ sử dụng API ⏳. Đây là rate limit, không phải lỗi hết credit.';

            $humanWait = aiSecondsToHuman($retryAfter);

            if ($humanWait !== '') {
                $userMessage .=
                    ' Bạn có thể thử lại sau khoảng ' .
                    $humanWait .
                    '.';
            } else {
                $userMessage .= ' Bạn hãy chờ một lúc rồi thử lại.';
            }
        } elseif ($isInsufficientQuota) {
            $kind = 'quota_or_billing_exhausted';

            $userMessage =
                'OpenAI API đã hết quota/credit hoặc không còn hạn mức sử dụng 💳. Admin cần kiểm tra Billing/Usage.';
        } else {
            $kind = 'rate_limit_unknown';

            $userMessage =
                'OpenAI đang từ chối tạm thời do giới hạn sử dụng ⏳. Admin hãy xem log để biết giới hạn cụ thể.';
        }
    } elseif ($httpStatus === 400) {
        $kind =
            'invalid_request';

        if (
            str_contains(
                $normalized,
                'model'
            )
        ) {
            $userMessage =
                'Model AI đang cấu hình không được tài khoản API hỗ trợ hoặc tên model chưa đúng ⚙️.';
        } else {
            $userMessage =
                'Yêu cầu gửi tới OpenAI chưa hợp lệ ⚙️. Chi tiết đã được lưu trong log cho admin.';
        }
    } elseif ($httpStatus >= 500) {
        $kind =
            'openai_service_error';

        $userMessage =
            'Dịch vụ OpenAI đang gặp lỗi tạm thời 🛠️. Bạn thử lại sau một chút nhé.';
    }

    return [
        'kind' =>
            $kind,
        'message' =>
            $userMessage,
        'api_code' =>
            $apiCode,
        'api_type' =>
            $apiType,
        'api_message' =>
            $apiMessage,
        'retry_after' =>
            $retryAfter,
        'retry_after_human' =>
            aiSecondsToHuman($retryAfter),
    ];
}

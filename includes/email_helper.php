<?php

/* =========================================================
   IphoneStore - EMAIL HELPER
   Dùng PHPMailer để gửi email SMTP.

   Hàm chính:
   guiEmailThongBao(
       string $email,
       string $hoTen,
       string $tieuDe,
       string $noiDung,
       ?string $lienKet = null
   ): array

   Kết quả trả về:
   [
       'success' => true/false,
       'error'   => null hoặc nội dung lỗi
   ]
========================================================= */

if (!function_exists('guiEmailThongBao')) {

    function layCauHinhEmail(): array
    {
        static $config = null;

        if (is_array($config)) {
            return $config;
        }

        $configPath = __DIR__ . '/../config/email.php';

        if (!is_file($configPath)) {
            $config = [];
            return $config;
        }

        $loaded = require $configPath;
        $config = is_array($loaded) ? $loaded : [];

        return $config;
    }

    function timComposerAutoloadEmail(): ?string
    {
        $candidates = [
            dirname(__DIR__) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php'
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    function napThuVienPHPMailer(): array
    {
        if (class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return [
                'success' => true,
                'error' => null
            ];
        }

        $autoloadPath = timComposerAutoloadEmail();

        if ($autoloadPath === null) {
            return [
                'success' => false,
                'error' => 'Không tìm thấy vendor/autoload.php. Hãy chạy composer require phpmailer/phpmailer.'
            ];
        }

        require_once $autoloadPath;

        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            return [
                'success' => false,
                'error' => 'PHPMailer chưa được cài hoặc chưa được Composer nạp.'
            ];
        }

        return [
            'success' => true,
            'error' => null
        ];
    }

    function taoLinkEmailTuyetDoi(?string $lienKet, array $config): ?string
    {
        $lienKet = trim((string)$lienKet);

        if ($lienKet === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $lienKet)) {
            return $lienKet;
        }

        $baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');

        if ($baseUrl === '') {
            return $lienKet;
        }

        return $baseUrl . '/' . ltrim($lienKet, '/');
    }

    function taoNoiDungEmailHtml(
        string $hoTen,
        string $tieuDe,
        string $noiDung,
        ?string $lienKet = null
    ): string {
        $hoTenSafe = htmlspecialchars(
            $hoTen !== '' ? $hoTen : 'Quý khách',
            ENT_QUOTES,
            'UTF-8'
        );

        $tieuDeSafe = htmlspecialchars($tieuDe, ENT_QUOTES, 'UTF-8');
        $noiDungSafe = nl2br(htmlspecialchars($noiDung, ENT_QUOTES, 'UTF-8'));

        $buttonHtml = '';

        if ($lienKet !== null && $lienKet !== '') {
            $linkSafe = htmlspecialchars($lienKet, ENT_QUOTES, 'UTF-8');

            $buttonHtml = '
                <div style="margin-top:24px;">
                    <a href="' . $linkSafe . '"
                       style="display:inline-block;padding:12px 20px;border-radius:10px;background:#d70018;color:#ffffff;text-decoration:none;font-weight:700;">
                        Xem chi tiết
                    </a>
                </div>
            ';
        }

        return '
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>' . $tieuDeSafe . '</title>
</head>
<body style="margin:0;padding:0;background:#f5f6f8;font-family:Arial,Helvetica,sans-serif;color:#222222;">
    <div style="width:100%;padding:30px 15px;box-sizing:border-box;">
        <div style="max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #eceff3;border-radius:16px;overflow:hidden;">
            <div style="padding:22px 26px;background:#111111;color:#ffffff;">
                <div style="font-size:21px;font-weight:800;">IphoneStore</div>
            </div>

            <div style="padding:28px 26px;">
                <div style="margin-bottom:14px;font-size:15px;">
                    Xin chào <strong>' . $hoTenSafe . '</strong>,
                </div>

                <h2 style="margin:0 0 16px;font-size:22px;line-height:1.35;color:#111111;">
                    ' . $tieuDeSafe . '
                </h2>

                <div style="font-size:15px;line-height:1.75;color:#4b5563;">
                    ' . $noiDungSafe . '
                </div>

                ' . $buttonHtml . '
            </div>

            <div style="padding:18px 26px;border-top:1px solid #eceff3;background:#fafafa;color:#8a8f98;font-size:12px;line-height:1.6;">
                Đây là email tự động từ IphoneStore. Vui lòng không trả lời trực tiếp email này.
            </div>
        </div>
    </div>
</body>
</html>';
    }

    function guiEmailThongBao(
        string $email,
        string $hoTen,
        string $tieuDe,
        string $noiDung,
        ?string $lienKet = null
    ): array {
        $email = trim($email);
        $hoTen = trim($hoTen);
        $tieuDe = trim($tieuDe);
        $noiDung = trim($noiDung);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error' => 'Địa chỉ email người nhận không hợp lệ.'
            ];
        }

        if ($tieuDe === '' || $noiDung === '') {
            return [
                'success' => false,
                'error' => 'Tiêu đề hoặc nội dung email đang trống.'
            ];
        }

        $config = layCauHinhEmail();

        if (empty($config) || empty($config['enabled'])) {
            return [
                'success' => false,
                'error' => 'Email SMTP chưa được bật hoặc chưa có cấu hình.'
            ];
        }

        $required = [
            'host',
            'port',
            'username',
            'password',
            'from_email',
            'from_name'
        ];

        foreach ($required as $key) {
            if (!isset($config[$key]) || trim((string)$config[$key]) === '') {
                return [
                    'success' => false,
                    'error' => "Thiếu cấu hình email: {$key}."
                ];
            }
        }

        $username = trim((string)$config['username']);
        $fromEmail = trim((string)$config['from_email']);

        /* Google có thể hiển thị App Password theo nhóm có khoảng trắng. */
        $password = preg_replace('/\s+/', '', (string)$config['password']);
        $password = $password !== null ? $password : trim((string)$config['password']);

        if (str_contains($password, 'DAN_APP_PASSWORD')) {
            return [
                'success' => false,
                'error' => 'Bạn chưa dán App Password vào config/email.php.'
            ];
        }

        if (
            !filter_var($username, FILTER_VALIDATE_EMAIL) ||
            !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)
        ) {
            return [
                'success' => false,
                'error' => 'Email gửi SMTP không hợp lệ.'
            ];
        }

        $library = napThuVienPHPMailer();

        if (!$library['success']) {
            return $library;
        }

        $absoluteLink = taoLinkEmailTuyetDoi($lienKet, $config);

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->CharSet = 'UTF-8';

            $debugLevel = max(0, min(4, (int)($config['debug'] ?? 0)));
            $mail->SMTPDebug = $debugLevel;

            if ($debugLevel > 0) {
                $mail->Debugoutput = static function (string $message, int $level): void {
                    error_log("PHPMailer SMTP [{$level}]: " . $message);
                };
            }

            $mail->isSMTP();
            $mail->Host = trim((string)$config['host']);
            $mail->SMTPAuth = true;
            $mail->Username = $username;
            $mail->Password = $password;

            $encryption = strtolower(trim((string)($config['encryption'] ?? 'tls')));

            if ($encryption === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->Port = (int)$config['port'];

            $mail->setFrom(
                $fromEmail,
                trim((string)$config['from_name'])
            );

            $mail->addAddress(
                $email,
                $hoTen !== '' ? $hoTen : $email
            );

            $mail->isHTML(true);
            $mail->Subject = $tieuDe;
            $mail->Body = taoNoiDungEmailHtml(
                $hoTen,
                $tieuDe,
                $noiDung,
                $absoluteLink
            );

            $mail->AltBody =
                $tieuDe . "\n\n" .
                $noiDung .
                ($absoluteLink ? "\n\nXem chi tiết: " . $absoluteLink : '');

            $mail->send();

            return [
                'success' => true,
                'error' => null
            ];

        } catch (Throwable $e) {
            error_log('IphoneStore email error: ' . $e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

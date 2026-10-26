<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/database.php";

/* =========================================================
   HÀM HỖ TRỢ - FILE TỰ HOẠT ĐỘNG
   KHÔNG CẦN account_validation.php
========================================================= */
function normalizeEmailAddress(string $email): string
{
    return strtolower(trim($email));
}

function validateEmailAddress(string $email): array
{
    $errors = [];

    if ($email === '') {
        return ['Email không được để trống.'];
    }

    if (strlen($email) > 100) {
        $errors[] = 'Email không được vượt quá 100 ký tự.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email không đúng định dạng.';
    }

    return $errors;
}

function normalizeVietnamPhone(string $phone): string
{
    $phone = trim($phone);

    $phone = preg_replace(
        '/[\s.\-()]/',
        '',
        $phone
    ) ?? $phone;

    if (str_starts_with($phone, '+84')) {
        $phone = '0' . substr($phone, 3);
    }

    return $phone;
}

function validateVietnamPhone(string $phone): array
{
    if ($phone === '') {
        return ['Số điện thoại không được để trống.'];
    }

    if (!preg_match('/^(03|05|07|08|09)[0-9]{8}$/', $phone)) {
        return [
            'Số điện thoại Việt Nam phải có 10 số và bắt đầu bằng 03, 05, 07, 08 hoặc 09.'
        ];
    }

    return [];
}

function passwordMatchesStored(
    string $plainPassword,
    string $storedPassword
): bool {
    $info = password_get_info($storedPassword);

    if (!empty($info['algo'])) {
        return password_verify(
            $plainPassword,
            $storedPassword
        );
    }

    return hash_equals(
        $storedPassword,
        $plainPassword
    );
}

function validateStrongPassword(
    string $password,
    string $email = '',
    string $fullName = ''
): array {
    $errors = [];

    if ($password === '') {
        return ['Mật khẩu mới không được để trống.'];
    }

    if (strlen($password) < 8) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 8 ký tự.';
    }

    if (strlen($password) > 72) {
        $errors[] = 'Mật khẩu mới không được vượt quá 72 ký tự.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 1 chữ hoa.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 1 chữ thường.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 1 chữ số.';
    }

    if (!preg_match('/[^A-Za-z0-9\s]/', $password)) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 1 ký tự đặc biệt.';
    }

    if (preg_match('/\s/', $password)) {
        $errors[] = 'Mật khẩu mới không được chứa khoảng trắng.';
    }

    $lowerPassword = strtolower($password);

    $commonPasswords = [
        'password',
        'password123',
        '12345678',
        '123456789',
        'qwerty123',
        'admin123',
        'abc123456'
    ];

    if (in_array($lowerPassword, $commonPasswords, true)) {
        $errors[] =
            'Mật khẩu mới quá phổ biến. Hãy chọn mật khẩu khó đoán hơn.';
    }

    if ($email !== '') {
        $emailLocal = explode(
            '@',
            strtolower($email),
            2
        )[0] ?? '';

        if (
            strlen($emailLocal) >= 3 &&
            str_contains(
                $lowerPassword,
                $emailLocal
            )
        ) {
            $errors[] =
                'Mật khẩu mới không nên chứa tên tài khoản trong email.';
        }
    }

    if ($fullName !== '') {
        $tokens = preg_split(
            '/\s+/u',
            strtolower(trim($fullName))
        ) ?: [];

        foreach ($tokens as $token) {
            if (
                strlen($token) >= 4 &&
                str_contains(
                    $lowerPassword,
                    $token
                )
            ) {
                $errors[] =
                    'Mật khẩu mới không nên chứa tên của bạn.';
                break;
            }
        }
    }

    return array_values(
        array_unique($errors)
    );
}

/* =========================================================
   CSRF TOKEN
========================================================= */
$csrfKey = 'csrf_forgot_password_token';

if (empty($_SESSION[$csrfKey])) {
    $_SESSION[$csrfKey] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string)$_SESSION[$csrfKey];

$errors = [];

$email = '';
$soDienThoai = '';

/* =========================================================
   XỬ LÝ QUÊN MẬT KHẨU
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalizeEmailAddress(
        (string)($_POST['email'] ?? '')
    );

    $soDienThoai = normalizeVietnamPhone(
        (string)($_POST['so_dien_thoai'] ?? '')
    );

    $matKhauMoi =
        (string)($_POST['mat_khau_moi'] ?? '');

    $xacNhan =
        (string)($_POST['xac_nhan_mat_khau'] ?? '');

    $csrfGuiLen =
        (string)($_POST['csrf_token'] ?? '');

    /* -----------------------------------------------------
       CSRF
    ------------------------------------------------------ */
    if (
        $csrfGuiLen === '' ||
        !hash_equals(
            (string)$_SESSION[$csrfKey],
            $csrfGuiLen
        )
    ) {
        $errors[] =
            'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
    }

    /* -----------------------------------------------------
       VALIDATE DỮ LIỆU
    ------------------------------------------------------ */
    $errors = array_merge(
        $errors,
        validateEmailAddress($email),
        validateVietnamPhone($soDienThoai),
        validateStrongPassword(
            $matKhauMoi,
            $email
        )
    );

    if ($matKhauMoi !== $xacNhan) {
        $errors[] =
            'Xác nhận mật khẩu mới không khớp.';
    }

    $errors = array_values(
        array_unique($errors)
    );

    /* -----------------------------------------------------
       XÁC MINH EMAIL + SỐ ĐIỆN THOẠI
    ------------------------------------------------------ */
    if (empty($errors)) {
        $stmt = $conn->prepare("
            SELECT
                id,
                ho_ten,
                email,
                mat_khau,
                trang_thai
            FROM nguoi_dung
            WHERE email = ?
              AND so_dien_thoai = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $errors[] =
                'Không thể xác minh tài khoản lúc này.';
        } else {
            $stmt->bind_param(
                "ss",
                $email,
                $soDienThoai
            );

            $stmt->execute();

            $user = $stmt
                ->get_result()
                ->fetch_assoc();

            if (
                !$user ||
                (int)$user['trang_thai'] !== 1
            ) {
                /*
                    Không nói cụ thể email hay SĐT sai để tránh
                    tiết lộ tài khoản tồn tại hay không.
                */
                $errors[] =
                    'Thông tin xác minh không chính xác hoặc tài khoản không khả dụng.';
            } else {
                /* -----------------------------------------
                   VALIDATE LẠI VỚI TÊN THẬT CỦA USER
                ------------------------------------------ */
                $passwordErrors =
                    validateStrongPassword(
                        $matKhauMoi,
                        (string)$user['email'],
                        (string)$user['ho_ten']
                    );

                if (
                    passwordMatchesStored(
                        $matKhauMoi,
                        (string)$user['mat_khau']
                    )
                ) {
                    $passwordErrors[] =
                        'Mật khẩu mới không được trùng mật khẩu hiện tại.';
                }

                $errors = array_values(
                    array_unique(
                        array_merge(
                            $errors,
                            $passwordErrors
                        )
                    )
                );

                /* -----------------------------------------
                   CẬP NHẬT MẬT KHẨU
                ------------------------------------------ */
                if (empty($errors)) {
                    $hash = password_hash(
                        $matKhauMoi,
                        PASSWORD_DEFAULT
                    );

                    $stmtUpdate = $conn->prepare("
                        UPDATE nguoi_dung
                        SET mat_khau = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if (!$stmtUpdate) {
                        $errors[] =
                            'Không thể cập nhật mật khẩu.';
                    } else {
                        $userId = (int)$user['id'];

                        $stmtUpdate->bind_param(
                            "si",
                            $hash,
                            $userId
                        );

                        if ($stmtUpdate->execute()) {
                            unset($_SESSION[$csrfKey]);

                            header(
                                "Location: login.php?reset=1"
                            );

                            exit;
                        }

                        $errors[] =
                            'Có lỗi xảy ra khi đặt lại mật khẩu.';
                    }
                }
            }
        }
    }
}
?>

<?php include "../includes/header.php"; ?>
<?php include "../includes/navbar.php"; ?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
.forgot-page {
    min-height: calc(100vh - 120px);
    padding: 55px 0;
    background:
        radial-gradient(
            circle at 12% 15%,
            rgba(238, 77, 45, .10),
            transparent 27%
        ),
        radial-gradient(
            circle at 90% 85%,
            rgba(13, 110, 253, .08),
            transparent 25%
        ),
        #f6f7f9;
}

.forgot-card {
    overflow: hidden;
    border: 1px solid #ebedf0;
    border-radius: 24px;
    background: #fff;
    box-shadow: 0 22px 65px rgba(0, 0, 0, .09);
}

.forgot-head {
    padding: 30px 32px;
    background:
        radial-gradient(
            circle at 90% 10%,
            rgba(238, 77, 45, .35),
            transparent 30%
        ),
        linear-gradient(
            135deg,
            #141414,
            #252525
        );
    color: #fff;
}

.forgot-icon {
    width: 50px;
    height: 50px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 15px;
    border-radius: 14px;
    background: rgba(255, 255, 255, .10);
    color: #ff7354;
    font-size: 23px;
}

.forgot-title {
    margin-bottom: 5px;
    font-size: 25px;
    font-weight: 850;
}

.forgot-subtitle {
    color: rgba(255, 255, 255, .70);
    font-size: 13px;
}

.forgot-body {
    padding: 32px;
}

.form-label {
    color: #444;
    font-size: 13px;
    font-weight: 750;
}

.form-control {
    min-height: 50px;
    border-radius: 10px;
    box-shadow: none !important;
}

.form-control:focus {
    border-color: #ee4d2d;
}

.password-wrap {
    position: relative;
}

.password-wrap .form-control {
    padding-right: 50px;
}

.password-eye {
    position: absolute;
    top: 50%;
    right: 12px;
    z-index: 4;
    border: 0;
    background: transparent;
    color: #888;
    transform: translateY(-50%);
}

.reset-note {
    margin-bottom: 22px;
    padding: 13px 14px;
    border: 1px solid #ffe0d7;
    border-radius: 11px;
    background: #fff7f3;
    color: #72564e;
    font-size: 12px;
    line-height: 1.6;
}

.password-rules {
    margin-top: 7px;
    color: #888;
    font-size: 11px;
    line-height: 1.55;
}

.error-list {
    border: 0;
    border-radius: 12px;
    font-size: 13px;
}

.error-list ul {
    margin-bottom: 0;
    padding-left: 20px;
}

.reset-btn {
    min-height: 50px;
    border: 0;
    border-radius: 11px;
    background: linear-gradient(
        135deg,
        #ee4d2d,
        #ff6a47
    );
    font-weight: 800;
}

.back-login {
    color: #666;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.back-login:hover {
    color: #ee4d2d;
}

@media (max-width: 575px) {
    .forgot-page {
        padding: 25px 0;
    }

    .forgot-head,
    .forgot-body {
        padding: 25px 22px;
    }
}
</style>

<div class="forgot-page">
    <div class="container">

        <div class="row justify-content-center">
            <div class="col-lg-5 col-md-7">

                <div class="forgot-card">

                    <div class="forgot-head">

                        <div class="forgot-icon">
                            <i class="bi bi-key"></i>
                        </div>

                        <div class="forgot-title">
                            Quên mật khẩu
                        </div>

                        <div class="forgot-subtitle">
                            Xác minh tài khoản trước khi đặt mật khẩu mới.
                        </div>

                    </div>

                    <div class="forgot-body">

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger error-list">
                                <strong>
                                    Không thể đặt lại mật khẩu:
                                </strong>

                                <ul class="mt-2">
                                    <?php foreach ($errors as $itemError): ?>
                                        <li>
                                            <?= htmlspecialchars($itemError) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="reset-note">
                            <i class="bi bi-shield-check me-1"></i>

                            Hệ thống hiện xác minh bằng
                            <strong>email + số điện thoại</strong>
                            đã đăng ký.

                            <br>

                            Sau khi thông tin khớp, mật khẩu mới sẽ được
                            lưu dưới dạng mã hóa an toàn.
                        </div>

                        <form
                            method="POST"
                            id="forgotPasswordForm"
                            novalidate
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($csrfToken) ?>"
                            >

                            <!-- EMAIL -->
                            <div class="mb-3">

                                <label class="form-label">
                                    Email tài khoản
                                </label>

                                <input
                                    type="email"
                                    name="email"
                                    class="form-control"
                                    value="<?= htmlspecialchars($email) ?>"
                                    maxlength="100"
                                    autocomplete="email"
                                    placeholder="example@gmail.com"
                                    required
                                >

                                <div class="invalid-feedback">
                                    Vui lòng nhập email hợp lệ.
                                </div>
                            </div>

                            <!-- SỐ ĐIỆN THOẠI -->
                            <div class="mb-3">

                                <label class="form-label">
                                    Số điện thoại đã đăng ký
                                </label>

                                <input
                                    type="tel"
                                    name="so_dien_thoai"
                                    class="form-control"
                                    value="<?= htmlspecialchars($soDienThoai) ?>"
                                    pattern="^(03|05|07|08|09)[0-9]{8}$"
                                    maxlength="10"
                                    inputmode="numeric"
                                    autocomplete="tel"
                                    placeholder="0912345678"
                                    required
                                >

                                <div class="invalid-feedback">
                                    Nhập số điện thoại Việt Nam hợp lệ.
                                </div>
                            </div>

                            <!-- MẬT KHẨU MỚI -->
                            <div class="mb-3">

                                <label class="form-label">
                                    Mật khẩu mới
                                </label>

                                <div class="password-wrap">

                                    <input
                                        type="password"
                                        name="mat_khau_moi"
                                        id="newPassword"
                                        class="form-control"
                                        minlength="8"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        placeholder="Nhập mật khẩu mới"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="password-eye"
                                        data-password="newPassword"
                                        aria-label="Hiện hoặc ẩn mật khẩu"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

                                </div>

                                <div class="password-rules">
                                    8–72 ký tự, có chữ hoa, chữ thường,
                                    số, ký tự đặc biệt và không có khoảng trắng.
                                </div>
                            </div>

                            <!-- XÁC NHẬN -->
                            <div class="mb-4">

                                <label class="form-label">
                                    Xác nhận mật khẩu mới
                                </label>

                                <div class="password-wrap">

                                    <input
                                        type="password"
                                        name="xac_nhan_mat_khau"
                                        id="confirmPassword"
                                        class="form-control"
                                        minlength="8"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        placeholder="Nhập lại mật khẩu mới"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="password-eye"
                                        data-password="confirmPassword"
                                        aria-label="Hiện hoặc ẩn mật khẩu"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

                                </div>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-danger reset-btn w-100"
                            >
                                <i class="bi bi-arrow-repeat me-1"></i>
                                Đặt lại mật khẩu
                            </button>

                        </form>

                        <div class="text-center mt-4">

                            <a
                                href="login.php"
                                class="back-login"
                            >
                                <i class="bi bi-arrow-left me-1"></i>
                                Quay lại đăng nhập
                            </a>

                        </div>

                    </div>

                </div>

            </div>
        </div>

    </div>
</div>

<script>
/* =========================================================
   HIỆN / ẨN MẬT KHẨU
========================================================= */
document
    .querySelectorAll('[data-password]')
    .forEach(function (button) {
        button.addEventListener(
            'click',
            function () {
                const input =
                    document.getElementById(
                        button.getAttribute('data-password')
                    );

                const icon =
                    button.querySelector('i');

                if (!input) {
                    return;
                }

                input.type =
                    input.type === 'password'
                        ? 'text'
                        : 'password';

                if (icon) {
                    icon.classList.toggle(
                        'bi-eye',
                        input.type === 'password'
                    );

                    icon.classList.toggle(
                        'bi-eye-slash',
                        input.type !== 'password'
                    );
                }
            }
        );
    });

/* =========================================================
   VALIDATE XÁC NHẬN MẬT KHẨU
========================================================= */
const form =
    document.getElementById('forgotPasswordForm');

const newPassword =
    document.getElementById('newPassword');

const confirmPassword =
    document.getElementById('confirmPassword');

function validateConfirmPassword() {
    if (
        newPassword.value !== '' &&
        confirmPassword.value !== '' &&
        newPassword.value !== confirmPassword.value
    ) {
        confirmPassword.setCustomValidity(
            'Mật khẩu xác nhận không khớp.'
        );
    } else {
        confirmPassword.setCustomValidity('');
    }
}

if (newPassword && confirmPassword) {
    newPassword.addEventListener(
        'input',
        validateConfirmPassword
    );

    confirmPassword.addEventListener(
        'input',
        validateConfirmPassword
    );
}

if (form) {
    form.addEventListener(
        'submit',
        function (event) {
            validateConfirmPassword();

            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }

            form.classList.add(
                'was-validated'
            );
        }
    );
}
</script>

<?php include "../includes/footer.php"; ?>

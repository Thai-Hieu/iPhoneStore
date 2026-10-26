<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/database.php";

/* =========================================================
   VALIDATE TÀI KHOẢN DÙNG RIÊNG CHO REGISTER

   File này tự hoạt động, KHÔNG cần account_validation.php.
========================================================= */
function textLength(string $value): int
{
    return function_exists('mb_strlen')
        ? mb_strlen($value, 'UTF-8')
        : strlen($value);
}

function textLower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function normalizeFullName(string $name): string
{
    $name = trim($name);

    return preg_replace(
        '/\s+/u',
        ' ',
        $name
    ) ?? $name;
}

function validateFullName(string $name): array
{
    $errors = [];
    $length = textLength($name);

    if ($name === '') {
        return [
            'Họ và tên không được để trống.'
        ];
    }

    if ($length < 2 || $length > 100) {
        $errors[] =
            'Họ và tên phải từ 2 đến 100 ký tự.';
    }

    if (
        !preg_match(
            "/^[\p{L}\p{M}][\p{L}\p{M}\s'.-]*$/u",
            $name
        )
    ) {
        $errors[] =
            'Họ và tên chỉ được chứa chữ cái, khoảng trắng, dấu chấm, dấu nháy hoặc gạch nối.';
    }

    return $errors;
}

function normalizeEmailAddress(string $email): string
{
    return textLower(trim($email));
}

function validateEmailAddress(string $email): array
{
    $errors = [];

    if ($email === '') {
        return [
            'Email không được để trống.'
        ];
    }

    if (strlen($email) > 100) {
        $errors[] =
            'Email không được vượt quá 100 ký tự.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] =
            'Email không đúng định dạng.';
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
        return [
            'Số điện thoại không được để trống.'
        ];
    }

    if (
        !preg_match(
            '/^(03|05|07|08|09)[0-9]{8}$/',
            $phone
        )
    ) {
        return [
            'Số điện thoại Việt Nam phải có 10 số và bắt đầu bằng 03, 05, 07, 08 hoặc 09.'
        ];
    }

    return [];
}

function validateStrongPassword(
    string $password,
    string $email = '',
    string $fullName = ''
): array {
    $errors = [];
    $length = strlen($password);

    if ($password === '') {
        return [
            'Mật khẩu không được để trống.'
        ];
    }

    if ($length < 8) {
        $errors[] =
            'Mật khẩu phải có ít nhất 8 ký tự.';
    }

    if ($length > 72) {
        $errors[] =
            'Mật khẩu không được vượt quá 72 ký tự.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] =
            'Mật khẩu phải có ít nhất 1 chữ hoa.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] =
            'Mật khẩu phải có ít nhất 1 chữ thường.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] =
            'Mật khẩu phải có ít nhất 1 chữ số.';
    }

    if (!preg_match('/[^A-Za-z0-9\s]/', $password)) {
        $errors[] =
            'Mật khẩu phải có ít nhất 1 ký tự đặc biệt.';
    }

    if (preg_match('/\s/', $password)) {
        $errors[] =
            'Mật khẩu không được chứa khoảng trắng.';
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

    if (
        in_array(
            $lowerPassword,
            $commonPasswords,
            true
        )
    ) {
        $errors[] =
            'Mật khẩu quá phổ biến. Hãy chọn mật khẩu khó đoán hơn.';
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
                'Mật khẩu không nên chứa tên tài khoản trong email.';
        }
    }

    if ($fullName !== '') {
        $tokens = preg_split(
            '/\s+/u',
            textLower($fullName)
        ) ?: [];

        foreach ($tokens as $token) {
            if (
                textLength($token) >= 4 &&
                str_contains(
                    $lowerPassword,
                    $token
                )
            ) {
                $errors[] =
                    'Mật khẩu không nên chứa tên của bạn.';
                break;
            }
        }
    }

    return array_values(
        array_unique($errors)
    );
}

function createCsrfToken(string $key): string
{
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] =
            bin2hex(random_bytes(32));
    }

    return (string)$_SESSION[$key];
}

function csrfIsValid(
    string $key,
    string $submittedToken
): bool {
    return
        $submittedToken !== '' &&
        !empty($_SESSION[$key]) &&
        hash_equals(
            (string)$_SESSION[$key],
            $submittedToken
        );
}


if (!empty($_SESSION['user']['id'])) {
    $role = (string)($_SESSION['user']['vai_tro'] ?? '');

    header(
        $role === 'admin'
            ? "Location: ../admin/index.php"
            : "Location: ../user/index.php"
    );
    exit;
}

$csrfKey = 'csrf_register_token';
$csrfToken = createCsrfToken($csrfKey);

$errors = [];

$hoTen = '';
$email = '';
$soDienThoai = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hoTen = normalizeFullName((string)($_POST['ho_ten'] ?? ''));
    $email = normalizeEmailAddress((string)($_POST['email'] ?? ''));
    $soDienThoai = normalizeVietnamPhone(
        (string)($_POST['so_dien_thoai'] ?? '')
    );

    $matKhau = (string)($_POST['mat_khau'] ?? '');
    $xacNhan = (string)($_POST['xac_nhan_mat_khau'] ?? '');
    $dongY = isset($_POST['dong_y_dieu_khoan']);

    $csrfGuiLen = (string)($_POST['csrf_token'] ?? '');

    if (!csrfIsValid($csrfKey, $csrfGuiLen)) {
        $errors[] = 'Phiên đăng ký không hợp lệ. Vui lòng tải lại trang.';
    }

    $errors = array_merge(
        $errors,
        validateFullName($hoTen),
        validateEmailAddress($email),
        validateVietnamPhone($soDienThoai),
        validateStrongPassword($matKhau, $email, $hoTen)
    );

    if ($matKhau !== $xacNhan) {
        $errors[] = 'Xác nhận mật khẩu không khớp.';
    }

    if (!$dongY) {
        $errors[] = 'Bạn cần đồng ý với điều khoản sử dụng để tạo tài khoản.';
    }

    if (empty($errors)) {
        $stmtCheck = $conn->prepare("
            SELECT id, email, so_dien_thoai
            FROM nguoi_dung
            WHERE email = ?
               OR so_dien_thoai = ?
            LIMIT 1
        ");

        if (!$stmtCheck) {
            $errors[] = 'Không thể kiểm tra tài khoản lúc này.';
        } else {
            $stmtCheck->bind_param(
                "ss",
                $email,
                $soDienThoai
            );

            $stmtCheck->execute();

            $existing = $stmtCheck
                ->get_result()
                ->fetch_assoc();

            if ($existing) {
                if (
                    isset($existing['email']) &&
                    textLower((string)$existing['email']) === $email
                ) {
                    $errors[] = 'Email này đã được sử dụng.';
                }

                if (
                    isset($existing['so_dien_thoai']) &&
                    normalizeVietnamPhone(
                        (string)$existing['so_dien_thoai']
                    ) === $soDienThoai
                ) {
                    $errors[] = 'Số điện thoại này đã được sử dụng.';
                }
            }
        }
    }

    if (empty($errors)) {
        $stmtRole = $conn->prepare("
            SELECT id
            FROM vai_tro
            WHERE vai_tro = 'user'
            LIMIT 1
        ");

        if (!$stmtRole) {
            $errors[] = 'Không thể xác định vai trò người dùng.';
        } else {
            $stmtRole->execute();

            $role = $stmtRole
                ->get_result()
                ->fetch_assoc();

            if (!$role) {
                $errors[] = 'Hệ thống chưa có vai trò user.';
            } else {
                $vaiTroId = (int)$role['id'];

                $matKhauHash = password_hash(
                    $matKhau,
                    PASSWORD_DEFAULT
                );

                $stmtInsert = $conn->prepare("
                    INSERT INTO nguoi_dung (
                        ho_ten,
                        email,
                        mat_khau,
                        so_dien_thoai,
                        vai_tro_id,
                        trang_thai
                    )
                    VALUES (?, ?, ?, ?, ?, 1)
                ");

                if (!$stmtInsert) {
                    $errors[] = 'Không thể tạo tài khoản lúc này.';
                } else {
                    $stmtInsert->bind_param(
                        "ssssi",
                        $hoTen,
                        $email,
                        $matKhauHash,
                        $soDienThoai,
                        $vaiTroId
                    );

                    try {
                        $stmtInsert->execute();

                        unset($_SESSION[$csrfKey]);

                        header("Location: login.php?registered=1");
                        exit;
                    } catch (Throwable $e) {
                        $errors[] =
                            'Email hoặc số điện thoại đã được sử dụng.';
                    }
                }
            }
        }
    }

    $errors = array_values(array_unique($errors));
}
?>

<?php include "../includes/header.php"; ?>
<?php include "../includes/navbar.php"; ?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
.account-page {
    min-height: calc(100vh - 120px);
    padding: 55px 0;
    background:
        radial-gradient(circle at 10% 10%, rgba(238,77,45,.10), transparent 27%),
        radial-gradient(circle at 90% 90%, rgba(13,110,253,.08), transparent 25%),
        #f6f7f9;
}

.account-shell {
    overflow: hidden;
    border: 1px solid #ebedf0;
    border-radius: 28px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(0,0,0,.10);
}

.account-hero {
    height: 100%;
    min-height: 760px;
    padding: 48px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background:
        radial-gradient(circle at 85% 15%, rgba(238,77,45,.28), transparent 28%),
        linear-gradient(145deg, #111, #1f2023 55%, #321810);
    color: #fff;
}

.account-hero h1 {
    margin-top: 24px;
    font-size: 41px;
    line-height: 1.12;
    font-weight: 850;
}

.account-hero h1 span {
    color: #ff6745;
}

.brand-pill {
    width: fit-content;
    padding: 8px 13px;
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    font-size: 12px;
    font-weight: 800;
}

.hero-text {
    margin-top: 16px;
    color: rgba(255,255,255,.72);
    line-height: 1.7;
}

.hero-list {
    display: grid;
    gap: 12px;
}

.hero-list div {
    display: flex;
    gap: 10px;
    align-items: center;
    color: rgba(255,255,255,.82);
    font-size: 13px;
}

.hero-list i {
    color: #ff7354;
}

.account-form {
    padding: 45px 50px;
}

.page-title {
    font-size: 30px;
    font-weight: 850;
}

.page-subtitle {
    margin: 5px 0 25px;
    color: #777;
    font-size: 14px;
}

.form-label {
    font-size: 13px;
    font-weight: 750;
    color: #444;
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
    right: 13px;
    z-index: 4;
    border: 0;
    background: transparent;
    color: #777;
    transform: translateY(-50%);
}

.password-rules {
    margin-top: 8px;
    padding: 12px 14px;
    border-radius: 10px;
    background: #f7f8fa;
    color: #777;
    font-size: 11px;
    line-height: 1.65;
}

.password-strength {
    height: 5px;
    margin-top: 9px;
    overflow: hidden;
    border-radius: 999px;
    background: #ececec;
}

.password-strength > span {
    display: block;
    width: 0;
    height: 100%;
    background: currentColor;
    transition: .2s;
}

.strength-text {
    margin-top: 5px;
    font-size: 11px;
    font-weight: 700;
}

.register-btn {
    min-height: 51px;
    border: 0;
    border-radius: 11px;
    background: linear-gradient(135deg,#ee4d2d,#ff6a47);
    font-weight: 800;
}

.form-check-input:checked {
    background-color: #ee4d2d;
    border-color: #ee4d2d;
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

.login-area {
    margin-top: 22px;
    text-align: center;
    color: #777;
    font-size: 14px;
}

.login-area a {
    color: #ee4d2d;
    font-weight: 800;
    text-decoration: none;
}

@media (max-width: 991px) {
    .account-hero {
        min-height: auto;
        padding: 34px;
    }

    .account-form {
        padding: 35px 28px;
    }
}
</style>

<div class="account-page">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">

                <div class="account-shell">
                    <div class="row g-0">

                        <div class="col-lg-6">
                            <div class="account-hero">

                                <div>
                                    <div class="brand-pill">
                                         IphoneStore
                                    </div>

                                    <h1>
                                        Tạo tài khoản
                                        <span>an toàn hơn.</span>
                                    </h1>

                                    <div class="hero-text">
                                        Thông tin tài khoản được kiểm tra ở cả
                                        trình duyệt và máy chủ trước khi lưu.
                                    </div>
                                </div>

                                <div class="hero-list">
                                    <div>
                                        <i class="bi bi-check-circle"></i>
                                        Email và số điện thoại không trùng
                                    </div>
                                    <div>
                                        <i class="bi bi-check-circle"></i>
                                        Mật khẩu mạnh và được mã hóa
                                    </div>
                                    <div>
                                        <i class="bi bi-check-circle"></i>
                                        Chống SQL Injection và CSRF
                                    </div>
                                </div>

                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="account-form">

                                <div class="page-title">
                                    Đăng ký
                                </div>

                                <div class="page-subtitle">
                                    Vui lòng nhập thông tin chính xác của bạn.
                                </div>

                                <?php if (!empty($errors)): ?>
                                    <div class="alert alert-danger error-list">
                                        <strong>
                                            Vui lòng kiểm tra lại:
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

                                <form
                                    method="POST"
                                    id="registerForm"
                                    novalidate
                                >
                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($csrfToken) ?>"
                                    >

                                    <div class="mb-3">
                                        <label class="form-label">
                                            Họ và tên
                                        </label>

                                        <input
                                            type="text"
                                            name="ho_ten"
                                            class="form-control"
                                            value="<?= htmlspecialchars($hoTen) ?>"
                                            minlength="2"
                                            maxlength="100"
                                            autocomplete="name"
                                            placeholder="Nguyễn Văn A"
                                            required
                                        >

                                        <div class="invalid-feedback">
                                            Họ tên từ 2–100 ký tự và không chứa ký tự lạ.
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">
                                            Email
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

                                    <div class="mb-3">
                                        <label class="form-label">
                                            Số điện thoại Việt Nam
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
                                            Nhập 10 số, bắt đầu bằng 03, 05, 07, 08 hoặc 09.
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">
                                            Mật khẩu
                                        </label>

                                        <div class="password-wrap">
                                            <input
                                                type="password"
                                                name="mat_khau"
                                                id="registerPassword"
                                                class="form-control"
                                                minlength="8"
                                                maxlength="72"
                                                autocomplete="new-password"
                                                required
                                            >

                                            <button
                                                type="button"
                                                class="password-eye"
                                                data-password="registerPassword"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>

                                        <div class="password-strength">
                                            <span id="strengthBar"></span>
                                        </div>

                                        <div
                                            class="strength-text"
                                            id="strengthText"
                                        >
                                            Chưa nhập mật khẩu
                                        </div>

                                        <div class="password-rules">
                                            Ít nhất 8 ký tự · 1 chữ hoa ·
                                            1 chữ thường · 1 số ·
                                            1 ký tự đặc biệt · không có khoảng trắng.
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">
                                            Xác nhận mật khẩu
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
                                                required
                                            >

                                            <button
                                                type="button"
                                                class="password-eye"
                                                data-password="confirmPassword"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="form-check mb-4">
                                        <input
                                            class="form-check-input"
                                            type="checkbox"
                                            name="dong_y_dieu_khoan"
                                            id="terms"
                                            value="1"
                                            required
                                        >

                                        <label
                                            class="form-check-label small"
                                            for="terms"
                                        >
                                            Tôi xác nhận thông tin đã nhập là đúng
                                            và đồng ý với điều khoản sử dụng.
                                        </label>
                                    </div>

                                    <button
                                        type="submit"
                                        class="btn btn-danger register-btn w-100"
                                    >
                                        <i class="bi bi-person-plus me-1"></i>
                                        Tạo tài khoản
                                    </button>
                                </form>

                                <div class="login-area">
                                    Đã có tài khoản?
                                    <a href="login.php">
                                        Đăng nhập
                                    </a>
                                </div>

                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
const form = document.getElementById('registerForm');
const password = document.getElementById('registerPassword');
const confirmPassword = document.getElementById('confirmPassword');
const strengthBar = document.getElementById('strengthBar');
const strengthText = document.getElementById('strengthText');

function passwordScore(value) {
    let score = 0;

    if (value.length >= 8) score++;
    if (/[A-ZÀ-Ỹ]/u.test(value)) score++;
    if (/[a-zà-ỹ]/u.test(value)) score++;
    if (/[0-9]/.test(value)) score++;
    if (/[^A-Za-zÀ-ỹ0-9\s]/u.test(value)) score++;

    return score;
}

function updateStrength() {
    const value = password.value;
    const score = passwordScore(value);

    const labels = [
        'Chưa nhập mật khẩu',
        'Rất yếu',
        'Yếu',
        'Trung bình',
        'Khá mạnh',
        'Mạnh'
    ];

    strengthBar.style.width = (score * 20) + '%';
    strengthText.textContent = labels[score];

    if (score <= 2) {
        strengthBar.style.color = '#dc3545';
        strengthText.style.color = '#dc3545';
    } else if (score <= 3) {
        strengthBar.style.color = '#fd7e14';
        strengthText.style.color = '#fd7e14';
    } else {
        strengthBar.style.color = '#198754';
        strengthText.style.color = '#198754';
    }
}

password.addEventListener('input', function () {
    updateStrength();

    if (
        confirmPassword.value !== '' &&
        confirmPassword.value !== password.value
    ) {
        confirmPassword.setCustomValidity(
            'Mật khẩu xác nhận không khớp.'
        );
    } else {
        confirmPassword.setCustomValidity('');
    }
});

confirmPassword.addEventListener('input', function () {
    if (confirmPassword.value !== password.value) {
        confirmPassword.setCustomValidity(
            'Mật khẩu xác nhận không khớp.'
        );
    } else {
        confirmPassword.setCustomValidity('');
    }
});

form.addEventListener('submit', function (event) {
    if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
    }

    form.classList.add('was-validated');
});

document
    .querySelectorAll('[data-password]')
    .forEach(function (button) {
        button.addEventListener('click', function () {
            const input =
                document.getElementById(
                    button.getAttribute('data-password')
                );

            const icon = button.querySelector('i');

            if (!input) return;

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
        });
    });

updateStrength();
</script>

<?php include "../includes/footer.php"; ?>

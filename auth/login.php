<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../config/database.php";

/* =========================================================
   HÀM VALIDATE DÙNG RIÊNG CHO LOGIN

   File này tự hoạt động, KHÔNG cần account_validation.php.
========================================================= */
function normalizeEmailAddress(string $email): string
{
    return strtolower(trim($email));
}

function validateEmailAddress(string $email): array
{
    $errors = [];

    if ($email === '') {
        $errors[] = 'Email không được để trống.';
        return $errors;
    }

    if (strlen($email) > 100) {
        $errors[] = 'Email không được vượt quá 100 ký tự.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email không đúng định dạng.';
    }

    return $errors;
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

function passwordIsHashed(string $storedPassword): bool
{
    $info = password_get_info($storedPassword);

    return !empty($info['algo']);
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

/*
    Chỉ cho phép quay lại URL nội bộ của iPhoneStore.
    Chặn URL ngoài / protocol-relative để tránh open redirect.
*/
function normalizeReturnUrl(string $url): string
{
    $url = trim($url);

    if (
        $url === '' ||
        str_contains($url, "\r") ||
        str_contains($url, "\n") ||
        str_starts_with($url, '//') ||
        !str_starts_with($url, '/iPhoneStore/')
    ) {
        return '';
    }

    $parts = parse_url($url);

    if (
        $parts === false ||
        isset($parts['scheme']) ||
        isset($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass'])
    ) {
        return '';
    }

    return $url;
}

$returnUrl = normalizeReturnUrl(
    (string)(
        $_POST['return_url']
        ?? $_GET['return_url']
        ?? ''
    )
);


if (!empty($_SESSION['user']['id'])) {
    $role = (string)($_SESSION['user']['vai_tro'] ?? '');

    if ($role === 'admin') {
        header("Location: ../admin/index.php");
        exit;
    }

    header(
        'Location: ' .
        (
            $returnUrl !== ''
                ? $returnUrl
                : '../user/index.php'
        )
    );
    exit;
}

$csrfKey = 'csrf_login_token';
$csrfToken = createCsrfToken($csrfKey);

$error = '';
$success = '';

$email = normalizeEmailAddress(
    (string)($_COOKIE['apple_shop_remember_email'] ?? '')
);

$remember = $email !== '';

/* =========================================================
   RATE LIMIT ĐĂNG NHẬP THEO SESSION
   - Tối đa 5 lần sai trong cửa sổ 15 phút
   - Khóa tạm 15 phút sau khi vượt ngưỡng
========================================================= */
if (!isset($_SESSION['login_guard'])) {
    $_SESSION['login_guard'] = [
        'fails' => 0,
        'window_started' => time(),
        'locked_until' => 0
    ];
}

$guard = &$_SESSION['login_guard'];

if (
    time() - (int)$guard['window_started'] > 900
) {
    $guard = [
        'fails' => 0,
        'window_started' => time(),
        'locked_until' => 0
    ];
}

if (($_GET['registered'] ?? '') === '1') {
    $success =
        'Đăng ký thành công. Bạn có thể đăng nhập ngay.';
}

if (($_GET['reset'] ?? '') === '1') {
    $success =
        'Đặt lại mật khẩu thành công. Hãy đăng nhập bằng mật khẩu mới.';
}

if (($_GET['logout'] ?? '') === '1') {
    $success =
        'Bạn đã đăng xuất khỏi hệ thống.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = normalizeEmailAddress(
        (string)($_POST['email'] ?? '')
    );

    $matKhau =
        (string)($_POST['mat_khau'] ?? '');

    $remember =
        isset($_POST['remember']);

    $csrfGuiLen =
        (string)($_POST['csrf_token'] ?? '');

    if ((int)$guard['locked_until'] > time()) {
        $remaining =
            (int)$guard['locked_until'] - time();

        $minutes =
            max(1, (int)ceil($remaining / 60));

        $error =
            "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$minutes} phút.";
    } elseif (!csrfIsValid($csrfKey, $csrfGuiLen)) {
        $error =
            'Phiên đăng nhập không hợp lệ. Vui lòng tải lại trang.';
    } elseif (
        !empty(validateEmailAddress($email))
    ) {
        $error =
            'Email hoặc mật khẩu không chính xác.';
    } elseif (
        $matKhau === '' ||
        strlen($matKhau) > 72
    ) {
        $error =
            'Email hoặc mật khẩu không chính xác.';
    } else {
        $stmt = $conn->prepare("
            SELECT
                nd.id,
                nd.ho_ten,
                nd.email,
                nd.mat_khau,
                nd.trang_thai,
                vt.vai_tro
            FROM nguoi_dung nd
            JOIN vai_tro vt
                ON vt.id = nd.vai_tro_id
            WHERE nd.email = ?
            LIMIT 1
        ");

        if (!$stmt) {
            $error =
                'Không thể xử lý đăng nhập lúc này.';
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();

            $user = $stmt
                ->get_result()
                ->fetch_assoc();

            $valid =
                $user &&
                passwordMatchesStored(
                    $matKhau,
                    (string)$user['mat_khau']
                );

            if (!$valid) {
                $guard['fails']++;

                if ((int)$guard['fails'] >= 5) {
                    $guard['locked_until'] =
                        time() + 900;
                }

                $error =
                    'Email hoặc mật khẩu không chính xác.';
            } elseif ((int)$user['trang_thai'] !== 1) {
                $error =
                    'Tài khoản hiện không thể đăng nhập.';
            } else {
                /*
                    Tự nâng cấp mật khẩu text cũ sang hash
                    sau khi xác thực thành công.
                */
                if (
                    !passwordIsHashed(
                        (string)$user['mat_khau']
                    )
                ) {
                    $hash = password_hash(
                        $matKhau,
                        PASSWORD_DEFAULT
                    );

                    $stmtHash = $conn->prepare("
                        UPDATE nguoi_dung
                        SET mat_khau = ?
                        WHERE id = ?
                    ");

                    if ($stmtHash) {
                        $userId = (int)$user['id'];

                        $stmtHash->bind_param(
                            "si",
                            $hash,
                            $userId
                        );

                        $stmtHash->execute();
                    }
                }

                $cookieOptions = [
                    'expires' => $remember
                        ? time() + (30 * 24 * 60 * 60)
                        : time() - 3600,
                    'path' => '/',
                    'secure' =>
                        !empty($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ];

                setcookie(
                    'apple_shop_remember_email',
                    $remember ? $email : '',
                    $cookieOptions
                );

                session_regenerate_id(true);

                $_SESSION['user'] = [
                    'id' => (int)$user['id'],
                    'ho_ten' =>
                        (string)$user['ho_ten'],
                    'email' =>
                        (string)$user['email'],
                    'vai_tro' =>
                        (string)$user['vai_tro']
                ];

                unset(
                    $_SESSION[$csrfKey],
                    $_SESSION['login_guard']
                );

                if ($user['vai_tro'] === 'admin') {
                    header("Location: ../admin/index.php");
                    exit;
                }

                header(
                    'Location: ' .
                    (
                        $returnUrl !== ''
                            ? $returnUrl
                            : '../user/index.php'
                    )
                );

                exit;
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
.login-page {
    min-height: calc(100vh - 120px);
    padding: 55px 0;
    background:
        radial-gradient(circle at 10% 10%, rgba(238,77,45,.10), transparent 27%),
        radial-gradient(circle at 90% 90%, rgba(13,110,253,.08), transparent 25%),
        #f6f7f9;
}

.login-shell {
    overflow: hidden;
    border: 1px solid #ebedf0;
    border-radius: 28px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(0,0,0,.10);
}

.login-hero {
    height: 100%;
    min-height: 620px;
    padding: 48px;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    background:
        radial-gradient(circle at 85% 15%, rgba(238,77,45,.28), transparent 28%),
        linear-gradient(145deg,#111,#1f2023 55%,#321810);
    color: #fff;
}

.login-hero h1 {
    margin-top: 24px;
    font-size: 42px;
    line-height: 1.12;
    font-weight: 850;
}

.login-hero h1 span {
    color: #ff6745;
}

.login-form {
    padding: 50px;
}

.page-title {
    font-size: 31px;
    font-weight: 850;
}

.page-subtitle {
    margin: 6px 0 28px;
    color: #777;
    font-size: 14px;
}

.form-control {
    min-height: 51px;
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
    color: #777;
    transform: translateY(-50%);
}

.login-btn {
    min-height: 51px;
    border: 0;
    border-radius: 11px;
    background: linear-gradient(135deg,#ee4d2d,#ff6a47);
    font-weight: 800;
}

.auth-links {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    margin: 15px 0 24px;
    font-size: 13px;
}

.auth-links a {
    color: #ee4d2d;
    font-weight: 750;
    text-decoration: none;
}

.login-note {
    color: rgba(255,255,255,.72);
    line-height: 1.7;
}

@media (max-width: 991px) {
    .login-hero {
        min-height: auto;
        padding: 34px;
    }

    .login-form {
        padding: 35px 28px;
    }
}
</style>

<div class="login-page">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">

                <div class="login-shell">
                    <div class="row g-0">

                        <div class="col-lg-6">
                            <div class="login-hero">

                                <div>
                                    <div class="fw-bold">
                                         IphoneStore
                                    </div>

                                    <h1>
                                        Chào mừng bạn
                                        <span>quay lại.</span>
                                    </h1>

                                    <div class="login-note">
                                        Đăng nhập để quản lý tài khoản,
                                        giỏ hàng và theo dõi đơn hàng của bạn.
                                    </div>
                                </div>

                                <div class="small text-white-50">
                                    Đăng nhập được giới hạn số lần thử
                                    để bảo vệ tài khoản.
                                </div>

                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="login-form">

                                <div class="page-title">
                                    Đăng nhập
                                </div>

                                <div class="page-subtitle">
                                    Nhập thông tin tài khoản để tiếp tục.
                                </div>

                                <?php if ($error !== ''): ?>
                                    <div class="alert alert-danger border-0">
                                        <?= htmlspecialchars($error) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($success !== ''): ?>
                                    <div class="alert alert-success border-0">
                                        <?= htmlspecialchars($success) ?>
                                    </div>
                                <?php endif; ?>

                                <form method="POST">

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($csrfToken) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="return_url"
                                        value="<?= htmlspecialchars(
                                            $returnUrl,
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>"
                                    >

                                    <div class="mb-3">
                                        <label class="form-label fw-bold">
                                            Email
                                        </label>

                                        <input
                                            type="email"
                                            name="email"
                                            class="form-control"
                                            value="<?= htmlspecialchars($email) ?>"
                                            maxlength="100"
                                            autocomplete="email"
                                            required
                                        >
                                    </div>

                                    <div class="mb-2">
                                        <label class="form-label fw-bold">
                                            Mật khẩu
                                        </label>

                                        <div class="password-wrap">
                                            <input
                                                type="password"
                                                name="mat_khau"
                                                id="loginPassword"
                                                class="form-control"
                                                maxlength="72"
                                                autocomplete="current-password"
                                                required
                                            >

                                            <button
                                                type="button"
                                                class="password-eye"
                                                id="togglePassword"
                                            >
                                                <i class="bi bi-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div class="auth-links">

                                        <label>
                                            <input
                                                type="checkbox"
                                                name="remember"
                                                value="1"
                                                <?= $remember ? 'checked' : '' ?>
                                            >
                                            Ghi nhớ email
                                        </label>

                                        <a href="quen_mat_khau.php">
                                            Quên mật khẩu?
                                        </a>

                                    </div>

                                    <button
                                        type="submit"
                                        class="btn btn-danger login-btn w-100"
                                    >
                                        Đăng nhập
                                    </button>

                                </form>

                                <div class="text-center mt-4">
                                    Chưa có tài khoản?
                                    <a
                                        href="register.php"
                                        class="text-danger fw-bold text-decoration-none"
                                    >
                                        Đăng ký
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
const passwordInput =
    document.getElementById('loginPassword');

const toggleButton =
    document.getElementById('togglePassword');

if (passwordInput && toggleButton) {
    toggleButton.addEventListener('click', function () {
        const icon = toggleButton.querySelector('i');

        passwordInput.type =
            passwordInput.type === 'password'
                ? 'text'
                : 'password';

        if (icon) {
            icon.classList.toggle(
                'bi-eye',
                passwordInput.type === 'password'
            );

            icon.classList.toggle(
                'bi-eye-slash',
                passwordInput.type !== 'password'
            );
        }
    });
}
</script>

<?php include "../includes/footer.php"; ?>

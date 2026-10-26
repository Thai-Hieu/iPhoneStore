<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

/* =========================================================
   ADMIN HIỆN TẠI
========================================================= */
$adminId = (int)($_SESSION['user']['id'] ?? 0);

if ($adminId <= 0) {
    header("Location: ../../auth/login.php");
    exit;
}

/* =========================================================
   HÀM HỖ TRỢ MẬT KHẨU

   Hỗ trợ:
   - tài khoản cũ còn lưu mật khẩu dạng text
   - tài khoản mới lưu bằng password_hash()
========================================================= */
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

/* =========================================================
   VALIDATE MẬT KHẨU MỚI
========================================================= */
function validateStrongPassword(
    string $password,
    string $email = '',
    string $fullName = ''
): array {
    $errors = [];

    if ($password === '') {
        return [
            'Mật khẩu mới không được để trống.'
        ];
    }

    if (strlen($password) < 8) {
        $errors[] =
            'Mật khẩu mới phải có ít nhất 8 ký tự.';
    }

    if (strlen($password) > 72) {
        $errors[] =
            'Mật khẩu mới không được vượt quá 72 ký tự.';
    }

    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] =
            'Mật khẩu mới phải có ít nhất 1 chữ hoa.';
    }

    if (!preg_match('/[a-z]/', $password)) {
        $errors[] =
            'Mật khẩu mới phải có ít nhất 1 chữ thường.';
    }

    if (!preg_match('/[0-9]/', $password)) {
        $errors[] =
            'Mật khẩu mới phải có ít nhất 1 chữ số.';
    }

    if (!preg_match('/[^A-Za-z0-9\s]/', $password)) {
        $errors[] =
            'Mật khẩu mới phải có ít nhất 1 ký tự đặc biệt.';
    }

    if (preg_match('/\s/', $password)) {
        $errors[] =
            'Mật khẩu mới không được chứa khoảng trắng.';
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
            'Mật khẩu mới quá phổ biến. Hãy chọn mật khẩu khó đoán hơn.';
    }

    /* -----------------------------------------------------
       Không cho mật khẩu chứa phần tên trước @ của email
    ------------------------------------------------------ */
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

    /* -----------------------------------------------------
       Không cho mật khẩu chứa từ tên admin dài >= 4 ký tự
    ------------------------------------------------------ */
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
   CSRF
========================================================= */
$csrfKey = 'csrf_admin_change_password';

if (empty($_SESSION[$csrfKey])) {
    $_SESSION[$csrfKey] =
        bin2hex(random_bytes(32));
}

$csrfToken =
    (string)$_SESSION[$csrfKey];

$errors = [];
$success = '';

/* =========================================================
   LẤY THÔNG TIN ADMIN
========================================================= */
$stmtAdmin = $conn->prepare("
    SELECT
        id,
        ho_ten,
        email,
        mat_khau,
        trang_thai
    FROM nguoi_dung
    WHERE id = ?
    LIMIT 1
");

if (!$stmtAdmin) {
    die(
        "Không thể tải tài khoản admin: " .
        htmlspecialchars(
            $conn->error,
            ENT_QUOTES,
            "UTF-8"
        )
    );
}

$stmtAdmin->bind_param(
    "i",
    $adminId
);

$stmtAdmin->execute();

$admin = $stmtAdmin
    ->get_result()
    ->fetch_assoc();

if (!$admin) {
    die("Không tìm thấy tài khoản admin.");
}

if ((int)$admin['trang_thai'] !== 1) {
    die("Tài khoản admin hiện đang bị khóa.");
}

/* =========================================================
   XỬ LÝ ĐỔI MẬT KHẨU
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matKhauCu =
        (string)($_POST['mat_khau_cu'] ?? '');

    $matKhauMoi =
        (string)($_POST['mat_khau_moi'] ?? '');

    $xacNhan =
        (string)($_POST['xac_nhan'] ?? '');

    $csrfGuiLen =
        (string)($_POST['csrf_token'] ?? '');

    /* -----------------------------------------------------
       KIỂM TRA CSRF
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
       KIỂM TRA MẬT KHẨU HIỆN TẠI
    ------------------------------------------------------ */
    if ($matKhauCu === '') {
        $errors[] =
            'Vui lòng nhập mật khẩu hiện tại.';
    } elseif (
        !passwordMatchesStored(
            $matKhauCu,
            (string)$admin['mat_khau']
        )
    ) {
        $errors[] =
            'Mật khẩu hiện tại không chính xác.';
    }

    /* -----------------------------------------------------
       VALIDATE MẬT KHẨU MỚI
    ------------------------------------------------------ */
    $errors = array_merge(
        $errors,
        validateStrongPassword(
            $matKhauMoi,
            (string)($admin['email'] ?? ''),
            (string)($admin['ho_ten'] ?? '')
        )
    );

    if ($matKhauMoi !== $xacNhan) {
        $errors[] =
            'Xác nhận mật khẩu mới không khớp.';
    }

    if (
        $matKhauMoi !== '' &&
        passwordMatchesStored(
            $matKhauMoi,
            (string)$admin['mat_khau']
        )
    ) {
        $errors[] =
            'Mật khẩu mới không được trùng mật khẩu hiện tại.';
    }

    $errors = array_values(
        array_unique($errors)
    );

    /* -----------------------------------------------------
       CẬP NHẬT MẬT KHẨU
    ------------------------------------------------------ */
    if (empty($errors)) {
        $matKhauHash = password_hash(
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
                'Không thể chuẩn bị cập nhật mật khẩu.';
        } else {
            $stmtUpdate->bind_param(
                "si",
                $matKhauHash,
                $adminId
            );

            if ($stmtUpdate->execute()) {
                $success =
                    'Đổi mật khẩu admin thành công.';

                /*
                    Cập nhật lại hash trong request hiện tại
                    để nếu submit lần nữa vẫn kiểm tra đúng.
                */
                $admin['mat_khau'] =
                    $matKhauHash;

                $_SESSION[$csrfKey] =
                    bin2hex(random_bytes(32));

                $csrfToken =
                    (string)$_SESSION[$csrfKey];
            } else {
                $errors[] =
                    'Có lỗi xảy ra khi đổi mật khẩu.';
            }
        }
    }
}
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
.admin-password-page {
    min-height: 72vh;
    padding: 46px 0;
    background:
        radial-gradient(
            circle at 12% 12%,
            rgba(238, 77, 45, .08),
            transparent 25%
        ),
        #f6f7f9;
}

.admin-password-card {
    overflow: hidden;
    border: 1px solid #ececec;
    border-radius: 23px;
    background: #fff;
    box-shadow: 0 16px 42px rgba(0, 0, 0, .07);
}

.admin-password-head {
    padding: 30px;
    background:
        radial-gradient(
            circle at 90% 10%,
            rgba(238, 77, 45, .30),
            transparent 28%
        ),
        linear-gradient(
            135deg,
            #151515,
            #262626
        );
    color: #fff;
}

.password-icon {
    width: 56px;
    height: 56px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 16px;
    background: rgba(255, 255, 255, .10);
    color: #ff7354;
    font-size: 25px;
}

.admin-password-title {
    margin-top: 14px;
    font-size: 25px;
    font-weight: 850;
}

.admin-password-email {
    margin-top: 5px;
    color: rgba(255, 255, 255, .68);
    font-size: 13px;
}

.admin-password-body {
    padding: 30px;
}

.password-rules {
    margin-bottom: 23px;
    padding: 14px 15px;
    border: 1px solid #ffe0d7;
    border-radius: 12px;
    background: #fff7f3;
    color: #72564e;
    font-size: 12px;
    line-height: 1.7;
}

.form-label {
    color: #444;
    font-size: 13px;
    font-weight: 750;
}

.password-wrap {
    position: relative;
}

.password-wrap .form-control {
    min-height: 51px;
    padding-right: 50px;
    border-radius: 10px;
    box-shadow: none !important;
}

.password-wrap .form-control:focus {
    border-color: #ee4d2d;
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

.error-list {
    border: 0;
    border-radius: 12px;
    font-size: 13px;
}

.error-list ul {
    margin-bottom: 0;
    padding-left: 20px;
}

.success-alert {
    border: 0;
    border-radius: 12px;
    font-size: 13px;
}

.change-password-btn {
    min-height: 51px;
    border: 0;
    border-radius: 11px;
    background: linear-gradient(
        135deg,
        #ee4d2d,
        #ff6a47
    );
    font-weight: 800;
    box-shadow: 0 10px 25px rgba(238, 77, 45, .20);
}

.change-password-btn:hover {
    background: linear-gradient(
        135deg,
        #df4528,
        #f65e3b
    );
}

.back-link {
    color: #666;
    font-size: 13px;
    font-weight: 700;
    text-decoration: none;
}

.back-link:hover {
    color: #ee4d2d;
}

@media (max-width: 575px) {
    .admin-password-page {
        padding: 25px 0;
    }

    .admin-password-head,
    .admin-password-body {
        padding: 24px 20px;
    }
}
</style>

<div class="admin-password-page">
    <div class="container">

        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">

                <div class="admin-password-card">

                    <div class="admin-password-head">

                        <div class="password-icon">
                            <i class="bi bi-shield-lock"></i>
                        </div>

                        <div class="admin-password-title">
                            Đổi mật khẩu admin
                        </div>

                        <div class="admin-password-email">
                            <?= htmlspecialchars(
                                (string)($admin['email'] ?? '')
                            ) ?>
                        </div>

                    </div>

                    <div class="admin-password-body">

                        <?php if ($success !== ''): ?>
                            <div class="alert alert-success success-alert">
                                <i class="bi bi-check-circle me-1"></i>
                                <?= htmlspecialchars($success) ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger error-list">
                                <strong>
                                    Không thể đổi mật khẩu:
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

                        <div class="password-rules">
                            <i class="bi bi-info-circle me-1"></i>

                            Mật khẩu mới phải có
                            <strong>8–72 ký tự</strong>,
                            ít nhất 1 chữ hoa, 1 chữ thường,
                            1 chữ số và 1 ký tự đặc biệt.

                            <br>

                            Không được chứa khoảng trắng,
                            không được trùng mật khẩu hiện tại
                            và không nên chứa tên hoặc tên tài khoản email.
                        </div>

                        <form
                            method="POST"
                            id="changePasswordForm"
                            novalidate
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($csrfToken) ?>"
                            >

                            <!-- MẬT KHẨU HIỆN TẠI -->
                            <div class="mb-3">

                                <label class="form-label">
                                    Mật khẩu hiện tại
                                </label>

                                <div class="password-wrap">

                                    <input
                                        type="password"
                                        name="mat_khau_cu"
                                        id="currentPassword"
                                        class="form-control"
                                        maxlength="72"
                                        autocomplete="current-password"
                                        placeholder="Nhập mật khẩu hiện tại"
                                        required
                                    >

                                    <button
                                        type="button"
                                        class="password-eye"
                                        data-password="currentPassword"
                                        aria-label="Hiện hoặc ẩn mật khẩu"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </button>

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
                                        placeholder="Ví dụ: Admin@2026"
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
                            </div>

                            <!-- XÁC NHẬN -->
                            <div class="mb-4">

                                <label class="form-label">
                                    Xác nhận mật khẩu mới
                                </label>

                                <div class="password-wrap">

                                    <input
                                        type="password"
                                        name="xac_nhan"
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
                                class="btn btn-danger change-password-btn w-100"
                            >
                                <i class="bi bi-key me-1"></i>
                                Đổi mật khẩu
                            </button>

                        </form>

                        <div class="text-center mt-4">
                            <a
                                href="thong_tin.php"
                                class="back-link"
                            >
                                <i class="bi bi-arrow-left me-1"></i>
                                Quay lại thông tin tài khoản
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
   XÁC NHẬN MẬT KHẨU PHÍA CLIENT
========================================================= */
const form =
    document.getElementById('changePasswordForm');

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

<?php require_once "../../includes/footer.php"; ?>

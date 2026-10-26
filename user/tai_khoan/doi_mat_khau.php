<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";

/* =========================================================
   HÀM HỖ TRỢ - FILE TỰ HOẠT ĐỘNG
   KHÔNG CẦN account_validation.php
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

$userId = (int)($_SESSION['user']['id'] ?? 0);

if ($userId <= 0) {
    header("Location: ../../auth/login.php");
    exit;
}

$csrfKey = 'csrf_user_change_password';
$csrfToken = createCsrfToken($csrfKey);

$errors = [];
$success = '';

$stmt = $conn->prepare("
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

if (!$stmt) {
    die("Không thể tải tài khoản.");
}

$stmt->bind_param("i", $userId);
$stmt->execute();

$user = $stmt
    ->get_result()
    ->fetch_assoc();

if (!$user) {
    die("Không tìm thấy tài khoản.");
}

if ((int)$user['trang_thai'] !== 1) {
    die("Tài khoản hiện đang bị khóa.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matKhauCu = (string)($_POST['mat_khau_cu'] ?? '');
    $matKhauMoi = (string)($_POST['mat_khau_moi'] ?? '');
    $xacNhan = (string)($_POST['xac_nhan'] ?? '');
    $csrfGuiLen = (string)($_POST['csrf_token'] ?? '');

    if (!csrfIsValid($csrfKey, $csrfGuiLen)) {
        $errors[] = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
    }

    if ($matKhauCu === '') {
        $errors[] = 'Vui lòng nhập mật khẩu hiện tại.';
    } elseif (
        !passwordMatchesStored(
            $matKhauCu,
            (string)$user['mat_khau']
        )
    ) {
        $errors[] = 'Mật khẩu hiện tại không chính xác.';
    }

    $errors = array_merge(
        $errors,
        validateStrongPassword(
            $matKhauMoi,
            (string)$user['email'],
            (string)$user['ho_ten']
        )
    );

    if ($matKhauMoi !== $xacNhan) {
        $errors[] = 'Xác nhận mật khẩu mới không khớp.';
    }

    if (
        $matKhauMoi !== '' &&
        passwordMatchesStored(
            $matKhauMoi,
            (string)$user['mat_khau']
        )
    ) {
        $errors[] = 'Mật khẩu mới không được trùng mật khẩu hiện tại.';
    }

    $errors = array_values(array_unique($errors));

    if (empty($errors)) {
        $hash = password_hash(
            $matKhauMoi,
            PASSWORD_DEFAULT
        );

        $stmtUpdate = $conn->prepare("
            UPDATE nguoi_dung
            SET mat_khau = ?
            WHERE id = ?
        ");

        if (!$stmtUpdate) {
            $errors[] = 'Không thể cập nhật mật khẩu.';
        } else {
            $stmtUpdate->bind_param(
                "si",
                $hash,
                $userId
            );

            if ($stmtUpdate->execute()) {
                $user['mat_khau'] = $hash;
                $success = 'Đổi mật khẩu thành công.';

                $_SESSION[$csrfKey] =
                    bin2hex(random_bytes(32));

                $csrfToken =
                    (string)$_SESSION[$csrfKey];
            } else {
                $errors[] = 'Có lỗi xảy ra khi đổi mật khẩu.';
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
.secure-page {
    min-height: 70vh;
    padding: 45px 0;
    background: #f6f7f9;
}

.secure-card {
    overflow: hidden;
    border: 1px solid #ececec;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 14px 38px rgba(0,0,0,.06);
}

.secure-head {
    padding: 28px 30px;
    background:
        radial-gradient(circle at 90% 10%, rgba(238,77,45,.30), transparent 28%),
        linear-gradient(135deg,#151515,#262626);
    color: #fff;
}

.secure-head h4 {
    margin: 13px 0 3px;
    font-weight: 850;
}

.secure-head .small {
    color: rgba(255,255,255,.7);
}

.secure-body {
    padding: 30px;
}

.password-wrap {
    position: relative;
}

.password-wrap .form-control {
    min-height: 50px;
    padding-right: 50px;
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

.rules {
    margin-bottom: 22px;
    padding: 13px 14px;
    border: 1px solid #ffe0d7;
    border-radius: 11px;
    background: #fff7f3;
    color: #72564e;
    font-size: 12px;
    line-height: 1.65;
}

.error-list {
    border: 0;
    border-radius: 11px;
}

.error-list ul {
    margin-bottom: 0;
    padding-left: 20px;
}

.submit-btn {
    min-height: 50px;
    border: 0;
    border-radius: 11px;
    background: linear-gradient(135deg,#ee4d2d,#ff6a47);
    font-weight: 800;
}
</style>

<div class="secure-page">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">

                <div class="secure-card">

                    <div class="secure-head">
                        <i class="bi bi-shield-lock fs-2"></i>

                        <h4>
                            Đổi mật khẩu
                        </h4>

                        <div class="small">
                            <?= htmlspecialchars(
                                (string)$user['email']
                            ) ?>
                        </div>
                    </div>

                    <div class="secure-body">

                        <?php if ($success !== ''): ?>
                            <div class="alert alert-success border-0">
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

                        <div class="rules">
                            Mật khẩu mới phải có:
                            <strong>8–72 ký tự</strong>,
                            ít nhất 1 chữ hoa, 1 chữ thường,
                            1 số và 1 ký tự đặc biệt.
                            Không được chứa khoảng trắng,
                            tên hoặc tên tài khoản email.
                        </div>

                        <form method="POST">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($csrfToken) ?>"
                            >

                            <?php
                            $fields = [
                                [
                                    'name' => 'mat_khau_cu',
                                    'id' => 'currentPassword',
                                    'label' => 'Mật khẩu hiện tại',
                                    'autocomplete' => 'current-password'
                                ],
                                [
                                    'name' => 'mat_khau_moi',
                                    'id' => 'newPassword',
                                    'label' => 'Mật khẩu mới',
                                    'autocomplete' => 'new-password'
                                ],
                                [
                                    'name' => 'xac_nhan',
                                    'id' => 'confirmPassword',
                                    'label' => 'Xác nhận mật khẩu mới',
                                    'autocomplete' => 'new-password'
                                ]
                            ];
                            ?>

                            <?php foreach ($fields as $field): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold">
                                        <?= htmlspecialchars($field['label']) ?>
                                    </label>

                                    <div class="password-wrap">
                                        <input
                                            type="password"
                                            name="<?= htmlspecialchars($field['name']) ?>"
                                            id="<?= htmlspecialchars($field['id']) ?>"
                                            class="form-control"
                                            minlength="<?= $field['name'] === 'mat_khau_cu' ? 1 : 8 ?>"
                                            maxlength="72"
                                            autocomplete="<?= htmlspecialchars($field['autocomplete']) ?>"
                                            required
                                        >

                                        <button
                                            type="button"
                                            class="password-eye"
                                            data-password="<?= htmlspecialchars($field['id']) ?>"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <button
                                type="submit"
                                class="btn btn-danger submit-btn w-100"
                            >
                                Đổi mật khẩu
                            </button>

                        </form>

                        <div class="text-center mt-4">
                            <a
                                href="thong_tin.php"
                                class="text-decoration-none"
                            >
                                ← Quay lại thông tin tài khoản
                            </a>
                        </div>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
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
</script>

<?php require_once "../../includes/footer.php"; ?>

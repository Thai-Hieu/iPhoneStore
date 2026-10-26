<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";

/* =========================================================
   HÀM VALIDATE DÙNG RIÊNG CHO TRANG THÔNG TIN USER

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

/* =========================================================
   USER HIỆN TẠI
========================================================= */
$userId = (int)($_SESSION['user']['id'] ?? 0);

if ($userId <= 0) {
    header("Location: ../../auth/login.php");
    exit;
}

/* =========================================================
   CSRF
========================================================= */
$csrfKey = 'csrf_user_profile_update';
$csrfToken = createCsrfToken($csrfKey);

/* =========================================================
   HÀM VALIDATE ĐỊA CHỈ
========================================================= */
function validateAddress(string $address): array
{
    $errors = [];
    $length = textLength($address);

    if ($address === '') {
        return $errors;
    }

    if ($length < 5) {
        $errors[] = 'Địa chỉ phải có ít nhất 5 ký tự nếu được nhập.';
    }

    if ($length > 500) {
        $errors[] = 'Địa chỉ không được vượt quá 500 ký tự.';
    }

    return $errors;
}

/* =========================================================
   LẤY THÔNG TIN USER
========================================================= */
$stmtUser = $conn->prepare("
    SELECT
        nd.id,
        nd.ho_ten,
        nd.email,
        nd.mat_khau,
        nd.so_dien_thoai,
        nd.dia_chi,
        nd.trang_thai,
        nd.ngay_tao,
        vt.vai_tro
    FROM nguoi_dung nd
    JOIN vai_tro vt
        ON vt.id = nd.vai_tro_id
    WHERE nd.id = ?
    LIMIT 1
");

if (!$stmtUser) {
    die("Không thể tải thông tin tài khoản.");
}

$stmtUser->bind_param("i", $userId);
$stmtUser->execute();

$user = $stmtUser
    ->get_result()
    ->fetch_assoc();

if (!$user) {
    die("Không tìm thấy tài khoản.");
}

if ((int)$user['trang_thai'] !== 1) {
    die("Tài khoản hiện đang bị khóa.");
}

/* =========================================================
   DỮ LIỆU FORM BAN ĐẦU
========================================================= */
$hoTen = (string)($user['ho_ten'] ?? '');
$email = (string)($user['email'] ?? '');
$soDienThoai = (string)($user['so_dien_thoai'] ?? '');
$diaChi = (string)($user['dia_chi'] ?? '');

$errors = [];
$success = '';

/* =========================================================
   XỬ LÝ CẬP NHẬT
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hoTen = normalizeFullName(
        (string)($_POST['ho_ten'] ?? '')
    );

    $email = normalizeEmailAddress(
        (string)($_POST['email'] ?? '')
    );

    $soDienThoai = normalizeVietnamPhone(
        (string)($_POST['so_dien_thoai'] ?? '')
    );

    $diaChi = trim(
        (string)($_POST['dia_chi'] ?? '')
    );

    $matKhauXacNhan = (string)(
        $_POST['mat_khau_xac_nhan'] ?? ''
    );

    $csrfGuiLen = (string)(
        $_POST['csrf_token'] ?? ''
    );

    /* -----------------------------------------------------
       VALIDATE CƠ BẢN
    ------------------------------------------------------ */
    if (!csrfIsValid($csrfKey, $csrfGuiLen)) {
        $errors[] =
            'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
    }

    $errors = array_merge(
        $errors,
        validateFullName($hoTen),
        validateEmailAddress($email),
        validateVietnamPhone($soDienThoai),
        validateAddress($diaChi)
    );

    /* -----------------------------------------------------
       EMAIL / SĐT LÀ THÔNG TIN NHẠY CẢM
       Nếu thay đổi thì yêu cầu nhập mật khẩu hiện tại.
    ------------------------------------------------------ */
    $emailCu = normalizeEmailAddress(
        (string)($user['email'] ?? '')
    );

    $soDienThoaiCu = normalizeVietnamPhone(
        (string)($user['so_dien_thoai'] ?? '')
    );

    $thayDoiThongTinBaoMat =
        $email !== $emailCu ||
        $soDienThoai !== $soDienThoaiCu;

    if ($thayDoiThongTinBaoMat) {
        if ($matKhauXacNhan === '') {
            $errors[] =
                'Bạn phải nhập mật khẩu hiện tại khi thay đổi email hoặc số điện thoại.';
        } elseif (
            !passwordMatchesStored(
                $matKhauXacNhan,
                (string)$user['mat_khau']
            )
        ) {
            $errors[] =
                'Mật khẩu hiện tại không chính xác.';
        }
    }

    /* -----------------------------------------------------
       KIỂM TRA EMAIL / SĐT TRÙNG TÀI KHOẢN KHÁC
    ------------------------------------------------------ */
    if (empty($errors)) {
        $stmtCheck = $conn->prepare("
            SELECT
                id,
                email,
                so_dien_thoai
            FROM nguoi_dung
            WHERE id <> ?
              AND (
                    email = ?
                    OR so_dien_thoai = ?
              )
            LIMIT 1
        ");

        if (!$stmtCheck) {
            $errors[] =
                'Không thể kiểm tra thông tin tài khoản lúc này.';
        } else {
            $stmtCheck->bind_param(
                "iss",
                $userId,
                $email,
                $soDienThoai
            );

            $stmtCheck->execute();

            $existing = $stmtCheck
                ->get_result()
                ->fetch_assoc();

            if ($existing) {
                if (
                    normalizeEmailAddress(
                        (string)($existing['email'] ?? '')
                    ) === $email
                ) {
                    $errors[] =
                        'Email này đã được sử dụng bởi tài khoản khác.';
                }

                if (
                    normalizeVietnamPhone(
                        (string)($existing['so_dien_thoai'] ?? '')
                    ) === $soDienThoai
                ) {
                    $errors[] =
                        'Số điện thoại này đã được sử dụng bởi tài khoản khác.';
                }
            }
        }
    }

    $errors = array_values(
        array_unique($errors)
    );

    /* -----------------------------------------------------
       CẬP NHẬT
    ------------------------------------------------------ */
    if (empty($errors)) {
        $stmtUpdate = $conn->prepare("
            UPDATE nguoi_dung
            SET
                ho_ten = ?,
                email = ?,
                so_dien_thoai = ?,
                dia_chi = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$stmtUpdate) {
            $errors[] =
                'Không thể chuẩn bị cập nhật thông tin.';
        } else {
            $stmtUpdate->bind_param(
                "ssssi",
                $hoTen,
                $email,
                $soDienThoai,
                $diaChi,
                $userId
            );

            if ($stmtUpdate->execute()) {
                /* -----------------------------------------
                   Đồng bộ session để navbar cập nhật ngay.
                ------------------------------------------ */
                $_SESSION['user']['ho_ten'] = $hoTen;
                $_SESSION['user']['email'] = $email;

                $success =
                    'Cập nhật thông tin tài khoản thành công.';

                /* -----------------------------------------
                   Tạo CSRF mới sau khi submit thành công.
                ------------------------------------------ */
                $_SESSION[$csrfKey] =
                    bin2hex(random_bytes(32));

                $csrfToken =
                    (string)$_SESSION[$csrfKey];

                /* -----------------------------------------
                   Load lại dữ liệu mới nhất.
                ------------------------------------------ */
                $stmtUser->execute();

                $user = $stmtUser
                    ->get_result()
                    ->fetch_assoc();

                $hoTen = (string)($user['ho_ten'] ?? '');
                $email = (string)($user['email'] ?? '');
                $soDienThoai =
                    (string)($user['so_dien_thoai'] ?? '');
                $diaChi =
                    (string)($user['dia_chi'] ?? '');
            } else {
                $errors[] =
                    'Có lỗi xảy ra khi cập nhật thông tin.';
            }
        }
    }
}

/* =========================================================
   CHỮ CÁI AVATAR
========================================================= */
$avatarText = '';

foreach (
    preg_split(
        '/\s+/u',
        trim((string)($user['ho_ten'] ?? ''))
    ) ?: []
    as $word
) {
    if ($word !== '') {
        $avatarText .= function_exists('mb_substr')
            ? mb_substr($word, 0, 1, 'UTF-8')
            : substr($word, 0, 1);
    }
}

if ($avatarText === '') {
    $avatarText = 'U';
}

if (function_exists('mb_substr')) {
    $avatarText =
        mb_substr($avatarText, 0, 2, 'UTF-8');
} else {
    $avatarText =
        substr($avatarText, 0, 2);
}
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
>

<style>
.profile-page {
    min-height: 72vh;
    padding: 45px 0;
    background:
        radial-gradient(
            circle at 10% 10%,
            rgba(238, 77, 45, .08),
            transparent 24%
        ),
        #f6f7f9;
}

.profile-shell {
    overflow: hidden;
    border: 1px solid #ececec;
    border-radius: 24px;
    background: #fff;
    box-shadow: 0 16px 42px rgba(0, 0, 0, .07);
}

.profile-sidebar {
    height: 100%;
    padding: 32px 28px;
    background:
        radial-gradient(
            circle at 85% 12%,
            rgba(238, 77, 45, .30),
            transparent 28%
        ),
        linear-gradient(
            145deg,
            #151515,
            #252525 58%,
            #321810
        );
    color: #fff;
}

.profile-avatar {
    width: 86px;
    height: 86px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 17px;
    border: 2px solid rgba(255, 255, 255, .18);
    border-radius: 24px;
    background: rgba(255, 255, 255, .10);
    font-size: 27px;
    font-weight: 850;
    text-transform: uppercase;
}

.profile-name {
    font-size: 23px;
    font-weight: 850;
}

.profile-email {
    margin-top: 4px;
    color: rgba(255, 255, 255, .65);
    font-size: 13px;
    word-break: break-all;
}

.profile-meta {
    display: grid;
    gap: 11px;
    margin-top: 27px;
}

.meta-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: rgba(255, 255, 255, .80);
    font-size: 13px;
}

.meta-item i {
    width: 29px;
    height: 29px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: rgba(255, 255, 255, .08);
    color: #ff7556;
}

.profile-side-actions {
    display: grid;
    gap: 9px;
    margin-top: 28px;
}

.profile-side-actions .btn {
    border-radius: 10px;
    font-weight: 700;
}

.profile-content {
    padding: 34px;
}

.profile-title {
    margin-bottom: 5px;
    color: #171717;
    font-size: 27px;
    font-weight: 850;
}

.profile-subtitle {
    margin-bottom: 27px;
    color: #777;
    font-size: 13px;
}

.section-title {
    margin: 4px 0 17px;
    color: #333;
    font-size: 15px;
    font-weight: 800;
}

.form-label {
    color: #444;
    font-size: 13px;
    font-weight: 750;
}

.form-control,
.form-select {
    min-height: 50px;
    border-radius: 10px;
    box-shadow: none !important;
}

textarea.form-control {
    min-height: 110px;
    resize: vertical;
}

.form-control:focus {
    border-color: #ee4d2d;
}

.input-hint {
    margin-top: 6px;
    color: #999;
    font-size: 11px;
    line-height: 1.5;
}

.security-box {
    margin-top: 8px;
    padding: 14px 15px;
    border: 1px solid #ffe1d8;
    border-radius: 12px;
    background: #fff8f5;
    color: #72564e;
    font-size: 12px;
    line-height: 1.6;
}

.profile-alert {
    border: 0;
    border-radius: 12px;
    font-size: 13px;
}

.error-list ul {
    margin-bottom: 0;
    padding-left: 20px;
}

.save-btn {
    min-height: 50px;
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

.save-btn:hover {
    background: linear-gradient(
        135deg,
        #df4528,
        #f65e3b
    );
}

.readonly-box {
    min-height: 50px;
    display: flex;
    align-items: center;
    padding: 0 14px;
    border: 1px solid #e6e6e6;
    border-radius: 10px;
    background: #f8f9fa;
    color: #666;
    font-size: 14px;
}

@media (max-width: 991px) {
    .profile-sidebar,
    .profile-content {
        padding: 28px 23px;
    }
}
</style>

<div class="profile-page">
    <div class="container">

        <div class="row justify-content-center">
            <div class="col-xl-10 col-lg-11">

                <div class="profile-shell">
                    <div class="row g-0">

                        <!-- ===============================
                             SIDEBAR TÀI KHOẢN
                        ================================ -->
                        <div class="col-lg-4">
                            <div class="profile-sidebar">

                                <div class="profile-avatar">
                                    <?= htmlspecialchars($avatarText) ?>
                                </div>

                                <div class="profile-name">
                                    <?= htmlspecialchars(
                                        (string)($user['ho_ten'] ?? '')
                                    ) ?>
                                </div>

                                <div class="profile-email">
                                    <?= htmlspecialchars(
                                        (string)($user['email'] ?? '')
                                    ) ?>
                                </div>

                                <div class="profile-meta">

                                    <div class="meta-item">
                                        <i class="bi bi-person-badge"></i>

                                        <span>
                                            Vai trò:
                                            <strong>
                                                Người mua
                                            </strong>
                                        </span>
                                    </div>

                                    <div class="meta-item">
                                        <i class="bi bi-check-circle"></i>

                                        <span>
                                            Trạng thái:
                                            <strong>
                                                Đang hoạt động
                                            </strong>
                                        </span>
                                    </div>

                                    <div class="meta-item">
                                        <i class="bi bi-calendar3"></i>

                                        <span>
                                            Tham gia:
                                            <strong>
                                                <?= !empty($user['ngay_tao'])
                                                    ? date(
                                                        'd/m/Y',
                                                        strtotime(
                                                            $user['ngay_tao']
                                                        )
                                                    )
                                                    : '-' ?>
                                            </strong>
                                        </span>
                                    </div>

                                </div>

                                <div class="profile-side-actions">

                                    <a
                                        href="doi_mat_khau.php"
                                        class="btn btn-light"
                                    >
                                        <i class="bi bi-key me-1"></i>
                                        Đổi mật khẩu
                                    </a>

                                    <a
                                        href="../don_hang/don_hang_da_dat.php"
                                        class="btn btn-outline-light"
                                    >
                                        <i class="bi bi-box-seam me-1"></i>
                                        Đơn hàng của tôi
                                    </a>

                                </div>

                            </div>
                        </div>

                        <!-- ===============================
                             FORM THÔNG TIN
                        ================================ -->
                        <div class="col-lg-8">
                            <div class="profile-content">

                                <div class="profile-title">
                                    Thông tin cá nhân
                                </div>

                                <div class="profile-subtitle">
                                    Quản lý thông tin dùng cho tài khoản
                                    và quá trình mua hàng của bạn.
                                </div>

                                <?php if ($success !== ''): ?>
                                    <div class="alert alert-success profile-alert">
                                        <i class="bi bi-check-circle me-1"></i>
                                        <?= htmlspecialchars($success) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($errors)): ?>
                                    <div class="alert alert-danger profile-alert error-list">
                                        <strong>
                                            Không thể cập nhật:
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
                                    id="profileForm"
                                    novalidate
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= htmlspecialchars($csrfToken) ?>"
                                    >

                                    <div class="section-title">
                                        Thông tin cơ bản
                                    </div>

                                    <div class="row">

                                        <div class="col-md-6 mb-3">
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
                                                required
                                            >

                                            <div class="invalid-feedback">
                                                Họ tên phải từ 2 đến 100 ký tự.
                                            </div>
                                        </div>

                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">
                                                Số điện thoại
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
                                                required
                                            >

                                            <div class="invalid-feedback">
                                                Nhập số điện thoại Việt Nam hợp lệ.
                                            </div>
                                        </div>

                                        <div class="col-12 mb-3">
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
                                                required
                                            >

                                            <div class="invalid-feedback">
                                                Vui lòng nhập email hợp lệ.
                                            </div>
                                        </div>

                                        <div class="col-12 mb-3">
                                            <label class="form-label">
                                                Địa chỉ
                                            </label>

                                            <textarea
                                                name="dia_chi"
                                                class="form-control"
                                                maxlength="500"
                                                placeholder="Nhập địa chỉ của bạn"
                                            ><?= htmlspecialchars($diaChi) ?></textarea>

                                            <div class="input-hint">
                                                Tối đa 500 ký tự.
                                            </div>
                                        </div>

                                    </div>

                                    <div class="section-title mt-2">
                                        Bảo mật khi thay đổi thông tin
                                    </div>

                                    <div class="security-box mb-3">
                                        <i class="bi bi-shield-lock me-1"></i>
                                        Nếu bạn thay đổi
                                        <strong>email hoặc số điện thoại</strong>,
                                        hệ thống yêu cầu nhập mật khẩu hiện tại
                                        để xác nhận đúng chủ tài khoản.
                                    </div>

                                    <div class="mb-4">
                                        <label class="form-label">
                                            Mật khẩu hiện tại
                                            <span class="text-muted fw-normal">
                                                (chỉ cần khi đổi email/SĐT)
                                            </span>
                                        </label>

                                        <input
                                            type="password"
                                            name="mat_khau_xac_nhan"
                                            class="form-control"
                                            maxlength="72"
                                            autocomplete="current-password"
                                            placeholder="Nhập mật khẩu để xác nhận thay đổi nhạy cảm"
                                        >
                                    </div>

                                    <div class="row align-items-end">

                                        <div class="col-md-6 mb-3 mb-md-0">
                                            <label class="form-label">
                                                Ngày tham gia
                                            </label>

                                            <div class="readonly-box">
                                                <i class="bi bi-calendar3 me-2"></i>

                                                <?= !empty($user['ngay_tao'])
                                                    ? date(
                                                        'd/m/Y H:i',
                                                        strtotime(
                                                            $user['ngay_tao']
                                                        )
                                                    )
                                                    : 'Chưa cập nhật' ?>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <button
                                                type="submit"
                                                class="btn btn-danger save-btn w-100"
                                            >
                                                <i class="bi bi-floppy me-1"></i>
                                                Lưu thay đổi
                                            </button>
                                        </div>

                                    </div>

                                </form>

                            </div>
                        </div>

                    </div>
                </div>

            </div>
        </div>

    </div>
</div>

<script>
const profileForm =
    document.getElementById('profileForm');

if (profileForm) {
    profileForm.addEventListener(
        'submit',
        function (event) {
            if (!profileForm.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }

            profileForm.classList.add(
                'was-validated'
            );
        }
    );
}
</script>

<?php require_once "../../includes/footer.php"; ?>

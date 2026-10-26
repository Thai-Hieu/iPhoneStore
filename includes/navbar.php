<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================================================
   THÔNG TIN TÀI KHOẢN TRÊN NAVBAR
========================================================= */
$navUser = $_SESSION['user'] ?? null;
$navRole = $navUser['vai_tro'] ?? 'guest';
$navHoTen = $navUser['ho_ten'] ?? '';
$navUserId = (int)($navUser['id'] ?? 0);

$navInitial = 'U';

if (trim((string)$navHoTen) !== '') {
    $navInitial = mb_strtoupper(
        mb_substr(trim((string)$navHoTen), 0, 1, 'UTF-8'),
        'UTF-8'
    );
}

$homeLink = $navRole === 'admin'
    ? '/iPhoneStore/admin/index.php'
    : '/iPhoneStore/user/index.php';

/* =========================================================
   LINK KẾT NỐI CỬA HÀNG
========================================================= */
$zaloLink = '#';
$facebookLink = '#';

/* =========================================================
   TỪ KHÓA TÌM NHANH IPHONE
   Chỉ dùng cho navbar user.
========================================================= */
$iphoneQuickSearches = [
    'iPhone Duo',
    'iPhone 18',
    'iPhone 17',
    'iPhone 16',
    'iPhone 15',
    'iPhone 14',
    'iPhone 13',
    'iPhone 12',
    'iPhone 11',
    'iPhone SE'
];

/* =========================================================
   THÔNG BÁO TRÊN NAVBAR
   User và Admin dùng chung dữ liệu.
========================================================= */
$navNotifications = [];
$navUnreadCount = 0;

$navNotificationPageLink = '';
$navNotificationOpenBase = '';
$navNotificationDeleteAction = '';
$navNotificationCsrf = '';

if ($navUserId > 0 && in_array($navRole, ['user', 'admin'], true)) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once __DIR__ . '/../config/database.php';
    }

    $notificationHelperPath = __DIR__ . '/notification_helper.php';

    if (is_file($notificationHelperPath)) {
        require_once $notificationHelperPath;
    }

    /* =====================================================
       CẢNH BÁO TỰ ĐỘNG
       - Giữ nguyên hệ thống thông báo cũ.
       - Chỉ kích hoạt helper mới tối đa 10 phút/lần / phiên.
       - Khuyến mãi sắp hết:
         gửi website + Toast + email cho user/admin.
       - Tồn kho <= 5:
         gửi website + Toast + email cho admin.
       - Lỗi cảnh báo không được làm hỏng navbar.
    ====================================================== */
    if (function_exists('kiemTraCanhBaoTuDongIphoneStore')) {
        $navAlertCheckInterval = 10 * 60; // 10 phút
        $navAlertLastCheck =
            (int)($_SESSION['iphone_store_alert_last_check'] ?? 0);

        $navNow = time();

        if (
            $navAlertLastCheck <= 0 ||
            ($navNow - $navAlertLastCheck) >= $navAlertCheckInterval
        ) {
            /*
                Ghi thời điểm trước khi chạy để tránh request lặp
                trong cùng phiên nếu helper gặp lỗi.
            */
            $_SESSION['iphone_store_alert_last_check'] = $navNow;

            try {
                kiemTraCanhBaoTuDongIphoneStore(
                    $conn,
                    5 // Ngưỡng "sắp hết hàng": còn <= 5
                );
            } catch (Throwable $e) {
                error_log(
                    'IphoneStore automatic alert error: ' .
                    $e->getMessage()
                );
            }
        }
    }

    if (
        function_exists('demThongBaoChuaDoc') &&
        function_exists('layThongBaoMoi')
    ) {
        if (function_exists('donDepThongBaoTuDong')) {
            donDepThongBaoTuDong($conn, $navUserId);
        }

        /*
            Đếm/lấy sau khi đã kiểm tra cảnh báo để thông báo mới
            xuất hiện ngay trên badge và Toast ở request hiện tại.
        */
        $navUnreadCount = demThongBaoChuaDoc($conn, $navUserId);
        $navNotifications = layThongBaoMoi($conn, $navUserId, 5);
    }

    if (empty($_SESSION['csrf_notification'])) {
        $_SESSION['csrf_notification'] = bin2hex(random_bytes(32));
    }

    $navNotificationCsrf = (string)$_SESSION['csrf_notification'];

    if ($navRole === 'admin') {
        $navNotificationPageLink = '/iPhoneStore/admin/thong_bao/index.php';
        $navNotificationOpenBase = '/iPhoneStore/admin/thong_bao/xem.php?id=';
        $navNotificationDeleteAction = '/iPhoneStore/admin/thong_bao/xoa.php';
    } else {
        $navNotificationPageLink = '/iPhoneStore/user/thong_bao/index.php';
        $navNotificationOpenBase = '/iPhoneStore/user/thong_bao/xem.php?id=';
        $navNotificationDeleteAction = '/iPhoneStore/user/thong_bao/xoa.php';
    }
}

$navNotificationIcons = [
    'dat_hang' => '🛍️',
    'don_moi' => '🧾',
    'da_duyet' => '✅',
    'dang_giao' => '🚚',
    'hoan_thanh' => '📦',
    'huy_don' => '❌',
    'khach_huy_don' => '👤',
    'bao_mat' => '🔐',
    'he_thong' => '🔔',

    /* Cảnh báo tự động mới */
    'khuyen_mai_sap_het_24h' => '⏰',
    'khuyen_mai_sap_het_1h' => '🔥',
    'san_pham_sap_het' => '⚠️',
    'san_pham_het_hang' => '🚨'
];

/* =========================================================
   MINI CART TRÊN NAVBAR
   Chỉ dùng cho user đã đăng nhập.
========================================================= */
$miniCartItems = [];
$miniCartQuantity = 0;
$miniCartTotal = 0.0;
$miniCartTotalRows = 0;

if ($navRole === 'user' && $navUserId > 0) {
    if (!isset($conn) || !($conn instanceof mysqli)) {
        require_once __DIR__ . '/../config/database.php';
    }

    $stmtMiniCartSummary = $conn->prepare("
        SELECT
            COUNT(*) AS tong_dong,
            COALESCE(SUM(so_luong), 0) AS tong_so_luong,
            COALESCE(SUM(so_luong * gia), 0) AS tong_tien
        FROM gio_hang
        WHERE nguoi_dung_id = ?
    ");

    if ($stmtMiniCartSummary) {
        $stmtMiniCartSummary->bind_param("i", $navUserId);
        $stmtMiniCartSummary->execute();

        $miniCartSummary = $stmtMiniCartSummary
            ->get_result()
            ->fetch_assoc();

        $miniCartTotalRows = (int)($miniCartSummary['tong_dong'] ?? 0);
        $miniCartQuantity = (int)($miniCartSummary['tong_so_luong'] ?? 0);
        $miniCartTotal = (float)($miniCartSummary['tong_tien'] ?? 0);
    }

    $stmtMiniCart = $conn->prepare("
        SELECT
            gh.id,
            gh.san_pham_id,
            gh.so_luong,
            gh.gia,
            gh.mau_id,
            gh.bien_the_id,
            sp.ten_san_pham,
            sp.hinh_anh,
            bt.ram,
            bt.rom,
            ms.ten_mau
        FROM gio_hang gh
        JOIN san_pham sp ON sp.id = gh.san_pham_id
        LEFT JOIN san_pham_bien_the bt ON bt.id = gh.bien_the_id
        LEFT JOIN mau_sac ms ON ms.id = gh.mau_id
        WHERE gh.nguoi_dung_id = ?
        ORDER BY gh.id DESC
        LIMIT 5
    ");

    if ($stmtMiniCart) {
        $stmtMiniCart->bind_param("i", $navUserId);
        $stmtMiniCart->execute();

        $miniCartItems = $stmtMiniCart
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);
    }
}

/* =========================================================
   CHỌN GIAO DIỆN NAVBAR
   includes/navbar/
   ├── navbar_desktop_admin.php
   ├── navbar_desktop_user.php
   ├── navbar_mobile_admin.php
   └── navbar_mobile_user.php
========================================================= */
$navbarRoleView = $navRole === 'admin' ? 'admin' : 'user';

require __DIR__ . "/navbar/navbar_desktop_{$navbarRoleView}.php";
require __DIR__ . "/navbar/navbar_mobile_{$navbarRoleView}.php";
// require_once __DIR__ . '/notification_toast.php';

/* =========================================================
   CHỈ HIỂN THỊ TOAST Ở TRANG CHỦ
   - User: /user/index.php
   - Admin: /admin/index.php
========================================================= */
$currentScript = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

$showNotificationToast = in_array(
    $currentScript,
    [
        '/iPhoneStore/user/index.php',
        '/iPhoneStore/admin/index.php'
    ],
    true
);

if ($showNotificationToast) {
    require_once __DIR__ . '/notification_toast.php';
}
?>

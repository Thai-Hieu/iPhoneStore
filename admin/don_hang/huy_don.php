<?php

if (session_status() === PHP_SESSION_NONE) {

    session_start();

}

require_once "../../middleware/check_admin.php";

require_once "../../config/database.php";

require_once "../../includes/notification_helper.php";

mysqli_report(

    MYSQLI_REPORT_ERROR |

    MYSQLI_REPORT_STRICT

);

/* =========================================================
   IphoneStore - HỦY ĐƠN ADMIN

   QUY TẮC:
   1. Admin chỉ được hủy khi đơn còn ở nhóm Chờ xử lý.

   2. Khi hủy:
      - đơn hàng chuyển sang trạng thái huy;
      - lưu lý do hủy, thời gian hủy và người hủy;
      - hoàn lại tồn kho.

   3. Thanh toán là dữ liệu  , được giữ nguyên
      và không chặn thao tác hủy.

   4. Không tạo hoặc cập nhật nghiệp vụ hoàn tiền.
========================================================= */

/* =========================================================

   CHỈ NHẬN POST

========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    http_response_code(405);

    die("Phương thức không hợp lệ.");

}

/* =========================================================

   DỮ LIỆU GỬI LÊN

========================================================= */

$orderId =

    (int)($_POST['id'] ?? 0);

$lyDoHuy =

    trim(

        (string)($_POST['ly_do_huy'] ?? '')

    );

$csrfToken =

    (string)($_POST['csrf_token'] ?? '');

/* =========================================================

   DANH SÁCH LÝ DO HỦY HỢP LỆ

   Phải đồng bộ với admin/don_hang/chi_tiet.php

========================================================= */

$cancelReasons = [

    'Khách hàng yêu cầu hủy đơn',

    'Không thể liên hệ được với khách hàng',

    'Khách hàng muốn đổi sang sản phẩm khác',

    'Khách hàng cung cấp sai thông tin đặt hàng',

    'Sản phẩm đã hết hàng',

    'Phiên bản hoặc màu khách chọn đã hết hàng',

    'Sản phẩm phát hiện lỗi trước khi giao',

    'Đơn hàng bị đặt trùng',

    'Đơn hàng có dấu hiệu bất thường hoặc gian lận',

    'Không thể giao hàng đến khu vực của khách',

    'Có sự cố vận chuyển và không thể tiếp tục giao',

    'Lý do vận hành khác từ cửa hàng',

    'Hiện tại đang là ngày lễ hoặc nghỉ, không thể giao hàng',

    'Khách hàng yêu cầu hủy đơn vì lý do cá nhân'

];

/* =========================================================

   CSRF

========================================================= */

if (

    empty($_SESSION['csrf_admin_cancel_order']) ||

    $csrfToken === '' ||

    !hash_equals(

        (string)$_SESSION['csrf_admin_cancel_order'],

        $csrfToken

    )

) {

    die(

        "Phiên làm việc không hợp lệ. " .

        "Vui lòng quay lại và thử lại."

    );

}

if ($orderId <= 0) {

    die("Đơn hàng không hợp lệ.");

}

if (

    $lyDoHuy === '' ||

    !in_array(

        $lyDoHuy,

        $cancelReasons,

        true

    )

) {

    die("Lý do hủy đơn không hợp lệ.");

}

/* =========================================================

   HELPER

========================================================= */

/* =========================================================
   TRẠNG THÁI ĐƯỢC PHÉP HỦY
========================================================= */

$pendingStatuses = [
    'cho_xu_ly',
    'da_dat',
    'Đã đặt',
    'cho_xac_nhan',
    'Chờ xác nhận'
];

/* =========================================================
   TRANSACTION

========================================================= */

$conn->begin_transaction();

try {

    /* =====================================================

       1. KHÓA ĐƠN HÀNG

    ====================================================== */

    $stmtOrder = $conn->prepare("

        SELECT

            id,

            nguoi_dung_id,

            trang_thai,

            ma_van_don

        FROM don_hang

        WHERE id = ?
        AND trang_thai IN (
            'cho_xu_ly',
            'da_dat',
            'Đã đặt',
            'cho_xac_nhan',
            'Chờ xác nhận'
        )
        LIMIT 1

        FOR UPDATE

    ");

    $stmtOrder->bind_param(

        "i",

        $orderId

    );

    $stmtOrder->execute();

    $order =

        $stmtOrder

            ->get_result()

            ->fetch_assoc();

    if (!$order) {

        throw new Exception(

            "Đơn hàng không tồn tại."

        );

    }

    $nguoiDungId =

        (int)$order['nguoi_dung_id'];

    if ($nguoiDungId <= 0) {

        throw new Exception(

            "Không xác định được khách hàng của đơn hàng."

        );

    }

    $currentStatus =

        trim(

            (string)$order['trang_thai']

        );

    if (
        !in_array(
            $currentStatus,
            $pendingStatuses,
            true
        )
    ) {
        throw new Exception(
            "Chỉ được hủy đơn khi đơn đang ở trạng thái Chờ xử lý."
        );
    }

    /* =====================================================

       2. KHÓA CHI TIẾT ĐƠN ĐỂ HOÀN TỒN

       Việc được phép hủy chỉ phụ thuộc trạng thái đơn hàng.

       Thanh toán trong đồ án là dữ liệu  , không dùng

       để chặn thao tác hủy đơn.

    ====================================================== */

    $stmtDetails = $conn->prepare("

        SELECT

            san_pham_id,

            so_luong,

            bien_the_id

        FROM chi_tiet_don_hang

        WHERE don_hang_id = ?

        FOR UPDATE

    ");

    $stmtDetails->bind_param(

        "i",

        $orderId

    );

    $stmtDetails->execute();

    $items =

        $stmtDetails

            ->get_result()

            ->fetch_all(MYSQLI_ASSOC);

    $variantProductIds = [];

    foreach ($items as $item) {

        $productId =

            (int)$item['san_pham_id'];

        $quantity =

            max(

                0,

                (int)$item['so_luong']

            );

        $variantId =

            (int)($item['bien_the_id'] ?? 0);

        if (

            $productId <= 0 ||

            $quantity <= 0

        ) {

            continue;

        }

        if ($variantId > 0) {

            $stmtRestoreVariant = $conn->prepare("

                UPDATE san_pham_bien_the

                SET so_luong = so_luong + ?

                WHERE id = ?

                AND san_pham_id = ?

                LIMIT 1

            ");

            $stmtRestoreVariant->bind_param(

                "iii",

                $quantity,

                $variantId,

                $productId

            );

            $stmtRestoreVariant->execute();

            if ($stmtRestoreVariant->affected_rows !== 1) {

                throw new Exception(

                    "Không thể hoàn tồn kho biến thể #" .

                    $variantId .

                    "."

                );

            }

            $variantProductIds[$productId] =

                true;

        } else {

            $stmtRestoreProduct = $conn->prepare("

                UPDATE san_pham

                SET so_luong = so_luong + ?

                WHERE id = ?

                LIMIT 1

            ");

            $stmtRestoreProduct->bind_param(

                "ii",

                $quantity,

                $productId

            );

            $stmtRestoreProduct->execute();

            if ($stmtRestoreProduct->affected_rows !== 1) {

                throw new Exception(

                    "Không thể hoàn tồn kho sản phẩm #" .

                    $productId .

                    "."

                );

            }

        }

    }

    /* =====================================================

       3. ĐỒNG BỘ TỒN TỔNG SẢN PHẨM CÓ BIẾN THỂ

    ====================================================== */

    if (!empty($variantProductIds)) {

        $stmtSyncStock = $conn->prepare("

            UPDATE san_pham

            SET so_luong = (

                SELECT COALESCE(

                    SUM(so_luong),

                    0

                )

                FROM san_pham_bien_the

                WHERE san_pham_id = ?

            )

            WHERE id = ?

            LIMIT 1

        ");

        foreach (

            array_keys($variantProductIds)

            as $productId

        ) {

            $productId =

                (int)$productId;

            $stmtSyncStock->bind_param(

                "ii",

                $productId,

                $productId

            );

            $stmtSyncStock->execute();

        }

    }

    /* =====================================================

       4. HỦY ĐƠN

    ====================================================== */

    $newStatus =

        'huy';

    $cancelledBy =

        'admin';

    $stmtUpdate = $conn->prepare("

        UPDATE don_hang

        SET

            trang_thai = ?,

            ly_do_huy = ?,

            ngay_huy = NOW(),

            nguoi_huy = ?

        WHERE id = ?

        LIMIT 1

    ");

    $stmtUpdate->bind_param(

        "sssi",

        $newStatus,

        $lyDoHuy,

        $cancelledBy,

        $orderId

    );

    $stmtUpdate->execute();

    if ($stmtUpdate->affected_rows !== 1) {

        throw new Exception(

            "Không thể hủy đơn hàng. Trạng thái đơn có thể đã thay đổi."

        );

    }

    /* =====================================================
       5. THANH TOÁN KHÔNG THAM GIA NGHIỆP VỤ HỦY

       Thanh toán là dữ liệu   và được giữ nguyên.
       Quyền hủy chỉ dựa trên trạng thái đơn hàng.
    ====================================================== */

    /* =====================================================

       6. COMMIT NGHIỆP VỤ CỐT LÕI

    ====================================================== */

    $conn->commit();

    $_SESSION['csrf_admin_cancel_order'] =

        bin2hex(

            random_bytes(32)

        );

    /* =====================================================

       7. THÔNG BÁO SAU COMMIT

    ====================================================== */

    try {

        /*

            Thông báo lý do hủy chuẩn.

        */

        thongBaoDonBiHuy(

            $conn,

            $nguoiDungId,

            $orderId,

            'admin',

            $lyDoHuy

        );

    } catch (Throwable $notificationError) {

        error_log(

            'IphoneStore admin cancel notification error, order #' .

            $orderId .

            ': ' .

            $notificationError->getMessage()

        );

    }

    /* =====================================================

       8. QUAY LẠI CHI TIẾT ĐƠN

    ====================================================== */

    $query = [

        'id' => $orderId,

        'cancel' => 'success'

    ];

    header(

        'Location: /iPhoneStore/admin/don_hang/chi_tiet.php?' .

        http_build_query($query)

    );

    exit;

} catch (Throwable $e) {

    try {

        $conn->rollback();

    } catch (Throwable $rollbackError) {

        error_log(

            'IphoneStore admin cancel rollback error, order #' .

            $orderId .

            ': ' .

            $rollbackError->getMessage()

        );

    }

    die(

        "Không thể hủy đơn hàng: " .

        htmlspecialchars(

            $e->getMessage(),

            ENT_QUOTES,

            "UTF-8"

        )

    );

}

?>

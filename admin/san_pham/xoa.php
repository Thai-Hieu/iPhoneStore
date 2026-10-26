<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

/* =========================================================
   HỖ TRỢ BẢNG ẢNH BIẾN THỂ

   Hỗ trợ cả hai tên cột đã từng dùng:
   - hinh_anh
   - duong_dan_anh
========================================================= */
function coBangAnhBienThe(
    mysqli $conn
): bool {
    $stmt = $conn->prepare("
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'san_pham_bien_the_hinh_anh'
        LIMIT 1
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->execute();

    return (bool)$stmt
        ->get_result()
        ->fetch_assoc();
}

function layCotAnhBienThe(
    mysqli $conn
): ?string {
    foreach (
        [
            'hinh_anh',
            'duong_dan_anh'
        ]
        as $columnName
    ) {
        $stmt = $conn->prepare("
            SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'san_pham_bien_the_hinh_anh'
            AND COLUMN_NAME = ?
            LIMIT 1
        ");

        if (!$stmt) {
            continue;
        }

        $stmt->bind_param(
            "s",
            $columnName
        );

        $stmt->execute();

        if (
            $stmt
                ->get_result()
                ->fetch_assoc()
        ) {
            return $columnName;
        }
    }

    return null;
}

/* =========================================================
   CHUẨN HÓA ĐƯỜNG DẪN ẢNH TRONG uploads/

   Database hiện có thể lưu:
   - iphone.jpg
   - bien_the/iphone.jpg
   - uploads/bien_the/iphone.jpg
   - /iPhoneStore/uploads/bien_the/iphone.jpg

   Hàm này chỉ cho phép xóa file nằm bên trong thư mục uploads,
   tránh trường hợp đường dẫn chứa ../ đi ra ngoài thư mục dự án.
========================================================= */
function chuanHoaDuongDanAnhUpload(
    string $imagePath
): ?string {
    $imagePath =
        trim(
            str_replace(
                '\\',
                '/',
                $imagePath
            )
        );

    if ($imagePath === '') {
        return null;
    }

    $marker =
        '/iPhoneStore/uploads/';

    $markerPos =
        stripos(
            $imagePath,
            $marker
        );

    if ($markerPos !== false) {
        $imagePath =
            substr(
                $imagePath,
                $markerPos + strlen($marker)
            );
    }

    $imagePath =
        ltrim(
            $imagePath,
            '/'
        );

    if (
        stripos(
            $imagePath,
            'uploads/'
        ) === 0
    ) {
        $imagePath =
            substr(
                $imagePath,
                strlen('uploads/')
            );
    }

    if (
        $imagePath === '' ||
        preg_match(
            '#(^|/)\.\.(/|$)#',
            $imagePath
        )
    ) {
        return null;
    }

    return $imagePath;
}

function xoaFileAnhUpload(
    string $imagePath
): void {
    $relativePath =
        chuanHoaDuongDanAnhUpload(
            $imagePath
        );

    if ($relativePath === null) {
        return;
    }

    $fullPath =
        __DIR__ .
        '/../../uploads/' .
        $relativePath;

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header(
        "Location: index.php?delete=invalid"
    );
    exit;
}

/* =========================================================
   KIỂM TRA SẢN PHẨM
========================================================= */
$stmtProduct = $conn->prepare("
    SELECT
        id,
        ma_san_pham,
        ten_san_pham,
        hinh_anh,
        trang_thai
    FROM san_pham
    WHERE id = ?
    LIMIT 1
");

$stmtProduct->bind_param(
    "i",
    $id
);

$stmtProduct->execute();

$sanPham = $stmtProduct
    ->get_result()
    ->fetch_assoc();

if (!$sanPham) {
    header(
        "Location: index.php?delete=not_found"
    );
    exit;
}

/* =========================================================
   KIỂM TRA SẢN PHẨM ĐÃ TỪNG CÓ TRONG ĐƠN HÀNG CHƯA

   Nếu đã từng được mua:
   - KHÔNG xóa chi_tiet_don_hang.
   - KHÔNG xóa sản phẩm vật lý.
   - KHÔNG xóa biến thể và ảnh biến thể.
   - Chỉ chuyển sản phẩm về trạng thái ngừng bán.

   Mục đích:
   - Giữ lịch sử đơn hàng.
   - Giữ mã sản phẩm.
   - Giữ đúng bien_the_id trong đơn cũ.
   - Giữ ảnh biến thể để user/admin còn xem đúng sản phẩm đã mua.
   - Giữ dữ liệu doanh thu / thống kê.
========================================================= */
$stmtOrderCheck = $conn->prepare("
    SELECT COUNT(*) AS tong
    FROM chi_tiet_don_hang
    WHERE san_pham_id = ?
");

$stmtOrderCheck->bind_param(
    "i",
    $id
);

$stmtOrderCheck->execute();

$orderCheckData = $stmtOrderCheck
    ->get_result()
    ->fetch_assoc();

$daTungDuocMua =
    (int)($orderCheckData['tong'] ?? 0) > 0;

/* =========================================================
   TRƯỜNG HỢP 1:
   SẢN PHẨM ĐÃ CÓ LỊCH SỬ ĐƠN HÀNG

   Chỉ "ngừng bán", tuyệt đối không hard delete.
========================================================= */
if ($daTungDuocMua) {
    $conn->begin_transaction();

    try {
        /*
            Xóa khỏi giỏ hàng hiện tại để khách không tiếp tục
            mua sản phẩm đã ngừng bán.
        */
        $stmtCart = $conn->prepare("
            DELETE FROM gio_hang
            WHERE san_pham_id = ?
        ");

        $stmtCart->bind_param(
            "i",
            $id
        );

        $stmtCart->execute();

        /*
            Chỉ ngừng bán sản phẩm.
            Không xóa đơn hàng, chi tiết đơn, đánh giá,
            biến thể, ảnh biến thể, mã sản phẩm
            hay dữ liệu lịch sử.
        */
        $stmtDisable = $conn->prepare("
            UPDATE san_pham
            SET trang_thai = 0
            WHERE id = ?
        ");

        $stmtDisable->bind_param(
            "i",
            $id
        );

        $stmtDisable->execute();

        /*
            Tắt toàn bộ chương trình khuyến mãi của sản phẩm,
            bao gồm cả khuyến mãi từng biến thể.
        */
        $stmtDisablePromo = $conn->prepare("
            UPDATE khuyen_mai
            SET trang_thai = 0
            WHERE san_pham_id = ?
        ");

        $stmtDisablePromo->bind_param(
            "i",
            $id
        );

        $stmtDisablePromo->execute();

        $conn->commit();

        header(
            "Location: index.php?delete=archived&id=" .
            urlencode(
                (string)$id
            )
        );
        exit;

    } catch (Throwable $e) {
        $conn->rollback();

        die(
            "Không thể ngừng bán sản phẩm: " .
            htmlspecialchars(
                $e->getMessage(),
                ENT_QUOTES,
                "UTF-8"
            )
        );
    }
}

/* =========================================================
   TRƯỜNG HỢP 2:
   SẢN PHẨM CHƯA TỪNG CÓ TRONG ĐƠN HÀNG

   Có thể xóa hoàn toàn.
========================================================= */

/*
    Nếu bảng ảnh biến thể tồn tại nhưng tên cột ảnh không thuộc
    hai kiểu hệ thống đang hỗ trợ thì dừng lại.

    Không tiếp tục hard delete trong tình huống này để tránh:
    - database bị xóa;
    - nhưng file ảnh vật lý bị bỏ lại trong uploads/bien_the/.
*/
$coBangAnhBienThe =
    coBangAnhBienThe($conn);

$cotAnhBienThe =
    layCotAnhBienThe($conn);

if (
    $coBangAnhBienThe &&
    $cotAnhBienThe === null
) {
    die(
        "Không thể xóa sản phẩm vì bảng " .
        "san_pham_bien_the_hinh_anh đang dùng cấu trúc cột ảnh " .
        "không được nhận diện. Hãy kiểm tra tên cột ảnh trước."
    );
}

/* =========================================================
   LẤY DANH SÁCH FILE ẢNH TRƯỚC KHI XÓA DATABASE

   Chỉ xóa file vật lý SAU KHI transaction commit thành công.
========================================================= */
$imageFiles = [];

/* -------------------------
   1. Ảnh chung sản phẩm
------------------------- */
$stmtImages = $conn->prepare("
    SELECT hinh_anh
    FROM san_pham_hinh_anh
    WHERE san_pham_id = ?
");

$stmtImages->bind_param(
    "i",
    $id
);

$stmtImages->execute();

$resultImages =
    $stmtImages->get_result();

while (
    $row =
        $resultImages->fetch_assoc()
) {
    $imageName =
        trim(
            (string)(
                $row['hinh_anh']
                ?? ''
            )
        );

    if ($imageName !== '') {
        $imageFiles[] =
            $imageName;
    }
}

/* -------------------------
   2. Ảnh riêng biến thể
------------------------- */
if (
    $coBangAnhBienThe &&
    $cotAnhBienThe !== null
) {
    $stmtVariantImages =
        $conn->prepare("
            SELECT
                ha.`{$cotAnhBienThe}` AS hinh_anh
            FROM san_pham_bien_the_hinh_anh ha
            INNER JOIN san_pham_bien_the bt
                ON bt.id = ha.bien_the_id
            WHERE bt.san_pham_id = ?
        ");

    $stmtVariantImages->bind_param(
        "i",
        $id
    );

    $stmtVariantImages->execute();

    $resultVariantImages =
        $stmtVariantImages->get_result();

    while (
        $row =
            $resultVariantImages->fetch_assoc()
    ) {
        $imageName =
            trim(
                (string)(
                    $row['hinh_anh']
                    ?? ''
                )
            );

        if ($imageName !== '') {
            $imageFiles[] =
                $imageName;
        }
    }
}

/*
    Nếu ảnh đại diện chỉ tồn tại trong san_pham.hinh_anh
    thì vẫn thêm vào danh sách xóa.

    san_pham.hinh_anh hiện có thể là:
    - ảnh chung: iphone.jpg
    - ảnh biến thể: bien_the/iphone.jpg
*/
$mainImage =
    trim(
        (string)(
            $sanPham['hinh_anh']
            ?? ''
        )
    );

if ($mainImage !== '') {
    $imageFiles[] =
        $mainImage;
}

/*
    Chuẩn hóa trước khi unique để:
    bien_the/a.jpg
    và /iPhoneStore/uploads/bien_the/a.jpg
    không bị coi là hai file khác nhau.
*/
$normalizedImageFiles = [];

foreach ($imageFiles as $imageName) {
    $normalized =
        chuanHoaDuongDanAnhUpload(
            $imageName
        );

    if ($normalized !== null) {
        $normalizedImageFiles[] =
            $normalized;
    }
}

$imageFiles =
    array_values(
        array_unique(
            $normalizedImageFiles
        )
    );

$conn->begin_transaction();

try {
    /* =====================================================
       1. XÓA KHỎI GIỎ HÀNG
    ===================================================== */
    $stmt = $conn->prepare("
        DELETE FROM gio_hang
        WHERE san_pham_id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    /* =====================================================
       2. XÓA ĐÁNH GIÁ

       Chỉ xóa ở trường hợp sản phẩm chưa từng được mua.
       Thực tế thường sẽ chưa có đánh giá hợp lệ.
    ===================================================== */
    $stmt = $conn->prepare("
        DELETE FROM danh_gia
        WHERE san_pham_id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    /* =====================================================
       3. XÓA KHUYẾN MÃI

       Phải xóa trước biến thể vì khuyen_mai có thể tham chiếu
       bien_the_id.
    ===================================================== */
    $stmt = $conn->prepare("
        DELETE FROM khuyen_mai
        WHERE san_pham_id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    /* =====================================================
       4. XÓA ẢNH BIẾN THỂ TRONG DATABASE

       Làm trước khi xóa san_pham_bien_the để hoạt động được
       cả khi foreign key chưa cấu hình ON DELETE CASCADE.
    ===================================================== */
    if ($coBangAnhBienThe) {
        $stmt = $conn->prepare("
            DELETE ha
            FROM san_pham_bien_the_hinh_anh ha
            INNER JOIN san_pham_bien_the bt
                ON bt.id = ha.bien_the_id
            WHERE bt.san_pham_id = ?
        ");

        $stmt->bind_param(
            "i",
            $id
        );

        $stmt->execute();
    }

    /* =====================================================
       5. XÓA HÌNH ẢNH CHUNG TRONG DATABASE
    ===================================================== */
    $stmt = $conn->prepare("
        DELETE FROM san_pham_hinh_anh
        WHERE san_pham_id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    /* =====================================================
       6. XÓA THÔNG SỐ KỸ THUẬT
    ===================================================== */
    $stmt = $conn->prepare("
        DELETE FROM san_pham_thong_so
        WHERE san_pham_id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    /* =====================================================
       7. XÓA BIẾN THỂ
    ===================================================== */
    $stmt = $conn->prepare("
        DELETE FROM san_pham_bien_the
        WHERE san_pham_id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    /* =====================================================
       8. XÓA MÀU SẢN PHẨM
    ===================================================== */
    $stmt = $conn->prepare("
        DELETE FROM san_pham_mau
        WHERE san_pham_id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    /* =====================================================
       9. XÓA SẢN PHẨM

       ma_san_pham nằm trong chính dòng san_pham nên khi xóa
       sản phẩm, mã sản phẩm cũng được xóa cùng dòng.
    ===================================================== */
    $stmt = $conn->prepare("
        DELETE FROM san_pham
        WHERE id = ?
    ");

    $stmt->bind_param(
        "i",
        $id
    );

    $stmt->execute();

    if (
        $stmt->affected_rows <= 0
    ) {
        throw new Exception(
            "Không tìm thấy sản phẩm để xóa."
        );
    }

    $conn->commit();

    /* =====================================================
       10. XÓA FILE ẢNH VẬT LÝ SAU KHI COMMIT

       Quan trọng:
       - ảnh chung: uploads/ten_file.jpg
       - ảnh biến thể: uploads/bien_the/ten_file.jpg

       Không dùng basename() ở đây vì basename() sẽ làm mất
       thư mục "bien_the/" và để lại file ảnh rác.
    ===================================================== */
    foreach ($imageFiles as $imageName) {
        xoaFileAnhUpload(
            $imageName
        );
    }

    header(
        "Location: index.php?delete=success"
    );
    exit;

} catch (Throwable $e) {
    $conn->rollback();

    die(
        "Không thể xóa sản phẩm: " .
        htmlspecialchars(
            $e->getMessage(),
            ENT_QUOTES,
            "UTF-8"
        )
    );
}
?>

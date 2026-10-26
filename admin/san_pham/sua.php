<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

/* =========================================================
   HÀM HỖ TRỢ
========================================================= */


function layCotAnhBienThe(
    mysqli $conn
): ?string {
    /*
        Hỗ trợ cả 2 tên cột đã từng dùng:
        - hinh_anh
        - duong_dan_anh

        Nhờ vậy không cần đổi database cũ ngay.
    */
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
            AND TABLE_NAME =
                'san_pham_bien_the_hinh_anh'
            AND COLUMN_NAME = ?
            LIMIT 1
        ");

        if (!$stmt) {
            continue;
        }

        $stmt->bind_param(
            's',
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

function coBangAnhBienThe(
    mysqli $conn
): bool {
    return layCotAnhBienThe(
        $conn
    ) !== null;
}

function toDateTimeLocal(?string $value): string
{
    if (empty($value)) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp
        ? date('Y-m-d\TH:i', $timestamp)
        : '';
}


/* =========================================================
   TẠO MÃ SẢN PHẨM TỰ ĐỘNG

   Dạng mã:
   8686 + 4 số ngẫu nhiên

   Quy tắc:
   - 8686 luôn cố định.
   - 4 số cuối không lặp nhau trong cùng một mã.
   - Toàn bộ mã không được trùng với sản phẩm khác.
========================================================= */
function taoMaSanPham(mysqli $conn): string
{
    $prefix = '8686';

    $stmtCheck = $conn->prepare("
        SELECT id
        FROM san_pham
        WHERE ma_san_pham = ?
        LIMIT 1
    ");

    if (!$stmtCheck) {
        throw new Exception(
            'Không thể kiểm tra mã sản phẩm. ' .
            'Hãy chắc chắn bảng san_pham đã có cột ma_san_pham.'
        );
    }

    for ($lanThu = 1; $lanThu <= 100; $lanThu++) {
        $digits = range(0, 9);
        $suffix = '';

        for ($i = 0; $i < 4; $i++) {
            $index = random_int(
                0,
                count($digits) - 1
            );

            $suffix .= (string)$digits[$index];

            array_splice(
                $digits,
                $index,
                1
            );
        }

        $maSanPham = $prefix . $suffix;

        $stmtCheck->bind_param(
            's',
            $maSanPham
        );

        $stmtCheck->execute();

        $result = $stmtCheck->get_result();

        if ($result->num_rows === 0) {
            $stmtCheck->close();

            return $maSanPham;
        }
    }

    $stmtCheck->close();

    throw new Exception(
        'Không thể tạo mã sản phẩm không trùng. ' .
        'Vui lòng thử lại.'
    );
}

/* =========================================================
   1. ID SẢN PHẨM
========================================================= */

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die('ID sản phẩm không hợp lệ');
}

/* =========================================================
   2. LẤY SẢN PHẨM
========================================================= */

$stmtProduct = $conn->prepare("
    SELECT *
    FROM san_pham
    WHERE id = ?
    LIMIT 1
");

$stmtProduct->bind_param('i', $id);
$stmtProduct->execute();

$sanPham = $stmtProduct
    ->get_result()
    ->fetch_assoc();

if (!$sanPham) {
    die('Sản phẩm không tồn tại');
}


/*
    File này sử dụng cột ma_san_pham.
    Nếu database chưa có cột thì báo rõ để tránh lỗi SQL khó hiểu.
*/
$resultMaSanPhamColumn = $conn->query("
    SHOW COLUMNS
    FROM san_pham
    LIKE 'ma_san_pham'
");

if (
    !$resultMaSanPhamColumn ||
    $resultMaSanPhamColumn->num_rows === 0
) {
    die(
        'Bảng san_pham chưa có cột ma_san_pham. ' .
        'Hãy chạy câu lệnh ALTER TABLE thêm ma_san_pham trước.'
    );
}

$maSanPhamHienTai = trim(
    (string)($sanPham['ma_san_pham'] ?? '')
);

/* =========================================================
   3. DANH MỤC
========================================================= */

$danhMucList = [];

$resultDanhMuc = $conn->query("
    SELECT *
    FROM danh_muc
    ORDER BY id ASC
");

while ($row = $resultDanhMuc->fetch_assoc()) {
    $danhMucList[] = $row;
}

/* =========================================================
   4. MÀU SẮC
========================================================= */

$mauSacList = [];

$resultMauSac = $conn->query("
    SELECT *
    FROM mau_sac
    ORDER BY id ASC
");

while ($row = $resultMauSac->fetch_assoc()) {
    $mauSacList[] = $row;
}

$validColorIds = array_map(
    fn($mau) => (int)$mau['id'],
    $mauSacList
);

$colorOptionsHtml = '<option value="">-- Chọn màu --</option>';

foreach ($mauSacList as $mau) {
    $colorOptionsHtml .=
        '<option value="' . (int)$mau['id'] . '">' .
        htmlspecialchars($mau['ten_mau'], ENT_QUOTES, 'UTF-8') .
        '</option>';
}

/* =========================================================
   5. BIẾN THỂ
/* =========================================================
   6. BIẾN THỂ
========================================================= */

$stmtVariants = $conn->prepare("
    SELECT
        id,
        san_pham_id,
        mau_id,
        ram,
        rom,
        gia,
        so_luong
    FROM san_pham_bien_the
    WHERE san_pham_id = ?
    ORDER BY id ASC
");

$stmtVariants->bind_param('i', $id);
$stmtVariants->execute();

$variants = $stmtVariants
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

$cotAnhBienThe =
    layCotAnhBienThe($conn);

$coBangAnhBienThe =
    $cotAnhBienThe !== null;

$variantImagesById = [];

if ($coBangAnhBienThe) {
    $stmtVariantImages =
        $conn->prepare("
            SELECT
                ha.id,
                ha.bien_the_id,
                ha.`{$cotAnhBienThe}` AS hinh_anh,
                ha.la_anh_chinh,
                ha.thu_tu

            FROM san_pham_bien_the_hinh_anh ha

            JOIN san_pham_bien_the bt
                ON bt.id = ha.bien_the_id

            WHERE bt.san_pham_id = ?

            ORDER BY
                ha.bien_the_id ASC,
                ha.la_anh_chinh DESC,
                ha.thu_tu ASC,
                ha.id ASC
        ");

    if ($stmtVariantImages) {
        $stmtVariantImages->bind_param(
            'i',
            $id
        );

        $stmtVariantImages->execute();

        $variantImageRows =
            $stmtVariantImages
                ->get_result()
                ->fetch_all(MYSQLI_ASSOC);

        foreach ($variantImageRows as $imageRow) {
            $variantId =
                (int)$imageRow['bien_the_id'];

            if (!isset($variantImagesById[$variantId])) {
                $variantImagesById[$variantId] = [];
            }

            $variantImagesById[$variantId][] =
                $imageRow;
        }
    }
}

/* =========================================================
   7. HÌNH ẢNH CHUNG
========================================================= */

$stmtImages = $conn->prepare("
    SELECT
        id,
        hinh_anh,
        thu_tu
    FROM san_pham_hinh_anh
    WHERE san_pham_id = ?
    ORDER BY thu_tu ASC, id ASC
");

$stmtImages->bind_param('i', $id);
$stmtImages->execute();

$productImages = $stmtImages
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);


/* =========================================================
   9. GIÁ TRỊ MÔ TẢ
   Có fallback cho dữ liệu cũ đang nằm trong mo_ta
========================================================= */

$moTaNganHienTai = trim((string)($sanPham['mo_ta_ngan'] ?? ''));

$moTaDaiHienTai = trim((string)($sanPham['mo_ta_dai'] ?? ''));

if ($moTaDaiHienTai === '') {
    $moTaDaiHienTai = trim((string)($sanPham['mo_ta'] ?? ''));
}

/* =========================================================
   9.1 NHÓM THÔNG SỐ KỸ THUẬT
========================================================= */
$specGroups = [
    'cau_hinh_bo_nho' => [
        'label' => 'Cấu hình & Bộ nhớ',
        'order' => 1
    ],
    'camera_man_hinh' => [
        'label' => 'Camera & Màn hình',
        'order' => 2
    ],
    'pin_sac' => [
        'label' => 'Pin & Sạc',
        'order' => 3
    ],
    'tien_ich' => [
        'label' => 'Tiện ích',
        'order' => 4
    ],
    'ket_noi' => [
        'label' => 'Kết nối',
        'order' => 5
    ],
    'thiet_ke_chat_lieu' => [
        'label' => 'Thiết kế & Chất liệu',
        'order' => 6
    ]
];

$specInitialData = [];

foreach ($specGroups as $groupKey => $groupInfo) {
    $specInitialData[$groupKey] = [];
}

/*
    Lấy thông số hiện có của sản phẩm.
    Đây là cùng bảng mà file thêm sản phẩm đang sử dụng.
*/
$stmtSpecs = $conn->prepare("
    SELECT
        id,
        nhom_ma,
        nhom_ten,
        ten_thong_so,
        gia_tri,
        thu_tu_nhom,
        thu_tu
    FROM san_pham_thong_so
    WHERE san_pham_id = ?
    ORDER BY thu_tu_nhom ASC, thu_tu ASC, id ASC
");

if (!$stmtSpecs) {
    die(
        'Không thể đọc thông số kỹ thuật. ' .
        'Hãy kiểm tra bảng san_pham_thong_so đã được tạo. Lỗi: ' .
        htmlspecialchars($conn->error)
    );
}

$stmtSpecs->bind_param('i', $id);
$stmtSpecs->execute();

$specRows = $stmtSpecs
    ->get_result()
    ->fetch_all(MYSQLI_ASSOC);

foreach ($specRows as $specRow) {
    $groupKey = (string)$specRow['nhom_ma'];

    if (!isset($specGroups[$groupKey])) {
        continue;
    }

    $specInitialData[$groupKey][] = [
        'name' => (string)$specRow['ten_thong_so'],
        'value' => (string)$specRow['gia_tri']
    ];
}

/*
    Nếu submit bị lỗi thì ưu tiên dữ liệu người dùng vừa nhập,
    không nạp lại dữ liệu cũ từ database.
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($specGroups as $groupKey => $groupInfo) {
        $specInitialData[$groupKey] = [];
    }

    $rawGroups = is_array($_POST['spec_group'] ?? null)
        ? $_POST['spec_group']
        : [];

    $rawNames = is_array($_POST['spec_name'] ?? null)
        ? $_POST['spec_name']
        : [];

    $rawValues = is_array($_POST['spec_value'] ?? null)
        ? $_POST['spec_value']
        : [];

    $rawCount = max(
        count($rawGroups),
        count($rawNames),
        count($rawValues)
    );

    for ($i = 0; $i < $rawCount; $i++) {
        $groupKey = trim((string)($rawGroups[$i] ?? ''));
        $name = trim((string)($rawNames[$i] ?? ''));
        $value = trim((string)($rawValues[$i] ?? ''));

        if (
            isset($specGroups[$groupKey]) &&
            ($name !== '' || $value !== '')
        ) {
            $specInitialData[$groupKey][] = [
                'name' => $name,
                'value' => $value
            ];
        }
    }
}

$errorMessage = '';

/* =========================================================
   10. XỬ LÝ UPDATE
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $danhMucId = (int)($_POST['danh_muc_id'] ?? 0);
    $tenSanPham = trim($_POST['ten_san_pham'] ?? '');

    $moTaNgan = trim($_POST['mo_ta_ngan'] ?? '');
    $moTaDai = trim($_POST['mo_ta_dai'] ?? '');

    $trangThai = (int)($_POST['trang_thai'] ?? 1);
    $trangThai = $trangThai === 1 ? 1 : 0;

    /*
        Mã sản phẩm là mã định danh cố định.
        Không lấy mã từ POST để admin không vô tình sửa mã.

        - Sản phẩm đã có mã: giữ nguyên.
        - Sản phẩm cũ chưa có mã: tạo mã khi bấm Lưu.
    */
    $maSanPham = $maSanPhamHienTai;

    $uploadedImages = [];
    $uploadedPaths = [];
    $filesToDelete = [];

    $variantUploadedPaths = [];
    $variantFilesToDelete = [];

    try {
        /* -------------------------------------------------
           10.1 THÔNG SỐ KỸ THUẬT
           Mỗi dòng = Nhóm + Tên thông số + Giá trị.
        ------------------------------------------------- */
        $technicalSpecs = [];
        $groupRowOrder = [];

        $rawSpecGroups = is_array($_POST['spec_group'] ?? null)
            ? $_POST['spec_group']
            : [];

        $rawSpecNames = is_array($_POST['spec_name'] ?? null)
            ? $_POST['spec_name']
            : [];

        $rawSpecValues = is_array($_POST['spec_value'] ?? null)
            ? $_POST['spec_value']
            : [];

        $specCount = max(
            count($rawSpecGroups),
            count($rawSpecNames),
            count($rawSpecValues)
        );

        for ($i = 0; $i < $specCount; $i++) {
            $groupKey = trim((string)($rawSpecGroups[$i] ?? ''));
            $specName = trim((string)($rawSpecNames[$i] ?? ''));
            $specValue = trim((string)($rawSpecValues[$i] ?? ''));

            if (
                $groupKey === '' &&
                $specName === '' &&
                $specValue === ''
            ) {
                continue;
            }

            if (!isset($specGroups[$groupKey])) {
                throw new Exception(
                    'Nhóm thông số kỹ thuật không hợp lệ'
                );
            }

            if ($specName === '' && $specValue === '') {
                continue;
            }

            if ($specName === '') {
                throw new Exception(
                    'Vui lòng nhập tên thông số trong nhóm ' .
                    $specGroups[$groupKey]['label']
                );
            }

            if ($specValue === '') {
                throw new Exception(
                    'Vui lòng nhập giá trị cho thông số "' .
                    $specName .
                    '"'
                );
            }

            if (mb_strlen($specName, 'UTF-8') > 150) {
                throw new Exception(
                    'Tên thông số không được vượt quá 150 ký tự'
                );
            }

            if (mb_strlen($specValue, 'UTF-8') > 2000) {
                throw new Exception(
                    'Giá trị của thông số "' .
                    $specName .
                    '" không được vượt quá 2000 ký tự'
                );
            }

            if (!isset($groupRowOrder[$groupKey])) {
                $groupRowOrder[$groupKey] = 0;
            }

            $groupRowOrder[$groupKey]++;

            $technicalSpecs[] = [
                'group_key' => $groupKey,
                'group_label' => $specGroups[$groupKey]['label'],
                'group_order' => (int)$specGroups[$groupKey]['order'],
                'name' => $specName,
                'value' => $specValue,
                'row_order' => $groupRowOrder[$groupKey]
            ];
        }

        /* -------------------------------------------------
           10.2 KIỂM TRA CƠ BẢN
        ------------------------------------------------- */

        if ($tenSanPham === '') {
            throw new Exception('Tên sản phẩm không được để trống');
        }

        if ($danhMucId <= 0) {
            throw new Exception('Danh mục không hợp lệ');
        }

        if (mb_strlen($moTaNgan) > 500) {
            throw new Exception('Mô tả ngắn không được vượt quá 500 ký tự');
        }

        /* -------------------------------------------------
           10.2 XỬ LÝ BIẾN THỂ
           Mỗi biến thể:
           Màu + RAM + ROM + Giá + SL
        ------------------------------------------------- */

        $variantData = [];
        $variantKeys = [];
        $tongSoLuongBienThe = 0;

        $countVariant =
            isset($_POST['rom']) && is_array($_POST['rom'])
                ? count($_POST['rom'])
                : 0;

        for ($i = 0; $i < $countVariant; $i++) {
            $variantId = (int)($_POST['variant_id'][$i] ?? 0);

            $variantFormKey =
                trim(
                    (string)(
                        $_POST['variant_form_key'][$i]
                        ?? ''
                    )
                );

            if (
                $variantFormKey === '' ||
                !preg_match(
                    '/^[a-zA-Z0-9_-]+$/',
                    $variantFormKey
                )
            ) {
                throw new Exception(
                    'Khóa ảnh biến thể không hợp lệ'
                );
            }

            $mauId = (int)($_POST['mau_variant'][$i] ?? 0);

            $ram = trim($_POST['ram'][$i] ?? '');
            $rom = trim($_POST['rom'][$i] ?? '');

            $giaVariant = (float)($_POST['gia_variant'][$i] ?? 0);
            $slVariant = max(
                0,
                (int)($_POST['sl_variant'][$i] ?? 0)
            );


            $dongTrong =
                $variantId <= 0 &&
                $mauId <= 0 &&
                $ram === '' &&
                $rom === '' &&
                $giaVariant <= 0 &&
                $slVariant <= 0;

            if ($dongTrong) {
                continue;
            }

            if (
                $mauId <= 0 ||
                !in_array($mauId, $validColorIds, true)
            ) {
                throw new Exception(
                    'Vui lòng chọn màu hợp lệ cho tất cả biến thể'
                );
            }

            if ($rom === '') {
                throw new Exception(
                    'Vui lòng nhập ROM cho tất cả biến thể'
                );
            }

            if ($giaVariant <= 0) {
                throw new Exception(
                    'Giá của tất cả biến thể phải lớn hơn 0'
                );
            }

            $variantKey =
                $mauId . '|' .
                mb_strtolower($ram) . '|' .
                mb_strtolower($rom);

            if (isset($variantKeys[$variantKey])) {
                throw new Exception(
                    "Bị trùng biến thể: $ram / $rom cùng màu"
                );
            }

            $variantKeys[$variantKey] = true;


            $variantData[] = [
                'id' => $variantId,
                'form_key' => $variantFormKey,
                'mau_id' => $mauId,
                'ram' => $ram,
                'rom' => $rom,
                'gia' => $giaVariant,
                'so_luong' => $slVariant
            ];

            $tongSoLuongBienThe += $slVariant;
        }

        if (empty($variantData)) {
            throw new Exception(
                'Sản phẩm iPhone phải có ít nhất 1 biến thể'
            );
        }

        /*
            Sản phẩm chỉ quản lý giá và tồn kho theo biến thể.
            Hai cột san_pham.gia / san_pham.so_luong vẫn được
            đồng bộ để các trang cũ trong hệ thống không lỗi.
        */
        $variantPrices =
            array_column(
                $variantData,
                'gia'
            );

        $gia =
            (float)min(
                $variantPrices
            );

        $soLuong =
            $tongSoLuongBienThe;

        /* -------------------------------------------------
           10.3 XÁC ĐỊNH MÀU TỪ CÁC BIẾN THỂ
        ------------------------------------------------- */

        $productColorIds = [];

        foreach ($variantData as $variant) {
            $productColorIds[] =
                (int)$variant['mau_id'];
        }

        $productColorIds =
            array_values(
                array_unique(
                    $productColorIds
                )
            );

        /* -------------------------------------------------
           10.4 UPLOAD ẢNH CHUNG MỚI
        ------------------------------------------------- */

        $uploadDir = '../../uploads/';

        if (!is_dir($uploadDir)) {
            mkdir(
                $uploadDir,
                0777,
                true
            );
        }

        $allowedExtensions = [
            'jpg',
            'jpeg',
            'png',
            'webp'
        ];

        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/webp'
        ];

        if (
            !empty($_FILES['hinh_anh_moi']['name']) &&
            is_array($_FILES['hinh_anh_moi']['name'])
        ) {
            foreach (
                $_FILES['hinh_anh_moi']['name']
                as $index => $originalName
            ) {
                if ($originalName === '') {
                    continue;
                }

                $error =
                    $_FILES['hinh_anh_moi']['error'][$index];

                if ($error !== UPLOAD_ERR_OK) {
                    throw new Exception(
                        'Có hình ảnh tải lên bị lỗi'
                    );
                }

                $size =
                    (int)$_FILES['hinh_anh_moi']['size'][$index];

                if ($size > 5 * 1024 * 1024) {
                    throw new Exception(
                        'Mỗi hình ảnh tối đa 5MB'
                    );
                }

                $tmpName =
                    $_FILES['hinh_anh_moi']['tmp_name'][$index];

                $extension = strtolower(
                    pathinfo(
                        $originalName,
                        PATHINFO_EXTENSION
                    )
                );

                $imageInfo =
                    @getimagesize($tmpName);

                $extensionHopLe = in_array(
                    $extension,
                    $allowedExtensions,
                    true
                );

                $mimeHopLe =
                    $imageInfo &&
                    in_array(
                        $imageInfo['mime'],
                        $allowedMimeTypes,
                        true
                    );

                if (!$extensionHopLe || !$mimeHopLe) {
                    throw new Exception(
                        'Chỉ cho phép ảnh JPG, JPEG, PNG hoặc WEBP'
                    );
                }

                $newName =
                    date('YmdHis') .
                    '_' .
                    uniqid() .
                    '.' .
                    $extension;

                $destination =
                    $uploadDir . $newName;

                if (
                    !move_uploaded_file(
                        $tmpName,
                        $destination
                    )
                ) {
                    throw new Exception(
                        'Không thể lưu hình ảnh'
                    );
                }

                $uploadedImages[] = $newName;
                $uploadedPaths[] = $destination;
            }
        }

        /* -------------------------------------------------
           10.6 ẢNH CẦN XÓA
        ------------------------------------------------- */

        $deleteImageIds = [];

        if (
            !empty($_POST['xoa_hinh']) &&
            is_array($_POST['xoa_hinh'])
        ) {
            $deleteImageIds = array_map(
                'intval',
                $_POST['xoa_hinh']
            );
        }

        $anhDaiDienId =
            (int)($_POST['anh_dai_dien'] ?? 0);

        $newMainProductIndex =
            isset($_POST['new_main_product_index']) &&
            $_POST['new_main_product_index'] !== ''
                ? (int)$_POST['new_main_product_index']
                : -1;

        $deleteVariantImageIds = [];

        if (
            !empty($_POST['xoa_anh_bien_the']) &&
            is_array($_POST['xoa_anh_bien_the'])
        ) {
            $deleteVariantImageIds =
                array_values(
                    array_filter(
                        array_map(
                            'intval',
                            $_POST['xoa_anh_bien_the']
                        ),
                        static fn(int $imageId): bool =>
                            $imageId > 0
                    )
                );
        }

        $selectedVariantMainImages =
            is_array(
                $_POST['anh_chinh_bien_the']
                ?? null
            )
                ? $_POST['anh_chinh_bien_the']
                : [];

        $selectedNewVariantMainIndexes =
            is_array(
                $_POST['anh_chinh_bien_the_moi']
                ?? null
            )
                ? $_POST['anh_chinh_bien_the_moi']
                : [];

        /* -------------------------------------------------
           10.7 TRANSACTION
        ------------------------------------------------- */

        $conn->begin_transaction();

        try {
            /* =============================================
               A. XÓA ẢNH ĐÃ CHỌN
            ============================================= */

            if (!empty($deleteImageIds)) {
                $stmtGetDelete = $conn->prepare("
                    SELECT hinh_anh
                    FROM san_pham_hinh_anh
                    WHERE id = ?
                    AND san_pham_id = ?
                ");

                $stmtDeleteImage = $conn->prepare("
                    DELETE FROM san_pham_hinh_anh
                    WHERE id = ?
                    AND san_pham_id = ?
                ");

                foreach ($deleteImageIds as $imageId) {
                    if ($imageId <= 0) {
                        continue;
                    }

                    $stmtGetDelete->bind_param(
                        'ii',
                        $imageId,
                        $id
                    );

                    $stmtGetDelete->execute();

                    $oldImage = $stmtGetDelete
                        ->get_result()
                        ->fetch_assoc();

                    if (!$oldImage) {
                        continue;
                    }

                    $filesToDelete[] =
                        $oldImage['hinh_anh'];

                    $stmtDeleteImage->bind_param(
                        'ii',
                        $imageId,
                        $id
                    );

                    $stmtDeleteImage->execute();
                }
            }

            /* =============================================
               B. THÊM ẢNH MỚI
            ============================================= */

            if (!empty($uploadedImages)) {
                $stmtMaxOrder = $conn->prepare("
                    SELECT
                        COALESCE(MAX(thu_tu), 0) AS max_order
                    FROM san_pham_hinh_anh
                    WHERE san_pham_id = ?
                ");

                $stmtMaxOrder->bind_param(
                    'i',
                    $id
                );

                $stmtMaxOrder->execute();

                $maxOrderData = $stmtMaxOrder
                    ->get_result()
                    ->fetch_assoc();

                $thuTu =
                    (int)($maxOrderData['max_order'] ?? 0);

                $stmtInsertImage = $conn->prepare("
                    INSERT INTO san_pham_hinh_anh (
                        san_pham_id,
                        hinh_anh,
                        thu_tu
                    )
                    VALUES (?, ?, ?)
                ");

                foreach ($uploadedImages as $imageName) {
                    $thuTu++;

                    $stmtInsertImage->bind_param(
                        'isi',
                        $id,
                        $imageName,
                        $thuTu
                    );

                    $stmtInsertImage->execute();
                }
            }

            /* =============================================
               C. XÁC ĐỊNH ẢNH ĐẠI DIỆN
            ============================================= */

            $hinhAnh =
                (string)($sanPham['hinh_anh'] ?? '');

            if (
                $anhDaiDienId > 0 &&
                !in_array(
                    $anhDaiDienId,
                    $deleteImageIds,
                    true
                )
            ) {
                $stmtMainImage = $conn->prepare("
                    SELECT hinh_anh
                    FROM san_pham_hinh_anh
                    WHERE id = ?
                    AND san_pham_id = ?
                    LIMIT 1
                ");

                $stmtMainImage->bind_param(
                    'ii',
                    $anhDaiDienId,
                    $id
                );

                $stmtMainImage->execute();

                $mainImageData = $stmtMainImage
                    ->get_result()
                    ->fetch_assoc();

                if ($mainImageData) {
                    $hinhAnh =
                        $mainImageData['hinh_anh'];
                }
            }

            /*
                Nếu admin chọn một ảnh mới làm ảnh đại diện,
                ưu tiên ảnh mới đó sau khi upload xong.
            */
            if (
                $newMainProductIndex >= 0 &&
                isset($uploadedImages[$newMainProductIndex])
            ) {
                $hinhAnh =
                    (string)$uploadedImages[$newMainProductIndex];
            }

            if (
                $hinhAnh === '' ||
                in_array(
                    $hinhAnh,
                    $filesToDelete,
                    true
                )
            ) {
                $stmtFirstImage = $conn->prepare("
                    SELECT hinh_anh
                    FROM san_pham_hinh_anh
                    WHERE san_pham_id = ?
                    ORDER BY thu_tu ASC, id ASC
                    LIMIT 1
                ");

                $stmtFirstImage->bind_param(
                    'i',
                    $id
                );

                $stmtFirstImage->execute();

                $firstImage = $stmtFirstImage
                    ->get_result()
                    ->fetch_assoc();

                $hinhAnh =
                    $firstImage['hinh_anh'] ?? '';
            }

            if (
                $hinhAnh === '' &&
                !empty($uploadedImages)
            ) {
                $hinhAnh = $uploadedImages[0];
            }

            /* =============================================
               D. UPDATE SẢN PHẨM

               gia_khuyen_mai cũ để 0.
               Khuyến mãi được quản lý riêng tại admin/khuyen_mai/.

               mo_ta vẫn lưu mô tả dài để tương thích
               với các trang cũ chưa sửa.
            ============================================= */

            $giaKhuyenMaiLegacy = 0.0;
            $moTaLegacy = $moTaDai;

            /*
                Chỉ tạo mã cho sản phẩm cũ chưa có mã.
                Sản phẩm đã có mã sẽ được giữ nguyên tuyệt đối.
            */
            if ($maSanPham === '') {
                $maSanPham = taoMaSanPham($conn);
            }

            $stmtUpdateProduct = $conn->prepare("
                UPDATE san_pham
                SET
                    ma_san_pham = ?,
                    danh_muc_id = ?,
                    ten_san_pham = ?,
                    gia = ?,
                    gia_khuyen_mai = ?,
                    so_luong = ?,
                    hinh_anh = ?,
                    mo_ta_ngan = ?,
                    mo_ta_dai = ?,
                    mo_ta = ?,
                    trang_thai = ?
                WHERE id = ?
            ");

            if (!$stmtUpdateProduct) {
                throw new Exception(
                    'Không thể chuẩn bị câu lệnh cập nhật sản phẩm: ' .
                    $conn->error
                );
            }

            $stmtUpdateProduct->bind_param(
                'sisddissssii',
                $maSanPham,
                $danhMucId,
                $tenSanPham,
                $gia,
                $giaKhuyenMaiLegacy,
                $soLuong,
                $hinhAnh,
                $moTaNgan,
                $moTaDai,
                $moTaLegacy,
                $trangThai,
                $id
            );

            $stmtUpdateProduct->execute();

            /* =============================================
               E. ĐỒNG BỘ THÔNG SỐ KỸ THUẬT

               Thông số là dữ liệu mô tả hiện tại của sản phẩm,
               nên khi sửa sẽ thay toàn bộ danh sách cũ bằng
               danh sách đang có trên form.
            ============================================= */
            $stmtDeleteSpecs = $conn->prepare("
                DELETE FROM san_pham_thong_so
                WHERE san_pham_id = ?
            ");

            if (!$stmtDeleteSpecs) {
                throw new Exception(
                    'Không thể chuẩn bị câu lệnh cập nhật thông số kỹ thuật: ' .
                    $conn->error
                );
            }

            $stmtDeleteSpecs->bind_param('i', $id);
            $stmtDeleteSpecs->execute();

            if (!empty($technicalSpecs)) {
                $stmtInsertSpec = $conn->prepare("
                    INSERT INTO san_pham_thong_so (
                        san_pham_id,
                        nhom_ma,
                        nhom_ten,
                        ten_thong_so,
                        gia_tri,
                        thu_tu_nhom,
                        thu_tu
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");

                if (!$stmtInsertSpec) {
                    throw new Exception(
                        'Không thể chuẩn bị câu lệnh lưu thông số kỹ thuật: ' .
                        $conn->error
                    );
                }

                foreach ($technicalSpecs as $spec) {
                    $groupKey = $spec['group_key'];
                    $groupLabel = $spec['group_label'];
                    $specName = $spec['name'];
                    $specValue = $spec['value'];
                    $groupOrder = (int)$spec['group_order'];
                    $rowOrder = (int)$spec['row_order'];

                    $stmtInsertSpec->bind_param(
                        'issssii',
                        $id,
                        $groupKey,
                        $groupLabel,
                        $specName,
                        $specValue,
                        $groupOrder,
                        $rowOrder
                    );

                    $stmtInsertSpec->execute();
                }
            }

            /* =============================================
               F. ĐỒNG BỘ MÀU SẢN PHẨM
            ============================================= */

            $stmtDeleteColors = $conn->prepare("
                DELETE FROM san_pham_mau
                WHERE san_pham_id = ?
            ");

            $stmtDeleteColors->bind_param(
                'i',
                $id
            );

            $stmtDeleteColors->execute();

            if (!empty($productColorIds)) {
                $stmtInsertColor = $conn->prepare("
                    INSERT INTO san_pham_mau (
                        san_pham_id,
                        mau_id
                    )
                    VALUES (?, ?)
                ");

                foreach ($productColorIds as $mauId) {
                    $stmtInsertColor->bind_param(
                        'ii',
                        $id,
                        $mauId
                    );

                    $stmtInsertColor->execute();
                }
            }

            /* =============================================
               G. UPDATE / INSERT BIẾN THỂ
            ============================================= */
            /* =============================================
               G. UPDATE / INSERT BIẾN THỂ
            ============================================= */

            $stmtUpdateVariant = $conn->prepare("
                UPDATE san_pham_bien_the
                SET
                    mau_id = ?,
                    ram = ?,
                    rom = ?,
                    gia = ?,
                    so_luong = ?
                WHERE id = ?
                AND san_pham_id = ?
            ");

            $stmtInsertVariant = $conn->prepare("
                INSERT INTO san_pham_bien_the (
                    san_pham_id,
                    mau_id,
                    ram,
                    rom,
                    gia,
                    so_luong
                )
                VALUES (?, ?, ?, ?, ?, ?)
            ");

            $stmtGetVariantImage = null;
            $stmtDeleteVariantImage = null;
            $stmtInsertVariantImage = null;
            $stmtResetVariantMainImage = null;
            $stmtSetVariantMainImage = null;
            $stmtFirstVariantImage = null;
            $stmtCountVariantImages = null;
            $stmtMaxVariantImageOrder = null;

            if ($coBangAnhBienThe) {
                $stmtGetVariantImage =
                    $conn->prepare("
                        SELECT
                            ha.id,
                            ha.`{$cotAnhBienThe}` AS hinh_anh

                        FROM san_pham_bien_the_hinh_anh ha

                        JOIN san_pham_bien_the bt
                            ON bt.id = ha.bien_the_id

                        WHERE ha.id = ?
                        AND ha.bien_the_id = ?
                        AND bt.san_pham_id = ?
                        LIMIT 1
                    ");

                $stmtDeleteVariantImage =
                    $conn->prepare("
                        DELETE FROM san_pham_bien_the_hinh_anh
                        WHERE id = ?
                        AND bien_the_id = ?
                    ");

                $stmtInsertVariantImage =
                    $conn->prepare("
                        INSERT INTO san_pham_bien_the_hinh_anh (
                            bien_the_id,
                            `{$cotAnhBienThe}`,
                            la_anh_chinh,
                            thu_tu,
                            ngay_tao
                        )
                        VALUES (?, ?, ?, ?, NOW())
                    ");

                $stmtResetVariantMainImage =
                    $conn->prepare("
                        UPDATE san_pham_bien_the_hinh_anh
                        SET la_anh_chinh = 0
                        WHERE bien_the_id = ?
                    ");

                $stmtSetVariantMainImage =
                    $conn->prepare("
                        UPDATE san_pham_bien_the_hinh_anh
                        SET la_anh_chinh = 1
                        WHERE id = ?
                        AND bien_the_id = ?
                    ");

                $stmtFirstVariantImage =
                    $conn->prepare("
                        SELECT id
                        FROM san_pham_bien_the_hinh_anh
                        WHERE bien_the_id = ?
                        ORDER BY
                            thu_tu ASC,
                            id ASC
                        LIMIT 1
                    ");

                $stmtCountVariantImages =
                    $conn->prepare("
                        SELECT COUNT(*) AS total
                        FROM san_pham_bien_the_hinh_anh
                        WHERE bien_the_id = ?
                    ");

                $stmtMaxVariantImageOrder =
                    $conn->prepare("
                        SELECT COALESCE(
                            MAX(thu_tu),
                            0
                        ) AS max_order
                        FROM san_pham_bien_the_hinh_anh
                        WHERE bien_the_id = ?
                    ");
            }

            foreach ($variantData as $variant) {
                $variantId =
                    (int)$variant['id'];

                $mauId =
                    (int)$variant['mau_id'];

                $ram =
                    $variant['ram'];

                $rom =
                    $variant['rom'];

                $giaVariant =
                    (float)$variant['gia'];

                $slVariant =
                    (int)$variant['so_luong'];

                if ($variantId > 0) {
                    $stmtUpdateVariant->bind_param(
                        'issdiii',
                        $mauId,
                        $ram,
                        $rom,
                        $giaVariant,
                        $slVariant,
                        $variantId,
                        $id
                    );

                    $stmtUpdateVariant->execute();

                    if ($stmtUpdateVariant->affected_rows === 0) {
                        $stmtCheckVariant = $conn->prepare("
                            SELECT id
                            FROM san_pham_bien_the
                            WHERE id = ?
                            AND san_pham_id = ?
                            LIMIT 1
                        ");

                        $stmtCheckVariant->bind_param(
                            'ii',
                            $variantId,
                            $id
                        );

                        $stmtCheckVariant->execute();

                        if (
                            !$stmtCheckVariant
                                ->get_result()
                                ->fetch_assoc()
                        ) {
                            throw new Exception(
                                'Có biến thể không thuộc sản phẩm này'
                            );
                        }
                    }
                } else {
                    $stmtInsertVariant->bind_param(
                        'iissdi',
                        $id,
                        $mauId,
                        $ram,
                        $rom,
                        $giaVariant,
                        $slVariant
                    );

                    $stmtInsertVariant->execute();

                    $variantId =
                        $stmtInsertVariant->insert_id;
                }

                /* -----------------------------------------
                   ẢNH RIÊNG CỦA BIẾN THỂ
                ----------------------------------------- */
                $formKey =
                    (string)$variant['form_key'];

                $newVariantImageNames =
                    $_FILES['variant_images']['name'][$formKey]
                    ?? [];

                $newVariantImageNames =
                    is_array($newVariantImageNames)
                        ? $newVariantImageNames
                        : [];

                $newVariantImageCount =
                    count(
                        array_filter(
                            $newVariantImageNames,
                            static fn($name) =>
                                trim(
                                    (string)$name
                                ) !== ''
                        )
                    );

                $deleteIdsForVariant = [];

                if ($variantId > 0) {
                    foreach (
                        $deleteVariantImageIds
                        as $deleteImageId
                    ) {
                        if (!$coBangAnhBienThe) {
                            break;
                        }

                        $stmtGetVariantImage->bind_param(
                            'iii',
                            $deleteImageId,
                            $variantId,
                            $id
                        );

                        $stmtGetVariantImage->execute();

                        $deleteImageRow =
                            $stmtGetVariantImage
                                ->get_result()
                                ->fetch_assoc();

                        if ($deleteImageRow) {
                            $deleteIdsForVariant[] =
                                $deleteImageId;
                        }
                    }
                }

                if (
                    (
                        $newVariantImageCount > 0 ||
                        !empty($deleteIdsForVariant)
                    ) &&
                    !$coBangAnhBienThe
                ) {
                    throw new Exception(
                        'Chưa có bảng san_pham_bien_the_hinh_anh. ' .
                        'Hãy chạy migration ảnh biến thể trước.'
                    );
                }

                if ($coBangAnhBienThe) {
                    $stmtCountVariantImages->bind_param(
                        'i',
                        $variantId
                    );

                    $stmtCountVariantImages->execute();

                    $countImageData =
                        $stmtCountVariantImages
                            ->get_result()
                            ->fetch_assoc();

                    $currentVariantImageCount =
                        (int)(
                            $countImageData['total']
                            ?? 0
                        );

                    $remainingAfterDelete =
                        max(
                            0,
                            $currentVariantImageCount -
                            count(
                                $deleteIdsForVariant
                            )
                        );

                    if (
                        $remainingAfterDelete +
                        $newVariantImageCount >
                        5
                    ) {
                        throw new Exception(
                            "Biến thể {$ram} / {$rom} chỉ được tối đa 5 ảnh"
                        );
                    }

                    foreach (
                        $deleteIdsForVariant
                        as $deleteImageId
                    ) {
                        $stmtGetVariantImage->bind_param(
                            'iii',
                            $deleteImageId,
                            $variantId,
                            $id
                        );

                        $stmtGetVariantImage->execute();

                        $deleteImageRow =
                            $stmtGetVariantImage
                                ->get_result()
                                ->fetch_assoc();

                        if (!$deleteImageRow) {
                            continue;
                        }

                        $variantFilesToDelete[] =
                            (string)$deleteImageRow[
                                'hinh_anh'
                            ];

                        $stmtDeleteVariantImage
                            ->bind_param(
                                'ii',
                                $deleteImageId,
                                $variantId
                            );

                        $stmtDeleteVariantImage
                            ->execute();
                    }

                    $newVariantImageIdsByIndex = [];

                    if ($newVariantImageCount > 0) {
                        $variantUploadDir =
                            '../../uploads/bien_the/';

                        if (!is_dir($variantUploadDir)) {
                            if (
                                !mkdir(
                                    $variantUploadDir,
                                    0777,
                                    true
                                ) &&
                                !is_dir(
                                    $variantUploadDir
                                )
                            ) {
                                throw new Exception(
                                    'Không thể tạo thư mục ảnh biến thể'
                                );
                            }
                        }

                        $stmtMaxVariantImageOrder
                            ->bind_param(
                                'i',
                                $variantId
                            );

                        $stmtMaxVariantImageOrder
                            ->execute();

                        $maxOrderData =
                            $stmtMaxVariantImageOrder
                                ->get_result()
                                ->fetch_assoc();

                        $variantImageOrder =
                            (int)(
                                $maxOrderData[
                                    'max_order'
                                ]
                                ?? 0
                            );

                        foreach (
                            $newVariantImageNames
                            as $imageIndex =>
                                $originalName
                        ) {
                            if (
                                trim(
                                    (string)$originalName
                                ) === ''
                            ) {
                                continue;
                            }

                            $error =
                                (int)(
                                    $_FILES[
                                        'variant_images'
                                    ]['error']
                                    [$formKey]
                                    [$imageIndex]
                                    ?? UPLOAD_ERR_NO_FILE
                                );

                            if (
                                $error !==
                                UPLOAD_ERR_OK
                            ) {
                                throw new Exception(
                                    "Ảnh biến thể {$ram} / {$rom} tải lên bị lỗi"
                                );
                            }

                            $size =
                                (int)(
                                    $_FILES[
                                        'variant_images'
                                    ]['size']
                                    [$formKey]
                                    [$imageIndex]
                                    ?? 0
                                );

                            if (
                                $size <= 0 ||
                                $size >
                                    5 * 1024 * 1024
                            ) {
                                throw new Exception(
                                    "Mỗi ảnh biến thể {$ram} / {$rom} tối đa 5MB"
                                );
                            }

                            $tmpName =
                                (string)(
                                    $_FILES[
                                        'variant_images'
                                    ]['tmp_name']
                                    [$formKey]
                                    [$imageIndex]
                                    ?? ''
                                );

                            $extension =
                                strtolower(
                                    pathinfo(
                                        (string)$originalName,
                                        PATHINFO_EXTENSION
                                    )
                                );

                            $allowedExtensionsVariant = [
                                'jpg',
                                'jpeg',
                                'png',
                                'webp'
                            ];

                            $allowedMimeTypesVariant = [
                                'image/jpeg',
                                'image/png',
                                'image/webp'
                            ];

                            $imageInfo =
                                @getimagesize(
                                    $tmpName
                                );

                            if (
                                !in_array(
                                    $extension,
                                    $allowedExtensionsVariant,
                                    true
                                ) ||
                                !$imageInfo ||
                                !in_array(
                                    $imageInfo['mime'],
                                    $allowedMimeTypesVariant,
                                    true
                                )
                            ) {
                                throw new Exception(
                                    "Ảnh biến thể {$ram} / {$rom} chỉ hỗ trợ JPG, JPEG, PNG hoặc WEBP"
                                );
                            }

                            $newImageName =
                                'bt_' .
                                $variantId .
                                '_' .
                                date('YmdHis') .
                                '_' .
                                bin2hex(
                                    random_bytes(5)
                                ) .
                                '.' .
                                $extension;

                            $destination =
                                $variantUploadDir .
                                $newImageName;

                            if (
                                !move_uploaded_file(
                                    $tmpName,
                                    $destination
                                )
                            ) {
                                throw new Exception(
                                    "Không thể lưu ảnh biến thể {$ram} / {$rom}"
                                );
                            }

                            $variantUploadedPaths[] =
                                $destination;

                            $relativeImagePath =
                                'bien_the/' .
                                $newImageName;

                            $variantImageOrder++;

                            $laAnhChinhMoi = 0;

                            $stmtInsertVariantImage
                                ->bind_param(
                                    'isii',
                                    $variantId,
                                    $relativeImagePath,
                                    $laAnhChinhMoi,
                                    $variantImageOrder
                                );

                            $stmtInsertVariantImage
                                ->execute();

                            $newVariantImageIdsByIndex[
                                (int)$imageIndex
                            ] =
                                (int)$stmtInsertVariantImage->insert_id;
                        }
                    }

                    /*
                        Chuẩn hóa ảnh chính:
                        - Nếu admin chọn ảnh chính cũ còn tồn tại -> dùng ảnh đó.
                        - Nếu không -> lấy ảnh còn lại đầu tiên.
                    */
                    $selectedMainImageId =
                        (int)(
                            $selectedVariantMainImages[
                                $variantId
                            ]
                            ?? 0
                        );

                    $stmtResetVariantMainImage
                        ->bind_param(
                            'i',
                            $variantId
                        );

                    $stmtResetVariantMainImage
                        ->execute();

                    $mainImageSet = false;

                    $selectedNewMainIndex =
                        isset(
                            $selectedNewVariantMainIndexes[
                                $formKey
                            ]
                        ) &&
                        $selectedNewVariantMainIndexes[
                            $formKey
                        ] !== ''
                            ? (int)$selectedNewVariantMainIndexes[
                                $formKey
                            ]
                            : -1;

                    if (
                        $selectedNewMainIndex >= 0 &&
                        isset(
                            $newVariantImageIdsByIndex[
                                $selectedNewMainIndex
                            ]
                        )
                    ) {
                        $newMainImageId =
                            (int)$newVariantImageIdsByIndex[
                                $selectedNewMainIndex
                            ];

                        $stmtSetVariantMainImage
                            ->bind_param(
                                'ii',
                                $newMainImageId,
                                $variantId
                            );

                        $stmtSetVariantMainImage
                            ->execute();

                        $mainImageSet = true;
                    }

                    if (
                        !$mainImageSet &&
                        $selectedMainImageId > 0
                    ) {
                        $stmtGetVariantImage
                            ->bind_param(
                                'iii',
                                $selectedMainImageId,
                                $variantId,
                                $id
                            );

                        $stmtGetVariantImage
                            ->execute();

                        $selectedMainRow =
                            $stmtGetVariantImage
                                ->get_result()
                                ->fetch_assoc();

                        if ($selectedMainRow) {
                            $stmtSetVariantMainImage
                                ->bind_param(
                                    'ii',
                                    $selectedMainImageId,
                                    $variantId
                                );

                            $stmtSetVariantMainImage
                                ->execute();

                            $mainImageSet = true;
                        }
                    }

                    if (!$mainImageSet) {
                        $stmtFirstVariantImage
                            ->bind_param(
                                'i',
                                $variantId
                            );

                        $stmtFirstVariantImage
                            ->execute();

                        $firstVariantImageRow =
                            $stmtFirstVariantImage
                                ->get_result()
                                ->fetch_assoc();

                        if ($firstVariantImageRow) {
                            $firstVariantImageId =
                                (int)$firstVariantImageRow[
                                    'id'
                                ];

                            $stmtSetVariantMainImage
                                ->bind_param(
                                    'ii',
                                    $firstVariantImageId,
                                    $variantId
                                );

                            $stmtSetVariantMainImage
                                ->execute();
                        }
                    }
                }

            }

            /* =============================================
               H. ĐỒNG BỘ GIÁ + TỔNG TỒN KHO
            ============================================= */

            /* =============================================
               I. ĐỒNG BỘ TỔNG TỒN KHO
            ============================================= */

            $stmtSyncProductFromVariants =
                $conn->prepare("
                    UPDATE san_pham
                    SET
                        gia = (
                            SELECT COALESCE(
                                MIN(gia),
                                0
                            )
                            FROM san_pham_bien_the
                            WHERE san_pham_id = ?
                        ),
                        so_luong = (
                            SELECT COALESCE(
                                SUM(so_luong),
                                0
                            )
                            FROM san_pham_bien_the
                            WHERE san_pham_id = ?
                        )
                    WHERE id = ?
                ");

            $stmtSyncProductFromVariants
                ->bind_param(
                    'iii',
                    $id,
                    $id,
                    $id
                );

            $stmtSyncProductFromVariants
                ->execute();

            /*
                Nếu ảnh đại diện hiện tại bị xóa khỏi ảnh biến thể
                và không có ảnh chung thay thế, lấy ảnh biến thể
                đầu tiên còn tồn tại làm ảnh đại diện sản phẩm.
            */
            if (
                $coBangAnhBienThe &&
                (
                    $hinhAnh === '' ||
                    in_array(
                        $hinhAnh,
                        $variantFilesToDelete,
                        true
                    )
                )
            ) {
                $stmtFallbackVariantImage =
                    $conn->prepare("
                        SELECT
                            ha.`{$cotAnhBienThe}` AS hinh_anh
                        FROM san_pham_bien_the_hinh_anh ha

                        JOIN san_pham_bien_the bt
                            ON bt.id = ha.bien_the_id

                        WHERE bt.san_pham_id = ?

                        ORDER BY
                            ha.la_anh_chinh DESC,
                            ha.thu_tu ASC,
                            ha.id ASC

                        LIMIT 1
                    ");

                if ($stmtFallbackVariantImage) {
                    $stmtFallbackVariantImage
                        ->bind_param(
                            'i',
                            $id
                        );

                    $stmtFallbackVariantImage
                        ->execute();

                    $fallbackImageRow =
                        $stmtFallbackVariantImage
                            ->get_result()
                            ->fetch_assoc();

                    $fallbackVariantImage =
                        (string)(
                            $fallbackImageRow[
                                'hinh_anh'
                            ]
                            ?? ''
                        );

                    if ($fallbackVariantImage !== '') {
                        $stmtUpdateRepresentative =
                            $conn->prepare("
                                UPDATE san_pham
                                SET hinh_anh = ?
                                WHERE id = ?
                                LIMIT 1
                            ");

                        if ($stmtUpdateRepresentative) {
                            $stmtUpdateRepresentative
                                ->bind_param(
                                    'si',
                                    $fallbackVariantImage,
                                    $id
                                );

                            $stmtUpdateRepresentative
                                ->execute();

                            $hinhAnh =
                                $fallbackVariantImage;
                        }
                    }
                }
            }

            /* =============================================
               J. COMMIT
            /* =============================================
               J. COMMIT
            ============================================= */

            $conn->commit();

            /*
                Xóa file vật lý sau khi database đã commit.
            */
            foreach ($filesToDelete as $oldFile) {
                if ($oldFile === $hinhAnh) {
                    continue;
                }

                $filePath =
                    '../../uploads/' . $oldFile;

                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }

            foreach (
                $variantFilesToDelete
                as $oldVariantFile
            ) {
                if ($oldVariantFile === $hinhAnh) {
                    continue;
                }

                $variantFilePath =
                    '../../uploads/' .
                    $oldVariantFile;

                if (is_file($variantFilePath)) {
                    @unlink($variantFilePath);
                }
            }

            header(
                'Location: index.php?updated=1'
            );

            exit;

        } catch (Throwable $e) {
            $conn->rollback();

            foreach ($uploadedPaths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            foreach (
                $variantUploadedPaths
                as $path
            ) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            throw $e;
        }

    } catch (Throwable $e) {
        $errorMessage =
            $e->getMessage();
    }
}
?>


<?php
$variantsByColor = [];

foreach ($variants as $variantItem) {
    $colorId =
        (int)($variantItem['mau_id'] ?? 0);

    if (!isset($variantsByColor[$colorId])) {
        $variantsByColor[$colorId] = [];
    }

    $variantsByColor[$colorId][] =
        $variantItem;
}
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>
.edit-product-card {
    border: 0;
    border-radius: 16px;
    overflow: hidden;
}

.section-box {
    border: 1px solid #e8e8e8;
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 22px;
    background: #fff;
}

.section-title {
    font-weight: 700;
    margin-bottom: 4px;
}


.product-code-box {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 18px;
    padding: 14px 16px;
    border: 1px solid #e7e9ee;
    border-radius: 12px;
    background: #f8f9fb;
}

.product-code-label {
    color: #6c757d;
    font-size: 12px;
    font-weight: 600;
}

.product-code-value {
    margin-top: 3px;
    color: #212529;
    font-size: 20px;
    font-weight: 800;
    letter-spacing: 1px;
}

.product-code-badge {
    padding: 7px 11px;
    border-radius: 999px;
    background: #fff0eb;
    color: #ee4d2d;
    font-size: 12px;
    font-weight: 700;
}

.color-option {
    border: 1px solid #dee2e6;
    border-radius: 10px;
    padding: 9px 12px;
    cursor: pointer;
}

.color-option:hover {
    border-color: #999;
}

.product-image-item {
    position: relative;
    width: 145px;
    border: 1px solid #ddd;
    border-radius: 12px;
    padding: 8px;
    background: #fff;
}

.product-image-item img {
    width: 100%;
    height: 120px;
    object-fit: contain;
    border-radius: 8px;
}

.product-image-item.main-image {
    border: 2px solid #198754;
}

.image-main-label {
    position: absolute;
    top: 6px;
    left: 6px;
    background: #198754;
    color: #fff;
    border-radius: 5px;
    font-size: 11px;
    padding: 3px 6px;
}

.image-preview-item {
    width: 120px;
    border: 1px solid #ddd;
    border-radius: 10px;
    padding: 6px;
    text-align: center;
    background: #fff;
}

.image-preview-item img {
    width: 100%;
    height: 100px;
    object-fit: contain;
    border-radius: 7px;
}

.variant-card {
    border: 1px solid #dee2e6;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 12px;
    background: #fff;
}

.variant-image-box {
    margin-top: 12px;
    padding: 14px;
    border: 1px dashed #b8c7dc;
    border-radius: 10px;
    background: #f8fbff;
}

.variant-image-title {
    color: #315c8d;
    font-size: 13px;
    font-weight: 800;
}

.variant-image-note {
    margin-top: 3px;
    color: #7b8492;
    font-size: 11px;
}

.variant-existing-images,
.variant-image-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 10px;
}

.variant-image-item,
.variant-image-preview-item {
    position: relative;
    width: 105px;
    padding: 6px;
    border: 1px solid #dde3ea;
    border-radius: 9px;
    background: #fff;
}

.variant-image-item img,
.variant-image-preview-item img {
    width: 100%;
    height: 88px;
    object-fit: contain;
    border-radius: 6px;
}

.variant-main-label {
    position: absolute;
    top: 4px;
    left: 4px;
    padding: 2px 5px;
    border-radius: 5px;
    background: #198754;
    color: #fff;
    font-size: 9px;
    font-weight: 800;
}

.variant-image-preview-item div {
    margin-top: 3px;
    color: #737b86;
    font-size: 9px;
    text-align: center;
}

.variant-promo-box {
    display: none;
    background: #fff8f4;
    border: 1px dashed #ff8a65;
    border-radius: 10px;
    padding: 14px;
    margin-top: 12px;
}

.promo-title {
    color: #ee4d2d;
    font-weight: 700;
}

.char-count {
    font-size: 12px;
    color: #777;
    text-align: right;
    margin-top: 4px;
}


/* =========================================================
   QUẢN LÝ ẢNH MỚI TRƯỚC KHI LƯU
========================================================= */

const selectedFileStore = new WeakMap();

function getSelectedFiles(input) {
    if (!selectedFileStore.has(input)) {
        selectedFileStore.set(
            input,
            Array.from(input.files || [])
        );
    }

    return selectedFileStore.get(input);
}

function applyFilesToInput(input, files) {
    const transfer =
        new DataTransfer();

    files.forEach(function (file) {
        transfer.items.add(file);
    });

    input.files =
        transfer.files;

    selectedFileStore.set(
        input,
        files
    );
}

function moveNewImageToMain(
    input,
    index
) {
    const files =
        [...getSelectedFiles(input)];

    if (
        index < 0 ||
        index >= files.length
    ) {
        return;
    }

    const [selected] =
        files.splice(index, 1);

    files.unshift(selected);

    applyFilesToInput(
        input,
        files
    );

    if (input.id === 'newImages') {
        const mainIndex =
            document.getElementById(
                'newMainProductIndex'
            );

        if (mainIndex) {
            mainIndex.value = '0';
        }

        renderNewProductImages(
            input
        );
    } else {
        renderNewVariantImages(
            input,
            0
        );
    }
}

function removeNewSelectedImage(
    input,
    index
) {
    const files =
        [...getSelectedFiles(input)];

    if (
        index < 0 ||
        index >= files.length
    ) {
        return;
    }

    files.splice(index, 1);

    applyFilesToInput(
        input,
        files
    );

    if (input.id === 'newImages') {
        const mainIndex =
            document.getElementById(
                'newMainProductIndex'
            );

        if (mainIndex) {
            mainIndex.value =
                files.length > 0
                    ? '0'
                    : '';
        }

        renderNewProductImages(
            input
        );
    } else {
        renderNewVariantImages(
            input,
            0
        );
    }
}

function renderNewProductImages(input) {
    const preview =
        document.getElementById(
            'newImagePreview'
        );

    if (!preview) {
        return;
    }

    preview.innerHTML = '';

    const files =
        getSelectedFiles(input);

    files.forEach(
        function (file, index) {
            const reader =
                new FileReader();

            reader.onload =
                function (event) {
                    const item =
                        document.createElement(
                            'div'
                        );

                    item.className =
                        'image-preview-item ' +
                        'new-image-preview-item' +
                        (index === 0
                            ? ' is-main'
                            : '');

                    item.innerHTML = `
                        ${index === 0
                            ? '<span class="new-main-badge">Ảnh chính</span>'
                            : ''
                        }

                        <img
                            src="${event.target.result}"
                            alt=""
                        >

                        <div class="image-action-row">
                            <label class="form-check small mb-0">
                                <input
                                    type="radio"
                                    class="form-check-input"
                                    name="new_product_main_preview"
                                    ${index === 0 ? 'checked' : ''}
                                    onchange="moveNewImageToMain(this.closest('.new-image-preview-item')._fileInput, ${index})"
                                >
                                Ảnh chính
                            </label>

                            <label class="form-check small text-danger mb-0">
                                <input
                                    type="radio"
                                    class="form-check-input"
                                    name="delete_new_product_${index}"
                                    onclick="removeNewSelectedImage(this.closest('.new-image-preview-item')._fileInput, ${index})"
                                >
                                Xóa ảnh
                            </label>
                        </div>
                    `;

                    item._fileInput =
                        input;

                    preview.appendChild(
                        item
                    );
                };

            reader.readAsDataURL(file);
        }
    );
}

function renderNewVariantImages(
    input,
    mainIndex = 0
) {
    const card =
        input.closest(
            '.capacity-row'
        );

    if (!card) {
        return;
    }

    const preview =
        card.querySelector(
            '.variant-image-preview'
        );

    if (!preview) {
        return;
    }

    preview.innerHTML = '';

    const files =
        getSelectedFiles(input);

    const formKeyInput =
        card.querySelector(
            'input[name="variant_form_key[]"]'
        );

    const formKey =
        formKeyInput
            ? formKeyInput.value
            : '';

    let hidden =
        card.querySelector(
            '.new-variant-main-index'
        );

    if (!hidden) {
        hidden =
            document.createElement(
                'input'
            );

        hidden.type =
            'hidden';

        hidden.className =
            'new-variant-main-index';

        hidden.name =
            `anh_chinh_bien_the_moi[${formKey}]`;

        card.appendChild(
            hidden
        );
    }

    hidden.value =
        files.length > 0
            ? String(mainIndex)
            : '';

    files.forEach(
        function (file, index) {
            const reader =
                new FileReader();

            reader.onload =
                function (event) {
                    const item =
                        document.createElement(
                            'div'
                        );

                    item.className =
                        'variant-image-preview-item ' +
                        'new-variant-image-preview-item' +
                        (index === mainIndex
                            ? ' is-main'
                            : '');

                    item.innerHTML = `
                        ${index === mainIndex
                            ? '<span class="new-main-badge">Ảnh chính</span>'
                            : ''
                        }

                        <img
                            src="${event.target.result}"
                            alt=""
                        >

                        <div class="image-action-row">
                            <label class="form-check small mb-0">
                                <input
                                    type="radio"
                                    class="form-check-input"
                                    name="new_variant_main_${formKey}"
                                    ${index === mainIndex ? 'checked' : ''}
                                    onchange="moveVariantNewImageToMain(this.closest('.new-variant-image-preview-item')._fileInput, ${index})"
                                >
                                Ảnh chính
                            </label>

                            <label class="form-check small text-danger mb-0">
                                <input
                                    type="radio"
                                    class="form-check-input"
                                    name="delete_new_variant_${formKey}_${index}"
                                    onclick="removeVariantNewImage(this.closest('.new-variant-image-preview-item')._fileInput, ${index})"
                                >
                                Xóa ảnh
                            </label>
                        </div>
                    `;

                    item._fileInput =
                        input;

                    preview.appendChild(
                        item
                    );
                };

            reader.readAsDataURL(file);
        }
    );
}

function moveVariantNewImageToMain(
    input,
    index
) {
    const files =
        [...getSelectedFiles(input)];

    if (
        index < 0 ||
        index >= files.length
    ) {
        return;
    }

    const [selected] =
        files.splice(index, 1);

    files.unshift(selected);

    applyFilesToInput(
        input,
        files
    );

    renderNewVariantImages(
        input,
        0
    );
}

function removeVariantNewImage(
    input,
    index
) {
    const files =
        [...getSelectedFiles(input)];

    if (
        index < 0 ||
        index >= files.length
    ) {
        return;
    }

    files.splice(index, 1);

    applyFilesToInput(
        input,
        files
    );

    renderNewVariantImages(
        input,
        0
    );
}

/* =========================================================
   TAB MÔ TẢ / THÔNG SỐ KỸ THUẬT
========================================================= */
.content-editor {
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    overflow: hidden;
    background: #fff;
}

.content-tabs {
    display: flex;
    gap: 10px;
    padding: 14px;
    border-bottom: 1px solid #eceef2;
    background: #fafbfc;
}

.content-tab-btn {
    border: 1px solid #dfe3e8;
    border-radius: 11px;
    padding: 10px 18px;
    background: #fff;
    color: #333;
    font-weight: 700;
    transition: .2s;
}

.content-tab-btn:hover {
    border-color: #86b7fe;
    color: #0d6efd;
}

.content-tab-btn.active {
    border-color: #86b7fe;
    background: #eef6ff;
    color: #0d6efd;
    box-shadow: 0 0 0 2px rgba(13, 110, 253, .06);
}

.content-tab-panel {
    display: none;
    padding: 20px;
}

.content-tab-panel.active {
    display: block;
}

/* =========================================================
   NHÓM THÔNG SỐ THU GỌN
========================================================= */
.spec-help-box {
    margin-bottom: 16px;
    padding: 12px 14px;
    border-radius: 10px;
    background: #f7f9fc;
    color: #666;
    font-size: 13px;
}

.spec-group {
    margin-bottom: 12px;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    overflow: hidden;
    background: #fff;
}

.spec-group:last-child {
    margin-bottom: 0;
}

.spec-group-header {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    border: 0;
    padding: 15px 16px;
    background: #f3f5f8;
    color: #222;
    text-align: left;
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
}

.spec-group-header:hover {
    background: #edf1f6;
}

.spec-group-arrow {
    font-size: 19px;
    line-height: 1;
    transition: transform .2s ease;
}

.spec-group.open .spec-group-arrow {
    transform: rotate(180deg);
}

.spec-group-body {
    display: none;
    padding: 16px;
    border-top: 1px solid #e5e7eb;
}

.spec-group.open .spec-group-body {
    display: block;
}

.spec-row {
    padding: 12px;
    margin-bottom: 10px;
    border: 1px solid #e8eaed;
    border-radius: 10px;
    background: #fbfcfd;
}

.spec-row:last-child {
    margin-bottom: 0;
}

.spec-value-input {
    min-height: 42px;
    resize: vertical;
}

@media (max-width: 767px) {
    .content-tabs {
        flex-direction: column;
    }

    .content-tab-btn {
        width: 100%;
    }
}

.image-action-row {
    display: flex;
    gap: 6px;
    margin-top: 7px;
}

.image-action-row .btn,
.image-action-row .form-check {
    flex: 1;
    padding: 4px 6px;
    font-size: 11px;
}

.image-action-row .form-check {
    display: flex;
    align-items: center;
    gap: 6px;
}

.new-image-preview-item,
.new-variant-image-preview-item {
    position: relative;
}

.new-image-preview-item.is-main,
.new-variant-image-preview-item.is-main {
    border-color: #dc3545;
    box-shadow: 0 0 0 2px rgba(220, 53, 69, .08);
}

.new-main-badge {
    position: absolute;
    top: 6px;
    left: 6px;
    z-index: 2;
    padding: 3px 7px;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
}

.product-image-item .form-check,
.variant-image-item .form-check {
    margin-bottom: 0;
}


.color-group-card {
    border: 1px solid #dfe3e8;
    border-radius: 16px;
    margin-bottom: 18px;
    overflow: hidden;
    background: #fff;
}

.color-group-header {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 14px;
    padding: 15px;
    background: #f8fafc;
    border-bottom: 1px solid #e8edf2;
}

.color-group-main {
    flex: 1;
    max-width: 360px;
}

.color-group-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.capacity-list {
    padding: 14px;
}

.capacity-row {
    border: 1px solid #e7e9ee;
    border-radius: 13px;
    padding: 13px;
    margin-bottom: 12px;
    background: #fbfcfd;
}

.capacity-row:last-child {
    margin-bottom: 0;
}

.capacity-row .input-group .form-select {
    max-width: 92px;
}

.capacity-row .input-group-text {
    min-width: 44px;
    justify-content: center;
    font-weight: 700;
}

.currency-display,
.promo-value-display {
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.capacity-extra {
    margin-top: 12px;
}

@media (max-width: 767px) {
    .color-group-header {
        align-items: stretch;
        flex-direction: column;
    }

    .color-group-main {
        max-width: none;
    }

    .color-group-actions .btn {
        flex: 1;
    }
}


.product-image-item.pending-delete,
.variant-image-item.pending-delete {
    opacity: .28;
    filter: grayscale(1);
}

.product-image-item.pending-delete::after,
.variant-image-item.pending-delete::after {
    content: "Sẽ xóa";
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: inherit;
    background: rgba(255, 255, 255, .62);
    color: #dc3545;
    font-weight: 800;
    pointer-events: none;
}

.product-image-item.live-main,
.variant-image-item.live-main {
    border: 2px solid #198754 !important;
    box-shadow: 0 0 0 2px rgba(25, 135, 84, .08);
}

.live-main-badge {
    position: absolute;
    top: 6px;
    left: 6px;
    z-index: 3;
    padding: 3px 6px;
    border-radius: 5px;
    background: #198754;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
}


.capacity-row .storage-select {
    width: 100%;
    max-width: none;
}
</style>

<div class="container mt-4 mb-5">
    <div class="card shadow edit-product-card">

        <div class="card-header bg-warning py-3">
            <h4 class="mb-0">
                ✏️ Sửa sản phẩm
            </h4>
        </div>

        <div class="card-body p-4">

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form
                method="POST"
                enctype="multipart/form-data"
            >

                <?php if (!$coBangAnhBienThe): ?>
                    <div class="alert alert-warning">
                        <strong>Chưa bật ảnh theo biến thể.</strong>
                        Hãy chạy
                        <code>migration_anh_bien_the_san_pham.sql</code>
                        rồi tải lại trang.
                    </div>
                <?php endif; ?>

                <!-- =====================================================
                     THÔNG TIN CƠ BẢN
                ====================================================== -->
                <div class="section-box">

                    <div class="section-title">
                        Thông tin cơ bản
                    </div>

                    <div class="text-muted small mb-3">
                        Mã sản phẩm, tên, danh mục và trạng thái.
                        Giá bán được quản lý trực tiếp ở từng biến thể.
                    </div>

                    <div class="product-code-box">

                        <div>
                            <div class="product-code-label">
                                Mã sản phẩm
                            </div>

                            <div class="product-code-value">
                                <?php if ($maSanPhamHienTai !== ''): ?>

                                    <?= htmlspecialchars(
                                        $maSanPhamHienTai
                                    ) ?>

                                <?php else: ?>

                                    Chưa có mã

                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if ($maSanPhamHienTai !== ''): ?>

                            <span class="product-code-badge">
                                🔒 Mã cố định
                            </span>

                        <?php else: ?>

                            <span class="product-code-badge">
                                ✨ Sẽ tạo mã 8686xxxx khi lưu
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            Tên sản phẩm
                        </label>

                        <input
                            type="text"
                            name="ten_san_pham"
                            class="form-control"
                            value="<?= htmlspecialchars($_POST['ten_san_pham'] ?? $sanPham['ten_san_pham']) ?>"
                            required
                        >
                    </div>

                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                Danh mục
                            </label>

                            <select
                                name="danh_muc_id"
                                class="form-select"
                                required
                            >
                                <?php
                                $currentCategoryId =
                                    (int)(
                                        $_POST['danh_muc_id']
                                        ?? $sanPham['danh_muc_id']
                                    );
                                ?>

                                <?php foreach ($danhMucList as $dm): ?>
                                    <option
                                        value="<?= (int)$dm['id'] ?>"
                                        <?= (int)$dm['id'] === $currentCategoryId
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= htmlspecialchars(
                                            $dm['ten_danh_muc']
                                        ) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold">
                                Trạng thái
                            </label>

                            <?php
                            $currentStatus =
                                (int)(
                                    $_POST['trang_thai']
                                    ?? $sanPham['trang_thai']
                                );
                            ?>

                            <select
                                name="trang_thai"
                                class="form-select"
                            >
                                <option
                                    value="1"
                                    <?= $currentStatus === 1
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Hiển thị
                                </option>

                                <option
                                    value="0"
                                    <?= $currentStatus === 0
                                        ? 'selected'
                                        : '' ?>
                                >
                                    Ẩn
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="alert alert-info mb-4">
                    <div class="fw-bold">
                        📱 Giá và tồn kho được đồng bộ từ biến thể
                    </div>

                    <div class="small mt-1">
                        Giá sản phẩm = giá thấp nhất trong các biến thể.
                        Tồn kho sản phẩm = tổng số lượng của tất cả biến thể.
                    </div>
                </div>

                <!-- =====================================================
                     NỘI DUNG SẢN PHẨM
                <!-- =====================================================
                     NỘI DUNG SẢN PHẨM
                     MÔ TẢ / THÔNG SỐ KỸ THUẬT
                ====================================================== -->
                <div class="section-box">

                    <div class="section-title">
                        Nội dung sản phẩm
                    </div>

                    <div class="text-muted small mb-3">
                        Sửa phần mô tả hoặc các thông số kỹ thuật hiển thị ở trang chi tiết sản phẩm.
                    </div>

                    <div class="content-editor">

                        <div class="content-tabs">

                            <button
                                type="button"
                                id="descriptionTabButton"
                                class="content-tab-btn active"
                                onclick="switchContentTab('description')"
                            >
                                📝 Mô tả
                            </button>

                            <button
                                type="button"
                                id="specificationTabButton"
                                class="content-tab-btn"
                                onclick="switchContentTab('specification')"
                            >
                                ⚙️ Thông số kỹ thuật
                            </button>

                        </div>

                        <!-- =============================================
                             TAB MÔ TẢ
                        ============================================== -->
                        <div
                            id="descriptionTabPanel"
                            class="content-tab-panel active"
                        >

                            <div class="mb-3">
                                <label class="form-label fw-bold">
                                    Mô tả ngắn
                                </label>

                                <?php
                                $currentShortDescription =
                                    $_POST['mo_ta_ngan'] ?? $moTaNganHienTai;
                                ?>

                                <textarea
                                    name="mo_ta_ngan"
                                    id="shortDescription"
                                    class="form-control"
                                    rows="3"
                                    maxlength="500"
                                    placeholder="VD: iPhone cao cấp với camera mạnh, màn hình 120Hz..."
                                    oninput="updateShortDescriptionCount()"
                                ><?= htmlspecialchars($currentShortDescription) ?></textarea>

                                <div
                                    id="shortDescriptionCount"
                                    class="char-count"
                                >
                                    0 / 500
                                </div>
                            </div>

                            <div class="mb-0">
                                <label class="form-label fw-bold">
                                    Mô tả chi tiết
                                </label>

                                <?php
                                $currentLongDescription =
                                    $_POST['mo_ta_dai'] ?? $moTaDaiHienTai;
                                ?>

                                <textarea
                                    name="mo_ta_dai"
                                    class="form-control"
                                    rows="10"
                                    placeholder="Nhập nội dung chi tiết về thiết kế, màn hình, camera, hiệu năng, pin..."
                                ><?= htmlspecialchars($currentLongDescription) ?></textarea>

                                <div class="form-text">
                                    Nội dung này sẽ hiển thị trong tab Mô tả ở trang chi tiết sản phẩm.
                                </div>
                            </div>

                        </div>

                        <!-- =============================================
                             TAB THÔNG SỐ KỸ THUẬT
                        ============================================== -->
                        <div
                            id="specificationTabPanel"
                            class="content-tab-panel"
                        >

                            <div class="spec-help-box">
                                Mỗi nhóm có thể có nhiều thông số. Bạn có thể sửa thông số cũ,
                                thêm dòng mới hoặc xóa dòng không còn sử dụng. Giá trị có thể xuống dòng.
                            </div>

                            <?php foreach ($specGroups as $groupKey => $groupInfo): ?>

                                <?php
                                $groupHasData =
                                    !empty($specInitialData[$groupKey]);

                                $groupOpen =
                                    $groupHasData ||
                                    $groupKey === 'cau_hinh_bo_nho';
                                ?>

                                <div
                                    class="spec-group <?= $groupOpen ? 'open' : '' ?>"
                                    id="specGroup_<?= htmlspecialchars($groupKey) ?>"
                                >

                                    <button
                                        type="button"
                                        class="spec-group-header"
                                        onclick="toggleSpecGroup(this)"
                                    >
                                        <span>
                                            <?= htmlspecialchars($groupInfo['label']) ?>
                                        </span>

                                        <span class="spec-group-arrow">
                                            ⌃
                                        </span>
                                    </button>

                                    <div class="spec-group-body">

                                        <div
                                            id="specRows_<?= htmlspecialchars($groupKey) ?>"
                                            class="spec-rows"
                                        ></div>

                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm mt-2"
                                            onclick="addSpecRow('<?= htmlspecialchars($groupKey, ENT_QUOTES, 'UTF-8') ?>')"
                                        >
                                            + Thêm thông số
                                        </button>

                                    </div>
                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>
                </div>

                <!-- =====================================================
                     HÌNH ẢNH CHUNG HIỆN TẠI
                <!-- =====================================================
                     HÌNH ẢNH HIỆN TẠI
                ====================================================== -->
                <div class="section-box">

                    <div class="section-title">
                        Hình ảnh chung hiện tại
                    </div>

                    <div class="d-flex flex-wrap gap-3 mt-3">

                        <?php if (!empty($productImages)): ?>

                            <?php foreach ($productImages as $image): ?>

                                <?php
                                $isMain =
                                    $image['hinh_anh'] ===
                                    ($sanPham['hinh_anh'] ?? '');
                                ?>

                                <div
                                    class="product-image-item <?= $isMain ? 'main-image' : '' ?>"
                                >
                                    <?php if ($isMain): ?>
                                        <span class="image-main-label">
                                            Đại diện
                                        </span>
                                    <?php endif; ?>

                                    <img
                                        src="../../uploads/<?= htmlspecialchars($image['hinh_anh']) ?>"
                                        alt=""
                                    >

                                    <div class="form-check mt-2">
                                        <input
                                            class="form-check-input existing-product-main-radio"
                                            type="radio"
                                            name="anh_dai_dien"
                                            value="<?= (int)$image['id'] ?>"
                                            id="mainImage<?= (int)$image['id'] ?>"
                                            <?= $isMain ? 'checked' : '' ?>
                                        >

                                        <label
                                            class="form-check-label small"
                                            for="mainImage<?= (int)$image['id'] ?>"
                                        >
                                            Ảnh đại diện
                                        </label>
                                    </div>

                                    <div class="form-check mt-1">
                                        <input
                                            class="form-check-input delete-image-checkbox"
                                            type="checkbox"
                                            name="xoa_hinh[]"
                                            value="<?= (int)$image['id'] ?>"
                                            id="deleteImage<?= (int)$image['id'] ?>"
                                            data-radio-id="mainImage<?= (int)$image['id'] ?>"
                                        >

                                        <label
                                            class="form-check-label text-danger small"
                                            for="deleteImage<?= (int)$image['id'] ?>"
                                        >
                                            Xóa ảnh
                                        </label>
                                    </div>
                                </div>

                            <?php endforeach; ?>

                        <?php elseif (!empty($sanPham['hinh_anh'])): ?>

                            <div class="product-image-item main-image">

                                <span class="image-main-label">
                                    Đại diện
                                </span>

                                <img
                                    src="../../uploads/<?= htmlspecialchars($sanPham['hinh_anh']) ?>"
                                    alt=""
                                >

                                <div class="small text-muted mt-2">
                                    Ảnh sản phẩm cũ chưa nằm trong thư viện ảnh.
                                </div>
                            </div>

                        <?php else: ?>

                            <div class="alert alert-secondary mb-0">
                                Sản phẩm chưa có hình ảnh.
                            </div>

                        <?php endif; ?>

                    </div>
                </div>

                <!-- =====================================================
                     THÊM ẢNH MỚI
                ====================================================== -->
                <div class="section-box">

                    <div class="section-title">
                        Thêm ảnh chung mới
                    </div>

                    <input
                        type="hidden"
                        name="new_main_product_index"
                        id="newMainProductIndex"
                        value=""
                    >

                    <input
                        type="file"
                        name="hinh_anh_moi[]"
                        id="newImages"
                        class="form-control mt-3"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        onchange="previewNewImages(event)"
                    >

                    <div
                        id="newImagePreview"
                        class="d-flex flex-wrap gap-2 mt-3"
                    ></div>
                </div>

                <!-- =====================================================
                     BIẾN THỂ SẢN PHẨM
                ====================================================== -->
                <div class="section-box">

                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="section-title mb-0">
                            Biến thể sản phẩm
                        </div>

                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            onclick="addColorGroup()"
                        >
                            + Thêm biến thể
                        </button>
                    </div>

                    <div id="colorGroupBox">

                        <?php foreach ($variantsByColor as $groupColorId => $groupVariants): ?>
                            <?php
                                $colorGroupKey =
                                    'existing_color_' .
                                    (int)$groupColorId;
                            ?>

                            <div
                                class="color-group-card"
                                data-color-key="<?= htmlspecialchars($colorGroupKey) ?>"
                            >
                                <div class="color-group-header">
                                    <div class="color-group-main">
                                        <label class="form-label small fw-bold">
                                            Màu
                                        </label>

                                        <select
                                            class="form-select color-group-select"
                                            onchange="syncColorGroup(this)"
                                            required
                                        >
                                            <option value="">
                                                -- Chọn màu --
                                            </option>

                                            <?php foreach ($mauSacList as $mau): ?>
                                                <option
                                                    value="<?= (int)$mau['id'] ?>"
                                                    <?= (int)$mau['id'] === (int)$groupColorId
                                                        ? 'selected'
                                                        : '' ?>
                                                >
                                                    <?= htmlspecialchars($mau['ten_mau']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="color-group-actions">
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm"
                                            onclick="addCapacityRow(this)"
                                        >
                                            + Thêm phiên bản
                                        </button>
                                    </div>
                                </div>

                                <div class="capacity-list">

                                    <?php foreach ($groupVariants as $variant): ?>
                                        <?php
                                            $variantId =
                                                (int)$variant['id'];


                                            $variantFormKey =
                                                'existing_' .
                                                $variantId;

                                            $variantImageList =
                                                $variantImagesById[
                                                    $variantId
                                                ]
                                                ?? [];

                                            $romRaw =
                                                strtoupper(
                                                    trim(
                                                        (string)$variant['rom']
                                                    )
                                                );

                                            $storageValue = '';
                                            $storageUnit = 'GB';

                                            if (
                                                preg_match(
                                                    '/^([0-9]+)\s*(GB|TB)$/i',
                                                    $romRaw,
                                                    $romMatch
                                                )
                                            ) {
                                                $storageValue =
                                                    $romMatch[1];
                                                $storageUnit =
                                                    strtoupper(
                                                        $romMatch[2]
                                                    );
                                            } else {
                                                $storageValue =
                                                    preg_replace(
                                                        '/\D+/',
                                                        '',
                                                        $romRaw
                                                    );
                                            }
                                        ?>

                                        <div class="capacity-row variant-row">
                                            <input
                                                type="hidden"
                                                name="variant_id[]"
                                                value="<?= $variantId ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="variant_form_key[]"
                                                value="<?= htmlspecialchars($variantFormKey) ?>"
                                            >


                                            <input
                                                type="hidden"
                                                name="mau_variant[]"
                                                class="variant-color-hidden"
                                                value="<?= (int)$groupColorId ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="ram[]"
                                                value=""
                                            >

                                            <input
                                                type="hidden"
                                                name="rom[]"
                                                class="rom-hidden"
                                                value="<?= htmlspecialchars($variant['rom']) ?>"
                                            >

                                            <div class="row g-2 align-items-end">
                                                <div class="col-md-3">
                                                    <label class="form-label small">
                                                        Dung lượng
                                                    </label>

                                                    <?php
                                                        $storageOptions = [
                                                            '8GB',
                                                            '16GB',
                                                            '32GB',
                                                            '64GB',
                                                            '128GB',
                                                            '256GB',
                                                            '512GB',
                                                            '1TB',
                                                            '2TB'
                                                        ];

                                                        $currentRom =
                                                            strtoupper(
                                                                preg_replace(
                                                                    '/\\s+/',
                                                                    '',
                                                                    (string)$variant['rom']
                                                                )
                                                            );
                                                    ?>

                                                    <select
                                                        class="form-select storage-select"
                                                        required
                                                        onchange="syncStorageValue(this)"
                                                    >
                                                        <option value="">
                                                            -- Chọn dung lượng --
                                                        </option>

                                                        <?php foreach ($storageOptions as $storageOption): ?>
                                                            <option
                                                                value="<?= htmlspecialchars($storageOption) ?>"
                                                                <?= $currentRom === $storageOption
                                                                    ? 'selected'
                                                                    : '' ?>
                                                            >
                                                                <?= htmlspecialchars($storageOption) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <div class="col-md-3">
                                                    <label class="form-label small">
                                                        Giá
                                                    </label>

                                                    <div class="input-group">
                                                        <input
                                                            type="text"
                                                            inputmode="numeric"
                                                            class="form-control currency-display"
                                                            value="<?= number_format((float)$variant['gia'], 0, ',', '.') ?>"
                                                            required
                                                            oninput="syncCurrencyInput(this)"
                                                        >

                                                        <span class="input-group-text">
                                                            đ
                                                        </span>

                                                        <input
                                                            type="hidden"
                                                            name="gia_variant[]"
                                                            class="currency-hidden"
                                                            value="<?= htmlspecialchars($variant['gia']) ?>"
                                                        >
                                                    </div>
                                                </div>

                                                <div class="col-md-2">
                                                    <label class="form-label small">
                                                        Tồn kho
                                                    </label>

                                                    <input
                                                        type="number"
                                                        min="0"
                                                        name="sl_variant[]"
                                                        class="form-control variant-stock"
                                                        value="<?= (int)$variant['so_luong'] ?>"
                                                        oninput="updateTotalStock()"
                                                    >
                                                </div>

                                                <div class="col-md-4 d-flex gap-1">

                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-secondary flex-fill"
                                                        disabled
                                                    >
                                                        Đã lưu
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="capacity-extra">
                                                <div class="variant-image-box">
                                                    <div class="variant-image-title">
                                                        🖼 Ảnh phiên bản
                                                    </div>

                                                    <?php if (!empty($variantImageList)): ?>
                                                        <div class="variant-existing-images">

                                                            <?php foreach ($variantImageList as $variantImage): ?>
                                                                <?php
                                                                    $variantImageId =
                                                                        (int)$variantImage['id'];

                                                                    $isVariantMain =
                                                                        (int)$variantImage['la_anh_chinh'] === 1;
                                                                ?>

                                                                <div class="variant-image-item">
                                                                    <?php if ($isVariantMain): ?>
                                                                        <span class="variant-main-label">
                                                                            Ảnh chính
                                                                        </span>
                                                                    <?php endif; ?>

                                                                    <img
                                                                        src="../../uploads/<?= htmlspecialchars($variantImage['hinh_anh']) ?>"
                                                                        alt=""
                                                                    >

                                                                    <label class="form-check mt-2 small">
                                                                        <input
                                                                            class="form-check-input variant-main-radio"
                                                                            type="radio"
                                                                            name="anh_chinh_bien_the[<?= $variantId ?>]"
                                                                            value="<?= $variantImageId ?>"
                                                                            <?= $isVariantMain ? 'checked' : '' ?>
                                                                        >
                                                                        Ảnh chính
                                                                    </label>

                                                                    <label class="form-check mt-1 small text-danger">
                                                                        <input
                                                                            class="form-check-input delete-variant-image-checkbox"
                                                                            type="checkbox"
                                                                            name="xoa_anh_bien_the[]"
                                                                            value="<?= $variantImageId ?>"
                                                                        >
                                                                        Xóa ảnh
                                                                    </label>
                                                                </div>
                                                            <?php endforeach; ?>

                                                        </div>
                                                    <?php endif; ?>

                                                    <input
                                                        type="file"
                                                        name="variant_images[<?= htmlspecialchars($variantFormKey) ?>][]"
                                                        class="form-control mt-3 variant-image-input"
                                                        accept="image/jpeg,image/png,image/webp"
                                                        multiple
                                                        <?= !$coBangAnhBienThe ? 'disabled' : '' ?>
                                                        onchange="previewVariantImages(this)"
                                                    >

                                                    <div class="variant-image-preview"></div>
                                                </div>

                                            </div>
                                        </div>

                                    <?php endforeach; ?>

                                </div>
                            </div>

                        <?php endforeach; ?>

                    </div>

                    <div
                        id="variantStockInfo"
                        class="alert alert-info mt-3"
                    >
                        Tổng tồn kho:
                        <strong id="totalVariantStock">
                            <?= array_sum(
                                array_map(
                                    fn($v) => (int)$v['so_luong'],
                                    $variants
                                )
                            ) ?>
                        </strong>
                    </div>
                </div>

                <!-- =====================================================
                     NÚT
                ====================================================== -->
                <button
                    type="submit"
                    class="btn btn-success"
                >
                    💾 Cập nhật sản phẩm
                </button>

                <a
                    href="index.php"
                    class="btn btn-secondary"
                >
                    Quay lại
                </a>

            </form>
        </div>
    </div>
</div>

<script>
const colorOptionsHtml = <?= json_encode(
    $colorOptionsHtml,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

const variantImageTableReady =
    <?= $coBangAnhBienThe ? 'true' : 'false' ?>;

const storageOptionsHtml = `
    <option value="">-- Chọn dung lượng --</option>
    <option value="8GB">8GB</option>
    <option value="16GB">16GB</option>
    <option value="32GB">32GB</option>
    <option value="64GB">64GB</option>
    <option value="128GB">128GB</option>
    <option value="256GB">256GB</option>
    <option value="512GB">512GB</option>
    <option value="1TB">1TB</option>
    <option value="2TB">2TB</option>
`;

let variantSequence = 0;

const specGroups = <?= json_encode(
    $specGroups,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

const specInitialData = <?= json_encode(
    $specInitialData,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
) ?>;

/* =========================================================
   TAB MÔ TẢ / THÔNG SỐ KỸ THUẬT
========================================================= */
function switchContentTab(tabName) {
    const descriptionButton =
        document.getElementById('descriptionTabButton');

    const specificationButton =
        document.getElementById('specificationTabButton');

    const descriptionPanel =
        document.getElementById('descriptionTabPanel');

    const specificationPanel =
        document.getElementById('specificationTabPanel');

    if (
        !descriptionButton ||
        !specificationButton ||
        !descriptionPanel ||
        !specificationPanel
    ) {
        return;
    }

    const showDescription =
        tabName === 'description';

    descriptionButton.classList.toggle(
        'active',
        showDescription
    );

    specificationButton.classList.toggle(
        'active',
        !showDescription
    );

    descriptionPanel.classList.toggle(
        'active',
        showDescription
    );

    specificationPanel.classList.toggle(
        'active',
        !showDescription
    );
}

/* =========================================================
   THU GỌN / MỞ NHÓM THÔNG SỐ
========================================================= */
function toggleSpecGroup(button) {
    const group =
        button.closest('.spec-group');

    if (!group) {
        return;
    }

    group.classList.toggle('open');
}

/* =========================================================
   THÊM DÒNG THÔNG SỐ
========================================================= */
function addSpecRow(
    groupKey,
    specName = '',
    specValue = ''
) {
    const container =
        document.getElementById(
            'specRows_' + groupKey
        );

    if (!container || !specGroups[groupKey]) {
        return;
    }

    const row =
        document.createElement('div');

    row.className = 'spec-row';

    row.innerHTML = `
        <input
            type="hidden"
            name="spec_group[]"
            value="${groupKey}"
        >

        <div class="row g-2 align-items-start">

            <div class="col-md-4">
                <label class="form-label small fw-bold">
                    Tên thông số
                </label>

                <input
                    type="text"
                    name="spec_name[]"
                    class="form-control spec-name-input"
                    maxlength="150"
                    placeholder="Ví dụ: Dung lượng pin"
                >
            </div>

            <div class="col-md-7">
                <label class="form-label small fw-bold">
                    Giá trị
                </label>

                <textarea
                    name="spec_value[]"
                    class="form-control spec-value-input"
                    rows="2"
                    maxlength="2000"
                    placeholder="Ví dụ: 37 giờ hoặc nhập nhiều dòng công nghệ"
                ></textarea>
            </div>

            <div class="col-md-1 d-grid">
                <label class="form-label small d-none d-md-block">
                    &nbsp;
                </label>

                <button
                    type="button"
                    class="btn btn-outline-danger"
                    title="Xóa thông số"
                    onclick="removeSpecRow(this)"
                >
                    ×
                </button>
            </div>

        </div>
    `;

    row.querySelector('.spec-name-input').value =
        specName;

    row.querySelector('.spec-value-input').value =
        specValue;

    container.appendChild(row);
}

function removeSpecRow(button) {
    const row =
        button.closest('.spec-row');

    if (row) {
        row.remove();
    }
}

/* =========================================================
   KHỞI TẠO THÔNG SỐ ĐÃ LƯU
========================================================= */
function initializeSpecs() {
    Object.keys(specGroups).forEach(
        function (groupKey) {
            const rows =
                Array.isArray(specInitialData[groupKey])
                    ? specInitialData[groupKey]
                    : [];

            if (rows.length > 0) {
                rows.forEach(function (row) {
                    addSpecRow(
                        groupKey,
                        row.name || '',
                        row.value || ''
                    );
                });
            } else {
                addSpecRow(groupKey);
            }
        }
    );
}


/* =========================================================
   FORMAT & NHÓM MÀU
========================================================= */

let colorGroupSequence = 0;

function onlyDigits(value) {
    return String(value || '').replace(/\D/g, '');
}

function formatThousands(value) {
    const digits =
        onlyDigits(value);

    if (digits === '') {
        return '';
    }

    return Number(digits)
        .toLocaleString('vi-VN');
}

function parsePercentValue(value) {
    let raw = String(value ?? '')
        .trim()
        .replace(',', '.')
        .replace(/[^0-9.]/g, '');

    if (raw === '') {
        return '';
    }

    const firstDot = raw.indexOf('.');

    if (firstDot !== -1) {
        raw =
            raw.slice(0, firstDot + 1) +
            raw.slice(firstDot + 1)
                .replace(/\./g, '');
    }

    let number = parseFloat(raw);

    if (!Number.isFinite(number)) {
        return '';
    }

    number = Math.max(
        0,
        Math.min(100, number)
    );

    return String(number);
}

function syncCurrencyInput(input) {
    const row =
        input.closest('.capacity-row');

    if (!row) {
        return;
    }

    const hidden =
        row.querySelector(
            '.currency-hidden'
        );

    const digits =
        onlyDigits(input.value);

    input.value =
        formatThousands(digits);

    if (hidden) {
        hidden.value = digits;
    }
}

function syncStorageValue(element) {
    const row =
        element.closest('.capacity-row');

    if (!row) {
        return;
    }

    const storageSelect =
        row.querySelector(
            '.storage-select'
        );

    const hidden =
        row.querySelector(
            '.rom-hidden'
        );

    if (hidden) {
        hidden.value =
            storageSelect
                ? storageSelect.value
                : '';
    }
}

function syncColorGroup(select) {
    const group =
        select.closest(
            '.color-group-card'
        );

    if (!group) {
        return;
    }

    const colorId =
        select.value || '';

    group.querySelectorAll(
        '.variant-color-hidden'
    ).forEach(function (input) {
        input.value =
            colorId;
    });
}

function updatePromoInput(select) {
    const row =
        select.closest('.capacity-row');

    if (!row) {
        return;
    }

    const display =
        row.querySelector('.promo-value-display');

    const hidden =
        row.querySelector('.promo-value-hidden');

    const unit =
        row.querySelector('.promo-unit');

    if (!display || !hidden || !unit) {
        return;
    }

    if (select.value === 'so_tien') {
        unit.textContent = 'đ';

        display.value =
            formatThousands(
                hidden.value || display.value
            );
    } else if (
        select.value === 'phan_tram'
    ) {
        unit.textContent = '%';

        const percent =
            parsePercentValue(
                hidden.value || display.value
            );

        display.value = percent;
        hidden.value = percent;
    } else {
        unit.textContent = '';
    }
}

function syncPromoValue(input) {
    const row =
        input.closest('.capacity-row');

    if (!row) {
        return;
    }

    const type =
        row.querySelector('.promo-type-select');

    const hidden =
        row.querySelector('.promo-value-hidden');

    const unit =
        row.querySelector('.promo-unit');

    if (!type || !hidden || !unit) {
        return;
    }

    const digits =
        onlyDigits(input.value);

    if (type.value === 'so_tien') {
        input.value =
            formatThousands(digits);

        hidden.value = digits;
        unit.textContent = 'đ';
        return;
    }

    if (type.value === 'phan_tram') {
        const percent =
            parsePercentValue(
                input.value
            );

        input.value = percent;
        hidden.value = percent;
        unit.textContent = '%';
        return;
    }

    input.value = digits;
    hidden.value = digits;
    unit.textContent = '';
}

function addColorGroup() {
    const box =
        document.getElementById(
            'colorGroupBox'
        );

    colorGroupSequence++;

    const colorKey =
        'new_color_' +
        colorGroupSequence +
        '_' +
        Date.now();

    const html = `
        <div
            class="color-group-card"
            data-color-key="${colorKey}"
        >
            <div class="color-group-header">
                <div class="color-group-main">
                    <label class="form-label small fw-bold">
                        Màu
                    </label>

                    <select
                        class="form-select color-group-select"
                        onchange="syncColorGroup(this)"
                        required
                    >
                        ${colorOptionsHtml}
                    </select>
                </div>

                <div class="color-group-actions">
                    <button
                        type="button"
                        class="btn btn-outline-primary btn-sm"
                        onclick="addCapacityRow(this)"
                    >
                        + Thêm phiên bản
                    </button>

                    <button
                        type="button"
                        class="btn btn-outline-danger btn-sm"
                        onclick="removeColorGroup(this)"
                    >
                        Xóa biến thể
                    </button>
                </div>
            </div>

            <div class="capacity-list"></div>
        </div>
    `;

    box.insertAdjacentHTML(
        'beforeend',
        html
    );

    const group =
        box.lastElementChild;

    addCapacityRow(
        group.querySelector(
            '.btn-outline-primary'
        )
    );
}

function addCapacityRow(button) {
    const group =
        button.closest(
            '.color-group-card'
        );

    if (!group) {
        return;
    }

    variantSequence++;

    const variantKey =
        'new_' +
        variantSequence +
        '_' +
        Date.now();

    const selectedColor =
        group.querySelector(
            '.color-group-select'
        )?.value || '';

    const imageInputDisabled =
        variantImageTableReady
            ? ''
            : 'disabled';

    const list =
        group.querySelector(
            '.capacity-list'
        );

    const html = `
        <div class="capacity-row variant-row">
            <input type="hidden" name="variant_id[]" value="0">
            <input type="hidden" name="variant_form_key[]" value="${variantKey}">
            <input type="hidden" name="mau_variant[]" class="variant-color-hidden" value="${selectedColor}">
            <input type="hidden" name="ram[]" value="">
            <input type="hidden" name="rom[]" class="rom-hidden" value="">

            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small">
                        Dung lượng
                    </label>

                    <select
                        class="form-select storage-select"
                        required
                        onchange="syncStorageValue(this)"
                    >
                        ${storageOptionsHtml}
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label small">
                        Giá
                    </label>

                    <div class="input-group">
                        <input
                            type="text"
                            inputmode="numeric"
                            class="form-control currency-display"
                            required
                            oninput="syncCurrencyInput(this)"
                        >

                        <span class="input-group-text">đ</span>

                        <input
                            type="hidden"
                            name="gia_variant[]"
                            class="currency-hidden"
                            value=""
                        >
                    </div>
                </div>

                <div class="col-md-2">
                    <label class="form-label small">
                        Tồn kho
                    </label>

                    <input
                        type="number"
                        min="0"
                        name="sl_variant[]"
                        class="form-control variant-stock"
                        value="0"
                        oninput="updateTotalStock()"
                    >
                </div>

                <div class="col-md-4 d-flex gap-1">

                    <button
                        type="button"
                        class="btn btn-outline-secondary flex-fill"
                        onclick="removeVariant(this)"
                    >
                        Xóa
                    </button>
                </div>
            </div>

            <div class="capacity-extra">
                <div class="variant-image-box">
                    <div class="variant-image-title">
                        🖼 Ảnh phiên bản
                    </div>

                    <input
                        type="file"
                        name="variant_images[${variantKey}][]"
                        class="form-control mt-3 variant-image-input"
                        accept="image/jpeg,image/png,image/webp"
                        multiple
                        ${imageInputDisabled}
                        onchange="previewVariantImages(this)"
                    >

                    <div class="variant-image-preview"></div>
                </div>

            </div>
        </div>
    `;

    list.insertAdjacentHTML(
        'beforeend',
        html
    );

    syncColorGroup(
        group.querySelector(
            '.color-group-select'
        )
    );

    checkVariants();
}

function removeColorGroup(button) {
    const group =
        button.closest(
            '.color-group-card'
        );

    if (!group) {
        return;
    }

    const savedRows =
        group.querySelectorAll(
            'input[name="variant_id[]"]'
        );

    const hasSaved =
        Array.from(savedRows)
            .some(function (input) {
                return Number(input.value) > 0;
            });

    if (hasSaved) {
        alert(
            'Không thể xóa cả nhóm màu đã lưu. Bạn có thể sửa từng phiên bản bên trong.'
        );
        return;
    }

    group.remove();
    updateTotalStock();
}

document.addEventListener(
    'submit',
    function () {
        document.querySelectorAll(
            '.color-group-select'
        ).forEach(syncColorGroup);

        document.querySelectorAll(
            '.capacity-row'
        ).forEach(function (row) {
            const storage =
                row.querySelector(
                    '.storage-select'
                );

            if (storage) {
                syncStorageValue(storage);
            }

            const currency =
                row.querySelector(
                    '.currency-display'
                );

            if (currency) {
                syncCurrencyInput(currency);
            }
        });
    },
    true
);


/* =========================================================
   XÓA BIẾN THỂ MỚI
/* =========================================================
   XÓA BIẾN THỂ MỚI
========================================================= */

function removeVariant(button) {
    const rows =
        document.querySelectorAll(
            '.variant-row'
        );

    if (rows.length <= 1) {
        alert(
            'Sản phẩm iPhone phải có ít nhất 1 biến thể.'
        );
        return;
    }

    const row =
        button.closest(
            '.capacity-row'
        );

    if (row) {
        row.remove();
    }

    checkVariants();
    updateTotalStock();
}

/* =========================================================
   KIỂM TRA / CẬP NHẬT BIẾN THỂ
/* =========================================================
   KIỂM TRA CÓ BIẾN THỂ
========================================================= */

function checkVariants() {
    updateTotalStock();
}

/* =========================================================
   TÍNH TỔNG TỒN KHO
/* =========================================================
   TÍNH TỔNG TỒN KHO
========================================================= */

function updateTotalStock() {
    let total = 0;

    const stocks =
        document.querySelectorAll(
            '.variant-stock'
        );

    stocks.forEach(function (input) {
        const value = Math.max(
            0,
            parseInt(input.value) || 0
        );

        total += value;
    });

    document.getElementById(
        'totalVariantStock'
    ).innerText = total;
}

/* =========================================================
   ĐẾM KÝ TỰ MÔ TẢ NGẮN
========================================================= */

function updateShortDescriptionCount() {
    const input =
        document.getElementById(
            'shortDescription'
        );

    const counter =
        document.getElementById(
            'shortDescriptionCount'
        );

    counter.innerText =
        input.value.length + ' / 500';
}

/* =========================================================
   XEM TRƯỚC ẢNH BIẾN THỂ
========================================================= */

function previewVariantImages(input) {
    const files =
        Array.from(
            input.files || []
        );

    if (files.length > 5) {
        alert(
            'Mỗi biến thể chỉ được chọn tối đa 5 ảnh.'
        );

        input.value = '';

        selectedFileStore.delete(
            input
        );

        renderNewVariantImages(
            input,
            0
        );

        return;
    }

    selectedFileStore.set(
        input,
        files
    );

    renderNewVariantImages(
        input,
        0
    );
}

/* =========================================================
   XEM TRƯỚC ẢNH CHUNG MỚI
========================================================= */

function previewNewImages(event) {
    const input =
        event.target;

    const files =
        Array.from(
            input.files || []
        );

    selectedFileStore.set(
        input,
        files
    );

    const mainIndex =
        document.getElementById(
            'newMainProductIndex'
        );

    if (mainIndex) {
        mainIndex.value =
            files.length > 0
                ? '0'
                : '';
    }

    renderNewProductImages(
        input
    );
}


/* =========================================================
   CẬP NHẬT TRỰC TIẾP TRẠNG THÁI ẢNH TRÊN GIAO DIỆN
   - Chọn ảnh đại diện: đổi viền/nhãn ngay
   - Chọn ảnh chính biến thể: đổi viền/nhãn ngay
   - Chọn xóa: làm mờ ảnh ngay, vẫn gửi dữ liệu khi bấm Cập nhật
========================================================= */

function clearLiveMainState(selector, badgeSelector) {
    document
        .querySelectorAll(selector)
        .forEach(function (item) {
            item.classList.remove('live-main');

            item
                .querySelectorAll(badgeSelector)
                .forEach(function (badge) {
                    badge.remove();
                });
        });
}

function markProductImageMain(radio) {
    if (!radio || radio.disabled) {
        return;
    }

    clearLiveMainState(
        '.product-image-item',
        '.live-main-badge'
    );

    const item =
        radio.closest('.product-image-item');

    if (!item) {
        return;
    }

    item.classList.add('live-main');

    let badge =
        item.querySelector('.image-main-label');

    if (!badge) {
        badge =
            document.createElement('span');

        badge.className =
            'image-main-label live-main-badge';

        badge.textContent =
            'Đại diện';

        item.appendChild(badge);
    }
}

function markVariantImageMain(radio) {
    if (!radio || radio.disabled) {
        return;
    }

    const group =
        radio.closest('.variant-existing-images');

    if (!group) {
        return;
    }

    group
        .querySelectorAll('.variant-image-item')
        .forEach(function (item) {
            item.classList.remove('live-main');

            const liveBadge =
                item.querySelector(
                    '.live-main-badge'
                );

            if (liveBadge) {
                liveBadge.remove();
            }
        });

    const item =
        radio.closest('.variant-image-item');

    if (!item) {
        return;
    }

    item.classList.add('live-main');

    let badge =
        item.querySelector('.variant-main-label');

    if (!badge) {
        badge =
            document.createElement('span');

        badge.className =
            'variant-main-label live-main-badge';

        badge.textContent =
            'Ảnh chính';

        item.appendChild(badge);
    }
}

document
    .querySelectorAll(
        '.existing-product-main-radio'
    )
    .forEach(function (radio) {
        radio.addEventListener(
            'change',
            function () {
                if (this.checked) {
                    markProductImageMain(this);
                }
            }
        );
    });

document
    .querySelectorAll(
        '.variant-main-radio'
    )
    .forEach(function (radio) {
        radio.addEventListener(
            'change',
            function () {
                if (this.checked) {
                    markVariantImageMain(this);
                }
            }
        );
    });

document
    .querySelectorAll(
        '.delete-image-checkbox'
    )
    .forEach(function (checkbox) {
        checkbox.addEventListener(
            'change',
            function () {
                const item =
                    this.closest(
                        '.product-image-item'
                    );

                if (item) {
                    item.classList.toggle(
                        'pending-delete',
                        this.checked
                    );
                }
            }
        );
    });

document
    .querySelectorAll(
        '.delete-variant-image-checkbox'
    )
    .forEach(function (checkbox) {
        checkbox.addEventListener(
            'change',
            function () {
                const item =
                    this.closest(
                        '.variant-image-item'
                    );

                if (item) {
                    item.classList.toggle(
                        'pending-delete',
                        this.checked
                    );
                }
            }
        );
    });

/* =========================================================
   KHÔNG CHO ẢNH BIẾN THỂ VỪA XÓA LÀM ẢNH CHÍNH
========================================================= */

document
    .querySelectorAll(
        '.delete-variant-image-checkbox'
    )
    .forEach(function(checkbox) {
        checkbox.addEventListener(
            'change',
            function() {
                const item =
                    this.closest(
                        '.variant-image-item'
                    );

                const radio =
                    item?.querySelector(
                        '.variant-main-radio'
                    );

                if (!radio) {
                    return;
                }

                radio.disabled =
                    this.checked;

                if (
                    this.checked &&
                    radio.checked
                ) {
                    radio.checked = false;
                }
            }
        );
    });

/* =========================================================
   KHÔNG CHO CHỌN ẢNH CHUNG VỪA XÓA LÀM ĐẠI DIỆN
========================================================= */

document
    .querySelectorAll(
        '.delete-image-checkbox'
    )
    .forEach(function (checkbox) {
        checkbox.addEventListener(
            'change',
            function () {
                const radioId =
                    this.dataset.radioId;

                const radio =
                    document.getElementById(
                        radioId
                    );

                if (!radio) {
                    return;
                }

                radio.disabled =
                    this.checked;

                if (
                    this.checked &&
                    radio.checked
                ) {
                    radio.checked = false;
                }
            }
        );
    });

/* =========================================================
   KHỞI TẠO
========================================================= */

document
    .querySelectorAll('.color-group-select')
    .forEach(syncColorGroup);

checkVariants();
updateTotalStock();
updateShortDescriptionCount();
initializeSpecs();
</script>

<?php require_once "../../includes/footer.php"; ?>

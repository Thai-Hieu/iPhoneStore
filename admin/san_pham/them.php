<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

/* =========================================================
   TẠO MÃ SẢN PHẨM TỰ ĐỘNG

   Dạng mã:
   8686 + 4 số ngẫu nhiên

   Ví dụ:
   86862479
   86869031

   Quy tắc:
   - 8686 luôn cố định.
   - 4 số cuối không lặp nhau trong cùng một mã.
   - Toàn bộ mã không được trùng với sản phẩm đã có.
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

        /*
            Lấy 4 chữ số khác nhau.
            Sau khi lấy một số thì xóa khỏi mảng,
            nên số đó không thể xuất hiện lần thứ hai.
        */
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
   HÀM HỖ TRỢ ẢNH BIẾN THỂ
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


/* =========================================================
   LẤY DANH MỤC
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
   LẤY DANH SÁCH MÀU
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

/* Dùng để tạo select màu trong JavaScript */
$colorOptionsHtml = '<option value="">-- Chọn màu --</option>';

foreach ($mauSacList as $mau) {
    $colorOptionsHtml .=
        '<option value="' . (int)$mau['id'] . '">' .
        htmlspecialchars($mau['ten_mau'], ENT_QUOTES, 'UTF-8') .
        '</option>';
}

$cotAnhBienThe =
    layCotAnhBienThe($conn);

$coBangAnhBienThe =
    $cotAnhBienThe !== null;

/* =========================================================
   NHÓM THÔNG SỐ KỸ THUẬT
   Dùng chung cho form thêm / sửa / trang chi tiết sau này.
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

/*
    Dữ liệu ban đầu cho JavaScript.
    Nếu submit bị lỗi, thông số đã nhập vẫn được giữ lại.
*/
$specInitialData = [];

foreach ($specGroups as $groupKey => $groupInfo) {
    $specInitialData[$groupKey] = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
   XỬ LÝ KHI SUBMIT FORM
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ten = trim($_POST['ten_san_pham'] ?? '');
    $dm = (int)($_POST['danh_muc_id'] ?? 0);

    /*
        Tách mô tả thành 2 phần:
        - Mô tả ngắn: dùng cho card / danh sách sản phẩm.
        - Mô tả dài: dùng cho trang chi tiết sản phẩm.
    */
    $moTaNgan = trim($_POST['mo_ta_ngan'] ?? '');
    $moTaDai = trim($_POST['mo_ta_dai'] ?? '');

    $uploadedPaths = [];

    try {
        /* -------------------------------------------------
           THÔNG SỐ KỸ THUẬT
       Mỗi dòng gồm: Nhóm + Tên thông số + Giá trị.
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

        /* Dòng hoàn toàn trống thì bỏ qua */
        if (
            $groupKey === '' &&
            $specName === '' &&
            $specValue === ''
        ) {
            continue;
        }

        if (!isset($specGroups[$groupKey])) {
            throw new Exception('Nhóm thông số kỹ thuật không hợp lệ');
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
                $specName . '"'
            );
        }

        if (mb_strlen($specName, 'UTF-8') > 150) {
            throw new Exception('Tên thông số không được vượt quá 150 ký tự');
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
           1. KIỂM TRA DỮ LIỆU CƠ BẢN
        ------------------------------------------------- */
        if ($ten === '') {
            throw new Exception('Tên sản phẩm không được để trống');
        }

        if ($dm <= 0) {
            throw new Exception('Danh mục không hợp lệ');
        }

        if (mb_strlen($moTaNgan, 'UTF-8') > 500) {
            throw new Exception('Mô tả ngắn không được vượt quá 500 ký tự');
        }

        /* -------------------------------------------------
           2. XỬ LÝ BIẾN THỂ
           Mỗi biến thể = Màu + RAM + ROM + Giá + SL; giao diện nhóm theo màu
        ------------------------------------------------- */
        $variants = [];
        $variantKeys = [];
        $tongSoLuongBienThe = 0;

        $count = isset($_POST['rom']) && is_array($_POST['rom'])
            ? count($_POST['rom'])
            : 0;

        for ($i = 0; $i < $count; $i++) {
            $formKey =
                trim(
                    (string)(
                        $_POST['variant_key'][$i]
                        ?? ''
                    )
                );

            $colorKey =
                trim(
                    (string)(
                        $_POST['variant_color_key'][$i]
                        ?? ''
                    )
                );

            $mauId = (int)($_POST['mau_variant'][$i] ?? 0);
            $ram = trim($_POST['ram'][$i] ?? '');
            $rom = trim($_POST['rom'][$i] ?? '');
            $giaV = (float)($_POST['gia_variant'][$i] ?? 0);
            $slV = max(0, (int)($_POST['sl_variant'][$i] ?? 0));

            if (
                $formKey === '' ||
                !preg_match(
                    '/^[a-zA-Z0-9_-]+$/',
                    $formKey
                ) ||
                $colorKey === '' ||
                !preg_match(
                    '/^[a-zA-Z0-9_-]+$/',
                    $colorKey
                )
            ) {
                throw new Exception(
                    'Dữ liệu nhóm màu / biến thể không hợp lệ'
                );
            }


            $dongTrong =
                $mauId <= 0 &&
                $ram === '' &&
                $rom === '' &&
                $giaV <= 0 &&
                $slV <= 0;

            if ($dongTrong) {
                continue;
            }

            if ($mauId <= 0 || !in_array($mauId, $validColorIds, true)) {
                throw new Exception('Vui lòng chọn màu hợp lệ cho tất cả biến thể');
            }

            if ($rom === '') {
                throw new Exception('Vui lòng nhập ROM cho tất cả biến thể');
            }

            if ($giaV <= 0) {
                throw new Exception('Giá của tất cả biến thể phải lớn hơn 0');
            }

            /* Không cho trùng Màu + RAM + ROM */
            $variantKey =
                $mauId . '|' .
                mb_strtolower($ram) . '|' .
                mb_strtolower($rom);

            if (isset($variantKeys[$variantKey])) {
                throw new Exception("Biến thể bị trùng: $ram / $rom cùng màu");
            }

            $variantKeys[$variantKey] = true;


            $variants[] = [
                'form_key' => $formKey,
                'color_key' => $colorKey,
                'mau_id' => $mauId,
                'ram' => $ram,
                'rom' => $rom,
                'gia' => $giaV,
                'so_luong' => $slV
            ];

            $tongSoLuongBienThe += $slV;
        }

        if (empty($variants)) {
            throw new Exception(
                'Sản phẩm iPhone phải có ít nhất 1 biến thể'
            );
        }

        /*
            Sản phẩm iPhone chỉ bán theo biến thể.
            Vẫn đồng bộ hai cột cũ trong san_pham:
            - gia = giá thấp nhất của các biến thể.
            - so_luong = tổng tồn kho của các biến thể.
        */
        $variantPrices =
            array_map(
                static fn(array $variant): float =>
                    (float)$variant['gia'],
                $variants
            );

        $gia =
            min($variantPrices);

        $soLuong =
            $tongSoLuongBienThe;

        /* -------------------------------------------------
           3. XÁC ĐỊNH MÀU TỪ CÁC BIẾN THỂ
        ------------------------------------------------- */
        $productColorIds = [];

        foreach ($variants as $variant) {
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
           4. UPLOAD HÌNH ẢNH CHUNG CỦA SẢN PHẨM
        ------------------------------------------------- */
        $dsHinhAnh = [];
        $hinh = '';

        if (!empty($_FILES['hinh_anh']['name'][0])) {
            $uploadDir = '../../uploads/';

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $allowedExt = [
                'jpg',
                'jpeg',
                'png',
                'webp'
            ];

            $allowedMime = [
                'image/jpeg',
                'image/png',
                'image/webp'
            ];

            foreach ($_FILES['hinh_anh']['name'] as $i => $originalName) {
                if ($originalName === '') {
                    continue;
                }

                if ($_FILES['hinh_anh']['error'][$i] !== UPLOAD_ERR_OK) {
                    throw new Exception('Có hình ảnh tải lên bị lỗi');
                }

                if ((int)$_FILES['hinh_anh']['size'][$i] > 5 * 1024 * 1024) {
                    throw new Exception('Mỗi hình ảnh tối đa 5MB');
                }

                $tmp = $_FILES['hinh_anh']['tmp_name'][$i];
                $ext = strtolower(
                    pathinfo($originalName, PATHINFO_EXTENSION)
                );

                $imageInfo = @getimagesize($tmp);

                $extensionHopLe = in_array($ext, $allowedExt, true);
                $mimeHopLe =
                    $imageInfo &&
                    in_array($imageInfo['mime'], $allowedMime, true);

                if (!$extensionHopLe || !$mimeHopLe) {
                    throw new Exception(
                        'Chỉ cho phép ảnh JPG, JPEG, PNG hoặc WEBP'
                    );
                }

                $newName =
                    date('YmdHis') . '_' .
                    uniqid() . '.' .
                    $ext;

                $destination = $uploadDir . $newName;

                if (!move_uploaded_file($tmp, $destination)) {
                    throw new Exception('Không thể lưu hình ảnh');
                }

                $dsHinhAnh[] = $newName;
                $uploadedPaths[] = $destination;
            }

            /* Ảnh đầu tiên là ảnh đại diện */
            $hinh = $dsHinhAnh[0] ?? '';
        }

        /* -------------------------------------------------
           6. TRANSACTION DATABASE
        ------------------------------------------------- */
        $conn->begin_transaction();

        try {
            /* =========================
               6.1 THÊM SẢN PHẨM
            ========================= */
            $giaKhuyenMaiLegacy = 0.0;

            /*
                Mã sản phẩm được sinh đúng 1 lần khi thêm mới.
                Sau khi lưu vào database sẽ không random lại.
            */
            $maSanPham = taoMaSanPham($conn);

            $stmtProduct = $conn->prepare("
                INSERT INTO san_pham (
                    ma_san_pham,
                    danh_muc_id,
                    ten_san_pham,
                    gia,
                    gia_khuyen_mai,
                    so_luong,
                    hinh_anh,
                    mo_ta,
                    mo_ta_ngan,
                    mo_ta_dai
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmtProduct) {
                throw new Exception(
                    'Không thể chuẩn bị câu lệnh thêm sản phẩm: ' .
                    $conn->error
                );
            }

            /*
                Vẫn lưu mo_ta = mo_ta_dai để các trang cũ
                đang dùng cột mo_ta chưa bị lỗi trong lúc chuyển đổi.
            */
            $moTaLegacy = $moTaDai;

            $stmtProduct->bind_param(
                'sisddissss',
                $maSanPham,
                $dm,
                $ten,
                $gia,
                $giaKhuyenMaiLegacy,
                $soLuong,
                $hinh,
                $moTaLegacy,
                $moTaNgan,
                $moTaDai
            );

            $stmtProduct->execute();

            $productId = $conn->insert_id;

            /* =========================
               6.2 LƯU THÔNG SỐ KỸ THUẬT
            ========================= */
            if (!empty($technicalSpecs)) {
                $stmtSpec = $conn->prepare("
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

                if (!$stmtSpec) {
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

                    $stmtSpec->bind_param(
                        'issssii',
                        $productId,
                        $groupKey,
                        $groupLabel,
                        $specName,
                        $specValue,
                        $groupOrder,
                        $rowOrder
                    );

                    $stmtSpec->execute();
                }
            }

            /* =========================
               6.3 LƯU HÌNH ẢNH
            ========================= */
            if (!empty($dsHinhAnh)) {
                $stmtImage = $conn->prepare("
                    INSERT INTO san_pham_hinh_anh (
                        san_pham_id,
                        hinh_anh,
                        thu_tu
                    )
                    VALUES (?, ?, ?)
                ");

                foreach ($dsHinhAnh as $index => $imageName) {
                    $thuTu = $index + 1;

                    $stmtImage->bind_param(
                        'isi',
                        $productId,
                        $imageName,
                        $thuTu
                    );

                    $stmtImage->execute();
                }
            }

            /* =========================
               6.3 LƯU MÀU SẢN PHẨM
            ========================= */
            if (!empty($productColorIds)) {
                $stmtColor = $conn->prepare("
                    INSERT INTO san_pham_mau (
                        san_pham_id,
                        mau_id
                    )
                    VALUES (?, ?)
                ");

                foreach ($productColorIds as $mauId) {
                    $stmtColor->bind_param(
                        'ii',
                        $productId,
                        $mauId
                    );

                    $stmtColor->execute();
                }
            }

            /* =========================
               6.5 LƯU BIẾN THỂ
            ========================= */
            $stmtVariant = $conn->prepare("
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

            if (!$stmtVariant) {
                throw new Exception(
                    'Không thể chuẩn bị câu lệnh lưu biến thể: ' .
                    $conn->error
                );
            }

            $stmtVariantImage = null;

            if ($coBangAnhBienThe) {
                $stmtVariantImage =
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

                if (!$stmtVariantImage) {
                    throw new Exception(
                        'Không thể chuẩn bị câu lệnh lưu ảnh biến thể: ' .
                        $conn->error
                    );
                }
            }

            $firstVariantImageRelative = null;

            /*
                Ảnh được upload theo nhóm màu.
                Một màu có thể có nhiều dung lượng; các biến thể cùng màu
                dùng chung bộ ảnh để admin không phải upload lặp lại.
            */
            $colorImagePathsByKey = [];

            $uniqueColorKeys = [];

            foreach ($variants as $variantForColor) {
                $uniqueColorKeys[
                    (string)$variantForColor['color_key']
                ] = true;
            }

            foreach (array_keys($uniqueColorKeys) as $colorKey) {
                $colorImageNames =
                    $_FILES['color_images']['name'][$colorKey]
                    ?? [];

                $colorImageNames =
                    is_array($colorImageNames)
                        ? $colorImageNames
                        : [];

                $colorImageCount =
                    count(
                        array_filter(
                            $colorImageNames,
                            static fn($name) =>
                                trim((string)$name) !== ''
                        )
                    );

                if ($colorImageCount > 5) {
                    throw new Exception(
                        'Mỗi màu chỉ được chọn tối đa 5 ảnh'
                    );
                }

                if ($colorImageCount <= 0) {
                    $colorImagePathsByKey[$colorKey] = [];
                    continue;
                }

                if (!$coBangAnhBienThe) {
                    throw new Exception(
                        'Chưa có bảng san_pham_bien_the_hinh_anh. ' .
                        'Hãy chạy migration ảnh biến thể trước.'
                    );
                }

                $variantUploadDir =
                    '../../uploads/bien_the/';

                if (!is_dir($variantUploadDir)) {
                    if (
                        !mkdir(
                            $variantUploadDir,
                            0777,
                            true
                        ) &&
                        !is_dir($variantUploadDir)
                    ) {
                        throw new Exception(
                            'Không thể tạo thư mục ảnh biến thể'
                        );
                    }
                }

                $allowedExt = [
                    'jpg',
                    'jpeg',
                    'png',
                    'webp'
                ];

                $allowedMime = [
                    'image/jpeg',
                    'image/png',
                    'image/webp'
                ];

                $savedColorImages = [];

                foreach (
                    $colorImageNames
                    as $imageIndex => $originalName
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
                            $_FILES['color_images']['error'][$colorKey][$imageIndex]
                            ?? UPLOAD_ERR_NO_FILE
                        );

                    if ($error !== UPLOAD_ERR_OK) {
                        throw new Exception(
                            'Có ảnh màu tải lên bị lỗi'
                        );
                    }

                    $size =
                        (int)(
                            $_FILES['color_images']['size'][$colorKey][$imageIndex]
                            ?? 0
                        );

                    if (
                        $size <= 0 ||
                        $size > 5 * 1024 * 1024
                    ) {
                        throw new Exception(
                            'Mỗi ảnh màu tối đa 5MB'
                        );
                    }

                    $tmp =
                        (string)(
                            $_FILES['color_images']['tmp_name'][$colorKey][$imageIndex]
                            ?? ''
                        );

                    $ext =
                        strtolower(
                            pathinfo(
                                (string)$originalName,
                                PATHINFO_EXTENSION
                            )
                        );

                    $imageInfo =
                        @getimagesize($tmp);

                    if (
                        !in_array(
                            $ext,
                            $allowedExt,
                            true
                        ) ||
                        !$imageInfo ||
                        !in_array(
                            $imageInfo['mime'],
                            $allowedMime,
                            true
                        )
                    ) {
                        throw new Exception(
                            'Ảnh màu chỉ hỗ trợ JPG, JPEG, PNG hoặc WEBP'
                        );
                    }

                    $newName =
                        'color_' .
                        date('YmdHis') .
                        '_' .
                        bin2hex(
                            random_bytes(5)
                        ) .
                        '.' .
                        $ext;

                    $destination =
                        $variantUploadDir .
                        $newName;

                    if (
                        !move_uploaded_file(
                            $tmp,
                            $destination
                        )
                    ) {
                        throw new Exception(
                            'Không thể lưu ảnh theo màu'
                        );
                    }

                    $uploadedPaths[] =
                        $destination;

                    $relativePath =
                        'bien_the/' .
                        $newName;

                    $savedColorImages[] =
                        $relativePath;

                    if (
                        $firstVariantImageRelative === null
                    ) {
                        $firstVariantImageRelative =
                            $relativePath;
                    }
                }

                $colorImagePathsByKey[$colorKey] =
                    $savedColorImages;
            }

            foreach ($variants as $variant) {
                $mauId = (int)$variant['mau_id'];
                $ram = $variant['ram'];
                $rom = $variant['rom'];
                $giaV = (float)$variant['gia'];
                $slV = (int)$variant['so_luong'];

                $stmtVariant->bind_param(
                    'iissdi',
                    $productId,
                    $mauId,
                    $ram,
                    $rom,
                    $giaV,
                    $slV
                );

                $stmtVariant->execute();

                $variantId =
                    $stmtVariant->insert_id;

                /* =========================
                   ẢNH THEO MÀU
                   Các dung lượng cùng màu dùng chung bộ ảnh.
                ========================= */
                $colorKey =
                    (string)$variant['color_key'];

                $variantImageRelativePaths =
                    $colorImagePathsByKey[$colorKey]
                    ?? [];

                if (
                    $stmtVariantImage &&
                    !empty($variantImageRelativePaths)
                ) {
                    foreach (
                        $variantImageRelativePaths
                        as $imageIndex => $relativePath
                    ) {
                        $thuTu =
                            $imageIndex + 1;

                        $laAnhChinh =
                            $thuTu === 1
                                ? 1
                                : 0;

                        $stmtVariantImage->bind_param(
                            'isii',
                            $variantId,
                            $relativePath,
                            $laAnhChinh,
                            $thuTu
                        );

                        $stmtVariantImage->execute();
                    }
                }

            }

            /*
                Nếu admin không tải ảnh chung của sản phẩm,
                tự dùng ảnh đầu tiên của biến thể làm ảnh đại diện.
            */
            if (
                $hinh === '' &&
                $firstVariantImageRelative !== null
            ) {
                $stmtMainImage =
                    $conn->prepare("
                        UPDATE san_pham
                        SET hinh_anh = ?
                        WHERE id = ?
                        LIMIT 1
                    ");

                if ($stmtMainImage) {
                    $stmtMainImage->bind_param(
                        'si',
                        $firstVariantImageRelative,
                        $productId
                    );

                    $stmtMainImage->execute();
                }
            }

            /* Thành công */
            $conn->commit();

            $maSanPhamJs = json_encode(
                $maSanPham,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            echo "
                <script>
                    const maSanPham = $maSanPhamJs;

                    alert(
                        'Thêm sản phẩm thành công!\\n' +
                        'Mã sản phẩm: ' + maSanPham
                    );

                    window.location = 'index.php';
                </script>
            ";

            exit;

        } catch (Throwable $e) {
            $conn->rollback();

            /* Nếu DB lỗi thì xóa ảnh vừa upload */
            foreach ($uploadedPaths as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            throw $e;
        }

    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}
?>

<?php require_once "../../includes/header.php"; ?>
<?php require_once "../../includes/navbar.php"; ?>

<link
    href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
    rel="stylesheet"
>

<style>
.add-product-card {
    border: 0;
    border-radius: 16px;
    overflow: hidden;
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

.variant-card {
    border: 1px solid #dee2e6;
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 12px;
    background: #fff;
}

.variant-promo-box {
    display: none;
    background: #fff8f4;
    border: 1px dashed #ff8a65;
    border-radius: 10px;
    padding: 14px;
    margin-top: 12px;
}

.variant-image-box {
    margin-top: 12px;
    padding: 13px;
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

.variant-image-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.variant-image-preview-item {
    width: 82px;
    padding: 5px;
    border: 1px solid #dde3ea;
    border-radius: 9px;
    background: #fff;
    text-align: center;
}

.variant-image-preview-item img {
    width: 100%;
    height: 70px;
    object-fit: contain;
    border-radius: 6px;
}

.variant-image-preview-item div {
    margin-top: 3px;
    color: #737b86;
    font-size: 9px;
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

.promo-title {
    color: #ee4d2d;
    font-weight: 700;
}

/* =========================================================
   TAB MÔ TẢ / THÔNG SỐ KỸ THUẬT
========================================================= */
.product-content-editor {
    border: 1px solid #e7e9ee;
    border-radius: 16px;
    background: #fff;
    overflow: hidden;
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

.color-group-card {
    border: 1px solid #dfe3e8;
    border-radius: 14px;
    margin-bottom: 16px;
    overflow: hidden;
    background: #fff;
}

.color-group-header {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 12px;
    padding: 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e7ebef;
}

.color-group-main {
    flex: 1;
}

.color-group-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.color-image-box {
    padding: 14px;
    border-bottom: 1px solid #eef1f4;
    background: #fff;
}

.capacity-list {
    padding: 14px;
}

.capacity-row {
    padding: 12px;
    margin-bottom: 10px;
    border: 1px solid #e7e9ee;
    border-radius: 12px;
    background: #fbfcfd;
}

.capacity-row:last-child {
    margin-bottom: 0;
}

.variant-promo-box {
    margin-top: 10px;
}

@media (max-width: 767px) {
    .color-group-header {
        align-items: stretch;
        flex-direction: column;
    }

    .color-group-actions {
        width: 100%;
    }

    .color-group-actions .btn {
        flex: 1;
    }
}


.capacity-row .input-group .form-select {
    max-width: 92px;
}

.capacity-row .input-group-text {
    min-width: 46px;
    justify-content: center;
    font-weight: 700;
    background: #f8f9fa;
}

.currency-display,
.promo-value-display {
    text-align: right;
    font-variant-numeric: tabular-nums;
}


.image-preview-item,
.variant-image-preview-item {
    position: relative;
}

.image-preview-actions {
    display: flex;
    gap: 6px;
    margin-top: 6px;
}

.image-preview-actions .btn {
    flex: 1;
    padding: 4px 6px;
    font-size: 11px;
}

.image-preview-item.is-main,
.variant-image-preview-item.is-main {
    border-color: #dc3545;
    box-shadow: 0 0 0 2px rgba(220, 53, 69, .08);
}

.main-image-badge {
    position: absolute;
    top: 7px;
    left: 7px;
    z-index: 2;
    padding: 4px 7px;
    border-radius: 999px;
    background: #198754;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
}


.color-image-box {
    padding: 18px 20px;
    border: 1px dashed #b7c8df;
    border-radius: 14px;
    background: #f8fbff;
    margin: 14px;
}

.color-image-box .fw-bold.small {
    font-size: 15px;
    color: #315c8d;
    margin-bottom: 10px;
}

.variant-image-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin: 12px 0 16px;
}

.variant-image-preview-item {
    position: relative;
    width: 158px;
    padding: 10px;
    border: 1px solid #d9dee6;
    border-radius: 14px;
    background: #fff;
    text-align: left;
}

.variant-image-preview-item img {
    width: 100%;
    height: 132px;
    object-fit: contain;
    border-radius: 8px;
    background: #f7f7f8;
}

.variant-image-preview-item.is-main {
    border: 2px solid #198754;
    box-shadow: none;
}

.variant-image-preview-item .main-image-badge {
    top: 8px;
    left: 8px;
    background: #198754;
    color: #fff;
    border-radius: 6px;
    padding: 4px 7px;
    font-size: 11px;
}

.variant-image-preview-actions {
    display: block;
    margin-top: 10px;
}

.variant-image-preview-actions label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    cursor: pointer;
    font-size: 14px;
}

.variant-image-preview-actions .delete-action {
    color: #dc3545;
}

.variant-image-preview-actions input[type="radio"],
.variant-image-preview-actions input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin: 0;
}

.color-image-input {
    margin-top: 6px;
}

@media (max-width: 767px) {
    .variant-image-preview-item {
        width: calc(50% - 8px);
    }
}


.product-common-image-section {
    border: 1px solid #e7e9ee;
    border-radius: 16px;
    padding: 18px;
    margin-bottom: 22px;
    background: #fff;
}

.product-common-image-title {
    margin-bottom: 14px;
    color: #1f2937;
    font-size: 18px;
    font-weight: 800;
}

.product-common-image-preview {
    display: flex;
    flex-wrap: wrap;
    gap: 14px;
    margin-bottom: 16px;
}

.product-common-image-preview:empty {
    display: none;
}

.product-common-image-upload {
    margin-top: 8px;
}

.product-common-image-preview .variant-image-preview-item {
    width: 160px;
}


.capacity-row .storage-select {
    width: 100%;
    max-width: none;
}


.preview-delete-button {
    width: 18px;
    height: 18px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 18px;
    padding: 0;
    border: 1px solid #dc3545;
    border-radius: 50%;
    background: #fff;
    color: #dc3545;
    font-size: 14px;
    line-height: 1;
    cursor: pointer;
}

.preview-delete-button:hover {
    background: #dc3545;
    color: #fff;
}

.variant-image-preview-item {
    contain: layout paint;
}

.variant-image-preview-actions label {
    user-select: none;
}

</style>

<div class="container mt-4 mb-5">
    <div class="card shadow add-product-card">

        <div class="card-header bg-primary text-white py-3">
            <h4 class="mb-0">
                ➕ Thêm sản phẩm
            </h4>
        </div>

        <div class="card-body p-4">

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-danger">
                    <?= htmlspecialchars($errorMessage) ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">

                <?php if (!$coBangAnhBienThe): ?>
                    <div class="alert alert-warning">
                        <strong>Chưa bật ảnh theo biến thể.</strong>
                        Hãy chạy file
                        <code>migration_anh_bien_the_san_pham.sql</code>
                        trong database.
                        Sau khi chạy SQL, tải lại trang để chọn ảnh cho từng biến thể.
                    </div>
                <?php endif; ?>

                <!-- =====================================================
                     THÔNG TIN CƠ BẢN
                ====================================================== -->
                <div class="alert alert-light border mb-3">
                    <div class="fw-bold">
                        🏷 Mã sản phẩm
                    </div>

                    <div class="small text-muted mt-1">
                        Hệ thống sẽ tự tạo mã dạng
                        <strong>8686xxxx</strong>
                        khi lưu sản phẩm.
                        Bốn số cuối được tạo ngẫu nhiên,
                        không lặp nhau và toàn bộ mã được kiểm tra
                        để không trùng với sản phẩm đã có.
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">
                        Tên sản phẩm
                    </label>

                    <input
                        type="text"
                        name="ten_san_pham"
                        class="form-control"
                        value="<?= htmlspecialchars($_POST['ten_san_pham'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label fw-bold">
                        Danh mục
                    </label>

                    <select
                        name="danh_muc_id"
                        class="form-select"
                        required
                    >
                        <option value="">
                            -- Chọn danh mục --
                        </option>

                        <?php foreach ($danhMucList as $dmRow): ?>
                            <option
                                value="<?= (int)$dmRow['id'] ?>"
                                <?= (int)($_POST['danh_muc_id'] ?? 0) === (int)$dmRow['id'] ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($dmRow['ten_danh_muc']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="alert alert-success mb-3">
                    <strong>🎯 Khuyến mãi đã tách riêng.</strong>
                    Sau khi thêm sản phẩm, hãy vào
                    <a href="/iPhoneStore/admin/khuyen_mai/index.php">Quản lý khuyến mãi</a>
                    để tạo chương trình giảm giá.
                </div>

                <div class="alert alert-info mb-4">
                    <div class="fw-bold">
                        📱 Giá và tồn kho quản lý theo biến thể
                    </div>

                    <div class="small mt-1">
                        Mỗi iPhone bắt buộc có ít nhất một biến thể.
                        Giá chung của sản phẩm được tự đồng bộ bằng
                        <strong>giá thấp nhất</strong>
                        trong các biến thể; số lượng chung bằng
                        <strong>tổng tồn kho</strong>
                        của tất cả biến thể.
                    </div>
                </div>

                <!-- =====================================================
                     HÌNH ẢNH CHUNG CỦA SẢN PHẨM
                ====================================================== -->
                <div class="product-common-image-section">

                    <div class="product-common-image-title">
                        Hình ảnh chung
                    </div>

                    <div
                        id="imagePreview"
                        class="product-common-image-preview"
                    ></div>

                    <div class="product-common-image-upload">
                        <input
                            type="file"
                            name="hinh_anh[]"
                            id="productImages"
                            class="form-control"
                            accept="image/jpeg,image/png,image/webp"
                            multiple
                            onchange="previewImages(event)"
                        >
                    </div>

                </div>

                <!-- =====================================================
                     NỘI DUNG SẢN PHẨM
                     MÔ TẢ / THÔNG SỐ KỸ THUẬT
                ====================================================== -->
                <div class="mb-4">

                    <div class="d-flex justify-content-between align-items-end mb-2">
                        <div>
                            <label class="form-label fw-bold mb-0">
                                Nội dung sản phẩm
                            </label>

                            <div class="small text-muted">
                                Nhập mô tả và thông số kỹ thuật riêng cho sản phẩm.
                            </div>
                        </div>
                    </div>

                    <div class="product-content-editor">

                        <!-- TAB -->
                        <div class="content-tabs">

                            <button
                                type="button"
                                class="content-tab-btn active"
                                id="descriptionTabButton"
                                onclick="switchContentTab('description')"
                            >
                                📝 Mô tả
                            </button>

                            <button
                                type="button"
                                class="content-tab-btn"
                                id="specificationTabButton"
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

                            <div class="mb-4">
                                <label class="form-label fw-bold">
                                    Mô tả ngắn
                                </label>

                                <textarea
                                    name="mo_ta_ngan"
                                    id="mo_ta_ngan"
                                    class="form-control"
                                    rows="3"
                                    maxlength="500"
                                    placeholder="Ví dụ: iPhone nổi bật với hiệu năng mạnh, camera chất lượng cao và màn hình sắc nét..."
                                    oninput="updateShortDescriptionCount()"
                                ><?= htmlspecialchars($_POST['mo_ta_ngan'] ?? '') ?></textarea>

                                <div class="d-flex justify-content-between mt-1">
                                    <small class="text-muted">
                                        Dùng ở card sản phẩm, danh sách và phần giới thiệu nhanh.
                                    </small>

                                    <small class="text-muted">
                                        <span id="shortDescriptionCount">0</span>/500 ký tự
                                    </small>
                                </div>
                            </div>

                            <div>
                                <label class="form-label fw-bold">
                                    Mô tả chi tiết
                                </label>

                                <textarea
                                    name="mo_ta_dai"
                                    class="form-control"
                                    rows="10"
                                    placeholder="Nhập nội dung chi tiết về thiết kế, màn hình, hiệu năng, camera, pin, trải nghiệm sử dụng..."
                                ><?= htmlspecialchars($_POST['mo_ta_dai'] ?? '') ?></textarea>

                                <small class="text-muted">
                                    Nội dung này sẽ hiển thị trong tab Mô tả ở trang chi tiết sản phẩm.
                                </small>
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
                                Mỗi nhóm có thể thêm nhiều dòng thông số. Ví dụ nhóm
                                <b>Pin & Sạc</b> có thể nhập: Dung lượng pin, Loại pin,
                                Hỗ trợ sạc tối đa, Công nghệ pin... Giá trị có thể xuống dòng.
                            </div>

                            <?php foreach ($specGroups as $groupKey => $groupInfo): ?>

                                <div
                                    class="spec-group <?= $groupKey === 'cau_hinh_bo_nho' ? 'open' : '' ?>"
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
                     NHÓM MÀU + DUNG LƯỢNG
                ====================================================== -->
                <div class="mb-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <label class="form-label fw-bold mb-0">
                                Biến thể sản phẩm
                            </label>

                        </div>

                        <button
                            type="button"
                            class="btn btn-primary btn-sm"
                            onclick="addColorGroup()"
                        >
                            + Thêm biến thể
                        </button>
                    </div>

                    <div id="colorGroupBox"></div>

                    <div
                        id="variantStockInfo"
                        class="alert alert-info mt-3"
                    >
                        Tổng tồn kho từ tất cả phiên bản:
                        <strong id="totalVariantStock">0</strong>
                        sản phẩm
                    </div>
                </div>

                <!-- =====================================================
                     NÚT SUBMIT
                ====================================================== -->
                <button
                    type="submit"
                    class="btn btn-success"
                >
                    💾 Lưu sản phẩm
                </button>

                <a
                    href="index.php"
                    class="btn btn-secondary"
                >
                    Hủy
                </a>

            </form>
        </div>
    </div>
</div>

<script>
const colorOptionsHtml = <?= json_encode(
    $colorOptionsHtml,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

const specGroups = <?= json_encode(
    $specGroups,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
) ?>;

const specInitialData = <?= json_encode(
    $specInitialData,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
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
   KHỞI TẠO THÔNG SỐ
   - Có dữ liệu POST: khôi phục dữ liệu đã nhập.
   - Chưa có dữ liệu: tạo 1 dòng trống / nhóm.
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
   QUẢN LÝ ẢNH TRƯỚC KHI UPLOAD
   - Không render lại toàn bộ preview khi chọn ảnh chính / xóa.
   - Tránh hiện tượng giật giao diện.
   - Chỉ sắp xếp FileList theo ảnh chính ngay trước khi submit.
========================================================= */

const selectedFileStore = new WeakMap();
const mainFileStore = new WeakMap();

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
    const transfer = new DataTransfer();

    files.forEach(function (file) {
        transfer.items.add(file);
    });

    input.files = transfer.files;
    selectedFileStore.set(input, files);
}

function getPreviewContainer(input) {
    if (input.id === 'productImages') {
        return document.getElementById('imagePreview');
    }

    const group = input.closest('.color-group-card');

    return group
        ? group.querySelector('.variant-image-preview')
        : null;
}

function refreshMainVisual(input) {
    const preview = getPreviewContainer(input);

    if (!preview) {
        return;
    }

    const files = getSelectedFiles(input);

    let mainFile =
        mainFileStore.get(input) || null;

    if (!mainFile || !files.includes(mainFile)) {
        mainFile = files[0] || null;

        if (mainFile) {
            mainFileStore.set(input, mainFile);
        } else {
            mainFileStore.delete(input);
        }
    }

    preview
        .querySelectorAll('.variant-image-preview-item')
        .forEach(function (item) {
            const isMain =
                item._previewFile === mainFile;

            item.classList.toggle(
                'is-main',
                isMain
            );

            const radio =
                item.querySelector(
                    '.preview-main-radio'
                );

            if (radio) {
                radio.checked = isMain;
            }

            let badge =
                item.querySelector(
                    '.main-image-badge'
                );

            if (isMain) {
                if (!badge) {
                    badge =
                        document.createElement(
                            'div'
                        );

                    badge.className =
                        'main-image-badge';

                    badge.textContent =
                        'Ảnh chính';

                    item.prepend(badge);
                }
            } else if (badge) {
                badge.remove();
            }
        });
}

function setImageAsMain(input, file) {
    const files =
        getSelectedFiles(input);

    if (!files.includes(file)) {
        return;
    }

    mainFileStore.set(
        input,
        file
    );

    refreshMainVisual(input);
}

function removeSelectedImageByFile(
    input,
    file,
    item
) {
    const files =
        [...getSelectedFiles(input)];

    const index =
        files.indexOf(file);

    if (index < 0) {
        return;
    }

    const wasMain =
        mainFileStore.get(input) === file;

    files.splice(index, 1);

    applyFilesToInput(
        input,
        files
    );

    if (item && item.parentNode) {
        item.remove();
    }

    if (wasMain) {
        const nextMain =
            files[0] || null;

        if (nextMain) {
            mainFileStore.set(
                input,
                nextMain
            );
        } else {
            mainFileStore.delete(input);
        }
    }

    refreshMainVisual(input);
}

function prepareImageFilesBeforeSubmit() {
    document
        .querySelectorAll(
            '#productImages, .color-image-input'
        )
        .forEach(function (input) {
            const files =
                [...getSelectedFiles(input)];

            const mainFile =
                mainFileStore.get(input);

            if (
                mainFile &&
                files.includes(mainFile)
            ) {
                const currentIndex =
                    files.indexOf(mainFile);

                if (currentIndex > 0) {
                    files.splice(
                        currentIndex,
                        1
                    );

                    files.unshift(
                        mainFile
                    );
                }
            }

            applyFilesToInput(
                input,
                files
            );
        });
}

function createImagePreviewItem(
    input,
    file,
    index,
    className
) {
    const item =
        document.createElement('div');

    item.className = className;
    item._fileInput = input;
    item._previewFile = file;

    const reader =
        new FileReader();

    reader.onload =
        function (event) {
            const image =
                document.createElement(
                    'img'
                );

            image.src =
                event.target.result;

            image.alt = '';

            const actions =
                document.createElement(
                    'div'
                );

            actions.className =
                'variant-image-preview-actions';

            const mainLabel =
                document.createElement(
                    'label'
                );

            const mainRadio =
                document.createElement(
                    'input'
                );

            mainRadio.type = 'radio';
            mainRadio.className =
                'preview-main-radio';

            mainRadio.name =
                'preview_main_' +
                (
                    input.id ||
                    input.name ||
                    'images'
                );

            mainRadio.addEventListener(
                'change',
                function () {
                    if (this.checked) {
                        setImageAsMain(
                            input,
                            file
                        );
                    }
                }
            );

            const mainText =
                document.createElement(
                    'span'
                );

            mainText.textContent =
                'Ảnh chính';

            mainLabel.appendChild(
                mainRadio
            );

            mainLabel.appendChild(
                mainText
            );

            const deleteLabel =
                document.createElement(
                    'label'
                );

            deleteLabel.className =
                'delete-action';

            const deleteButton =
                document.createElement(
                    'button'
                );

            deleteButton.type =
                'button';

            deleteButton.className =
                'preview-delete-button';

            deleteButton.textContent =
                '×';

            deleteButton.addEventListener(
                'click',
                function (event) {
                    event.preventDefault();
                    event.stopPropagation();

                    removeSelectedImageByFile(
                        input,
                        file,
                        item
                    );
                }
            );

            const deleteText =
                document.createElement(
                    'span'
                );

            deleteText.textContent =
                'Xóa ảnh';

            deleteLabel.appendChild(
                deleteButton
            );

            deleteLabel.appendChild(
                deleteText
            );

            actions.appendChild(
                mainLabel
            );

            actions.appendChild(
                deleteLabel
            );

            item.appendChild(image);
            item.appendChild(actions);

            refreshMainVisual(input);
        };

    reader.readAsDataURL(file);

    return item;
}

function renderProductImagePreview(input) {
    const preview =
        document.getElementById(
            'imagePreview'
        );

    if (!preview) {
        return;
    }

    preview.innerHTML = '';

    const files =
        getSelectedFiles(input);

    if (
        files.length > 0 &&
        !mainFileStore.has(input)
    ) {
        mainFileStore.set(
            input,
            files[0]
        );
    }

    files.forEach(
        function (file, index) {
            preview.appendChild(
                createImagePreviewItem(
                    input,
                    file,
                    index,
                    'variant-image-preview-item'
                )
            );
        }
    );
}

function renderColorImagePreview(input) {
    const group =
        input.closest(
            '.color-group-card'
        );

    if (!group) {
        return;
    }

    const preview =
        group.querySelector(
            '.variant-image-preview'
        );

    if (!preview) {
        return;
    }

    preview.innerHTML = '';

    const files =
        getSelectedFiles(input);

    if (
        files.length > 0 &&
        !mainFileStore.has(input)
    ) {
        mainFileStore.set(
            input,
            files[0]
        );
    }

    files.forEach(
        function (file, index) {
            preview.appendChild(
                createImagePreviewItem(
                    input,
                    file,
                    index,
                    'variant-image-preview-item'
                )
            );
        }
    );
}

/* =========================================================
   FORMAT INPUT
========================================================= */
function onlyDigits(value) {
    return String(value || '').replace(/\D/g, '');
}

function formatThousands(value) {
    const digits = onlyDigits(value);

    if (digits === '') {
        return '';
    }

    return Number(digits).toLocaleString('vi-VN');
}

function syncCurrencyInput(input) {
    const row =
        input.closest('.capacity-row');

    if (!row) {
        return;
    }

    const hidden =
        row.querySelector('.currency-hidden');

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
        row.querySelector('.storage-select');

    const hidden =
        row.querySelector('.rom-hidden');

    if (hidden) {
        hidden.value =
            storageSelect
                ? storageSelect.value
                : '';
    }
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
    } else if (select.value === 'phan_tram') {
        unit.textContent = '%';

        const digits =
            onlyDigits(
                hidden.value || display.value
            );

        const percent =
            digits === ''
                ? ''
                : String(
                    Math.min(
                        100,
                        Number(digits)
                    )
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
            digits === ''
                ? ''
                : String(
                    Math.min(
                        100,
                        Number(digits)
                    )
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

/* Trước khi submit, đồng bộ toàn bộ input động */
document.addEventListener(
    'submit',
    function () {
        prepareImageFilesBeforeSubmit();
        document
            .querySelectorAll('.capacity-row')
            .forEach(function (row) {
                const storage =
                    row.querySelector('.storage-select');

                if (storage) {
                    syncStorageValue(storage);
                }

                const currency =
                    row.querySelector('.currency-display');

                if (currency) {
                    syncCurrencyInput(currency);
                }

                const promo =
                    row.querySelector('.promo-value-display');

                if (promo) {
                    syncPromoValue(promo);
                }
            });
    },
    true
);

/* =========================================================
   NHÓM MÀU + DUNG LƯỢNG
========================================================= */

let colorGroupSequence = 0;

function addColorGroup() {
    const box =
        document.getElementById('colorGroupBox');

    colorGroupSequence++;

    const colorKey =
        'color_' +
        colorGroupSequence +
        '_' +
        Date.now();

    const imageInputDisabled =
        variantImageTableReady
            ? ''
            : 'disabled';

    const html = `
        <div
            class="color-group-card"
            data-color-key="${colorKey}"
        >
            <div class="color-group-header">
                <div class="color-group-main">
                    <label class="form-label small fw-bold">
                        Màu sắc
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

            <div class="color-image-box">
                <div class="fw-bold small">
                    🖼 Ảnh của biến thể này
                </div>


                <div class="small text-muted mb-2">
                    Tối đa 5 ảnh. Ảnh được chọn “Ảnh chính” sẽ được ưu tiên hiển thị.
                </div>

                <input
                    type="file"
                    name="color_images[${colorKey}][]"
                    class="form-control color-image-input"
                    accept="image/jpeg,image/png,image/webp"
                    multiple
                    ${imageInputDisabled}
                    onchange="previewColorImages(this)"
                >

                <div class="variant-image-preview"></div>
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

    updateTotalStock();
}

function addCapacityRow(button) {
    const group =
        button.closest('.color-group-card');

    if (!group) {
        return;
    }

    variantSequence++;

    const colorKey =
        group.dataset.colorKey;

    const variantKey =
        'variant_' +
        variantSequence +
        '_' +
        Date.now();

    const selectedColor =
        group.querySelector(
            '.color-group-select'
        )?.value || '';

    const list =
        group.querySelector(
            '.capacity-list'
        );

    const html = `
        <div class="capacity-row variant-row">
            <input
                type="hidden"
                name="variant_key[]"
                value="${variantKey}"
            >

            <input
                type="hidden"
                name="variant_color_key[]"
                value="${colorKey}"
            >

            <input
                type="hidden"
                name="mau_variant[]"
                class="variant-color-hidden"
                value="${selectedColor}"
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
                value=""
            >

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
                            placeholder="26.400.000"
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
                        required
                        placeholder="0"
                        oninput="updateTotalStock()"
                    >
                </div>

                <div class="col-md-4 d-flex gap-1">

                    <button
                        type="button"
                        class="btn btn-outline-secondary flex-fill"
                        onclick="removeCapacityRow(this)"
                    >
                        Xóa
                    </button>
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

    const newestRow =
        list.lastElementChild;

    if (newestRow) {
        const storage =
            newestRow.querySelector(
                '.storage-select'
            );

        if (storage) {
            syncStorageValue(storage);
        }
    }

    checkVariants();
}

function syncColorGroup(select) {
    const group =
        select.closest('.color-group-card');

    if (!group) {
        return;
    }

    const colorId =
        select.value || '';

    group
        .querySelectorAll(
            '.variant-color-hidden'
        )
        .forEach(function (input) {
            input.value = colorId;
        });

    checkVariants();
}

function removeCapacityRow(button) {
    const group =
        button.closest('.color-group-card');

    const rows =
        group
            ? group.querySelectorAll(
                '.capacity-row'
            )
            : [];

    if (rows.length <= 1) {
        alert(
            'Mỗi biến thể phải có ít nhất một phiên bản dung lượng.'
        );
        return;
    }

    const row =
        button.closest('.capacity-row');

    if (row) {
        row.remove();
    }

    checkVariants();
}

function removeColorGroup(button) {
    const groups =
        document.querySelectorAll(
            '.color-group-card'
        );

    if (groups.length <= 1) {
        alert(
            'Sản phẩm phải có ít nhất một biến thể.'
        );
        return;
    }

    const group =
        button.closest(
            '.color-group-card'
        );

    if (group) {
        group.remove();
    }

    checkVariants();
}


/* =========================================================
   KIỂM TRA / CẬP NHẬT
========================================================= */
function checkVariants() {
    document
        .querySelectorAll(
            '.color-group-select'
        )
        .forEach(syncColorGroupSafe);

    updateTotalStock();
}

function syncColorGroupSafe(select) {
    const group =
        select.closest('.color-group-card');

    if (!group) {
        return;
    }

    const colorId =
        select.value || '';

    group
        .querySelectorAll(
            '.variant-color-hidden'
        )
        .forEach(function (input) {
            input.value = colorId;
        });
}

/* =========================================================
   TÍNH TỔNG TỒN KHO
========================================================= */
function updateTotalStock() {
    let total = 0;

    document
        .querySelectorAll(
            '.variant-stock'
        )
        .forEach(function (input) {
            total += Math.max(
                0,
                parseInt(input.value) || 0
            );
        });

    const output =
        document.getElementById(
            'totalVariantStock'
        );

    if (output) {
        output.innerText = total;
    }
}

/* =========================================================
   PREVIEW ẢNH THEO MÀU
========================================================= */
function previewColorImages(input) {
    const files =
        Array.from(
            input.files || []
        );

    if (files.length > 5) {
        alert(
            'Mỗi màu chỉ được chọn tối đa 5 ảnh.'
        );

        input.value = '';
        selectedFileStore.delete(input);
        renderColorImagePreview(input);
        return;
    }

    selectedFileStore.set(
        input,
        files
    );

    if (files.length > 0) {
        mainFileStore.set(
            input,
            files[0]
        );
    } else {
        mainFileStore.delete(input);
    }

    renderColorImagePreview(input);
}

/* =========================================================
   PREVIEW HÌNH ẢNH CHUNG
========================================================= */
function previewImages(event) {
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

    if (files.length > 0) {
        mainFileStore.set(
            input,
            files[0]
        );
    } else {
        mainFileStore.delete(input);
    }

    renderProductImagePreview(input);
}

/* =========================================================
   ĐẾM KÝ TỰ MÔ TẢ NGẮN
========================================================= */
function updateShortDescriptionCount() {
    const input = document.getElementById('mo_ta_ngan');
    const counter = document.getElementById('shortDescriptionCount');

    if (!input || !counter) {
        return;
    }

    counter.innerText = input.value.length;
}

/* Tạo sẵn dữ liệu khi mở trang */
addColorGroup();
initializeSpecs();
updateShortDescriptionCount();
</script>

<?php require_once "../../includes/footer.php"; ?>

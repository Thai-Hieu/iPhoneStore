<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';

/*
    Chỉ admin mới được gọi API lấy biến thể.
*/
if (
    (string)($_SESSION['user']['vai_tro'] ?? '')
    !== 'admin'
) {
    http_response_code(403);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        [
            'success' => false,
            'error' => 'Không có quyền.',
            'variants' => [],
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

header(
    'Content-Type: application/json; charset=utf-8'
);

$sanPhamId =
    (int)($_GET['san_pham_id'] ?? 0);

if ($sanPhamId <= 0) {
    echo json_encode(
        [
            'success' => false,
            'error' => 'Sản phẩm không hợp lệ.',
            'variants' => [],
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
    Thông tin tồn kho cấp sản phẩm dùng cho giao diện Xả kho:
    - tong_ton_kho: vẫn dùng cho trạng thái "còn hàng / hết hàng";
    - ton_bien_the_cao_nhat: dùng xét Xả kho toàn sản phẩm có biến thể;
    - co_bien_the: phân biệt sản phẩm có/không có biến thể.
*/
$productSummary = [
    'has_variants' => false,
    'total_stock' => 0,
    'max_variant_stock' => 0,
];

$stmtSummary = $conn->prepare("
    SELECT
        EXISTS (
            SELECT 1
            FROM san_pham_bien_the bt_exists
            WHERE bt_exists.san_pham_id = sp.id
        ) AS co_bien_the,

        CASE
            WHEN EXISTS (
                SELECT 1
                FROM san_pham_bien_the bt_exists2
                WHERE bt_exists2.san_pham_id = sp.id
            )
            THEN COALESCE((
                SELECT SUM(bt_sum.so_luong)
                FROM san_pham_bien_the bt_sum
                WHERE bt_sum.san_pham_id = sp.id
            ), 0)
            ELSE COALESCE(sp.so_luong, 0)
        END AS tong_ton_kho,

        COALESCE((
            SELECT MAX(bt_max.so_luong)
            FROM san_pham_bien_the bt_max
            WHERE bt_max.san_pham_id = sp.id
        ), 0) AS ton_bien_the_cao_nhat

    FROM san_pham sp
    WHERE sp.id = ?
    LIMIT 1
");

if ($stmtSummary) {
    $stmtSummary->bind_param('i', $sanPhamId);
    $stmtSummary->execute();

    $summaryRow =
        $stmtSummary
            ->get_result()
            ->fetch_assoc();

    $stmtSummary->close();

    if ($summaryRow) {
        $productSummary = [
            'has_variants' =>
                (int)($summaryRow['co_bien_the'] ?? 0) === 1,

            'total_stock' =>
                max(
                    0,
                    (int)($summaryRow['tong_ton_kho'] ?? 0)
                ),

            'max_variant_stock' =>
                max(
                    0,
                    (int)($summaryRow['ton_bien_the_cao_nhat'] ?? 0)
                ),
        ];
    }
}

/*
    Lấy toàn bộ biến thể.

    Không xóa biến thể hết hàng khỏi kết quả vì:
    - trang sửa khuyến mãi cũ vẫn cần biết biến thể trước đây là biến thể nào;
    - frontend sẽ dùng trường disabled/out_of_stock để khóa lựa chọn;
    - backend _common.php vẫn kiểm tra tồn kho khi submit.
*/
$stmt =
    $conn->prepare("
        SELECT
            bt.id,
            bt.ram,
            bt.rom,
            bt.gia,
            bt.so_luong,
            ms.ten_mau
        FROM san_pham_bien_the bt
        LEFT JOIN mau_sac ms
            ON ms.id = bt.mau_id
        WHERE bt.san_pham_id = ?
        ORDER BY
            CASE
                WHEN bt.so_luong <= 0
                THEN 1
                ELSE 0
            END,
            bt.gia ASC,
            bt.id ASC
    ");

if (!$stmt) {
    http_response_code(500);

    echo json_encode(
        [
            'success' => false,
            'error' => 'Không thể tải danh sách biến thể.',
            'variants' => [],
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

$stmt->bind_param(
    'i',
    $sanPhamId
);

$stmt->execute();

$rows =
    $stmt
        ->get_result()
        ->fetch_all(
            MYSQLI_ASSOC
        );

$stmt->close();

$variants = [];

foreach ($rows as $row) {
    $stock =
        (int)($row['so_luong'] ?? 0);

    $outOfStock =
        $stock <= 0;

    $parts = [];

    $rom =
        trim(
            (string)($row['rom'] ?? '')
        );

    $ram =
        trim(
            (string)($row['ram'] ?? '')
        );

    $color =
        trim(
            (string)($row['ten_mau'] ?? '')
        );

    if ($rom !== '') {
        $parts[] = $rom;
    }

    if ($ram !== '') {
        $parts[] = $ram;
    }

    if ($color !== '') {
        $parts[] = $color;
    }

    $parts[] =
        number_format(
            (float)($row['gia'] ?? 0),
            0,
            ',',
            '.'
        ) . 'đ';

    if ($outOfStock) {
        $parts[] =
            'HẾT HÀNG';
    } else {
        $parts[] =
            'còn ' . $stock;
    }

    $variants[] = [
        'id' =>
            (int)$row['id'],

        'label' =>
            implode(
                ' · ',
                $parts
            ),

        'price' =>
            (float)$row['gia'],

        'stock' =>
            $stock,

        /*
            Frontend dùng stock để kiểm tra thêm ngưỡng Xả kho.
            out_of_stock/disabled vẫn giữ nguyên cho logic hết hàng cũ.
        */
        'out_of_stock' =>
            $outOfStock,

        'disabled' =>
            $outOfStock,
    ];
}

echo json_encode(
    [
        'success' => true,
        'variants' => $variants,
        'product' => $productSummary,
    ],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);

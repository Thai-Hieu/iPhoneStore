<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_user.php";
require_once "../../config/database.php";
require_once "../../includes/notification_helper.php";
require_once __DIR__ . "/ham_ho_tro.php";

function dgChuanHoaFiles(
    array $files
): array {
    $result = [];

    if (
        !isset($files['name']) ||
        !is_array($files['name'])
    ) {
        return $result;
    }

    $count = count($files['name']);

    for ($i = 0; $i < $count; $i++) {
        $result[] = [
            'name' =>
                (string)($files['name'][$i] ?? ''),
            'type' =>
                (string)($files['type'][$i] ?? ''),
            'tmp_name' =>
                (string)($files['tmp_name'][$i] ?? ''),
            'error' =>
                (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' =>
                (int)($files['size'][$i] ?? 0)
        ];
    }

    return $result;
}

function dgLuuAnhTam(
    array $file,
    string $uploadDir
): ?array {
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception(
            'Có ảnh tải lên bị lỗi. Vui lòng thử lại.'
        );
    }

    if ($file['size'] <= 0 || $file['size'] > 5 * 1024 * 1024) {
        throw new Exception(
            'Mỗi ảnh đánh giá phải nhỏ hơn hoặc bằng 5MB.'
        );
    }

    $finfo =
        new finfo(FILEINFO_MIME_TYPE);

    $mime =
        $finfo->file(
            $file['tmp_name']
        );

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($extensions[$mime])) {
        throw new Exception(
            'Ảnh đánh giá chỉ hỗ trợ JPG, PNG hoặc WEBP.'
        );
    }

    if (
        @getimagesize(
            $file['tmp_name']
        ) === false
    ) {
        throw new Exception(
            'Tệp tải lên không phải ảnh hợp lệ.'
        );
    }

    if (!is_dir($uploadDir)) {
        if (
            !mkdir(
                $uploadDir,
                0775,
                true
            ) &&
            !is_dir($uploadDir)
        ) {
            throw new Exception(
                'Không thể tạo thư mục ảnh đánh giá.'
            );
        }
    }

    $fileName =
        'dg_' .
        date('Ymd_His') .
        '_' .
        bin2hex(random_bytes(8)) .
        '.' .
        $extensions[$mime];

    $fullPath =
        rtrim(
            $uploadDir,
            DIRECTORY_SEPARATOR
        ) .
        DIRECTORY_SEPARATOR .
        $fileName;

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $fullPath
        )
    ) {
        throw new Exception(
            'Không thể lưu ảnh đánh giá.'
        );
    }

    return [
        'file_name' => $fileName,
        'full_path' => $fullPath
    ];
}

$nguoiDungId =
    (int)($_SESSION['user']['id'] ?? 0);

$sanPhamId =
    (int)($_POST['san_pham_id'] ?? 0);

$redirectUrl =
    '/iPhoneStore/user/index.php';

if ($sanPhamId > 0) {
    $redirectUrl =
        '/iPhoneStore/user/san_pham/chi_tiet.php?id=' .
        $sanPhamId .
        '#reviews';
}

if (
    $_SERVER['REQUEST_METHOD'] !== 'POST' ||
    $nguoiDungId <= 0 ||
    $sanPhamId <= 0
) {
    header(
        'Location: ' .
        $redirectUrl
    );
    exit;
}

try {
    $csrf =
        (string)($_POST['csrf_token'] ?? '');

    $sessionCsrf =
        (string)(
            $_SESSION['review_csrf']
            ?? ''
        );

    if (
        $sessionCsrf === '' ||
        !hash_equals(
            $sessionCsrf,
            $csrf
        )
    ) {
        throw new Exception(
            'Phiên gửi đánh giá không hợp lệ. Vui lòng tải lại trang.'
        );
    }

    $meta = dgMeta($conn);

    $userColumn =
        $meta['user'];

    $contentColumn =
        $meta['content'];

    $dateColumn =
        $meta['date'];

    $statusColumn =
        $meta['status'];

    $idColumn =
        $meta['id'];

    if ($userColumn === null) {
        throw new Exception(
            'Bảng danh_gia chưa có cột nguoi_dung_id/user_id.'
        );
    }

    $soSao =
        (int)($_POST['so_sao'] ?? 0);

    $noiDung =
        trim(
            (string)(
                $_POST['noi_dung']
                ?? ''
            )
        );

    if ($soSao < 1 || $soSao > 5) {
        throw new Exception(
            'Vui lòng chọn từ 1 đến 5 sao.'
        );
    }

    if (
        mb_strlen(
            $noiDung,
            'UTF-8'
        ) > 1500
    ) {
        throw new Exception(
            'Nội dung đánh giá tối đa 1500 ký tự.'
        );
    }

    /* Mọi user đã đăng nhập đều được phép đánh giá. */

    $selectExisting =
        $idColumn !== null
            ? "`$idColumn` AS review_id"
            : 'so_sao';

    $stmtExisting = $conn->prepare("
        SELECT $selectExisting
        FROM danh_gia

        WHERE san_pham_id = ?
        AND `$userColumn` = ?

        " .
        (
            $idColumn !== null
                ? "ORDER BY `$idColumn` DESC"
                : ''
        ) . "

        LIMIT 1
    ");

    if (!$stmtExisting) {
        throw new Exception(
            'Không thể kiểm tra đánh giá hiện tại.'
        );
    }

    $stmtExisting->bind_param(
        "ii",
        $sanPhamId,
        $nguoiDungId
    );

    $stmtExisting->execute();

    $existing =
        $stmtExisting
            ->get_result()
            ->fetch_assoc();

    $laCapNhatDanhGia =
        (bool)$existing;

    $tenSanPham =
        'Sản phẩm';

    $stmtProductName = $conn->prepare("
        SELECT ten_san_pham
        FROM san_pham
        WHERE id = ?
        LIMIT 1
    ");

    if ($stmtProductName) {
        $stmtProductName->bind_param(
            "i",
            $sanPhamId
        );

        $stmtProductName->execute();

        $productRow =
            $stmtProductName
                ->get_result()
                ->fetch_assoc();

        if (!empty($productRow['ten_san_pham'])) {
            $tenSanPham =
                (string)$productRow['ten_san_pham'];
        }
    }

    $danhGiaId = 0;

    if ($existing) {
        $setParts = [
            'so_sao = ?'
        ];

        $types = 'i';

        $values = [
            $soSao
        ];

        if ($contentColumn !== null) {
            $setParts[] =
                "`$contentColumn` = ?";

            $types .= 's';
            $values[] = $noiDung;
        }

        if ($dateColumn !== null) {
            $setParts[] =
                "`$dateColumn` = NOW()";
        }

        if ($statusColumn !== null) {
            $setParts[] =
                "`$statusColumn` = 1";
        }

        if (
            $idColumn !== null &&
            !empty($existing['review_id'])
        ) {
            $where =
                "`$idColumn` = ?";

            $types .= 'i';
            $values[] =
                (int)$existing['review_id'];
        } else {
            $where =
                "san_pham_id = ?
                AND `$userColumn` = ?";

            $types .= 'ii';
            $values[] = $sanPhamId;
            $values[] = $nguoiDungId;
        }

        $sql = "
            UPDATE danh_gia
            SET " .
            implode(', ', $setParts) . "
            WHERE $where
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception(
                'Không thể chuẩn bị cập nhật đánh giá.'
            );
        }

        dgBindParams(
            $stmt,
            $types,
            $values
        );

        $stmt->execute();

        if (
            $idColumn !== null &&
            !empty($existing['review_id'])
        ) {
            $danhGiaId =
                (int)$existing['review_id'];
        }

    } else {
        $columns = [
            'san_pham_id',
            "`$userColumn`",
            'so_sao'
        ];

        $placeholders = [
            '?',
            '?',
            '?'
        ];

        $types = 'iii';

        $values = [
            $sanPhamId,
            $nguoiDungId,
            $soSao
        ];

        if ($contentColumn !== null) {
            $columns[] =
                "`$contentColumn`";

            $placeholders[] = '?';

            $types .= 's';
            $values[] = $noiDung;
        }

        if ($dateColumn !== null) {
            $columns[] =
                "`$dateColumn`";

            $placeholders[] =
                'NOW()';
        }

        if ($statusColumn !== null) {
            $columns[] =
                "`$statusColumn`";

            $placeholders[] = '1';
        }

        $sql = "
            INSERT INTO danh_gia (
                " .
                implode(', ', $columns) .
                "
            )
            VALUES (
                " .
                implode(
                    ', ',
                    $placeholders
                ) .
                "
            )
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception(
                'Không thể chuẩn bị lưu đánh giá.'
            );
        }

        dgBindParams(
            $stmt,
            $types,
            $values
        );

        $stmt->execute();

        $danhGiaId =
            (int)$conn->insert_id;
    }

    if (
        $danhGiaId <= 0 &&
        $idColumn !== null
    ) {
        $stmtReviewId = $conn->prepare("
            SELECT `$idColumn` AS id
            FROM danh_gia
            WHERE san_pham_id = ?
            AND `$userColumn` = ?
            ORDER BY `$idColumn` DESC
            LIMIT 1
        ");

        if ($stmtReviewId) {
            $stmtReviewId->bind_param(
                "ii",
                $sanPhamId,
                $nguoiDungId
            );

            $stmtReviewId->execute();

            $idRow =
                $stmtReviewId
                    ->get_result()
                    ->fetch_assoc();

            $danhGiaId =
                (int)($idRow['id'] ?? 0);
        }
    }

    /*
        ẢNH ĐÁNH GIÁ
        - Giữ ảnh cũ nếu user không chọn xóa.
        - Có thể thêm ảnh mới.
        - Tổng tối đa 5 ảnh.
    */
    if (
        $danhGiaId > 0 &&
        dgCoBangHinhAnhDanhGia($conn)
    ) {
        $uploadDir =
            dirname(
                __DIR__,
                2
            ) .
            '/uploads/danh_gia';

        $xoaIds =
            array_values(
                array_unique(
                    array_filter(
                        array_map(
                            'intval',
                            $_POST['xoa_hinh_anh']
                            ?? []
                        ),
                        static fn($id) => $id > 0
                    )
                )
            );

        $stmtExistingImages = $conn->prepare("
            SELECT
                id,
                hinh_anh
            FROM danh_gia_hinh_anh
            WHERE danh_gia_id = ?
            ORDER BY thu_tu ASC, id ASC
        ");

        $existingImages = [];

        if ($stmtExistingImages) {
            $stmtExistingImages->bind_param(
                "i",
                $danhGiaId
            );

            $stmtExistingImages->execute();

            $existingImages =
                $stmtExistingImages
                    ->get_result()
                    ->fetch_all(MYSQLI_ASSOC);
        }

        $ownedDeleteIds = [];

        foreach ($existingImages as $img) {
            $imgId = (int)$img['id'];

            if (
                in_array(
                    $imgId,
                    $xoaIds,
                    true
                )
            ) {
                $ownedDeleteIds[] = $imgId;
            }
        }

        $files =
            dgChuanHoaFiles(
                $_FILES['hinh_anh']
                ?? []
            );

        $newFiles =
            array_values(
                array_filter(
                    $files,
                    static fn($file) =>
                        (int)$file['error'] !==
                        UPLOAD_ERR_NO_FILE
                )
            );

        $remainingCount =
            count($existingImages) -
            count($ownedDeleteIds);

        if (
            $remainingCount +
            count($newFiles) >
            5
        ) {
            throw new Exception(
                'Mỗi đánh giá chỉ được tối đa 5 ảnh.'
            );
        }

        foreach ($existingImages as $img) {
            if (
                !in_array(
                    (int)$img['id'],
                    $ownedDeleteIds,
                    true
                )
            ) {
                continue;
            }

            $stmtDeleteImage =
                $conn->prepare("
                    DELETE FROM danh_gia_hinh_anh
                    WHERE id = ?
                    AND danh_gia_id = ?
                    LIMIT 1
                ");

            if ($stmtDeleteImage) {
                $imageId =
                    (int)$img['id'];

                $stmtDeleteImage->bind_param(
                    "ii",
                    $imageId,
                    $danhGiaId
                );

                $stmtDeleteImage->execute();
            }

            $oldPath =
                $uploadDir .
                DIRECTORY_SEPARATOR .
                basename(
                    (string)$img['hinh_anh']
                );

            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $stmtCountImages =
            $conn->prepare("
                SELECT COUNT(*) AS tong
                FROM danh_gia_hinh_anh
                WHERE danh_gia_id = ?
            ");

        $currentCount = 0;

        if ($stmtCountImages) {
            $stmtCountImages->bind_param(
                "i",
                $danhGiaId
            );

            $stmtCountImages->execute();

            $currentCount =
                (int)(
                    $stmtCountImages
                        ->get_result()
                        ->fetch_assoc()['tong']
                    ?? 0
                );
        }

        $savedNewFiles = [];

        try {
            foreach ($newFiles as $file) {
                $saved =
                    dgLuuAnhTam(
                        $file,
                        $uploadDir
                    );

                if ($saved === null) {
                    continue;
                }

                $savedNewFiles[] =
                    $saved['full_path'];

                $currentCount++;

                $stmtInsertImage =
                    $conn->prepare("
                        INSERT INTO danh_gia_hinh_anh (
                            danh_gia_id,
                            hinh_anh,
                            thu_tu,
                            ngay_tao
                        )
                        VALUES (
                            ?,
                            ?,
                            ?,
                            NOW()
                        )
                    ");

                if (!$stmtInsertImage) {
                    throw new Exception(
                        'Không thể lưu thông tin ảnh đánh giá.'
                    );
                }

                $stmtInsertImage->bind_param(
                    "isi",
                    $danhGiaId,
                    $saved['file_name'],
                    $currentCount
                );

                $stmtInsertImage->execute();
            }
        } catch (Throwable $imageError) {
            foreach ($savedNewFiles as $filePath) {
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }

            throw $imageError;
        }
    }

    unset(
        $_SESSION['review_error']
    );

    /*
        Thông báo sau khi user gửi/sửa đánh giá.
        Lỗi thông báo không làm hỏng đánh giá đã lưu.
    */
    try {
        if (function_exists('taoThongBao')) {
            taoThongBao(
                $conn,
                $nguoiDungId,
                $laCapNhatDanhGia
                    ? 'Đánh giá đã được cập nhật'
                    : 'Đánh giá đã được ghi nhận',
                'Đánh giá ' .
                    $soSao .
                    ' sao của bạn cho ' .
                    $tenSanPham .
                    ' đã được lưu.',
                'danh_gia',
                '/iPhoneStore/user/san_pham/chi_tiet.php?id=' .
                    $sanPhamId .
                    '#reviews',
                null
            );
        }

        if (
            function_exists(
                'guiThongBaoChoTatCaAdmin'
            )
        ) {
            guiThongBaoChoTatCaAdmin(
                $conn,
                $laCapNhatDanhGia
                    ? 'Khách hàng cập nhật đánh giá'
                    : 'Có đánh giá sản phẩm mới',
                'Khách hàng vừa ' .
                    (
                        $laCapNhatDanhGia
                            ? 'cập nhật'
                            : 'gửi'
                    ) .
                    ' đánh giá ' .
                    $soSao .
                    ' sao cho ' .
                    $tenSanPham .
                    '.',
                'danh_gia',
                '/iPhoneStore/admin/danh_gia/index.php',
                null
            );
        }
    } catch (Throwable $notificationError) {
        error_log(
            'iPhoneStore review notification error: ' .
            $notificationError->getMessage()
        );
    }

    header(
        'Location: /iPhoneStore/user/san_pham/chi_tiet.php?id=' .
        $sanPhamId .
        '&review=success#reviews'
    );
    exit;

} catch (Throwable $e) {
    $_SESSION['review_error'] =
        $e->getMessage();

    header(
        'Location: ' .
        $redirectUrl
    );
    exit;
}

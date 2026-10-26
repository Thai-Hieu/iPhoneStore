<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

function redirectBack(string $status): void
{
    $query = [
        'order' => $status,
        'page' => max(1, (int)($_POST['page'] ?? 1)),
    ];

    $q = trim((string)($_POST['q'] ?? ''));
    $danhMucId = (int)($_POST['danh_muc_id'] ?? 0);
    $trangThai = (string)($_POST['trang_thai'] ?? '');

    if ($q !== '') {
        $query['q'] = $q;
    }

    if ($danhMucId > 0) {
        $query['danh_muc_id'] = $danhMucId;
    }

    if (in_array($trangThai, ['0', '1'], true)) {
        $query['trang_thai'] = $trangThai;
    }

    header('Location: index.php?' . http_build_query($query));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$viTriMoi = (int)($_POST['thu_tu_hien_thi'] ?? 0);

if ($id <= 0 || $viTriMoi <= 0) {
    redirectBack('invalid');
}

$checkColumn = $conn->query("
    SHOW COLUMNS
    FROM san_pham
    LIKE 'thu_tu_hien_thi'
");

if (!$checkColumn || $checkColumn->num_rows === 0) {
    redirectBack('error');
}

/*
    Chuẩn hóa toàn bộ thứ tự trước khi dịch chuyển:
    - không để NULL / 0 / trùng số gây sai luồng
    - giữ thứ tự hiện có
    - nếu trùng thì id mới hơn đứng trước
*/
$conn->begin_transaction();

try {
    $lockResult = $conn->query("
        SELECT
            id,
            thu_tu_hien_thi
        FROM san_pham
        ORDER BY
            CASE
                WHEN thu_tu_hien_thi IS NULL OR thu_tu_hien_thi <= 0
                THEN 999999
                ELSE thu_tu_hien_thi
            END ASC,
            id DESC
        FOR UPDATE
    ");

    if (!$lockResult) {
        throw new Exception('Không thể khóa danh sách sản phẩm.');
    }

    $rows = [];
    while ($row = $lockResult->fetch_assoc()) {
        $rows[] = $row;
    }

    if (empty($rows)) {
        throw new Exception('Không có sản phẩm.');
    }

    $exists = false;
    foreach ($rows as $row) {
        if ((int)$row['id'] === $id) {
            $exists = true;
            break;
        }
    }

    if (!$exists) {
        throw new Exception('Không tìm thấy sản phẩm.');
    }

    /*
        Gán lại thứ tự liên tục 1..N.
        Việc này giúp xử lý cả dữ liệu cũ nếu từng có số trùng.
    */
    $normalizeStmt = $conn->prepare("
        UPDATE san_pham
        SET thu_tu_hien_thi = ?
        WHERE id = ?
        LIMIT 1
    ");

    if (!$normalizeStmt) {
        throw new Exception('Không thể chuẩn hóa thứ tự.');
    }

    $viTriCu = 0;
    $stt = 1;

    foreach ($rows as $row) {
        $rowId = (int)$row['id'];

        $normalizeStmt->bind_param('ii', $stt, $rowId);

        if (!$normalizeStmt->execute()) {
            throw new Exception('Lỗi chuẩn hóa thứ tự.');
        }

        if ($rowId === $id) {
            $viTriCu = $stt;
        }

        $stt++;
    }

    $normalizeStmt->close();

    $tongSanPham = count($rows);

    if ($viTriMoi > $tongSanPham) {
        $viTriMoi = $tongSanPham;
    }

    if ($viTriCu <= 0) {
        throw new Exception('Không xác định được vị trí cũ.');
    }

    if ($viTriMoi !== $viTriCu) {
        /*
            Trường hợp kéo sản phẩm LÊN:
            VD 16 -> 1
            Các sản phẩm đang ở 1..15 sẽ +1.
        */
        if ($viTriMoi < $viTriCu) {
            $shiftStmt = $conn->prepare("
                UPDATE san_pham
                SET thu_tu_hien_thi = thu_tu_hien_thi + 1
                WHERE id <> ?
                  AND thu_tu_hien_thi >= ?
                  AND thu_tu_hien_thi < ?
            ");

            if (!$shiftStmt) {
                throw new Exception('Không thể dịch thứ tự lên.');
            }

            $shiftStmt->bind_param(
                'iii',
                $id,
                $viTriMoi,
                $viTriCu
            );

            if (!$shiftStmt->execute()) {
                throw new Exception('Lỗi dịch thứ tự lên.');
            }

            $shiftStmt->close();
        }

        /*
            Trường hợp đẩy sản phẩm XUỐNG:
            VD 2 -> 10
            Các sản phẩm đang ở 3..10 sẽ -1.
        */
        if ($viTriMoi > $viTriCu) {
            $shiftStmt = $conn->prepare("
                UPDATE san_pham
                SET thu_tu_hien_thi = thu_tu_hien_thi - 1
                WHERE id <> ?
                  AND thu_tu_hien_thi > ?
                  AND thu_tu_hien_thi <= ?
            ");

            if (!$shiftStmt) {
                throw new Exception('Không thể dịch thứ tự xuống.');
            }

            $shiftStmt->bind_param(
                'iii',
                $id,
                $viTriCu,
                $viTriMoi
            );

            if (!$shiftStmt->execute()) {
                throw new Exception('Lỗi dịch thứ tự xuống.');
            }

            $shiftStmt->close();
        }

        $moveStmt = $conn->prepare("
            UPDATE san_pham
            SET thu_tu_hien_thi = ?
            WHERE id = ?
            LIMIT 1
        ");

        if (!$moveStmt) {
            throw new Exception('Không thể cập nhật vị trí mới.');
        }

        $moveStmt->bind_param(
            'ii',
            $viTriMoi,
            $id
        );

        if (!$moveStmt->execute()) {
            throw new Exception('Lỗi cập nhật vị trí mới.');
        }

        $moveStmt->close();
    }

    $conn->commit();

    redirectBack('updated');
} catch (Throwable $e) {
    $conn->rollback();
    redirectBack('error');
}

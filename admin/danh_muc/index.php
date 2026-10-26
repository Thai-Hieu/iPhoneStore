<?php
session_start();
require_once "../../middleware/check_admin.php";

require_once "../../config/database.php";

$result = $conn->query("SELECT * FROM danh_muc ORDER BY id DESC");

// XÓA
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $conn->query("DELETE FROM danh_muc WHERE id = $id");
    header("Location: index.php");
}
?>

<?php 

require_once "../../includes/header.php";
require_once "../../includes/navbar.php";

?>

<div class="container my-4">
    <h3 class="mb-3">📂 Quan ly danh muc</h3>

    <a href="them.php" class="btn btn-success mb-3">➕ Them danh muc</a>

    <table class="table table-bordered table-hover">
        <thead class="table-dark">
            <tr>
                <th>ID</th>
                <th>Ten danh muc</th>
                <th>Trang thai</th>
                <th width="180">Hanh dong</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><?= $row['id'] ?></td>
                <td><?= $row['ten_danh_muc'] ?></td>
                <td>
                    <?= $row['trang_thai'] ? 'Hien thi' : 'An' ?>
                </td>
                <td>
                    <a href="sua.php?id=<?= $row['id'] ?>" class="btn btn-warning btn-sm">✏️ Sua</a>
                    <a href="?delete=<?= $row['id'] ?>" 
                       onclick="return confirm('Xoa danh muc nay?')"
                       class="btn btn-danger btn-sm">🗑️ Xoa</a>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php include "../../includes/footer.php"; ?>

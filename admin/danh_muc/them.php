<?php
session_start();
require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $ten = $_POST['ten_danh_muc'];
    $trang_thai = $_POST['trang_thai'];

    $stmt = $conn->prepare(
        "INSERT INTO danh_muc (ten_danh_muc, trang_thai) VALUES (?, ?)"
    );
    $stmt->bind_param("si", $ten, $trang_thai);
    $stmt->execute();

    header("Location: index.php");
}
?>

<?php 

require_once "../../includes/header.php";
require_once "../../includes/navbar.php";

?>
<div class="container my-4">
    <h3>➕ Them danh muc</h3>

    <form method="POST">
        <div class="mb-3">
            <label>Ten danh muc</label>
            <input type="text" name="ten_danh_muc" class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Trang thai</label>
            <select name="trang_thai" class="form-control">
                <option value="1">Hien thi</option>
                <option value="0">An</option>
            </select>
        </div>

        <button class="btn btn-success">Luu</button>
        <a href="index.php" class="btn btn-secondary">Quay lai</a>
    </form>
</div>

<?php include "../../includes/footer.php"; ?>

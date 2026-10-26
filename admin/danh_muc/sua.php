<?php
session_start();
require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";

$id = (int)$_GET['id'];
$result = $conn->query("SELECT * FROM danh_muc WHERE id = $id");
$danh_muc = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $ten = $_POST['ten_danh_muc'];
    $trang_thai = $_POST['trang_thai'];

    $stmt = $conn->prepare(
        "UPDATE danh_muc SET ten_danh_muc=?, trang_thai=? WHERE id=?"
    );
    $stmt->bind_param("sii", $ten, $trang_thai, $id);
    $stmt->execute();

    header("Location: index.php");
}
?>


<?php 

require_once "../../includes/header.php";
require_once "../../includes/navbar.php";

?>
<div class="container my-4">
    <h3>✏️ Sua danh muc</h3>

    <form method="POST">
        <div class="mb-3">
            <label>Ten danh muc</label>
            <input type="text" name="ten_danh_muc"
                   value="<?= $danh_muc['ten_danh_muc'] ?>"
                   class="form-control" required>
        </div>

        <div class="mb-3">
            <label>Trang thai</label>
            <select name="trang_thai" class="form-control">
                <option value="1" <?= $danh_muc['trang_thai'] ? 'selected' : '' ?>>Hien thi</option>
                <option value="0" <?= !$danh_muc['trang_thai'] ? 'selected' : '' ?>>An</option>
            </select>
        </div>

        <button class="btn btn-warning">Cap nhat</button>
        <a href="index.php" class="btn btn-secondary">Quay lai</a>
    </form>
</div>

<?php include "../../includes/footer.php"; ?>

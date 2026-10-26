<?php
require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once __DIR__ . "/thong_ke_helper.php";
require_once __DIR__ . "/_layout.php";
$range=tkDateRange();
$perPage=10;
$page=tkPageNumber('page');
$totalOrders=tkOrdersCount($conn,$range['from_sql'],$range['to_sql']);
$pager=tkPager($totalOrders,$page,$perPage);
$page=$pager['page'];
include "../../includes/header.php";
include "../../includes/navbar.php";
tkRenderStyles();
$o=tkOverview($conn,$range['from_sql'],$range['to_sql']);
$status=tkOrderStatus($conn,$range['from_sql'],$range['to_sql']);
$rows=tkOrdersHighValue($conn,$range['from_sql'],$range['to_sql'],$perPage,$pager['offset']);
?>
<div class="tk-shell">
    <div class="tk-hero">
        <h1 class="tk-title">📦 Thống kê đơn hàng</h1>
        <div class="tk-sub">Theo dõi trạng thái xử lý và các đơn có giá trị cao nhất trong khoảng thời gian đã chọn.</div>
    </div>
    <?php tkRenderNav('orders',$range); tkRenderFilter($range); ?>

    <div class="row g-3 mb-3">
        <?php $cards=[['⏳','Chờ xử lý',$o['don_cho_xu_ly']],['📝','Đã duyệt / xử lý',$o['don_da_duyet']],['🚚','Đang giao',$o['don_dang_giao']],['✅','Hoàn thành',$o['don_hoan_thanh']],['❌','Đã hủy',$o['don_da_huy']],['📈','Tỷ lệ hoàn thành',number_format($o['ty_le_hoan_thanh'],1).'%']]; foreach($cards as $c): ?>
        <div class="col-6 col-lg-4 col-xl-2"><div class="tk-card"><div class="tk-card-body"><div class="tk-kpi-label"><?=h($c[1])?></div><div class="tk-kpi-value"><?=h($c[2])?></div></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-xl-4">
            <div class="tk-section h-100">
                <h2 class="tk-section-title mb-2">Cơ cấu trạng thái đơn</h2>
                <div class="tk-section-desc mb-3">Tất cả trạng thái đều hiển thị bằng tiếng Việt để admin dễ đọc.</div>
                <div class="tk-chart-sm"><canvas id="status"></canvas></div>
            </div>
        </div>
        <div class="col-xl-8">
            <div class="tk-section h-100">
                <div class="tk-section-head">
                    <div>
                        <h2 class="tk-section-title">Đơn hàng có giá trị cao nhất</h2>
                        <div class="tk-section-desc">Sắp xếp từ tổng tiền lớn xuống nhỏ. Có mã đơn hệ thống và mã vận đơn riêng biệt.</div>
                    </div>
                    <div class="tk-actions"><a class="btn btn-success tk-export-big" href="<?=h(tkExportUrl('don_hang',$range))?>">📥 Xuất Excel đơn hàng</a></div>
                </div>
                <div class="tk-note"><b>Lưu ý:</b> Mã đơn hệ thống được hiển thị dạng <b>DH000073</b>. Chuỗi <b>8686xxxx</b> trong bảng đơn hàng là <b>mã vận đơn</b>; nếu chưa được cấp sẽ hiện “Chưa có mã vận đơn”.</div>
                <div class="table-responsive">
                    <table class="table tk-table">
                        <thead><tr><th>Xếp hạng</th><th>Mã đơn</th><th>Mã vận đơn</th><th>Ngày đặt</th><th>Khách hàng</th><th>Thanh toán</th><th>Trạng thái đơn</th><th>TT thanh toán</th><th class="text-end">Tổng tiền</th></tr></thead>
                        <tbody>
                        <?php if(!$rows): ?><tr><td colspan="9" class="tk-empty">Không có đơn hàng trong khoảng thời gian này.</td></tr><?php endif; ?>
                        <?php foreach($rows as $i=>$r): $rank=$pager['offset']+$i+1; ?>
                            <tr>
                                <td><span class="tk-rank"><?=$rank?></span></td>
                                <td class="fw-bold"><?=h(tkOrderCode((int)$r['id']))?></td>
                                <td><?=h(tkTrackingCode($r['ma_van_don']))?></td>
                                <td><?=date('d/m/Y H:i',strtotime($r['ngay_dat']))?></td>
                                <td><?=h($r['khach_hang'])?></td>
                                <td><?=h($r['phuong_thuc'])?></td>
                                <td><?=tkBadge($r['trang_thai'])?></td>
                                <td><?=h(tkPaymentStatusLabel($r['trang_thai_thanh_toan']))?></td>
                                <td class="text-end fw-bold"><?=tkMoney($r['tong_tien'])?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php tkRenderPagination($pager,$range,'page'); ?>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('status'),{type:'doughnut',data:{labels:<?=json_encode(array_column($status,'label'),JSON_UNESCAPED_UNICODE)?>,datasets:[{data:<?=json_encode(array_column($status,'value'))?>,backgroundColor:['#f4b740','#5b8def','#55a868','#2ca25f','#e15759'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'67%',plugins:{legend:{position:'bottom'}}}});
</script>
<?php include "../../includes/footer.php"; ?>

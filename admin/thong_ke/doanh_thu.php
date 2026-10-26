<?php
require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once __DIR__ . "/thong_ke_helper.php";
require_once __DIR__ . "/_layout.php";
$range=tkDateRange();
$overview=tkOverview($conn,$range['from_sql'],$range['to_sql']);
$trend=tkRevenueTrend($conn,$range['from_sql'],$range['to_sql'],$range['preset']);
$payments=tkPaymentMethods($conn,$range['from_sql'],$range['to_sql']);
$perPage=10;
$page=tkPageNumber('page');
$totalRevenueRows=tkRevenueDetailCount($conn,$range['from_sql'],$range['to_sql']);
$pager=tkPager($totalRevenueRows,$page,$perPage);
$details=tkRevenueDetails($conn,$range['from_sql'],$range['to_sql'],$pager['per_page'],$pager['offset']);
include "../../includes/header.php";
include "../../includes/navbar.php";
tkRenderStyles();
?>
<div class="tk-shell">
    <div class="tk-hero">
        <h1 class="tk-title">💰 Thống kê doanh thu</h1>
        <div class="tk-sub">Theo dõi tiền thu từ các đơn đã giao/hoàn thành và đối soát chi tiết từng sản phẩm đã bán.</div>
    </div>
    <?php tkRenderNav('revenue',$range); tkRenderFilter($range); ?>

    <div class="tk-note">Doanh thu chỉ ghi nhận từ các đơn <b>Đã giao / Hoàn thành</b>. Bảng chi tiết bên dưới xếp hạng theo <b>thành tiền của từng sản phẩm trong đơn</b>, từ cao xuống thấp.</div>

    <div class="row g-3 mb-3">
        <?php
        $cards=[
            ['💰','Tổng doanh thu',tkMoney($overview['doanh_thu']),'Tiền từ các đơn hoàn thành'],
            ['✅','Đơn hoàn thành',(int)$overview['don_hoan_thanh'],'Đơn được tính vào doanh thu'],
            ['🛒','Sản phẩm đã bán',(int)$overview['san_pham_da_ban'],'Tổng số lượng sản phẩm'],
            ['🧾','Giá trị TB/đơn',tkMoney($overview['gia_tri_don_trung_binh']),'Doanh thu trung bình mỗi đơn']
        ];
        foreach($cards as $c): ?>
            <div class="col-12 col-sm-6 col-xl-3"><div class="tk-card"><div class="tk-card-body tk-kpi"><div><div class="tk-kpi-label"><?=h($c[1])?></div><div class="tk-kpi-value"><?=h($c[2])?></div><div class="tk-kpi-note"><?=h($c[3])?></div></div><div class="tk-icon"><?=$c[0]?></div></div></div></div>
        <?php endforeach; ?>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="tk-section h-100">
                <div class="tk-section-head"><div><h2 class="tk-section-title">Xu hướng doanh thu</h2><div class="tk-section-desc">Theo ngày; nếu chọn Năm nay sẽ tự gom theo tháng.</div></div></div>
                <div class="tk-chart"><canvas id="trend"></canvas></div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="tk-section h-100">
                <div class="tk-section-head"><div><h2 class="tk-section-title">Theo phương thức thanh toán</h2><div class="tk-section-desc">Tỷ trọng doanh thu của các phương thức thanh toán.</div></div></div>
                <div class="tk-chart-sm"><canvas id="pay"></canvas></div>
            </div>
        </div>
    </div>

    <div class="tk-section">
        <div class="tk-section-head">
            <div>
                <h2 class="tk-section-title">Chi tiết giao dịch tạo doanh thu</h2>
                <div class="tk-section-desc">Có <?=number_format($totalRevenueRows,0,',','.')?> dòng sản phẩm phát sinh doanh thu trong khoảng thời gian đang chọn.</div>
            </div>
            <div class="tk-actions"><a class="btn btn-success tk-export-big" href="<?=h(tkExportUrl('doanh_thu',$range))?>">📥 Xuất Excel doanh thu chi tiết</a></div>
        </div>
        <div class="table-responsive">
            <table class="table tk-table align-middle">
                <thead><tr>
                    <th class="text-center">Xếp hạng</th><th>Mã sản phẩm</th><th>Tên sản phẩm</th><th>Phiên bản</th><th>Mã đơn</th><th>Mã vận đơn</th><th class="text-center">SL</th><th class="text-end">Đơn giá</th><th class="text-end">Thành tiền</th><th>Thời gian</th><th>Khách hàng</th><th>Phương thức TT</th><th>Trạng thái TT</th>
                </tr></thead>
                <tbody>
                <?php if(!$details): ?>
                    <tr><td colspan="13" class="tk-empty">Chưa có giao dịch doanh thu trong khoảng thời gian này.</td></tr>
                <?php else: foreach($details as $i=>$r): ?>
                    <tr>
                        <td class="text-center"><span class="tk-rank"><?=$pager['offset']+$i+1?></span></td>
                        <td class="fw-semibold"><?=h($r['ma_san_pham'])?></td>
                        <td><div class="fw-semibold"><?=h($r['ten_san_pham'])?></div></td>
                        <td><?=h(tkVariantLabel($r))?></td>
                        <td class="fw-semibold"><?=h(tkOrderCode((int)$r['don_hang_id']))?></td>
                        <td><?=h(tkTrackingCode($r['ma_van_don']))?></td>
                        <td class="text-center fw-semibold"><?=(int)$r['so_luong']?></td>
                        <td class="text-end"><?=tkMoney($r['gia_ban'])?></td>
                        <td class="text-end fw-bold"><?=tkMoney($r['thanh_tien'])?></td>
                        <td><?=date('d/m/Y H:i',strtotime($r['ngay_dat']))?></td>
                        <td><?=h($r['khach_hang'])?></td>
                        <td><?=h($r['phuong_thuc'])?></td>
                        <td><span class="tk-badge <?=mb_strtolower(tkPaymentStatusLabel($r['trang_thai_thanh_toan']),'UTF-8')==='đã thanh toán'?'tk-ok':'tk-warn'?>"><?=h(tkPaymentStatusLabel($r['trang_thai_thanh_toan']))?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php tkRenderPagination($pager,$range,'page'); ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('trend'),{type:'line',data:{labels:<?=json_encode(array_column($trend,'moc'),JSON_UNESCAPED_UNICODE)?>,datasets:[{label:'Doanh thu',data:<?=json_encode(array_map('floatval',array_column($trend,'doanh_thu')))?>,borderColor:'#2457e6',backgroundColor:'rgba(36,87,230,.12)',fill:true,tension:.35,pointRadius:3,pointHoverRadius:6}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'Doanh thu: '+new Intl.NumberFormat('vi-VN').format(c.raw)+'đ'}}},scales:{y:{beginAtZero:true,ticks:{callback:v=>new Intl.NumberFormat('vi-VN',{notation:'compact'}).format(v)+'đ'}}}}});
new Chart(document.getElementById('pay'),{type:'doughnut',data:{labels:<?=json_encode(array_column($payments,'phuong_thuc'),JSON_UNESCAPED_UNICODE)?>,datasets:[{data:<?=json_encode(array_map('floatval',array_column($payments,'doanh_thu')))?>,backgroundColor:['#2457e6','#2ca25f','#f4b740','#8b5cf6','#e15759'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'64%',plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:c=>c.label+': '+new Intl.NumberFormat('vi-VN').format(c.raw)+'đ'}}}}});
</script>
<?php include "../../includes/footer.php"; ?>

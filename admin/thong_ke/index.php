<?php
require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once __DIR__ . "/thong_ke_helper.php";
require_once __DIR__ . "/_layout.php";
$range=tkDateRange();
include "../../includes/header.php";
include "../../includes/navbar.php";
tkRenderStyles();
?>

<div class="tk-shell">
<div class="tk-hero"><h1 class="tk-title">📊 Tổng quan hoạt động iPhoneStore</h1><div class="tk-sub">Bức tranh nhanh về doanh thu, đơn hàng, sản phẩm và khách hàng trong kỳ đã chọn.</div></div>
<?php tkRenderNav('overview',$range); tkRenderFilter($range); $o=tkOverview($conn,$range['from_sql'],$range['to_sql']); $trend=tkRevenueTrend($conn,$range['from_sql'],$range['to_sql'],$range['preset']); $status=tkOrderStatus($conn,$range['from_sql'],$range['to_sql']); $top=tkTopProducts($conn,$range['from_sql'],$range['to_sql'],5); ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><div class="text-muted small">Kỳ: <b><?= date('d/m/Y',strtotime($range['from'])) ?></b> - <b><?= date('d/m/Y',strtotime($range['to'])) ?></b></div><a class="btn btn-success rounded-3 fw-bold" href="<?= h(tkExportUrl('all',$range)) ?>">📥 Xuất báo cáo tổng hợp</a></div>
<div class="row g-3 mb-3">
<?php $cards=[['💰','Doanh thu',tkMoney($o['doanh_thu']),'Chỉ tính đơn hoàn thành'],['✅','Đơn hoàn thành',$o['don_hoan_thanh'],number_format($o['ty_le_hoan_thanh'],1).'% tổng đơn'],['⏳','Đơn cần xử lý',(int)$o['don_cho_xu_ly']+(int)$o['don_da_duyet']+(int)$o['don_dang_giao'],'Chờ xử lý + đã duyệt + đang giao'],['🛒','Sản phẩm đã bán',$o['san_pham_da_ban'],'Theo đơn hoàn thành'],['👥','Khách mới',$o['khach_moi'],'Tài khoản khách tạo trong kỳ'],['🧾','Giá trị TB/đơn',tkMoney($o['gia_tri_don_trung_binh']),'Doanh thu / đơn hoàn thành']]; foreach($cards as $c): ?>
<div class="col-12 col-sm-6 col-xl-4"><div class="tk-card"><div class="tk-card-body tk-kpi"><div><div class="tk-kpi-label"><?= h($c[1]) ?></div><div class="tk-kpi-value"><?= h($c[2]) ?></div><div class="tk-kpi-note"><?= h($c[3]) ?></div></div><div class="tk-icon"><?= $c[0] ?></div></div></div></div><?php endforeach; ?>
</div>
<div class="row g-3"><div class="col-xl-8"><div class="tk-section"><div class="tk-section-head"><div><h2 class="tk-section-title">Xu hướng doanh thu</h2><div class="tk-section-desc">Nhìn nhanh doanh thu từ các đơn hoàn thành.</div></div><div class="tk-actions"><a class="btn btn-outline-primary" href="<?= h(tkPageUrl('doanh_thu.php',$range)) ?>">Xem chi tiết →</a></div></div><div class="tk-chart"><canvas id="rev"></canvas></div></div></div>
<div class="col-xl-4"><div class="tk-section"><div class="tk-section-head"><div><h2 class="tk-section-title">Cơ cấu đơn hàng</h2><div class="tk-section-desc">Tỷ trọng trạng thái đơn trong kỳ.</div></div><div class="tk-actions"><a class="btn btn-outline-primary" href="<?= h(tkPageUrl('don_hang.php',$range)) ?>">Chi tiết →</a></div></div><div class="tk-chart-sm"><canvas id="orders"></canvas></div></div></div></div>
<div class="tk-section"><div class="tk-section-head"><div><h2 class="tk-section-title">Top sản phẩm bán chạy</h2><div class="tk-section-desc">5 sản phẩm có số lượng bán cao nhất.</div></div><div class="tk-actions"><a class="btn btn-outline-primary" href="<?= h(tkPageUrl('san_pham.php',$range)) ?>">Xem sản phẩm →</a></div></div><div class="table-responsive"><table class="table tk-table"><thead><tr><th>#</th><th>Sản phẩm</th><th>Mã SP</th><th class="text-center">Đã bán</th><th class="text-end">Doanh thu</th></tr></thead><tbody><?php foreach($top as $i=>$r): ?><tr><td><?= $i+1 ?></td><td class="fw-semibold"><?= h($r['ten_san_pham']) ?></td><td><?= h($r['ma_san_pham']) ?></td><td class="text-center"><?= (int)$r['so_luong_ban'] ?></td><td class="text-end fw-semibold"><?= tkMoney($r['doanh_thu']) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script><script>
new Chart(document.getElementById('rev'),{type:'line',data:{labels:<?= json_encode(array_column($trend,'moc'),JSON_UNESCAPED_UNICODE) ?>,datasets:[{label:'Doanh thu',data:<?= json_encode(array_map('floatval',array_column($trend,'doanh_thu'))) ?>,borderColor:'#2457e6',backgroundColor:'rgba(36,87,230,.12)',fill:true,tension:.35,pointRadius:3}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{callback:v=>new Intl.NumberFormat('vi-VN',{notation:'compact'}).format(v)+'đ'}}}}});
new Chart(document.getElementById('orders'),{type:'doughnut',data:{labels:<?= json_encode(array_column($status,'label'),JSON_UNESCAPED_UNICODE) ?>,datasets:[{data:<?= json_encode(array_column($status,'value')) ?>,backgroundColor:['#f4b740','#5b8def','#55a868','#2ca25f','#e15759'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'68%',plugins:{legend:{position:'bottom'}}}});
</script><?php include "../../includes/footer.php"; ?>
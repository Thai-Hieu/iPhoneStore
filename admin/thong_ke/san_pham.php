<?php
require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once __DIR__ . "/thong_ke_helper.php";
require_once __DIR__ . "/_layout.php";
$range=tkDateRange();
$perPage=10;
$stockAll=tkStockAll($conn,'DESC');
$sumStock=array_sum(array_map(fn($r)=>(int)$r['so_luong'],$stockAll));
$outCount=count(array_filter($stockAll,fn($r)=>(int)$r['so_luong']<=0));
$lowCount=count(array_filter($stockAll,fn($r)=>(int)$r['so_luong']>0&&(int)$r['so_luong']<=3));
$top=tkTopProducts($conn,$range['from_sql'],$range['to_sql'],10);
$variants=tkTopVariants($conn,$range['from_sql'],$range['to_sql'],10);
$stockPage=tkPageNumber('stock_page');
$stockPager=tkPager(count($stockAll),$stockPage,$perPage);
$stockRows=tkTopStock($conn,$perPage,$stockPager['offset']);
$ratingPage=tkPageNumber('rating_page');
$ratingPager=tkPager(tkProductRatingsCount($conn),$ratingPage,$perPage);
$ratings=tkProductRatings($conn,$perPage,$ratingPager['offset']);
include "../../includes/header.php";
include "../../includes/navbar.php";
tkRenderStyles();
?>
<div class="tk-shell">
    <div class="tk-hero">
        <h1 class="tk-title">📱 Thống kê sản phẩm</h1>
        <div class="tk-sub">Phân tích sản phẩm bán chạy, tồn kho theo từng biến thể và chất lượng đánh giá từ khách hàng.</div>
    </div>
    <?php tkRenderNav('products',$range); tkRenderFilter($range); ?>

    <div class="row g-3 mb-3">
        <div class="col-12 col-sm-4"><div class="tk-card"><div class="tk-card-body"><div class="tk-kpi-label">Tổng tồn kho biến thể</div><div class="tk-kpi-value"><?=$sumStock?></div><div class="tk-kpi-note">Cộng tồn của các biến thể hiện có</div></div></div></div>
        <div class="col-6 col-sm-4"><div class="tk-card"><div class="tk-card-body"><div class="tk-kpi-label">Sắp hết hàng</div><div class="tk-kpi-value"><?=$lowCount?></div><div class="tk-kpi-note">Biến thể còn 1–3 sản phẩm</div></div></div></div>
        <div class="col-6 col-sm-4"><div class="tk-card"><div class="tk-card-body"><div class="tk-kpi-label">Hết hàng</div><div class="tk-kpi-value"><?=$outCount?></div><div class="tk-kpi-note">Biến thể tồn bằng 0</div></div></div></div>
    </div>

    <div class="tk-section">
        <div class="tk-section-head">
            <div><h2 class="tk-section-title">🔥 Sản phẩm bán chạy nhất</h2><div class="tk-section-desc">Chỉ tính số lượng bán từ các đơn đã hoàn thành trong kỳ đang lọc.</div></div>
            <div class="tk-actions"><a class="btn btn-success tk-export-big" href="<?=h(tkExportUrl('san_pham_ban_chay',$range))?>">📥 Xuất Excel bán chạy</a></div>
        </div>
        <div class="tk-chart"><canvas id="top"></canvas></div>
        <div class="table-responsive mt-3"><table class="table tk-table"><thead><tr><th>#</th><th>Mã sản phẩm</th><th>Tên sản phẩm</th><th class="text-center">Đã bán</th><th class="text-end">Doanh thu</th></tr></thead><tbody><?php foreach($top as $i=>$r): ?><tr><td><span class="tk-rank"><?=$i+1?></span></td><td class="fw-semibold"><?=h($r['ma_san_pham'])?></td><td><?=h($r['ten_san_pham'])?></td><td class="text-center fw-bold"><?=(int)$r['so_luong_ban']?></td><td class="text-end fw-semibold"><?=tkMoney($r['doanh_thu'])?></td></tr><?php endforeach; ?></tbody></table></div>
    </div>

    <div class="tk-section">
        <div class="tk-section-head">
            <div><h2 class="tk-section-title">📦 Sản phẩm / biến thể tồn kho nhiều nhất</h2><div class="tk-section-desc">Sắp xếp tồn kho giảm dần để admin biết hàng nào đang chiếm lượng tồn lớn.</div></div>
            <div class="tk-actions"><a class="btn btn-success tk-export-big" href="<?=h(tkExportUrl('ton_kho_nhieu',$range))?>">📥 Xuất Excel tồn kho</a></div>
        </div>
        <div class="table-responsive"><table class="table tk-table"><thead><tr><th>Xếp hạng</th><th>Mã SP</th><th>Sản phẩm</th><th>Màu</th><th>RAM</th><th>ROM</th><th class="text-end">Giá bán</th><th class="text-center">Tồn kho</th><th>Trạng thái</th></tr></thead><tbody><?php foreach($stockRows as $i=>$r): $rank=$stockPager['offset']+$i+1; ?><tr><td><span class="tk-rank"><?=$rank?></span></td><td><?=h($r['ma_san_pham'])?></td><td class="fw-semibold"><?=h($r['ten_san_pham'])?></td><td><?=h($r['ten_mau']?:'-')?></td><td><?=h($r['ram']?:'-')?></td><td><?=h($r['rom']?:'-')?></td><td class="text-end"><?=tkMoney($r['gia'])?></td><td class="text-center fw-bold"><?=(int)$r['so_luong']?></td><td><span class="tk-badge <?=(int)$r['so_luong']<=0?'tk-danger':((int)$r['so_luong']<=3?'tk-warn':'tk-ok')?>"><?=h($r['trang_thai_ton'])?></span></td></tr><?php endforeach; ?></tbody></table></div>
        <?php tkRenderPagination($stockPager,$range,'stock_page'); ?>
    </div>

    <div class="tk-section">
        <div class="tk-section-head">
            <div><h2 class="tk-section-title">⭐ Sản phẩm được đánh giá cao</h2><div class="tk-section-desc">Xếp theo số sao trung bình, sau đó ưu tiên sản phẩm có nhiều lượt đánh giá hơn.</div></div>
            <div class="tk-actions"><a class="btn btn-success tk-export-big" href="<?=h(tkExportUrl('danh_gia_san_pham',$range))?>">📥 Xuất Excel đánh giá</a></div>
        </div>
        <div class="table-responsive"><table class="table tk-table"><thead><tr><th>Xếp hạng</th><th>Mã SP</th><th>Sản phẩm</th><th class="text-center">Sao TB</th><th class="text-center">Lượt đánh giá</th><th class="text-center">5 sao</th><th class="text-center">4 sao</th><th class="text-center">≤ 3 sao</th></tr></thead><tbody><?php if(!$ratings): ?><tr><td colspan="8" class="tk-empty">Chưa có dữ liệu đánh giá sản phẩm.</td></tr><?php endif; ?><?php foreach($ratings as $i=>$r): $rank=$ratingPager['offset']+$i+1; ?><tr><td><span class="tk-rank"><?=$rank?></span></td><td><?=h($r['ma_san_pham'])?></td><td class="fw-semibold"><?=h($r['ten_san_pham'])?></td><td class="text-center"><span class="tk-stars">★</span> <b><?=number_format((float)$r['sao_trung_binh'],1)?></b>/5</td><td class="text-center"><?=(int)$r['so_luot_danh_gia']?></td><td class="text-center"><?=(int)$r['danh_gia_5_sao']?></td><td class="text-center"><?=(int)$r['danh_gia_4_sao']?></td><td class="text-center"><?=(int)$r['danh_gia_3_sao_tro_xuong']?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php tkRenderPagination($ratingPager,$range,'rating_page'); ?>
    </div>

    <div class="tk-section">
        <div class="tk-section-head"><div><h2 class="tk-section-title">🏆 Biến thể bán chạy</h2><div class="tk-section-desc">Cho biết màu và dung lượng nào bán tốt nhất.</div></div></div>
        <div class="table-responsive"><table class="table tk-table"><thead><tr><th>#</th><th>Sản phẩm</th><th>Màu</th><th>RAM</th><th>ROM</th><th class="text-center">Đã bán</th><th class="text-end">Doanh thu</th></tr></thead><tbody><?php foreach($variants as $i=>$r): ?><tr><td><?=$i+1?></td><td class="fw-semibold"><?=h($r['ten_san_pham'])?></td><td><?=h($r['ten_mau']?:'-')?></td><td><?=h($r['ram']?:'-')?></td><td><?=h($r['rom']?:'-')?></td><td class="text-center"><?=(int)$r['so_luong_ban']?></td><td class="text-end"><?=tkMoney($r['doanh_thu'])?></td></tr><?php endforeach; ?></tbody></table></div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('top'),{type:'bar',data:{labels:<?=json_encode(array_column($top,'ten_san_pham'),JSON_UNESCAPED_UNICODE)?>,datasets:[{label:'Số lượng bán',data:<?=json_encode(array_map('intval',array_column($top,'so_luong_ban')))?>,backgroundColor:'#2457e6',borderRadius:8}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true}}}});
</script>
<?php include "../../includes/footer.php"; ?>

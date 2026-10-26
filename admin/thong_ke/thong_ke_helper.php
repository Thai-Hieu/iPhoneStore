<?php
if (!function_exists('tkDateRange')) {
    function tkDateRange(): array {
        date_default_timezone_set('Asia/Ho_Chi_Minh');
        $preset = trim((string)($_GET['preset'] ?? 'month'));
        $fromRaw = trim((string)($_GET['from'] ?? ''));
        $toRaw = trim((string)($_GET['to'] ?? ''));
        $today = new DateTimeImmutable('today');
        if ($fromRaw !== '' && $toRaw !== '') {
            $from = DateTimeImmutable::createFromFormat('Y-m-d', $fromRaw);
            $to = DateTimeImmutable::createFromFormat('Y-m-d', $toRaw);
            if ($from && $to) {
                if ($from > $to) [$from, $to] = [$to, $from];
                return ['preset'=>'custom','from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'from_sql'=>$from->format('Y-m-d 00:00:00'),'to_sql'=>$to->modify('+1 day')->format('Y-m-d 00:00:00')];
            }
        }
        switch ($preset) {
            case 'today': $from=$today; $to=$today; break;
            case '7days': $from=$today->modify('-6 days'); $to=$today; break;
            case 'year': $from=new DateTimeImmutable($today->format('Y-01-01')); $to=$today; break;
            default: $preset='month'; $from=new DateTimeImmutable($today->format('Y-m-01')); $to=$today;
        }
        return ['preset'=>$preset,'from'=>$from->format('Y-m-d'),'to'=>$to->format('Y-m-d'),'from_sql'=>$from->format('Y-m-d 00:00:00'),'to_sql'=>$to->modify('+1 day')->format('Y-m-d 00:00:00')];
    }
}
function tkCompletedSql(string $a='dh'): string { return "$a.trang_thai IN ('da_giao','Đã giao','hoan_thanh','Hoàn thành')"; }
function tkCancelledSql(string $a='dh'): string { return "$a.trang_thai IN ('da_huy','Đã hủy','huy','Hủy')"; }
function tkPendingSql(string $a='dh'): string { return "$a.trang_thai IN ('cho_xu_ly','da_dat','Đã đặt','cho_xac_nhan','Chờ xác nhận')"; }
function tkApprovedSql(string $a='dh'): string { return "$a.trang_thai IN ('da_duyet','da_xu_ly','dang_xu_ly','Đang xử lý')"; }
function tkShippingSql(string $a='dh'): string { return "$a.trang_thai IN ('dang_giao','Đang giao')"; }
function tkMoney($v): string { return number_format((float)$v,0,',','.') . 'đ'; }
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function tkOrderCode(int $id): string { return 'DH' . str_pad((string)$id, 6, '0', STR_PAD_LEFT); }
function tkTrackingCode($code): string { $code=trim((string)$code); return $code!=='' ? $code : 'Chưa có mã vận đơn'; }
function tkStatusLabel($status): string {
    $s=mb_strtolower(trim((string)$status),'UTF-8');
    $map=[
        'cho_xu_ly'=>'Chờ xử lý','chờ xử lý'=>'Chờ xử lý','da_dat'=>'Đã đặt','đã đặt'=>'Đã đặt',
        'cho_xac_nhan'=>'Chờ xác nhận','chờ xác nhận'=>'Chờ xác nhận','da_duyet'=>'Đã duyệt','đã duyệt'=>'Đã duyệt',
        'da_xu_ly'=>'Đã xử lý','đã xử lý'=>'Đã xử lý','dang_xu_ly'=>'Đang xử lý','đang xử lý'=>'Đang xử lý',
        'dang_giao'=>'Đang giao','đang giao'=>'Đang giao','da_giao'=>'Đã giao','đã giao'=>'Đã giao',
        'hoan_thanh'=>'Hoàn thành','hoàn thành'=>'Hoàn thành','huy'=>'Đã hủy','hủy'=>'Đã hủy','da_huy'=>'Đã hủy','đã hủy'=>'Đã hủy'
    ];
    return $map[$s] ?? ((string)$status !== '' ? (string)$status : 'Chưa xác định');
}
function tkPaymentStatusLabel($status): string {
    $s=mb_strtolower(trim((string)$status),'UTF-8');
    $map=['da_thanh_toan'=>'Đã thanh toán','đã thanh toán'=>'Đã thanh toán','chua_thanh_toan'=>'Chưa thanh toán','chưa thanh toán'=>'Chưa thanh toán','cho_xac_nhan'=>'Chờ xác nhận','chờ xác nhận'=>'Chờ xác nhận','that_bai'=>'Thất bại','thất bại'=>'Thất bại'];
    return $map[$s] ?? ((string)$status !== '' && $status !== '-' ? (string)$status : 'Chưa xác định');
}
function tkFetchOne(mysqli $conn,string $sql,string $types='',array $params=[]): array {
    $stmt=$conn->prepare($sql); if(!$stmt) throw new RuntimeException($conn->error);
    if($types!==''&&$params) $stmt->bind_param($types,...$params); $stmt->execute(); return $stmt->get_result()->fetch_assoc() ?: [];
}
function tkFetchAll(mysqli $conn,string $sql,string $types='',array $params=[]): array {
    $stmt=$conn->prepare($sql); if(!$stmt) throw new RuntimeException($conn->error);
    if($types!==''&&$params) $stmt->bind_param($types,...$params); $stmt->execute(); return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
function tkQuery(array $range,array $extra=[]): string { return http_build_query(array_merge(['preset'=>$range['preset'],'from'=>$range['from'],'to'=>$range['to']],$extra)); }
function tkPageUrl(string $page,array $range,array $extra=[]): string { return $page.'?'.tkQuery($range,$extra); }
function tkExportUrl(string $type,array $range): string { return 'export_excel.php?'.tkQuery($range,['type'=>$type]); }
function tkPageNumber(string $name='page'): int { return max(1,(int)($_GET[$name]??1)); }
function tkPager(int $total,int $page,int $perPage): array { $pages=max(1,(int)ceil($total/$perPage)); $page=min($page,$pages); return ['page'=>$page,'pages'=>$pages,'per_page'=>$perPage,'offset'=>($page-1)*$perPage,'total'=>$total]; }
function tkOverview(mysqli $conn,string $from,string $to): array {
    $completed=tkCompletedSql(); $cancelled=tkCancelledSql(); $pending=tkPendingSql(); $approved=tkApprovedSql(); $shipping=tkShippingSql();
    $o=tkFetchOne($conn,"SELECT COUNT(*) tong_don,COALESCE(SUM(CASE WHEN $completed THEN dh.tong_tien ELSE 0 END),0) doanh_thu,COALESCE(SUM(CASE WHEN $completed THEN 1 ELSE 0 END),0) don_hoan_thanh,COALESCE(SUM(CASE WHEN $pending THEN 1 ELSE 0 END),0) don_cho_xu_ly,COALESCE(SUM(CASE WHEN $approved THEN 1 ELSE 0 END),0) don_da_duyet,COALESCE(SUM(CASE WHEN $shipping THEN 1 ELSE 0 END),0) don_dang_giao,COALESCE(SUM(CASE WHEN $cancelled THEN 1 ELSE 0 END),0) don_da_huy FROM don_hang dh WHERE dh.ngay_dat>=? AND dh.ngay_dat<?",'ss',[$from,$to]);
    $p=tkFetchOne($conn,"SELECT COALESCE(SUM(ct.so_luong),0) san_pham_da_ban FROM chi_tiet_don_hang ct JOIN don_hang dh ON dh.id=ct.don_hang_id WHERE dh.ngay_dat>=? AND dh.ngay_dat<? AND ".tkCompletedSql(),'ss',[$from,$to]);
    $c=tkFetchOne($conn,"SELECT COALESCE(SUM(CASE WHEN vai_tro_id=2 THEN 1 ELSE 0 END),0) tong_khach_hang,COALESCE(SUM(CASE WHEN vai_tro_id=2 AND ngay_tao>=? AND ngay_tao<? THEN 1 ELSE 0 END),0) khach_moi FROM nguoi_dung",'ss',[$from,$to]);
    $done=(int)($o['don_hoan_thanh']??0); $revenue=(float)($o['doanh_thu']??0); $total=(int)($o['tong_don']??0); $cancel=(int)($o['don_da_huy']??0);
    return array_merge($o,$p,$c,['gia_tri_don_trung_binh'=>$done?$revenue/$done:0,'ty_le_hoan_thanh'=>$total?$done/$total*100:0,'ty_le_huy'=>$total?$cancel/$total*100:0]);
}
function tkRevenueTrend(mysqli $conn,string $from,string $to,string $preset): array {
    $expr=$preset==='year'?"DATE_FORMAT(dh.ngay_dat,'%Y-%m')":"DATE(dh.ngay_dat)";
    return tkFetchAll($conn,"SELECT $expr moc,COUNT(*) so_don,COALESCE(SUM(dh.tong_tien),0) doanh_thu FROM don_hang dh WHERE dh.ngay_dat>=? AND dh.ngay_dat<? AND ".tkCompletedSql()." GROUP BY $expr ORDER BY $expr",'ss',[$from,$to]);
}
function tkOrderStatus(mysqli $conn,string $from,string $to): array {
    $row=tkFetchOne($conn,"SELECT COALESCE(SUM(CASE WHEN ".tkPendingSql()." THEN 1 ELSE 0 END),0) cho_xu_ly,COALESCE(SUM(CASE WHEN ".tkApprovedSql()." THEN 1 ELSE 0 END),0) da_duyet,COALESCE(SUM(CASE WHEN ".tkShippingSql()." THEN 1 ELSE 0 END),0) dang_giao,COALESCE(SUM(CASE WHEN ".tkCompletedSql()." THEN 1 ELSE 0 END),0) hoan_thanh,COALESCE(SUM(CASE WHEN ".tkCancelledSql()." THEN 1 ELSE 0 END),0) da_huy FROM don_hang dh WHERE dh.ngay_dat>=? AND dh.ngay_dat<?",'ss',[$from,$to]);
    return [['label'=>'Chờ xử lý','value'=>(int)$row['cho_xu_ly']],['label'=>'Đã duyệt / xử lý','value'=>(int)$row['da_duyet']],['label'=>'Đang giao','value'=>(int)$row['dang_giao']],['label'=>'Hoàn thành','value'=>(int)$row['hoan_thanh']],['label'=>'Đã hủy','value'=>(int)$row['da_huy']]];
}
function tkTopProducts(mysqli $conn,string $from,string $to,int $limit=10): array {
    $limit=max(1,min(10000,$limit)); return tkFetchAll($conn,"SELECT sp.id,sp.ma_san_pham,sp.ten_san_pham,SUM(ct.so_luong) so_luong_ban,SUM(ct.gia*ct.so_luong) doanh_thu FROM chi_tiet_don_hang ct JOIN don_hang dh ON dh.id=ct.don_hang_id JOIN san_pham sp ON sp.id=ct.san_pham_id WHERE dh.ngay_dat>=? AND dh.ngay_dat<? AND ".tkCompletedSql()." GROUP BY sp.id,sp.ma_san_pham,sp.ten_san_pham ORDER BY so_luong_ban DESC,doanh_thu DESC LIMIT $limit",'ss',[$from,$to]);
}
function tkTopVariants(mysqli $conn,string $from,string $to,int $limit=12): array {
    $limit=max(1,min(10000,$limit)); return tkFetchAll($conn,"SELECT bt.id bien_the_id,sp.ma_san_pham,sp.ten_san_pham,ms.ten_mau,bt.ram,bt.rom,SUM(ct.so_luong) so_luong_ban,SUM(ct.gia*ct.so_luong) doanh_thu FROM chi_tiet_don_hang ct JOIN don_hang dh ON dh.id=ct.don_hang_id JOIN san_pham sp ON sp.id=ct.san_pham_id LEFT JOIN san_pham_bien_the bt ON bt.id=ct.bien_the_id LEFT JOIN mau_sac ms ON ms.id=ct.mau_id WHERE dh.ngay_dat>=? AND dh.ngay_dat<? AND ".tkCompletedSql()." AND ct.bien_the_id IS NOT NULL GROUP BY bt.id,sp.ma_san_pham,sp.ten_san_pham,ms.ten_mau,bt.ram,bt.rom ORDER BY so_luong_ban DESC,doanh_thu DESC LIMIT $limit",'ss',[$from,$to]);
}
function tkStockAll(mysqli $conn,string $direction='ASC'): array {
    $dir=strtoupper($direction)==='DESC'?'DESC':'ASC';
    return tkFetchAll($conn,"SELECT bt.id bien_the_id,sp.ma_san_pham,sp.ten_san_pham,ms.ten_mau,bt.ram,bt.rom,bt.gia,bt.so_luong,CASE WHEN bt.so_luong<=0 THEN 'Hết hàng' WHEN bt.so_luong<=3 THEN 'Sắp hết' ELSE 'Còn hàng' END trang_thai_ton FROM san_pham_bien_the bt JOIN san_pham sp ON sp.id=bt.san_pham_id LEFT JOIN mau_sac ms ON ms.id=bt.mau_id ORDER BY bt.so_luong $dir,sp.ten_san_pham,bt.id");
}
function tkStockWarnings(mysqli $conn,int $limit=12): array { $a=tkStockAll($conn,'ASC'); return array_slice(array_values(array_filter($a,fn($r)=>(int)$r['so_luong']<=3)),0,$limit); }
function tkTopCustomers(mysqli $conn,string $from,string $to,int $limit=10): array {
    $limit=max(1,min(10000,$limit)); return tkFetchAll($conn,"SELECT nd.id,nd.ho_ten,nd.email,nd.so_dien_thoai,nd.ngay_tao,COUNT(dh.id) so_don_hoan_thanh,COALESCE(SUM(dh.tong_tien),0) tong_chi_tieu,COALESCE(SUM((SELECT SUM(ct.so_luong) FROM chi_tiet_don_hang ct WHERE ct.don_hang_id=dh.id)),0) san_pham_da_mua FROM nguoi_dung nd JOIN don_hang dh ON dh.nguoi_dung_id=nd.id WHERE dh.ngay_dat>=? AND dh.ngay_dat<? AND ".tkCompletedSql()." GROUP BY nd.id,nd.ho_ten,nd.email,nd.so_dien_thoai,nd.ngay_tao ORDER BY tong_chi_tieu DESC,so_don_hoan_thanh DESC LIMIT $limit",'ss',[$from,$to]);
}
function tkPaymentMethods(mysqli $conn,string $from,string $to): array { return tkFetchAll($conn,"SELECT COALESCE(NULLIF(TRIM(tt.phuong_thuc),''),'Chưa xác định') phuong_thuc,COUNT(*) so_don,COALESCE(SUM(dh.tong_tien),0) doanh_thu FROM don_hang dh LEFT JOIN thanh_toan tt ON tt.id=(SELECT tt2.id FROM thanh_toan tt2 WHERE tt2.don_hang_id=dh.id ORDER BY tt2.id DESC LIMIT 1) WHERE dh.ngay_dat>=? AND dh.ngay_dat<? AND ".tkCompletedSql()." GROUP BY COALESCE(NULLIF(TRIM(tt.phuong_thuc),''),'Chưa xác định') ORDER BY so_don DESC",'ss',[$from,$to]); }
function tkRevenueDetailCount(mysqli $conn,string $from,string $to): int {
    $r=tkFetchOne($conn,"SELECT COUNT(*) total FROM chi_tiet_don_hang ct JOIN don_hang dh ON dh.id=ct.don_hang_id WHERE dh.ngay_dat>=? AND dh.ngay_dat<? AND ".tkCompletedSql('dh'),'ss',[$from,$to]);
    return (int)($r['total']??0);
}
function tkRevenueDetails(mysqli $conn,string $from,string $to,int $limit,int $offset): array {
    $limit=max(1,min(1000000,$limit)); $offset=max(0,$offset);
    return tkFetchAll($conn,"SELECT ct.id chi_tiet_id,dh.id don_hang_id,dh.ma_van_don,dh.ngay_dat,dh.tong_tien tong_tien_don,sp.ma_san_pham,sp.ten_san_pham,COALESCE(ms.ten_mau,'') ten_mau,COALESCE(bt.ram,'') ram,COALESCE(bt.rom,'') rom,ct.so_luong,ct.gia gia_ban,(ct.gia*ct.so_luong) thanh_tien,COALESCE(nd.ho_ten,dh.ten_nguoi_nhan,'Khách hàng') khach_hang,COALESCE(tt.phuong_thuc,'Chưa xác định') phuong_thuc,COALESCE(tt.trang_thai,'Chưa xác định') trang_thai_thanh_toan FROM chi_tiet_don_hang ct JOIN don_hang dh ON dh.id=ct.don_hang_id JOIN san_pham sp ON sp.id=ct.san_pham_id LEFT JOIN san_pham_bien_the bt ON bt.id=ct.bien_the_id LEFT JOIN mau_sac ms ON ms.id=ct.mau_id LEFT JOIN nguoi_dung nd ON nd.id=dh.nguoi_dung_id LEFT JOIN thanh_toan tt ON tt.id=(SELECT tt2.id FROM thanh_toan tt2 WHERE tt2.don_hang_id=dh.id ORDER BY tt2.id DESC LIMIT 1) WHERE dh.ngay_dat>=? AND dh.ngay_dat<? AND ".tkCompletedSql('dh')." ORDER BY thanh_tien DESC,dh.ngay_dat DESC,dh.id DESC,ct.id DESC LIMIT $limit OFFSET $offset",'ss',[$from,$to]);
}
function tkVariantLabel(array $row): string {
    $parts=[];
    foreach(['ten_mau','ram','rom'] as $key){$v=trim((string)($row[$key]??''));if($v!==''&&$v!=='-')$parts[]=$v;}
    return $parts ? implode(' • ',$parts) : 'Mặc định';
}
function tkOrdersCount(mysqli $conn,string $from,string $to): int { $r=tkFetchOne($conn,"SELECT COUNT(*) total FROM don_hang dh WHERE dh.ngay_dat>=? AND dh.ngay_dat<?",'ss',[$from,$to]); return (int)($r['total']??0); }
function tkOrdersHighValue(mysqli $conn,string $from,string $to,int $limit,int $offset): array {
    $limit=max(1,min(10000,$limit)); $offset=max(0,$offset);
    return tkFetchAll($conn,"SELECT dh.id,dh.ma_van_don,dh.ngay_dat,COALESCE(nd.ho_ten,dh.ten_nguoi_nhan,'Khách hàng') khach_hang,dh.tong_tien,dh.trang_thai,COALESCE(tt.phuong_thuc,'Chưa xác định') phuong_thuc,COALESCE(tt.trang_thai,'Chưa xác định') trang_thai_thanh_toan FROM don_hang dh LEFT JOIN nguoi_dung nd ON nd.id=dh.nguoi_dung_id LEFT JOIN thanh_toan tt ON tt.id=(SELECT tt2.id FROM thanh_toan tt2 WHERE tt2.don_hang_id=dh.id ORDER BY tt2.id DESC LIMIT 1) WHERE dh.ngay_dat>=? AND dh.ngay_dat<? ORDER BY dh.tong_tien DESC,dh.ngay_dat DESC,dh.id DESC LIMIT $limit OFFSET $offset",'ss',[$from,$to]);
}
function tkTopStock(mysqli $conn,int $limit,int $offset): array { $all=tkStockAll($conn,'DESC'); return array_slice($all,$offset,$limit); }
function tkProductRatingsCount(mysqli $conn): int { $r=tkFetchOne($conn,"SELECT COUNT(DISTINCT san_pham_id) total FROM danh_gia"); return (int)($r['total']??0); }
function tkProductRatings(mysqli $conn,int $limit,int $offset): array {
    $limit=max(1,min(10000,$limit)); $offset=max(0,$offset);
    return tkFetchAll($conn,"SELECT sp.id,sp.ma_san_pham,sp.ten_san_pham,COUNT(dg.id) so_luot_danh_gia,ROUND(AVG(dg.so_sao),1) sao_trung_binh,SUM(CASE WHEN dg.so_sao=5 THEN 1 ELSE 0 END) danh_gia_5_sao,SUM(CASE WHEN dg.so_sao=4 THEN 1 ELSE 0 END) danh_gia_4_sao,SUM(CASE WHEN dg.so_sao<=3 THEN 1 ELSE 0 END) danh_gia_3_sao_tro_xuong FROM san_pham sp JOIN danh_gia dg ON dg.san_pham_id=sp.id GROUP BY sp.id,sp.ma_san_pham,sp.ten_san_pham ORDER BY sao_trung_binh DESC,so_luot_danh_gia DESC,sp.ten_san_pham LIMIT $limit OFFSET $offset");
}
function tkNewCustomersTrend(mysqli $conn,string $from,string $to,string $preset): array { $expr=$preset==='year'?"DATE_FORMAT(ngay_tao,'%Y-%m')":"DATE(ngay_tao)"; return tkFetchAll($conn,"SELECT $expr moc,COUNT(*) so_khach FROM nguoi_dung WHERE vai_tro_id=2 AND ngay_tao>=? AND ngay_tao<? GROUP BY $expr ORDER BY $expr",'ss',[$from,$to]); }
?>

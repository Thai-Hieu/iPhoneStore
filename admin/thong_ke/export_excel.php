<?php
require_once "../../middleware/check_admin.php";
require_once "../../config/database.php";
require_once __DIR__ . "/thong_ke_helper.php";
$autoload=__DIR__ . '/../../vendor/autoload.php';
if(!is_file($autoload)){http_response_code(500);die('Chưa cài PhpSpreadsheet. Chạy: composer require phpoffice/phpspreadsheet tại D:\\laragon\\iPhoneStore');}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$type=trim((string)($_GET['type']??'all'));
$allowed=['all','tong_quan','doanh_thu','don_hang','san_pham','san_pham_ban_chay','ton_kho','ton_kho_nhieu','danh_gia_san_pham','khach_hang'];
if(!in_array($type,$allowed,true))$type='all';
$range=tkDateRange();
$periodText=date('d/m/Y',strtotime($range['from'])).' - '.date('d/m/Y',strtotime($range['to']));
$spreadsheet=new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

$navy='10234B';$blue='2457E6';$lightBlue='EAF0FF';$lightGray='F7F9FC';$green='14804A';$red='C9362B';$amber='B7791F';$white='FFFFFF';$text='1F2A44';

function exCol(int $i): string{return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);}
function excelBase($sheet,int $lastColumn): void{
    $end=exCol($lastColumn);
    $sheet->getStyle("A1:{$end}2000")->getFont()->setName('Calibri')->setSize(11)->getColor()->setARGB('1F2A44');
    $sheet->getSheetView()->setZoomScale(90);
    $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(.35)->setBottom(.35)->setLeft(.3)->setRight(.3);
}
function excelTitle($sheet,string $title,string $periodText,int $lastColumn): void{
    global $navy,$white,$lightBlue,$text;
    $end=exCol($lastColumn); excelBase($sheet,$lastColumn);
    $sheet->mergeCells("A1:{$end}1");$sheet->setCellValue('A1','IPHONESTORE');
    $sheet->mergeCells("A2:{$end}2");$sheet->setCellValue('A2',mb_strtoupper($title,'UTF-8'));
    $sheet->mergeCells("A3:{$end}3");$sheet->setCellValue('A3','Kỳ báo cáo: '.$periodText.'  •  Xuất lúc: '.date('d/m/Y H:i'));
    $sheet->getStyle("A1:{$end}2")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($navy);
    $sheet->getStyle("A1:{$end}2")->getFont()->setBold(true)->getColor()->setARGB($white);
    $sheet->getStyle('A1')->getFont()->setSize(16);$sheet->getStyle('A2')->getFont()->setSize(20);
    $sheet->getStyle("A1:{$end}3")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("A3:{$end}3")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($lightBlue);
    $sheet->getStyle("A3:{$end}3")->getFont()->setItalic(true)->setSize(10)->getColor()->setARGB($text);
    $sheet->getRowDimension(1)->setRowHeight(25);$sheet->getRowDimension(2)->setRowHeight(34);$sheet->getRowDimension(3)->setRowHeight(22);
}
function excelHeader($sheet,int $row,array $headers): void{
    global $blue,$white;
    foreach($headers as $i=>$header)$sheet->setCellValue(exCol($i+1).$row,$header);
    $end=exCol(count($headers));$rg="A{$row}:{$end}{$row}";
    $sheet->getStyle($rg)->getFont()->setBold(true)->setSize(11)->getColor()->setARGB($white);
    $sheet->getStyle($rg)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($blue);
    $sheet->getStyle($rg)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    $sheet->getRowDimension($row)->setRowHeight(30);
}
function excelFinish($sheet,int $headerRow,int $lastRow,int $lastColumn,array $widths=[]): void{
    global $lightGray;
    $end=exCol($lastColumn);$lastRow=max($lastRow,$headerRow);
    $rg="A{$headerRow}:{$end}{$lastRow}";
    $sheet->getStyle($rg)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('D8DEE9');
    $sheet->getStyle($rg)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    for($r=$headerRow+1;$r<=$lastRow;$r++){
        $sheet->getRowDimension($r)->setRowHeight(24);
        if(($r-$headerRow)%2===0)$sheet->getStyle("A{$r}:{$end}{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($lightGray);
    }
    if($lastRow>$headerRow)$sheet->setAutoFilter("A{$headerRow}:{$end}{$lastRow}");
    $sheet->freezePane('A'.($headerRow+1));
    foreach(range(1,$lastColumn) as $i){$col=exCol($i);if(isset($widths[$i]))$sheet->getColumnDimension($col)->setWidth($widths[$i]);else $sheet->getColumnDimension($col)->setAutoSize(true);}
}
function excelMoney($sheet,string $col,int $from,int $to): void{if($to>=$from)$sheet->getStyle("{$col}{$from}:{$col}{$to}")->getNumberFormat()->setFormatCode('#,##0" đ"');}
function excelRank($sheet,int $from,int $to): void{if($to>=$from)$sheet->getStyle("A{$from}:A{$to}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);}
function excelEmpty($sheet,int $row,int $lastColumn,string $text='Không có dữ liệu'): int{$end=exCol($lastColumn);$sheet->mergeCells("A{$row}:{$end}{$row}");$sheet->setCellValue("A{$row}",$text);$sheet->getStyle("A{$row}:{$end}{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);return $row;}

function addOverviewSheet(Spreadsheet $ss,mysqli $conn,array $range,string $period): void{
    $d=tkOverview($conn,$range['from_sql'],$range['to_sql']);$sh=$ss->createSheet();$sh->setTitle('01_Tong_quan');excelTitle($sh,'Tổng quan kinh doanh',$period,3);excelHeader($sh,5,['STT','Chỉ số','Giá trị']);
    $items=[['Tổng doanh thu',(float)$d['doanh_thu'],'money'],['Đơn hoàn thành',(int)$d['don_hoan_thanh'],'int'],['Đơn chờ xử lý',(int)$d['don_cho_xu_ly'],'int'],['Đơn đã duyệt / xử lý',(int)$d['don_da_duyet'],'int'],['Đơn đang giao',(int)$d['don_dang_giao'],'int'],['Đơn đã hủy',(int)$d['don_da_huy'],'int'],['Sản phẩm đã bán',(int)$d['san_pham_da_ban'],'int'],['Giá trị đơn trung bình',(float)$d['gia_tri_don_trung_binh'],'money'],['Tỷ lệ hoàn thành',(float)$d['ty_le_hoan_thanh']/100,'percent'],['Tỷ lệ hủy',(float)$d['ty_le_huy']/100,'percent'],['Khách mới trong kỳ',(int)$d['khach_moi'],'int']];
    $r=6;foreach($items as $i=>$it){$sh->fromArray([$i+1,$it[0],$it[1]],null,"A{$r}");if($it[2]==='money')$sh->getStyle("C{$r}")->getNumberFormat()->setFormatCode('#,##0" đ"');if($it[2]==='percent')$sh->getStyle("C{$r}")->getNumberFormat()->setFormatCode('0.0%');$r++;}excelFinish($sh,5,$r-1,3,[1=>8,2=>34,3=>24]);excelRank($sh,6,$r-1);
}
function addRevenueSheet(Spreadsheet $ss,mysqli $conn,array $range,string $period): void{
    $data=tkRevenueDetails($conn,$range['from_sql'],$range['to_sql'],1000000,0);
    $overview=tkOverview($conn,$range['from_sql'],$range['to_sql']);
    $sh=$ss->createSheet();
    $sh->setTitle('02_Doanh_thu');
    excelTitle($sh,'CHI TIẾT DOANH THU THEO SẢN PHẨM ĐÃ BÁN',$period,14);
    $sh->mergeCells('A5:N5');
    $sh->setCellValue('A5','Tổng doanh thu đơn hoàn thành: '.number_format((float)$overview['doanh_thu'],0,',','.').' đ   |   Đơn hoàn thành: '.(int)$overview['don_hoan_thanh'].'   |   Sản phẩm đã bán: '.(int)$overview['san_pham_da_ban']);
    $sh->getStyle('A5:N5')->getFont()->setBold(true)->setSize(12);
    $sh->getStyle('A5:N5')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
    $sh->getRowDimension(5)->setRowHeight(28);
    excelHeader($sh,7,['Xếp hạng','Mã sản phẩm','Tên sản phẩm','Phiên bản','Mã đơn','Mã vận đơn','Số lượng','Đơn giá','Thành tiền','Thời gian','Khách hàng','Phương thức TT','Trạng thái TT','Tổng giá trị đơn']);
    $r=8;$lineTotal=0;
    foreach($data as $i=>$x){
        $sh->fromArray([
            $i+1,
            $x['ma_san_pham'],
            $x['ten_san_pham'],
            tkVariantLabel($x),
            tkOrderCode((int)$x['don_hang_id']),
            tkTrackingCode($x['ma_van_don']),
            (int)$x['so_luong'],
            (float)$x['gia_ban'],
            (float)$x['thanh_tien'],
            date('d/m/Y H:i',strtotime($x['ngay_dat'])),
            $x['khach_hang'],
            $x['phuong_thuc'],
            tkPaymentStatusLabel($x['trang_thai_thanh_toan']),
            (float)$x['tong_tien_don']
        ],null,"A{$r}");
        $lineTotal+=(float)$x['thanh_tien'];
        $r++;
    }
    if(!$data){excelEmpty($sh,$r,14,'Chưa có giao dịch doanh thu trong khoảng thời gian này');$r++;}
    $sh->mergeCells("A{$r}:H{$r}");
    $sh->setCellValue("A{$r}",'TỔNG THÀNH TIỀN SẢN PHẨM');
    $sh->setCellValue("I{$r}",$lineTotal);
    $sh->getStyle("A{$r}:I{$r}")->getFont()->setBold(true)->setSize(12);
    $sh->getStyle("A{$r}:I{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('EAF2FF');
    $sh->getStyle("A{$r}:H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    excelFinish($sh,7,$r,14,[1=>11,2=>19,3=>34,4=>25,5=>15,6=>21,7=>12,8=>20,9=>22,10=>20,11=>25,12=>20,13=>20,14=>22]);
    excelMoney($sh,'H',8,$r-1);excelMoney($sh,'I',8,$r);excelMoney($sh,'N',8,$r-1);excelRank($sh,8,$r-1);
}
function addOrdersSheet(Spreadsheet $ss,mysqli $conn,array $range,string $period): void{
    $data=tkOrdersHighValue($conn,$range['from_sql'],$range['to_sql'],1000000,0);$sh=$ss->createSheet();$sh->setTitle('03_Don_hang');excelTitle($sh,'Đơn hàng theo giá trị giảm dần',$period,10);excelHeader($sh,5,['Xếp hạng','Mã đơn','Mã vận đơn','Ngày đặt','Khách hàng','Phương thức TT','Trạng thái đơn','Trạng thái TT','Tổng tiền','Ghi chú']);$r=6;
    foreach($data as $i=>$x){$sh->fromArray([$i+1,tkOrderCode((int)$x['id']),tkTrackingCode($x['ma_van_don']),date('d/m/Y H:i',strtotime($x['ngay_dat'])),$x['khach_hang'],$x['phuong_thuc'],tkStatusLabel($x['trang_thai']),tkPaymentStatusLabel($x['trang_thai_thanh_toan']),(float)$x['tong_tien'],trim((string)$x['ma_van_don'])===''?'Chưa được cấp mã vận đơn':'Đã có mã vận đơn'],null,"A{$r}");$r++;}if(!$data){excelEmpty($sh,$r,10);$r++;}excelFinish($sh,5,$r-1,10,[1=>10,2=>15,3=>21,4=>20,5=>24,6=>18,7=>18,8=>18,9=>22,10=>24]);excelMoney($sh,'I',6,$r-1);excelRank($sh,6,$r-1);
}
function addBestSellingSheet(Spreadsheet $ss,mysqli $conn,array $range,string $period): void{
    $data=tkTopProducts($conn,$range['from_sql'],$range['to_sql'],100);$sh=$ss->createSheet();$sh->setTitle('04_Ban_chay');excelTitle($sh,'Sản phẩm bán chạy nhất',$period,5);excelHeader($sh,5,['Xếp hạng','Mã sản phẩm','Tên sản phẩm','Số lượng đã bán','Doanh thu']);$r=6;
    foreach($data as $i=>$x){$sh->fromArray([$i+1,$x['ma_san_pham'],$x['ten_san_pham'],(int)$x['so_luong_ban'],(float)$x['doanh_thu']],null,"A{$r}");$r++;}if(!$data){excelEmpty($sh,$r,5);$r++;}excelFinish($sh,5,$r-1,5,[1=>10,2=>18,3=>34,4=>18,5=>24]);excelMoney($sh,'E',6,$r-1);excelRank($sh,6,$r-1);
}
function addStockSheet(Spreadsheet $ss,mysqli $conn,string $period): void{
    $data=tkStockAll($conn,'DESC');$sh=$ss->createSheet();$sh->setTitle('05_Ton_kho');excelTitle($sh,'Tồn kho nhiều nhất theo từng biến thể',$period,9);excelHeader($sh,5,['Xếp hạng','Mã SP','Tên sản phẩm','Màu','RAM','ROM','Giá bán','Tồn kho','Trạng thái']);$r=6;
    foreach($data as $i=>$x){$sh->fromArray([$i+1,$x['ma_san_pham'],$x['ten_san_pham'],$x['ten_mau']?:'-',$x['ram']?:'-',$x['rom']?:'-',(float)$x['gia'],(int)$x['so_luong'],$x['trang_thai_ton']],null,"A{$r}");if((int)$x['so_luong']<=0)$sh->getStyle("I{$r}")->getFont()->setBold(true)->getColor()->setARGB('C9362B');elseif((int)$x['so_luong']<=3)$sh->getStyle("I{$r}")->getFont()->setBold(true)->getColor()->setARGB('B7791F');$r++;}if(!$data){excelEmpty($sh,$r,9);$r++;}excelFinish($sh,5,$r-1,9,[1=>10,2=>18,3=>32,4=>18,5=>12,6=>14,7=>22,8=>12,9=>16]);excelMoney($sh,'G',6,$r-1);excelRank($sh,6,$r-1);
}
function addRatingsSheet(Spreadsheet $ss,mysqli $conn,string $period): void{
    $data=tkProductRatings($conn,1000000,0);$sh=$ss->createSheet();$sh->setTitle('06_Danh_gia');excelTitle($sh,'Đánh giá sản phẩm theo số sao',$period,8);excelHeader($sh,5,['Xếp hạng','Mã SP','Tên sản phẩm','Sao trung bình','Lượt đánh giá','5 sao','4 sao','≤ 3 sao']);$r=6;
    foreach($data as $i=>$x){$sh->fromArray([$i+1,$x['ma_san_pham'],$x['ten_san_pham'],(float)$x['sao_trung_binh'],(int)$x['so_luot_danh_gia'],(int)$x['danh_gia_5_sao'],(int)$x['danh_gia_4_sao'],(int)$x['danh_gia_3_sao_tro_xuong']],null,"A{$r}");$sh->getStyle("D{$r}")->getNumberFormat()->setFormatCode('0.0"/5"');$r++;}if(!$data){excelEmpty($sh,$r,8,'Chưa có dữ liệu đánh giá sản phẩm');$r++;}excelFinish($sh,5,$r-1,8,[1=>10,2=>18,3=>34,4=>16,5=>18,6=>12,7=>12,8=>12]);excelRank($sh,6,$r-1);
}
function addCustomersSheet(Spreadsheet $ss,mysqli $conn,array $range,string $period): void{
    $data=tkFetchAll($conn,"SELECT nd.id,nd.ho_ten,nd.email,nd.so_dien_thoai,nd.ngay_tao,COALESCE(SUM(CASE WHEN ".tkCompletedSql('dh')." THEN 1 ELSE 0 END),0) so_don_hoan_thanh,COALESCE(SUM(CASE WHEN ".tkCompletedSql('dh')." THEN dh.tong_tien ELSE 0 END),0) tong_chi_tieu FROM nguoi_dung nd LEFT JOIN don_hang dh ON dh.nguoi_dung_id=nd.id AND dh.ngay_dat>=? AND dh.ngay_dat<? WHERE nd.vai_tro_id=2 GROUP BY nd.id,nd.ho_ten,nd.email,nd.so_dien_thoai,nd.ngay_tao ORDER BY tong_chi_tieu DESC,so_don_hoan_thanh DESC,nd.ngay_tao DESC",'ss',[$range['from_sql'],$range['to_sql']]);
    $sh=$ss->createSheet();$sh->setTitle('07_Khach_hang');excelTitle($sh,'Thống kê khách hàng',$period,8);excelHeader($sh,5,['Xếp hạng','Khách hàng','Email','Số điện thoại','Ngày tạo','Đơn hoàn thành','Tổng chi tiêu','Khách mới trong kỳ']);$r=6;
    foreach($data as $i=>$x){$isNew=$x['ngay_tao']>=$range['from_sql']&&$x['ngay_tao']<$range['to_sql'];$sh->fromArray([$i+1,$x['ho_ten']?:'Khách hàng #'.$x['id'],$x['email']?:'-',$x['so_dien_thoai']?:'-',date('d/m/Y',strtotime($x['ngay_tao'])),(int)$x['so_don_hoan_thanh'],(float)$x['tong_chi_tieu'],$isNew?'Có':'Không'],null,"A{$r}");$r++;}if(!$data){excelEmpty($sh,$r,8);$r++;}excelFinish($sh,5,$r-1,8,[1=>10,2=>26,3=>30,4=>18,5=>16,6=>18,7=>24,8=>20]);excelMoney($sh,'G',6,$r-1);excelRank($sh,6,$r-1);
}

switch($type){
    case 'tong_quan':addOverviewSheet($spreadsheet,$conn,$range,$periodText);break;
    case 'doanh_thu':addRevenueSheet($spreadsheet,$conn,$range,$periodText);break;
    case 'don_hang':addOrdersSheet($spreadsheet,$conn,$range,$periodText);break;
    case 'san_pham':case 'san_pham_ban_chay':addBestSellingSheet($spreadsheet,$conn,$range,$periodText);break;
    case 'ton_kho':case 'ton_kho_nhieu':addStockSheet($spreadsheet,$conn,$periodText);break;
    case 'danh_gia_san_pham':addRatingsSheet($spreadsheet,$conn,$periodText);break;
    case 'khach_hang':addCustomersSheet($spreadsheet,$conn,$range,$periodText);break;
    default:addOverviewSheet($spreadsheet,$conn,$range,$periodText);addRevenueSheet($spreadsheet,$conn,$range,$periodText);addOrdersSheet($spreadsheet,$conn,$range,$periodText);addBestSellingSheet($spreadsheet,$conn,$range,$periodText);addStockSheet($spreadsheet,$conn,$periodText);addRatingsSheet($spreadsheet,$conn,$periodText);addCustomersSheet($spreadsheet,$conn,$range,$periodText);break;
}
$spreadsheet->setActiveSheetIndex(0);
$filename='Bao_cao_iPhoneStore_'.$range['from'].'_den_'.$range['to'].'.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: max-age=0');header('Pragma: public');
$writer=new Xlsx($spreadsheet);$writer->save('php://output');exit;

<?php
declare(strict_types=1);

// api/get_alerts.php
include_once(__DIR__ . "/../../ayr.php");
require_once __DIR__ . '/../../kontrol.php';
header('Content-Type: application/json; charset=utf-8');
$alerts = [];

// Örnek 1: Kritik Stok Seviyesi (STLINE tablosundaki bir custom alana veya başka bir mantığa göre uyarlanabilir)
$stmtLowStock = $dbh->prepare("SELECT COUNT(*) FROM LG_001_ITEMS WHERE ACTIVE=0 AND STOCK_LEVEL < MIN_STOCK_LEVEL");
$stmtLowStock->execute();
$low_stock_count = (int)$stmtLowStock->fetchColumn();
if ($low_stock_count > 0) {
	    $alerts[] = ['type' => 'warning', 'text' => "$low_stock_count ürünün stoğu kritik seviyede.", 'link' => 'rapor_hareketsiz_stok.php'];
}

// Örnek 2: Vadesi Geçmiş Faturalar
$stmtPastDue = $dbh->prepare("SELECT COUNT(*) FROM {$firmadonem}CLFLINE WHERE TRCODE = 1 AND DUEDATE < GETDATE() AND STATUS = 0");
$stmtPastDue->execute();
$past_due_count = (int)$stmtPastDue->fetchColumn();
if ($past_due_count > 0) {
	    $alerts[] = ['type' => 'danger', 'text' => "$past_due_count faturanın vadesi geçti.", 'link' => '#'];
}

echo json_encode($alerts, JSON_UNESCAPED_UNICODE);
?>

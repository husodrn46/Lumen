<?php
declare(strict_types=1);

// api/weekly.php
include_once(__DIR__ . "/../../ayr.php");
require_once __DIR__ . '/../../kontrol.php';

// Rapor yetkisi (M17)
if ((int) m_p_yetki($terminalkullanici, 'M17') !== 1) {
    header('Content-Type: application/json; charset=UTF-8', true, 403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

try {
} catch (PDOException) {
    header('Content-Type: application/json; charset=UTF-8', true, 500);
    echo json_encode(['error'=>'DB bağlantı hatası']);
    exit;
}

// Parametreleri al
$year  = isset($_GET['year'])  ? intval($_GET['year'])  : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : 1;

// SQL: ayın gün sayısını 7’ye bölüp yukarı yuvarlayarak hafta no hesaplıyoruz
$sql = "
  SELECT
    CEILING(DAY(DATE_) / 7.0) AS haftano,
    SUM(AMOUNT)               AS toplam
  FROM {$firmadonem}KSLINES
  WHERE
    SIGN     = 0
    AND CARDREF = 2
    AND YEAR(DATE_)  = ?
    AND MONTH(DATE_) = ?
  GROUP BY CEILING(DAY(DATE_) / 7.0)
  ORDER BY haftano
";
$stmt = $dbh->prepare($sql);

// Burada SQL’de iki soru işareti (?) olduğundan, array içinde tam iki değer olmalı
$stmt->execute([$year, $month]);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// JSON olarak döndür
header('Content-Type: application/json; charset=UTF-8');
echo json_encode($rows, JSON_NUMERIC_CHECK);

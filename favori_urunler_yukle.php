<?php
declare(strict_types=1);

// JSON çıktısı için buffer başlat
ob_start();

include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/kontrol.php");

// Buffer'ı temizle (HTML çıktısını engelle)
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// Input validation
if (!isset($_POST['fisid'])) {
    die(json_encode(['success' => false, 'message' => 'Fiş ID eksik']));
}

$fisid = (int)$_POST['fisid'];

if ($fisid <= 0) {
    die(json_encode(['success' => false, 'message' => 'Geçersiz fiş ID']));
}

try {
    // Mevcut fişin müşteri ID'sini al
    $stmt_cari = $dbh->prepare("SELECT CLIENTREF FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF = :fisid");
    $stmt_cari->execute([':fisid' => $fisid]);
    $caridata = $stmt_cari->fetch(PDO::FETCH_ASSOC);

    if (!$caridata || !$caridata['CLIENTREF']) {
        die(json_encode(['success' => false, 'message' => 'Müşteri bulunamadı']));
    }

    $cariid = $caridata['CLIENTREF'];

    // Bu müşterinin en çok sipariş ettiği ürünleri getir (son 6 ay)
    $stmt_favoriler = $dbh->prepare("SELECT TOP 10
        STOCKREF AS stok_id,
        COUNT(*) AS siparis_sayisi,
        AVG(AMOUNT) AS miktar
        FROM " . $firmadonem . "ORFLINE
        WHERE ORDFICHEREF IN (
            SELECT LOGICALREF FROM " . $firmadonem . "ORFICHE
            WHERE CLIENTREF = :cariid
            AND CANCELLED = 0
            AND DATE_ >= DATEADD(MONTH, -6, GETDATE())
        )
        AND STOCKREF > 0
        GROUP BY STOCKREF
        ORDER BY COUNT(*) DESC, AVG(AMOUNT) DESC");
    $stmt_favoriler->execute([':cariid' => $cariid]);
    $urunler = $stmt_favoriler->fetchAll(PDO::FETCH_ASSOC);

    if (empty($urunler)) {
        die(json_encode(['success' => false, 'message' => 'Favori ürün bulunamadı']));
    }

    // Miktarları tam sayıya yuvarla
    foreach ($urunler as &$urun) {
        $urun['miktar'] = ceil($urun['miktar']);
    }

    echo json_encode(['success' => true, 'message' => 'Favori ürünler yüklendi', 'urunler' => $urunler]);

} catch (PDOException $e) {
    error_log("Favori ürünler yükleme hatası: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Veritabanı hatası']));
}

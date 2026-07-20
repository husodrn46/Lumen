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

    // Bu müşterinin son faturasını bul (INVOICE tablosundan - TRCODE 7,8)
    $stmt_son_fatura = $dbh->prepare("SELECT TOP 1 LOGICALREF FROM " . $firmadonem . "INVOICE
        WHERE CLIENTREF = :cariid
        AND CANCELLED = 0
        AND TRCODE IN (7, 8)
        ORDER BY DATE_ DESC, LOGICALREF DESC");
    $stmt_son_fatura->execute([':cariid' => $cariid]);
    $son_fatura = $stmt_son_fatura->fetch(PDO::FETCH_ASSOC);

    // Fatura yoksa sipariş tablosuna bak
    if (!$son_fatura) {
        $stmt_son_siparis = $dbh->prepare("SELECT TOP 1 LOGICALREF FROM " . $firmadonem . "ORFICHE
            WHERE CLIENTREF = :cariid
            AND LOGICALREF != :fisid
            AND CANCELLED = 0
            ORDER BY DATE_ DESC, LOGICALREF DESC");
        $stmt_son_siparis->execute([':cariid' => $cariid, ':fisid' => $fisid]);
        $son_siparis = $stmt_son_siparis->fetch(PDO::FETCH_ASSOC);

        if (!$son_siparis) {
            die(json_encode(['success' => false, 'message' => 'Bu müşterinin önceki faturası veya siparişi bulunamadı']));
        }

        // Sipariş varsa onun satırlarını getir
        $stmt_urunler = $dbh->prepare("SELECT
            STOCKREF AS stok_id,
            AMOUNT AS miktar
            FROM " . $firmadonem . "ORFLINE
            WHERE ORDFICHEREF = :fis_id
            AND STOCKREF > 0
            ORDER BY LOGICALREF ASC");
        $stmt_urunler->execute([':fis_id' => $son_siparis['LOGICALREF']]);
    } else {
        // Fatura varsa onun satırlarını getir (STLINE tablosundan)
        $stmt_urunler = $dbh->prepare("SELECT
            STOCKREF AS stok_id,
            AMOUNT AS miktar
            FROM " . $firmadonem . "STLINE
            WHERE INVOICEREF = :fatura_id
            AND LINETYPE = 0
            AND STOCKREF > 0
            ORDER BY LOGICALREF ASC");
        $stmt_urunler->execute([':fatura_id' => $son_fatura['LOGICALREF']]);
    }

    $urunler = $stmt_urunler->fetchAll(PDO::FETCH_ASSOC);

    // Miktarları tam sayıya yuvarla
    foreach ($urunler as &$urun) {
        $urun['miktar'] = ceil($urun['miktar']);
    }

    if (empty($urunler)) {
        die(json_encode(['success' => false, 'message' => 'Son fatura/siparişte ürün bulunamadı']));
    }

    echo json_encode(['success' => true, 'message' => 'Son sipariş yüklendi', 'urunler' => $urunler]);

} catch (PDOException $e) {
    error_log("Son sipariş yükleme hatası: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Veritabanı hatası', 'error' => $e->getMessage()]));
} catch (Exception $e) {
    error_log("Son sipariş yükleme genel hatası: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Hata oluştu', 'error' => $e->getMessage()]));
}

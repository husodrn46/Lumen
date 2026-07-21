<?php
declare(strict_types=1);

// JSON çıktısı için buffer başlat
ob_start();

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");

// Buffer'ı temizle (HTML çıktısını engelle)
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

// Input validation
if (!isset($_POST['fisid']) || !isset($_POST['stok_id']) || !isset($_POST['miktar'])) {
    die(json_encode(['success' => false, 'message' => 'Eksik parametreler']));
}

// CSRF koruması
if (!csrf_verify()) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => 'Geçersiz güvenlik doğrulaması']));
}

$fisid = (int)$_POST['fisid'];
$stok_id = (int)$_POST['stok_id'];
$miktar = (float)$_POST['miktar'];

if ($fisid <= 0 || $stok_id <= 0 || $miktar <= 0) {
    die(json_encode(['success' => false, 'message' => 'Geçersiz değerler']));
}

try {
    // Fişin varlığını ve müşterisini kontrol et
    $stmt_fis = $dbh->prepare("SELECT CLIENTREF, DATE_, SOURCEINDEX FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF = :fisid");
    $stmt_fis->execute([':fisid' => $fisid]);
    $fis = $stmt_fis->fetch(PDO::FETCH_ASSOC);

    if (!$fis) {
        die(json_encode(['success' => false, 'message' => 'Fiş bulunamadı']));
    }

    $cariid = $fis['CLIENTREF'];
    $tarih = $fis['DATE_'];

    // Stok bilgilerini al
    $stmt_stok = $dbh->prepare("SELECT
        I.CODE,
        I.NAME,
        I.VAT,
        I.UNITSETREF,
        U.CODE AS BIRIM,
        U.MAINUNIT
    FROM " . $firma . "ITEMS I
    LEFT JOIN " . $firma . "UNITSETL U ON I.UNITSETREF = U.UNITSETREF AND U.LINENR = 1
    WHERE I.LOGICALREF = :stokid");
    $stmt_stok->execute([':stokid' => $stok_id]);
    $stok = $stmt_stok->fetch(PDO::FETCH_ASSOC);

    if (!$stok) {
        die(json_encode(['success' => false, 'message' => 'Stok bulunamadı']));
    }

    // Fiyat bilgisini al (müşteriye özel fiyat listesinden)
    $stmt_fiyat = $dbh->prepare("SELECT TOP 1 PRICE
        FROM " . $firma . "PRCLIST
        WHERE CARDREF = :stokid
        AND PTYPE = 2
        AND CLIENTCODE = (SELECT CODE FROM " . $firma . "CLCARD WHERE LOGICALREF = :cariid)
        AND BEGDATE <= :tarih
        AND ENDDATE >= :tarih
        AND ACTIVE = 0
        ORDER BY PRIORITY DESC");
    $stmt_fiyat->execute([':stokid' => $stok_id, ':cariid' => $cariid, ':tarih' => $tarih]);
    $fiyat_data = $stmt_fiyat->fetch(PDO::FETCH_ASSOC);

    // Fiyat bulunamazsa standart fiyat listesinden al
    if (!$fiyat_data) {
        $stmt_fiyat2 = $dbh->prepare("SELECT TOP 1 PRICE
            FROM " . $firma . "PRCLIST
            WHERE CARDREF = :stokid
            AND PTYPE = 1
            AND BEGDATE <= :tarih
            AND ENDDATE >= :tarih
            AND ACTIVE = 0
            ORDER BY PRIORITY DESC");
        $stmt_fiyat2->execute([':stokid' => $stok_id, ':tarih' => $tarih]);
        $fiyat_data = $stmt_fiyat2->fetch(PDO::FETCH_ASSOC);
    }

    $fiyat = $fiyat_data ? (float)$fiyat_data['PRICE'] : 0;

    // Satırı ekle
    $stmt_insert = $dbh->prepare("INSERT INTO " . $firmadonem . "ORFLINE (
        ORDFICHEREF,
        STOCKREF,
        CLIENTREF,
        AMOUNT,
        PRICE,
        TOTAL,
        VAT,
        VATAMNT,
        LINETYPE,
        TRCODE,
        DATE_,
        CANCELLED,
        LINENET,
        DISTCOST,
        DISTDISC,
        UOMFACTOR,
        USREF,
        UINFO1,
        UINFO2,
        SOURCEINDEX
    ) VALUES (
        :fisid,
        :stokid,
        :cariid,
        :miktar,
        :fiyat,
        :miktar * :fiyat,
        :kdv,
        (:miktar * :fiyat * :kdv) / 100,
        0,
        1,
        :tarih,
        0,
        :miktar * :fiyat,
        0,
        0,
        1,
        0,
        '',
        '',
        :sourceindex
    )");

    $result = $stmt_insert->execute([':fisid' => $fisid, ':stokid' => $stok_id, ':cariid' => $cariid, ':miktar' => $miktar, ':fiyat' => $fiyat, ':kdv' => $stok['VAT'], ':tarih' => $tarih, ':sourceindex' => $fis['SOURCEINDEX']]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Ürün başarıyla eklendi', 'stok_adi' => $stok['NAME'], 'miktar' => $miktar, 'fiyat' => $fiyat]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Ekleme başarısız']);
    }

} catch (PDOException $e) {
    error_log("Otomatik stok ekleme hatası: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Veritabanı hatası']));
} catch (Exception $e) {
    error_log("Otomatik stok ekleme genel hatası: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Hata oluştu']));
}

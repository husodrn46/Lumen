<?php
declare(strict_types=1);

// Output buffering başlat (tüm çıktıyı yakala)
ob_start();

include_once(__DIR__ . "/ayr.php");

// Session kontrolü
if (session_id() === '') {
    session_start();
}

if (!isset($_SESSION['plasiyer_id']) || empty($_SESSION['plasiyer_id'])) {
    ob_clean(); // Önceki tüm çıktıları temizle
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Oturum gerekli']);
    exit;
}

$terminalkullanici = $_SESSION['plasiyer_id'];

// Önceki tüm çıktıları temizle (ayr.php, kontrol.php vs.'den gelen HTML)
ob_clean();

// JSON header ayarla
header('Content-Type: application/json; charset=utf-8');

$fisRef = isset($_GET['fis']) ? intval($_GET['fis']) : 0;

if ($fisRef <= 0) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz fiş referansı']);
    exit;
}

try {
    // Yazdırma kayıtlarını getir (LOG tablosundan - hiç silinmez)
    $stmt = $dbh->prepare("
        SELECT
            MD.ID,
            MD.TARIH,
            MD.DIZAYN,
            MD.MIKTAR,
            MD.KULLANICI,
            SL.CODE AS KULLANICI_KODU,
            SL.DEFINITION_ AS KULLANICI_ADI
        FROM M_MOBIL_DIZAYN_LOG MD
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = MD.KULLANICI
        WHERE MD.FIS = :fis
        ORDER BY MD.TARIH DESC
    ");
    $stmt->execute([':fis' => $fisRef]);

    $kayitlar = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $tarih = $row['TARIH'] ? date('d.m.Y H:i', strtotime((string) $row['TARIH'])) : 'Bilinmiyor';
        $kullanici = $row['KULLANICI_ADI'] ?: 'Bilinmiyor';
        $kullaniciKodu = $row['KULLANICI_KODU'] ?: '-';

        $kayitlar[] = ['id' => $row['ID'], 'tarih' => $tarih, 'dizayn' => $row['DIZAYN'], 'miktar' => $row['MIKTAR'], 'kullanici' => $kullanici, 'kullanici_kodu' => $kullaniciKodu];
    }

    echo json_encode([
        'success' => true,
        'data' => $kayitlar,
        'toplam' => count($kayitlar)
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Veritabanı hatası: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

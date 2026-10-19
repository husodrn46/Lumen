<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/oturum.php';

// Output buffering başlat (tüm çıktıyı yakala)
ob_start();

include_once(__DIR__ . "/../ayr.php");

// Oturum + zorla çıkış + saldırı modu kontrolü kontrol.php'den geçer (eskiden yalnız
// $_SESSION'a bakılıyordu; yönetici tarafından kapatılan oturum okumaya devam
// ediyordu). Bu uç yalnız JSON döndürdüğünden kontrol.php'nin JSON yanıt yolu seçilir.
$_SERVER['HTTP_ACCEPT'] = 'application/json';
require_once __DIR__ . '/../kontrol.php';

// Önceki tüm çıktıları temizle (ayr.php, kontrol.php vs.'den gelen HTML)
ob_clean();

// JSON header ayarla
header('Content-Type: application/json; charset=utf-8');

$fisRef = isset($_GET['fis']) ? intval($_GET['fis']) : 0;
if ($fisRef > 0 && !m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $fisRef)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Yetkiniz yok']);
    exit;
}

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
        LEFT JOIN {$GLOBALS['LUMEN_SLSMAN']} SL ON SL.LOGICALREF = MD.KULLANICI
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
    // Ayrinti yalniz sunucu loguna; kullaniciya jenerik mesaj.
    error_log('yazdirma_detay: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Kayitlar alinamadi. Lutfen tekrar deneyin.'
    ], JSON_UNESCAPED_UNICODE);
}
?>

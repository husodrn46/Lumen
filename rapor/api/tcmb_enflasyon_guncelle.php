<?php
declare(strict_types=1);

/**
 * TCMB EVDS API Enflasyon Veri Güncelleme
 * TCMB'den aylık TÜFE verilerini çeker ve SQLite veritabanına kaydeder
 */

header('Content-Type: application/json; charset=utf-8');

// Kimlik doğrulama
require_once __DIR__ . '/../../ayr.php';
require_once __DIR__ . '/../../kontrol.php';

// SQLite bağlantısı
$db_path = __DIR__ . '/../../database/enflasyon.db';
try {
    $db = new PDO('sqlite:' . $db_path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('tcmb_enflasyon_guncelle hata: ' . $e->getMessage());
    die(json_encode(['success' => false, 'error' => 'Veritabani baglanti hatasi']));
}

// API key kontrolü
$stmtApiAyar = $db->prepare("SELECT api_key, aktif FROM enflasyon_api_ayarlar WHERE id = 1");
$stmtApiAyar->execute();
$api_ayar = $stmtApiAyar->fetch(PDO::FETCH_ASSOC);

if (!$api_ayar || !$api_ayar['aktif'] || empty($api_ayar['api_key'])) {
    echo json_encode(['success' => false, 'error' => 'TCMB API ayarları yapılmamış. Lütfen önce API key tanımlayın.']);
    exit;
}

$api_key = $api_ayar['api_key'];

// TCMB EVDS API parametreleri
$base_url = 'https://evds2.tcmb.gov.tr/service/evds/';

// TÜFE Aylık Değişim Oranı
// TP.FG.J0 = TÜFE Aylık % Değişim
$serie_code = 'TP.FG.J0';

// Son 24 ay için veri çek
$baslangic = date('d-m-Y', strtotime('-24 months'));
$bitis = date('d-m-Y');

$url = $base_url . "series=" . $serie_code . "&startDate=" . $baslangic . "&endDate=" . $bitis . "&type=json&key=" . $api_key;

// cURL ile TCMB'ye istek at
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http_code !== 200) {
    echo json_encode(['success' => false, 'error' => 'TCMB API hatası. HTTP Kodu: ' . $http_code, 'url' => $base_url . "series=" . $serie_code . "&startDate=" . $baslangic . "&endDate=" . $bitis]);
    exit;
}

$data = json_decode($response, true);

if (!$data || !isset($data['items']) || empty($data['items'])) {
    echo json_encode(['success' => false, 'error' => 'TCMB\'den veri alınamadı veya veri formatı hatalı.', 'response' => $response]);
    exit;
}

// Verileri parse et ve kaydet
$stmt = $db->prepare("INSERT OR REPLACE INTO enflasyon_aylik (yil, ay, oran, tufe, kaynak, guncellenme_tarihi) VALUES (?, ?, ?, ?, ?, datetime('now', 'localtime'))");
$stmtMevcut = $db->prepare("SELECT COUNT(*) FROM enflasyon_aylik WHERE yil = :yil AND ay = :ay");

$eklenen = 0;
$guncellenen = 0;

foreach ($data['items'] as $item) {
    // Tarih formatı: YYYY-MM-DD
    if (!isset($item['Tarih'])) {
        continue;
    }
    if (!isset($item['TP_FG_J0'])) {
        continue;
    }
    $tarih_parts = explode('-', (string) $item['Tarih']);
    if (count($tarih_parts) !== 3) {
        continue;
    }

    $yil = (int)$tarih_parts[0];
    $ay = (int)$tarih_parts[1];
    $oran = (float)$item['TP_FG_J0'];

    // Veriyi kaydet
    $stmtMevcut->execute([':yil' => $yil, ':ay' => $ay]);
    $mevcutMu = (int) $stmtMevcut->fetchColumn();

    $stmt->execute([$yil, $ay, $oran, null, 'TCMB']);

    if ($mevcutMu > 0) {
        $guncellenen++;
    } else {
        $eklenen++;
    }
}

// Son güncelleme zamanını kaydet
$db->exec("UPDATE enflasyon_api_ayarlar SET son_guncelleme = datetime('now', 'localtime') WHERE id = 1");

echo json_encode(['success' => true, 'eklenen' => $eklenen, 'guncellenen' => $guncellenen, 'toplam' => $eklenen + $guncellenen, 'son_guncelleme' => date('Y-m-d H:i:s'), 'mesaj' => "$eklenen yeni, $guncellenen mevcut kayıt güncellendi."]);

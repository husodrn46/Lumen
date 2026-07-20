<?php
declare(strict_types=1);

/**
 * Akıllı Sipariş Tahmin Sistemi - AJAX Endpoint
 *
 * Müşterinin geçmiş siparişlerini analiz ederek
 * sık sipariş verdiği ürünleri önerir.
 */

// Output buffering temizle
ob_start();

// Veritabanı bağlantısı ve kimlik doğrulama
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/kontrol.php';

// Tüm önceki output'u temizle
ob_end_clean();

// JSON header'ı ayarla
header('Content-Type: application/json; charset=utf-8');

// POST verileri
$cariId = isset($_POST['cari_id']) ? intval($_POST['cari_id']) : 0;
$limit = isset($_POST['limit']) ? max(1, min((int) $_POST['limit'], 50)) : 10;

if ($cariId === 0) {
    echo json_encode(['success' => false, 'message' => 'Cari ID eksik']);
    exit;
}

// Yetki: kullanici bu cariyi gorebiliyor mu?
if (function_exists('m_p_cariid_goruntulebilir_mi') && !m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $cariId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Bu cari icin yetkiniz yok']);
    exit;
}

// Helper fonksiyonlar
function e(mixed $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

function fmtqty(float|int|string|null $n): string {
    if (function_exists('kusuratadet')) {
        return kusuratadet($n);
    }
    $n = floatval($n);
    if (abs($n) < 1e-7) {
        $n = 0.0;
    }
    return number_format($n, 2, ',', '.');
}

function fmtprice(float|int|string|null $n): string {
    if (function_exists('kusuratpara')) {
        return kusuratpara($n);
    }
    return number_format(floatval($n), 2, ',', '.');
}

try {
    /**
     * AKILLI ÖNERİ ALGORITMASI
     *
     * 1. Son 3 ay içinde sipariş verilen ürünler
     * 2. Sipariş sıklığına göre sırala (en çok sipariş edilen üstte)
     * 3. Toplam sipariş miktarını göster
     * 4. Son sipariş tarihini göster
     * 5. Ortalama fiyatı göster
     */
    $query = "
        SELECT TOP {$limit}
            S.LOGICALREF AS STOK_ID,
            S.CODE AS STOK_KODU,
            S.NAME AS STOK_ADI,
            B.CODE AS BIRIM,
            COUNT(DISTINCT F.LOGICALREF) AS SIPARIS_SAYISI,
            SUM(L.AMOUNT) AS TOPLAM_MIKTAR,
            AVG(L.PRICE) AS ORT_FIYAT,
            MAX(F.DATE_) AS SON_SIPARIS_TARIHI,
            ISNULL((
                SELECT SUM(ST.ONHAND)
                FROM {$firmadonemx}STINVTOT ST
                WHERE ST.STOCKREF = S.LOGICALREF AND ST.INVENNO = -1
            ), 0) AS GUNCEL_STOK
        FROM {$firmadonem}ORFLINE L WITH(NOLOCK)
        INNER JOIN {$firmadonem}ORFICHE F WITH(NOLOCK)
            ON L.ORDFICHEREF = F.LOGICALREF
        INNER JOIN {$firma}ITEMS S WITH(NOLOCK)
            ON L.STOCKREF = S.LOGICALREF
        LEFT JOIN {$firma}UNITSETL B WITH(NOLOCK)
            ON S.UNITSETREF = B.UNITSETREF AND B.MAINUNIT = 1
        WHERE F.CLIENTREF = :cari_id
          AND F.TRCODE IN (1, 7)  -- 1: Satış siparişi, 7: Perakende satış
          AND L.LINETYPE = 0      -- Normal satır
          AND F.DATE_ >= DATEADD(MONTH, -3, GETDATE())  -- Son 3 ay
          AND S.ACTIVE = 0
        GROUP BY
            S.LOGICALREF,
            S.CODE,
            S.NAME,
            B.CODE
        ORDER BY
            SIPARIS_SAYISI DESC,
            TOPLAM_MIKTAR DESC
    ";

    $stmt = $dbh->prepare($query);
    $stmt->bindParam(':cari_id', $cariId, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows || count($rows) === 0) {
        echo json_encode(['success' => true, 'data' => [], 'message' => 'Bu müşteri için son 3 ayda sipariş kaydı bulunamadı.']);
        exit;
    }

    // Sonuçları formatla
    $results = [];
    foreach ($rows as $row) {
        $sonSiparisTarihi = $row['SON_SIPARIS_TARIHI'];
        if ($sonSiparisTarihi) {
            $dt = new DateTime($sonSiparisTarihi);
            $sonSiparisTarihiFormatli = $dt->format('d.m.Y');
        } else {
            $sonSiparisTarihiFormatli = '-';
        }

        $results[] = ['stok_id' => $row['STOK_ID'], 'stok_kodu' => $row['STOK_KODU'], 'stok_adi' => $row['STOK_ADI'], 'birim' => $row['BIRIM'], 'siparis_sayisi' => intval($row['SIPARIS_SAYISI']), 'toplam_miktar' => floatval($row['TOPLAM_MIKTAR']), 'toplam_miktar_fmt' => fmtqty($row['TOPLAM_MIKTAR']), 'ort_fiyat' => floatval($row['ORT_FIYAT']), 'ort_fiyat_fmt' => fmtprice($row['ORT_FIYAT']), 'son_siparis_tarihi' => $sonSiparisTarihiFormatli, 'guncel_stok' => floatval($row['GUNCEL_STOK']), 'guncel_stok_fmt' => fmtqty($row['GUNCEL_STOK'])];
    }

    echo json_encode(['success' => true, 'data' => $results, 'total' => count($results), 'message' => 'Son 3 ayda en çok sipariş verilen ' . count($results) . ' ürün.']);

} catch (PDOException $e) {
    error_log("Sipariş öneri hatası: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Veritabanı hatası oluştu.']);
}

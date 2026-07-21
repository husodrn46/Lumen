<?php
declare(strict_types=1);

// api/get_alerts.php
include_once(__DIR__ . "/../../ayr.php");
require_once __DIR__ . '/../../kontrol.php';
header('Content-Type: application/json; charset=utf-8');

// Rapor yetkisi (M17) — kasa/satis verisi doner.
if ((int) m_p_yetki($terminalkullanici, 'M17') !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

/*
 * DURUM: Bu uç şu an hiçbir uyarı üretmiyor ve kod tabanında çağıran yok.
 *
 * İçindeki iki örnek sorgu da LOGO şemasında bulunmayan sütunlara dayanıyordu
 * ve her istekte hata veriyordu:
 *   - ITEMS.STOCK_LEVEL / MIN_STOCK_LEVEL  (LOGO'da ürün bazlı minimum stok
 *     alanı standart değildir; ayrıca firma öneki "LG_001_" olarak sabit
 *     yazılmıştı, başka firma numarasında zaten çalışmazdı)
 *   - CLFLINE.DUEDATE                      (vade bilgisi CLFLINE'da tutulmaz)
 *
 * Kendi uyarılarınızı eklemek için $alerts dizisine
 *   ['type' => 'warning'|'danger', 'text' => '...', 'link' => '...']
 * biçiminde öğe ekleyin. Sorgu hatasının panoyu kırmaması için blok
 * try/catch içindedir; hata durumunda boş liste döner ve loga yazılır.
 */

$alerts = [];

try {
    // Örnek: kendi uyarı sorgularınızı buraya ekleyin.
} catch (Throwable $e) {
    error_log('rapor/api/get_alerts: ' . $e->getMessage());
    $alerts = [];
}

echo json_encode($alerts, JSON_UNESCAPED_UNICODE);

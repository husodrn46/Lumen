<?php
declare(strict_types=1);

// api/kasa.php
include_once(__DIR__ . "/../../ayr.php");
require_once __DIR__ . '/../../kontrol.php';
header('Content-Type: application/json; charset=UTF-8');

// Rapor yetkisi (M17) — kasa_hareket.php ile ayni kapi.
if ((int) m_p_yetki($terminalkullanici, 'M17') !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

try {

    // Kur fonksiyonu (aynı kaldı)
    function getRate($base, $apiKey): void
    {
        // … (curl / file_get_contents) …
        // dönüş: float TRY kuru veya false
    }

    $apiKey = getenv('EXCHANGE_RATE_API_KEY') ?: '';
    $usdRate = getRate('USD', $apiKey) ?: 1;
    $eurRate = getRate('EUR', $apiKey) ?: 1;
    $rates = ['USD' => $usdRate, 'EUR' => $eurRate];

    // SQL sorgusu (aynı)
    $sql = "
        SELECT
          KSCARD.LOGICALREF AS KOD,
          KSCARD.NAME       AS ADI,
          CASE 
            WHEN KSCARD.LOGICALREF = 9  THEN 'USD'
            WHEN KSCARD.LOGICALREF = 10 THEN 'EUR'
            ELSE 'TL'
          END AS TUR,
          SUM(
            CASE WHEN KSCARD.LOGICALREF IN (9,10) AND KSLINES.SIGN = 0 THEN KSLINES.TRNET
                 WHEN KSCARD.LOGICALREF NOT IN (9,10) AND KSLINES.SIGN = 0 THEN KSLINES.AMOUNT
                 ELSE 0 END
          )
          - SUM(
            CASE WHEN KSCARD.LOGICALREF IN (9,10) AND KSLINES.SIGN = 1 THEN KSLINES.TRNET
                 WHEN KSCARD.LOGICALREF NOT IN (9,10) AND KSLINES.SIGN = 1 THEN KSLINES.AMOUNT
                 ELSE 0 END
          ) AS NET_BAKIYE
        FROM {$firma}KSCARD KSCARD
        LEFT JOIN {$firmadonem}KSLINES KSLINES
          ON KSCARD.LOGICALREF = KSLINES.CARDREF
        WHERE KSCARD.ACTIVE = 0
	        GROUP BY KSCARD.LOGICALREF, KSCARD.NAME
	        ORDER BY KOD
	    ";
	    $stmt = $dbh->prepare($sql);
	    $stmt->execute();

    // İsteğe bağlı “only” parametresini oku
    $onlyKeys = [];
    if (!empty($_GET['only'])) {
        // örn: only=kod,adi,net
        $onlyKeys = array_map('trim', explode(',', (string) $_GET['only']));
    }

    // Sonuç dizisini hazırla
    $out = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $net = floatval($r['NET_BAKIYE']);
        $rate = array_key_exists($r['TUR'], $rates) ? $rates[$r['TUR']] : 1;
        $item = [
            'kod' => $r['KOD'],
            'adi' => $r['ADI'],
            'tur' => $r['TUR'],
            'net' => $net,
            'tlDeg' => $net * $rate
        ];

        // Sadece seçili anahtarları bırak
        if ($onlyKeys !== []) {
            $filtered = [];
            foreach ($onlyKeys as $k) {
                if (array_key_exists($k, $item)) {
                    $filtered[$k] = $item[$k];
                }
            }
            $out[] = $filtered;
        } else {
            $out[] = $item;
        }
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    error_log('rapor/api/kasa hata: ' . $e->getMessage());
    echo json_encode(['error' => 'Bir hata olustu']);
}

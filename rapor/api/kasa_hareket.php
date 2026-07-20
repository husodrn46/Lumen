<?php
// -------- JSON moduna temiz giriş

// Output buffering başlat (kontrol.php redirect'lerini yakala)
ob_start();

// Oturum ve yetki kontrolü - GÜVENLİK İÇİN ZORUNLU
include_once __DIR__ . '/../../ayr.php';
include __DIR__ . '/../../kontrol.php';

// Buffer'ı temizle (kontrol.php'nin olası çıktılarını yok say)
ob_end_clean();

// JSON header
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$DEBUG = isset($_GET['debug']);

try {
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok'=>false, 'error'=>'method_not_allowed']);
        exit;
    }

    // Yetki kontrolü - M17 (Kasa raporları) yetkisi gerekli
    if (m_p_yetki($terminalkullanici, 'M17') != 1) {
        http_response_code(403);
        echo json_encode(['ok'=>false, 'error'=>'forbidden', 'message'=>'Bu rapora erişim yetkiniz yok']);
        exit;
    }

    // Parametre
    $cardref = isset($_GET['cardref']) ? (int)$_GET['cardref'] : 0;
    if ($cardref <= 0) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'bad_request', 'message'=>'cardref param missing or invalid']);
        exit;
    }

    // Sorgu — DEFINITION_ kaldırıldı; fallback olarak c.NAME kullanıldı
    $sql = "
        SELECT TOP 30
            CONVERT(varchar(10), l.DATE_, 23) AS tarih,CUSTTITLE AS cari,
            COALESCE(NULLIF(l.LINEEXP, ''), c.NAME) AS aciklama,
            CASE WHEN l.SIGN = 0 THEN
                 CASE WHEN c.LOGICALREF IN (9,10) THEN ISNULL(l.TRNET,0) ELSE ISNULL(l.AMOUNT,0) END
                 ELSE 0 END AS giren,
            CASE WHEN l.SIGN = 1 THEN
                 CASE WHEN c.LOGICALREF IN (9,10) THEN ISNULL(l.TRNET,0) ELSE ISNULL(l.AMOUNT,0) END
                 ELSE 0 END AS cikan
        FROM {$firmadonem}KSLINES l
        JOIN {$firma}KSCARD  c ON l.CARDREF = c.LOGICALREF
        WHERE l.CARDREF = :ref
        ORDER BY l.DATE_ DESC, l.LOGICALREF DESC;
    ";

    $st = $dbh->prepare($sql);
    $st->execute([':ref' => $cardref]);
    $rows = $st->fetchAll();

    http_response_code(200);
    echo json_encode($rows);
    exit;

} catch (Exception $e) { // Throwable PHP 7+
    // Sunucu log'una yaz
    error_log('[kasa_hareket] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());

    http_response_code(500);
    $payload = ['ok'     => false, 'error'  => 'server_error', 'message'=> 'Beklenmeyen bir hata oluştu.'];
    if ($DEBUG) {
        $payload['detail'] = $e->getMessage();
    }
    echo json_encode($payload);
    exit;
}
?>

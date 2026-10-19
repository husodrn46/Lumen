<?php

declare(strict_types=1);

/**
 * Günlük hatırlatıcı (zamanlanmış görev) → bildirim kutusu.
 *
 *   - Görevler: vadesi yarın/bugün olan veya geçmiş açık görevler → atanan kişi
 *   - Çekler: portföydeki müşteri çeklerinden vadesi --gun (varsayılan 3) gün
 *     içinde olanlar → çek yetkisi (M30) olanlar ve yöneticiler
 *
 * Her olay kişi başına bir kez bildirilir (bildirim anahtarı); betik günde
 * birkaç kez çalışsa da tekrar bildirim üretmez.
 *
 *   php scripts/bildirim_hatirlatici.php [--gun=3]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$s = getopt('', ['gun:']);
$gun = max(1, min(30, (int) ($s['gun'] ?? 3)));

$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? 'bildirim_hatirlatici.php';
require_once dirname(__DIR__) . '/ayr.php';
require_once dirname(__DIR__) . '/includes/bildirim.php';

$yazilan = 0;

// ---- Görevler -------------------------------------------------------------
try {
    $gorevler = $dbh->query("
        SELECT ID, BASLIK, ATANAN_ID, CONVERT(VARCHAR(10), VADE_TARIHI, 120) AS VADE,
               DATEDIFF(DAY, CAST(GETDATE() AS DATE), CAST(VADE_TARIHI AS DATE)) AS KALAN
        FROM M_GOREV
        WHERE AKTIF = 1 AND DURUM IN (0, 1, 2) AND VADE_TARIHI IS NOT NULL
          AND CAST(VADE_TARIHI AS DATE) <= DATEADD(DAY, 1, CAST(GETDATE() AS DATE))")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $gorevler = [];
    fwrite(STDERR, 'görevler okunamadı: ' . $e->getMessage() . "\n");
}
foreach ($gorevler as $g) {
    $kalan = (int) $g['KALAN'];
    $baslik = $kalan < 0 ? 'Görevin vadesi geçti' : ($kalan === 0 ? 'Görevin vadesi bugün' : 'Görevin vadesi yarın');
    // Anahtar duruma göre: "yarın" ve "geçti" ayrı ayrı bir kez bildirilir.
    $anahtar = 'gorev-' . (int) $g['ID'] . '-' . ($kalan < 0 ? 'gecti' : 'vade') . '-' . $g['VADE'];
    if (lumen_bildirim_ekle($dbh, (int) $g['ATANAN_ID'], 'gorev', $baslik,
            (string) $g['BASLIK'] . ' (vade ' . date('d.m.Y', strtotime((string) $g['VADE'])) . ')', 'gorev/gorevler.php', $anahtar)) {
        $yazilan++;
    }
}

// ---- Çekler ---------------------------------------------------------------
try {
    $cekler = $dbh->query("
        SELECT C.LOGICALREF, C.PORTFOYNO, C.AMOUNT, C.OWING, CONVERT(VARCHAR(10), C.DUEDATE, 120) AS VADE
        FROM {$firmadonem}CSCARD C
        WHERE C.DOC = 1 AND C.CURRSTAT = 1 AND ISNULL(C.CANCELLED, 0) = 0
          AND CAST(C.DUEDATE AS DATE) BETWEEN CAST(GETDATE() AS DATE) AND DATEADD(DAY, {$gun}, CAST(GETDATE() AS DATE))
        ORDER BY C.DUEDATE")->fetchAll(PDO::FETCH_ASSOC);
    $alicilar = $dbh->query("
        SELECT Y.PERSONEL FROM M_P_YETKI Y INNER JOIN LG_SLSMAN S ON S.LOGICALREF = Y.PERSONEL
        WHERE S.ACTIVE = 0 AND (Y.YETKI = 0 OR (Y.YETKI = 1 AND Y.M30 = 1))")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $cekler = [];
    $alicilar = [];
    fwrite(STDERR, 'çekler okunamadı: ' . $e->getMessage() . "\n");
}
foreach ($cekler as $c) {
    $metin = number_format((float) $c['AMOUNT'], 2, ',', '.') . ' TL — ' . trim((string) $c['OWING'])
        . ' — portföy ' . trim((string) $c['PORTFOYNO']) . ' — vade ' . date('d.m.Y', strtotime((string) $c['VADE']));
    foreach ($alicilar as $p) {
        if (lumen_bildirim_ekle($dbh, (int) $p, 'cek_vade', 'Çek vadesi yaklaşıyor', $metin, 'cek/cek_gorsel.php',
                'cek-' . $firmadonem . (int) $c['LOGICALREF'] . '-' . $c['VADE'])) {
            $yazilan++;
        }
    }
}

printf("%d görev, %d çek incelendi; %d yeni bildirim yazıldı.\n", count($gorevler), count($cekler), $yazilan);

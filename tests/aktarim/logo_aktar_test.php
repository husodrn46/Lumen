<?php

declare(strict_types=1);

/**
 * LOGO → bağımsız Lumen içe aktarma testi (scripts/logo_aktar.php).
 * LOGO demo veritabanı (LUMEN_DEMO) kurulu olmalı; boş bir test veritabanı açar.
 *
 *   docker compose -f demo/docker-compose.yml exec -T app php tests/aktarim/logo_aktar_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
putenv('LUMEN_VERI_KAYNAGI=lumen');
require_once dirname(__DIR__, 2) . '/includes/kurulum_sema.php';
require_once dirname(__DIR__, 2) . '/includes/aktarim/logo_aktar.php';

const KAYNAK = 'LUMEN_DEMO';
const HEDEF = 'LUMEN_AKTARIM_TEST';
$baglan = static fn(string $db): PDO => new PDO('sqlsrv:server=' . getenv('AKL_DB_SERVER') . ";database={$db};TrustServerCertificate=1",
    (string) getenv('AKL_DB_USER'), (string) getenv('AKL_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$master = $baglan('master');
$master->exec("IF DB_ID(N'" . HEDEF . "') IS NOT NULL BEGIN ALTER DATABASE [" . HEDEF . "] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [" . HEDEF . "]; END");
$master->exec('CREATE DATABASE [' . HEDEF . ']');
$k = $baglan(KAYNAK);
$h = $baglan(HEDEF);
$s = kurulum_sema_uygula($h, dirname(__DIR__, 2) . '/sql', true);

$hata = 0;
$bekle = static function (bool $kosul, string $ad) use (&$hata): void {
    echo ($kosul ? '  ✓ ' : '  ✗ ') . $ad . "\n";
    $hata += $kosul ? 0 : 1;
};
$sessiz = static function (string $s): void {
};
echo "LOGO → Lumen içe aktarma\n";
$bekle($s['hatalar'] === [], 'hedef şeması kuruldu');

$ayar = ['firma' => 1, 'donem' => 2, 'tarih' => date('Y-m-d'), 'uygula' => false];
$d = logo_aktar($k, $h, $ayar, $sessiz);
$bekle($d['ok'] && !$d['uygulandi'] && (int) $h->query('SELECT COUNT(*) FROM dbo.LM_CARI')->fetchColumn() === 0,
    'deneme kipi toplamları doğrular, hiçbir şey yazmaz');

$r = logo_aktar($k, $h, ['uygula' => true] + $ayar, $sessiz);
$bekle($r['ok'] && $r['uygulandi'], 'uygula: ' . $r['mesaj']);
foreach ($r['karsilastirma'] as $ad => [$a, $b]) {
    $bekle(abs($a - $b) <= 0.011, sprintf('%s: LOGO %.2f = Lumen %.2f', $ad, $a, $b));
}
$tek = static fn(PDO $p, string $sql): string => (string) $p->query($sql)->fetchColumn();
$bekle($tek($k, 'SELECT COUNT(*) FROM LG_001_CLCARD') === $tek($h, 'SELECT COUNT(*) FROM dbo.LM_CARI'), 'cari sayısı aynı');
$bekle($tek($k, "SELECT CODE FROM LG_001_CLCARD WHERE LOGICALREF = 5") === $tek($h, 'SELECT KOD FROM dbo.LM_CARI WHERE ID = 5'), 'cari kimlikleri korunur');
$bekle($tek($k, 'SELECT COUNT(*) FROM LG_001_ITEMS') === $tek($h, 'SELECT COUNT(*) FROM dbo.LM_STOK'), 'stok sayısı aynı');
$bekle($tek($k, "SELECT SIFRE FROM M_P_YETKI P JOIN LG_SLSMAN S ON S.LOGICALREF = P.PERSONEL WHERE S.CODE = 'YONETICI'")
    === $tek($h, "SELECT SIFRE FROM M_P_YETKI P JOIN dbo.LM_PERSONEL S ON S.ID = P.PERSONEL WHERE S.KOD = 'YONETICI'"), 'yönetici parolası aynı (kimlikler korunur)');
$bekle((int) $tek($h, "SELECT COUNT(*) FROM dbo.LMV_ITEMS I JOIN dbo.LMV_UNITSETL U ON U.UNITSETREF = I.UNITSETREF AND U.MAINUNIT = 1") === (int) $tek($h, 'SELECT COUNT(*) FROM dbo.LM_STOK'),
    'her stokun LOGO uyumlu görünümde ana birimi var');
try {
    logo_aktar($k, $h, ['uygula' => true] + $ayar, $sessiz);
    $bekle(false, 'dolu hedefe ikinci aktarım reddedilir');
} catch (RuntimeException $e) {
    $bekle(str_contains($e->getMessage(), 'boş değil'), 'dolu hedefe ikinci aktarım reddedilir');
}
$h = null;
$master->exec('ALTER DATABASE [' . HEDEF . '] SET SINGLE_USER WITH ROLLBACK IMMEDIATE; DROP DATABASE [' . HEDEF . ']');
printf("%s\n", $hata === 0 ? 'içe aktarma testi geçti' : "{$hata} kontrol başarısız");
exit($hata === 0 ? 0 : 1);

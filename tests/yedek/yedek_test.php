<?php

declare(strict_types=1);

/**
 * Uygulama içi yedekleme: al → veriyi boz → geri yükle → her şey yedek anına döner.
 * Yarım/bozuk dosya ve başka moddan yedek reddedilir. Demo/test veritabanında çalışır
 * (bağımsız modda tüm veritabanı, LOGO modunda M_* tabloları).
 *
 *   docker compose -f demo/docker-compose.yml exec -T app php tests/yedek/yedek_test.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}
require_once dirname(__DIR__, 2) . '/includes/yedek/yedek_lib.php';

$db = (string) getenv('AKL_DB_NAME');
if (preg_match('/^LUMEN_[A-Z0-9_]+$/', $db) !== 1) {
    fwrite(STDERR, "REDDEDİLDİ: yalnız demo/test veritabanında ({$db}).\n");
    exit(3);
}
$dbh = new PDO('sqlsrv:server=' . getenv('AKL_DB_SERVER') . ";database={$db};TrustServerCertificate=1",
    (string) getenv('AKL_DB_USER'), (string) getenv('AKL_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hata = 0;
$bekle = static function (bool $k, string $ad) use (&$hata): void {
    echo ($k ? '  ✓ ' : '  ✗ ') . $ad . "\n";
    $hata += $k ? 0 : 1;
};
$bagimsiz = lumen_bagimsiz_mi();
echo 'Yedekleme (' . ($bagimsiz ? 'bağımsız' : 'LOGO') . " modu, {$db})\n";

/** Her tablonun satır sayısı + kimlik sayaçları — geri yüklemeden sonra aynı olmalı. */
$ozet = static function () use ($dbh): array {
    $o = [];
    foreach (lumen_yedek_tablolari($dbh) as $t) {
        $o[$t] = (int) $dbh->query("SELECT COUNT(*) FROM dbo.[{$t}]")->fetchColumn();
    }
    return $o;
};
$once = $ozet();
$denetim = $bagimsiz
    ? static fn(): string => implode('|', [(string) $dbh->query('SELECT SUM(BAKIYE) FROM LMV_CARI_BAKIYE')->fetchColumn(),
        (string) $dbh->query('SELECT SUM(MIKTAR) FROM LMV_STOK_MIKTAR')->fetchColumn(),
        (string) $dbh->query('SELECT SIFRE FROM M_P_YETKI WHERE PERSONEL = 1')->fetchColumn()])
    : static fn(): string => (string) $dbh->query('SELECT SIFRE FROM M_P_YETKI WHERE PERSONEL = 1')->fetchColumn();
$denetimOnce = $denetim();

$y = lumen_yedek_al($dbh, 'elle');
$bekle($y['ok'], 'yedek alındı: ' . $y['mesaj']);
$yol = lumen_yedek_klasoru() . '/' . $y['ad'];
$bekle(is_array(lumen_yedek_dogrula($yol)), 'yedek doğrulanır (başlık + bitiş kaydı)');

// Veriyi boz: parolayı değiştir, kayıt sil/ekle
$dbh->exec("UPDATE M_P_YETKI SET SIFRE = 'bozuk' WHERE PERSONEL = 1");
if ($bagimsiz) {
    $dbh->exec('DELETE FROM LM_CARI_HAREKET WHERE ID = (SELECT MIN(ID) FROM LM_CARI_HAREKET)');
    $dbh->exec("INSERT INTO LM_AYAR (ANAHTAR, DEGER) VALUES ('yedek_test', 'x')");
}
$bekle($denetim() !== $denetimOnce, 'veri değişti');

$g = lumen_yedek_geri_yukle($dbh, $yol);
$bekle($g['ok'], 'geri yüklendi: ' . $g['mesaj']);
$bekle($ozet() === $once, 'tüm tabloların satır sayıları yedek anıyla aynı');
$bekle($denetim() === $denetimOnce, 'bakiyeler, stok ve parola yedek anıyla aynı');
$bekle(isset($g['onceki']) && is_file(lumen_yedek_klasoru() . '/' . $g['onceki']), 'geri yüklemeden önceki durumun yedeği alındı');
if ($bagimsiz) {
    // Kimlik sayacı: yeni kayıt mevcut en büyük kimliğin üstüne gelir
    $max = (int) $dbh->query('SELECT MAX(ID) FROM LM_BIRIM')->fetchColumn();
    $dbh->exec("INSERT INTO LM_BIRIM (KOD, AD) VALUES ('YDKTEST', 'x')");
    $bekle((int) $dbh->query("SELECT ID FROM LM_BIRIM WHERE KOD = 'YDKTEST'")->fetchColumn() > $max, 'kimlik sayacı geri yüklemeden sonra doğru');
    $dbh->exec("DELETE FROM LM_BIRIM WHERE KOD = 'YDKTEST'");
}

// Yarım dosya reddedilir ve hiçbir şeyi değiştirmez
$yarim = lumen_yedek_klasoru() . '/yarim_test.lumen.gz';
$satirlar = gzfile($yol);
file_put_contents($yarim, gzencode(implode('', array_slice($satirlar, 0, (int) (count($satirlar) / 2)))));
$r = lumen_yedek_geri_yukle($dbh, $yarim);
$bekle(!$r['ok'] && str_contains($r['mesaj'], 'yarım'), 'yarım yedek reddedilir');
// Başka modda alınmış yedek reddedilir
$baska = lumen_yedek_klasoru() . '/baska_mod_test.lumen.gz';
$satirlar[0] = json_encode(['lumen_yedek' => 1, 'mod' => $bagimsiz ? 'logo' : 'lumen']) . "\n";
file_put_contents($baska, gzencode(implode('', $satirlar)));
$r = lumen_yedek_geri_yukle($dbh, $baska);
$bekle(!$r['ok'] && str_contains($r['mesaj'], 'başka çalışma modunda'), 'başka moddan yedek reddedilir');
$bekle($ozet() === $once, 'reddedilen denemeler veriyi değiştirmedi');

foreach ([$yol, $yarim, $baska, lumen_yedek_klasoru() . '/' . ($g['onceki'] ?? '-')] as $f) {
    @unlink($f);
}
echo $hata === 0 ? "yedekleme testi geçti\n" : "{$hata} kontrol başarısız\n";
exit($hata === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * Performans ölçümü (bağımsız mod): önemli ekranların, raporların, yedeklemenin ve Excel
 * aktarmanın büyük veride yanıt süreleri. Her ekran 3 kez açılır, ortanca süre alınır.
 *   ekran: iyi < 2 sn · yavaş < 5 sn · çok yavaş ≥ 5 sn (çıkış kodu 1);
 *   indirilen PDF/Excel: 5 / 15 sn; toplu işlem (yedek, Excel aktarma): 10 / 30 sn
 *
 *   docker compose -f demo/docker-compose.yml exec -T app php tests/performans/olc.php --veri [--rapor yol.md]
 *   (--veri: önce tests/performans/buyuk_veri.sql yüklenir; 5.000 cari, 20.000 ürün, 1 yıllık hareket)
 */

require dirname(__DIR__) . '/akis/_ortak.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (strtolower((string) getenv('LUMEN_VERI_KAYNAGI')) !== 'lumen') {
    fwrite(STDERR, "Bağımsız mod içindir (bash demo/kur.sh --bagimsiz).\n");
    exit(3);
}
$o = getopt('', ['veri', 'rapor:']);
if (isset($o['veri'])) {
    $t0 = microtime(true);
    $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, 900);
    $pdo->exec((string) file_get_contents(__DIR__ . '/buyuk_veri.sql'));
    printf("Büyük veri yüklendi (%.0f sn)\n", microtime(true) - $t0);
}
$say = static fn(string $t): int => (int) tek($pdo, "SELECT COUNT(*) FROM dbo.{$t}");
printf("Veri: %d cari, %d ürün, %d belge, %d belge satırı, %d stok hareketi, %d cari hareketi, %d kasa fişi\n",
    $say('LM_CARI'), $say('LM_STOK'), $say('LM_BELGE'), $say('LM_BELGE_SATIR'), $say('LM_STOK_HAREKET'), $say('LM_CARI_HAREKET'), $say('LM_FINANS_FIS'));

$pdo->exec('DELETE FROM M_GIRIS_LOG');
$y = (new Tarayici($URL))->giris('YONETICI');
$cari = (int) tek($pdo, 'SELECT TOP 1 CARI_ID FROM LM_CARI_HAREKET GROUP BY CARI_ID ORDER BY COUNT(*) DESC');
$cariKod = (string) tek($pdo, "SELECT KOD FROM LM_CARI WHERE ID = {$cari}");
$stok = (int) tek($pdo, 'SELECT TOP 1 STOK_ID FROM LM_STOK_HAREKET GROUP BY STOK_ID ORDER BY COUNT(*) DESC');
$stokKod = (string) tek($pdo, "SELECT KOD FROM LM_STOK WHERE ID = {$stok}");
$fatura = (int) tek($pdo, 'SELECT MAX(ID) FROM LM_BELGE WHERE TUR = 3');
$siparis = (int) tek($pdo, 'SELECT MAX(ID) FROM LM_BELGE WHERE TUR = 1');
$bas = date('Y-01-01');

$sayfalar = [
    'Ana sayfa' => 'index.php',
    'Cari arama (unvan)' => 'cari/cari.php?cari=' . rawurlencode('Yılmaz Ticaret'),
    'Cari otomatik tamamlama' => 'cari/ajax_cari_search.php?term=' . rawurlencode('öztürk'),
    'Bakiye listesi (5.000 eşleşme)' => 'cari/lg_bakiye.php?q=' . rawurlencode('Ticaret'),
    'Cari ekstresi (en kalabalık cari)' => "cari/lg_hareket.php?cariid={$cari}",
    'Cari ekstresi PDF' => "cari/lg_hareket_ekstre_pdf.php?cariid={$cari}",
    'Cari ekstresi Excel' => "cari/lg_hareket_ekstre_excel.php?cariid={$cari}",
    'Cari kartı' => "kart/cari.php?id={$cari}",
    'Stok arama' => ['stok/urun_arama_ajax.php', ['barkod' => 'Tabak model']],
    'Belge ürün arama' => 'belge/stok_ara.php?yon=1&q=' . rawurlencode('Kase'),
    'Stok kartı (en hareketli ürün)' => "kart/stok.php?id={$stok}",
    'Belge listesi (yıl)' => "belge/index.php?bas={$bas}",
    'Fatura ekranı' => "belge/goster.php?id={$fatura}",
    'Fatura PDF' => "belge/yazdir.php?id={$fatura}&pdf=1",
    'Sipariş ekranı' => "siparis/lg_fis.php?stokhareket={$siparis}",
    'Tüm siparişler' => 'siparis/lg_tumsiparisler.php',
    'Kasa ve banka (yıl)' => "finans/index.php?bas={$bas}",
    'Rapor: günlük işlemler' => 'rapor/gunluk_islemler.php',
    'Rapor: aylık satış' => 'rapor/rapor_satis_aylik.php',
    'Rapor: müşteri ligi' => 'rapor/rapor_musteriler_top.php',
    'Rapor: ürün ligi' => 'rapor/rapor_urun_performans.php',
    'Rapor: ürün ligi Excel (20.000 ürün)' => 'rapor/rapor_urun_performans_excel_xlsx.php',
    'Rapor: müşteri ligi Excel' => 'rapor/rapor_musteriler_top_excel_xlsx.php',
    'Rapor: yaşlandırma' => 'rapor/rapor_cari_yaslandirma.php',
    'Rapor: tahsilat radarı' => 'rapor/rapor_cari_borc_alacak.php',
    'Rapor: nakit akışı' => 'rapor/rapor_nakit_akis.php',
    'Rapor: kasa' => 'rapor/rapor_kasa.php',
    'Rapor: stok izleme' => 'rapor/rapor_stok_izleme.php',
    'Rapor: yıllık özet' => 'rapor/rapor_yillik_ozet.php',
];
// Sınırlar: ekran 2/5 sn; indirilen PDF/Excel 5/15 sn; toplu işlem (yedek, Excel aktarma) 10/30 sn
$sinir = static fn(string $ad): array => match (true) {
    str_starts_with($ad, 'Yedek') || str_starts_with($ad, 'Excel') || str_starts_with($ad, 'Toplu') => [10, 30],
    str_contains($ad, 'PDF') || str_contains($ad, 'Excel') => [5, 15],
    default => [2, 5],
};
$sonuc = [];
$olc = static function (callable $is): array {
    $sureler = [];
    $kod = 0;
    for ($i = 0; $i < 3; $i++) {
        $t = microtime(true);
        $kod = max($kod, $is());   // üç denemeden biri bile hatalıysa hata sayılır
        $sureler[] = microtime(true) - $t;
    }
    sort($sureler);
    return [$sureler[1], $kod];
};
echo "\n";
foreach ($sayfalar as $ad => $yol) {
    [$sure, $kod] = $olc(static function () use ($y, $yol): int {
        $r = is_array($yol) ? $y->istek($yol[0], $yol[1]) : $y->istek($yol);
        // Excel/PDF çıktısı gerçekten dosya olmalı (hata sayfası 200 dönse de yakalansın)
        if (!is_array($yol) && preg_match('/(excel|pdf)/', $yol) && !preg_match('/^(PK|%PDF)/', $r['govde'])) {
            return 500;
        }
        return $r['kod'];
    });
    $sonuc[] = [$ad, $sure, $kod];
    printf("  %-36s %6.2f sn  HTTP %d %s\n", $ad, $sure, $kod, $sure >= $sinir($ad)[1] ? '✗ çok yavaş' : ($sure >= $sinir($ad)[0] ? '! yavaş' : ''));
}

// Yedekleme (tüm veritabanı) ve Excel'den 5.000 cari aktarma (önizleme + onay)
$t = microtime(true);
$r = $y->istek('ayar/sistem_yedek.php', ['csrf_token' => $y->belirtec('ayar/sistem_yedek.php'), 'islem' => 'yedek_al']);
$sure = microtime(true) - $t;
preg_match('/\?indir=(lumen_[0-9_]+_elle_[a-f0-9]{6}\.lumen\.gz)/', $r['govde'], $m);
$boyut = isset($m[1]) ? (int) @filesize(dirname(__DIR__, 2) . '/database/yedekler/' . $m[1]) : 0;
$sonuc[] = ['Yedek al (tüm veritabanı, ' . round($boyut / 1048576, 1) . ' MB)', $sure, $r['kod']];
printf("  %-36s %6.2f sn  (%0.1f MB)\n", 'Yedek al', $sure, $boyut / 1048576);
$x = new PhpOffice\PhpSpreadsheet\Spreadsheet();
$satirlar = [['Kod', 'Unvan', 'Tür', 'Açılış bakiyesi']];
$ek = (string) random_int(100, 999);
for ($i = 1; $i <= 5000; $i++) {
    $satirlar[] = ["XLP{$ek}." . $i, "Excel performans carisi {$i}", 'Alıcı', $i % 7 === 0 ? 250 : ''];
}
$x->getActiveSheet()->fromArray($satirlar, null, 'A1', true);
$dosya = tempnam(sys_get_temp_dir(), 'perf') . '.xlsx';
(new PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($dosya);
$t = microtime(true);
$g = $y->istekDosya('tanimlar/ice_aktar.php', ['csrf_token' => $y->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'yukle', 'tur' => 'cari', 'tarih' => date('Y-m-d')],
    'dosya', $dosya, 'perf.xlsx');
$on = microtime(true) - $t;
preg_match('/name="anahtar" value="([a-f0-9]+)"/', $g['govde'], $mm);
$t = microtime(true);
$a = $y->istek('tanimlar/ice_aktar.php', ['csrf_token' => $y->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'onayla', 'anahtar' => $mm[1] ?? '']);
$yaz = microtime(true) - $t;
@unlink($dosya);
$sonuc[] = ['Excel 5.000 cari: önizleme', $on, $g['kod']];
$sonuc[] = ['Excel 5.000 cari: aktarma', $yaz, str_contains($a['govde'], '5000 yeni cari') ? 200 : 500];
printf("  %-36s %6.2f sn\n  %-36s %6.2f sn %s\n", 'Excel 5.000 cari: önizleme', $on, 'Excel 5.000 cari: aktarma', $yaz, str_contains($a['govde'], '5000 yeni cari') ? '' : '✗ aktarılmadı');

// Toplu fiyat güncelleme: bütün ürünlerin satış fiyatı %1
$tf = ['tur' => '1', 'grup' => '', 'kod' => '', 'yontem' => 'yuzde', 'deger' => '1', 'yuvarla' => '0', 'tarih' => date('Y-m-d')];
$t = microtime(true);
$r = $y->istek('tanimlar/toplu_fiyat.php', ['csrf_token' => $y->belirtec('tanimlar/toplu_fiyat.php'), 'islem' => 'uygula'] + $tf);
$sure = microtime(true) - $t;
$sonuc[] = ['Toplu fiyat güncelleme (tüm ürünler)', $sure, $r['kod'] === 303 ? 200 : 500];
printf("  %-36s %6.2f sn %s\n", 'Toplu fiyat (tüm ürünler)', $sure, $r['kod'] === 303 ? '' : '✗ uygulanmadı');

$cokYavas = array_filter($sonuc, static fn($s) => $s[1] >= $sinir($s[0])[1] || $s[2] >= 500);
$yavas = array_filter($sonuc, static fn($s) => $s[1] >= $sinir($s[0])[0] && $s[1] < $sinir($s[0])[1]);
printf("\n%d ölçüm: %d çok yavaş/hatalı, %d yavaş\n", count($sonuc), count($cokYavas), count($yavas));
if (isset($o['rapor'])) {
    $md = "## Performans ölçümü\n\n| Ekran / işlem | Süre | Durum |\n|---|---|---|\n";
    foreach ($sonuc as [$ad, $s, $k]) {
        [$iyi, $kotu] = $sinir($ad);
        $md .= sprintf("| %s | %.2f sn | %s |\n", $ad, $s, $k >= 500 ? '❌ HTTP ' . $k : ($s >= $kotu ? '❌ çok yavaş' : ($s >= $iyi ? '⚠️ yavaş' : '✅')));
    }
    file_put_contents((string) $o['rapor'], $md);
}
exit($cokYavas === [] ? 0 : 1);

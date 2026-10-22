<?php

declare(strict_types=1);

/**
 * Belge (fatura, irsaliye, iade) yazdırma şablonu — ekranda yazdırma ve PDF (dompdf)
 * aynı HTML'i kullanır. Yalnız bağımsız mod. Firma bilgileri LM_AYAR'dan (firma_*).
 *
 * Not: Lumen e-Fatura/e-Arşiv düzenlemez; çıktı bilgi/teslim nüshasıdır.
 */

require_once __DIR__ . '/belge_lib.php';

if (!function_exists('lumen_tutar_yaziyla')) {
    /** 2466.5 → "İkibindörtyüzaltmışaltı Türk Lirası Elli Kuruş" (Türkçe fatura geleneği: bitişik). */
    function lumen_tutar_yaziyla(float $tutar, string $pb = 'TRY'): string
    {
        $birler = ['', 'bir', 'iki', 'üç', 'dört', 'beş', 'altı', 'yedi', 'sekiz', 'dokuz'];
        $onlar = ['', 'on', 'yirmi', 'otuz', 'kırk', 'elli', 'altmış', 'yetmiş', 'seksen', 'doksan'];
        $basamak = ['', 'bin', 'milyon', 'milyar', 'trilyon'];
        $ucluk = static function (int $n) use ($birler, $onlar): string {
            $y = intdiv($n, 100);
            $o = intdiv($n % 100, 10);
            $b = $n % 10;
            return ($y > 1 ? $birler[$y] : '') . ($y > 0 ? 'yüz' : '') . $onlar[$o] . $birler[$b];
        };
        $yazi = static function (int $n) use ($ucluk, $basamak): string {
            if ($n === 0) {
                return 'sıfır';
            }
            $parca = [];
            $i = 0;
            while ($n > 0 && $i < count($basamak)) {
                $u = $n % 1000;
                if ($u > 0) {
                    // "bir bin" denmez: 1000 = "bin"
                    $parca[] = ($i === 1 && $u === 1 ? '' : $ucluk($u)) . $basamak[$i];
                }
                $n = intdiv($n, 1000);
                $i++;
            }
            return implode('', array_reverse($parca));
        };
        // Türkçe büyük harf: i → İ, ı → I (mb_strtoupper i'yi I yapar)
        $buyukHarf = static fn(string $s): string => strtr(mb_substr($s, 0, 1), ['i' => 'İ', 'ı' => 'I']) === mb_substr($s, 0, 1)
            ? mb_strtoupper(mb_substr($s, 0, 1), 'UTF-8') . mb_substr($s, 1)
            : strtr(mb_substr($s, 0, 1), ['i' => 'İ', 'ı' => 'I']) . mb_substr($s, 1);
        $tutar = round(abs($tutar), 2);
        $tam = (int) floor($tutar);
        $kurus = (int) round(($tutar - $tam) * 100);
        if ($kurus === 100) {
            $tam++;
            $kurus = 0;
        }
        [$ana, $alt] = match ($pb) {
            'USD' => ['ABD Doları', 'Sent'],
            'EUR' => ['Avro', 'Sent'],
            'GBP' => ['İngiliz Sterlini', 'Peni'],
            default => ['Türk Lirası', 'Kuruş'],
        };
        $metin = $buyukHarf($yazi($tam)) . ' ' . $ana;
        if ($kurus > 0) {
            $metin .= ' ' . $buyukHarf($yazi($kurus)) . ' ' . $alt;
        }
        return $metin;
    }
}

if (!function_exists('lumen_firma_bilgileri')) {
    /** @return array{unvan:string, vergi_dairesi:string, vergi_no:string, adres:string, telefon:string, eposta:string, mersis:string} */
    function lumen_firma_bilgileri(PDO $dbh): array
    {
        $a = $dbh->query("SELECT ANAHTAR, DEGER FROM dbo.LM_AYAR WHERE ANAHTAR LIKE 'firma[_]%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        $d = static fn(string $k): string => trim((string) ($a['firma_' . $k] ?? ''));
        return ['unvan' => $d('unvan') ?: 'Lumen', 'vergi_dairesi' => $d('vergi_dairesi'), 'vergi_no' => $d('vergi_no'),
                'adres' => $d('adres'), 'telefon' => $d('telefon'), 'eposta' => $d('eposta'), 'mersis' => $d('mersis')];
    }
}

if (!function_exists('lumen_belge_yazdir_html')) {
    /**
     * Belgenin yazdırılabilir HTML'i. $pdf: dompdf için (yazı tipi DejaVu, logo gömülü).
     * Belge yoksa null.
     */
    function lumen_belge_yazdir_html(PDO $dbh, int $id, bool $pdf = false, bool $fiyatGoster = true): ?string
    {
        $st = $dbh->prepare("SELECT B.*, C.KOD AS CARI_KOD, C.UNVAN, C.VERGI_NO, C.VERGI_DAIRESI, C.TC_NO, C.ADRES, C.IL, C.ILCE, C.TELEFON,
                                    D.AD AS DEPO_AD, KB.NO AS KAYNAK_NO, KB.TARIH AS KAYNAK_TARIH
                             FROM dbo.LM_BELGE B
                             LEFT JOIN dbo.LM_CARI C ON C.ID = B.CARI_ID
                             LEFT JOIN dbo.LM_DEPO D ON D.ID = B.DEPO_ID
                             LEFT JOIN dbo.LM_BELGE KB ON KB.ID = B.KAYNAK_BELGE_ID
                             WHERE B.ID = :id AND (B.TUR <> 1 OR B.YON = 2)");
        $st->execute([':id' => $id]);
        $b = $st->fetch(PDO::FETCH_ASSOC);
        if (!$b) {
            return null;
        }
        $s = $dbh->prepare("SELECT S.*, ST.KOD, ST.AD, BR.KOD AS BIRIM FROM dbo.LM_BELGE_SATIR S
                            LEFT JOIN dbo.LM_STOK ST ON ST.ID = S.STOK_ID LEFT JOIN dbo.LM_BIRIM BR ON BR.ID = S.BIRIM_ID
                            WHERE S.BELGE_ID = :id ORDER BY S.SIRA");
        $s->execute([':id' => $id]);
        $satirlar = $s->fetchAll(PDO::FETCH_ASSOC);
        $kod = lumen_belge_tur_kodu((int) $b['TUR'], (int) $b['YON']);
        $tur = $kod !== null ? lumen_belge_turleri()[$kod] : ['ad' => 'Belge', 'cari' => 0];
        $irsaliye = (int) $b['TUR'] === 2;
        // Fiyatsız çıktı: irsaliye, sayım ve fiyat görme yetkisi olmayan (depo) kullanıcı
        $fiyatli = $fiyatGoster && !$irsaliye && (int) $b['TUR'] !== 6;
        $firma = lumen_firma_bilgileri($dbh);
        $pb = (string) $b['PARA_BIRIMI'];
        $dovizli = $pb !== 'TRY';

        $h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        // Türkçe büyük harf (PDF kütüphanesi CSS büyük harfinde i → I yapıyordu: "MIKTAR")
        $bh = static fn(string $s): string => htmlspecialchars(mb_strtoupper(strtr($s, ['i' => 'İ', 'ı' => 'I']), 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $tl = static fn(mixed $v): string => number_format((float) $v, 2, ',', '.');
        $mk = static fn(mixed $v): string => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
        $tarih = static fn(?string $t): string => $t ? date('d.m.Y', (int) strtotime($t)) : '';

        // KDV dökümü (oran → matrah, KDV)
        $kdvDokum = [];
        foreach ($satirlar as $r) {
            $o = (string) (float) $r['KDV_ORANI'];
            $kdvDokum[$o] ??= ['matrah' => 0.0, 'kdv' => 0.0];
            $kdvDokum[$o]['matrah'] += (float) $r['KDV_MATRAH'];
            $kdvDokum[$o]['kdv'] += (float) $r['KDV_TUTAR'];
        }
        ksort($kdvDokum, SORT_NUMERIC);

        $logo = '';
        $logoYol = dirname(__DIR__, 2) . '/logo.png';
        if (is_file($logoYol) && filesize($logoYol) < 2_000_000) {
            $logo = '<img class="logo" alt="" src="' . ($pdf ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($logoYol)) : '../logo.png') . '">';
        }
        $cariAdres = trim(implode(' ', array_filter([(string) $b['ADRES'], trim($b['ILCE'] . ' / ' . $b['IL'], ' /')])));
        $vergi = static fn(string $vd, string $no): string => implode(' · ', array_filter([
            trim($vd) !== '' ? 'Vergi dairesi: ' . trim($vd) : '', trim($no) !== '' ? (strlen(trim($no)) === 11 ? 'TCKN: ' : 'VKN: ') . trim($no) : '']));

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title><?= $h($tur['ad'] . ' ' . $b['NO']) ?></title>
<style>
    @page { size: A4; margin: 14mm 12mm 16mm 12mm; }
    * { box-sizing: border-box; }
    body { font-family: <?= $pdf ? "'DejaVu Sans', sans-serif" : "'Avenir Next', 'Montserrat', 'Segoe UI', sans-serif" ?>; color: #1f2937; font-size: <?= $pdf ? '8.5pt' : '13px' ?>; margin: 0; }
    .sayfa { max-width: 820px; margin: 0 auto; <?= $pdf ? '' : 'padding: 24px; background: #fff;' ?> }
    table { border-collapse: collapse; width: 100%; }
    .ust td { vertical-align: top; padding: 0; }
    .logo { max-height: 46px; max-width: 200px; margin-bottom: 6px; }
    .firma { font-weight: bold; font-size: 1.25em; color: #6F1022; }
    .kucuk { color: #4b5563; font-size: .9em; line-height: 1.45; }
    .belge-baslik { text-align: right; }
    .belge-baslik .tur { font-size: 1.6em; font-weight: bold; color: #6F1022; letter-spacing: .5px; }
    .bilgi td { padding: 3px 0 3px 10px; font-size: .95em; }
    .bilgi td.e { color: #6b7280; text-align: right; white-space: nowrap; }
    .cizgi { height: 3px; background: #6F1022; margin: 10px 0 12px; }
    .kutu { border: 1px solid #e5e7eb; border-radius: 6px; padding: 9px 11px; }
    .kutu .e { color: #6b7280; font-size: .8em; letter-spacing: .4px; font-weight: bold; margin-bottom: 3px; }
    .kutu .unvan { font-weight: bold; font-size: 1.1em; }
    .satirlar { margin-top: 12px; }
    .satirlar th { background: #6F1022; color: #fff; font-size: .82em; letter-spacing: .3px; padding: 6px 6px; text-align: left; }
    .satirlar td { padding: 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
    .satirlar tr:nth-child(even) td { background: #fafafa; }
    .sag, .satirlar th.sag { text-align: right; } .orta { text-align: center; } .nw { white-space: nowrap; }
    .alt td { vertical-align: top; padding: 0; }
    .toplam td { padding: 4px 8px; }
    .toplam .genel td { font-weight: bold; font-size: 1.15em; color: #6F1022; border-top: 2px solid #6F1022; padding-top: 7px; }
    .dokum th { font-size: .8em; color: #6b7280; text-align: right; padding: 3px 6px; border-bottom: 1px solid #e5e7eb; }
    .dokum th:first-child, .dokum td:first-child { text-align: left; }
    .dokum td { padding: 3px 6px; text-align: right; }
    .yazi { margin-top: 10px; font-size: .95em; }
    .imza td { width: 50%; padding: 30px 10px 0; text-align: center; color: #6b7280; }
    .imza .cizgi-i { border-top: 1px solid #9ca3af; padding-top: 4px; margin: 0 30px; }
    .not { margin-top: 18px; color: #9ca3af; font-size: .78em; text-align: center; }
    .iptal { color: #b91c1c; border: 2px solid #b91c1c; display: inline-block; padding: 2px 10px; font-weight: bold; margin-top: 4px; }
    <?php if (!$pdf): ?>
    body { background: #f3f4f6; }
    .arac { max-width: 820px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
    .arac a, .arac button { font: inherit; font-weight: 600; font-size: 13px; padding: 8px 14px; border-radius: 9px; border: 1px solid #e5e7eb; background: #fff; color: #1f2937; cursor: pointer; text-decoration: none; }
    .arac .birincil { background: #6F1022; color: #fff; border-color: #6F1022; }
    @media print { body { background: #fff; } .arac { display: none; } .sayfa { padding: 0; } }
    @media (max-width: 640px) { .sayfa { padding: 12px; } .satirlar .gizle-m { display: none; } }
    <?php endif; ?>
</style>
</head>
<body>
<?php if (!$pdf): ?>
<div class="arac">
    <a href="goster.php?id=<?= (int) $b['ID'] ?>">Geri</a>
    <a href="yazdir.php?id=<?= (int) $b['ID'] ?>&amp;pdf=1">PDF indir</a>
    <button type="button" class="birincil" onclick="window.print()">Yazdır</button>
</div>
<?php endif; ?>
<div class="sayfa">
    <table class="ust"><tr>
        <td style="width:55%">
            <?= $logo ?>
            <div class="firma"><?= $h($firma['unvan']) ?></div>
            <div class="kucuk">
                <?php if ($firma['adres'] !== ''): ?><?= $h($firma['adres']) ?><br><?php endif; ?>
                <?php if ($firma['vergi_dairesi'] !== '' || $firma['vergi_no'] !== ''): ?><?= $h($vergi($firma['vergi_dairesi'], $firma['vergi_no'])) ?><br><?php endif; ?>
                <?php if ($firma['mersis'] !== ''): ?>MERSİS: <?= $h($firma['mersis']) ?><br><?php endif; ?>
                <?= $h(implode(' · ', array_filter([$firma['telefon'], $firma['eposta']]))) ?>
            </div>
        </td>
        <td class="belge-baslik">
            <div class="tur"><?= $bh($tur['ad']) ?></div>
            <?php if ((int) $b['DURUM'] === 9): ?><div class="iptal">İPTAL EDİLDİ</div><?php endif; ?>
            <table class="bilgi" style="width:auto; margin-left:auto; margin-top:6px">
                <tr><td class="e"><?= $bh('Belge no') ?></td><td><b><?= $h($b['NO']) ?></b></td></tr>
                <tr><td class="e"><?= $bh('Tarih') ?></td><td><?= $h($tarih($b['TARIH'])) ?></td></tr>
                <?php if ($b['VADE']): ?><tr><td class="e"><?= $bh('Vade') ?></td><td><?= $h($tarih($b['VADE'])) ?></td></tr><?php endif; ?>
                <?php if ($b['DOKUMAN_NO']): ?><tr><td class="e"><?= $bh('Dış belge no') ?></td><td><?= $h($b['DOKUMAN_NO']) ?></td></tr><?php endif; ?>
                <?php if ($b['KAYNAK_NO']): ?><tr><td class="e"><?= $bh('Sipariş') ?></td><td><?= $h($b['KAYNAK_NO'] . ' · ' . $tarih($b['KAYNAK_TARIH'])) ?></td></tr><?php endif; ?>
                <?php if ($dovizli): ?><tr><td class="e"><?= $bh('Kur') ?></td><td>1 <?= $h($pb) ?> = <?= $h(number_format((float) $b['KUR'], 4, ',', '.')) ?> TL</td></tr><?php endif; ?>
            </table>
        </td>
    </tr></table>
    <div class="cizgi"></div>

    <?php if ($b['CARI_ID']): ?>
    <div class="kutu">
        <div class="e"><?= $bh((int) $tur['cari'] < 0 || (int) $b['YON'] === 2 ? 'Satıcı / Tedarikçi' : 'Sayın') ?></div>
        <div class="unvan"><?= $h($b['UNVAN']) ?></div>
        <div class="kucuk">
            <?php if ($cariAdres !== ''): ?><?= $h($cariAdres) ?><br><?php endif; ?>
            <?php $cv = $vergi((string) $b['VERGI_DAIRESI'], (string) ($b['VERGI_NO'] ?: $b['TC_NO'])); if ($cv !== ''): ?><?= $h($cv) ?><br><?php endif; ?>
            <?= $h(implode(' · ', array_filter([(string) $b['TELEFON'], 'Cari kodu: ' . $b['CARI_KOD']]))) ?>
        </div>
    </div>
    <?php endif; ?>

    <table class="satirlar">
        <thead><tr>
            <th style="width:4%">#</th><th><?= $bh('Ürün / hizmet') ?></th><th class="sag nw" style="width:12%"><?= $bh('Miktar') ?></th>
            <?php if ($fiyatli): ?>
                <th class="sag nw" style="width:14%"><?= $bh('Birim fiyat') ?></th><th class="sag gizle-m" style="width:6%"><?= $bh('İsk.') ?></th><th class="sag gizle-m" style="width:6%"><?= $bh('KDV') ?></th><th class="sag nw" style="width:14%"><?= $bh('Tutar') ?></th>
            <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($satirlar as $r):
            $dovizSatir = $dovizli && $r['DOVIZ_FIYAT'] !== null; ?>
            <tr>
                <td><?= (int) $r['SIRA'] ?></td>
                <td><b><?= $h($r['KOD']) ?></b> <?= $h($r['AD']) ?><?php if ($r['ACIKLAMA']): ?><div class="kucuk"><?= $h($r['ACIKLAMA']) ?></div><?php endif; ?></td>
                <td class="sag nw"><?= $h($mk($r['MIKTAR'])) ?> <?= $h($r['BIRIM'] ?? '') ?></td>
                <?php if ($fiyatli): ?>
                    <td class="sag nw"><?= $dovizSatir ? $h($tl($r['DOVIZ_FIYAT']) . ' ' . $pb) . '<div class="kucuk">' . $h($tl($r['FIYAT'])) . ' TL</div>' : $h($tl($r['FIYAT'])) ?></td>
                    <td class="sag gizle-m"><?= (float) $r['ISKONTO_ORANI'] > 0 ? '%' . $h($mk($r['ISKONTO_ORANI'])) : '' ?></td>
                    <td class="sag gizle-m">%<?= $h($mk($r['KDV_ORANI'])) ?></td>
                    <td class="sag nw"><?= $h($tl($r['NET'])) ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($fiyatli): ?>
    <table class="alt" style="margin-top:12px"><tr>
        <td style="width:52%; padding-right:16px">
            <table class="dokum">
                <tr><th><?= $bh('KDV oranı') ?></th><th><?= $bh('Matrah') ?></th><th><?= $bh('KDV') ?></th></tr>
                <?php foreach ($kdvDokum as $o => $d): ?>
                    <tr><td>%<?= $h($mk($o)) ?></td><td><?= $h($tl($d['matrah'])) ?></td><td><?= $h($tl($d['kdv'])) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <div class="yazi"><b>Yalnız:</b> <?= $h(lumen_tutar_yaziyla((float) $b['NET'])) ?>
                <?php if ($dovizli && $b['DOVIZ_NET'] !== null): ?><br><span class="kucuk">(<?= $h(lumen_tutar_yaziyla((float) $b['DOVIZ_NET'], $pb)) ?>)</span><?php endif; ?></div>
        </td>
        <td>
            <table class="toplam">
                <tr><td>Brüt tutar</td><td class="sag nw"><?= $h($tl($b['BRUT'])) ?> TL</td></tr>
                <?php if ((float) $b['ISKONTO'] > 0): ?><tr><td>İskonto</td><td class="sag nw">- <?= $h($tl($b['ISKONTO'])) ?> TL</td></tr><?php endif; ?>
                <tr><td>KDV matrahı</td><td class="sag nw"><?= $h($tl((float) $b['BRUT'] - (float) $b['ISKONTO'])) ?> TL</td></tr>
                <tr><td>Hesaplanan KDV</td><td class="sag nw"><?= $h($tl($b['KDV'])) ?> TL</td></tr>
                <tr class="genel"><td>Genel toplam</td><td class="sag nw"><?= $h($tl($b['NET'])) ?> TL</td></tr>
                <?php if ($dovizli && $b['DOVIZ_NET'] !== null): ?>
                    <tr><td>Döviz toplamı</td><td class="sag nw"><b><?= $h($tl($b['DOVIZ_NET']) . ' ' . $pb) ?></b></td></tr>
                <?php endif; ?>
            </table>
        </td>
    </tr></table>
    <?php endif; ?>

    <?php if ($b['ACIKLAMA']): ?><div class="kutu" style="margin-top:12px"><div class="e"><?= $bh('Açıklama') ?></div><?= $h($b['ACIKLAMA']) ?></div><?php endif; ?>

    <?php if ($irsaliye || (int) $b['TUR'] === 3): ?>
    <table class="imza"><tr>
        <td><div class="cizgi-i">Teslim eden</div></td>
        <td><div class="cizgi-i">Teslim alan</div></td>
    </tr></table>
    <?php endif; ?>

    <div class="not">Bu çıktı Lumen ile hazırlanmıştır; e-Fatura / e-Arşiv belgesi değildir.</div>
</div>
</body>
</html>
<?php
        return (string) ob_get_clean();
    }
}

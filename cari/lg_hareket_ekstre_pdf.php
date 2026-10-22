<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
// Cari finans bilgisi (bakiye/ekstre/fatura) yalnız bakiye ya da ekstre yetkisiyle görülür
// (adres doğrudan yazılarak da açılmasın; menüdeki "Müşteri Bakiye" kuralıyla aynı aile).
if (!m_p_cari_finans_erisim_var_mi($terminalkullanici)) {
    lumen_hata_sayfasi('Yetkiniz yok', 'Cari bakiye ve ekstre için Müşteri Bakiye ya da Cari Bakiye/Ekstre yetkisi gerekir.', 403, 'fa-lock');
}
ob_start();

include_once __DIR__ . '/../ayr.php';
include_once __DIR__ . '/../log_ip.php';

require __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$CARIID = (int) ($_GET['cariid'] ?? 0);
if ($CARIID <= 0) {
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    lumen_hata_sayfasi('Cari seçilmedi', 'Ekstre için cari bilgisi eksik. Cari listesinden tekrar açın.');
}

$donemSecim = isset($_GET['donem']) ? (string) $_GET['donem'] : 'aktif';
if (!in_array($donemSecim, ['aktif', 'onceki', 'tumu'], true)) {
    $donemSecim = 'aktif';
}

if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $CARIID, 'M4')) {
    ob_end_clean();
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

try {
// Cari, resmi bakiye ve hareketler ekranla aynı kaynaktan (cari/cari_ekstre_lib.php), iki modda.
require_once __DIR__ . '/cari_ekstre_lib.php';
$cari = cari_ekstre_cari($dbh, (int) $CARIID) ?: ['CODE' => '', 'DEFINITION_' => 'Bilinmeyen Cari'];
$resmi_bakiye = cari_ekstre_resmi_bakiye($dbh, (int) $CARIID);
$hareketler = [];
$toplam_borc = 0.0;
$toplam_alacak = 0.0;
foreach (cari_ekstre_hareketleri($dbh, (int) $CARIID, (string) $donemSecim) as $h) {
    $h['TRCODE'] = cari_islem_etiketi($h['TRCODE_NO'] !== null ? (int) $h['TRCODE_NO'] : null);   // DejaVu Sans Türkçe harfleri basar
    $hareketler[] = $h;
    $toplam_borc += (float) $h['BORC'];
    $toplam_alacak += (float) $h['ALACAK'];
}
// Çok hareketli caride PDF kütüphanesi belleği aşıyordu (1.000 satır 256 MB): PDF'e son
// $pdfEnFazlaSatir hareket girer, öncekiler "Devir" satırında toplanır (bakiye yine doğru).
// Tamamı ekranda ve Excel çıktısında.
$pdfEnFazlaSatir = 500;
$kesilenHareket = max(0, count($hareketler) - $pdfEnFazlaSatir);
if ($kesilenHareket > 0) {
    $hareketler = array_slice($hareketler, -$pdfEnFazlaSatir);
    $toplam_borc = array_sum(array_map(static fn($h) => (float) $h['BORC'], $hareketler));
    $toplam_alacak = array_sum(array_map(static fn($h) => (float) $h['ALACAK'], $hareketler));
}
lumen_bellek_en_az(256);
@set_time_limit(120);
$islem_net_etki = $toplam_borc - $toplam_alacak;
$baslangic_bakiye = $resmi_bakiye - $islem_net_etki;

$donemEtiket = cari_ekstre_donem_etiketi((string) $donemSecim);

function ekstre_para(float $v): string
{
    return number_format($v, 2, ',', '.');
}

function ekstre_tarih(?string $t): string
{
    if (empty($t)) {
        return '';
    }
    $ts = strtotime($t);
    return $ts ? date('d.m.Y', $ts) : (string) $t;
}

$bakiyeMutlak = abs($resmi_bakiye);
$bakiyeDurum = abs($resmi_bakiye) < 0.01 ? '' : ($resmi_bakiye > 0 ? 'Borçlu' : 'Alacaklı');
$bakiyeRenk = $resmi_bakiye > 0 ? '#b91c1c' : ($resmi_bakiye < 0 ? '#059669' : '#374151');

// --- Cari iletisim/adres/vergi (bos alanlar gizlenir) ---
$cariTel = trim((string) ($cari['TELNRS1'] ?? '')) ?: trim((string) ($cari['TELNRS2'] ?? ''));
$adresParca = array_filter([
    trim((string) ($cari['ADDR1'] ?? '')),
    trim((string) ($cari['ADDR2'] ?? '')),
    trim((string) ($cari['TOWN'] ?? '')),
    trim((string) ($cari['DISTRICT'] ?? '')),
    trim((string) ($cari['CITY'] ?? '')),
], static fn($p) => $p !== '');
$cariAdres = implode(' ', $adresParca);
$vergiNo = trim((string) ($cari['TAXNR'] ?? ''));
$vergiDairesi = trim((string) ($cari['TAXOFFICE'] ?? ''));
$vergiSatiri = trim($vergiDairesi . ($vergiNo !== '' ? ' - ' . $vergiNo : ''), ' -');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="utf-8">
<title>Cari Hareket Ekstresi</title>
<style>
@page { margin: 16mm 12mm 20mm 12mm; }
* { box-sizing: border-box; }
body { font-family: 'DejaVu Sans', sans-serif; color: #1f2937; font-size: 9pt; margin: 0; }

/* ===== HEADER ===== */
table.header { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
table.header > tbody > tr > td { vertical-align: middle; padding: 0; }
.logo-box {
    width: 42px; height: 42px; background: #b91c1c; color: #fff;
    text-align: center; font-size: 16pt; font-weight: bold;
    border-radius: 5px;
}
.firma-ad { font-size: 14pt; font-weight: bold; color: #7f1212; }
.h-right { text-align: right; }
.ekstre-baslik { font-size: 13pt; font-weight: bold; color: #b91c1c; }
.ekstre-meta { font-size: 8pt; color: #6b7280; margin-top: 2px; line-height: 1.5; }
.kirmizi-cizgi { height: 3px; background: #b91c1c; margin: 0 0 10px 0; }

/* ===== CARI BILGI ===== */
table.info { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
table.info td {
    border: 1px solid #e5e7eb; padding: 6px 9px; font-size: 8.5pt; vertical-align: top;
}
table.info td.label {
    background: #fdecec; font-weight: bold; width: 13%; color: #7f1212; white-space: nowrap;
}
.cari-unvan { font-size: 10pt; font-weight: bold; color: #1f2937; }

/* ===== OZET METRIK ===== */
table.summary { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
table.summary td {
    border: 1px solid #e5e7eb; padding: 8px 6px; text-align: center;
    background: #f8fafc; width: 25%;
}
table.summary td.bakiye-cell { background: #fef2f2; border: 1px solid #fca5a5; }
.summary .lbl {
    font-size: 7.5pt; color: #6b7280; letter-spacing: 0.4px;
    font-weight: bold; display: block;
}
.summary .val { font-size: 11pt; font-weight: bold; display: block; margin-top: 3px; color: #374151; }
.summary .val.giris { color: #059669; }
.summary .val.cikis { color: #6F1022; }
.summary td.bakiye-cell .lbl { color: #7f1212; }
.summary td.bakiye-cell .val { font-size: 14pt; color: <?php echo $bakiyeRenk; ?>; }
.summary td.bakiye-cell .durum { font-size: 7.5pt; color: #7f1212; font-weight: bold; display: block; }

/* ===== HAREKET TABLOSU ===== */
table.hareket { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
table.hareket thead th {
    background: #7f1212; color: #fff; padding: 7px 6px;
    font-size: 8pt; font-weight: bold; border: 1px solid #5e0d0d; text-align: left;
}
table.hareket thead th.right { text-align: right; }
table.hareket thead th.center { text-align: center; }
table.hareket tbody td {
    border: 1px solid #e5e7eb; padding: 5px 6px; font-size: 8.5pt; vertical-align: top;
}
table.hareket tbody tr.zebra td { background: #fafafa; }
table.hareket tbody tr.devir td { background: #f3f4f6; font-style: italic; color: #6b7280; }
.right { text-align: right; }
.center { text-align: center; }
.giris { color: #059669; font-weight: bold; }
.cikis { color: #6F1022; font-weight: bold; }
.vade { color: #b45309; }
.bakiye { font-weight: bold; }
.bakiye.poz { color: #059669; }
.bakiye.neg { color: #6F1022; }
.muted { color: #9ca3af; }

/* ===== TOPLAM ===== */
table.toplam { width: 100%; border-collapse: collapse; margin-top: 0; }
table.toplam td {
    background: #b91c1c; color: #fff; padding: 8px 10px;
    font-weight: bold; font-size: 9.5pt; border: 1px solid #7f1212;
}
table.toplam td.right { text-align: right; }

.bos { text-align: center; padding: 30px 10px; color: #9ca3af; font-style: italic; font-size: 10pt; border: 1px dashed #cfd8dc; }

/* ===== FOOTER ===== */
table.footer-wrap { width: 100%; border-collapse: collapse; margin-top: 22px; }
table.footer-wrap td { vertical-align: bottom; font-size: 7.5pt; color: #9ca3af; }
.imza-box { text-align: center; }
.imza-line { border-top: 1px solid #9ca3af; width: 150px; padding-top: 3px; font-size: 8pt; color: #6b7280; }

.col-tarih { width: 9%; }
.col-tip { width: 19%; }
.col-acik { width: 24%; }
.col-vade { width: 9%; }
.col-num { width: 13%; }
</style>
</head>
<body>

<table class="header">
    <tr>
        <td class="h-right">
            <div class="ekstre-baslik">Cari Hesap Ekstresi</div>
            <div class="ekstre-meta">
                Dönem: <?php echo htmlspecialchars($donemEtiket, ENT_QUOTES, 'UTF-8'); ?><br>
                Rapor Tarihi: <?php echo date('d.m.Y H:i'); ?>
            </div>
        </td>
    </tr>
</table>
<div class="kirmizi-cizgi"></div>

<table class="info">
    <tr>
        <td class="label">Cari Unvan</td>
        <td colspan="3"><span class="cari-unvan"><?php echo htmlspecialchars((string) $cari['DEFINITION_'], ENT_QUOTES, 'UTF-8'); ?></span></td>
    </tr>
    <tr>
        <td class="label">Cari Kod</td>
        <td><?php echo htmlspecialchars((string) $cari['CODE'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td class="label">Telefon</td>
        <td><?php echo $cariTel !== '' ? htmlspecialchars($cariTel, ENT_QUOTES, 'UTF-8') : '<span class="muted">-</span>'; ?></td>
    </tr>
    <?php if ($cariAdres !== '' || $vergiSatiri !== ''): ?>
    <tr>
        <td class="label">Adres</td>
        <td><?php echo $cariAdres !== '' ? htmlspecialchars($cariAdres, ENT_QUOTES, 'UTF-8') : '<span class="muted">-</span>'; ?></td>
        <td class="label">Vergi D./No</td>
        <td><?php echo $vergiSatiri !== '' ? htmlspecialchars($vergiSatiri, ENT_QUOTES, 'UTF-8') : '<span class="muted">-</span>'; ?></td>
    </tr>
    <?php endif; ?>
</table>

<table class="summary">
    <tr>
        <td>
            <span class="lbl">ÖNCEKİ DÖNEM DEVRİ</span>
            <span class="val"><?php echo ekstre_para($baslangic_bakiye); ?> TL</span>
        </td>
        <td>
            <span class="lbl">TOPLAM ÇIKIŞ (BORÇ)</span>
            <span class="val cikis"><?php echo ekstre_para($toplam_borc); ?> TL</span>
        </td>
        <td>
            <span class="lbl">TOPLAM GİRİŞ (ALACAK)</span>
            <span class="val giris"><?php echo ekstre_para($toplam_alacak); ?> TL</span>
        </td>
        <td class="bakiye-cell">
            <span class="lbl">GÜNCEL BAKİYE</span>
            <span class="val"><?php echo ekstre_para($bakiyeMutlak); ?> TL</span>
            <?php if ($bakiyeDurum !== ''): ?><span class="durum">(<?php echo $bakiyeDurum; ?>)</span><?php endif; ?>
        </td>
    </tr>
</table>

<?php if ($kesilenHareket > 0): ?>
<p style="font-size:7.5pt;color:#6b7280;margin:0 0 6px 0;">Bu PDF son <?php echo $pdfEnFazlaSatir; ?> hareketi içerir; önceki <?php echo number_format($kesilenHareket, 0, ',', '.'); ?> hareket "Devir" satırına dahildir. Tamamı için Excel çıktısını kullanın.</p>
<?php endif; ?>
<table class="hareket">
    <thead>
        <tr>
            <th class="col-tarih center">Tarih</th>
            <th class="col-tip">İşlem Türü</th>
            <th class="col-acik">Açıklama</th>
            <th class="col-vade center">Vade</th>
            <th class="right col-num">Borç</th>
            <th class="right col-num">Alacak</th>
            <th class="right col-num">Bakiye</th>
        </tr>
    </thead>
    <tbody>
<?php
if (empty($hareketler) && abs($baslangic_bakiye) <= 0.01) {
    echo '<tr><td colspan="7" class="bos">Bu cari için görüntülenecek hareket bulunamadı.</td></tr>';
} else {
    if (abs($baslangic_bakiye) > 0.01) {
        $basClass = $baslangic_bakiye >= 0 ? 'poz' : 'neg';
        echo '<tr class="devir">';
        echo '<td class="center">-</td>';
        echo '<td>Önceki Dönem Bakiyesi</td>';
        echo '<td>Devir</td>';
        echo '<td class="center">-</td>';
        echo '<td class="right muted">-</td>';
        echo '<td class="right muted">-</td>';
        echo '<td class="right bakiye ' . $basClass . '">' . ekstre_para($baslangic_bakiye) . ' TL</td>';
        echo '</tr>';
    }

    $bakiye = $baslangic_bakiye;
    $idx = 0;
    foreach ($hareketler as $h) {
        $borc = (float) $h['BORC'];
        $alacak = (float) $h['ALACAK'];
        if ($borc > 0)   { $bakiye += $borc; }
        if ($alacak > 0) { $bakiye -= $alacak; }

        $bakiyeCls = $bakiye >= 0 ? 'poz' : 'neg';
        $rowCls = ($idx % 2 === 1) ? 'zebra' : '';
        $vadeStr = ekstre_tarih($h['VADE'] ?? null);
        $idx++;

        echo '<tr' . ($rowCls !== '' ? ' class="' . $rowCls . '"' : '') . '>';
        echo '<td class="center">' . htmlspecialchars(ekstre_tarih((string) $h['DATE_']), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) ($h['TRCODE'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars((string) ($h['ACIKLAMA'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="center vade">' . ($vadeStr !== '' ? htmlspecialchars($vadeStr, ENT_QUOTES, 'UTF-8') : '<span class="muted">-</span>') . '</td>';
        if ($borc > 0) {
            echo '<td class="right cikis">' . ekstre_para($borc) . ' TL</td>';
        } else {
            echo '<td class="right muted">-</td>';
        }
        if ($alacak > 0) {
            echo '<td class="right giris">' . ekstre_para($alacak) . ' TL</td>';
        } else {
            echo '<td class="right muted">-</td>';
        }
        echo '<td class="right bakiye ' . $bakiyeCls . '">' . ekstre_para($bakiye) . ' TL</td>';
        echo '</tr>';
    }
}
?>
    </tbody>
</table>

<table class="toplam">
    <tr>
        <td>GENEL TOPLAM</td>
        <td class="right">Borç: <?php echo ekstre_para($toplam_borc); ?> TL</td>
        <td class="right">Alacak: <?php echo ekstre_para($toplam_alacak); ?> TL</td>
        <td class="right">Bakiye: <?php echo ekstre_para($bakiyeMutlak); ?> TL <?php echo $bakiyeDurum !== '' ? '(' . $bakiyeDurum . ')' : ''; ?></td>
    </tr>
</table>

<table class="footer-wrap">
    <tr>
        <td>Bu ekstre <?php echo date('d.m.Y H:i'); ?> tarihinde oluşturulmuştur.</td>
        <td class="imza-box"><div class="imza-line">Kase / Imza</div></td>
    </tr>
</table>

</body>
</html>
<?php
$html = ob_get_contents();
ob_clean();

// Dosya adı
$temizUnvan = preg_replace('/[^a-zA-Z0-9_-]/u', '_', (string) ($cari['DEFINITION_'] ?? 'Cari'));
$temizUnvan = preg_replace('/_+/', '_', (string) $temizUnvan);
$temizUnvan = trim((string) $temizUnvan, '_');
if ($temizUnvan === '') {
    $temizUnvan = 'Cari';
}
$dosyaAdi = 'Cari_Hareket_Ekstresi_' . $temizUnvan . '_' . date('Ymd_His') . '.pdf';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

// Her sayfanin altina sayfa numarasi (X / Y) - opsiyonel; hata olsa bile PDF uretilsin
try {
    $canvas = $dompdf->getCanvas();
    $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    if ($canvas && $font) {
        $canvas->page_text(
            $canvas->get_width() - 95,
            $canvas->get_height() - 24,
            'Sayfa {PAGE_NUM} / {PAGE_COUNT}',
            $font,
            7,
            [0.6, 0.6, 0.6]
        );
    }
} catch (Throwable $eSayfa) {
    error_log('[lg_hareket_ekstre_pdf] sayfa no: ' . $eSayfa->getMessage());
}

ob_end_clean();
$dompdf->stream($dosyaAdi, ['Attachment' => false]);
exit;
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('[lg_hareket_ekstre_pdf] hata: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Ekstre olusturulurken hata olustu.');
}

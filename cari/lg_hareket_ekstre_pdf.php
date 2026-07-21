<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
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
    die('Hata: Gecersiz cari id.');
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
// Cari bilgileri (iletisim + vergi alanlari dahil)
$stmtCari = $dbh->prepare("SELECT CODE, DEFINITION_, TELNRS1, TELNRS2, ADDR1, ADDR2, TOWN, DISTRICT, CITY, TAXOFFICE, TAXNR
    FROM {$firma}CLCARD WHERE LOGICALREF = :cariid");
$stmtCari->execute([':cariid' => $CARIID]);
$cari = $stmtCari->fetch(PDO::FETCH_ASSOC) ?: ['CODE' => '', 'DEFINITION_' => 'Bilinmeyen Cari'];

// Resmi bakiye
$stmtResmi = $dbh->prepare("SELECT (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE
    FROM {$firmadonemx}GNTOTCL G WITH(NOLOCK)
    WHERE G.CARDREF = :cariid AND G.TOTTYP = 1");
$stmtResmi->execute([':cariid' => $CARIID]);
$resmi_bakiye = (float) $stmtResmi->fetchColumn();

$donemPrefixler = match ($donemSecim) {
    'onceki' => [$eskifirmadonem],
    'tumu' => [$firmadonem, $eskifirmadonem],
    default => [$firmadonem],
};

$trcodeCaseExpr = "CASE HAREKET.TRCODE
    WHEN 1 THEN 'Nakit Tahsilat' WHEN 2 THEN 'Nakit Odeme' WHEN 3 THEN 'Borc Dekontu' WHEN 4 THEN 'Alacak Dekontu' WHEN 5 THEN 'Virman Fisi'
    WHEN 6 THEN 'Kur Farki Fisi' WHEN 12 THEN 'Ozel Fis' WHEN 14 THEN 'Acilis Fisi' WHEN 20 THEN 'Gelen Havale' WHEN 21 THEN 'Gonderilen Havale'
    WHEN 24 THEN 'Doviz Alis Belgesi' WHEN 25 THEN 'Doviz Satis belgesi' WHEN 28 THEN 'Alinan Hizmet Faturasi' WHEN 29 THEN 'Verilen Hizmet Faturasi'
    WHEN 31 THEN 'Satin Alma Faturasi' WHEN 32 THEN 'Perakende Satis Iade Faturasi' WHEN 33 THEN 'Toptan Satis Iade Faturasi'
    WHEN 34 THEN 'Alinan Hizmet Faturasi' WHEN 35 THEN 'Alinan Proforma Fatura' WHEN 36 THEN 'Satin Alma Iade Faturasi'
    WHEN 37 THEN 'Perakende Satis Faturasi' WHEN 38 THEN 'Toptan Satis Faturasi' WHEN 39 THEN 'Verilen Hizmet Faturasi'
    WHEN 40 THEN 'Verilen proforma fatura' WHEN 41 THEN 'Verilen Vade Farki Faturasi' WHEN 42 THEN 'Alinan Vade Farki Faturasi'
    WHEN 43 THEN 'Satin Alma Fiyat Farki Faturasi' WHEN 44 THEN 'Satis Fiyat Farki Faturasi' WHEN 45 THEN 'Verilen Serbest Meslek Makbuzu'
    WHEN 46 THEN 'Alinan Serbest Meslek Makbuzu' WHEN 56 THEN 'Mustahsil Makbuzu'
    WHEN 61 THEN 'Cek Girisi' WHEN 62 THEN 'Senet Girisi'
    WHEN 63 THEN 'Cek Cikisi (Cari Hesaba)' WHEN 64 THEN 'Senet Cikisi (Cari Hesaba)'
    WHEN 70 THEN 'Kredi Karti Fisi' WHEN 71 THEN 'Kredi Karti Iade Fisi' WHEN 72 THEN 'Firma Kredi Karti Fisi' WHEN 73 THEN 'Firma Kredi Karti Iade Fisi'
    WHEN 81 THEN 'Satinalma Siparisi' WHEN 82 THEN 'Satis Siparisi'
  END";

$cariIdSafe = (int) $CARIID;
$queryParts = [];
foreach ($donemPrefixler as $prefix) {
    $acilisFiltresi = ($donemSecim === 'tumu') ? ' AND HAREKET.TRCODE <> 14' : '';
    // Vade: yalnizca cek/senet GIRISI (61/62) icin CSCARD.DUEDATE (giriste SOURCEFREF = CSTRANS).
    $queryParts[] = "
    SELECT
      HAREKET.LOGICALREF,
      HAREKET.DATE_,
      HAREKET.TRCODE AS TRCODE_NO,
      {$trcodeCaseExpr} AS TRCODE,
      HAREKET.LINEEXP AS ACIKLAMA,
      ISNULL((1 - HAREKET.SIGN) * HAREKET.AMOUNT, 0) AS BORC,
      ISNULL(HAREKET.SIGN * HAREKET.AMOUNT, 0) AS ALACAK,
      VADE.DUEDATE AS VADE
    FROM {$prefix}CLFLINE AS HAREKET
    OUTER APPLY (
      SELECT TOP 1 CS.DUEDATE
      FROM {$prefix}CSTRANS CT WITH(NOLOCK)
      JOIN {$prefix}CSCARD CS WITH(NOLOCK) ON CS.LOGICALREF = CT.CSREF
      WHERE HAREKET.TRCODE IN (61, 62) AND CT.LOGICALREF = HAREKET.SOURCEFREF
    ) VADE
    WHERE HAREKET.CANCELLED = 0 AND HAREKET.CLIENTREF = {$cariIdSafe}{$acilisFiltresi}";
}

$sql = "SELECT * FROM (" . implode(" UNION ALL ", $queryParts) . ") AS BIRLESIK ORDER BY DATE_ ASC, LOGICALREF ASC";
$stmt = $dbh->prepare($sql);
$stmt->execute();
$hareketler = [];
$toplam_borc = 0.0;
$toplam_alacak = 0.0;
while ($h = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $hareketler[] = $h;
    $toplam_borc += (float) $h['BORC'];
    $toplam_alacak += (float) $h['ALACAK'];
}
$islem_net_etki = $toplam_borc - $toplam_alacak;
$baslangic_bakiye = $resmi_bakiye - $islem_net_etki;

$donemEtiket = match ($donemSecim) {
    'onceki' => '2025 (Onceki Donem)',
    'tumu' => 'Tum Donemler',
    default => '2026 (Aktif Donem)',
};

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
$bakiyeDurum = abs($resmi_bakiye) < 0.01 ? '' : ($resmi_bakiye > 0 ? 'Borclu' : 'Alacakli');
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
    font-size: 7.5pt; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px;
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
                Donem: <?php echo htmlspecialchars($donemEtiket, ENT_QUOTES, 'UTF-8'); ?><br>
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
            <span class="lbl">Onceki Donem Devri</span>
            <span class="val"><?php echo ekstre_para($baslangic_bakiye); ?> TL</span>
        </td>
        <td>
            <span class="lbl">Toplam Cikis (Borc)</span>
            <span class="val cikis"><?php echo ekstre_para($toplam_borc); ?> TL</span>
        </td>
        <td>
            <span class="lbl">Toplam Giris (Alacak)</span>
            <span class="val giris"><?php echo ekstre_para($toplam_alacak); ?> TL</span>
        </td>
        <td class="bakiye-cell">
            <span class="lbl">Guncel Bakiye</span>
            <span class="val"><?php echo ekstre_para($bakiyeMutlak); ?> TL</span>
            <?php if ($bakiyeDurum !== ''): ?><span class="durum">(<?php echo $bakiyeDurum; ?>)</span><?php endif; ?>
        </td>
    </tr>
</table>

<table class="hareket">
    <thead>
        <tr>
            <th class="col-tarih center">Tarih</th>
            <th class="col-tip">Islem Turu</th>
            <th class="col-acik">Aciklama</th>
            <th class="col-vade center">Vade</th>
            <th class="right col-num">Borc</th>
            <th class="right col-num">Alacak</th>
            <th class="right col-num">Bakiye</th>
        </tr>
    </thead>
    <tbody>
<?php
if (empty($hareketler) && abs($baslangic_bakiye) <= 0.01) {
    echo '<tr><td colspan="7" class="bos">Bu cari icin goruntulenecek hareket bulunamadi.</td></tr>';
} else {
    if (abs($baslangic_bakiye) > 0.01) {
        $basClass = $baslangic_bakiye >= 0 ? 'poz' : 'neg';
        echo '<tr class="devir">';
        echo '<td class="center">-</td>';
        echo '<td>Onceki Donem Bakiyesi</td>';
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
        <td class="right">Borc: <?php echo ekstre_para($toplam_borc); ?> TL</td>
        <td class="right">Alacak: <?php echo ekstre_para($toplam_alacak); ?> TL</td>
        <td class="right">Bakiye: <?php echo ekstre_para($bakiyeMutlak); ?> TL <?php echo $bakiyeDurum !== '' ? '(' . $bakiyeDurum . ')' : ''; ?></td>
    </tr>
</table>

<table class="footer-wrap">
    <tr>
        <td>Bu ekstre <?php echo date('d.m.Y H:i'); ?> tarihinde olusturulmustur.</td>
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

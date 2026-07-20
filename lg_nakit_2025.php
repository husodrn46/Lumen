<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/log_ip.php");
include_once(__DIR__ . "/ayr.php");

// 2025 dönemi için eski tabloları kullan
$firmadonem = $eskifirmadonem;

// Parametreler
if (isset($_GET['cari_id']) && isset($_GET['REF'])) {
    $CARIID = (int)$_GET['cari_id'];
    $REF    = (int)$_GET['REF'];
} else {
    die("Parametre eksik.");
}
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $CARIID, 'M4')) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Para format
function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat;
    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0;
    }
    return number_format((float)$kusurat, (int)($parakusurat ?? 2), ',', '.');
}

$mdoviz = "₺";

// KASA SATIRI
$qKs = $dbh->prepare("SELECT * FROM {$firmadonem}KSLINES WHERE LOGICALREF = :ref");
$qKs->execute([':ref' => $REF]) || die("KSLINES hatası: " . print_r($dbh->errorInfo(), true));
$ks = $qKs->fetch(PDO::FETCH_ASSOC);
if (!$ks) {
    die("Bu LOGICALREF için kasa hareketi bulunamadı.");
}

// KASA KARTI
$kasa = false;
$kasaRef = (isset($ks['CARDREF']) && (int)$ks['CARDREF'] > 0) ? (int)$ks['CARDREF'] : 0;

if ($kasaRef > 0) {
    $qKasa = $dbh->prepare("SELECT * FROM {$firma}KSCARD WHERE LOGICALREF = :kasaRef");
    $qKasa->execute([':kasaRef' => $kasaRef]) || die("KSCARD hatası: " . print_r($dbh->errorInfo(), true));
    $kasa = $qKasa->fetch(PDO::FETCH_ASSOC);
}

// İlgili cari satırı (nakit tahsilat/ödeme)
($qCl = $dbh->prepare("
    SELECT *
    FROM {$firmadonem}CLFLINE
    WHERE CLIENTREF  = :cariid
      AND SOURCEFREF = :ref
      AND TRCODE IN (1,2)
      AND CANCELLED = 0
    ORDER BY LOGICALREF DESC
")) || die("CLFLINE hatası: " . print_r($dbh->errorInfo(), true));
$qCl->execute([':cariid' => $CARIID, ':ref' => $REF]) || die("CLFLINE hatası: " . print_r($dbh->errorInfo(), true));
$cl = $qCl->fetch(PDO::FETCH_ASSOC);

// Cari kart
($qCari = $dbh->prepare("
    SELECT DEFINITION_, CITY, TELNRS1
    FROM {$firma}CLCARD
    WHERE LOGICALREF = :cariid
")) || die("CLCARD hatası: " . print_r($dbh->errorInfo(), true));
$qCari->execute([':cariid' => $CARIID]) || die("CLCARD hatası: " . print_r($dbh->errorInfo(), true));
$cari = $qCari->fetch(PDO::FETCH_ASSOC);

// Başlık + tutar + açıklama
$trtext = 'Nakit İşlem';
if ($cl) {
    if ((int)$cl['TRCODE'] === 1) {
        $trtext = 'Nakit Tahsilat';
    }
    if ((int)$cl['TRCODE'] === 2) {
        $trtext = 'Nakit Ödeme';
    }
}
$goster_tutar = $cl ? $cl['AMOUNT'] : ($ks['AMOUNT'] ?? 0);
$aciklama = $cl && !empty($cl['LINEEXP'])
    ? $cl['LINEEXP']
    : ($ks['LINEEXP'] ?? '');

// Kasa adını elde et
$kasaKod = '-';
$kasaAd  = '';
if ($kasa) {
    if (isset($kasa['CODE'])) {
        $kasaKod = $kasa['CODE'];
    }
    if (isset($kasa['DEFINITION_'])) {
        $kasaAd = $kasa['DEFINITION_'];
    } elseif (isset($kasa['DEFINITION'])) {
        $kasaAd = $kasa['DEFINITION'];
    } elseif (isset($kasa['NAME'])) {
        $kasaAd = $kasa['NAME'];
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($trtext); ?> (2025)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { margin:0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background:#f3f4f6; color:#0f172a; }
        .topbar {
            position: sticky; top:0; background:#fff; border-bottom:1px solid #e5e7eb;
            padding:.6rem 1.2rem; display:flex; justify-content:space-between; align-items:center; z-index:50;
        }
        .topbar-title { display: flex; align-items: center; gap: 10px; }
        .donem-badge {
            background-color: #fef3c7;
            color: #92400e;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
        }
        .btn {
            display:inline-flex; align-items:center; gap:.25rem;
            background:#0f766e; color:#fff; border:none; border-radius:.5rem;
            padding:.35rem .7rem; font-size:.75rem; text-decoration:none; cursor:pointer;
        }
        .btn:hover { background:#115e57; color:#fff; }
        .btn.back { background:#e2e8f0; color:#0f172a; }
        .btn.back:hover { background:#cbd5f5; }
        .page-container { max-width: 900px; margin: 1.3rem auto 2.5rem; padding:0 1rem; display:flex; flex-direction:column; gap:1.1rem; }
        .header { display:flex; gap:1rem; align-items:center; }
        .header img { height:44px; }
        .header .title { font-size:1.05rem; font-weight:600; }
        .info-grid {
            display:grid; grid-template-columns: repeat(auto-fit,minmax(160px,1fr));
            gap:.6rem; background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; padding:.75rem .9rem;
        }
        .info-item { display:flex; flex-direction:column; gap:.25rem; }
        .label { font-size:.65rem; text-transform:uppercase; color:#6b7280; letter-spacing:.03em; }
        .val { font-weight:600; font-size:.85rem; }
        .table-box {
            background:#fff; border:1px solid #e5e7eb; border-radius:.75rem; overflow:hidden;
        }
        table { width:100%; border-collapse:collapse; }
        th, td { padding:.45rem .55rem; font-size:.72rem; border-bottom:1px solid #edf2f7; }
        thead { background:#f8fafc; }
        th { text-align:left; }
        .right { text-align:right; }
        @media print {
            .topbar, .btn { display:none !important; }
            body { background:#fff; }
            .page-container { max-width:100%; margin:0; padding:0; }
            .table-box { border:1px solid #000; }
        }
    </style>
</head>
<body>

<div class="topbar">
    <div class="topbar-title">
        <span style="font-weight:600;font-size:.9rem;"><?php echo htmlspecialchars($trtext); ?></span>
        <span class="donem-badge">2025 Dönemi</span>
    </div>
    <div>
        <a href="lg_hareket.php?cariid=<?php echo (int)$CARIID; ?>" class="btn back">← Geri Dön</a>
        <button class="btn" onclick="window.print()">PDF Olarak Kaydet</button>
    </div>
</div>

<div class="page-container">

    <div class="header">
        <img src="logo.png" alt="Lumen">
        <div class="title"><?php echo htmlspecialchars($trtext); ?></div>
    </div>

    <div class="info-grid">
        <div class="info-item">
            <div class="label">Cari</div>
            <div class="val"><?php echo ($cari && isset($cari['DEFINITION_'])) ? htmlspecialchars((string) $cari['DEFINITION_']) : ''; ?></div>
        </div>
        <div class="info-item">
            <div class="label">Tarih</div>
            <div class="val"><?php echo isset($ks['DATE_']) ? tarihcevir($ks['DATE_']) : '-'; ?></div>
        </div>
        <div class="info-item">
            <div class="label">Belge No</div>
            <div class="val"><?php echo isset($ks['FICHENO']) ? htmlspecialchars((string) $ks['FICHENO']) : '-'; ?></div>
        </div>
        <div class="info-item">
            <div class="label">Tutar</div>
            <div class="val"><?php echo paraformat($goster_tutar) . ' ' . $mdoviz; ?></div>
        </div>
        <div class="info-item">
            <div class="label">Kasa</div>
            <div class="val">
                <?php
                if ($kasa) {
                    if ($kasaAd !== '') {
                        echo htmlspecialchars($kasaKod . ' — ' . $kasaAd);
                    } else {
                        echo htmlspecialchars((string) $kasaKod);
                    }
                } else {
                    echo '-';
                }
                ?>
            </div>
        </div>
        <div class="info-item">
            <div class="label">Açıklama</div>
            <div class="val"><?php echo $aciklama !== '' ? htmlspecialchars((string) $aciklama) : '-'; ?></div>
        </div>
    </div>

    <div class="table-box">
        <table>
            <thead>
            <tr>
                <th>Tarih</th>
                <th>İşlem</th>
                <th>Açıklama</th>
                <th class="right">Tutar</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td><?php echo isset($ks['DATE_']) ? tarihcevir($ks['DATE_']) : '-'; ?></td>
                <td><?php echo htmlspecialchars($trtext); ?></td>
                <td><?php echo $aciklama !== '' ? htmlspecialchars((string) $aciklama) : '-'; ?></td>
                <td class="right"><?php echo paraformat($goster_tutar) . ' ' . $mdoviz; ?></td>
            </tr>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>

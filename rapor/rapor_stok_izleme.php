<?php

declare(strict_types=1);

/**
 * rapor_stok_izleme.php — Stok erime / negatif izleme.
 * Son 14 gunde gercek satis vs uretim dengesi (sayim TRCODE 50/51 haric),
 * mevcut stok ve "tahmini kac gun sonra tukenir" ile riskli urunleri one cikarir.
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$gun = isset($_GET['gun']) && is_numeric($_GET['gun']) ? max(7, min(90, (int) $_GET['gun'])) : 14;

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$nf = static fn($x, int $d = 0): string => number_format((float) $x, $d, ',', '.');

// Son N gun: gercek satis (cikis, sayim haric) ve uretim/giris (sayim haric) + mevcut stok
$rows = [];
$urunKosulu = urun_kodu_kosulu('I');
try {
    $sql = "
        SELECT I.CODE, I.NAME,
            ISNULL(ST.ONHAND, 0) AS STOK,
            ISNULL(SH.SATIS, 0)  AS SATIS,
            ISNULL(SH.URETIM, 0) AS URETIM
        FROM {$firma}ITEMS I
        LEFT JOIN (
            SELECT STOCKREF, SUM(ONHAND) AS ONHAND
            FROM {$firmadonemx}STINVTOT WITH(NOLOCK)
            WHERE INVENNO <> -1 GROUP BY STOCKREF
        ) ST ON ST.STOCKREF = I.LOGICALREF
        LEFT JOIN (
            SELECT S.STOCKREF,
                SUM(CASE WHEN S.IOCODE IN (2,4) AND S.TRCODE NOT IN (50,51) THEN S.AMOUNT ELSE 0 END) AS SATIS,
                SUM(CASE WHEN S.IOCODE IN (1,3) AND S.TRCODE NOT IN (50,51) THEN S.AMOUNT ELSE 0 END) AS URETIM
            FROM {$firmadonem}STLINE S WITH(NOLOCK)
            WHERE S.CANCELLED = 0 AND S.DATE_ >= DATEADD(day, -{$gun}, GETDATE())
            GROUP BY S.STOCKREF
        ) SH ON SH.STOCKREF = I.LOGICALREF
        WHERE {$urunKosulu} AND I.ACTIVE = 0
          AND (ISNULL(SH.SATIS,0) > 0 OR ISNULL(ST.ONHAND,0) < 0)
    ";
    $rows = $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('rapor_stok_izleme: ' . $e->getMessage());
}

// Hesap: net erime + tahmini kalan gun
foreach ($rows as &$r) {
    $r['STOK']   = (float) $r['STOK'];
    $r['SATIS']  = (float) $r['SATIS'];
    $r['URETIM'] = (float) $r['URETIM'];
    $r['NET']    = $r['SATIS'] - $r['URETIM']; // pozitif = eriyor
    $gunlukErime = $r['NET'] / $gun;           // gunluk net dususu
    if ($r['STOK'] < 0) {
        $r['KALAN_GUN'] = -1;                  // zaten negatif
    } elseif ($gunlukErime > 0) {
        $r['KALAN_GUN'] = (int) floor($r['STOK'] / $gunlukErime);
    } else {
        $r['KALAN_GUN'] = 9999;                // erime yok
    }
}
unset($r);

// Siralama: once negatif (stok<0), sonra en az kalan gun
usort($rows, static function ($a, $b) {
    if (($a['STOK'] < 0) !== ($b['STOK'] < 0)) {
        return $a['STOK'] < 0 ? -1 : 1;
    }
    return $a['KALAN_GUN'] <=> $b['KALAN_GUN'];
});

$negatifSayi = 0;
$kritikSayi  = 0; // kalan gun < 7 (negatif degil)
foreach ($rows as $r) {
    if ($r['STOK'] < 0) {
        $negatifSayi++;
    } elseif ($r['KALAN_GUN'] < 7) {
        $kritikSayi++;
    }
}
$enHizli = $rows[0] ?? null;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok İzleme — Erime &amp; Negatif</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --text-1:#1f2937; --text-2:#6b7280; --text-3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; --amber:#d97706; --amber-soft:#fffbeb; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; background:var(--bg); color:var(--text-1); font-size:14px; }
        .top-header { position:sticky; top:0; z-index:40; background:rgba(255,255,255,0.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
        .header-inner { max-width:1100px; margin:0 auto; height:60px; display:flex; align-items:center; gap:12px; padding:0 18px; }
        .header-back { display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; border-radius:10px; color:var(--text-2); text-decoration:none; }
        .header-back:hover { background:rgba(0,0,0,0.05); color:var(--red); }
        .header-title { font-size:16px; font-weight:700; display:inline-flex; align-items:center; gap:8px; flex:1; }
        .header-title i { color:var(--red,#ef4444); }
        .gun-sec { padding:8px 12px; border:1px solid var(--border); border-radius:10px; font-family:inherit; font-size:14px; font-weight:600; background:#fff; }
        main { max-width:1100px; margin:0 auto; padding:18px 18px 60px; }
        .ozet { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:18px; }
        .oz { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:15px 17px; box-shadow:0 2px 8px rgba(0,0,0,0.03); }
        .oz .lbl { font-size:11px; color:var(--text-3); text-transform:uppercase; letter-spacing:.4px; font-weight:600; display:flex; align-items:center; gap:6px; }
        .oz .val { font-size:24px; font-weight:800; margin-top:5px; line-height:1; }
        .oz.kirmizi .val { color:var(--red); } .oz.amber .val { color:var(--amber); } .oz.notr .val { color:var(--text-1); }
        .oz .sub { font-size:11.5px; color:var(--text-3); margin-top:4px; }
        .aciklama { background:var(--amber-soft); border:1px solid #fde68a; border-radius:12px; padding:12px 16px; font-size:12.5px; color:#92400e; margin-bottom:16px; display:flex; gap:9px; align-items:flex-start; }
        .aciklama i { margin-top:2px; }
        .liste { background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.03); }
        .liste-bas { padding:13px 16px; font-size:12px; font-weight:700; color:var(--text-2); text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px; }
        .liste-bas i { color:var(--red); }
        table { width:100%; border-collapse:collapse; font-size:13px; }
        thead th { text-align:left; font-size:10.5px; font-weight:700; color:var(--text-2); text-transform:uppercase; letter-spacing:.4px; padding:10px 14px; border-bottom:1px solid var(--border); background:#fafafa; white-space:nowrap; }
        thead th.sag { text-align:right; }
        tbody td { padding:10px 14px; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        tbody tr:hover { background:#fafbff; }
        .kod { font-weight:700; color:var(--text-1); white-space:nowrap; }
        .ad { color:var(--text-2); font-size:12px; max-width:240px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .num.stok-neg { color:var(--red); font-weight:700; }
        .rozet { font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:100px; white-space:nowrap; }
        .rozet.neg { background:var(--red-soft); color:var(--red); }
        .rozet.kritik { background:var(--red-soft); color:var(--red); }
        .rozet.dikkat { background:var(--amber-soft); color:var(--amber); }
        .rozet.ok { background:var(--emerald-soft); color:var(--emerald); }
        .bos { padding:40px 18px; text-align:center; color:var(--text-3); }
        .bos i { font-size:30px; display:block; margin-bottom:10px; opacity:.6; }
        @media (max-width:720px) {
            main { padding:14px 12px 50px; }
            .ozet { grid-template-columns:1fr; gap:10px; }
            .ad { display:none; }
            thead th.gizle-mb, tbody td.gizle-mb { display:none; }
        }
    </style>
</head>
<body>
    <header class="top-header">
        <div class="header-inner">
            <a href="dashboard.php" class="header-back" title="Geri"><i class="fa fa-arrow-left"></i></a>
            <span class="header-title"><i class="fa-solid fa-triangle-exclamation"></i> Stok İzleme</span>
            <form method="get" style="margin:0;">
                <select name="gun" class="gun-sec" onchange="this.form.submit()" aria-label="Donem">
                    <?php foreach ([7,14,30,60] as $g): ?>
                        <option value="<?php echo $g; ?>" <?php echo $g === $gun ? 'selected' : ''; ?>>Son <?php echo $g; ?> gün</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </header>

    <main>
        <div class="ozet">
            <div class="oz kirmizi">
                <div class="lbl"><i class="fa-solid fa-circle-exclamation"></i> Negatif Stok</div>
                <div class="val"><?php echo $nf($negatifSayi); ?></div>
                <div class="sub">stoğu eksiye düşmüş ürün</div>
            </div>
            <div class="oz amber">
                <div class="lbl"><i class="fa-solid fa-hourglass-half"></i> Kritik (&lt; 7 gün)</div>
                <div class="val"><?php echo $nf($kritikSayi); ?></div>
                <div class="sub">7 günden az dayanacak</div>
            </div>
            <div class="oz notr">
                <div class="lbl"><i class="fa-solid fa-bolt"></i> En Hızlı Eriyen</div>
                <div class="val" style="font-size:17px;"><?php echo $enHizli ? $h($enHizli['CODE']) : '-'; ?></div>
                <div class="sub"><?php echo $enHizli ? ('son ' . $gun . ' günde net -' . $nf($enHizli['NET'])) : 'veri yok'; ?></div>
            </div>
        </div>

        <div class="aciklama">
            <i class="fa-solid fa-circle-info"></i>
            <span>"Net erime" = son <?php echo $gun; ?> gün <b>satış − üretim/giriş</b> (sayım hareketleri hariç). Pozitifse stok eriyor.
            "Kalan gün" = mevcut stok ÷ günlük erime hızı; bu tempoyla stoğun ne zaman biteceğinin tahminidir. Sayım sonrası en hızlı eriyenleri buradan erken yakalayabilirsin.</span>
        </div>

        <div class="liste">
            <div class="liste-bas"><i class="fa-solid fa-arrow-trend-down"></i> Eriyen &amp; Negatif Ürünler (<?php echo count($rows); ?>)</div>
            <?php if (empty($rows)): ?>
                <div class="bos"><i class="fa-regular fa-circle-check"></i> Son <?php echo $gun; ?> günde eriyen veya negatif ürün yok.</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table>
                <thead>
                    <tr>
                        <th>Kod</th>
                        <th class="gizle-mb">Ürün</th>
                        <th class="sag">Stok</th>
                        <th class="sag gizle-mb">Satış (<?php echo $gun; ?>g)</th>
                        <th class="sag gizle-mb">Üretim (<?php echo $gun; ?>g)</th>
                        <th class="sag">Net Erime</th>
                        <th class="sag">Durum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $stokNeg = $r['STOK'] < 0;
                        if ($stokNeg) { $rc = 'neg'; $rt = 'NEGATİF'; }
                        elseif ($r['KALAN_GUN'] < 7) { $rc = 'kritik'; $rt = '~' . $r['KALAN_GUN'] . ' gün'; }
                        elseif ($r['KALAN_GUN'] < 21) { $rc = 'dikkat'; $rt = '~' . $r['KALAN_GUN'] . ' gün'; }
                        else { $rc = 'ok'; $rt = $r['KALAN_GUN'] >= 9999 ? 'stabil' : ('~' . $r['KALAN_GUN'] . ' gün'); } ?>
                    <tr>
                        <td class="kod"><?php echo $h($r['CODE']); ?></td>
                        <td class="ad gizle-mb"><?php echo $h($r['NAME']); ?></td>
                        <td class="num <?php echo $stokNeg ? 'stok-neg' : ''; ?>"><?php echo $nf($r['STOK']); ?></td>
                        <td class="num gizle-mb"><?php echo $nf($r['SATIS']); ?></td>
                        <td class="num gizle-mb"><?php echo $nf($r['URETIM']); ?></td>
                        <td class="num" style="<?php echo $r['NET'] > 0 ? 'color:var(--red);font-weight:600;' : 'color:var(--text-3);'; ?>"><?php echo $r['NET'] > 0 ? '-' . $nf($r['NET']) : $nf($r['NET']); ?></td>
                        <td class="num"><span class="rozet <?php echo $rc; ?>"><?php echo $rt; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

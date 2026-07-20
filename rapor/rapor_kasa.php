<?php
declare(strict_types=1);

/**
 * rapor_kasa.php — "Kasa Paneli" (yeniden tasarım 2026-07-07).
 *
 * DÜZELTİLEN VERİ BUG'LARI:
 *  1) Döviz kasaları bu kurulumda 'DOLAR'/'EURO'/'ALTIN' KODLU (eski 'DÖVİZ-%' deseni
 *     hiç eşleşmiyordu) → dolar bakiyesi TL sanılıp toplama katılıyordu.
 *  2) Dış kur API'si (anahtar yok → sabit 32,50 gibi ESKİ kurlar) tamamen KALDIRILDI.
 *     LOGO zaten her hareketin TL karşılığını yazar: döviz kasalarında AMOUNT = hareket
 *     günü TL karşılığı, TRNET = döviz miktarı. TL karşılıklar buradan alınır (gerçek).
 *
 * Yerleşim: bordo krom; vurgular + GRUPLU liste (TL Kasalar pay-dolgulu /
 * Döviz-Kıymet hesapları kendi biriminde + kayıtlı TL karşılığı) + accordion son-30
 * hareket (api/kasa_hareket.php korunur).
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Döviz/kıymet hesabı tespiti: bu kurulumda kod DOLAR/EURO/ALTIN (+eski DÖVİZ-% geri uyum)
function kasaTuru(string $code): array
{
    $c = mb_strtoupper($code, 'UTF-8');
    if (preg_match('/^(DÖVİZ|DOVIZ)-/u', $c)) {
        if (str_contains($c, 'EUR')) { return ['EUR', '€']; }
        if (str_contains($c, 'ALTIN')) { return ['ALTIN', 'br']; }
        return ['USD', '$'];
    }
    if ($c === 'DOLAR') { return ['USD', '$']; }
    if ($c === 'EURO')  { return ['EUR', '€']; }
    if ($c === 'ALTIN') { return ['ALTIN', 'br']; }
    return ['TL', '₺'];
}

// Tek sorgu: kasa başına net AMOUNT (TL karşılığı) + net TRNET (döviz miktarı) + son hareket
$sql = "
SELECT k.LOGICALREF AS CARDREF, k.CODE AS KOD, k.NAME AS AD,
    SUM(CASE WHEN l.SIGN = 0 THEN ISNULL(l.AMOUNT,0) ELSE -ISNULL(l.AMOUNT,0) END) AS NET_TL,
    SUM(CASE WHEN l.SIGN = 0 THEN ISNULL(l.TRNET,0)  ELSE -ISNULL(l.TRNET,0)  END) AS NET_DVZ,
    COUNT(l.LOGICALREF) AS HAREKET,
    MAX(l.DATE_) AS SON_HAREKET
FROM {$firma}KSCARD k
LEFT JOIN {$firmadonem}KSLINES l ON l.CARDREF = k.LOGICALREF AND l.DATE_ <= GETDATE()
WHERE k.ACTIVE = 0
GROUP BY k.LOGICALREF, k.CODE, k.NAME
ORDER BY k.CODE";
$stmt = $dbh->prepare($sql);
$stmt->execute();

$tlKasalar = [];
$dvzKasalar = [];
$toplamTL = 0.0;          // tüm kasaların TL karşılığı (döviz: kayıtlı hareket kurları)
$tlPozToplam = 0.0;       // pay dolgusu için (yalnız pozitif TL kasalar)
$negatifler = ['adet' => 0, 'tutar' => 0.0];
$dvzOzet = [];            // USD/EUR/ALTIN ham toplamlar
$enBuyuk = ['ad' => '-', 'tl' => 0.0];

while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $kod = (string) $r['KOD'];
    [$tur, $sym] = kasaTuru($kod);
    // Kuruş-altı kalıntılar (−0,004 gibi) "0,00 ama kırmızı" tutarsızlığı yaratmasın
    $netTL = round((float) $r['NET_TL'], 2);
    $netDvz = round((float) $r['NET_DVZ'], 2);
    $sonH = $r['SON_HAREKET'] ? date('d.m.Y', strtotime((string) $r['SON_HAREKET'])) : '-';

    $satir = [
        'ref' => (int) $r['CARDREF'], 'kod' => $kod, 'ad' => (string) $r['AD'],
        'tur' => $tur, 'sym' => $sym, 'netTL' => $netTL, 'netDvz' => $netDvz,
        'hareket' => (int) $r['HAREKET'], 'son' => $sonH,
    ];

    $toplamTL += $netTL;
    if ($netTL > $enBuyuk['tl']) { $enBuyuk = ['ad' => $satir['ad'], 'tl' => $netTL]; }

    if ($tur === 'TL') {
        if ($netTL < -0.005) { $negatifler['adet']++; $negatifler['tutar'] += $netTL; }
        if ($netTL > 0) { $tlPozToplam += $netTL; }
        $tlKasalar[] = $satir;
    } else {
        $dvzOzet[$tur] = ($dvzOzet[$tur] ?? 0.0) + $netDvz;
        if ($netDvz < -0.005) { $negatifler['adet']++; }
        $dvzKasalar[] = $satir;
    }
}
$tlPozToplam = max(1.0, $tlPozToplam);
$kasaSayisi = count($tlKasalar) + count($dvzKasalar);

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$para = static fn($x): string => number_format((float) $x, 2, ',', '.');
$kisa = static function ($v): string {
    $v = (float) $v;
    if (abs($v) >= 1_000_000) { return number_format($v / 1_000_000, 2, ',', '.') . ' M'; }
    if (abs($v) >= 1_000)     { return number_format($v / 1_000, 0, ',', '.') . ' B'; }
    return number_format($v, 0, ',', '.');
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasa Paneli</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#f8f6f7; --card:#fff; --text-1:#1c1220; --text-2:#6d6276; --text-3:#9c92a5; --border:#ebe4ea;
            --red:#6F1022; --red-koyu:#7f1d1d; --red-orta:#b91c1c; --red-soft:#fdf0f0;
            --emerald:#059669; --amber:#d97706; --amber-soft:#fffbeb;
            --golge:0 12px 30px -20px rgba(28,18,32,.35);
        }
        * { box-sizing:border-box; margin:0; }
        body {
            font-family:'Avenir Next','Montserrat',sans-serif; color:var(--text-1); min-height:100vh; font-size:14px;
            background:
                radial-gradient(820px 280px at 100% -70px, rgba(111,16,34,.07), transparent 60%),
                radial-gradient(560px 220px at -60px 25%, rgba(111,16,34,.05), transparent 55%),
                var(--bg);
        }

        .top { position:sticky; top:0; z-index:40; background:rgba(255,255,255,.85); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-bottom:1px solid var(--border); }
        .top-in { max-width:1060px; margin:0 auto; padding:12px 22px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .geri { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; color:var(--text-2); background:#fff; border:1px solid var(--border); text-decoration:none; transition:.18s; }
        .geri:hover { color:var(--red); border-color:var(--red); transform:translateX(-2px); }
        .t-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:17px; background:linear-gradient(135deg,var(--red-orta),var(--red-koyu)); color:#fff; box-shadow:0 6px 14px -6px rgba(185,28,28,.5); }
        .t-baslik h1 { font-size:16px; font-weight:700; line-height:1.2; }
        .t-baslik p { font-size:11.5px; color:var(--text-2); }
        .aksiyon { margin-left:auto; display:flex; gap:8px; }
        .btn { display:inline-flex; align-items:center; gap:7px; font-family:inherit; font-size:12.5px; font-weight:600; padding:10px 14px; border-radius:11px; cursor:pointer; text-decoration:none; border:1px solid var(--border); background:#fff; color:var(--text-2); transition:.18s; }
        .btn:hover { color:var(--red); border-color:var(--red); transform:translateY(-1px); }

        main { max-width:1060px; margin:0 auto; padding:20px 22px 56px; }

        .vurgular { display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:12px; margin-bottom:18px; }
        .vurgu { background:var(--card); border:1px solid var(--border); border-radius:15px; padding:13px 16px; display:flex; align-items:center; gap:12px; box-shadow:var(--golge); animation:giris .5s cubic-bezier(.22,1,.36,1) both; }
        .vurgu:nth-child(2) { animation-delay:50ms; } .vurgu:nth-child(3) { animation-delay:100ms; } .vurgu:nth-child(4) { animation-delay:150ms; }
        .vurgu-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
        .vurgu.kir .vurgu-ico { background:var(--red-soft); color:var(--red); }
        .vurgu.yes .vurgu-ico { background:#ecfdf5; color:var(--emerald); }
        .vurgu.alt .vurgu-ico { background:var(--amber-soft); color:var(--amber); }
        .vurgu.mavi .vurgu-ico { background:#f0f9ff; color:#0284c7; }
        .vurgu-l { font-size:10px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:var(--text-3); }
        .vurgu-v { font-size:16.5px; font-weight:800; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vurgu-s { font-size:10.5px; color:var(--text-3); }
        .vurgu-v.eksi { color:var(--red); }

        .grup-baslik { display:flex; align-items:center; gap:9px; font-size:13px; font-weight:800; margin:18px 2px 10px; }
        .grup-baslik i { color:var(--red); }
        .grup-baslik .adet { font-size:10.5px; font-weight:700; background:var(--red-soft); color:var(--red); padding:2px 9px; border-radius:99px; }
        .grup-baslik .sag { margin-left:auto; font-size:11px; font-weight:700; color:var(--text-2); }

        .kasa-liste { display:flex; flex-direction:column; gap:9px; }
        .kasa {
            background:var(--card); border:1px solid var(--border); border-radius:14px;
            box-shadow:var(--golge); overflow:hidden; cursor:pointer; transition:border-color .18s, transform .18s;
            animation:giris .45s cubic-bezier(.22,1,.36,1) both; animation-delay:calc(var(--i,0) * 35ms);
        }
        .kasa:hover { border-color:rgba(111,16,34,.35); }
        .kasa.eksi { border-color:rgba(111,16,34,.35); background:linear-gradient(180deg,#fff8f8,#fff); }
        .kasa-ust { display:flex; align-items:center; gap:14px; padding:13px 16px; }
        .k-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:15px; background:#f4eff3; color:var(--text-2); }
        .kasa.eksi .k-ico { background:var(--red-soft); color:var(--red); }
        .k-bilgi { flex:1; min-width:0; }
        .k-ad { font-size:13.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .k-alt { font-size:10.5px; color:var(--text-3); display:flex; gap:10px; margin-top:2px; flex-wrap:wrap; }
        .k-pay { margin-top:7px; position:relative; height:5px; border-radius:99px; background:#f3eef2; overflow:hidden; max-width:340px; }
        .k-pay .d { position:absolute; inset:0 auto 0 0; border-radius:99px; background:linear-gradient(90deg,#34d399,var(--emerald)); }
        .k-sag { text-align:right; flex-shrink:0; }
        .k-tutar { font-size:16px; font-weight:800; font-variant-numeric:tabular-nums; color:var(--emerald); white-space:nowrap; }
        .k-tutar.eksi { color:var(--red); }
        .k-tutar small { font-size:11.5px; font-weight:600; color:var(--text-3); }
        .k-tl { font-size:10.5px; color:var(--text-3); margin-top:2px; font-variant-numeric:tabular-nums; }
        .cev { color:var(--text-3); font-size:13px; margin-left:6px; transition:transform .22s; flex-shrink:0; }
        .kasa[aria-expanded="true"] .cev { transform:rotate(180deg); }

        .k-detay { display:none; border-top:1px solid #f5f1f4; padding:12px 16px 14px; background:#fdfbfc; cursor:default; }
        .k-detay.acik { display:block; }
        .d-baslik { font-size:11px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:var(--text-3); display:flex; align-items:center; gap:6px; margin-bottom:8px; }
        .d-baslik i { color:var(--red); }
        .dtw { overflow-x:auto; }
        table.dt { width:100%; border-collapse:collapse; min-width:640px; }
        .dt th { padding:7px 10px; font-size:9.5px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); background:#fff; border-bottom:1px solid var(--border); text-align:right; white-space:nowrap; }
        .dt th:nth-child(1), .dt th:nth-child(2), .dt th:nth-child(3) { text-align:left; }
        .dt td { padding:7px 10px; font-size:11.5px; border-bottom:1px solid #f5f1f4; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
        .dt td:nth-child(1), .dt td:nth-child(2), .dt td:nth-child(3) { text-align:left; }
        .dt td:nth-child(2), .dt td:nth-child(3) { max-width:220px; overflow:hidden; text-overflow:ellipsis; }
        .dt .g { color:var(--emerald); font-weight:700; }
        .dt .c { color:var(--red); font-weight:700; }
        .dt .n { color:var(--text-3); }
        .dt .t { font-weight:800; }
        .d-yukleniyor { display:flex; align-items:center; gap:9px; color:var(--text-2); font-size:12px; padding:6px 0; }
        .d-spin { width:16px; height:16px; border:2px solid #fbd5d5; border-top-color:var(--red); border-radius:50%; animation:spin .8s linear infinite; }
        .d-hata { background:var(--red-soft); border:1px solid rgba(111,16,34,.25); color:var(--red-koyu); border-radius:9px; padding:9px 12px; font-size:12px; font-weight:600; }
        .d-bos { color:var(--text-3); font-size:12px; padding:4px 0; }

        .dip-not { margin-top:14px; font-size:11px; color:var(--text-3); line-height:1.6; }

        @keyframes giris { from { opacity:0; transform:translateY(9px); } to { opacity:1; transform:none; } }
        @keyframes spin { to { transform:rotate(360deg); } }

        @media (max-width:640px) {
            main { padding:14px 12px 46px; }
            .kasa-ust { padding:12px 13px; gap:10px; }
            .k-ico { width:34px; height:34px; font-size:13px; }
            .k-tutar { font-size:14.5px; }
        }
        @media print {
            body { background:#fff; }
            .top { display:none !important; }
            .vurgu, .kasa { box-shadow:none; break-inside:avoid; }
            .k-detay { display:none !important; }
        }
        html.akl-dark .vurgu, html.akl-dark .kasa, html.akl-dark .geri, html.akl-dark .btn { background:var(--card); }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="dashboard.php" class="geri" title="Rapor listesine dön"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="t-ico"><i class="fa-solid fa-vault"></i></span>
            <div class="t-baslik">
                <h1>Kasa Paneli</h1>
                <p>anlık bakiyeler · satıra dokun, son 30 hareketi gör</p>
            </div>
            <div class="aksiyon">
                <button onclick="window.print()" class="btn" title="Yazdır"><i class="fa-solid fa-print"></i><span>Yazdır</span></button>
            </div>
        </div>
    </header>

    <main>
        <div class="vurgular">
            <div class="vurgu kir">
                <span class="vurgu-ico"><i class="fa-solid fa-sack-dollar"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Toplam Varlık (TL)</div>
                    <div class="vurgu-v"><?php echo $kisa($toplamTL); ?> ₺</div>
                    <div class="vurgu-s"><?php echo $kasaSayisi; ?> kasa · döviz dahil (kayıtlı kur)</div>
                </div>
            </div>
            <div class="vurgu alt">
                <span class="vurgu-ico"><i class="fa-solid fa-coins"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Döviz / Kıymet</div>
                    <div class="vurgu-v" style="font-size:13.5px;">
                        <?php
                        $parcalar = [];
                        foreach (['USD' => '$', 'EUR' => '€', 'ALTIN' => 'br'] as $t => $s) {
                            if (isset($dvzOzet[$t])) { $parcalar[] = $kisa($dvzOzet[$t]) . ' ' . $s; }
                        }
                        echo $parcalar !== [] ? $h(implode(' · ', $parcalar)) : '—';
                        ?>
                    </div>
                    <div class="vurgu-s"><?php echo count($dvzKasalar); ?> hesap · kendi biriminde</div>
                </div>
            </div>
            <div class="vurgu <?php echo $negatifler['adet'] > 0 ? 'kir' : 'yes'; ?>">
                <span class="vurgu-ico"><i class="fa-solid <?php echo $negatifler['adet'] > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?>"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Negatif Kasa</div>
                    <div class="vurgu-v <?php echo $negatifler['adet'] > 0 ? 'eksi' : ''; ?>"><?php echo $negatifler['adet'] > 0 ? $negatifler['adet'] . ' kasa' : 'Yok'; ?></div>
                    <div class="vurgu-s"><?php echo $negatifler['adet'] > 0 ? $para($negatifler['tutar']) . ' ₺' : 'tüm bakiyeler pozitif'; ?></div>
                </div>
            </div>
            <div class="vurgu mavi">
                <span class="vurgu-ico"><i class="fa-solid fa-trophy"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">En Büyük Kasa</div>
                    <div class="vurgu-v" style="font-size:14px;"><?php echo $h(mb_substr($enBuyuk['ad'], 0, 26)); ?></div>
                    <div class="vurgu-s"><?php echo $kisa($enBuyuk['tl']); ?> ₺</div>
                </div>
            </div>
        </div>

        <!-- TL kasalar -->
        <div class="grup-baslik">
            <i class="fa-solid fa-turkish-lira-sign"></i> TL Kasalar
            <span class="adet"><?php echo count($tlKasalar); ?></span>
            <span class="sag"><?php echo $para(array_sum(array_column($tlKasalar, 'netTL'))); ?> ₺</span>
        </div>
        <div class="kasa-liste">
            <?php $i = 0; foreach ($tlKasalar as $k):
                $eksi = $k['netTL'] < -0.005;
                $pay = !$eksi ? 100 * $k['netTL'] / $tlPozToplam : 0; ?>
            <div class="kasa js-kasa <?php echo $eksi ? 'eksi' : ''; ?>" style="--i:<?php echo min($i, 10); ?>;" data-ref="<?php echo $k['ref']; ?>" role="button" aria-expanded="false" tabindex="0">
                <div class="kasa-ust">
                    <span class="k-ico"><i class="fa-solid <?php echo $eksi ? 'fa-triangle-exclamation' : 'fa-vault'; ?>"></i></span>
                    <div class="k-bilgi">
                        <div class="k-ad"><?php echo $h($k['ad']); ?></div>
                        <div class="k-alt">
                            <span><?php echo $h($k['kod']); ?></span>
                            <span><i class="fa-regular fa-clock" style="margin-right:2px;"></i>son: <?php echo $h($k['son']); ?></span>
                            <span><?php echo number_format($k['hareket'], 0, ',', '.'); ?> hareket</span>
                        </div>
                        <?php if (!$eksi && $k['netTL'] > 0): ?>
                        <div class="k-pay"><span class="d" style="width:<?php echo number_format(max(1.5, $pay), 1, '.', ''); ?>%;"></span></div>
                        <?php endif; ?>
                    </div>
                    <div class="k-sag">
                        <div class="k-tutar <?php echo $eksi ? 'eksi' : ''; ?>"><?php echo $para($k['netTL']); ?> <small>₺</small></div>
                        <?php if (!$eksi && $k['netTL'] > 0): ?><div class="k-tl">pay %<?php echo number_format($pay, 1, ',', '.'); ?></div><?php endif; ?>
                    </div>
                    <i class="fa-solid fa-chevron-down cev"></i>
                </div>
                <div class="k-detay js-detay"></div>
            </div>
            <?php $i++; endforeach; ?>
        </div>

        <?php if ($dvzKasalar !== []): ?>
        <!-- Döviz / kıymet hesapları -->
        <div class="grup-baslik">
            <i class="fa-solid fa-coins"></i> Döviz / Kıymet Hesapları
            <span class="adet"><?php echo count($dvzKasalar); ?></span>
            <span class="sag">TL karşılığı: <?php echo $para(array_sum(array_column($dvzKasalar, 'netTL'))); ?> ₺</span>
        </div>
        <div class="kasa-liste">
            <?php $i = 0; foreach ($dvzKasalar as $k):
                $eksi = $k['netDvz'] < -0.005; ?>
            <div class="kasa js-kasa <?php echo $eksi ? 'eksi' : ''; ?>" style="--i:<?php echo min($i, 10); ?>;" data-ref="<?php echo $k['ref']; ?>" role="button" aria-expanded="false" tabindex="0">
                <div class="kasa-ust">
                    <span class="k-ico"><i class="fa-solid <?php echo $k['tur'] === 'ALTIN' ? 'fa-ring' : 'fa-money-bill-wave'; ?>"></i></span>
                    <div class="k-bilgi">
                        <div class="k-ad"><?php echo $h($k['ad']); ?></div>
                        <div class="k-alt">
                            <span><?php echo $h($k['kod']); ?></span>
                            <span><i class="fa-regular fa-clock" style="margin-right:2px;"></i>son: <?php echo $h($k['son']); ?></span>
                            <span><?php echo number_format($k['hareket'], 0, ',', '.'); ?> hareket</span>
                        </div>
                    </div>
                    <div class="k-sag">
                        <div class="k-tutar <?php echo $eksi ? 'eksi' : ''; ?>"><?php echo $para($k['netDvz']); ?> <small><?php echo $h($k['sym']); ?></small></div>
                        <div class="k-tl">≈ <?php echo $para($k['netTL']); ?> ₺ · kayıtlı kur</div>
                    </div>
                    <i class="fa-solid fa-chevron-down cev"></i>
                </div>
                <div class="k-detay js-detay"></div>
            </div>
            <?php $i++; endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="dip-not">
            <b>TL karşılıkları</b> dış kurdan değil, LOGO'daki hareketlerin <b>işlem günü kayıtlı TL değerlerinden</b> hesaplanır
            (döviz hesaplarında miktar TRNET, TL karşılığı AMOUNT). · Bakiyeler bugüne kadarki tüm dönem hareketlerinin netidir.
        </div>
    </main>

    <script>
    (function () {
        var TRfmt = function (v) {
            var n = parseFloat(v); if (isNaN(n)) { n = 0; }
            return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        };
        var esc = function (s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        };

        document.querySelectorAll('.js-kasa').forEach(function (kart) {
            var detay = kart.querySelector('.js-detay');

            function ciz(rows) {
                if (!rows || !rows.length) {
                    detay.innerHTML = '<div class="d-bos"><i class="fa-regular fa-folder-open"></i> Bu kasada hareket yok.</div>';
                    return;
                }
                var html = '<div class="d-baslik"><i class="fa-solid fa-clock-rotate-left"></i> Son ' + rows.length + ' hareket</div>' +
                    '<div class="dtw"><table class="dt"><thead><tr>' +
                    '<th>Tarih</th><th>Cari</th><th>Açıklama</th><th>Giren</th><th>Çıkan</th></tr></thead><tbody>';
                for (var i = 0; i < rows.length; i++) {
                    var r = rows[i];
                    var g = parseFloat(r.giren || 0), c = parseFloat(r.cikan || 0);
                    html += '<tr>' +
                        '<td>' + esc(r.tarih) + '</td>' +
                        '<td title="' + esc(r.cari) + '">' + esc(r.cari) + '</td>' +
                        '<td title="' + esc(r.aciklama) + '">' + esc(r.aciklama) + '</td>' +
                        '<td class="' + (g > 0 ? 'g' : 'n') + '">' + (g > 0 ? '+' + TRfmt(g) : '—') + '</td>' +
                        '<td class="' + (c > 0 ? 'c' : 'n') + '">' + (c > 0 ? '−' + TRfmt(c) : '—') + '</td>' +
                        '</tr>';
                }
                detay.innerHTML = html + '</tbody></table></div>';
            }

            kart.addEventListener('click', function (e) {
                if (detay.contains(e.target)) { return; }
                var acikMi = detay.classList.contains('acik');
                if (acikMi) {
                    detay.classList.remove('acik');
                    kart.setAttribute('aria-expanded', 'false');
                    return;
                }
                detay.classList.add('acik');
                kart.setAttribute('aria-expanded', 'true');
                if (detay.getAttribute('data-durum')) { return; }
                detay.setAttribute('data-durum', 'yukleniyor');
                detay.innerHTML = '<div class="d-yukleniyor"><span class="d-spin"></span> Hareketler yükleniyor…</div>';
                fetch('api/kasa_hareket.php?cardref=' + encodeURIComponent(kart.getAttribute('data-ref')), { credentials: 'include', cache: 'no-store' })
                    .then(function (res) { if (!res.ok) { throw new Error('Sunucu hatası (' + res.status + ')'); } return res.json(); })
                    .then(function (rows) { detay.setAttribute('data-durum', 'tamam'); ciz(rows); })
                    .catch(function (err) {
                        detay.removeAttribute('data-durum');
                        detay.innerHTML = '<div class="d-hata"><i class="fa-solid fa-triangle-exclamation"></i> ' + esc(err.message || 'Bağlantı hatası') + '</div>';
                    });
            });
        });
    })();
    </script>
</body>
</html>

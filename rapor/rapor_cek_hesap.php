<?php
declare(strict_types=1);

/**
 * rapor_cek_hesap.php — "Çek Hesap Dökümü" (yeniden tasarım 2026-07-10).
 *
 * Eski: Tailwind CDN + Material Icons + Chart.js aylık grafik + simple-datatables.
 * Yeni: Lumen bordo krom, vurgu kartları, aylık vade dağılımı DOLGULU LİSTE
 * (grafik yok — liste konuşur), arama + Vade/Tutar canlı sıralama + sayfalama
 * vanilla JS. Cari çek detay modalı (fetch_customer_checks.php?kimen=) korundu.
 *
 * Veri tanımı DEĞİŞMEDİ: CSCARD DOC=1 (müşteri çeki), STATUS IN(0,1);
 * durum sekmesi CURRSTAT (1=portföy, 2=ciro, 4/5/6/8=tahsil), tutar TRNET.
 */

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Durum filtresi: portfoy (varsayilan) / ciro / tahsil / tumu
$durum = (string) ($_GET['durum'] ?? 'portfoy');
if (!in_array($durum, ['portfoy', 'ciro', 'tahsil', 'tumu'], true)) {
    $durum = 'portfoy';
}
$currstatFiltre = match ($durum) {
    'ciro'   => 'IN (2)',
    'tahsil' => 'IN (4,5,6,8)',
    'tumu'   => 'IN (1,2,3,4,5,6,8)',
    default  => 'IN (1)',
};
$durumBaslik = match ($durum) {
    'ciro'   => 'Ciro Edilen Çekler',
    'tahsil' => 'Tahsil Edilen Çekler',
    'tumu'   => 'Tüm Çekler',
    default  => 'Portföydeki Çekler',
};

function raporDateToTimestamp(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime((string) $value);
    return ($ts === false) ? null : $ts;
}

// CSCARD.CURRSTAT -> [etiket, renk sinifi]
function cekDurumBilgi(int $cs): array
{
    return match ($cs) {
        1 => ['Portföyde', 'portfoy'],
        2 => ['Ciro Edildi', 'ciro'],
        3 => ['Teminatta', 'diger'],
        4 => ['Tahsilde', 'tahsilde'],
        5 => ['Teminat Tahsilde', 'tahsilde'],
        6 => ['Tahsil Edildi', 'tahsil'],
        8 => ['Tahsil Edildi', 'tahsil'],
        default => ['Diğer', 'diger'],
    };
}

// 1) Aylık vade dağılımı (tutar + adet)
$monthly = [];
$stmtMonthly = $dbh->prepare("
    SELECT CONVERT(char(7), C.DUEDATE, 126) AS AY, SUM(C.TRNET) AS TUTAR, COUNT(*) AS ADET
    FROM {$firmadonem}CSCARD C WITH(NOLOCK)
    WHERE C.CURRSTAT {$currstatFiltre} AND C.STATUS IN(0,1) AND C.DOC=1
    GROUP BY CONVERT(char(7), C.DUEDATE, 126)
    ORDER BY AY
");
$stmtMonthly->execute();
while ($r = $stmtMonthly->fetch(PDO::FETCH_ASSOC)) {
    $monthly[(string) $r['AY']] = ['t' => (float) $r['TUTAR'], 'a' => (int) $r['ADET']];
}
$maxAyTutar = 1.0;
foreach ($monthly as $m) { $maxAyTutar = max($maxAyTutar, $m['t']); }

// 2) Detaylı liste ve KPI'lar
$details = [];
$caris = [];
$totalCount = 0;
$totalAmount = 0.0;
$maxDueTs = null;
$minDueTs = null;
$overdueCount = 0;
$overdueAmount = 0.0;
$maxTutar = 1.0;

$todayTs = strtotime(date('Y-m-d')) ?: time();
$next7DaysTs = strtotime('+7 days', $todayTs) ?: $todayTs;

$sql_details = "
    SELECT
        CAST(LGMAIN.DUEDATE AS DATE) AS DUEDATE,
        LGMAIN.TRNET AS TUTAR,
        LGMAIN.OWING AS KIMDEN,
        LGMAIN.PORTFOYNO,
        LGMAIN.NEWSERINO,
        CAST(LGMAIN.SETDATE AS DATE) AS SETDATE,
        LGMAIN.CURRSTAT AS DURUM_KOD,
        ISNULL(CL.CODE, '') AS CARIHESAP
    FROM {$firmadonem}CSCARD LGMAIN WITH(NOLOCK)
    LEFT JOIN (
        SELECT
            T.CSREF,
            T.CARDREF,
            ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
        FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
    ) TX ON TX.CSREF = LGMAIN.LOGICALREF AND TX.RN = 1
    LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
    WHERE LGMAIN.CURRSTAT {$currstatFiltre} AND LGMAIN.STATUS IN(0,1) AND LGMAIN.DOC=1
    ORDER BY CAST(LGMAIN.DUEDATE AS DATE) ASC, LGMAIN.OWING, LGMAIN.TRNET
";

$stmtDetails = $dbh->prepare($sql_details);
$stmtDetails->execute();
while ($r = $stmtDetails->fetch(PDO::FETCH_ASSOC)) {
    $amount = isset($r['TUTAR']) ? (float) $r['TUTAR'] : 0.0;
    $dueTs = raporDateToTimestamp($r['DUEDATE'] ?? null);
    $setTs = raporDateToTimestamp($r['SETDATE'] ?? null);

    $r['TUTAR'] = $amount;
    $r['_DUE_TS'] = $dueTs;
    $r['_DUE_ISO'] = $dueTs !== null ? date('Y-m-d', $dueTs) : '';
    $r['_DUE_FMT'] = $dueTs !== null ? date('d.m.Y', $dueTs) : '-';
    $r['_SET_ISO'] = $setTs !== null ? date('Y-m-d', $setTs) : '';
    $r['_SET_FMT'] = $setTs !== null ? date('d.m.Y', $setTs) : '-';

    $details[] = $r;
    $totalCount++;
    $totalAmount += $amount;
    $maxTutar = max($maxTutar, $amount);

    // Vadesi gecmis (yalnizca portfoydeki cekler icin anlamli)
    $cekPortfoyde = ((int) ($r['DURUM_KOD'] ?? 0) === 1);
    if ($cekPortfoyde && $dueTs !== null && (int) $dueTs < $todayTs) {
        $overdueCount++;
        $overdueAmount += $amount;
    }

    $cariKod = trim((string) ($r['CARIHESAP'] ?? ''));
    if ($cariKod !== '') {
        $caris[$cariKod] = true;
    }

    if ($dueTs !== null && ($maxDueTs === null || $dueTs > $maxDueTs)) {
        $maxDueTs = $dueTs;
    }
    if ($dueTs !== null && ($minDueTs === null || $dueTs < $minDueTs)) {
        $minDueTs = $dueTs;
    }
}
$uniqueCari = count($caris);

// Yaklasan vadeler (7 gun, yalniz portfoyde)
$upcomingCount = 0;
$upcomingAmount = 0.0;
foreach ($details as $d) {
    if (((int) ($d['DURUM_KOD'] ?? 0) === 1) && ($d['_DUE_TS'] ?? null) !== null && (int) $d['_DUE_TS'] >= $todayTs && (int) $d['_DUE_TS'] <= $next7DaysTs) {
        $upcomingCount++;
        $upcomingAmount += (float) ($d['TUTAR'] ?? 0);
    }
}

// Sekme adetleri (tum durumlar)
$durumSayilari = ['portfoy' => 0, 'ciro' => 0, 'tahsil' => 0, 'tumu' => 0];
try {
    $stmtSay = $dbh->query("SELECT CURRSTAT, COUNT(*) AS A FROM {$firmadonem}CSCARD WITH(NOLOCK) WHERE STATUS IN(0,1) AND DOC=1 GROUP BY CURRSTAT");
    while ($s = $stmtSay->fetch(PDO::FETCH_ASSOC)) {
        $cs = (int) $s['CURRSTAT'];
        $a = (int) $s['A'];
        $durumSayilari['tumu'] += $a;
        if ($cs === 1) {
            $durumSayilari['portfoy'] += $a;
        } elseif ($cs === 2) {
            $durumSayilari['ciro'] += $a;
        } elseif (in_array($cs, [4, 5, 6, 8], true)) {
            $durumSayilari['tahsil'] += $a;
        }
    }
} catch (Throwable $e) {
    error_log('cek durum sayilari: ' . $e->getMessage());
}

$ayAdlari = [1=>'Oca',2=>'Şub',3=>'Mar',4=>'Nis',5=>'May',6=>'Haz',7=>'Tem',8=>'Ağu',9=>'Eyl',10=>'Eki',11=>'Kas',12=>'Ara'];
$buAyKey = date('Y-m');

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$para = static fn($x): string => number_format((float) $x, 2, ',', '.');
$adetF = static fn($x): string => number_format((float) $x, 0, ',', '.');
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
    <title>Çek Hesap Dökümü — <?php echo $h($durumBaslik); ?></title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg:#f8f6f7; --card:#fff; --text-1:#1c1220; --text-2:#6d6276; --text-3:#9c92a5; --border:#ebe4ea;
            --red:#6F1022; --red-koyu:#7f1d1d; --red-orta:#b91c1c; --red-soft:#fdf0f0;
            --emerald:#059669; --emerald-soft:#ecfdf5; --amber:#d97706; --amber-soft:#fffbeb;
            --sky:#0284c7; --sky-soft:#eff6ff; --mor:#7c3aed; --mor-soft:#f5f3ff;
            --golge:0 12px 30px -20px rgba(28,18,32,.35);
        }
        * { box-sizing:border-box; margin:0; }
        body {
            font-family:'Avenir Next','Montserrat',sans-serif; color:var(--text-1); min-height:100vh; font-size:14px;
            background:
                radial-gradient(820px 280px at 100% -70px, rgba(111,16,34,.07), transparent 60%),
                radial-gradient(560px 220px at -60px 25%, rgba(245,158,11,.06), transparent 55%),
                var(--bg);
        }

        .top { position:sticky; top:0; z-index:40; background:rgba(255,255,255,.85); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px); border-bottom:1px solid var(--border); }
        .top-in { max-width:1220px; margin:0 auto; padding:12px 22px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
        .geri { width:38px; height:38px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; color:var(--text-2); background:#fff; border:1px solid var(--border); text-decoration:none; transition:.18s; }
        .geri:hover { color:var(--red); border-color:var(--red); transform:translateX(-2px); }
        .t-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:17px; background:linear-gradient(135deg,var(--red-orta),var(--red-koyu)); color:#fff; box-shadow:0 6px 14px -6px rgba(185,28,28,.5); }
        .t-baslik h1 { font-size:16px; font-weight:700; line-height:1.2; }
        .t-baslik p { font-size:11.5px; color:var(--text-2); }
        .aksiyon { margin-left:auto; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .btn { display:inline-flex; align-items:center; gap:7px; font-family:inherit; font-size:12.5px; font-weight:600; padding:10px 14px; border-radius:11px; cursor:pointer; text-decoration:none; border:1px solid var(--border); background:#fff; color:var(--text-2); transition:.18s; }
        .btn:hover { color:var(--red); border-color:var(--red); transform:translateY(-1px); }
        .btn.birincil { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 6px 14px -8px rgba(111,16,34,.55); }
        .btn.birincil:hover { background:var(--red-orta); color:#fff; }

        main { max-width:1220px; margin:0 auto; padding:20px 22px 56px; }

        /* Durum sekmeleri */
        .sekmeler { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
        .sekme { display:inline-flex; align-items:center; gap:8px; padding:9px 15px; border-radius:99px; border:1.5px solid var(--border); background:#fff; color:var(--text-2); font-size:12.5px; font-weight:700; text-decoration:none; transition:.16s; }
        .sekme:hover { border-color:var(--red); color:var(--red); }
        .sekme.on { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 5px 12px -7px rgba(111,16,34,.55); }
        .sekme .adet { font-size:10.5px; font-weight:800; padding:1px 8px; border-radius:99px; background:var(--red-soft); color:var(--red-orta); }
        .sekme.on .adet { background:rgba(255,255,255,.25); color:#fff; }

        /* Vurgular */
        .vurgular { display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:12px; margin-bottom:16px; }
        .vurgu { background:var(--card); border:1px solid var(--border); border-radius:15px; padding:13px 16px; display:flex; align-items:center; gap:12px; box-shadow:var(--golge); animation:giris .5s cubic-bezier(.22,1,.36,1) both; }
        .vurgu:nth-child(2) { animation-delay:50ms; } .vurgu:nth-child(3) { animation-delay:100ms; } .vurgu:nth-child(4) { animation-delay:150ms; }
        .vurgu-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
        .vurgu.kir .vurgu-ico { background:var(--red-soft); color:var(--red); }
        .vurgu.mor .vurgu-ico { background:var(--mor-soft); color:var(--mor); }
        .vurgu.alt .vurgu-ico { background:var(--amber-soft); color:var(--amber); }
        .vurgu.yes .vurgu-ico { background:var(--emerald-soft); color:var(--emerald); }
        .vurgu.gecikmis { border-color:rgba(111,16,34,.4); background:linear-gradient(180deg,#fef5f5,#fff); }
        .vurgu-l { font-size:10px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:var(--text-3); }
        .vurgu-v { font-size:16.5px; font-weight:800; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vurgu-v.kirmizi { color:var(--red-orta); }
        .vurgu-s { font-size:10.5px; color:var(--text-3); }

        /* Aylık vade dağılımı — dolgulu liste */
        .ay-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--golge); margin-bottom:16px; overflow:hidden; animation:giris .5s cubic-bezier(.22,1,.36,1) .1s both; }
        .ay-bas { padding:13px 18px; border-bottom:1px solid var(--border); font-size:13.5px; font-weight:700; display:flex; align-items:center; gap:8px; }
        .ay-bas i { color:var(--red); }
        .ay-liste { padding:10px 18px 14px; }
        .ay-satir { display:grid; grid-template-columns:86px 52px 1fr 130px; align-items:center; gap:10px; padding:5px 0; }
        .ay-ad { font-size:12px; font-weight:700; white-space:nowrap; }
        .ay-ad .buay { display:inline-block; margin-left:5px; font-size:8.5px; font-weight:800; letter-spacing:.4px; padding:1.5px 6px; border-radius:99px; background:var(--red); color:#fff; vertical-align:middle; }
        .ay-adet { font-size:10.5px; color:var(--text-3); font-weight:600; white-space:nowrap; }
        .ay-bar { height:14px; border-radius:6px; background:#f4eff3; overflow:hidden; }
        .ay-bar span { display:block; height:100%; border-radius:6px; background:linear-gradient(90deg,rgba(111,16,34,.75),rgba(185,28,28,.9)); min-width:2px; }
        .ay-satir.gecmis .ay-bar span { background:rgba(156,146,165,.45); }
        .ay-satir.gecmis .ay-ad, .ay-satir.gecmis .ay-tutar { color:var(--text-3); }
        .ay-tutar { font-size:12px; font-weight:700; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }

        /* Liste kartı */
        .lig-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--golge); overflow:hidden; animation:giris .5s cubic-bezier(.22,1,.36,1) .15s both; }
        .lig-bas { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .lig-bas .baslik { font-size:13.5px; font-weight:700; display:inline-flex; align-items:center; gap:8px; }
        .lig-bas .baslik i { color:var(--red); }
        .siralayici { display:inline-flex; gap:6px; }
        .cip { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:99px; border:1.5px solid var(--border); background:#fff; color:var(--text-2); font-family:inherit; font-size:12px; font-weight:700; cursor:pointer; transition:.16s; }
        .cip:hover { border-color:var(--red); color:var(--red); }
        .cip.on { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 5px 12px -7px rgba(111,16,34,.55); }
        .ara-kutu { position:relative; margin-left:auto; flex:0 1 260px; min-width:180px; }
        .ara-kutu i { position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:12.5px; }
        .ara-kutu input { width:100%; padding:9px 12px 9px 33px; font-family:inherit; font-size:13.5px; border:1px solid var(--border); border-radius:10px; outline:none; transition:.16s; }
        .ara-kutu input:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.1); }
        .kayit-not { font-size:11px; color:var(--text-3); font-weight:600; white-space:nowrap; }

        .tw { overflow-x:auto; }
        table.lig { width:100%; border-collapse:collapse; min-width:940px; }
        .lig th { padding:10px 14px; font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); background:#fbf8fa; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; }
        .lig th.sag { text-align:right; }
        .lig td { padding:9px 14px; border-bottom:1px solid #f5f1f4; font-size:12.5px; text-align:left; font-variant-numeric:tabular-nums; white-space:nowrap; vertical-align:middle; }
        .lig td.sag { text-align:right; }
        .lig tr:hover td { background:#fdfbfc; }
        .lig tr.gecikmis td { background:#fdf1f1; }
        .lig tr.gecikmis:hover td { background:#fbe7e7; }
        .lig tr.yaklasan td { background:#fffaef; }
        .lig tr.yaklasan:hover td { background:#fdf3da; }
        .vade-t { font-weight:700; }
        .vade-t.gec { color:var(--red-orta); }
        .vade-t.yak { color:var(--amber); }
        .rozet { display:inline-flex; align-items:center; gap:4px; margin-left:6px; font-size:9px; font-weight:800; letter-spacing:.3px; padding:2px 7px; border-radius:99px; vertical-align:middle; }
        .rozet.gec { background:#fecaca; color:#991b1b; }
        .rozet.yak { background:#fde68a; color:#92400e; }
        .deger-hucre { position:relative; display:block; min-width:140px; padding:3px 0; }
        .deger-hucre .dolgu { position:absolute; inset:0 auto 0 0; border-radius:6px; background:rgba(111,16,34,.12); }
        .deger-hucre span.icerik { position:relative; padding-right:4px; font-weight:700; display:block; text-align:right; }
        .kimden-btn { color:var(--red-orta); font-weight:600; background:none; border:none; padding:0; cursor:pointer; text-align:left; font-family:inherit; font-size:inherit; transition:.15s; max-width:280px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .kimden-btn:hover { color:var(--red); text-decoration:underline; }
        .durum-rozet { display:inline-flex; padding:3px 10px; border-radius:99px; font-size:10.5px; font-weight:700; white-space:nowrap; }
        .durum-rozet.d-portfoy { background:var(--sky-soft); color:var(--sky); }
        .durum-rozet.d-ciro { background:var(--mor-soft); color:var(--mor); }
        .durum-rozet.d-tahsilde { background:var(--amber-soft); color:var(--amber); }
        .durum-rozet.d-tahsil { background:var(--emerald-soft); color:var(--emerald); }
        .durum-rozet.d-diger { background:#f3f4f6; color:var(--text-2); }
        .mono { font-family:'Courier New',monospace; font-size:11.5px; }
        .lig tfoot td { border-top:2px solid var(--border); background:#fbf8fa; font-weight:800; }

        .sayfalar { display:flex; justify-content:flex-end; gap:6px; padding:12px 16px; flex-wrap:wrap; }
        .sayfalar button { padding:7px 12px; font-family:inherit; font-size:12px; font-weight:700; background:#fff; color:var(--text-2); border:1px solid var(--border); border-radius:9px; cursor:pointer; transition:.14s; }
        .sayfalar button:hover:not(:disabled) { border-color:var(--red); color:var(--red); }
        .sayfalar button.on { background:var(--red); color:#fff; border-color:var(--red); }
        .sayfalar button:disabled { opacity:.4; cursor:not-allowed; }

        .dipnot { margin-top:12px; font-size:11px; line-height:1.6; color:var(--text-3); }
        .dipnot b { color:var(--text-2); }

        .bos-genel { padding:56px 20px; text-align:center; color:var(--text-3); }
        .bos-genel i { font-size:38px; display:block; margin-bottom:12px; color:#e6d8de; }

        /* Modal (cari çek detayı) */
        .modal-arka { position:fixed; inset:0; z-index:50; background:rgba(28,18,32,.5); backdrop-filter:blur(4px); -webkit-backdrop-filter:blur(4px); padding:20px 14px; overflow-y:auto; opacity:0; visibility:hidden; transition:opacity .25s, visibility .25s; }
        .modal-arka.acik { opacity:1; visibility:visible; }
        .modal-kutu { max-width:960px; margin:0 auto; background:#fff; border-radius:16px; border:1px solid var(--border); box-shadow:0 20px 50px rgba(28,18,32,.3); overflow:hidden; transform:translate3d(0,16px,0) scale(.97); transition:transform .3s cubic-bezier(.22,1,.36,1); }
        .modal-arka.acik .modal-kutu { transform:none; }
        .modal-bas { display:flex; align-items:center; justify-content:space-between; padding:14px 20px; background:linear-gradient(135deg,var(--red-orta),var(--red-koyu)); color:#fff; }
        .modal-bas h5 { font-size:15px; font-weight:700; }
        .modal-kapat { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:50%; border:1px solid rgba(255,255,255,.3); background:rgba(255,255,255,.1); color:#fff; cursor:pointer; font-size:15px; transition:.15s; }
        .modal-kapat:hover { background:rgba(255,255,255,.22); }
        .modal-icerik { max-height:72vh; overflow-y:auto; padding:18px; }
        @keyframes spin { to { transform:rotate(360deg); } }
        .yukleniyor { display:flex; flex-direction:column; align-items:center; padding:32px 0; }
        .yukleniyor .halka { width:36px; height:36px; border:3px solid #fecaca; border-top-color:var(--red); border-radius:50%; animation:spin .8s linear infinite; }
        .yukleniyor p { font-size:12px; color:var(--text-2); margin-top:12px; }

        @keyframes giris { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

        @media (max-width:760px) {
            main { padding:14px 12px 46px; }
            .aksiyon { margin-left:0; width:100%; }
            .aksiyon .btn span { display:none; }
            .ay-satir { grid-template-columns:72px 1fr 108px; }
            .ay-adet { display:none; }
            .ara-kutu { flex:1 1 100%; margin-left:0; }
            .lig td:first-child, .lig th:first-child { position:sticky; left:0; background:#fff; z-index:2; }
            .lig th:first-child { background:#fbf8fa; z-index:3; }
            .lig tr.gecikmis td:first-child { background:#fdf1f1; }
            .lig tr.yaklasan td:first-child { background:#fffaef; }
        }
        @media print {
            body { background:#fff; }
            .top, .sekmeler, .siralayici, .ara-kutu, .sayfalar, .modal-arka { display:none !important; }
            .vurgu, .ay-kart, .lig-kart { box-shadow:none; break-inside:avoid; }
            .deger-hucre .dolgu, .ay-bar span { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
        html.lumen-dark .vurgu, html.lumen-dark .ay-kart, html.lumen-dark .lig-kart, html.lumen-dark .geri, html.lumen-dark .btn, html.lumen-dark .cip, html.lumen-dark .sekme { background:var(--card); }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="dashboard.php" class="geri" title="Rapor listesine dön"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="t-ico"><i class="fa-solid fa-file-invoice"></i></span>
            <div class="t-baslik">
                <h1>Çek Hesap Dökümü</h1>
                <p><?php echo $h($durumBaslik); ?> · müşteri çekleri</p>
            </div>
            <div class="aksiyon">
                <button onclick="window.print()" class="btn" title="Yazdır"><i class="fa-solid fa-print"></i><span>Yazdır</span></button>
                <a href="rapor_cek_hesap_excel_xlsx.php?durum=<?php echo urlencode($durum); ?>" class="btn birincil" title="Excel indir"><i class="fa-solid fa-file-excel"></i><span>Excel</span></a>
            </div>
        </div>
    </header>

    <main>
        <!-- Durum sekmeleri -->
        <div class="sekmeler">
            <a href="?durum=portfoy" class="sekme <?php echo $durum === 'portfoy' ? 'on' : ''; ?>"><i class="fa-solid fa-wallet"></i> Portföyde <span class="adet"><?php echo $adetF($durumSayilari['portfoy']); ?></span></a>
            <a href="?durum=ciro" class="sekme <?php echo $durum === 'ciro' ? 'on' : ''; ?>"><i class="fa-solid fa-right-left"></i> Ciro Edilen <span class="adet"><?php echo $adetF($durumSayilari['ciro']); ?></span></a>
            <a href="?durum=tahsil" class="sekme <?php echo $durum === 'tahsil' ? 'on' : ''; ?>"><i class="fa-solid fa-circle-check"></i> Tahsil Edilen <span class="adet"><?php echo $adetF($durumSayilari['tahsil']); ?></span></a>
            <a href="?durum=tumu" class="sekme <?php echo $durum === 'tumu' ? 'on' : ''; ?>"><i class="fa-solid fa-layer-group"></i> Tümü <span class="adet"><?php echo $adetF($durumSayilari['tumu']); ?></span></a>
        </div>

        <!-- Vurgular -->
        <div class="vurgular">
            <div class="vurgu kir">
                <span class="vurgu-ico"><i class="fa-solid fa-sack-dollar"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Toplam Tutar</div>
                    <div class="vurgu-v"><?php echo $kisa($totalAmount); ?> ₺</div>
                    <div class="vurgu-s"><?php echo $para($totalAmount); ?> ₺</div>
                </div>
            </div>
            <div class="vurgu mor">
                <span class="vurgu-ico"><i class="fa-solid fa-money-check-dollar"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Çek Adedi</div>
                    <div class="vurgu-v"><?php echo $adetF($totalCount); ?></div>
                    <div class="vurgu-s"><?php echo $adetF($uniqueCari); ?> farklı cari</div>
                </div>
            </div>
            <?php if ($durum === 'portfoy'): ?>
            <div class="vurgu <?php echo $overdueCount > 0 ? 'kir gecikmis' : 'yes'; ?>">
                <span class="vurgu-ico"><i class="fa-solid <?php echo $overdueCount > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?>"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Vadesi Geçmiş</div>
                    <?php if ($overdueCount > 0): ?>
                        <div class="vurgu-v kirmizi"><?php echo $kisa($overdueAmount); ?> ₺</div>
                        <div class="vurgu-s"><?php echo $adetF($overdueCount); ?> çek gecikmiş</div>
                    <?php else: ?>
                        <div class="vurgu-v" style="color:var(--emerald);">Yok</div>
                        <div class="vurgu-s">tüm vadeler ileride</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="vurgu alt">
                <span class="vurgu-ico"><i class="fa-solid fa-hourglass-half"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">7 Gün İçinde</div>
                    <?php if ($upcomingCount > 0): ?>
                        <div class="vurgu-v" style="color:var(--amber);"><?php echo $kisa($upcomingAmount); ?> ₺</div>
                        <div class="vurgu-s"><?php echo $adetF($upcomingCount); ?> çekin vadesi dolacak</div>
                    <?php else: ?>
                        <div class="vurgu-v">—</div>
                        <div class="vurgu-s">yaklaşan vade yok</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="vurgu alt">
                <span class="vurgu-ico"><i class="fa-solid fa-calendar-days"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Vade Aralığı</div>
                    <div class="vurgu-v" style="font-size:13.5px;"><?php echo $minDueTs !== null ? date('d.m.Y', $minDueTs) : '—'; ?> → <?php echo $maxDueTs !== null ? date('d.m.Y', $maxDueTs) : '—'; ?></div>
                    <div class="vurgu-s">ilk ve son vade</div>
                </div>
            </div>
            <div class="vurgu yes">
                <span class="vurgu-ico"><i class="fa-solid fa-scale-balanced"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Ortalama Çek</div>
                    <div class="vurgu-v"><?php echo $totalCount > 0 ? $kisa($totalAmount / $totalCount) : '—'; ?> ₺</div>
                    <div class="vurgu-s">çek başına ortalama</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($details === []): ?>
            <div class="lig-kart"><div class="bos-genel">
                <i class="fa-regular fa-folder-open"></i>
                <b>Bu durumda çek yok</b>
            </div></div>
        <?php else: ?>

        <!-- Aylık vade dağılımı -->
        <?php if (count($monthly) > 1): ?>
        <section class="ay-kart">
            <div class="ay-bas"><i class="fa-solid fa-calendar-week"></i> Aylık Vade Dağılımı</div>
            <div class="ay-liste">
                <?php foreach ($monthly as $ayKey => $m):
                    $parca = explode('-', (string) $ayKey);
                    $etiket = ($ayAdlari[(int) ($parca[1] ?? 0)] ?? $ayKey) . ' ' . ($parca[0] ?? '');
                    $w = 100 * $m['t'] / $maxAyTutar;
                    $gecmis = (string) $ayKey < $buAyKey; ?>
                <div class="ay-satir <?php echo $gecmis ? 'gecmis' : ''; ?>">
                    <span class="ay-ad"><?php echo $h($etiket); ?><?php if ((string) $ayKey === $buAyKey): ?><span class="buay">BU AY</span><?php endif; ?></span>
                    <span class="ay-adet"><?php echo $adetF($m['a']); ?> çek</span>
                    <span class="ay-bar"><span style="width:<?php echo number_format(max(1.5, $w), 1, '.', ''); ?>%;"></span></span>
                    <span class="ay-tutar"><?php echo $para($m['t']); ?> ₺</span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- Çek listesi -->
        <section class="lig-kart">
            <div class="lig-bas">
                <span class="baslik"><i class="fa-solid fa-table-list"></i> Çek Listesi</span>
                <div class="siralayici" role="group" aria-label="Sıralama ölçütü">
                    <button type="button" class="cip on" data-m="vade"><i class="fa-solid fa-calendar-day"></i> Vade</button>
                    <button type="button" class="cip" data-m="tutar"><i class="fa-solid fa-turkish-lira-sign"></i> Tutar</button>
                </div>
                <div class="ara-kutu">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="ara" placeholder="Kimden, seri no, cari kodu ara..." autocomplete="off">
                </div>
                <span class="kayit-not"><span id="kayitAdet"><?php echo $adetF($totalCount); ?></span> çek</span>
            </div>
            <div class="tw">
                <table class="lig">
                    <thead>
                        <tr>
                            <th>Vade</th>
                            <th class="sag">Tutar</th>
                            <th>Kimden</th>
                            <th>Durum</th>
                            <th>Portföy No</th>
                            <th>Seri No</th>
                            <th>Alım Tarihi</th>
                            <th>Cari Kodu</th>
                        </tr>
                    </thead>
                    <tbody id="govde">
                        <?php foreach ($details as $r):
                            $dueDate = $r['_DUE_TS'] ?? null;
                            $cekPortfoyde = ((int) ($r['DURUM_KOD'] ?? 0) === 1);
                            $isPast = $cekPortfoyde && ($dueDate !== null && (int) $dueDate < $todayTs);
                            $isUpcoming = $cekPortfoyde && ($dueDate !== null && (int) $dueDate >= $todayTs && (int) $dueDate <= $next7DaysTs);
                            $rowCls = $isPast ? 'gecikmis' : ($isUpcoming ? 'yaklasan' : '');
                            $dateCls = $isPast ? 'gec' : ($isUpcoming ? 'yak' : '');
                            $db = cekDurumBilgi((int) ($r['DURUM_KOD'] ?? 0));
                            $wT = 100 * (float) $r['TUTAR'] / $maxTutar;
                            $araStr = mb_strtolower(($r['KIMDEN'] ?? '') . ' ' . ($r['NEWSERINO'] ?? '') . ' ' . ($r['PORTFOYNO'] ?? '') . ' ' . ($r['CARIHESAP'] ?? '') . ' ' . $r['_DUE_FMT'], 'UTF-8'); ?>
                        <tr class="<?php echo $rowCls; ?>"
                            data-vade="<?php echo $h($r['_DUE_ISO']); ?>"
                            data-tutar="<?php echo number_format((float) $r['TUTAR'], 4, '.', ''); ?>"
                            data-ara="<?php echo $h($araStr); ?>">
                            <td>
                                <span class="vade-t <?php echo $dateCls; ?>"><?php echo $h($r['_DUE_FMT']); ?></span>
                                <?php if ($isPast): ?><span class="rozet gec">GECİKMİŞ</span>
                                <?php elseif ($isUpcoming): ?><span class="rozet yak">7 GÜN</span><?php endif; ?>
                            </td>
                            <td class="sag">
                                <span class="deger-hucre">
                                    <span class="dolgu" style="width:<?php echo number_format(max(1.5, $wT), 1, '.', ''); ?>%;"></span>
                                    <span class="icerik"><?php echo $para($r['TUTAR']); ?> ₺</span>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="kimden-btn cek-detay-link" data-customer="<?php echo $h($r['KIMDEN']); ?>" title="<?php echo $h($r['KIMDEN']); ?>">
                                    <?php echo $h($r['KIMDEN']); ?>
                                </button>
                            </td>
                            <td><span class="durum-rozet d-<?php echo $db[1]; ?>"><?php echo $db[0]; ?></span></td>
                            <td class="mono"><?php echo $h($r['PORTFOYNO']); ?></td>
                            <td class="mono"><?php echo $h($r['NEWSERINO']); ?></td>
                            <td><?php echo $h($r['_SET_FMT']); ?></td>
                            <td class="mono"><?php echo $h($r['CARIHESAP']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>TOPLAM</td>
                            <td class="sag" style="color:var(--red);"><?php echo $para($totalAmount); ?> ₺</td>
                            <td colspan="6"><?php echo $adetF($totalCount); ?> çek · <?php echo $adetF($uniqueCari); ?> cari</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="sayfalar" id="sayfalar"></div>
        </section>

        <p class="dipnot"><b>Kapsam:</b> müşteri çekleri (kendi çeklerimiz hariç). Durumlar LOGO çek durum kodundan gelir: Portföyde, Ciro Edildi, Tahsilde / Teminatta, Tahsil Edildi. <b>Gecikmiş / 7 gün</b> işaretleri yalnız portföydeki çekler için hesaplanır. Satıra tıklayıp "Kimden" bağlantısıyla o kişinin tüm çeklerini görebilirsiniz.</p>
        <?php endif; ?>
    </main>

    <!-- Cari çek detay modalı -->
    <div id="checksModal" class="modal-arka" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="modal-kutu">
            <div class="modal-bas">
                <h5 id="modalTitle">Çek Detayları</h5>
                <button type="button" onclick="hideModal()" class="modal-kapat" aria-label="Kapat"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-icerik" id="modalBodyContent" tabindex="-1"></div>
        </div>
    </div>

    <script>
    (function () {
        var govde = document.getElementById('govde');
        if (!govde) { return; }
        var satirlar = Array.prototype.slice.call(govde.querySelectorAll('tr'));
        var araInput = document.getElementById('ara');
        var sayfalar = document.getElementById('sayfalar');
        var kayitAdet = document.getElementById('kayitAdet');
        var cipler = document.querySelectorAll('.cip');

        var SAYFA = 25, sayfa = 1, metrik = 'vade', filtreli = satirlar.slice();

        function uygula() {
            filtreli.sort(function (a, b) {
                if (metrik === 'vade') {
                    return (a.dataset.vade || '').localeCompare(b.dataset.vade || '');
                }
                return parseFloat(b.dataset.tutar) - parseFloat(a.dataset.tutar);
            });
            satirlar.forEach(function (r) { r.style.display = 'none'; });
            var bas = (sayfa - 1) * SAYFA;
            filtreli.slice(bas, bas + SAYFA).forEach(function (r) {
                r.style.display = '';
                govde.appendChild(r);
            });
            kayitAdet.textContent = filtreli.length.toLocaleString('tr-TR');
            ciz();
        }
        function ciz() {
            var toplam = Math.max(1, Math.ceil(filtreli.length / SAYFA));
            sayfalar.innerHTML = '';
            if (toplam <= 1) { return; }
            function buton(txt, aktifMi, tikla, kapali) {
                var b = document.createElement('button');
                b.textContent = txt;
                if (aktifMi) { b.classList.add('on'); }
                if (kapali) { b.disabled = true; }
                b.onclick = tikla;
                sayfalar.appendChild(b);
            }
            buton('‹', false, function () { if (sayfa > 1) { sayfa--; uygula(); } }, sayfa === 1);
            var s = Math.max(1, sayfa - 2), e = Math.min(toplam, s + 4);
            if (e - s < 4) { s = Math.max(1, e - 4); }
            for (var i = s; i <= e; i++) {
                (function (pg) { buton(String(pg), pg === sayfa, function () { sayfa = pg; uygula(); }); })(i);
            }
            buton('›', false, function () { if (sayfa < toplam) { sayfa++; uygula(); } }, sayfa === toplam);
        }
        cipler.forEach(function (cip) {
            cip.addEventListener('click', function () {
                cipler.forEach(function (x) { x.classList.remove('on'); });
                cip.classList.add('on');
                metrik = cip.getAttribute('data-m');
                sayfa = 1;
                uygula();
            });
        });
        araInput.addEventListener('input', function () {
            var q = this.value.trim().toLocaleLowerCase('tr-TR');
            filtreli = q === '' ? satirlar.slice() : satirlar.filter(function (r) {
                return (r.getAttribute('data-ara') || '').indexOf(q) !== -1;
            });
            sayfa = 1;
            uygula();
        });
        uygula();
    })();

    // Cari çek detay modalı (fetch_customer_checks.php sözleşmesi korunur: ?kimen=)
    var lastFocusedElement = null;
    function showModal() {
        lastFocusedElement = document.activeElement;
        document.getElementById('checksModal').classList.add('acik');
    }
    function hideModal() {
        document.getElementById('checksModal').classList.remove('acik');
        if (lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
            lastFocusedElement.focus();
        }
    }
    document.querySelectorAll('.cek-detay-link').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var customer = this.getAttribute('data-customer');
            document.getElementById('modalTitle').textContent = customer + ' — Çek Detayları';
            document.getElementById('modalBodyContent').innerHTML =
                '<div class="yukleniyor"><div class="halka"></div><p>Yükleniyor...</p></div>';
            showModal();
            fetch('fetch_customer_checks.php?kimen=' + encodeURIComponent(customer))
                .then(function (r) { return r.text(); })
                .then(function (html) { document.getElementById('modalBodyContent').innerHTML = html; })
                .catch(function () {
                    document.getElementById('modalBodyContent').innerHTML =
                        '<p style="color:var(--red);text-align:center;padding:20px 0;">Veri yüklenemedi.</p>';
                });
        });
    });
    document.getElementById('checksModal').addEventListener('click', function (e) {
        if (e.target === this) { hideModal(); }
    });
    document.addEventListener('keydown', function (e) {
        var modal = document.getElementById('checksModal');
        if (!modal || !modal.classList.contains('acik')) { return; }
        if (e.key === 'Escape') { e.preventDefault(); hideModal(); }
    });
    </script>
</body>
</html>

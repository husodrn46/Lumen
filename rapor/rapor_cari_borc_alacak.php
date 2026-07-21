<?php
declare(strict_types=1);

/**
 * rapor_cari_borc_alacak.php — "Tahsilat Radarı" (yeniden tasarım 2026-07-07).
 *
 * Borçlu carileri BÜYÜKLÜK + YAŞLILIK iki boyutuyla gösterir: hücre-içi dolgulu
 * lig tablosu + Pareto (alacağın %80'i kimde) + SON ALIM rozeti (uzun süredir
 * uğramayan borçlu kırmızı yanar) + sıralama çipleri (Bakiye / Son Alım —
 * "en eskiden beri gelmeyen" tahsilat öncelik listesi). Filtreler korunur
 * (arama, min tutar, hariç-tut, limit — hariç/limit katlanır bölümde).
 * Veri tanımı DEĞİŞMEDİ (GNTOTCL TOTTYP=1, DEBIT−CREDIT>0; Excel'le uyumlu).
 * Kaldırılanlar: hep boş Not1/Not2 sütunları, tek-değerli "Durum" pill'i,
 * Material Icons.
 */

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../log_ip.php");
require_once __DIR__ . '/../kontrol.php';

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$canSeeBalance = (m_p_yetki($terminalkullanici, 'CR1') == 1);

$rawQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$q = $rawQ !== '' ? turkce($rawQ) : '';

// Minimum gösterim tutarı
$rawMin = isset($_GET['min']) ? trim((string) $_GET['min']) : '';
$minAbs = 0.0;
if ($rawMin !== '') {
    $minNorm = str_replace(' ', '', $rawMin);
    if (str_contains($minNorm, ',') && str_contains($minNorm, '.')) {
        $minNorm = str_replace('.', '', $minNorm);
        $minNorm = str_replace(',', '.', $minNorm);
    } elseif (str_contains($minNorm, ',')) {
        $minNorm = str_replace(',', '.', $minNorm);
    }
    if (is_numeric($minNorm)) { $minAbs = max(0.0, (float) $minNorm); }
}

// Hariç tutulacaklar (parametre yoksa varsayılan liste)
$defaultExclude = ['genel gider'];
$rawExclude = isset($_GET['exclude']) ? trim((string) $_GET['exclude']) : '';
$excludeList = [];
if (!isset($_GET['exclude'])) {
    $excludeList = $defaultExclude;
    $rawExclude = implode(', ', $defaultExclude);
} else {
    $parts = preg_split('/[\r\n,;]+/', $rawExclude) ?: [];
    foreach ($parts as $p) {
        $p = trim((string) $p);
        if ($p !== '') { $excludeList[] = $p; }
    }
    $excludeList = array_slice(array_values(array_unique($excludeList)), 0, 20);
}

$defaultLimit = isset($carilistesayisi) ? (int) $carilistesayisi : 1000;
$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : $defaultLimit;
$limit = max(50, min($limit, 10000));

$rows = [];
$totals = ['TOPLAM_BORC' => 0.0, 'BORCLU_SAYI' => 0, 'TOPLAM_SAYI' => 0];
$pageError = '';
$avgDebt = 0.0;

$balanceExpr = "(ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0))";

if ($canSeeBalance) {
    $where = [];
    $params = [];
    $where[] = "C.ACTIVE = 0";

    $normalize = static fn(string $col): string =>
        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(" .
        "{$col}, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS";

    if ($q !== '') {
        $like = "%{$q}%";
        $where[] = "(" . $normalize('C.DEFINITION_') . " LIKE :p1 OR " . $normalize('C.CODE') . " LIKE :p2 OR " . $normalize('C.CITY') . " LIKE :p3)";
        $params[':p1'] = $like;
        $params[':p2'] = $like;
        $params[':p3'] = $like;
    }

    $where[] = "{$balanceExpr} > 0";

    if ($minAbs > 0) {
        $where[] = "{$balanceExpr} >= :min_abs";
        $params[':min_abs'] = $minAbs;
    }

    if ($excludeList !== []) {
        $i = 0;
        foreach ($excludeList as $ex) {
            $i++;
            $like = "%" . turkce($ex) . "%";
            // PDO_SQLSRV: aynı named param'ı iki kez kullanmak 07002 verir → ayrı adlar
            $where[] = "(" . $normalize('C.DEFINITION_') . " NOT LIKE :ex_def{$i} AND " . $normalize('C.CODE') . " NOT LIKE :ex_code{$i})";
            $params[":ex_def{$i}"] = $like;
            $params[":ex_code{$i}"] = $like;
        }
    }

    $whereSql = implode("\n    AND ", $where);

    $sqlList = "
        SELECT TOP {$limit}
            C.LOGICALREF AS CARIID, C.CODE AS KODU, C.DEFINITION_ AS UNVANI, C.CITY AS SEHIR,
            ISNULL(G.DEBIT, 0) AS BORC, ISNULL(G.CREDIT, 0) AS ALACAK,
            LS.SON_URUN_ALIMI,
            {$balanceExpr} AS BAKIYE
        FROM {$firma}CLCARD C WITH(NOLOCK)
        LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK) ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
        OUTER APPLY (
            SELECT TOP 1 I.DATE_ AS SON_URUN_ALIMI
            FROM {$firmadonem}INVOICE I WITH(NOLOCK)
            WHERE I.CLIENTREF = C.LOGICALREF AND I.CANCELLED = 0 AND I.TRCODE IN (7, 8)
              AND EXISTS (SELECT 1 FROM {$firmadonem}STLINE L WITH(NOLOCK)
                          WHERE L.INVOICEREF = I.LOGICALREF AND L.CANCELLED = 0 AND L.LINETYPE = 0)
            ORDER BY I.DATE_ DESC, I.LOGICALREF DESC
        ) LS
        WHERE {$whereSql}
        ORDER BY {$balanceExpr} DESC
    ";

    $sqlTotals = "
        SELECT SUM(X.BAKIYE) AS TOPLAM_BORC, COUNT(*) AS TOPLAM_SAYI
        FROM (
            SELECT {$balanceExpr} AS BAKIYE
            FROM {$firma}CLCARD C WITH(NOLOCK)
            LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK) ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
            WHERE {$whereSql}
        ) X
    ";

    try {
        $calistir = static function (string $sql, array $p, string $aksiyon) use ($dbh, $q, $limit) {
            if (function_exists('app_db_prepare_execute')) {
                return app_db_prepare_execute($dbh, $sql, $p, [
                    'page' => 'rapor/rapor_cari_borc_alacak.php', 'action' => $aksiyon,
                    'has_search' => ($q !== ''), 'limit' => $limit,
                ]);
            }
            $st = $dbh->prepare($sql);
            $st->execute($p);
            return $st;
        };

        $totalsRaw = $calistir($sqlTotals, $params, 'totals')->fetch(PDO::FETCH_ASSOC) ?: [];
        $totals['TOPLAM_BORC'] = (float) ($totalsRaw['TOPLAM_BORC'] ?? 0);
        $totals['TOPLAM_SAYI'] = (int) ($totalsRaw['TOPLAM_SAYI'] ?? 0);
        $totals['BORCLU_SAYI'] = $totals['TOPLAM_SAYI'];
        $avgDebt = $totals['BORCLU_SAYI'] > 0 ? $totals['TOPLAM_BORC'] / $totals['BORCLU_SAYI'] : 0.0;

        $rows = $calistir($sqlList, $params, 'list')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        if (function_exists('app_log_exception')) {
            $ref = app_log_exception($e, 'rapor/rapor_cari_borc_alacak.php', ['q' => mb_substr($rawQ, 0, 120), 'limit' => $limit]);
            $pageError = "Beklenmeyen bir hata oluştu. Ref: " . htmlspecialchars((string) $ref, ENT_QUOTES, 'UTF-8');
        } else {
            $pageError = "Beklenmeyen bir hata oluştu: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
        $rows = [];
    }
}

// ── Satır zenginleştirme: pay, kümülatif (Pareto), son-alım günü ──
$bugun = new DateTimeImmutable('today');
$maxBakiye = 1.0;
foreach ($rows as $r) { $maxBakiye = max($maxBakiye, (float) $r['BAKIYE']); }
$listToplam = (float) array_sum(array_map(static fn($r): float => (float) $r['BAKIYE'], $rows));

$kum = 0.0;
$pareto80 = 0;
$enYasli = ['ad' => '-', 'gun' => -1, 'bakiye' => 0.0];
foreach ($rows as $i => $r) {
    $b = (float) $r['BAKIYE'];
    $pay = $listToplam > 0 ? 100 * $b / $listToplam : 0.0;
    $kum += $pay;
    $rows[$i]['PAY'] = $pay;
    $rows[$i]['KUM'] = $kum;
    if ($pareto80 === 0 && $kum >= 80.0) { $pareto80 = $i + 1; }

    $sonT = !empty($r['SON_URUN_ALIMI']) ? new DateTimeImmutable(substr((string) $r['SON_URUN_ALIMI'], 0, 10)) : null;
    $gun = $sonT ? (int) $sonT->diff($bugun)->format('%a') : -1;
    $rows[$i]['SON_GUN'] = $gun;
    $rows[$i]['SON_TXT'] = $sonT ? $sonT->format('d.m.Y') : '-';
    if ($gun > $enYasli['gun'] && $b > 0) { $enYasli = ['ad' => (string) $r['UNVANI'], 'gun' => $gun, 'bakiye' => $b]; }
}
if ($pareto80 === 0) { $pareto80 = count($rows); }
$enBuyuk = $rows[0] ?? null;

$h = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$para = static fn($x): string => number_format((float) $x, 2, ',', '.');
$adetF = static fn($x): string => number_format((float) $x, 0, ',', '.');
$kisa = static function ($v): string {
    $v = (float) $v;
    if (abs($v) >= 1_000_000) { return number_format($v / 1_000_000, 2, ',', '.') . ' M'; }
    if (abs($v) >= 1_000)     { return number_format($v / 1_000, 0, ',', '.') . ' B'; }
    return number_format($v, 0, ',', '.');
};
$sonRozet = static function (int $gun): array {
    if ($gun < 0) { return ['gri', 'alım yok']; }
    if ($gun <= 30) { return ['yesil', $gun . ' gün']; }
    if ($gun <= 60) { return ['sari', $gun . ' gün']; }
    return ['kirmizi', $gun . ' gün'];
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tahsilat Radarı — Cari Borç</title>
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
        .top-in { max-width:1180px; margin:0 auto; padding:12px 22px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
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

        main { max-width:1180px; margin:0 auto; padding:20px 22px 56px; }

        /* Filtre çubuğu */
        .filtre-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--golge); padding:13px 16px; margin-bottom:16px; animation:giris .5s cubic-bezier(.22,1,.36,1) both; }
        .filtre-satir { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
        .ara-kutu { position:relative; flex:1 1 260px; min-width:200px; }
        .ara-kutu i { position:absolute; left:13px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:13px; }
        .ara-kutu input { width:100%; padding:11px 13px 11px 36px; font-family:inherit; font-size:14px; border:1px solid var(--border); border-radius:11px; outline:none; transition:.16s; }
        .ara-kutu input:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.1); }
        .min-kutu { position:relative; flex:0 1 150px; }
        .min-kutu input { width:100%; padding:11px 13px; font-family:inherit; font-size:14px; border:1px solid var(--border); border-radius:11px; outline:none; transition:.16s; }
        .min-kutu input:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.1); }
        .gelismis { width:100%; margin-top:10px; }
        .gelismis summary { list-style:none; cursor:pointer; font-size:11.5px; font-weight:700; color:var(--text-3); display:inline-flex; align-items:center; gap:6px; }
        .gelismis summary::-webkit-details-marker { display:none; }
        .gelismis summary:hover { color:var(--red); }
        .gelismis[open] summary i { transform:rotate(180deg); }
        .gelismis-ic { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; }
        .g-alan { flex:1 1 280px; }
        .g-alan label { display:block; font-size:10.5px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:var(--text-3); margin-bottom:5px; }
        .g-alan input { width:100%; padding:10px 12px; font-family:inherit; font-size:13.5px; border:1px solid var(--border); border-radius:10px; outline:none; }
        .g-alan input:focus { border-color:var(--red); box-shadow:0 0 0 3px rgba(111,16,34,.1); }
        .g-alan small { display:block; font-size:10.5px; color:var(--text-3); margin-top:4px; }
        .g-alan.dar { flex:0 1 140px; }

        .vurgular { display:grid; grid-template-columns:repeat(auto-fit,minmax(215px,1fr)); gap:12px; margin-bottom:16px; }
        .vurgu { background:var(--card); border:1px solid var(--border); border-radius:15px; padding:13px 16px; display:flex; align-items:center; gap:12px; box-shadow:var(--golge); animation:giris .5s cubic-bezier(.22,1,.36,1) both; }
        .vurgu:nth-child(2) { animation-delay:50ms; } .vurgu:nth-child(3) { animation-delay:100ms; } .vurgu:nth-child(4) { animation-delay:150ms; }
        .vurgu-ico { width:40px; height:40px; border-radius:12px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:16px; }
        .vurgu.yes .vurgu-ico { background:#ecfdf5; color:var(--emerald); }
        .vurgu.alt .vurgu-ico { background:var(--amber-soft); color:var(--amber); }
        .vurgu.kir .vurgu-ico { background:var(--red-soft); color:var(--red); }
        .vurgu.mor .vurgu-ico { background:#f5f3ff; color:#7c3aed; }
        .vurgu-l { font-size:10px; font-weight:700; letter-spacing:.4px; text-transform:uppercase; color:var(--text-3); }
        .vurgu-v { font-size:16.5px; font-weight:800; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .vurgu-s { font-size:10.5px; color:var(--text-3); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        .uyari-kart { background:var(--red-soft); border:1px solid rgba(111,16,34,.3); color:var(--red-koyu); border-radius:14px; padding:13px 16px; font-size:13px; display:flex; align-items:center; gap:10px; margin-bottom:16px; }
        .uyari-kart i { color:var(--red); font-size:17px; }

        /* Lig tablosu */
        .lig-kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:var(--golge); overflow:hidden; animation:giris .5s cubic-bezier(.22,1,.36,1) .12s both; }
        .lig-bas { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .lig-bas .baslik { font-size:13.5px; font-weight:700; display:inline-flex; align-items:center; gap:8px; }
        .lig-bas .baslik i { color:var(--red); }
        .siralayici { display:inline-flex; gap:6px; }
        .cip { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:99px; border:1.5px solid var(--border); background:#fff; color:var(--text-2); font-family:inherit; font-size:12px; font-weight:700; cursor:pointer; transition:.16s; }
        .cip:hover { border-color:var(--red); color:var(--red); }
        .cip.on { background:var(--red); border-color:var(--red); color:#fff; box-shadow:0 5px 12px -7px rgba(111,16,34,.55); }
        .kayit-not { margin-left:auto; font-size:11px; color:var(--text-3); font-weight:600; white-space:nowrap; }

        .tw { overflow-x:auto; }
        table.lig { width:100%; border-collapse:collapse; min-width:980px; }
        .lig th { padding:10px 14px; font-size:10px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); background:#fbf8fa; border-bottom:1px solid var(--border); text-align:right; white-space:nowrap; }
        .lig th:nth-child(1), .lig th:nth-child(2) { text-align:left; }
        .lig td { padding:9px 14px; border-bottom:1px solid #f5f1f4; font-size:12.5px; text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; vertical-align:middle; }
        .lig td:nth-child(1) { width:46px; }
        .lig td:nth-child(2) { text-align:left; max-width:300px; }
        .lig tr:hover td { background:#fdfbfc; }
        .sira { display:inline-flex; align-items:center; justify-content:center; min-width:27px; height:27px; padding:0 5px; border-radius:8px; background:#f4eff3; color:var(--text-2); font-size:11px; font-weight:800; }
        tr.ilk3 .sira { background:linear-gradient(135deg,#fbbf24,#d97706); color:#fff; }
        .m-ad { font-weight:700; font-size:12.5px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:290px; }
        .m-alt { font-size:10.5px; color:var(--text-3); display:flex; gap:8px; }
        .deger-hucre { position:relative; display:block; min-width:150px; padding:3px 0; }
        .deger-hucre .dolgu { position:absolute; inset:0 auto 0 0; border-radius:6px; background:linear-gradient(90deg,rgba(52,211,153,.22),rgba(5,150,105,.16)); }
        .deger-hucre span { position:relative; padding-right:4px; font-weight:800; color:var(--emerald); }
        .pay-t, .kum-t { color:var(--text-3); font-size:11.5px; }
        .p80 { display:inline-flex; align-items:center; gap:4px; margin-left:6px; font-size:9px; font-weight:800; letter-spacing:.4px; padding:2px 7px; border-radius:99px; background:var(--amber-soft); color:var(--amber); border:1px solid rgba(217,119,6,.3); }
        .yan-t { color:var(--text-2); font-size:11.5px; }
        .son-rozet { display:inline-block; font-size:10px; font-weight:700; padding:2px 8px; border-radius:99px; }
        .son-rozet.yesil { background:#ecfdf5; color:var(--emerald); }
        .son-rozet.sari { background:var(--amber-soft); color:var(--amber); }
        .son-rozet.kirmizi { background:var(--red-soft); color:var(--red); }
        .son-rozet.gri { background:#f3f0f5; color:#6b6472; }
        .hareket-btn { display:inline-flex; align-items:center; gap:5px; padding:6px 11px; border-radius:9px; background:#fff; border:1px solid var(--border); color:var(--text-2); font-size:11px; font-weight:700; text-decoration:none; transition:.15s; }
        .hareket-btn:hover { border-color:var(--red); color:var(--red); }
        .lig tfoot td { border-top:2px solid var(--border); background:#fbf8fa; font-weight:800; }

        .sayfalar { display:flex; justify-content:flex-end; gap:6px; padding:12px 16px; flex-wrap:wrap; }
        .sayfalar button { padding:7px 12px; font-family:inherit; font-size:12px; font-weight:700; background:#fff; color:var(--text-2); border:1px solid var(--border); border-radius:9px; cursor:pointer; transition:.14s; }
        .sayfalar button:hover:not(:disabled) { border-color:var(--red); color:var(--red); }
        .sayfalar button.on { background:var(--red); color:#fff; border-color:var(--red); }
        .sayfalar button:disabled { opacity:.4; cursor:not-allowed; }

        .bos-genel { padding:56px 20px; text-align:center; color:var(--text-3); }
        .bos-genel i { font-size:38px; display:block; margin-bottom:12px; color:#e6d8de; }

        @keyframes giris { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

        @media (max-width:760px) {
            main { padding:14px 12px 46px; }
            .aksiyon { margin-left:0; width:100%; }
            .aksiyon .btn span { display:none; }
            .kayit-not { margin-left:0; }
            .lig td:first-child, .lig th:first-child { position:sticky; left:0; background:#fff; z-index:2; }
            .lig th:first-child { background:#fbf8fa; z-index:3; }
        }
        @media print {
            body { background:#fff; }
            .top, .filtre-kart, .siralayici, .sayfalar, .hareket-btn { display:none !important; }
            .vurgu, .lig-kart { box-shadow:none; break-inside:avoid; }
            .deger-hucre .dolgu, .son-rozet { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }
        html.lumen-dark .vurgu, html.lumen-dark .filtre-kart, html.lumen-dark .lig-kart, html.lumen-dark .geri, html.lumen-dark .btn, html.lumen-dark .cip { background:var(--card); }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="dashboard.php" class="geri" title="Rapor listesine dön"><i class="fa-solid fa-arrow-left"></i></a>
            <span class="t-ico"><i class="fa-solid fa-satellite-dish"></i></span>
            <div class="t-baslik">
                <h1>Tahsilat Radarı</h1>
                <p>borçlu cariler · büyüklük + son alım yaşı</p>
            </div>
            <div class="aksiyon">
                <button onclick="window.print()" class="btn" title="Yazdır"><i class="fa-solid fa-print"></i><span>Yazdır</span></button>
                <?php $excelQuery = http_build_query(['q' => $rawQ, 'min' => $rawMin, 'exclude' => $rawExclude, 'limit' => (int) $limit]); ?>
                <a href="rapor_cari_borc_alacak_excel_xlsx.php?<?php echo $h($excelQuery); ?>" class="btn birincil" title="Excel indir"><i class="fa-solid fa-file-excel"></i><span>Excel</span></a>
            </div>
        </div>
    </header>

    <main>
        <!-- Filtreler -->
        <form method="get" class="filtre-kart">
            <div class="filtre-satir">
                <div class="ara-kutu">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="q" value="<?php echo $h($rawQ); ?>" placeholder="Firma adı, kodu veya şehir...">
                </div>
                <div class="min-kutu">
                    <input type="number" name="min" min="0" step="0.01" value="<?php echo $h($rawMin); ?>" placeholder="Min tutar ₺">
                </div>
                <button type="submit" class="btn birincil"><i class="fa-solid fa-filter"></i> Uygula</button>
                <details class="gelismis" <?php echo (isset($_GET['exclude']) || isset($_GET['limit'])) ? 'open' : ''; ?>>
                    <summary><i class="fa-solid fa-chevron-down"></i> Gelişmiş (hariç tut · limit)</summary>
                    <div class="gelismis-ic">
                        <div class="g-alan">
                            <label>Hariç Tut</label>
                            <input type="text" name="exclude" value="<?php echo $h($rawExclude); ?>" placeholder="örn: genel gider">
                            <small>Virgülle ayırın; ada veya koda uyan cariler listelenmez.</small>
                        </div>
                        <div class="g-alan dar">
                            <label>Limit</label>
                            <input type="number" name="limit" min="50" max="10000" value="<?php echo (int) $limit; ?>">
                        </div>
                    </div>
                </details>
            </div>
        </form>

        <?php if (!$canSeeBalance): ?>
            <div class="uyari-kart"><i class="fa-solid fa-lock"></i><span><b>Bakiye görme yetkiniz yok (CR1).</b> Bu rapor cari bakiyeleri gösterdiği için yetki gerektirir.</span></div>
        <?php elseif ($pageError !== ''): ?>
            <div class="uyari-kart"><i class="fa-solid fa-triangle-exclamation"></i><span><?php echo $pageError; ?></span></div>
        <?php else: ?>

        <!-- Vurgular -->
        <div class="vurgular">
            <div class="vurgu yes">
                <span class="vurgu-ico"><i class="fa-solid fa-sack-dollar"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Toplam Alacak</div>
                    <div class="vurgu-v" style="color:var(--emerald);"><?php echo $kisa($totals['TOPLAM_BORC']); ?> ₺</div>
                    <div class="vurgu-s"><?php echo $adetF($totals['TOPLAM_SAYI']); ?> borçlu cari · ort. <?php echo $kisa($avgDebt); ?> ₺</div>
                </div>
            </div>
            <div class="vurgu alt">
                <span class="vurgu-ico"><i class="fa-solid fa-chart-pie"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">Alacağın %80'i</div>
                    <div class="vurgu-v"><?php echo $adetF($pareto80); ?> caride</div>
                    <div class="vurgu-s"><?php echo count($rows) > 0 ? 'listenin %' . number_format(100 * $pareto80 / max(1, count($rows)), 1, ',', '.') . '\'i' : '—'; ?></div>
                </div>
            </div>
            <div class="vurgu mor">
                <span class="vurgu-ico"><i class="fa-solid fa-trophy"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">En Büyük Borçlu</div>
                    <div class="vurgu-v" style="font-size:13.5px;"><?php echo $enBuyuk ? $h(mb_substr((string) $enBuyuk['UNVANI'], 0, 24)) : '—'; ?></div>
                    <div class="vurgu-s"><?php echo $enBuyuk ? $kisa((float) $enBuyuk['BAKIYE']) . ' ₺ · pay %' . number_format($enBuyuk['PAY'], 1, ',', '.') : 'veri yok'; ?></div>
                </div>
            </div>
            <div class="vurgu kir">
                <span class="vurgu-ico"><i class="fa-solid fa-hourglass-end"></i></span>
                <div style="min-width:0;">
                    <div class="vurgu-l">En Uzun Sessizlik</div>
                    <div class="vurgu-v" style="font-size:13.5px;"><?php echo $enYasli['gun'] >= 0 ? $h(mb_substr($enYasli['ad'], 0, 24)) : '—'; ?></div>
                    <div class="vurgu-s"><?php echo $enYasli['gun'] >= 0 ? $enYasli['gun'] . ' gündür alım yok · ' . $kisa($enYasli['bakiye']) . ' ₺ borç' : '—'; ?></div>
                </div>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <div class="lig-kart"><div class="bos-genel">
                <i class="fa-regular fa-folder-open"></i>
                <b>Kayıt bulunamadı</b>
            </div></div>
        <?php else: ?>

        <!-- Radar tablosu -->
        <section class="lig-kart">
            <div class="lig-bas">
                <span class="baslik"><i class="fa-solid fa-table-list"></i> Borçlu Listesi</span>
                <div class="siralayici" role="group" aria-label="Sıralama ölçütü">
                    <button type="button" class="cip on" data-m="bakiye"><i class="fa-solid fa-turkish-lira-sign"></i> Bakiye</button>
                    <button type="button" class="cip" data-m="gun"><i class="fa-solid fa-hourglass-end"></i> Son Alım (eski → yeni)</button>
                </div>
                <span class="kayit-not">gösterilen: <span id="kayitAdet"><?php echo count($rows); ?></span> / toplam <?php echo $adetF($totals['TOPLAM_SAYI']); ?></span>
            </div>
            <div class="tw">
                <table class="lig">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Cari</th>
                            <th>Bakiye (Borç)</th>
                            <th>Pay</th>
                            <th class="kum-bas">Küm. Pay</th>
                            <th>Borç</th>
                            <th>Alacak</th>
                            <th>Son Alım</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="govde">
                        <?php foreach ($rows as $i => $r):
                            $b = (float) $r['BAKIYE'];
                            $w = 100 * $b / $maxBakiye;
                            [$rc, $rt] = $sonRozet((int) $r['SON_GUN']);
                            // "Son alım" sıralaması: alımı hiç olmayan en yaşlı sayılır
                            $gunSort = (int) $r['SON_GUN'] < 0 ? 99999 : (int) $r['SON_GUN'];
                            $paretoBurada = ($i + 1 === $pareto80 && $pareto80 < count($rows)); ?>
                        <tr data-bakiye="<?php echo number_format($b, 4, '.', ''); ?>"
                            data-gun="<?php echo $gunSort; ?>"
                            data-wb="<?php echo number_format($w, 2, '.', ''); ?>">
                            <td><span class="sira"><?php echo $i + 1; ?></span></td>
                            <td>
                                <div class="m-ad" title="<?php echo $h($r['UNVANI']); ?>"><?php echo $h($r['UNVANI']); ?></div>
                                <div class="m-alt"><span><?php echo $h($r['KODU']); ?></span><?php if (!empty($r['SEHIR'])): ?><span><i class="fa-solid fa-location-dot" style="margin-right:2px;"></i><?php echo $h($r['SEHIR']); ?></span><?php endif; ?></div>
                            </td>
                            <td>
                                <span class="deger-hucre">
                                    <span class="dolgu" style="width:<?php echo number_format(max(1.5, $w), 1, '.', ''); ?>%;"></span>
                                    <span><?php echo $para($b); ?> ₺</span>
                                </span>
                            </td>
                            <td class="pay-t">%<?php echo number_format($r['PAY'], 2, ',', '.'); ?></td>
                            <td class="kum-t">%<?php echo number_format($r['KUM'], 1, ',', '.'); ?><?php if ($paretoBurada): ?><span class="p80"><i class="fa-solid fa-flag"></i>%80</span><?php endif; ?></td>
                            <td class="yan-t"><?php echo $para((float) $r['BORC']); ?></td>
                            <td class="yan-t"><?php echo $para((float) $r['ALACAK']); ?></td>
                            <td><span class="son-rozet <?php echo $rc; ?>" title="Son ürün alımı: <?php echo $h($r['SON_TXT']); ?>"><?php echo $h($rt); ?></span></td>
                            <td><a class="hareket-btn" href="<?php echo APP_ROOT_URL; ?>/lg_hareket.php?cariid=<?php echo (int) $r['CARIID']; ?>"><i class="fa-solid fa-list"></i> Hareket</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td></td>
                            <td>TOPLAM (listelenen)</td>
                            <td style="color:var(--emerald);"><?php echo $para($listToplam); ?> ₺</td>
                            <td class="pay-t">%100</td>
                            <td class="kum-t"></td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="sayfalar" id="sayfalar"></div>
        </section>
        <?php endif; ?>
        <?php endif; ?>
    </main>

    <script>
    (function () {
        var govde = document.getElementById('govde');
        if (!govde) { return; }
        var satirlar = Array.prototype.slice.call(govde.querySelectorAll('tr'));
        var sayfalar = document.getElementById('sayfalar');
        var kayitAdet = document.getElementById('kayitAdet');
        var cipler = document.querySelectorAll('.cip');
        var kumSutun = document.querySelectorAll('.kum-bas, .kum-t');

        var SAYFA = 25, sayfa = 1, metrik = 'bakiye';

        function uygula() {
            // bakiye: büyükten küçüğe · gun: en yaşlı (büyük gün) üstte
            satirlar.sort(function (a, b) { return parseFloat(b.dataset[metrik]) - parseFloat(a.dataset[metrik]); });
            satirlar.forEach(function (r) { r.style.display = 'none'; });
            var bas = (sayfa - 1) * SAYFA;
            satirlar.slice(bas, bas + SAYFA).forEach(function (r, idx) {
                r.style.display = '';
                govde.appendChild(r);
                r.querySelector('.sira').textContent = bas + idx + 1;
                r.classList.toggle('ilk3', metrik === 'bakiye' && bas + idx < 3);
            });
            kayitAdet.textContent = satirlar.length;
            kumSutun.forEach(function (el) { el.style.display = metrik === 'bakiye' ? '' : 'none'; });
            ciz();
        }
        function ciz() {
            var toplam = Math.max(1, Math.ceil(satirlar.length / SAYFA));
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
        uygula();
    })();
    </script>
</body>
</html>

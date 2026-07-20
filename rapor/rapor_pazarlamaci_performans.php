<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// Yetki kontrolu
if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$canSeeBalance = (m_p_yetki($terminalkullanici, 'CR1') == 1);

function money_tr_pz(float|int|string|null $amount): string
{
    $decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
    return number_format((float) ($amount ?? 0), $decimals, ',', '.') . ' &#8378;';
}

/* -----------------------------------------------------------------
 *  Parametreler – 2026 yili sabit
 * ----------------------------------------------------------------- */
$year = 2026;

$cariPrefix = isset($_GET['prefix']) && trim((string) $_GET['prefix']) !== ''
    ? trim((string) $_GET['prefix'])
    : 'TMÇN';

$monthNames = [
    1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan',
    5 => 'Mayıs', 6 => 'Haziran', 7 => 'Temmuz', 8 => 'Ağustos',
    9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık',
];

/* -----------------------------------------------------------------
 *  1) MÜŞTERİ BAKİYELERİ (DEVİR HARİÇ) – 2026 Dönemi
 *     Genel toplam (GNTOTCL TOTTYP=1) − devir fişleri (CLFLINE TRCODE=14).
 *     DİKKAT: Bu kurulumun LV_ GNTOTCL view'ında TOTTYP=0 (devir) satırı
 *     YOK (yalnız 1 ve 2 var) — eski "TOTTYP=0'ı düş" yaklaşımı hep 0
 *     düşüyor, bakiyeyi devir DAHİL gösteriyordu (2026-07-09 düzeltildi).
 * ----------------------------------------------------------------- */
$customers = [];
$totals = [
    'TOPLAM_BORC' => 0.0,
    'TOPLAM_ALACAK' => 0.0,
    'NET' => 0.0,
    'MUSTERI_SAYI' => 0,
    'BORCLU_SAYI' => 0,
    'ALACAKLI_SAYI' => 0,
];

// Devir hariç bakiye ifadesi: Genel toplam - Devir fişleri = Sadece 2026 dönemi
$balanceExpr = "(
    (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0))
    - ISNULL(DV.DEVIR, 0)
)";

if ($canSeeBalance) {
    $sqlCustomers = "
        SELECT
            C.LOGICALREF AS CARIID,
            C.CODE        AS KODU,
            C.DEFINITION_ AS UNVANI,
            C.CITY        AS SEHIR,
            C.TELNRS1,
            (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE_TOPLAM,
            ISNULL(DV.DEVIR, 0) AS BAKIYE_DEVIR,
            {$balanceExpr} AS BAKIYE
        FROM {$firma}CLCARD C WITH(NOLOCK)
        LEFT JOIN {$firmadonemx}GNTOTCL G WITH(NOLOCK)
            ON G.CARDREF = C.LOGICALREF AND G.TOTTYP = 1
        LEFT JOIN (
            SELECT CLIENTREF,
                   SUM(CASE WHEN SIGN = 0 THEN AMOUNT ELSE -AMOUNT END) AS DEVIR
            FROM {$firmadonem}CLFLINE WITH(NOLOCK)
            WHERE TRCODE = 14 AND CANCELLED = 0
            GROUP BY CLIENTREF
        ) DV ON DV.CLIENTREF = C.LOGICALREF
        WHERE C.ACTIVE = 0
          AND C.CODE LIKE :prefix
        ORDER BY {$balanceExpr} DESC
    ";

    try {
        $stmt = $dbh->prepare($sqlCustomers);
        $stmt->execute([':prefix' => $cariPrefix . '%']);
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($customers as $c) {
            $b = (float) $c['BAKIYE'];
            $totals['MUSTERI_SAYI']++;
            if ($b > 0) {
                $totals['TOPLAM_BORC'] += $b;
                $totals['BORCLU_SAYI']++;
            } elseif ($b < 0) {
                $totals['TOPLAM_ALACAK'] += abs($b);
                $totals['ALACAKLI_SAYI']++;
            }
            $totals['NET'] += $b;
        }
    } catch (Throwable $e) {
        $customers = [];
    }
}

/* -----------------------------------------------------------------
 *  2) AYLIK SATIŞ PERFORMANSI – 2026 yılı, TMÇN müşterileri
 *     STLINE üzerinden satış fişleri (TRCODE 7/8)
 *     Sadece 2026-01-01 ile 2027-01-01 arasındaki hareketler
 * ----------------------------------------------------------------- */
$monthly = [];
for ($i = 1; $i <= 12; $i++) {
    $monthly[$i] = ['C' => 0, 'QTY' => 0.0, 'NET' => 0.0];
}
$yearlyTotal = 0.0;

$sqlMonthly = "
    SELECT
        MONTH(SL.DATE_) AS M,
        COUNT(DISTINCT SL.INVOICEREF) AS C,
        SUM(SL.AMOUNT) AS QTY,
        SUM(ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)) AS NET
    FROM {$firmadonem}STLINE SL WITH(NOLOCK)
    JOIN {$firma}CLCARD CL WITH(NOLOCK) ON SL.CLIENTREF = CL.LOGICALREF
    WHERE CL.CODE LIKE :prefix
      AND SL.TRCODE IN (7, 8)
      AND SL.DATE_ >= :startDate
      AND SL.DATE_ < :endDate
      AND SL.CANCELLED = 0
      AND SL.LINETYPE = 0
    GROUP BY MONTH(SL.DATE_)
";

try {
    $stmt = $dbh->prepare($sqlMonthly);
    $stmt->execute([
        ':prefix'    => $cariPrefix . '%',
        ':startDate' => '2026-01-01',
        ':endDate'   => '2027-01-01',
    ]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = (int) $r['M'];
        $monthly[$m]['C']   = (int) $r['C'];
        $monthly[$m]['QTY'] = (float) $r['QTY'];
        $monthly[$m]['NET'] = (float) $r['NET'];
    }
} catch (Throwable $e) {
    // Sessiz hata - monthly bos kalir
}

/* -----------------------------------------------------------------
 *  3) MÜŞTERİ BAZLI SATIŞ TOPLAMI – 2026 yılı (sadece toptan satış fişi)
 *     Her müşterinin yıl içinde yaptığı toplam ciro ve işlem sayısı
 * ----------------------------------------------------------------- */
$customerSales = [];

$sqlCustSales = "
    SELECT
        CL.LOGICALREF AS CARIID,
        COUNT(DISTINCT SL.INVOICEREF) AS ISLEM_SAYISI,
        SUM(SL.AMOUNT) AS TOPLAM_ADET,
        SUM(ISNULL(SL.LINENET, (SL.PRICE * SL.AMOUNT - SL.DISTDISC)) + ISNULL(SL.VATAMNT, 0)) AS TOPLAM_CIRO
    FROM {$firmadonem}STLINE SL WITH(NOLOCK)
    JOIN {$firma}CLCARD CL WITH(NOLOCK) ON SL.CLIENTREF = CL.LOGICALREF
    WHERE CL.CODE LIKE :prefix
      AND SL.TRCODE IN (7, 8)
      AND SL.DATE_ >= :startDate
      AND SL.DATE_ < :endDate
      AND SL.CANCELLED = 0
      AND SL.LINETYPE = 0
    GROUP BY CL.LOGICALREF
";

try {
    $stmt = $dbh->prepare($sqlCustSales);
    $stmt->execute([
        ':prefix'    => $cariPrefix . '%',
        ':startDate' => '2026-01-01',
        ':endDate'   => '2027-01-01',
    ]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $customerSales[(int) $r['CARIID']] = $r;
    }
} catch (Throwable $e) {
    // Sessiz
}

/* -----------------------------------------------------------------
 *  4) AYLIK TAHSİLAT – 2026 yılı, TMÇN müşterileri
 *     Tek kaynak: CLFLINE (cari hesap hareketleri).
 *     Hareket tarihi (CF.DATE_) bazlı — yani 2026 yılında FİİLEN
 *     yapılan tahsilatlar / alınan çek-senetler.
 *
 *     Dahil edilen TRCODE'lar (SIGN=1, alacak yönü):
 *        1  = Nakit Tahsilat
 *        4  = Alacak Dekontu
 *        20 = Gelen Havale
 *        61 = Çek Girişi (müşteriden çek alındığı an)
 *        62 = Senet Girişi (müşteriden senet alındığı an)
 *        70 = Kredi Kartı / POS Tahsilatı
 *
 *     NOT: CSCARD/CSTRANS üzerinden ayrı bir çek-senet sorgusu
 *     YAPILMAZ. Bu sorgular SETDATE (tanzim tarihi) bazlı çalışıp
 *     hem çift sayıma hem yıl sınırlarında kaymaya neden oluyordu.
 *     CLFLINE TRCODE=61/62 zaten "2026'da alınan çek/senet"i doğru
 *     tarihte (alma tarihi) ve doğru tutarda kapsar.
 * ----------------------------------------------------------------- */
$monthlyTahsilat = array_fill(1, 12, 0.0);
$yearlyTahsilat = 0.0;

$sqlTahsilat = "
    SELECT
        MONTH(CF.DATE_) AS M,
        SUM(CF.AMOUNT) AS TUTAR
    FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
    JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CF.CLIENTREF = CL.LOGICALREF
    WHERE CL.CODE LIKE :prefix
      AND CF.TRCODE IN (1, 4, 20, 61, 62, 70)
      AND CF.SIGN = 1
      AND CF.DATE_ >= '2026-01-01'
      AND CF.DATE_ < '2027-01-01'
      AND CF.CANCELLED = 0
    GROUP BY MONTH(CF.DATE_)
";

try {
    $stmt = $dbh->prepare($sqlTahsilat);
    $stmt->execute([':prefix' => $cariPrefix . '%']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $m = (int) $r['M'];
        if ($m >= 1 && $m <= 12) {
            $monthlyTahsilat[$m] = (float) $r['TUTAR'];
        }
    }
} catch (Throwable $e) {
    // Sessiz
}

/* -----------------------------------------------------------------
 *  5) MÜŞTERİ BAZLI TAHSİLAT TOPLAMI – 2026 yılı
 *     Tek kaynak: CLFLINE (4. blok ile aynı kriter, müşteri bazında)
 * ----------------------------------------------------------------- */
$customerTahsilat = [];

$sqlCustTah = "
    SELECT
        CL.LOGICALREF AS CARIID,
        SUM(CF.AMOUNT) AS TUTAR
    FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
    JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CF.CLIENTREF = CL.LOGICALREF
    WHERE CL.CODE LIKE :prefix
      AND CF.TRCODE IN (1, 4, 20, 61, 62, 70)
      AND CF.SIGN = 1
      AND CF.DATE_ >= '2026-01-01'
      AND CF.DATE_ < '2027-01-01'
      AND CF.CANCELLED = 0
    GROUP BY CL.LOGICALREF
";

try {
    $stmt = $dbh->prepare($sqlCustTah);
    $stmt->execute([':prefix' => $cariPrefix . '%']);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $id = (int) $r['CARIID'];
        $customerTahsilat[$id] = (float) $r['TUTAR'];
    }
} catch (Throwable $e) {}

/* -----------------------------------------------------------------
 *  KPI hesaplamalari – 2026 yili ozet metrikleri
 * ----------------------------------------------------------------- */
$chartLabels = $chartValues = $monthsData = [];
$totalMonthsWithSales = 0;
$bestMonth = ['M' => 0, 'NET' => 0.0];
$totalCustomersWithSales = count($customerSales);

for ($m = 1; $m <= 12; $m++) {
    $d = $monthly[$m];
    $chartLabels[] = $monthNames[$m];
    $chartValues[] = $d['NET'];
    $monthsData[] = ['M' => $m, 'C' => $d['C'], 'NET' => $d['NET']];

    $yearlyTotal += $d['NET'];
    if ($d['NET'] > 0) {
        $totalMonthsWithSales++;
        if ($d['NET'] > $bestMonth['NET']) {
            $bestMonth = ['M' => $m, 'NET' => $d['NET']];
        }
    }
}

$avgMonth = $totalMonthsWithSales > 0 ? $yearlyTotal / $totalMonthsWithSales : 0;

for ($m = 1; $m <= 12; $m++) {
    $yearlyTahsilat += $monthlyTahsilat[$m];
}
$tahsilatOrani = $yearlyTotal > 0 ? ($yearlyTahsilat / $yearlyTotal) * 100 : 0;

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pazarlamaci Performans – <?php echo htmlspecialchars($cariPrefix, ENT_QUOTES, 'UTF-8'); ?> – 2026</title>
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            margin: 0;
        }

        /* HEADER */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            min-height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(5, 150, 105, 0.18);
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.04);
        }
        .header-inner {
            max-width: 1280px; margin: 0 auto;
            min-height: 64px;
            display: flex; align-items: center; gap: 14px;
            padding: 10px 24px;
            flex-wrap: wrap;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: var(--emerald-soft); color: var(--emerald); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            font-size: 18px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 10px;
        }
        .header-title i { color: var(--emerald); font-size: 16px; }
        .header-sub {
            font-size: 11.5px; color: var(--text-3);
            margin-left: 4px; font-weight: 500;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .header-sub .chip {
            padding: 2px 8px; border-radius: 100px;
            background: var(--emerald-soft); color: var(--emerald);
            font-family: 'Courier New', monospace;
            font-size: 11px; font-weight: 700;
        }
        .header-spacer { flex: 1; }
        .header-form { display: flex; gap: 6px; align-items: center; }
        .header-form input {
            padding: 8px 12px 8px 34px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 16px;
            background: #fff url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='%239ca3af'><path d='M12 9l3-3m-6 6a4 4 0 110-8 4 4 0 010 8z'/></svg>") no-repeat 10px center;
            background-size: 14px;
            outline: none;
            width: 210px;
            min-height: 44px;
            color: var(--text-1);
        }
        .header-form input:focus {
            border-color: rgba(5, 150, 105, 0.5);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.1);
        }
        .header-form button {
            padding: 8px 14px;
            border: none;
            border-radius: 10px;
            background: var(--emerald);
            color: #fff;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            min-height: 44px;
            transition: all 0.2s ease;
        }
        .header-form button:hover { background: #047857; transform: translateY(-1px); }
        .icon-btn {
            width: 40px; height: 40px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-2);
            display: inline-flex; align-items: center; justify-content: center;
            text-decoration: none; cursor: pointer;
            transition: all 0.2s ease;
        }
        .icon-btn:hover { border-color: rgba(5, 150, 105, 0.4); color: var(--emerald); background: var(--emerald-soft); }
        .icon-btn.excel:hover { border-color: rgba(5, 150, 105, 0.4); color: var(--emerald); background: var(--emerald-soft); }

        main.page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 22px 24px 60px;
        }

        /* STAT CARDS (KPI) */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .stat-card {
            position: relative;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 18px 16px 22px;
            overflow: hidden;
            transition: all 0.25s ease;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .stat-card:nth-child(1) { animation-delay: 0.00s; }
        .stat-card:nth-child(2) { animation-delay: 0.05s; }
        .stat-card:nth-child(3) { animation-delay: 0.10s; }
        .stat-card:nth-child(4) { animation-delay: 0.15s; }
        .stat-card:nth-child(5) { animation-delay: 0.20s; }
        .stat-card:nth-child(6) { animation-delay: 0.25s; }
        .stat-card:nth-child(7) { animation-delay: 0.30s; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,0.06); }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; bottom: 0; left: 0;
            width: 4px;
        }
        .stat-card.tone-sky::before     { background: var(--sky); }
        .stat-card.tone-emerald::before { background: var(--emerald); }
        .stat-card.tone-indigo::before  { background: var(--indigo); }
        .stat-card.tone-amber::before   { background: var(--amber); }
        .stat-card.tone-red::before     { background: var(--red); }
        .stat-card.tone-purple::before  { background: var(--purple); }
        .stat-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .stat-card.tone-sky .stat-icon     { background: var(--sky-soft);     color: var(--sky); }
        .stat-card.tone-emerald .stat-icon { background: var(--emerald-soft); color: var(--emerald); }
        .stat-card.tone-indigo .stat-icon  { background: var(--indigo-soft);  color: var(--indigo); }
        .stat-card.tone-amber .stat-icon   { background: var(--amber-soft);   color: var(--amber); }
        .stat-card.tone-red .stat-icon     { background: var(--red-soft);     color: var(--red); }
        .stat-card.tone-purple .stat-icon  { background: var(--purple-soft);  color: var(--purple); }
        .stat-body { min-width: 0; flex: 1; }
        .stat-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
            margin-top: 2px;
            word-break: break-word;
        }
        .stat-card.tone-emerald .stat-value { color: var(--emerald); }
        .stat-card.tone-amber .stat-value   { color: var(--amber); }
        .stat-card.tone-red .stat-value     { color: var(--red); }
        .stat-card.tone-purple .stat-value  { color: var(--purple); }
        .stat-card.tone-indigo .stat-value  { color: var(--indigo); }
        .stat-sub {
            font-size: 11px;
            color: var(--text-3);
            margin-top: 3px;
        }
        .stat-sub .pill-ok   { color: var(--emerald); font-weight: 700; }
        .stat-sub .pill-warn { color: var(--amber);   font-weight: 700; }
        .stat-sub .pill-bad  { color: var(--red);     font-weight: 700; }

        /* GLASS CARD */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(5, 150, 105, 0.14);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 18px;
            overflow: hidden;
        }
        .card-head {
            padding: 16px 20px 14px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px;
        }
        .card-head .icon-box {
            width: 36px; height: 36px;
            flex-shrink: 0;
            border-radius: 10px;
            background: var(--emerald-soft);
            color: var(--emerald);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px;
        }
        .card-head .icon-box.tone-amber { background: var(--amber-soft); color: var(--amber); }
        .card-head h2 { font-size: 14px; font-weight: 700; color: var(--text-1); margin: 0; }
        .card-head p { font-size: 11.5px; color: var(--text-2); margin: 1px 0 0; }
        .card-head .spacer { flex: 1; }
        .card-head .meta { font-size: 11.5px; color: var(--text-3); font-weight: 500; }
        .card-body { padding: 18px 20px; }

        /* SPLIT LAYOUT */
        .split-grid { display: grid; grid-template-columns: 7fr 5fr; gap: 18px; margin-bottom: 18px; }
        .chart-wrap { position: relative; height: 380px; }

        /* TABLE */
        .gd-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .gd-table thead th {
            text-align: left;
            font-size: 10.5px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            background: #fafafa;
            white-space: nowrap;
        }
        .gd-table tbody td {
            padding: 11px 12px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--emerald-soft); }
        .gd-table tbody tr.best-row { background: linear-gradient(90deg, rgba(5, 150, 105, 0.07), transparent); }
        .gd-table tfoot td {
            padding: 12px;
            background: #fafafa;
            font-weight: 700;
            border-top: 2px solid var(--border);
            color: var(--text-1);
        }
        .gd-table .col-right  { text-align: right; }
        .gd-table .col-center { text-align: center; }
        .gd-table .mono       { font-family: 'Courier New', monospace; font-size: 11.5px; color: var(--text-2); }
        .val-money { font-weight: 700; color: var(--emerald); white-space: nowrap; }
        .val-tah   { font-weight: 700; color: var(--amber);   white-space: nowrap; }
        .val-mute  { color: var(--text-3); }
        .val-sub   { font-size: 10.5px; color: var(--text-3); margin-top: 2px; }

        /* Durum etiketleri */
        .tag {
            display: inline-flex; align-items: center;
            padding: 3px 10px; border-radius: 100px;
            font-size: 10.5px; font-weight: 700;
        }
        .tag.tag-borclu   { background: var(--emerald-soft); color: var(--emerald); }
        .tag.tag-alacakli { background: var(--red-soft);     color: var(--red); }
        .tag.tag-sifir    { background: #f3f4f6;             color: var(--text-2); }

        /* Oran */
        .ratio-good { color: var(--emerald); font-weight: 700; font-size: 11.5px; }
        .ratio-warn { color: var(--amber);   font-weight: 700; font-size: 11.5px; }
        .ratio-bad  { color: var(--red);     font-weight: 700; font-size: 11.5px; }

        /* Butonlar */
        .btn-action {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 10px; border-radius: 8px;
            border: none; cursor: pointer;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 11px; font-weight: 600;
            transition: all 0.2s ease;
            text-decoration: none;
            min-height: 32px;
        }
        .btn-action.tone-emerald { background: var(--emerald); color: #fff; }
        .btn-action.tone-emerald:hover { background: #047857; }
        .btn-action.tone-amber   { background: var(--amber);   color: #fff; }
        .btn-action.tone-amber:hover   { background: #b45309; }
        .btn-action.tone-sky     { background: var(--sky);     color: #fff; }
        .btn-action.tone-sky:hover     { background: #0369a1; }

        /* Ozel gider input */
        .gider-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 16px;
            text-align: right;
            outline: none;
            min-height: 44px;
            color: var(--text-1);
            transition: all 0.2s ease;
        }
        .gider-input:focus {
            border-color: rgba(217, 119, 6, 0.5);
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
        }

        /* Modal */
        .m-overlay {
            position: fixed; inset: 0;
            z-index: 60;
            background: rgba(17, 24, 39, 0.35);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .m-overlay.open { display: flex; }
        .m-dialog {
            background: #fff;
            border-radius: 16px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 24px 48px rgba(0,0,0,0.18);
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .m-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
        }
        .m-head h3 {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
            margin: 0;
        }
        .m-head h3 i { color: var(--emerald); }
        .m-close {
            width: 32px; height: 32px;
            border-radius: 8px;
            border: none;
            background: transparent;
            color: var(--text-2);
            font-size: 20px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .m-close:hover { background: var(--red-soft); color: var(--red); }
        .m-body { padding: 18px 20px; overflow-y: auto; flex: 1; }
        .m-foot {
            padding: 12px 20px;
            background: #fafafa;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
        }
        .m-foot .btn {
            padding: 9px 18px;
            border-radius: 10px;
            border: none;
            background: var(--emerald);
            color: #fff;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .m-foot .btn:hover { background: #047857; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .stat-row { grid-template-columns: repeat(2, 1fr); }
            .split-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 767px) {
            .top-header { min-height: 52px; }
            .header-inner { padding: 8px 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-sub { display: none; }
            .header-back { width: 32px; height: 32px; border-radius: 8px; }
            .header-form input { width: 160px; }

            main.page { padding: 14px 12px 40px; }
            .stat-row { grid-template-columns: 1fr; gap: 10px; }
            .stat-card { padding: 14px 14px 14px 18px; }
            .stat-value { font-size: 17px; }
            .card-head { padding: 14px 16px 12px; }
            .card-body { padding: 14px 16px; }
            .gd-table { font-size: 12px; }
            .gd-table thead th { padding: 8px 10px; font-size: 9.5px; }
            .gd-table tbody td { padding: 10px; }
            .chart-wrap { height: 320px; }
            .m-dialog { max-height: 92vh; }
            .m-head { padding: 12px 16px; }
            .m-body { padding: 14px 16px; }
        }

        /* PRINT */
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .glass-card, .stat-card { box-shadow: none !important; animation: none !important; }
            .top-header { position: static; }
        }
    </style>
</head>
<body>

<!-- HEADER -->
<header class="top-header no-print">
    <div class="header-inner">
        <a href="dashboard.php" class="header-back" title="Geri">
            <span class="material-icons">arrow_back</span>
        </a>
        <div class="header-divider"></div>
        <div>
            <div class="header-title">
                <i class="fa-solid fa-chart-line"></i>
                Pazarlamaci Performans – 2026
            </div>
            <div class="header-sub">
                <span class="chip"><?php echo htmlspecialchars($cariPrefix, ENT_QUOTES, 'UTF-8'); ?></span>
                kodlu musteriler – devir haric
            </div>
        </div>
        <div class="header-spacer"></div>
        <form method="get" class="header-form">
            <input type="text" name="prefix"
                value="<?php echo htmlspecialchars($cariPrefix, ENT_QUOTES, 'UTF-8'); ?>"
                placeholder="Cari kod on eki">
            <button type="submit">Uygula</button>
        </form>
        <button type="button" onclick="window.print()" class="icon-btn" title="Yazdir">
            <span class="material-icons">print</span>
        </button>
        <?php $excelQuery = http_build_query(['prefix' => $cariPrefix]); ?>
        <a href="rapor_pazarlamaci_performans_excel.php?<?php echo htmlspecialchars($excelQuery, ENT_QUOTES, 'UTF-8'); ?>"
           class="icon-btn excel" title="Excel">
            <span class="material-icons">download</span>
        </a>
    </div>
</header>

<main class="page">

    <!-- KPI Kartlari – 2026 yili ozet gostergeler -->
    <?php $tahsilEdilmeyen = $yearlyTotal - $yearlyTahsilat; ?>
    <div class="stat-row" style="grid-template-columns: repeat(7, 1fr);">
        <div class="stat-card tone-sky">
            <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
            <div class="stat-body">
                <div class="stat-label">Musteri Sayisi</div>
                <div class="stat-value"><?php echo $totals['MUSTERI_SAYI']; ?></div>
                <div class="stat-sub"><?php echo $totalCustomersWithSales; ?> aktif alici</div>
            </div>
        </div>
        <div class="stat-card tone-emerald">
            <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
            <div class="stat-body">
                <div class="stat-label">2026 Yillik Ciro</div>
                <div class="stat-value"><?php echo number_format($yearlyTotal, 0, ',', '.'); ?> &#8378;</div>
                <div class="stat-sub">ort. <?php echo number_format($avgMonth, 0, ',', '.'); ?> &#8378;/ay</div>
            </div>
        </div>
        <div class="stat-card tone-indigo">
            <div class="stat-icon"><i class="fa-solid fa-arrow-trend-up"></i></div>
            <div class="stat-body">
                <div class="stat-label">Toplam Borc</div>
                <div class="stat-value"><?php echo money_tr_pz($totals['TOPLAM_BORC']); ?></div>
                <div class="stat-sub"><?php echo $totals['BORCLU_SAYI']; ?> borclu (devir haric)</div>
            </div>
        </div>
        <div class="stat-card tone-red">
            <div class="stat-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
            <div class="stat-body">
                <div class="stat-label">Toplam Alacak</div>
                <div class="stat-value"><?php echo money_tr_pz($totals['TOPLAM_ALACAK']); ?></div>
                <div class="stat-sub"><?php echo $totals['ALACAKLI_SAYI']; ?> alacakli (devir haric)</div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <div class="stat-icon"><i class="fa-solid fa-hand-holding-dollar"></i></div>
            <div class="stat-body">
                <div class="stat-label">2026 Tahsilat</div>
                <div class="stat-value"><?php echo number_format($yearlyTahsilat, 0, ',', '.'); ?> &#8378;</div>
                <div class="stat-sub">
                    oran:
                    <span class="<?php echo $tahsilatOrani >= 80 ? 'pill-ok' : ($tahsilatOrani >= 50 ? 'pill-warn' : 'pill-bad'); ?>">
                        %<?php echo number_format($tahsilatOrani, 1, ',', '.'); ?>
                    </span>
                </div>
            </div>
        </div>
        <div class="stat-card tone-<?php echo $tahsilEdilmeyen > 0 ? 'red' : 'emerald'; ?>">
            <div class="stat-icon">
                <i class="fa-solid <?php echo $tahsilEdilmeyen > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check'; ?>"></i>
            </div>
            <div class="stat-body">
                <div class="stat-label">Acik Bakiye</div>
                <div class="stat-value"><?php echo number_format(abs($tahsilEdilmeyen), 0, ',', '.'); ?> &#8378;</div>
                <div class="stat-sub"><?php echo $tahsilEdilmeyen > 0 ? 'tahsil edilmedi' : 'fazla tahsilat'; ?></div>
            </div>
        </div>
        <div class="stat-card tone-purple">
            <div class="stat-icon"><i class="fa-solid fa-trophy"></i></div>
            <div class="stat-body">
                <div class="stat-label">En Iyi Ay</div>
                <div class="stat-value" style="font-size:16px;"><?php echo $monthNames[$bestMonth['M']] ?? '-'; ?></div>
                <div class="stat-sub"><?php echo number_format($bestMonth['NET'], 0, ',', '.'); ?> &#8378;</div>
            </div>
        </div>
    </div>

    <!-- Grafik + Aylik Tablo -->
    <div class="split-grid">
        <!-- Grafik -->
        <div class="glass-card" style="margin-bottom:0; animation-delay:0.32s;">
            <div class="card-head">
                <span class="icon-box"><i class="fa-solid fa-chart-column"></i></span>
                <div>
                    <h2>2026 Aylik Satis Dagilimi</h2>
                    <p>Satis (TRCODE 7/8) STLINE bazli</p>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-wrap">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Aylik Detay Tablosu -->
        <div class="glass-card" style="margin-bottom:0; animation-delay:0.38s;">
            <div class="card-head">
                <span class="icon-box"><i class="fa-solid fa-list-check"></i></span>
                <div>
                    <h2>2026 Aylik Detaylar</h2>
                    <p>Ay bazli satis + tahsilat karsilastirmasi</p>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <div style="overflow-x:auto;">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Ay</th>
                                <th class="col-right">Islem</th>
                                <th class="col-right">Satis</th>
                                <th class="col-right">Tahsilat</th>
                                <th class="col-right">Oran</th>
                                <th class="col-center no-print">Detay</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($monthsData as $d):
                                $isBest = ($d['M'] === $bestMonth['M'] && $d['NET'] > 0);
                                $ayTahsilat = $monthlyTahsilat[$d['M']] ?? 0;
                                $ayOran = $d['NET'] > 0 ? ($ayTahsilat / $d['NET']) * 100 : 0;
                                $ayRatioClass = $ayOran >= 80 ? 'ratio-good' : ($ayOran >= 50 ? 'ratio-warn' : 'ratio-bad');
                            ?>
                            <tr class="<?php echo $isBest ? 'best-row' : ''; ?>">
                                <td style="font-weight:600; color:var(--text-1);">
                                    <?php echo $monthNames[$d['M']]; ?>
                                    <?php if ($isBest): ?><i class="fa-solid fa-star" style="color:var(--amber); font-size:10px; margin-left:4px;"></i><?php endif; ?>
                                </td>
                                <td class="col-right"><?php echo number_format($d['C']); ?></td>
                                <td class="col-right val-money"><?php echo number_format($d['NET'], 2, ',', '.'); ?> &#8378;</td>
                                <td class="col-right val-tah"><?php echo number_format($ayTahsilat, 2, ',', '.'); ?> &#8378;</td>
                                <td class="col-right <?php echo $ayRatioClass; ?>">
                                    <?php echo $d['NET'] > 0 ? '%' . number_format($ayOran, 0) : '-'; ?>
                                </td>
                                <td class="col-center no-print" style="white-space:nowrap;">
                                    <?php if ($d['C'] > 0): ?>
                                    <button class="btn-action tone-emerald ay-detay-btn" data-month="<?php echo $d['M']; ?>" title="Faturalar">
                                        <i class="fa-solid fa-file-invoice"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($ayTahsilat > 0): ?>
                                    <button class="btn-action tone-amber ay-tahsilat-btn" data-month="<?php echo $d['M']; ?>" title="Tahsilatlar">
                                        <i class="fa-solid fa-hand-holding-dollar"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($d['C'] === 0 && $ayTahsilat <= 0): ?>
                                    <span class="val-mute" style="font-size:11px;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>TOPLAM</td>
                                <td class="col-right"><?php echo number_format(array_sum(array_column($monthsData, 'C'))); ?></td>
                                <td class="col-right val-money"><?php echo number_format($yearlyTotal, 2, ',', '.'); ?> &#8378;</td>
                                <td class="col-right val-tah"><?php echo number_format($yearlyTahsilat, 2, ',', '.'); ?> &#8378;</td>
                                <td class="col-right <?php echo $tahsilatOrani >= 80 ? 'ratio-good' : ($tahsilatOrani >= 50 ? 'ratio-warn' : 'ratio-bad'); ?>">
                                    %<?php echo number_format($tahsilatOrani, 1, ',', '.'); ?>
                                </td>
                                <td class="no-print"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Aylik Gider Tablosu – Elle girilir, otomatik toplanir -->
    <div class="glass-card" style="animation-delay:0.44s;">
        <div class="card-head">
            <span class="icon-box tone-amber"><i class="fa-solid fa-credit-card"></i></span>
            <div>
                <h2>Sirket Karti Giderleri – 2026</h2>
                <p>Tutarlari yazin, toplam otomatik hesaplanir</p>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table id="giderTable" class="gd-table">
                    <thead>
                        <tr>
                            <th style="width:140px;">Ay</th>
                            <th class="col-right" style="width:200px; color:var(--amber);">Gider</th>
                            <th class="col-right" style="color:var(--emerald); background:var(--emerald-soft);">Ay Cirosu</th>
                            <th class="col-right" style="background:#f9fafb;">Net (Ciro - Gider)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php for ($m = 1; $m <= 12; $m++):
                            $ayCiro = $monthly[$m]['NET'];
                        ?>
                        <tr data-month="<?php echo $m; ?>">
                            <td style="font-weight:600; color:var(--text-1);"><?php echo $monthNames[$m]; ?></td>
                            <td class="col-right" style="padding:6px 12px;">
                                <input type="number" step="0.01" min="0" class="gider-input"
                                       data-month="<?php echo $m; ?>" placeholder="0,00">
                            </td>
                            <td class="col-right val-money" style="background:var(--emerald-soft);"><?php echo number_format($ayCiro, 2, ',', '.'); ?> &#8378;</td>
                            <td class="col-right ay-net" data-month="<?php echo $m; ?>" data-ciro="<?php echo $ayCiro; ?>" style="background:#fafafa; font-weight:700;">
                                <?php echo number_format($ayCiro, 2, ',', '.'); ?> &#8378;
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>TOPLAM</td>
                            <td class="col-right" id="topGider" style="background:var(--amber-soft); color:var(--amber);">0,00 &#8378;</td>
                            <td class="col-right" style="background:var(--emerald-soft); color:var(--emerald);"><?php echo number_format($yearlyTotal, 2, ',', '.'); ?> &#8378;</td>
                            <td class="col-right" id="topNet" style="background:#f3f4f6; color:var(--text-1);"><?php echo number_format($yearlyTotal, 2, ',', '.'); ?> &#8378;</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Musteri Detay Tablosu -->
    <div class="glass-card" style="animation-delay:0.50s;">
        <div class="card-head">
            <span class="icon-box"><i class="fa-solid fa-address-book"></i></span>
            <div>
                <h2>Musteri Detaylari</h2>
                <p>2026 bakiye (devir haric) ve ciro</p>
            </div>
            <div class="spacer"></div>
            <div class="meta"><?php echo count($customers); ?> musteri</div>
        </div>
        <div class="card-body" style="padding:0;">
            <div style="overflow-x:auto;">
                <table id="custTable" class="gd-table">
                    <thead>
                        <tr>
                            <th>Kod</th>
                            <th>Musteri</th>
                            <th>Sehir</th>
                            <?php if ($canSeeBalance): ?>
                            <th class="col-right">Bakiye</th>
                            <th>Durum</th>
                            <?php endif; ?>
                            <th class="col-right">2026 Ciro</th>
                            <th class="col-right">Tahsilat</th>
                            <th class="col-right">Oran</th>
                            <th class="col-right">Islem</th>
                            <th class="col-right no-print">Hareket</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $colspan = $canSeeBalance ? 10 : 8; ?>
                        <?php if (!$canSeeBalance && empty($customers)): ?>
                            <tr>
                                <td colspan="<?php echo $colspan; ?>" style="text-align:center; padding:40px 16px; color:var(--text-3);">
                                    <i class="fa-solid fa-lock" style="font-size:28px; display:block; margin-bottom:8px; color:#d1d5db;"></i>
                                    Yetkiniz yok veya kayit bulunamadi.
                                </td>
                            </tr>
                        <?php elseif (empty($customers)): ?>
                            <tr>
                                <td colspan="<?php echo $colspan; ?>" style="text-align:center; padding:40px 16px; color:var(--text-3);">
                                    <i class="fa-solid fa-inbox" style="font-size:28px; display:block; margin-bottom:8px; color:#d1d5db;"></i>
                                    <span class="mono"><?php echo htmlspecialchars($cariPrefix, ENT_QUOTES, 'UTF-8'); ?></span> ile baslayan musteri bulunamadi.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $c):
                                $bakiye = (float) ($c['BAKIYE'] ?? 0);
                                $cariId = (int) ($c['CARIID'] ?? 0);
                                $sales = $customerSales[$cariId] ?? null;
                                $ciro = $sales ? (float) $sales['TOPLAM_CIRO'] : 0.0;
                                $islemSayisi = $sales ? (int) $sales['ISLEM_SAYISI'] : 0;

                                $durumText = 'Sifir';
                                $tagClass = 'tag-sifir';
                                $bakiyeStyle = 'color:var(--text-2);';
                                if ($bakiye > 0) {
                                    $durumText = 'Borclu';
                                    $tagClass = 'tag-borclu';
                                    $bakiyeStyle = 'color:var(--emerald);';
                                } elseif ($bakiye < 0) {
                                    $durumText = 'Alacakli';
                                    $tagClass = 'tag-alacakli';
                                    $bakiyeStyle = 'color:var(--red);';
                                }

                                $custTah = $customerTahsilat[$cariId] ?? 0.0;
                                $custOran = $ciro > 0 ? ($custTah / $ciro) * 100 : 0;
                                $custRatioClass = $custOran >= 80 ? 'ratio-good' : ($custOran >= 50 ? 'ratio-warn' : 'ratio-bad');
                            ?>
                            <tr>
                                <td class="mono" style="font-weight:700; color:var(--text-1);">
                                    <?php echo htmlspecialchars((string) ($c['KODU'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="font-weight:600; color:var(--text-1);">
                                    <?php echo htmlspecialchars((string) ($c['UNVANI'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="color:var(--text-2);">
                                    <?php
                                        $sehir = (string) ($c['SEHIR'] ?? '');
                                        echo htmlspecialchars(function_exists('trcevir') ? (string) trcevir($sehir) : $sehir, ENT_QUOTES, 'UTF-8');
                                    ?>
                                </td>
                                <?php if ($canSeeBalance): ?>
                                <td class="col-right" style="font-weight:700; <?php echo $bakiyeStyle; ?>">
                                    <?php echo money_tr_pz(abs($bakiye)); ?>
                                </td>
                                <td>
                                    <span class="tag <?php echo $tagClass; ?>"><?php echo $durumText; ?></span>
                                </td>
                                <?php endif; ?>
                                <td class="col-right <?php echo $ciro > 0 ? 'val-money' : 'val-mute'; ?>">
                                    <?php echo $ciro > 0 ? number_format($ciro, 2, ',', '.') . ' &#8378;' : '-'; ?>
                                </td>
                                <td class="col-right <?php echo $custTah > 0 ? 'val-tah' : 'val-mute'; ?>">
                                    <?php echo $custTah > 0 ? number_format($custTah, 2, ',', '.') . ' &#8378;' : '-'; ?>
                                </td>
                                <td class="col-right <?php echo $ciro > 0 ? $custRatioClass : 'val-mute'; ?>">
                                    <?php echo $ciro > 0 ? '%' . number_format($custOran, 0) : '-'; ?>
                                </td>
                                <td class="col-right">
                                    <?php echo $islemSayisi > 0 ? number_format($islemSayisi) : '-'; ?>
                                </td>
                                <td class="col-right no-print">
                                    <a class="btn-action tone-sky"
                                        href="<?php echo APP_ROOT_URL; ?>/lg_hareket.php?cariid=<?php echo $cariId; ?>">
                                        <i class="fa-solid fa-list"></i> Hareket
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Ay Detay Modal -->
    <div id="ayDetayModal" class="m-overlay">
        <div class="m-dialog">
            <div class="m-head">
                <h3>
                    <i class="fa-solid fa-receipt"></i>
                    <span id="ayDetayTitle">Fatura Detaylari</span>
                </h3>
                <button id="ayDetayClose" class="m-close">&times;</button>
            </div>
            <div class="m-body" id="ayDetayBody">
                <p style="text-align:center; color:var(--text-3); padding:32px 16px;">Yukleniyor...</p>
            </div>
            <div class="m-foot">
                <button id="ayDetayCloseBtn" class="btn">Kapat</button>
            </div>
        </div>
    </div>

</main>

<script src="/tm/js/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('monthlyChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: <?php echo json_encode($chartLabels, JSON_UNESCAPED_UNICODE); ?>,
        datasets: [{
            label: '2026 Satis (TL)',
            data: <?php echo json_encode($chartValues, JSON_NUMERIC_CHECK); ?>,
            backgroundColor: 'rgba(5, 150, 105, 0.82)',
            borderColor: 'rgba(5, 150, 105, 1)',
            borderWidth: 2,
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.parsed.y.toLocaleString('tr-TR', { style: 'currency', currency: 'TRY' });
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(v) { return v.toLocaleString('tr-TR'); }
                },
                grid: { color: 'rgba(0,0,0,0.04)' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

// Ay detay modal – AJAX
const monthNamesTR = <?php echo json_encode($monthNames, JSON_UNESCAPED_UNICODE); ?>;
const currentPrefix = <?php echo json_encode($cariPrefix, JSON_UNESCAPED_UNICODE); ?>;

function openAyModal(title, bodyLoading) {
    $('#ayDetayTitle').text(title);
    $('#ayDetayBody').html(bodyLoading);
    $('#ayDetayModal').addClass('open');
}

function closeAyDetayModal() {
    $('#ayDetayModal').removeClass('open');
}

$('.ay-detay-btn').on('click', function() {
    const month = $(this).data('month');
    const monthName = monthNamesTR[month] || '';
    openAyModal(
        monthName + ' 2026 – Toptan Satis Faturalari',
        '<p style="text-align:center; color:#9ca3af; padding:32px 16px;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>Yukleniyor...</p>'
    );

    $.ajax({
        url: 'rapor_pazarlamaci_ay_detay.php',
        type: 'POST',
        data: { month: month, prefix: currentPrefix },
        success: function(response) { $('#ayDetayBody').html(response); },
        error: function() {
            $('#ayDetayBody').html('<p style="text-align:center; color:var(--red,#6F1022); padding:32px 16px;">Veriler yuklenirken bir hata olustu.</p>');
        }
    });
});

$('.ay-tahsilat-btn').on('click', function() {
    const month = $(this).data('month');
    const monthName = monthNamesTR[month] || '';
    openAyModal(
        monthName + ' 2026 – Tahsilat Detaylari',
        '<p style="text-align:center; color:#9ca3af; padding:32px 16px;"><i class="fa-solid fa-spinner fa-spin" style="margin-right:6px;"></i>Yukleniyor...</p>'
    );

    $.ajax({
        url: 'rapor_pazarlamaci_ay_tahsilat.php',
        type: 'POST',
        data: { month: month, prefix: currentPrefix },
        success: function(response) { $('#ayDetayBody').html(response); },
        error: function() {
            $('#ayDetayBody').html('<p style="text-align:center; color:var(--red,#6F1022); padding:32px 16px;">Veriler yuklenirken bir hata olustu.</p>');
        }
    });
});

$('#ayDetayClose, #ayDetayCloseBtn').on('click', closeAyDetayModal);
$('#ayDetayModal').on('click', function(e) {
    if ($(e.target).is('#ayDetayModal')) closeAyDetayModal();
});
$(document).on('keyup', function(e) {
    if (e.key === 'Escape') closeAyDetayModal();
});

// Gider tablosu – otomatik hesaplama
const fmtTR = v => v.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' \u20BA';
const yearCiro = <?php echo json_encode($yearlyTotal, JSON_NUMERIC_CHECK); ?>;

function hesaplaGider() {
    let topGider = 0;

    for (let m = 1; m <= 12; m++) {
        let gider = parseFloat($('.gider-input[data-month="'+m+'"]').val()) || 0;
        topGider += gider;

        let ciro = parseFloat($('.ay-net[data-month="'+m+'"]').data('ciro')) || 0;
        let net = ciro - gider;
        let netCell = $('.ay-net[data-month="'+m+'"]');
        netCell.text(fmtTR(net));
        netCell.css('color', net > 0 ? 'var(--emerald)' : (net < 0 ? 'var(--red)' : 'var(--text-1)'));
    }

    $('#topGider').text(fmtTR(topGider));

    let topNetVal = yearCiro - topGider;
    let topNetEl = $('#topNet');
    topNetEl.text(fmtTR(topNetVal));
    topNetEl.css('color', topNetVal >= 0 ? 'var(--emerald)' : 'var(--red)');
}

$('.gider-input').on('input', hesaplaGider);
</script>

</body>
</html>

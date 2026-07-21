<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['stokid', 'stokhareket', 'return_to']);

$resmiKdvZorunlu = defined('RESMI_FORCE_KDV');
$resmiKdvOrani = $resmiKdvZorunlu ? (float) RESMI_FORCE_KDV : 0.0;

// Session bazlı parametre kontrolü
$stokid = getPageParamInt('stokid');
$fisid = getPageParamInt('stokhareket');
$lgStokDuzenleBackUrl = safeLocalReturnUrl(
    getPageParamString('return_to', ''),
    '../siparis/lg_fis.php?stokhareket=' . $fisid
);

if ($stokid <= 0 || $fisid <= 0) {
    exit("Gerekli parametreler eksik.");
}

// Veritabanı işlemleri
try {
    // Düzenlenecek stok satırının bilgilerini çek
    $stokara_stmt = $dbh->prepare(
        "SELECT O.LOGICALREF AS ID, O.STOCKREF, O.AMOUNT, O.PRICE, O.VAT, O.LINEEXP, O.UOMREF,
                I.CODE, I.NAME, 
                U.CODE AS BIRIM 
         FROM {$firmadonem}ORFLINE O 
         LEFT JOIN {$firma}ITEMS I ON O.STOCKREF = I.LOGICALREF 
         LEFT JOIN {$firma}UNITSETL U ON O.UOMREF = U.LOGICALREF 
         WHERE O.ORDFICHEREF = :fisid AND O.STOCKREF = :stokid"
    );
    $stokara_stmt->execute([':fisid' => $fisid, ':stokid' => $stokid]);
    $stokara = $stokara_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stokara) {
        exit("Fişte düzenlenecek stok bilgisi bulunamadı.");
    }

    if ($resmiKdvZorunlu) {
        $stokara['VAT'] = $resmiKdvOrani;
    }

    // Cari ID'sini bul
    $caribul_stmt = $dbh->prepare("SELECT CLIENTREF FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF=:fisid");
    $caribul_stmt->execute([':fisid' => $fisid]);
    $caribul = $caribul_stmt->fetch(PDO::FETCH_ASSOC);
    $cariid = $caribul ? $caribul['CLIENTREF'] : null;

} catch (PDOException $e) {
    error_log("Stok Düzenleme Sayfası Veritabanı Hatası: " . $e->getMessage());
    exit("Sistemde bir hata oluştu. Lütfen daha sonra tekrar deneyin.");
}

// Orijinal formatlama fonksiyonlarınız
function kusuratadet1(float|int|string|null $kusurata): string
{
    global $adetkusurat;
    if (!isset($kusurata) || $kusurata === '') {
        $kusurata = 0;
    }
    return number_format(
        (float) $kusurata,
        $adetkusurat ?? 2,
        '.',
        ''
    );
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <link rel="icon" type="image/png" href="icon.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stok Kalemi Duzenle</title>
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        input[type=number] { -moz-appearance: textfield; }

        /* ═══════ HEADER ═══════ */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
        }
        .header-inner {
            max-width: 760px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            font-size: 18px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .header-title i { color: var(--red,#ef4444); font-size: 16px; }

        /* ═══════ CARD ═══════ */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            overflow: hidden;
        }
        .glass-card + .glass-card { margin-top: 16px; }
        .glass-card:nth-of-type(2) { animation-delay: 80ms; }
        .glass-card:nth-of-type(3) { animation-delay: 160ms; }

        /* Urun hero */
        .urun-hero {
            padding: 18px 20px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-bottom: 1px solid rgba(248, 113, 113, 0.25);
        }
        .urun-hero .kod {
            display: inline-block;
            padding: 3px 10px;
            font-size: 11px;
            font-weight: 600;
            color: var(--red);
            background: #fff;
            border: 1px solid rgba(239, 68, 68, 0.25);
            border-radius: 100px;
            letter-spacing: 0.4px;
        }
        .urun-hero .ad {
            margin-top: 8px;
            font-size: 17px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.3;
        }

        /* Form body */
        .form-body { padding: 20px; }

        .field { display: flex; flex-direction: column; gap: 6px; }
        .field-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .field-control {
            width: 100%;
            padding: 11px 14px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 600;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            transition: all 0.2s ease;
            outline: none;
        }
        .field-control:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .field-control[readonly] {
            background: #f9fafb;
            color: var(--text-2);
            cursor: default;
        }
        .field-hint {
            margin-top: 4px;
            font-size: 11px;
            font-weight: 600;
            color: var(--emerald);
        }
        .field-hint i { margin-right: 4px; }

        .fields-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 14px;
        }
        .col-2 { grid-column: span 2; }
        .col-3 { grid-column: span 3; }
        .col-4 { grid-column: span 4; }
        .col-6 { grid-column: span 6; }
        .col-12 { grid-column: span 12; }

        /* Stok info chips */
        .stok-chips {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-top: 6px;
        }
        .chip-box {
            padding: 12px 14px;
            border-radius: 12px;
            text-align: center;
            border: 1px solid var(--border);
            background: #fafafa;
        }
        .chip-box .chip-label {
            font-size: 10.5px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
        }
        .chip-box .chip-value {
            margin-top: 4px;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.2;
        }
        .chip-box .chip-note {
            margin-top: 2px;
            font-size: 10px;
            font-weight: 500;
        }
        .chip-box.sky { background: var(--sky-soft); border-color: rgba(14, 165, 233, 0.2); }
        .chip-box.sky .chip-label,
        .chip-box.sky .chip-value { color: var(--sky); }
        .chip-box.emerald { background: var(--emerald-soft); border-color: rgba(5, 150, 105, 0.2); }
        .chip-box.emerald .chip-label,
        .chip-box.emerald .chip-value,
        .chip-box.emerald .chip-note { color: var(--emerald); }
        .chip-box.amber { background: var(--amber-soft); border-color: rgba(217, 119, 6, 0.25); }
        .chip-box.amber .chip-label,
        .chip-box.amber .chip-value,
        .chip-box.amber .chip-note { color: var(--amber); }
        .chip-box.red { background: var(--red-soft); border-color: rgba(239, 68, 68, 0.22); }
        .chip-box.red .chip-label,
        .chip-box.red .chip-value,
        .chip-box.red .chip-note { color: var(--red); }

        /* Submit button */
        .save-row { margin-top: 6px; }
        .btn-save {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 20px;
            background: var(--red,#ef4444);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(239, 68, 68, 0.2);
        }
        .btn-save:hover { background: var(--red); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(239, 68, 68, 0.3); }
        .btn-save:active { transform: translateY(0); }

        /* ═══════ SATIS GECMISI CARD (yeniden tasarim) ═══════ */
        .history-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.24s both;
            will-change: transform, opacity;
            overflow: hidden;
            position: relative;
        }
        .history-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--emerald), #34d399);
        }
        .history-head {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 18px 20px 14px;
            border-bottom: 1px solid var(--border);
        }
        .history-head .ico-box {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: var(--emerald-soft);
            color: var(--emerald);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .history-head .head-text { flex: 1 1 auto; min-width: 0; }
        .history-card h4 {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.25;
            margin: 0;
        }
        .history-card .musteri {
            display: block;
            margin-top: 2px;
            font-size: 11.5px;
            color: var(--text-2);
            font-weight: 500;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .history-card .musteri strong { color: var(--text-1); font-weight: 600; }

        .history-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            padding: 16px 20px;
        }
        .history-stat {
            padding: 12px 10px;
            background: #fafafa;
            border: 1px solid var(--border);
            border-radius: 10px;
            text-align: center;
            transition: all 0.2s ease;
        }
        .history-stat:hover {
            background: #fff;
            border-color: rgba(5, 150, 105, 0.25);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }
        .history-stat .lbl {
            font-size: 10.5px;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            font-weight: 600;
        }
        .history-stat .val {
            margin-top: 3px;
            font-size: 13px;
            font-weight: 700;
        }
        .history-stat .sub {
            margin-top: 2px;
            font-size: 9.5px;
            color: var(--text-3);
            font-weight: 500;
        }
        .history-stat.blue .val { color: #2563eb; }
        .history-stat.purple .val { color: #7c3aed; }
        .history-stat.green .val { color: var(--emerald); }
        .history-stat.red .val { color: var(--red); }
        .history-stat.amber .val { color: var(--amber); }
        .history-stat.teal .val { color: #0d9488; }

        .history-list {
            padding: 0 20px 18px;
        }
        .history-list-inner {
            background: #fafafa;
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
        }
        .history-list .list-title {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 10px 14px;
            background: #fff;
            border-bottom: 1px solid var(--border);
            font-size: 10.5px;
            color: var(--text-2);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .history-list .list-title i { color: var(--emerald); }

        /* Sale row (detayli) */
        .sale-row {
            padding: 12px 14px;
            background: #fafafa;
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 8px;
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 12px;
            align-items: center;
        }
        .sale-row:last-child { margin-bottom: 0; }

        .sale-date {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 100px;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-1);
            white-space: nowrap;
        }
        .sale-date i { color: var(--text-3); font-size: 10px; }

        .sale-body {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }
        .sale-qty {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-1);
        }
        .sale-qty .unit {
            font-size: 10.5px;
            font-weight: 500;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-left: 2px;
        }
        .sale-prices {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            font-size: 12px;
        }
        .sale-prices .brut {
            text-decoration: line-through;
            color: var(--text-3);
            font-weight: 500;
        }
        .sale-prices .arrow {
            color: var(--text-3);
            font-size: 9px;
        }
        .sale-prices .net {
            color: var(--emerald);
            font-weight: 700;
            font-size: 13px;
        }
        .sale-prices .net.solo { font-size: 13px; }
        .sale-prices .no-isk {
            color: var(--text-3);
            font-size: 10px;
            font-style: italic;
        }
        .sale-prices .isk-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 2px 7px;
            background: var(--red-soft);
            color: var(--red);
            border: 1px solid rgba(239, 68, 68, 0.22);
            border-radius: 100px;
            font-size: 10.5px;
            font-weight: 700;
        }
        .sale-prices .isk-pill i { font-size: 8px; }

        .sale-totals {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 120px;
            text-align: right;
            font-size: 11px;
            color: var(--text-2);
        }
        .sale-totals .total-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
            white-space: nowrap;
        }
        .sale-totals .total-row .lbl {
            font-weight: 500;
        }
        .sale-totals .total-row .val {
            font-weight: 600;
            color: var(--text-1);
        }
        .sale-totals .total-final {
            padding-top: 4px;
            margin-top: 2px;
            border-top: 1px dashed rgba(5, 150, 105, 0.3);
            font-size: 12px;
        }
        .sale-totals .total-final .lbl { color: var(--emerald); font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .sale-totals .total-final .val { color: var(--emerald); font-weight: 800; }

        .empty-history {
            padding: 16px 18px;
            background: rgba(255,255,255,0.92);
            border: 1px dashed var(--border);
            border-radius: 14px;
            text-align: center;
            font-size: 13px;
            color: var(--text-2);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.24s both;
            will-change: transform, opacity;
        }
        .empty-history i { color: var(--text-3); margin-right: 6px; }

        /* ═══════ TOAST ═══════ */
        #toastContainer {
            position: fixed;
            top: 16px;
            right: 16px;
            z-index: 9999;
        }
        #globalToast {
            min-width: 280px;
            max-width: 360px;
            border-radius: 12px;
            color: #fff;
            box-shadow: 0 14px 34px -18px rgba(15, 23, 42, 0.6);
            overflow: hidden;
            display: none;
        }
        #globalToast.show { display: block; animation: toastIn 0.2s ease both; }
        #globalToast .inner {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.4;
        }
        #globalToast.bg-success { background: #16a34a; }
        #globalToast.bg-danger  { background: var(--red,#6F1022); }
        #globalToast.bg-warning { background: #f59e0b; color: #1f2937; }
        #globalToast.bg-info    { background: #0284c7; }
        #toastCloseBtn {
            margin-left: auto;
            background: transparent;
            border: none;
            color: inherit;
            opacity: 0.85;
            cursor: pointer;
            padding: 2px 6px;
            font-size: 13px;
        }
        #toastCloseBtn:hover { opacity: 1; }

        /* ═══════ ANIMATIONS ═══════ */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translate3d(0, -8px, 0); }
            to { opacity: 1; transform: translate3d(0, 0, 0); }
        }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            .header-divider { height: 18px; }

            main { padding: 14px 12px 40px !important; }

            .urun-hero { padding: 14px 16px; }
            .urun-hero .ad { font-size: 15px; }
            .form-body { padding: 16px; }
            .field-control { font-size: 16px; padding: 11px 12px; } /* iOS zoom engeli */

            .fields-grid { gap: 12px; }
            .col-2, .col-3, .col-4 { grid-column: span 6; }
            .col-6 { grid-column: span 12; }

            .history-card { padding: 16px; }
            .history-stats { grid-template-columns: 1fr 1fr; gap: 8px; }
            .history-stat { padding: 8px 6px; }
            .history-stat .val { font-size: 12px; }
            .history-stat .sub { font-size: 9px; }

            .sale-row {
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 12px;
            }
            .sale-date { align-self: flex-start; }
            .sale-totals {
                text-align: left;
                min-width: 0;
                padding-top: 8px;
                border-top: 1px dashed var(--border);
            }
        }
        @media (max-width: 420px) {
            .history-stats { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>

<body OnLoad="document.frm.stkadt.focus();">

    <header class="top-header">
        <div class="header-inner">
            <a href="<?php echo htmlspecialchars($lgStokDuzenleBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="header-back" title="Geri Don">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-pen-to-square"></i>Stok Kalemi Duzenle
            </span>
        </div>
    </header>

    <main style="max-width:760px;margin:0 auto;padding:20px 24px 60px;">

        <section class="glass-card">
            <div class="urun-hero">
                <span class="kod"><i class="fa-solid fa-barcode" style="margin-right:4px;"></i> <?php echo htmlspecialchars((string) $stokara['CODE'], ENT_QUOTES, 'UTF-8'); ?></span>
                <div class="ad"><?php echo htmlspecialchars(tr($stokara['NAME']), ENT_QUOTES, 'UTF-8'); ?></div>
            </div>

            <div class="form-body">
                <form name="frm" id="frm" method="POST" action="../siparis/lg_fis.php?stokhareket=<?php echo $fisid; ?>" onsubmit="return validateForm()" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="stkduzenle" value="<?php echo $stokara['ID']; ?>">
                    <input type="hidden" name="kontrol" value="<?php echo uniqid(); ?>">
                    <input type="hidden" name="stokhareket" value="<?php echo $fisid; ?>">
                    <input type="hidden" name="stkbirim" value="<?php echo $stokara['UOMREF']; ?>">

                    <div class="fields-grid">
                        <div class="col-3 field">
                            <label class="field-label" for="stkadt">Miktar</label>
                            <input type="number" step="any" name="stkadt" id="stkadt" value="<?php echo kusuratadet1($stokara['AMOUNT']); ?>"
                                required autofocus inputmode="decimal" aria-label="Miktar" class="field-control">
                        </div>

                        <div class="col-3 field">
                            <label class="field-label" for="stbrm_display">Birim</label>
                            <input type="text" name="stbrm_display" id="stbrm_display" value="<?php echo htmlspecialchars((string) $stokara['BIRIM'], ENT_QUOTES, 'UTF-8'); ?>" readonly
                                class="field-control">
                        </div>

                        <div class="col-4 field">
                            <label class="field-label" for="stkfyt">Fiyat</label>
                            <input type="text" name="stkfyt" id="stkfyt" value="<?php echo kusuratpara($stokara['PRICE']); ?>"
                                inputmode="decimal" aria-label="Birim fiyat" class="field-control">
                        </div>

                        <div class="col-2 field">
                            <label class="field-label" for="stkkdv">KDV(%)</label>
                            <input type="text" name="stkkdv" id="stkkdv" value="<?php echo kusuratsifir($resmiKdvZorunlu ? $resmiKdvOrani : $stokara['VAT']); ?>" <?php echo $resmiKdvZorunlu ? 'readonly' : ''; ?>
                                inputmode="decimal" aria-label="KDV yuzde" class="field-control">
                            <?php if ($resmiKdvZorunlu): ?>
                                <div class="field-hint"><i class="fa-solid fa-lock"></i>Resmi mod: KDV sabit %<?php echo rtrim(rtrim(number_format($resmiKdvOrani, 2, '.', ''), '0'), '.'); ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Stok bilgi chip'leri -->
                        <?php
                        $bekleyenSiparis = function_exists('bekleyen_siparis') ? bekleyen_siparis($stokid) : 0;
                        $mevcutStok = function_exists('stok_miktar_bul') ? stok_miktar_bul($stokid) : 0;
                        $stokDurumu = '';
                        $stokBoxClass = '';
                        $stokIcon = '';
                        if ($mevcutStok > $bekleyenSiparis) {
                            $stokDurumu = 'Yeterli';
                            $stokBoxClass = 'emerald';
                            $stokIcon = 'fa-circle-check';
                        } elseif ($mevcutStok > 0 && $mevcutStok <= $bekleyenSiparis) {
                            $stokDurumu = 'Dusuk';
                            $stokBoxClass = 'amber';
                            $stokIcon = 'fa-triangle-exclamation';
                        } else {
                            $stokDurumu = 'Yetersiz';
                            $stokBoxClass = 'red';
                            $stokIcon = 'fa-circle-xmark';
                        }
                        ?>
                        <div class="col-12">
                            <div class="stok-chips">
                                <div class="chip-box sky">
                                    <div class="chip-label"><i class="fa-solid fa-dolly"></i> Bek. Siparis</div>
                                    <div class="chip-value"><?php echo kusuratadet($bekleyenSiparis); ?></div>
                                </div>
                                <div class="chip-box <?php echo $stokBoxClass; ?>">
                                    <div class="chip-label"><i class="fa-solid <?php echo $stokIcon; ?>"></i> Mevcut Stok</div>
                                    <div class="chip-value"><?php echo kusuratadet($mevcutStok); ?></div>
                                    <div class="chip-note">(<?php echo htmlspecialchars($stokDurumu, ENT_QUOTES, 'UTF-8'); ?>)</div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12 field">
                            <label class="field-label" for="aciklama">Satir Aciklamasi</label>
                            <input type="text" name="aciklama" id="aciklama" value="<?php echo htmlspecialchars((string) $stokara['LINEEXP'], ENT_QUOTES, 'UTF-8'); ?>"
                                class="field-control" placeholder="Aciklama giriniz..." autocomplete="off">
                        </div>

                        <div class="col-12 save-row">
                            <button class="btn-save" type="submit">
                                <i class="fa-solid fa-floppy-disk"></i> Kaydet
                                <i class="fa-solid fa-arrow-right" style="margin-left:4px;font-size:11px;opacity:0.85;"></i>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </section>
    </main>

    <?php
    // Bu musteriye ozel satis gecmisi (iskontolu ve KDV'li detaylar dahil)
    if ($cariid && (m_p_yetki($terminalkullanici, 'SP3') == 1 || $yetkidurum == 0)) {
        $stmt = $dbh->prepare("
            SELECT TOP 10
                S.PRICE, S.AMOUNT, S.DATE_,
                ISNULL(S.TOTAL, 0)     AS BRUT_TUTAR,
                ISNULL(S.VATMATRAH, 0) AS NET_MATRAH,
                ISNULL(S.VATAMNT, 0)   AS KDV_TUTAR,
                ISNULL(S.VAT, 0)       AS KDV_ORAN,
                ISNULL(S.LINENET, 0)   AS LINE_NET
            FROM " . $firmadonem . "STLINE S
            WHERE S.STOCKREF = :stokid
              AND S.LINETYPE = 0
              AND S.CANCELLED = 0
              AND S.CLIENTREF = :cariid
              AND S.TRCODE IN(7,8)
            ORDER BY S.DATE_ DESC
        ");
        $stmt->execute([':stokid' => $stokid, ':cariid' => $cariid]);
        $musteriSatislari = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($musteriSatislari)) {
            $alimSayisi = count($musteriSatislari);
            $toplamMiktar = 0.0;
            $toplamNetMatrah = 0.0;
            $toplamBrut = 0.0;
            $toplamIskOran = 0.0;
            $toplamNetBirim = 0.0;
            $enYuksekIsk = 0.0;

            foreach ($musteriSatislari as &$s) {
                $amnt = (float) $s['AMOUNT'];
                $brut = (float) $s['BRUT_TUTAR'];   // satir brut tutari (iskontosuz)
                $net  = (float) $s['NET_MATRAH'];   // KDV matrahi (iskontolu net)
                $iskOran = ($brut > 0) ? (($brut - $net) / $brut * 100) : 0.0;
                $netBirim = ($amnt > 0) ? ($net / $amnt) : (float) $s['PRICE'];
                $kdvliToplam = $net + (float) $s['KDV_TUTAR'];
                $s['ISK_ORAN']   = $iskOran;
                $s['NET_BIRIM']  = $netBirim;
                $s['KDVLI_TOPLAM'] = $kdvliToplam;

                $toplamMiktar    += $amnt;
                $toplamNetMatrah += $net;
                $toplamBrut      += $brut;
                $toplamIskOran   += $iskOran;
                $toplamNetBirim  += $netBirim;
                if ($iskOran > $enYuksekIsk) { $enYuksekIsk = $iskOran; }
            }
            unset($s);

            $ortMiktar    = $toplamMiktar    / $alimSayisi;
            $ortNetBirim  = $toplamNetBirim  / $alimSayisi;
            $ortIskOran   = $toplamIskOran   / $alimSayisi;
            $sonSatis = $musteriSatislari[0];
            ?>
            <div style="max-width:760px;margin:0 auto;padding:0 24px 60px;">
                <div class="history-card">
                    <div class="history-head">
                        <span class="ico-box"><i class="fa-solid fa-user-tie"></i></span>
                        <div class="head-text">
                            <h4>Bu Musterinin Satis Gecmisi</h4>
                            <span class="musteri"><strong><?php echo htmlspecialchars(function_exists('cari_bul') ? cari_bul($cariid) : '', ENT_QUOTES, 'UTF-8'); ?></strong></span>
                        </div>
                    </div>
                    <div class="history-stats">
                        <div class="history-stat blue">
                            <div class="lbl">Son Satis</div>
                            <div class="val"><?php echo date("d.m.Y", strtotime((string) $sonSatis['DATE_'])); ?></div>
                        </div>
                        <div class="history-stat red">
                            <div class="lbl">Ort. Iskonto</div>
                            <div class="val">%<?php echo number_format($ortIskOran, 2, ',', '.'); ?></div>
                            <?php if ($enYuksekIsk > $ortIskOran + 0.5): ?>
                                <div class="sub">en yuksek %<?php echo number_format($enYuksekIsk, 2, ',', '.'); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="history-stat green">
                            <div class="lbl">Ort. Net Fiyat</div>
                            <div class="val"><?php echo paraformat($ortNetBirim); ?> &#8378;</div>
                        </div>
                        <div class="history-stat purple">
                            <div class="lbl">Ort. Miktar</div>
                            <div class="val"><?php echo kusuratadet($ortMiktar); ?> <?php echo htmlspecialchars((string) $stokara['BIRIM'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <div class="history-stat amber">
                            <div class="lbl">Alim Sayisi</div>
                            <div class="val"><?php echo $alimSayisi; ?> adet</div>
                        </div>
                        <div class="history-stat teal">
                            <div class="lbl">Toplam Net</div>
                            <div class="val"><?php echo paraformat($toplamNetMatrah); ?> &#8378;</div>
                        </div>
                    </div>

                    <div class="history-list">
                        <div class="history-list-inner">
                        <div class="list-title"><i class="fa-solid fa-clock-rotate-left"></i> Satis Detaylari (<?php echo $alimSayisi; ?>)</div>
                        <?php foreach ($musteriSatislari as $satis):
                            $birim = htmlspecialchars((string) $stokara['BIRIM'], ENT_QUOTES, 'UTF-8');
                            $hasIsk = ((float) $satis['ISK_ORAN']) > 0.01;
                        ?>
                            <div class="sale-row">
                                <div class="sale-date">
                                    <i class="fa-regular fa-calendar"></i>
                                    <?php echo date("d.m.Y", strtotime((string) $satis['DATE_'])); ?>
                                </div>
                                <div class="sale-body">
                                    <div class="sale-qty">
                                        <?php echo kusuratadet($satis['AMOUNT']); ?>
                                        <span class="unit"><?php echo $birim; ?></span>
                                    </div>
                                    <div class="sale-prices">
                                        <?php if ($hasIsk): ?>
                                            <span class="brut"><?php echo paraformat($satis['PRICE']); ?> &#8378;</span>
                                            <span class="arrow"><i class="fa-solid fa-arrow-right"></i></span>
                                            <span class="net"><?php echo paraformat($satis['NET_BIRIM']); ?> &#8378;</span>
                                            <span class="isk-pill"><i class="fa-solid fa-tag"></i>%<?php echo number_format((float) $satis['ISK_ORAN'], 2, ',', '.'); ?></span>
                                        <?php else: ?>
                                            <span class="net solo"><?php echo paraformat($satis['NET_BIRIM']); ?> &#8378;</span>
                                            <span class="no-isk">iskontosuz</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="sale-totals">
                                    <div class="total-row">
                                        <span class="lbl">Net</span>
                                        <span class="val"><?php echo paraformat($satis['NET_MATRAH']); ?> &#8378;</span>
                                    </div>
                                    <div class="total-row">
                                        <span class="lbl">KDV %<?php echo kusuratsifir($satis['KDV_ORAN']); ?></span>
                                        <span class="val"><?php echo paraformat($satis['KDV_TUTAR']); ?> &#8378;</span>
                                    </div>
                                    <div class="total-row total-final">
                                        <span class="lbl">Toplam</span>
                                        <span class="val"><?php echo paraformat($satis['KDVLI_TOPLAM']); ?> &#8378;</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php } else { ?>
            <div style="max-width:760px;margin:0 auto;padding:0 24px 60px;">
                <div class="empty-history">
                    <i class="fa-solid fa-circle-info"></i>
                    Bu musteriye daha once bu urunden satis yapilmamis.
                </div>
            </div>
        <?php }
    }
    ?>

    <script src="/tm/js/jquery-3.7.1.min.js"></script>

    <!-- Toast Container -->
    <div id="toastContainer">
        <div id="globalToast" role="status" aria-live="polite" aria-atomic="true">
            <div class="inner">
                <i id="toastIcon" class="fa-solid"></i>
                <span id="toastMessage"></span>
                <button type="button" id="toastCloseBtn" aria-label="Kapat"><i class="fa fa-times"></i></button>
            </div>
        </div>
    </div>

    <script>
        // Animation render bug fix
        window.addEventListener('load', function(){
            requestAnimationFrame(function(){
                document.querySelectorAll('.glass-card, .history-card, .empty-history').forEach(function(el){ void el.offsetHeight; });
            });
        });

        // Toast Fonksiyonu
        function showToast(message, type = 'success', duration = 3000) {
            const toastEl = document.getElementById('globalToast');
            const messageEl = document.getElementById('toastMessage');
            const iconEl = document.getElementById('toastIcon');

            if (!toastEl || !messageEl) {
                alert(String(message).replace(/<[^>]+>/g, ''));
                return;
            }

            toastEl.classList.remove('show', 'bg-success', 'bg-danger', 'bg-warning', 'bg-info');
            iconEl.classList.remove('fa-circle-check', 'fa-circle-xmark', 'fa-triangle-exclamation', 'fa-circle-info');

            const styles = {
                success: { bg: 'bg-success', icon: 'fa-circle-check' },
                danger:  { bg: 'bg-danger',  icon: 'fa-circle-xmark' },
                error:   { bg: 'bg-danger',  icon: 'fa-circle-xmark' },
                warning: { bg: 'bg-warning', icon: 'fa-triangle-exclamation' },
                info:    { bg: 'bg-info',    icon: 'fa-circle-info' }
            };

            const style = styles[type] || styles.success;
            toastEl.classList.add(style.bg, 'show');
            iconEl.classList.add(style.icon);
            messageEl.innerHTML = message;

            if (window.globalToastTimer) {
                clearTimeout(window.globalToastTimer);
            }
            window.globalToastTimer = setTimeout(() => {
                toastEl.classList.remove('show');
            }, duration);
        }

        function validateForm() {
            const miktarInput = document.getElementById("stkadt");
            if (miktarInput.value.trim() === "" || parseFloat(miktarInput.value.replace(',', '.')) <= 0) {
                showToast("Miktar kismi bos olamaz ve 0'dan buyuk olmalidir!", 'warning');
                miktarInput.focus();
                return false;
            }
            return true;
        }

        $(document).ready(function () {
            $('#stkadt').select();

            // URL'den toast mesaji kontrolu
            const urlParams = new URLSearchParams(window.location.search);
            const toastMsg = urlParams.get('toast_msg');
            const toastType = urlParams.get('toast_type') || 'success';
            if (toastMsg) {
                showToast(decodeURIComponent(toastMsg), toastType);
            }

            const toastCloseBtn = document.getElementById('toastCloseBtn');
            if (toastCloseBtn) {
                toastCloseBtn.addEventListener('click', function () {
                    const toastEl = document.getElementById('globalToast');
                    if (toastEl) { toastEl.classList.remove('show'); }
                });
            }
        });
    </script>
</body>
</html>

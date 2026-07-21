<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/log_ip.php");

$stokhareket = isset($_GET['stokhareket']) ? (int) $_GET['stokhareket'] : 0;

// Mevcut fiş bilgilerini al
$mevcutFis = null;
if ($stokhareket > 0) {
    $stmtFis = $dbh->prepare("
        SELECT F.LOGICALREF, F.FICHENO, F.CLIENTREF, F.NETTOTAL,
               C.CODE AS CARI_KODU, C.DEFINITION_ AS CARI_ADI
        FROM {$firmadonem}ORFICHE F
        LEFT JOIN {$firma}CLCARD C ON C.LOGICALREF = F.CLIENTREF
        WHERE F.LOGICALREF = :stokhareket
    ");
    $stmtFis->execute([':stokhareket' => $stokhareket]);
    $mevcutFis = $stmtFis->fetch(PDO::FETCH_ASSOC);
}

$basarili = false;
$hata = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['yeni_cari'])) {
    if (!csrf_verify()) {
        $hata = 'Geçersiz güvenlik doğrulaması. Lütfen sayfayı yenileyip tekrar deneyin.';
    } else {
        $yeni_cari = (int) $_POST['yeni_cari'];

        if ($stokhareket <= 0 || !$mevcutFis) {
            $hata = 'Geçersiz fiş bilgisi.';
        } elseif ($yeni_cari <= 0) {
            $hata = 'Lütfen geçerli bir cari seçin.';
        } elseif ($yeni_cari === (int)$mevcutFis['CLIENTREF']) {
            $hata = 'Seçilen cari mevcut cari ile aynı.';
        } else {
            // Eski cari bilgileri
            $eski_cari_kodu = $mevcutFis['CARI_KODU'];
            $eski_cari_adi = $mevcutFis['CARI_ADI'];
            $ficheno = $mevcutFis['FICHENO'];

            // Yeni cari bilgisini al
            $stmtYeniCari = $dbh->prepare("
                SELECT CODE AS CARI_KODU, DEFINITION_ AS CARI_ADI
                FROM {$firma}CLCARD
                WHERE LOGICALREF = :yeni_cari AND ACTIVE = 0
            ");
            $stmtYeniCari->execute([':yeni_cari' => $yeni_cari]);
            $yeniCari = $stmtYeniCari->fetch(PDO::FETCH_ASSOC);

            if (!$yeniCari) {
                $hata = 'Seçilen cari bulunamadı.';
            } else {
                $yeni_cari_kodu = $yeniCari['CARI_KODU'];
                $yeni_cari_adi = $yeniCari['CARI_ADI'];

                // Aktarım işlemi
                $stmtGuncelle = $dbh->prepare("
                    UPDATE {$firmadonem}ORFICHE
                    SET CLIENTREF = :yeni_cari
                    WHERE LOGICALREF = :stokhareket
                ");
                $gncl = $stmtGuncelle->execute([':yeni_cari' => $yeni_cari, ':stokhareket' => $stokhareket]);

                if ($gncl) {
                    $aciklama = "Cari değiştirildi: [{$eski_cari_kodu}] {$eski_cari_adi} → [{$yeni_cari_kodu}] {$yeni_cari_adi}";
                    if (function_exists('logFisGuncelleme')) {
                        logFisGuncelleme($stokhareket, $ficheno, $terminalkullanici, $aciklama, []);
                    }
                    $basarili = true;

                    // Fiş bilgisini güncelle
                    $mevcutFis['CLIENTREF'] = $yeni_cari;
                    $mevcutFis['CARI_KODU'] = $yeni_cari_kodu;
                    $mevcutFis['CARI_ADI'] = $yeni_cari_adi;
                } else {
                    $hata = 'Aktarım sırasında bir hata oluştu.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Baska Cariye Aktar</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
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
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* HEADER */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(2, 132, 199, 0.18);
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.05);
        }
        .header-inner {
            max-width: 900px;
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
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--sky); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }
        .header-title .title-icon {
            width: 32px; height: 32px;
            border-radius: 10px;
            background: var(--sky-soft);
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }

        /* LAYOUT */
        main {
            max-width: 900px;
            margin: 0 auto;
            padding: 22px 24px 60px;
        }

        /* ALERTS */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid;
            margin-bottom: 18px;
            font-size: 13px;
            animation: cardIn 0.4s ease;
        }
        .alert .alert-icon {
            width: 32px; height: 32px;
            flex-shrink: 0;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .alert-title { font-weight: 700; margin-bottom: 2px; font-size: 13px; }
        .alert-desc { font-size: 12px; opacity: 0.85; }
        .alert-success { background: var(--emerald-soft); border-color: rgba(5,150,105,0.25); color: #065f46; }
        .alert-success .alert-icon { background: #fff; color: var(--emerald); }
        .alert-error { background: var(--red-soft); border-color: rgba(111,16,34,0.25); color: #991b1b; }
        .alert-error .alert-icon { background: #fff; color: var(--red); }

        /* GLASS CARD */
        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 22px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .glass-card + .glass-card { margin-top: 18px; }
        .glass-card:nth-of-type(2) { animation-delay: 80ms; }

        /* HERO (source order) */
        .source-hero {
            border-color: rgba(111, 16, 34, 0.22);
            background:
                linear-gradient(135deg, rgba(254, 242, 242, 0.85) 0%, rgba(255,255,255,0.94) 60%);
        }
        .source-head {
            display: flex;
            align-items: center;
            gap: 14px;
            padding-bottom: 16px;
            border-bottom: 1px dashed var(--border);
            margin-bottom: 16px;
        }
        .source-head .icon-box {
            width: 48px; height: 48px;
            flex-shrink: 0;
            border-radius: 14px;
            background: var(--red-soft);
            color: var(--red);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .source-head .head-label {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 2px;
        }
        .source-head .head-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
        }
        .source-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
        .info-block {
            padding: 12px 14px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        .info-block .info-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .info-block .info-value {
            font-size: 14px;
            font-weight: 600;
            color: var(--text-1);
            line-height: 1.4;
            word-break: break-word;
        }
        .info-block.tone-emerald .info-value { color: var(--emerald); font-size: 18px; }

        /* SEARCH PANEL */
        .card-head {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        .card-head .icon-box {
            width: 40px; height: 40px;
            flex-shrink: 0;
            border-radius: 12px;
            background: var(--indigo-soft);
            color: var(--indigo);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .card-head h2 {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-1);
            margin-bottom: 2px;
        }
        .card-head p {
            font-size: 12px;
            color: var(--text-2);
            line-height: 1.5;
        }

        .search-wrap {
            position: relative;
            margin-bottom: 14px;
        }
        .search-wrap .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 14px;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 12px 14px 12px 40px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            transition: all 0.2s ease;
            outline: none;
        }
        .search-input:focus {
            border-color: rgba(79, 70, 229, 0.5);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        /* CUSTOMER LIST */
        .cari-list {
            max-height: 380px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            padding: 6px;
        }
        .cari-list::-webkit-scrollbar { width: 8px; }
        .cari-list::-webkit-scrollbar-track { background: transparent; }
        .cari-list::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 4px; }
        .cari-list::-webkit-scrollbar-thumb:hover { background: #d1d5db; }

        .firma-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 12px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.15s ease;
            border: 1px solid transparent;
            background: transparent;
        }
        .firma-card + .firma-card { margin-top: 3px; }
        .firma-card:hover {
            background: var(--indigo-soft);
            border-color: rgba(79, 70, 229, 0.15);
        }
        .firma-card.is-selected {
            background: var(--indigo-soft);
            border-color: var(--indigo);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.15);
        }
        .firma-card.is-disabled {
            opacity: 0.45;
            cursor: not-allowed;
            background: #f9fafb;
        }
        .firma-card.is-disabled:hover {
            background: #f9fafb;
            border-color: transparent;
        }
        .firma-avatar {
            width: 36px; height: 36px;
            flex-shrink: 0;
            border-radius: 10px;
            background: var(--sky-soft);
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 700;
        }
        .firma-card.is-selected .firma-avatar {
            background: var(--indigo);
            color: #fff;
        }
        .firma-body { flex: 1; min-width: 0; }
        .firma-code {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            font-family: 'Avenir Next', 'Montserrat', monospace;
            letter-spacing: 0.3px;
        }
        .firma-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-1);
            line-height: 1.35;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .firma-mark {
            flex-shrink: 0;
            color: var(--indigo);
            font-size: 16px;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .firma-card.is-selected .firma-mark { opacity: 1; }
        .firma-card.is-disabled .firma-mark {
            opacity: 1;
            color: var(--text-3);
        }

        .empty-row {
            padding: 24px 16px;
            text-align: center;
            color: var(--text-3);
            font-size: 13px;
        }
        .empty-row i { font-size: 22px; margin-bottom: 8px; color: #d1d5db; display: block; }

        .list-meta {
            margin-top: 10px;
            font-size: 11px;
            color: var(--text-3);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* SELECTED PREVIEW */
        .selected-preview {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 12px;
            background: var(--indigo-soft);
            border: 1px solid rgba(79, 70, 229, 0.2);
            display: none;
            align-items: center;
            gap: 12px;
        }
        .selected-preview.is-visible { display: flex; }
        .selected-preview .prev-icon {
            width: 36px; height: 36px;
            flex-shrink: 0;
            border-radius: 10px;
            background: #fff;
            color: var(--indigo);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        .selected-preview .prev-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--indigo);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .selected-preview .prev-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-1);
            line-height: 1.35;
            word-break: break-word;
        }

        /* ACTION ROW */
        .action-row {
            margin-top: 18px;
            display: flex;
            gap: 10px;
        }
        .btn-flat {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 20px;
            border-radius: 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .btn-flat:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.12); transform: translateY(-1px); }
        .btn-flat:active { transform: translateY(0); }
        .btn-primary {
            flex: 1;
            background: var(--red);
            color: #fff;
            border-color: var(--red);
        }
        .btn-primary:hover { background: #b91c1c; border-color: #b91c1c; }
        .btn-primary:disabled {
            background: #e5e7eb;
            border-color: #e5e7eb;
            color: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .btn-ghost {
            background: #fff;
            color: var(--text-2);
            border-color: var(--border);
        }
        .btn-ghost:hover { color: var(--text-1); background: #f9fafb; }

        /* EMPTY STATE */
        .empty-card {
            text-align: center;
            padding: 40px 20px;
        }
        .empty-card .empty-ico {
            width: 64px; height: 64px;
            border-radius: 18px;
            background: var(--amber-soft);
            color: var(--amber);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin-bottom: 14px;
        }
        .empty-card h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-1);
            margin-bottom: 6px;
        }
        .empty-card p {
            font-size: 13px;
            color: var(--text-2);
            margin-bottom: 16px;
        }

        /* ANIMATIONS */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* MOBILE */
        @media (max-width: 767px) {
            .top-header { height: 52px; }
            .header-inner { padding: 0 14px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-title .title-icon { width: 28px; height: 28px; font-size: 12px; }
            .header-back { width: 32px; height: 32px; border-radius: 8px; }
            .header-divider { height: 20px; }

            main { padding: 14px 14px 48px; }
            .glass-card { padding: 16px; border-radius: 14px; }

            .source-head { gap: 12px; padding-bottom: 14px; margin-bottom: 14px; }
            .source-head .icon-box { width: 42px; height: 42px; font-size: 17px; }
            .source-head .head-title { font-size: 16px; }
            .source-grid { grid-template-columns: 1fr; gap: 10px; }
            .info-block.tone-emerald .info-value { font-size: 16px; }

            .card-head { gap: 10px; margin-bottom: 14px; }
            .card-head .icon-box { width: 36px; height: 36px; font-size: 14px; }
            .card-head h2 { font-size: 14px; }

            .search-input { font-size: 16px; padding: 11px 12px 11px 38px; } /* iOS zoom engeli */
            .search-wrap .search-icon { left: 12px; }

            .cari-list { max-height: 320px; }
            .firma-card { padding: 10px; gap: 10px; }
            .firma-avatar { width: 34px; height: 34px; font-size: 13px; }
            .firma-name { font-size: 13px; }

            .action-row { flex-direction: column-reverse; gap: 8px; }
            .btn-flat { width: 100%; padding: 13px 16px; font-size: 14px; }
            .btn-flat:hover { transform: none; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="<?php echo $stokhareket > 0 ? 'siparis/lg_fis.php?stokhareket=' . $stokhareket : 'index.php'; ?>" class="header-back" title="Geri">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <h1 class="header-title">
                <span class="title-icon"><i class="fa-solid fa-arrow-right-arrow-left"></i></span>
                Baska Cariye Aktar
            </h1>
        </div>
    </header>

    <main>

        <?php if ($basarili): ?>
            <div class="alert alert-success" role="status">
                <div class="alert-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                    <div class="alert-title">Aktarim basarili</div>
                    <div class="alert-desc">Siparis yeni cariye aktarildi.</div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hata): ?>
            <div class="alert alert-error" role="alert">
                <div class="alert-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <div class="alert-title"><?php echo htmlspecialchars($hata, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($mevcutFis): ?>

            <!-- Kaynak Siparis Bilgisi -->
            <section class="glass-card source-hero">
                <div class="source-head">
                    <div class="icon-box"><i class="fa-solid fa-file-invoice"></i></div>
                    <div>
                        <div class="head-label">Kaynak Siparis</div>
                        <div class="head-title"><?php echo htmlspecialchars($mevcutFis['FICHENO'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <div class="source-grid">
                    <div class="info-block">
                        <div class="info-label"><i class="fa-solid fa-user"></i> Mevcut Cari</div>
                        <div class="info-value">
                            <?php echo e_tr($mevcutFis['CARI_KODU']); ?> - <?php echo e_tr($mevcutFis['CARI_ADI']); ?>
                        </div>
                    </div>
                    <div class="info-block tone-emerald">
                        <div class="info-label"><i class="fa-solid fa-turkish-lira-sign"></i> Net Toplam</div>
                        <div class="info-value">
                            <?php echo number_format((float)$mevcutFis['NETTOTAL'], 2, ',', '.'); ?> TL
                        </div>
                    </div>
                </div>
            </section>

            <!-- Hedef Cari Secimi -->
            <section class="glass-card">
                <div class="card-head">
                    <div class="icon-box"><i class="fa-solid fa-users"></i></div>
                    <div>
                        <h2>Hedef Cari Secimi</h2>
                        <p>Siparisi aktarmak istediginiz cariyi arayin ve listeden secin.</p>
                    </div>
                </div>

                <form method="POST" id="aktarForm" autocomplete="off">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="yeni_cari" id="yeni_cari" value="">

                    <div class="search-wrap">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" id="cari_arama" class="search-input"
                               placeholder="Cari kodu veya adi yazin..." autocomplete="off">
                    </div>

                    <div class="cari-list" id="cari_list">
                        <?php
                        $stmtCariler = $dbh->prepare("SELECT LOGICALREF, CODE, DEFINITION_ FROM {$firma}CLCARD WHERE ACTIVE=0 ORDER BY CODE");
                        $stmtCariler->execute();
                        $mevcutRef = (int)$mevcutFis['CLIENTREF'];
                        $toplamCari = 0;
                        while ($cari = $stmtCariler->fetch(PDO::FETCH_ASSOC)) {
                            $toplamCari++;
                            $ref = (int)$cari['LOGICALREF'];
                            $isDisabled = ($ref === $mevcutRef);
                            $kod = htmlspecialchars((string)$cari['CODE'], ENT_QUOTES, 'UTF-8');
                            $adi = htmlspecialchars(tr((string)$cari['DEFINITION_']), ENT_QUOTES, 'UTF-8');
                            $baslik = mb_substr($cari['CODE'] ?: '?', 0, 1, 'UTF-8');
                            $cls = 'firma-card' . ($isDisabled ? ' is-disabled' : '');
                            $searchText = htmlspecialchars(mb_strtolower($cari['CODE'] . ' ' . tr((string)$cari['DEFINITION_']), 'UTF-8'), ENT_QUOTES, 'UTF-8');
                            echo '<div class="' . $cls . '" data-ref="' . $ref . '" data-disabled="' . ($isDisabled ? '1' : '0') . '" data-search="' . $searchText . '" data-code="' . $kod . '" data-name="' . $adi . '">';
                            echo '  <div class="firma-avatar">' . htmlspecialchars($baslik, ENT_QUOTES, 'UTF-8') . '</div>';
                            echo '  <div class="firma-body">';
                            echo '    <div class="firma-code">' . $kod . '</div>';
                            echo '    <div class="firma-name">' . $adi . '</div>';
                            echo '  </div>';
                            echo '  <div class="firma-mark"><i class="fa-solid fa-' . ($isDisabled ? 'ban' : 'circle-check') . '"></i></div>';
                            echo '</div>';
                        }
                        if ($toplamCari === 0) {
                            echo '<div class="empty-row"><i class="fa-solid fa-inbox"></i>Aktif cari bulunamadi.</div>';
                        }
                        ?>
                        <div class="empty-row" id="empty_row" style="display:none;">
                            <i class="fa-solid fa-magnifying-glass"></i>Eslesme bulunamadi.
                        </div>
                    </div>

                    <div class="list-meta">
                        <i class="fa-solid fa-info-circle"></i>
                        <span id="list_meta_text">Toplam <?php echo $toplamCari; ?> cari listelendi. Mevcut cari secilemez.</span>
                    </div>

                    <div class="selected-preview" id="selected_preview">
                        <div class="prev-icon"><i class="fa-solid fa-arrow-right"></i></div>
                        <div>
                            <div class="prev-label">Aktarim hedefi</div>
                            <div class="prev-value" id="selected_text">-</div>
                        </div>
                    </div>

                    <div class="action-row">
                        <a href="<?php echo $stokhareket > 0 ? 'siparis/lg_fis.php?stokhareket=' . $stokhareket : 'index.php'; ?>" class="btn-flat btn-ghost">
                            <i class="fa-solid fa-xmark"></i> Vazgec
                        </a>
                        <button type="submit" id="confirm_btn" disabled
                                onclick="return confirm('Siparisi secilen cariye aktarmak istediginizden emin misiniz?');"
                                class="btn-flat btn-primary">
                            <i class="fa-solid fa-arrow-right-arrow-left"></i> Aktarimi Onayla
                        </button>
                    </div>
                </form>
            </section>

        <?php else: ?>
            <section class="glass-card empty-card">
                <div class="empty-ico"><i class="fa-solid fa-circle-exclamation"></i></div>
                <h3>Gecerli bir siparis bulunamadi</h3>
                <p>Aktarim yapilacak siparis bilgisi eksik veya erisilemez durumda.</p>
                <a href="index.php" class="btn-flat btn-ghost">
                    <i class="fa-solid fa-house"></i> Ana Sayfa
                </a>
            </section>
        <?php endif; ?>

    </main>

    <script>
    (function() {
        var input = document.getElementById('cari_arama');
        var list = document.getElementById('cari_list');
        var emptyRow = document.getElementById('empty_row');
        var hidden = document.getElementById('yeni_cari');
        var btn = document.getElementById('confirm_btn');
        var preview = document.getElementById('selected_preview');
        var previewText = document.getElementById('selected_text');
        if (!list || !input) return;

        var cards = Array.prototype.slice.call(list.querySelectorAll('.firma-card'));

        function norm(s) {
            return (s || '').toLocaleLowerCase('tr');
        }

        function filter(q) {
            var term = norm(q).trim();
            var visible = 0;
            cards.forEach(function(c) {
                var hay = c.getAttribute('data-search') || '';
                var match = !term || hay.indexOf(term) !== -1;
                c.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            if (emptyRow) emptyRow.style.display = (visible === 0 && cards.length > 0) ? 'block' : 'none';
        }

        function selectCard(card) {
            if (card.getAttribute('data-disabled') === '1') return;
            cards.forEach(function(c) { c.classList.remove('is-selected'); });
            card.classList.add('is-selected');
            hidden.value = card.getAttribute('data-ref');
            btn.disabled = false;
            if (preview && previewText) {
                previewText.textContent = card.getAttribute('data-code') + ' - ' + card.getAttribute('data-name');
                preview.classList.add('is-visible');
            }
        }

        cards.forEach(function(c) {
            c.addEventListener('click', function() { selectCard(c); });
        });

        input.addEventListener('input', function(e) { filter(e.target.value); });

        // Enter tusu: ilk gorunur/secilebilir kayitlari sec
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var first = cards.find(function(c) {
                    return c.style.display !== 'none' && c.getAttribute('data-disabled') !== '1';
                });
                if (first) selectCard(first);
            }
        });
    })();
    </script>

</body>
</html>

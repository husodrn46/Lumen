<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once(__DIR__ . "/kontrol.php");

// Dönem kontrolü (2025 dönemi için eski tabloları kullan)
$donemParam = isset($_GET['donem']) ? $_GET['donem'] : '';
if ($donemParam === '2025' && isset($eskifirmadonem)) {
    $firmadonem = $eskifirmadonem;
}

// Yetki kontrolü - M18 (Değişiklik Geçmişi / Loglar) yetkisi gerekli
if (m_p_yetki($terminalkullanici, 'M18') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$fisRef = isset($_GET['fis']) ? intval($_GET['fis']) : 0;

if ($fisRef <= 0) {
    die("Geçersiz fiş referansı");
}

$fisGecmisBackUrl = safeLocalReturnUrl(
    isset($_GET['return_to']) ? (string) $_GET['return_to'] : '',
    'lg_fis.php?stokhareket=' . $fisRef
);

// Para ve miktar formatlama fonksiyonları
function paraFormat($tutar): string {
    return number_format((float)$tutar, 2, ',', '.');
}
function miktarFormat($miktar): string {
    return number_format((float)$miktar, 2, ',', '.');
}

// Fiş bilgisini al
$stmtFisInfo = $dbh->prepare("SELECT FICHENO, DATE_ FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :fisRef");
$stmtFisInfo->execute([':fisRef' => $fisRef]);
$fisInfo = $stmtFisInfo->fetch(PDO::FETCH_ASSOC);
if (!$fisInfo) {
    die("Fiş bulunamadı");
}

// ============================================================================
// Fiş işlem geçmişini al (oluşturma, güncelleme, silme)
// ============================================================================
$fisIslemler = [];
try {
    $stmtFisIslemler = $dbh->prepare("
        SELECT
            'FIS' AS TIP,
            ISLEM_TIPI,
            TARIH,
            CARI_KODU,
            CARI_ADI,
            TOPLAM_TUTAR,
            TOPLAM_KDV,
            GENEL_TOPLAM,
            DOVIZ_TIPI,
            ACIKLAMA,
            IP_ADRESI,
            SL.DEFINITION_ AS KULLANICI_ADI
        FROM M_FIS_LOG FL
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = FL.KULLANICI
        WHERE FL.FIS_REF = :fisRef
    ");
    $stmtFisIslemler->execute([':fisRef' => $fisRef]);
    $fisIslemler = $stmtFisIslemler->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("M_FIS_LOG sorgu hatası: " . $e->getMessage());
}

// Satır değişiklik geçmişini al
$satirDegisiklikler = [];
try {
    $stmtSatirDegisiklikler = $dbh->prepare("
        SELECT
            'SATIR' AS TIP,
            ISLEM_TIPI,
            TARIH,
            STOK_KODU,
            STOK_ADI,
            ACIKLAMA,
            IP_ADRESI,
            SL.DEFINITION_ AS KULLANICI_ADI,
            ESKI_MIKTAR,
            ESKI_FIYAT,
            ESKI_TOTAL,
            YENI_MIKTAR,
            YENI_FIYAT,
            YENI_TOTAL
        FROM M_SATIR_DEGISIKLIK_LOG SD
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = SD.KULLANICI
        WHERE SD.FIS_REF = :fisRef
    ");
    $stmtSatirDegisiklikler->execute([':fisRef' => $fisRef]);
    $satirDegisiklikler = $stmtSatirDegisiklikler->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("M_SATIR_DEGISIKLIK_LOG sorgu hatası: " . $e->getMessage());
}

// İskonto log tablosundan veri çek
$iskontoler = [];
try {
    $stmtIskontoler = $dbh->prepare("
        SELECT
            'ISKONTO' AS TIP,
            TARIH,
            ISNULL(ISKONTO_TIPI, '1') AS ISKONTO_SEVIYE,
            ISNULL(ESKI_DISCPER, 0) AS ESKI_ISKONTO,
            ISNULL(YENI_DISCPER, 0) AS YENI_ISKONTO,
            ISNULL(ESKI_DISTDISC, 0) AS ESKI_TUTAR,
            ISNULL(YENI_DISTDISC, 0) AS YENI_TUTAR,
            ACIKLAMA,
            IP_ADRESI,
            SL.DEFINITION_ AS KULLANICI_ADI
        FROM M_ISKONTO_LOG IL
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = IL.KULLANICI
        WHERE IL.FIS_REF = :fisRef
    ");
    $stmtIskontoler->execute([':fisRef' => $fisRef]);
    $iskontoler = $stmtIskontoler->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("M_ISKONTO_LOG sorgu hatası: " . $e->getMessage());
}

// KDV log tablosundan veri çek
$kdvDegisiklikler = [];
try {
    $stmtKdvDegisiklikler = $dbh->prepare("
        SELECT
            'KDV' AS TIP,
            TARIH,
            STOK_KODU,
            STOK_ADI,
            ESKI_KDV_ORAN AS ESKI_KDV,
            YENI_KDV_ORAN AS YENI_KDV,
            ACIKLAMA,
            IP_ADRESI,
            SL.DEFINITION_ AS KULLANICI_ADI
        FROM M_KDV_DEGISIKLIK_LOG KD
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = KD.KULLANICI
        WHERE KD.FIS_REF = :fisRef
    ");
    $stmtKdvDegisiklikler->execute([':fisRef' => $fisRef]);
    $kdvDegisiklikler = $stmtKdvDegisiklikler->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("M_KDV_DEGISIKLIK_LOG sorgu hatası: " . $e->getMessage());
}

// Fiyat değişiklik loglarını al
$fiyatDegisiklikler = [];
try {
    $stmtFiyat = $dbh->prepare("
        SELECT
            'FIYAT' AS TIP,
            TARIH,
            STOK_KODU,
            STOK_ADI,
            ESKI_FIYAT,
            YENI_FIYAT,
            MIKTAR,
            ACIKLAMA,
            IP_ADRESI,
            SL.DEFINITION_ AS KULLANICI_ADI
        FROM M_FIYAT_LOG FD
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = FD.KULLANICI_ID
        WHERE FD.FIS_REF = :fisRef
    ");
    $stmtFiyat->execute([':fisRef' => $fisRef]);
    $fiyatDegisiklikler = $stmtFiyat->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("M_FIYAT_LOG sorgu hatası: " . $e->getMessage());
}

// Yazdırma loglarını al (M_YAZDIR_LOG — kim, ne zaman, kaç kez, hangi tip yazdırdı)
$yazdirmalar = [];
try {
    $stmtYazdir = $dbh->prepare("
        SELECT
            'YAZDIR' AS TIP,
            TARIH,
            YAZDIRMA_TIPI,
            YAZDIRMA_SAYISI,
            ACIKLAMA,
            IP_ADRESI,
            SL.DEFINITION_ AS KULLANICI_ADI
        FROM M_YAZDIR_LOG YZ
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = YZ.KULLANICI_ID
        WHERE YZ.FIS_REF = :fisRef
    ");
    $stmtYazdir->execute([':fisRef' => $fisRef]);
    $yazdirmalar = $stmtYazdir->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("M_YAZDIR_LOG sorgu hatası: " . $e->getMessage());
}

// Tüm değişiklikleri birleştir
$tumDegisiklikler = array_merge($fisIslemler, $satirDegisiklikler, $iskontoler, $kdvDegisiklikler, $fiyatDegisiklikler, $yazdirmalar);

// Tarihe göre sırala (en yeni en üstte)
usort($tumDegisiklikler, fn($a, $b): int => strtotime((string) $b['TARIH']) - strtotime((string) $a['TARIH']));

// Tip sayaçları
$tipSayac = ['FIS' => 0, 'SATIR' => 0, 'ISKONTO' => 0, 'KDV' => 0, 'FIYAT' => 0, 'YAZDIR' => 0];
foreach ($tumDegisiklikler as $d) {
    $tipSayac[$d['TIP']] = ($tipSayac[$d['TIP']] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Degisiklik Gecmisi</title>
    <?php include_once(__DIR__ . '/pwa-header.php'); ?>
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
            --red-border: rgba(248, 113, 113, 0.25);
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

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
            max-width: 900px;
            margin: 0 auto;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
        }
        .header-left { display: flex; align-items: center; gap: 14px; }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-title { font-size: 18px; font-weight: 700; }
        .header-sub { font-size: 12px; color: var(--text-3); display: flex; align-items: center; gap: 10px; }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 12px; border-radius: 100px;
            font-size: 12px; font-weight: 700;
        }

        /* ═══════ FILTERS ═══════ */
        .filter-bar {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin-bottom: 16px;
            animation: cardIn 0.5s cubic-bezier(0.22,1,0.36,1) 0.1s both;
        }
        .filter-chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 6px 14px; border-radius: 100px;
            font-size: 12px; font-weight: 600;
            background: rgba(255,255,255,0.88); border: 1px solid var(--border);
            color: var(--text-2); cursor: pointer;
            transition: all 0.2s ease; backdrop-filter: blur(4px);
        }
        .filter-chip:hover { border-color: #d1d5db; background: #fff; }
        .filter-chip.active {
            background: var(--red,#ef4444); color: #fff; border-color: var(--red,#ef4444);
            box-shadow: 0 2px 8px rgba(111,16,34,0.25);
        }
        .filter-chip .count { opacity: 0.7; }

        /* ═══════ TIMELINE ═══════ */
        .timeline { position: relative; padding-left: 32px; }
        .timeline::before {
            content: '';
            position: absolute; left: 11px; top: 0; bottom: 0; width: 2px;
            background: linear-gradient(180deg, rgba(239,68,68,0.15), var(--border) 20%, var(--border) 80%, rgba(99,102,241,0.15));
            border-radius: 2px;
        }
        .tl-item {
            position: relative; padding-bottom: 16px;
            opacity: 0; animation: cardIn 0.5s cubic-bezier(0.22,1,0.36,1) both;
        }
        .tl-item:last-child { padding-bottom: 0; }
        .tl-dot {
            position: absolute; left: -32px; top: 14px;
            width: 22px; height: 22px; border-radius: 50%;
            border: 3px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,0.12);
            display: flex; align-items: center; justify-content: center;
            z-index: 2;
        }
        .tl-dot-inner { width: 6px; height: 6px; background: #fff; border-radius: 50%; }

        /* ═══════ CARDS ═══════ */
        .tl-card {
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 14px; overflow: hidden;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .tl-card:hover {
            border-color: rgba(239,68,68,0.3);
            box-shadow: 0 8px 20px -10px rgba(0,0,0,0.1);
        }
        .tl-card-header {
            padding: 10px 16px;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            display: flex; align-items: center; justify-content: space-between;
            gap: 8px;
        }
        .tl-card-header-label {
            display: flex; align-items: center; gap: 6px;
            font-size: 13px; font-weight: 600;
        }
        .tl-card-header-date { font-size: 11px; color: var(--text-3); white-space: nowrap; }
        .tl-card-body { padding: 12px 16px; }
        .tl-card-body p { font-size: 13px; color: var(--text-2); margin-bottom: 6px; }
        .tl-card-body p:last-child { margin-bottom: 0; }
        .tl-card-footer {
            padding: 8px 16px;
            background: rgba(249,250,251,0.7);
            border-top: 1px solid rgba(0,0,0,0.03);
            display: flex; align-items: center; gap: 12px;
            font-size: 11px; color: var(--text-3);
        }

        .tl-values { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 13px; }
        .tl-values .label { color: var(--text-3); }
        .tl-values strong { font-weight: 600; }

        .tl-change-badge {
            display: inline-flex; align-items: center;
            padding: 2px 8px; border-radius: 6px;
            font-size: 12px; font-weight: 600;
        }

        /* ═══════ EMPTY STATE ═══════ */
        .empty-state {
            background: rgba(255,255,255,0.88);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px; padding: 60px 24px; text-align: center;
            animation: cardIn 0.5s cubic-bezier(0.22,1,0.36,1) 0.2s both;
        }

        /* ═══════ COLORS ═══════ */
        .c-emerald { background: #059669; }
        .c-cyan { background: #0891b2; }
        .c-rose { background: #e11d48; }
        .c-slate { background: #64748b; }
        .c-green { background: #16a34a; }
        .c-red { background: var(--red,#6F1022); }
        .c-blue { background: #2563eb; }
        .c-purple { background: #9333ea; }
        .c-amber { background: #d97706; }
        .c-orange { background: #ea580c; }

        .hdr-emerald { background: linear-gradient(135deg, #ecfdf5, #d1fae5); }
        .hdr-cyan { background: linear-gradient(135deg, #ecfeff, #cffafe); }
        .hdr-rose { background: linear-gradient(135deg, #fff1f2, #ffe4e6); }
        .hdr-slate { background: linear-gradient(135deg, #f8fafc, #f1f5f9); }
        .hdr-green { background: linear-gradient(135deg, #f0fdf4, #dcfce7); }
        .hdr-red { background: linear-gradient(135deg, #fef2f2, #fee2e2); }
        .hdr-blue { background: linear-gradient(135deg, #eff6ff, #dbeafe); }
        .hdr-purple { background: linear-gradient(135deg, #faf5ff, #f3e8ff); }
        .hdr-amber { background: linear-gradient(135deg, #fffbeb, #fef3c7); }
        .hdr-orange { background: linear-gradient(135deg, #fff7ed, #ffedd5); }

        .ic-emerald { color: #059669; } .ic-cyan { color: #0891b2; }
        .ic-rose { color: #e11d48; } .ic-slate { color: #64748b; }
        .ic-green { color: #16a34a; } .ic-red { color: var(--red,#6F1022); }
        .ic-blue { color: #2563eb; } .ic-purple { color: #9333ea; }
        .ic-amber { color: #d97706; } .ic-orange { color: #ea580c; }

        /* ═══════ ANIMATIONS ═══════ */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0); }
            to { opacity: 1; transform: translate3d(0, 0, 0); }
        }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; }
            .header-title { font-size: 15px; }
            .header-back { width: 30px; height: 30px; }
            .header-divider { height: 18px; }
            .header-sub { font-size: 11px; gap: 8px; }
            .header-badge { font-size: 11px; padding: 3px 10px; }

            main { padding: 14px 10px !important; }
            .filter-bar { gap: 5px; margin-bottom: 12px; }
            .filter-chip { padding: 5px 10px; font-size: 11px; }

            .timeline { padding-left: 28px; }
            .timeline::before { left: 9px; }
            .tl-dot { left: -28px; width: 20px; height: 20px; top: 12px; }
            .tl-dot-inner { width: 5px; height: 5px; }
            .tl-item { padding-bottom: 12px; }

            .tl-card { border-radius: 10px; }
            .tl-card-header { padding: 8px 12px; flex-wrap: wrap; }
            .tl-card-header-label { font-size: 12px; }
            .tl-card-header-date { font-size: 10px; }
            .tl-card-body { padding: 10px 12px; }
            .tl-card-body p { font-size: 12px; }
            .tl-card-footer { padding: 6px 12px; font-size: 10px; gap: 8px; }
            .tl-values { font-size: 12px; gap: 3px 10px; }
            .tl-change-badge { font-size: 11px; padding: 2px 6px; }

            .empty-state { padding: 40px 16px; border-radius: 12px; }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="top-header">
        <div class="header-inner">
            <div class="header-left">
                <a href="<?php echo htmlspecialchars($fisGecmisBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="header-back" title="Geri Don">
                    <i class="fa fa-arrow-left"></i>
                </a>
                <div class="header-divider"></div>
                <div>
                    <span class="header-title">Degisiklik Gecmisi</span>
                    <div class="header-sub">
                        <span><i class="fa-solid fa-hashtag" style="color:var(--red,#ef4444);margin-right:2px;"></i><?php echo htmlspecialchars((string) $fisInfo['FICHENO']); ?></span>
                        <span><i class="fa-regular fa-calendar-alt" style="margin-right:2px;"></i><?php echo date('d.m.Y', strtotime((string) $fisInfo['DATE_'])); ?></span>
                    </div>
                </div>
            </div>
            <span class="header-badge <?php echo count($tumDegisiklikler) > 0 ? 'bg-red-50 text-red-600' : 'bg-gray-100 text-gray-500'; ?>">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <?php echo count($tumDegisiklikler); ?> kayit
            </span>
        </div>
    </header>

    <main style="max-width:900px;margin:0 auto;padding:24px 16px;">

        <?php if (count($tumDegisiklikler) > 0): ?>
        <!-- Filtreler -->
        <div class="filter-bar" id="filtreler">
            <button type="button" class="filter-chip active" data-filtre="TUMU">
                Tumu <span class="count">(<?php echo count($tumDegisiklikler); ?>)</span>
            </button>
            <?php if ($tipSayac['FIS'] > 0): ?>
            <button type="button" class="filter-chip" data-filtre="FIS">
                <i class="fa-solid fa-file"></i> Fis <span class="count">(<?php echo $tipSayac['FIS']; ?>)</span>
            </button>
            <?php endif; ?>
            <?php if ($tipSayac['SATIR'] > 0): ?>
            <button type="button" class="filter-chip" data-filtre="SATIR">
                <i class="fa-solid fa-list"></i> Satir <span class="count">(<?php echo $tipSayac['SATIR']; ?>)</span>
            </button>
            <?php endif; ?>
            <?php if ($tipSayac['ISKONTO'] > 0): ?>
            <button type="button" class="filter-chip" data-filtre="ISKONTO">
                <i class="fa-solid fa-percent"></i> Iskonto <span class="count">(<?php echo $tipSayac['ISKONTO']; ?>)</span>
            </button>
            <?php endif; ?>
            <?php if ($tipSayac['KDV'] > 0): ?>
            <button type="button" class="filter-chip" data-filtre="KDV">
                <i class="fa-solid fa-receipt"></i> KDV <span class="count">(<?php echo $tipSayac['KDV']; ?>)</span>
            </button>
            <?php endif; ?>
            <?php if ($tipSayac['FIYAT'] > 0): ?>
            <button type="button" class="filter-chip" data-filtre="FIYAT">
                <i class="fa-solid fa-tag"></i> Fiyat <span class="count">(<?php echo $tipSayac['FIYAT']; ?>)</span>
            </button>
            <?php endif; ?>
            <?php if ($tipSayac['YAZDIR'] > 0): ?>
            <button type="button" class="filter-chip" data-filtre="YAZDIR">
                <i class="fa-solid fa-print"></i> Yazdirma <span class="count">(<?php echo $tipSayac['YAZDIR']; ?>)</span>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (count($tumDegisiklikler) == 0): ?>
            <div class="empty-state">
                <div style="width:48px;height:48px;border-radius:14px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;color:var(--text-3);font-size:20px;">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <p style="font-size:16px;font-weight:600;color:var(--text-1);">Degisiklik kaydi bulunamadi</p>
                <p style="font-size:13px;color:var(--text-3);margin-top:6px;">Bu fis icin henuz bir degisiklik yapilmamis.</p>
                <a href="<?php echo htmlspecialchars($fisGecmisBackUrl, ENT_QUOTES, 'UTF-8'); ?>"
                   style="display:inline-flex;align-items:center;gap:6px;margin-top:16px;padding:8px 18px;border-radius:10px;background:var(--red,#ef4444);color:#fff;font-size:13px;font-weight:600;text-decoration:none;">
                    <i class="fa-solid fa-arrow-left"></i> Fise Don
                </a>
            </div>
        <?php else: ?>
            <!-- Timeline -->
            <div class="timeline">
                <?php
                $idx = 0;
                foreach ($tumDegisiklikler as $degisiklik):
                    $tarih = date('d.m.Y H:i', strtotime((string) $degisiklik['TARIH']));
                    $kullanici = $degisiklik['KULLANICI_ADI'] ?: 'Bilinmiyor';
                    $ip = $degisiklik['IP_ADRESI'] ?? '-';

                    if ($degisiklik['TIP'] == 'FIS') {
                        $islemTipi = $degisiklik['ISLEM_TIPI'];
                        if ($islemTipi == 'OLUSTURMA') { $dc='c-emerald'; $hc='hdr-emerald'; $ic='ic-emerald'; $icon='fa-file-circle-plus'; }
                        elseif ($islemTipi == 'GUNCELLEME') { $dc='c-cyan'; $hc='hdr-cyan'; $ic='ic-cyan'; $icon='fa-file-pen'; }
                        elseif ($islemTipi == 'SILME') { $dc='c-rose'; $hc='hdr-rose'; $ic='ic-rose'; $icon='fa-file-circle-xmark'; }
                        else { $dc='c-slate'; $hc='hdr-slate'; $ic='ic-slate'; $icon='fa-file'; }
                    } elseif ($degisiklik['TIP'] == 'SATIR') {
                        $islemTipi = $degisiklik['ISLEM_TIPI'];
                        if ($islemTipi == 'EKLEME' || $islemTipi == 'EKLE') { $dc='c-green'; $hc='hdr-green'; $ic='ic-green'; $icon='fa-plus-circle'; }
                        elseif ($islemTipi == 'SILME' || $islemTipi == 'SIL') { $dc='c-red'; $hc='hdr-red'; $ic='ic-red'; $icon='fa-trash-alt'; }
                        else { $dc='c-blue'; $hc='hdr-blue'; $ic='ic-blue'; $icon='fa-edit'; }
                    } elseif ($degisiklik['TIP'] == 'ISKONTO') { $dc='c-purple'; $hc='hdr-purple'; $ic='ic-purple'; $icon='fa-percent';
                    } elseif ($degisiklik['TIP'] == 'FIYAT') { $dc='c-amber'; $hc='hdr-amber'; $ic='ic-amber'; $icon='fa-tag';
                    } elseif ($degisiklik['TIP'] == 'YAZDIR') { $dc='c-slate'; $hc='hdr-slate'; $ic='ic-slate'; $icon='fa-print';
                    } else { $dc='c-orange'; $hc='hdr-orange'; $ic='ic-orange'; $icon='fa-receipt'; }
                ?>
                <div class="tl-item" style="animation-delay:calc(<?php echo $idx; ?> * 40ms);" data-tip="<?php echo htmlspecialchars($degisiklik['TIP']); ?>">
                    <div class="tl-dot <?php echo $dc; ?>"><div class="tl-dot-inner"></div></div>
                    <div class="tl-card">
                        <div class="tl-card-header <?php echo $hc; ?>">
                            <div class="tl-card-header-label">
                                <i class="fas <?php echo $icon; ?> <?php echo $ic; ?>"></i>
                                <span><?php
                                    if ($degisiklik['TIP'] == 'FIS') {
                                        $m = ['OLUSTURMA'=>'Fis Olusturuldu','GUNCELLEME'=>'Fis Guncellendi','SILME'=>'Fis Silindi','KAPANMA'=>'Fis Kapatildi'];
                                        echo $m[$degisiklik['ISLEM_TIPI']] ?? htmlspecialchars($degisiklik['ISLEM_TIPI']);
                                    } elseif ($degisiklik['TIP'] == 'SATIR') {
                                        $m = ['EKLEME'=>'Satir Eklendi','EKLE'=>'Satir Eklendi','SILME'=>'Satir Silindi','SIL'=>'Satir Silindi','DUZENLE'=>'Satir Duzenlendi','DUZENLEME'=>'Satir Duzenlendi'];
                                        echo $m[$degisiklik['ISLEM_TIPI']] ?? htmlspecialchars((string) $degisiklik['ISLEM_TIPI']);
                                    } elseif ($degisiklik['TIP'] == 'ISKONTO') { echo htmlspecialchars($degisiklik['ISKONTO_SEVIYE']) . '. Iskonto';
                                    } elseif ($degisiklik['TIP'] == 'FIYAT') { echo 'Fiyat Degisikligi';
                                    } elseif ($degisiklik['TIP'] == 'YAZDIR') { echo 'Fis Yazdirildi';
                                    } else { echo 'KDV Degisikligi'; }
                                ?></span>
                            </div>
                            <span class="tl-card-header-date"><i class="fa-regular fa-clock" style="margin-right:3px;"></i><?php echo $tarih; ?></span>
                        </div>

                        <div class="tl-card-body">
                            <?php if ($degisiklik['TIP'] == 'FIS' && !empty($degisiklik['CARI_ADI'])): ?>
                                <p><i class="fas fa-user-tie" style="margin-right:4px;color:var(--text-3);"></i><?php echo htmlspecialchars((string) $degisiklik['CARI_ADI']); ?><?php if (!empty($degisiklik['CARI_KODU'])): ?> <span style="color:var(--text-3);">(<?php echo htmlspecialchars((string) $degisiklik['CARI_KODU']); ?>)</span><?php endif; ?></p>
                            <?php endif; ?>

                            <?php if (in_array($degisiklik['TIP'], ['SATIR', 'KDV', 'FIYAT']) && !empty($degisiklik['STOK_KODU'])): ?>
                                <p><i class="fas fa-box" style="margin-right:4px;color:var(--text-3);"></i><strong style="color:var(--text-2);"><?php echo htmlspecialchars((string) $degisiklik['STOK_KODU']); ?></strong><?php if (!empty($degisiklik['STOK_ADI'])): ?> - <?php echo htmlspecialchars((string) $degisiklik['STOK_ADI']); ?><?php endif; ?></p>
                            <?php endif; ?>

                            <?php if ($degisiklik['TIP'] == 'FIS'): ?>
                                <?php if (!empty($degisiklik['ACIKLAMA'])): ?><p><?php echo htmlspecialchars((string) $degisiklik['ACIKLAMA']); ?></p><?php endif; ?>
                                <?php if ($degisiklik['GENEL_TOPLAM'] > 0): ?>
                                    <div class="tl-values" style="margin-top:4px;">
                                        <span><span class="label">Toplam:</span> <strong><?php echo paraFormat($degisiklik['TOPLAM_TUTAR']); ?> TL</strong></span>
                                        <span><span class="label">KDV:</span> <strong><?php echo paraFormat($degisiklik['TOPLAM_KDV']); ?> TL</strong></span>
                                        <span><span class="label">G.Toplam:</span> <strong style="color:#059669;"><?php echo paraFormat($degisiklik['GENEL_TOPLAM']); ?> TL</strong></span>
                                    </div>
                                <?php endif; ?>

                            <?php elseif ($degisiklik['TIP'] == 'SATIR'): ?>
                                <?php if ($degisiklik['ISLEM_TIPI'] == 'SIL' || $degisiklik['ISLEM_TIPI'] == 'SILME'): ?>
                                    <div class="tl-values">
                                        <span><span class="label">Miktar:</span> <strong style="color:var(--red,#6F1022);"><?php echo miktarFormat($degisiklik['ESKI_MIKTAR']); ?></strong></span>
                                        <span><span class="label">Fiyat:</span> <strong><?php echo paraFormat($degisiklik['ESKI_FIYAT']); ?> TL</strong></span>
                                        <span><span class="label">Toplam:</span> <strong style="color:var(--red,#6F1022);"><?php echo paraFormat($degisiklik['ESKI_TOTAL']); ?> TL</strong></span>
                                    </div>
                                <?php elseif (($degisiklik['ISLEM_TIPI'] == 'DUZENLE' || $degisiklik['ISLEM_TIPI'] == 'DUZENLEME') && !empty($degisiklik['ACIKLAMA'])): ?>
                                    <p><?php echo htmlspecialchars((string) $degisiklik['ACIKLAMA']); ?></p>
                                <?php else: ?>
                                    <div class="tl-values">
                                        <span><span class="label">Miktar:</span> <strong><?php echo miktarFormat($degisiklik['YENI_MIKTAR']); ?></strong></span>
                                        <span><span class="label">Fiyat:</span> <strong><?php echo paraFormat($degisiklik['YENI_FIYAT']); ?> TL</strong></span>
                                        <span><span class="label">Toplam:</span> <strong style="color:#059669;"><?php echo paraFormat($degisiklik['YENI_TOTAL']); ?> TL</strong></span>
                                    </div>
                                <?php endif; ?>

                            <?php elseif ($degisiklik['TIP'] == 'ISKONTO'): ?>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <?php if ($degisiklik['ESKI_ISKONTO'] > 0): ?>
                                        <span class="tl-change-badge" style="background:#f1f5f9;color:var(--text-3);text-decoration:line-through;">%<?php echo miktarFormat($degisiklik['ESKI_ISKONTO']); ?></span>
                                        <i class="fas fa-arrow-right" style="color:var(--text-3);font-size:10px;"></i>
                                    <?php endif; ?>
                                    <span class="tl-change-badge" style="background:#f3e8ff;color:#7c3aed;font-weight:700;">%<?php echo miktarFormat($degisiklik['YENI_ISKONTO']); ?></span>
                                    <span style="font-size:12px;color:var(--text-3);">Tutar: <strong style="color:var(--text-1);"><?php echo paraFormat($degisiklik['YENI_TUTAR']); ?> TL</strong></span>
                                </div>

                            <?php elseif ($degisiklik['TIP'] == 'FIYAT'): ?>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span class="tl-change-badge" style="background:#f1f5f9;color:var(--text-3);text-decoration:line-through;"><?php echo paraFormat($degisiklik['ESKI_FIYAT']); ?> TL</span>
                                    <i class="fas fa-arrow-right" style="color:var(--text-3);font-size:10px;"></i>
                                    <span class="tl-change-badge" style="background:#fef3c7;color:#b45309;font-weight:700;"><?php echo paraFormat($degisiklik['YENI_FIYAT']); ?> TL</span>
                                    <?php if (!empty($degisiklik['MIKTAR'])): ?>
                                        <span style="font-size:12px;color:var(--text-3);">x <?php echo miktarFormat($degisiklik['MIKTAR']); ?></span>
                                    <?php endif; ?>
                                </div>

                            <?php elseif ($degisiklik['TIP'] == 'YAZDIR'): ?>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <span class="tl-change-badge" style="background:#f1f5f9;color:#475569;font-weight:700;"><i class="fa-solid fa-print" style="margin-right:4px;"></i><?php echo htmlspecialchars((string) ($degisiklik['YAZDIRMA_TIPI'] ?: 'YAZDIRMA')); ?></span>
                                    <?php if ((int) ($degisiklik['YAZDIRMA_SAYISI'] ?? 0) > 0): ?>
                                        <span class="tl-change-badge" style="background:#e0e7ff;color:#4338ca;font-weight:700;"><?php echo (int) $degisiklik['YAZDIRMA_SAYISI']; ?>. yazdirma</span>
                                    <?php endif; ?>
                                    <?php if (!empty($degisiklik['ACIKLAMA'])): ?>
                                        <span style="font-size:12px;color:var(--text-3);"><?php echo htmlspecialchars((string) $degisiklik['ACIKLAMA']); ?></span>
                                    <?php endif; ?>
                                </div>

                            <?php else: ?>
                                <div style="display:flex;align-items:center;gap:8px;">
                                    <span class="tl-change-badge" style="background:#f1f5f9;color:var(--text-3);text-decoration:line-through;">%<?php echo (int)$degisiklik['ESKI_KDV']; ?></span>
                                    <i class="fas fa-arrow-right" style="color:var(--text-3);font-size:10px;"></i>
                                    <span class="tl-change-badge" style="background:#ffedd5;color:#c2410c;font-weight:700;">%<?php echo (int)$degisiklik['YENI_KDV']; ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="tl-card-footer">
                            <span><i class="fas fa-user" style="margin-right:3px;"></i><?php echo htmlspecialchars((string) $kullanici); ?></span>
                            <span><i class="fas fa-network-wired" style="margin-right:3px;"></i><?php echo htmlspecialchars((string) $ip); ?></span>
                        </div>
                    </div>
                </div>
                <?php $idx++; endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

<script>
(function() {
    var filtreler = document.getElementById('filtreler');
    if (!filtreler) return;

    filtreler.addEventListener('click', function(e) {
        var btn = e.target.closest('.filter-chip');
        if (!btn) return;

        var tip = btn.dataset.filtre;

        filtreler.querySelectorAll('.filter-chip').forEach(function(b) { b.classList.remove('active'); });
        btn.classList.add('active');

        document.querySelectorAll('.tl-item').forEach(function(item) {
            item.style.display = (tip === 'TUMU' || item.dataset.tip === tip) ? '' : 'none';
        });
    });
})();
</script>

</body>
</html>

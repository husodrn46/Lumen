<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once(__DIR__ . "/kontrol.php");

// Yetki kontrolü - M23 (Kullanıcı Aktivite Dashboard) yetkisi veya yönetici (YETKI = 0)
if (m_p_yetki($terminalkullanici, 'M23') != 1 && $yetkidurum !== 0) {
    echo '<div class="min-h-screen flex items-center justify-center bg-gray-100">';
    echo '<div class="bg-white p-8 rounded-lg shadow-lg text-center">';
    echo '<i class="fas fa-shield-alt text-red-500 text-6xl mb-4"></i>';
    echo '<h2 class="text-2xl font-bold text-gray-800 mb-2">Yetkisiz Erişim</h2>';
    echo '<p class="text-gray-600 mb-4">Bu sayfayı görüntüleme yetkiniz bulunmamaktadır.</p>';
    echo '<a href="index.php" class="bg-red-500 hover:bg-red-600 text-white px-6 py-2 rounded-lg inline-block transition-colors">';
    echo '<i class="fas fa-home mr-2"></i>Ana Sayfaya Dön</a>';
    echo '</div></div>';
    exit;
}

// Tarih filtreleri (SQL Server formatı)
$bugun = date('Y.m.d');
$_donem_input = (string)($_GET['donem'] ?? 'bu_ay');
$_donem_whitelist = ['bugun', 'bu_hafta', 'bu_ay', 'son_3_ay', 'son_6_ay', 'bu_yil'];
$filtre_donem = in_array($_donem_input, $_donem_whitelist, true) ? $_donem_input : 'bu_ay';
$filtre_kullanici = isset($_GET['kullanici']) ? intval($_GET['kullanici']) : 0;
$filtre_gun_raw = $_GET['gun'] ?? date('Y-m-d');
$filtre_gun_ts = strtotime((string)$filtre_gun_raw);
if ($filtre_gun_ts === false) {
    $filtre_gun_ts = strtotime('today');
}
$filtre_gun_input = date('Y-m-d', $filtre_gun_ts);
$filtre_gun_sql = date('Y.m.d', $filtre_gun_ts);
$filtre_gun_label = date('d.m.Y', $filtre_gun_ts);

// Dönem bazlı tarih hesaplama
switch ($filtre_donem) {
    case 'bugun':
        $filtre_tarih_bas = $filtre_gun_sql;
        $filtre_tarih_son = $filtre_gun_sql;
        break;
    case 'bu_hafta':
        $filtre_tarih_bas = date('Y.m.d', strtotime('monday this week'));
        $filtre_tarih_son = date('Y.m.d', strtotime('sunday this week'));
        break;
    case 'bu_ay':
        $filtre_tarih_bas = date('Y.m.01');
        $filtre_tarih_son = date('Y.m.t');
        break;
    case 'son_3_ay':
        $filtre_tarih_bas = date('Y.m.01', strtotime('-2 months'));
        $filtre_tarih_son = date('Y.m.t');
        break;
    case 'son_6_ay':
        $filtre_tarih_bas = date('Y.m.01', strtotime('-5 months'));
        $filtre_tarih_son = date('Y.m.t');
        break;
    case 'bu_yil':
        $filtre_tarih_bas = date('Y.01.01');
        $filtre_tarih_son = date('Y.12.31');
        break;
    default:
        $filtre_tarih_bas = date('Y.m.01');
        $filtre_tarih_son = date('Y.m.t');
}

// Firma numarasını çıkar (LG_001_ -> 1)
$firmaNr = intval(str_replace(['LG_', '_'], '', $firma));
$satisSiparisWhere = "TRCODE IN (1,20,21,22) AND NETTOTAL > 0 AND ISNULL(CANCELLED,0) = 0";
$satisFaturaWhere = "TRCODE IN (7,8) AND NETTOTAL > 0 AND ISNULL(CANCELLED,0) = 0";
$satisSiparisWhereF = "F.TRCODE IN (1,20,21,22) AND F.NETTOTAL > 0 AND ISNULL(F.CANCELLED,0) = 0";

// Tüm aktif kullanıcıları çek
$stmtKullanicilar = $dbh->prepare("
    SELECT LOGICALREF, CODE, DEFINITION_
    FROM LG_SLSMAN
    WHERE FIRMNR = :firmaNr
        AND ACTIVE = 0
    ORDER BY DEFINITION_ ASC
");
$stmtKullanicilar->execute([':firmaNr' => $firmaNr]);
$tumKullanicilar = $stmtKullanicilar->fetchAll(PDO::FETCH_ASSOC);

// Kullanıcı bazlı aktivite metrikleri (iptal olmayan satış siparişleri + satış faturaları)
$performansSQL = "
    SELECT
        S.LOGICALREF AS KULLANICI_ID,
        S.CODE AS KULLANICI_KODU,
        S.DEFINITION_ AS KULLANICI_ADI,
        COUNT(DISTINCT F.FIS_UNIQ_ID) AS ISLEM_SAYISI,
        SUM(CASE WHEN F.ISLEM_TIPI = 'siparis' THEN 1 ELSE 0 END) AS SIPARIS_SAYISI,
        SUM(CASE WHEN F.ISLEM_TIPI = 'fatura' THEN 1 ELSE 0 END) AS FATURA_SAYISI,
        ISNULL(SUM(F.NETTOTAL), 0) AS TOPLAM_TUTAR,
        ISNULL(AVG(F.NETTOTAL), 0) AS ORTALAMA_ISLEM,
        SUM(CASE WHEN CONVERT(VARCHAR, F.DATE_, 102) = :bugun_islem THEN 1 ELSE 0 END) AS BUGUN_ISLEM,
        ISNULL(SUM(CASE WHEN CONVERT(VARCHAR, F.DATE_, 102) = :bugun_tutar THEN F.NETTOTAL ELSE 0 END), 0) AS BUGUN_TUTAR
    FROM LG_SLSMAN S
    LEFT JOIN (
        -- Satış siparişleri - iptal ve 0 TL hariç
        SELECT
            CONCAT('O-', CAST(LOGICALREF AS VARCHAR(30))) AS FIS_UNIQ_ID,
            LOGICALREF,
            SALESMANREF,
            DATE_,
            NETTOTAL,
            TRCODE,
            'siparis' AS ISLEM_TIPI
        FROM " . $firmadonem . "ORFICHE
        WHERE {$satisSiparisWhere}
        UNION ALL
        -- Satış faturaları - iptal ve 0 TL hariç
        SELECT
            CONCAT('I-', CAST(LOGICALREF AS VARCHAR(30))) AS FIS_UNIQ_ID,
            LOGICALREF,
            SALESMANREF,
            DATE_,
            NETTOTAL,
            TRCODE,
            'fatura' AS ISLEM_TIPI
        FROM " . $firmadonem . "INVOICE
        WHERE {$satisFaturaWhere}
    ) F ON F.SALESMANREF = S.LOGICALREF
        AND CONVERT(VARCHAR, F.DATE_, 102) BETWEEN :filtre_tarih_bas AND :filtre_tarih_son
    WHERE S.FIRMNR = :firmaNr
        AND S.ACTIVE = 0
";

$performansParams = [':bugun_islem' => $filtre_gun_sql, ':bugun_tutar' => $filtre_gun_sql, ':filtre_tarih_bas' => $filtre_tarih_bas, ':filtre_tarih_son' => $filtre_tarih_son, ':firmaNr' => $firmaNr];

if ($filtre_kullanici > 0) {
    $performansSQL .= " AND S.LOGICALREF = :filtre_kullanici";
    $performansParams[':filtre_kullanici'] = $filtre_kullanici;
}

$performansSQL .= "
    GROUP BY S.LOGICALREF, S.CODE, S.DEFINITION_
    ORDER BY ISLEM_SAYISI DESC, TOPLAM_TUTAR DESC
";

$stmtPerformans = $dbh->prepare($performansSQL);
$stmtPerformans->execute($performansParams);
$performansVerileri = $stmtPerformans->fetchAll(PDO::FETCH_ASSOC);

// Genel istatistikler (iptal olmayan satış siparişleri + satış faturaları)
$genelStatsSQL = "
    SELECT
        COUNT(DISTINCT F.FIS_UNIQ_ID) AS TOPLAM_ISLEM,
        SUM(CASE WHEN F.ISLEM_TIPI = 'siparis' THEN 1 ELSE 0 END) AS SIPARIS_SAYISI,
        SUM(CASE WHEN F.ISLEM_TIPI = 'fatura' THEN 1 ELSE 0 END) AS FATURA_SAYISI,
        ISNULL(SUM(F.NETTOTAL), 0) AS TOPLAM_TUTAR,
        COUNT(DISTINCT F.CLIENTREF) AS AKTIF_MUSTERI,
        COUNT(DISTINCT F.SALESMANREF) AS AKTIF_SATICI
    FROM (
        -- Satış siparişleri - iptal ve 0 TL hariç
        SELECT
            CONCAT('O-', CAST(LOGICALREF AS VARCHAR(30))) AS FIS_UNIQ_ID,
            LOGICALREF,
            CLIENTREF,
            SALESMANREF,
            DATE_,
            NETTOTAL,
            'siparis' AS ISLEM_TIPI
        FROM " . $firmadonem . "ORFICHE
        WHERE {$satisSiparisWhere}
        UNION ALL
        -- Satış faturaları - iptal ve 0 TL hariç
        SELECT
            CONCAT('I-', CAST(LOGICALREF AS VARCHAR(30))) AS FIS_UNIQ_ID,
            LOGICALREF,
            CLIENTREF,
            SALESMANREF,
            DATE_,
            NETTOTAL,
            'fatura' AS ISLEM_TIPI
        FROM " . $firmadonem . "INVOICE
        WHERE {$satisFaturaWhere}
    ) F
    WHERE CONVERT(VARCHAR, F.DATE_, 102) BETWEEN :filtre_tarih_bas AND :filtre_tarih_son
";
$genelStatsParams = [':filtre_tarih_bas' => $filtre_tarih_bas, ':filtre_tarih_son' => $filtre_tarih_son];
if ($filtre_kullanici > 0) {
    $genelStatsSQL .= " AND F.SALESMANREF = :filtre_kullanici";
    $genelStatsParams[':filtre_kullanici'] = $filtre_kullanici;
}
$stmtGenelStats = $dbh->prepare($genelStatsSQL);
$stmtGenelStats->execute($genelStatsParams);
$genelStats = $stmtGenelStats->fetch(PDO::FETCH_ASSOC);

// Saatlik aktivite analizi (bugün için)
$saatlikAktivite = [];
try {
    $saatlikAktiviteSQL = "
        SELECT
            CAST(FLOOR(CAST(F.TIME_ AS BIGINT) / 16777216.0) AS INT) AS SAAT,
            COUNT(*) AS ISLEM_SAYISI,
            ISNULL(SUM(F.NETTOTAL), 0) AS TUTAR
        FROM (
            -- Satış siparişleri - iptal ve 0 TL hariç
            SELECT DATE_, TIME_, NETTOTAL, SALESMANREF
            FROM " . $firmadonem . "ORFICHE
            WHERE {$satisSiparisWhere}
            UNION ALL
            -- Satış faturaları - iptal ve 0 TL hariç
            SELECT DATE_, TIME_, NETTOTAL, SALESMANREF
            FROM " . $firmadonem . "INVOICE
            WHERE {$satisFaturaWhere}
        ) F
        WHERE CONVERT(VARCHAR, F.DATE_, 102) = :bugun
            AND F.TIME_ IS NOT NULL
            AND F.TIME_ > 0
    ";
    $saatlikAktiviteParams = [':bugun' => $filtre_gun_sql];
    if ($filtre_kullanici > 0) {
        $saatlikAktiviteSQL .= " AND F.SALESMANREF = :filtre_kullanici";
        $saatlikAktiviteParams[':filtre_kullanici'] = $filtre_kullanici;
    }
    $saatlikAktiviteSQL .= "
        GROUP BY CAST(FLOOR(CAST(F.TIME_ AS BIGINT) / 16777216.0) AS INT)
        ORDER BY CAST(FLOOR(CAST(F.TIME_ AS BIGINT) / 16777216.0) AS INT) ASC
    ";
    $stmtSaatlikAktivite = $dbh->prepare($saatlikAktiviteSQL);
    $stmtSaatlikAktivite->execute($saatlikAktiviteParams);
    $saatlikAktivite = $stmtSaatlikAktivite->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {
    // TIME_ alanı yoksa veya farklı formattaysa sadece tarih bazlı göster
    $saatlikFallbackSQL = "
        SELECT
            -1 AS SAAT,
            COUNT(*) AS ISLEM_SAYISI,
            ISNULL(SUM(F.NETTOTAL), 0) AS TUTAR
        FROM (
            -- Satış siparişleri - iptal ve 0 TL hariç
            SELECT DATE_, NETTOTAL, SALESMANREF
            FROM " . $firmadonem . "ORFICHE
            WHERE {$satisSiparisWhere}
            UNION ALL
            -- Satış faturaları - iptal ve 0 TL hariç
            SELECT DATE_, NETTOTAL, SALESMANREF
            FROM " . $firmadonem . "INVOICE
            WHERE {$satisFaturaWhere}
        ) F
        WHERE CONVERT(VARCHAR, F.DATE_, 102) = :bugun
    ";
    $saatlikFallbackParams = [':bugun' => $filtre_gun_sql];
    if ($filtre_kullanici > 0) {
        $saatlikFallbackSQL .= " AND F.SALESMANREF = :filtre_kullanici";
        $saatlikFallbackParams[':filtre_kullanici'] = $filtre_kullanici;
    }
    $stmtSaatlikAktiviteFallback = $dbh->prepare($saatlikFallbackSQL);
    $stmtSaatlikAktiviteFallback->execute($saatlikFallbackParams);
    $saatlikAktivite = $stmtSaatlikAktiviteFallback->fetchAll(PDO::FETCH_ASSOC);
}

// En cok iskonto veren kullanicilar
$iskontoAnalizi = [];
try {
    $iskontoSQL = "
        SELECT TOP 5
            S.LOGICALREF AS KULLANICI_ID,
            S.DEFINITION_ AS KULLANICI_ADI,
            COUNT(DISTINCT IL.FIS_REF) AS ISKONTO_SAYISI,
            ISNULL(AVG(IL.YENI_DISCPER), 0) AS ORTALAMA_ISKONTO,
            ISNULL(MAX(IL.YENI_DISCPER), 0) AS MAX_ISKONTO
        FROM M_ISKONTO_LOG IL
        LEFT JOIN LG_SLSMAN S ON S.LOGICALREF = IL.KULLANICI
        LEFT JOIN " . $firmadonem . "ORFICHE F ON F.LOGICALREF = IL.FIS_REF
        WHERE CONVERT(VARCHAR, F.DATE_, 102) BETWEEN :filtre_tarih_bas AND :filtre_tarih_son
          AND {$satisSiparisWhereF}
    ";
    $iskontoParams = [':filtre_tarih_bas' => $filtre_tarih_bas, ':filtre_tarih_son' => $filtre_tarih_son];
    if ($filtre_kullanici > 0) {
        $iskontoSQL .= " AND IL.KULLANICI = :filtre_kullanici";
        $iskontoParams[':filtre_kullanici'] = $filtre_kullanici;
    }
    $iskontoSQL .= "
        GROUP BY S.LOGICALREF, S.DEFINITION_
        ORDER BY ISKONTO_SAYISI DESC
    ";
    $stmtIskontoAnalizi = $dbh->prepare($iskontoSQL);
    $stmtIskontoAnalizi->execute($iskontoParams);
    $iskontoAnalizi = $stmtIskontoAnalizi->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException) {
    // M_ISKONTO_LOG tablosu yoksa boş dizi
    $iskontoAnalizi = [];
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kullanici Aktivite Dashboard</title>
    <?php include_once(__DIR__ . '/pwa-header.php'); ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* HEADER */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            height: 100%; display: flex; align-items: center; gap: 14px;
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
            display: inline-flex; align-items: center; gap: 10px;
        }
        .header-title i { color: var(--red); font-size: 16px; }
        .header-sub {
            font-size: 11px; color: var(--text-3);
            margin-left: 4px; font-weight: 500;
        }

        /* MAIN LAYOUT */
        main.page {
            max-width: 1200px;
            margin: 0 auto;
            padding: 22px 24px 60px;
        }

        /* GLASS CARD */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 18px;
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
            background: var(--red-soft);
            color: var(--red);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 14px;
        }
        .card-head .icon-box.tone-indigo { background: var(--indigo-soft); color: var(--indigo); }
        .card-head .icon-box.tone-sky    { background: var(--sky-soft);    color: var(--sky); }
        .card-head .icon-box.tone-emerald{ background: var(--emerald-soft);color: var(--emerald); }
        .card-head .icon-box.tone-amber  { background: var(--amber-soft);  color: var(--amber); }
        .card-head .icon-box.tone-purple { background: var(--purple-soft); color: var(--purple); }
        .card-head h2 { font-size: 14px; font-weight: 700; color: var(--text-1); }
        .card-head p { font-size: 11.5px; color: var(--text-2); margin-top: 1px; }
        .card-body { padding: 18px 20px; }

        /* FILTER PANEL */
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
        }
        .filter-field label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 6px;
        }
        .filter-field label i { color: var(--red); margin-right: 6px; }
        .filter-input, .filter-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            color: var(--text-1);
            background: #fff;
            transition: all 0.2s ease;
            outline: none;
        }
        .filter-input:focus, .filter-select:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .filter-apply {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 10px 14px;
            background: var(--red);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .filter-apply:hover { background: #b91c1c; transform: translateY(-1px); box-shadow: 0 8px 18px rgba(111,16,34,0.18); }
        .filter-field.apply { display: flex; align-items: flex-end; }
        .filter-summary {
            margin-top: 14px;
            padding: 10px 14px;
            background: var(--red-soft);
            border: 1px solid rgba(248, 113, 113, 0.22);
            border-radius: 10px;
            color: var(--red);
            font-size: 12px;
            font-weight: 500;
        }
        .filter-summary i { margin-right: 6px; }
        .filter-summary strong { font-weight: 700; }
        .filter-summary .sep { margin: 0 8px; color: rgba(111,16,34,0.4); }

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
        .stat-icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .stat-card.tone-sky .stat-icon     { background: var(--sky-soft);     color: var(--sky); }
        .stat-card.tone-emerald .stat-icon { background: var(--emerald-soft); color: var(--emerald); }
        .stat-card.tone-indigo .stat-icon  { background: var(--indigo-soft);  color: var(--indigo); }
        .stat-card.tone-amber .stat-icon   { background: var(--amber-soft);   color: var(--amber); }
        .stat-body { min-width: 0; }
        .stat-label {
            font-size: 10px;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .stat-value {
            font-size: 22px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
            margin-top: 2px;
            word-break: break-word;
        }
        .stat-card.tone-emerald .stat-value { color: var(--emerald); }
        .stat-note {
            margin-top: 3px;
            font-size: 10.5px;
            color: var(--text-3);
            white-space: nowrap;
        }

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
            position: sticky;
            top: 0;
        }
        .gd-table tbody td {
            padding: 11px 12px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .gd-table tbody tr { cursor: pointer; transition: background 0.15s ease; }
        .gd-table tbody tr:hover { background: var(--red-soft); }
        .gd-table .col-center { text-align: center; }
        .gd-table .col-right { text-align: right; }
        .gd-table .rank-cell {
            font-weight: 700;
            color: var(--text-1);
            white-space: nowrap;
        }
        .gd-table .rank-cell i { margin-right: 4px; }
        .gd-table .rank-cell .gold   { color: #f59e0b; }
        .gd-table .rank-cell .silver { color: #9ca3af; }
        .gd-table .rank-cell .bronze { color: #d97706; }
        .gd-table .user-name {
            font-weight: 600;
            color: var(--text-1);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .gd-table .user-name i { color: var(--sky); }
        .gd-table .user-code {
            font-size: 10.5px;
            color: var(--text-3);
            margin-top: 2px;
        }
        .pill {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 12px;
            font-weight: 700;
            background: var(--sky-soft);
            color: var(--sky);
        }
        .val-money {
            font-weight: 700;
            color: var(--emerald);
            white-space: nowrap;
        }
        .val-soft {
            color: var(--text-2);
            white-space: nowrap;
        }
        .daily-cell strong {
            display: block;
            color: var(--purple);
            font-weight: 700;
            font-size: 12.5px;
        }
        .daily-cell small {
            color: var(--text-3);
            font-size: 10.5px;
        }

        /* EMPTY */
        .empty {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 16px;
            text-align: center;
            color: var(--text-3);
        }
        .empty i { font-size: 34px; color: #d1d5db; margin-bottom: 10px; }
        .empty p { font-size: 12.5px; color: var(--text-2); }

        /* TWO COL */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-bottom: 18px;
        }
        .two-col .glass-card { margin-bottom: 0; }

        /* CHART WRAP */
        .chart-wrap { padding: 8px 4px 4px; }

        /* DAY SUMMARY */
        .day-summary { margin-top: 14px; }
        .day-summary .busy-tag {
            padding: 10px 14px;
            background: var(--indigo-soft);
            border: 1px solid rgba(79, 70, 229, 0.18);
            border-radius: 10px;
            font-size: 12px;
            color: var(--indigo);
            margin-bottom: 10px;
        }
        .day-summary .busy-tag strong { font-weight: 700; }
        .day-slot {
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px 14px;
            background: #fafafa;
            margin-bottom: 8px;
        }
        .day-slot .slot-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .day-slot .slot-name {
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-1);
        }
        .day-slot .slot-name small { color: var(--text-3); font-weight: 500; margin-left: 4px; }
        .day-slot .slot-count {
            font-size: 12px;
            font-weight: 700;
            color: var(--indigo);
        }
        .day-slot .slot-ciro {
            font-size: 10.5px;
            color: var(--text-3);
            margin-top: 4px;
        }

        /* ISKONTO LIST */
        .iskonto-list { display: flex; flex-direction: column; gap: 10px; }
        .iskonto-item {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            background: #fff;
            transition: all 0.2s ease;
        }
        .iskonto-item:hover { border-color: rgba(239, 68, 68, 0.32); }
        .iskonto-item .iskonto-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .iskonto-item .iskonto-name {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-1);
        }
        .iskonto-item .iskonto-count {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 10.5px;
            font-weight: 700;
            background: var(--red-soft);
            color: var(--red);
        }
        .iskonto-item .iskonto-meta {
            display: flex;
            gap: 14px;
            font-size: 11.5px;
            color: var(--text-2);
        }
        .iskonto-item .iskonto-meta span strong { color: var(--text-1); font-weight: 700; }

        /* MODAL */
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
            max-width: 720px;
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
        }
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
            padding: 8px 18px;
            border-radius: 10px;
            border: none;
            background: var(--red);
            color: #fff;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .m-foot .btn:hover { background: #b91c1c; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* RESPONSIVE */
        @media (max-width: 1100px) {
            .stat-row { grid-template-columns: repeat(2, 1fr); }
            .filter-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .two-col { grid-template-columns: 1fr; }
        }
        @media (max-width: 767px) {
            .top-header { height: 52px; }
            .header-inner { padding: 0 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-sub { display: none; }
            .header-back { width: 32px; height: 32px; border-radius: 8px; }

            main.page { padding: 14px 12px 40px; }

            .stat-row { grid-template-columns: 1fr; gap: 10px; }
            .stat-card { padding: 14px 14px 14px 18px; }
            .stat-value { font-size: 18px; }

            .filter-grid { grid-template-columns: 1fr; gap: 10px; }

            .card-head { padding: 14px 16px 12px; }
            .card-body { padding: 14px 16px; }

            .gd-table { font-size: 12px; }
            .gd-table thead th { padding: 8px 10px; font-size: 9.5px; }
            .gd-table tbody td { padding: 10px; }
            .gd-table .user-code { font-size: 9.5px; }

            .m-dialog { max-height: 92vh; }
            .m-head { padding: 12px 16px; }
            .m-body { padding: 14px 16px; }
            .m-foot { padding: 10px 16px; }
        }
    </style>
</head>
<body>

<header class="top-header">
    <div class="header-inner">
        <a href="index.php" class="header-back" title="Ana Sayfa">
            <i class="fa fa-arrow-left"></i>
        </a>
        <div class="header-divider"></div>
        <span class="header-title">
            <i class="fa-solid fa-chart-line"></i>
            Kullanici Aktivite Dashboard
        </span>
        <span class="header-sub">Satis ekibi performans ve kullanim analizi</span>
    </div>
</header>

<main class="page">
    <!-- Filtreler -->
    <section class="glass-card">
        <div class="card-head">
            <span class="icon-box"><i class="fa-solid fa-sliders"></i></span>
            <div>
                <h2>Filtreler</h2>
                <p>Donem, gun ve kullanici secimi</p>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" class="filter-grid">
                <div class="filter-field">
                    <label><i class="far fa-calendar-alt"></i>Donem</label>
                    <select name="donem" class="filter-select">
                        <option value="bugun" <?php echo $filtre_donem == 'bugun' ? 'selected' : ''; ?>>Bugün</option>
                        <option value="bu_hafta" <?php echo $filtre_donem == 'bu_hafta' ? 'selected' : ''; ?>>Bu Hafta</option>
                        <option value="bu_ay" <?php echo $filtre_donem == 'bu_ay' ? 'selected' : ''; ?>>Bu Ay</option>
                        <option value="son_3_ay" <?php echo $filtre_donem == 'son_3_ay' ? 'selected' : ''; ?>>Son 3 Ay</option>
                        <option value="son_6_ay" <?php echo $filtre_donem == 'son_6_ay' ? 'selected' : ''; ?>>Son 6 Ay</option>
                        <option value="bu_yil" <?php echo $filtre_donem == 'bu_yil' ? 'selected' : ''; ?>>Bu Yıl</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label><i class="far fa-calendar-check"></i>Secili Gun</label>
                    <input type="date" name="gun" value="<?php echo htmlspecialchars($filtre_gun_input); ?>" class="filter-input">
                </div>
                <div class="filter-field">
                    <label><i class="fas fa-user-tie"></i>Kullanici</label>
                    <select name="kullanici" class="filter-select">
                        <option value="0">Tüm Kullanıcılar</option>
                        <?php foreach ($tumKullanicilar as $kullanici): ?>
                            <option value="<?php echo $kullanici['LOGICALREF']; ?>" <?php echo $filtre_kullanici == $kullanici['LOGICALREF'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(tr($kullanici['DEFINITION_'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-field apply">
                    <button type="submit" class="filter-apply">
                        <i class="fas fa-sync-alt"></i>Uygula
                    </button>
                </div>
            </form>
            <div class="filter-summary">
                <i class="fas fa-info-circle"></i>
                <strong>Secili donem:</strong>
                <?php
                    $ts_bas = strtotime(str_replace('.', '-', $filtre_tarih_bas));
                    $ts_son = strtotime(str_replace('.', '-', $filtre_tarih_son));
                    echo date('d.m.Y', $ts_bas !== false ? $ts_bas : time());
                ?> - <?php
                    echo date('d.m.Y', $ts_son !== false ? $ts_son : time());
                ?>
                <span class="sep">|</span>
                <strong>Secili gun:</strong> <?php echo htmlspecialchars($filtre_gun_label); ?>
            </div>
        </div>
    </section>

    <!-- Genel Istatistikler -->
    <div class="stat-row">
        <div class="stat-card tone-sky">
            <span class="stat-icon"><i class="fas fa-shopping-cart"></i></span>
            <div class="stat-body">
                <div class="stat-label">Toplam Islem</div>
                <div class="stat-value"><?php echo number_format((float)($genelStats['TOPLAM_ISLEM'] ?? 0)); ?></div>
                <div class="stat-note">
                    <?php echo number_format((float)($genelStats['SIPARIS_SAYISI'] ?? 0)); ?> siparis /
                    <?php echo number_format((float)($genelStats['FATURA_SAYISI'] ?? 0)); ?> fatura
                </div>
            </div>
        </div>
        <div class="stat-card tone-emerald">
            <span class="stat-icon"><i class="fas fa-lira-sign"></i></span>
            <div class="stat-body">
                <div class="stat-label">Toplam Islem Tutari</div>
                <div class="stat-value"><?php echo number_format((float)($genelStats['TOPLAM_TUTAR'] ?? 0), 2, ',', '.'); ?> TL</div>
            </div>
        </div>
        <div class="stat-card tone-indigo">
            <span class="stat-icon"><i class="fas fa-users"></i></span>
            <div class="stat-body">
                <div class="stat-label">Aktif Musteri</div>
                <div class="stat-value"><?php echo number_format((float)$genelStats['AKTIF_MUSTERI']); ?></div>
            </div>
        </div>
        <div class="stat-card tone-amber">
            <span class="stat-icon"><i class="fas fa-user-tie"></i></span>
            <div class="stat-body">
                <div class="stat-label">Aktif Satici</div>
                <div class="stat-value"><?php echo number_format((float)$genelStats['AKTIF_SATICI']); ?></div>
            </div>
        </div>
    </div>

    <!-- Kullanici Performans Tablosu -->
    <section class="glass-card">
        <div class="card-head">
            <span class="icon-box tone-sky"><i class="fas fa-chart-line"></i></span>
            <div>
                <h2>Kullanici Performansi</h2>
                <p>Donem bazli islem, siparis/fatura kirilimi ve tutar verileri</p>
            </div>
        </div>
        <div class="card-body" style="padding: 0;">
            <?php if (count($performansVerileri) > 0): ?>
                <div style="overflow-x:auto;">
                    <table class="gd-table">
                        <thead>
                            <tr>
                                <th>Sira</th>
                                <th>Kullanici</th>
                                <th class="col-center">Islem</th>
                                <th class="col-center">Siparis / Fatura</th>
                                <th class="col-right">Toplam Tutar</th>
                                <th class="col-right">Ort. Islem</th>
                                <th class="col-center">Secili Gun</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($performansVerileri as $index => $kullanici): ?>
                                <?php
                                $badge = '';
                                if ($index == 0) {
                                    $badge = '<i class="fas fa-trophy gold"></i>';
                                } elseif ($index == 1) {
                                    $badge = '<i class="fas fa-medal silver"></i>';
                                } elseif ($index == 2) {
                                    $badge = '<i class="fas fa-award bronze"></i>';
                                }
                                ?>
                                <tr onclick="kullaniciDetayGoster(<?php echo $kullanici['KULLANICI_ID']; ?>)">
                                    <td class="rank-cell">
                                        <?php echo $badge; ?>#<?php echo $index + 1; ?>
                                    </td>
                                    <td>
                                        <div class="user-name">
                                            <i class="fas fa-user-circle"></i>
                                            <?php echo htmlspecialchars((string) $kullanici['KULLANICI_ADI']); ?>
                                        </div>
                                        <div class="user-code"><?php echo htmlspecialchars((string) $kullanici['KULLANICI_KODU']); ?></div>
                                    </td>
                                    <td class="col-center">
                                        <span class="pill"><?php echo number_format((float)($kullanici['ISLEM_SAYISI'] ?? 0)); ?></span>
                                    </td>
                                    <td class="col-center val-soft">
                                        <?php echo number_format((float)($kullanici['SIPARIS_SAYISI'] ?? 0)); ?> /
                                        <?php echo number_format((float)($kullanici['FATURA_SAYISI'] ?? 0)); ?>
                                    </td>
                                    <td class="col-right val-money">
                                        <?php echo number_format((float)($kullanici['TOPLAM_TUTAR'] ?? 0), 2, ',', '.'); ?> TL
                                    </td>
                                    <td class="col-right val-soft">
                                        <?php echo number_format((float)($kullanici['ORTALAMA_ISLEM'] ?? 0), 2, ',', '.'); ?> TL
                                    </td>
                                    <td class="col-center daily-cell">
                                        <strong><?php echo number_format((float)($kullanici['BUGUN_ISLEM'] ?? 0)); ?> islem</strong>
                                        <small><?php echo number_format((float)($kullanici['BUGUN_TUTAR'] ?? 0), 2, ',', '.'); ?> TL</small>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty">
                    <i class="fas fa-user-slash"></i>
                    <p>Bu filtreler icin performans verisi bulunamadi.</p>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Grafikler -->
    <div class="two-col">
        <!-- Saatlik Aktivite -->
        <section class="glass-card">
            <div class="card-head">
                <span class="icon-box tone-indigo"><i class="fas fa-clock"></i></span>
                <div>
                    <h2>Secili Gun (<?php echo htmlspecialchars($filtre_gun_label); ?>) - Saatlik Aktivite</h2>
                    <p>Islem adedi dagilimi</p>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-wrap">
                    <canvas id="saatlikChart"></canvas>
                </div>
                <div id="gunBolumOzeti" class="day-summary"></div>
            </div>
        </section>

        <!-- Iskonto Analizi -->
        <section class="glass-card">
            <div class="card-head">
                <span class="icon-box"><i class="fas fa-percent"></i></span>
                <div>
                    <h2>Iskonto Kullanimi (Top 5)</h2>
                    <p>En cok iskonto veren kullanicilar</p>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($iskontoAnalizi)): ?>
                    <div class="iskonto-list">
                        <?php foreach ($iskontoAnalizi as $iskonto): ?>
                            <div class="iskonto-item">
                                <div class="iskonto-head">
                                    <span class="iskonto-name"><?php echo htmlspecialchars((string) $iskonto['KULLANICI_ADI']); ?></span>
                                    <span class="iskonto-count"><?php echo $iskonto['ISKONTO_SAYISI']; ?> islem</span>
                                </div>
                                <div class="iskonto-meta">
                                    <span>Ort: <strong>%<?php echo number_format((float)$iskonto['ORTALAMA_ISKONTO'], 2); ?></strong></span>
                                    <span>Max: <strong>%<?php echo number_format((float)$iskonto['MAX_ISKONTO'], 2); ?></strong></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">
                        <i class="fas fa-inbox"></i>
                        <p>Bu donem icin iskonto verisi bulunamadi.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<script>
// Saatlik Aktivite Grafiği
const saatlikData = <?php echo json_encode($saatlikAktivite); ?>;
const mesaiBaslangicSaat = 8;
const mesaiBitisSaat = 18;
const saatler = [];
const islemler = [];
let mesaiDisiIslem = 0;
let saatBilgisiYokIslem = 0;

saatlikData.forEach(d => {
    const saat = Number(d.SAAT);
    const islem = Number(d.ISLEM_SAYISI || 0);

    if (saat === -1 || Number.isNaN(saat)) {
        saatBilgisiYokIslem += islem;
        return;
    }

    if (saat >= mesaiBaslangicSaat && saat < mesaiBitisSaat) {
        saatler.push(String(saat).padStart(2, '0') + ':00');
        islemler.push(islem);
    } else {
        mesaiDisiIslem += islem;
    }
});

if (mesaiDisiIslem > 0) {
    saatler.push('Mesai Dışı');
    islemler.push(mesaiDisiIslem);
}

if (saatBilgisiYokIslem > 0) {
    saatler.push('Saat bilgisi yok');
    islemler.push(saatBilgisiYokIslem);
}

const ctx = document.getElementById('saatlikChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: saatler,
        datasets: [{
            label: 'İşlem Sayısı',
            data: islemler,
            backgroundColor: 'rgba(79, 70, 229, 0.6)',
            borderColor: 'rgba(79, 70, 229, 1)',
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1 }
            }
        }
    }
});

// Günü 3 bölüme ayır: 08:00-11:00, 11:00-15:00, 15:00-18:00
const gunBolumleri = [
    { label: 'Açılış', aralik: '08:00-11:00', islem: 0, tutar: 0 },
    { label: 'Öğle', aralik: '11:00-15:00', islem: 0, tutar: 0 },
    { label: 'Kapanış', aralik: '15:00-18:00', islem: 0, tutar: 0 }
];

saatlikData.forEach(d => {
    const saat = Number(d.SAAT);
    const islem = Number(d.ISLEM_SAYISI || 0);
    const tutar = Number(d.TUTAR || 0);

    if (Number.isNaN(saat) || saat < 0) return;

    if (saat >= 8 && saat < 11) {
        gunBolumleri[0].islem += islem;
        gunBolumleri[0].tutar += tutar;
    } else if (saat >= 11 && saat < 15) {
        gunBolumleri[1].islem += islem;
        gunBolumleri[1].tutar += tutar;
    } else if (saat >= 15 && saat < 18) {
        gunBolumleri[2].islem += islem;
        gunBolumleri[2].tutar += tutar;
    }
});

const enYogunBolum = gunBolumleri.reduce((max, bolum) => bolum.islem > max.islem ? bolum : max, gunBolumleri[0]);
const gunBolumOzetiEl = document.getElementById('gunBolumOzeti');

if (gunBolumOzetiEl) {
    const bolumKartlari = gunBolumleri.map(bolum => `
        <div class="day-slot">
            <div class="slot-row">
                <span class="slot-name">${bolum.label} <small>(${bolum.aralik})</small></span>
                <span class="slot-count">${bolum.islem} islem</span>
            </div>
            <div class="slot-ciro">Tutar: ${bolum.tutar.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} TL</div>
        </div>
    `).join('');

    gunBolumOzetiEl.innerHTML = `
        <div class="busy-tag">
            <strong>En yogun dilim:</strong> ${enYogunBolum.label} (${enYogunBolum.aralik}) - ${enYogunBolum.islem} islem
        </div>
        ${bolumKartlari}
    `;
}
</script>

<!-- Modal (Kullanici Detay) -->
<div id="kullaniciModal" class="m-overlay">
    <div class="m-dialog">
        <div class="m-head">
            <h3>Kullanici Islemleri</h3>
            <button id="kullaniciModalClose" class="m-close" aria-label="Kapat">&times;</button>
        </div>
        <div class="m-body" id="kullaniciModalBody">
            <!-- AJAX ile yuklenecek icerik -->
        </div>
        <div class="m-foot">
            <button id="kullaniciModalCloseBtn" class="btn">Kapat</button>
        </div>
    </div>
</div>

<script src="/tm/js/jquery-3.7.1.min.js"></script>
<script>
// Kullanici detay modali
function kullaniciDetayGoster(kullaniciId) {
    const donem = <?php echo json_encode($filtre_donem, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    const gun = <?php echo json_encode($filtre_gun_input, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    $('#kullaniciModalBody').html('<div class="empty"><i class="fas fa-spinner fa-spin"></i><p>Yukleniyor...</p></div>');
    $('#kullaniciModal').addClass('open');

    $.ajax({
        url: 'siparis/kullanici_siparisler.php',
        type: 'POST',
        data: { kullanici_id: kullaniciId, donem: donem, gun: gun },
        success: function (response) {
            $('#kullaniciModalBody').html(response);
        },
        error: function() {
            $('#kullaniciModalBody').html('<div class="empty"><i class="fas fa-exclamation-triangle" style="color:var(--red,#6F1022);"></i><p style="color:var(--red,#6F1022);">Veriler yuklenirken bir hata olustu.</p></div>');
        }
    });
}

// Modal kapatma
function closeKullaniciModal() {
    $('#kullaniciModal').removeClass('open');
}

$('#kullaniciModalClose, #kullaniciModalCloseBtn').click(closeKullaniciModal);

// Modal dışına tıklayınca kapatma
$('#kullaniciModal').click(function(event) {
    if ($(event.target).is('#kullaniciModal')) {
        closeKullaniciModal();
    }
});

// ESC tuşu ile kapatma
$(document).keyup(function(e) {
    if (e.key === "Escape") {
        closeKullaniciModal();
    }
});
</script>

</body>
</html>

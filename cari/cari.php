<?php
declare(strict_types=1);

include_once(__DIR__ . "/../log_ip.php");
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['cari']);

// Sayfa ilk açıldığında (farklı sayfadan gelince) eski aramayı temizle
// Kendi sayfasından gelen istekleri (form gönderimi, yönlendirme) temizleme
$currentPage = basename($_SERVER['PHP_SELF']);
$referer = $_SERVER['HTTP_REFERER'] ?? '';
$isFromSamePage = !empty($referer) && strpos($referer, $currentPage) !== false;

if (empty($_GET) && empty($_POST) && !$isFromSamePage) {
    clearPageParams();
}

// Cagiran sayfa isterse hedef URL'leri override edebilir (resmi modul gibi).
$cariBackUrl = (isset($cariBackUrl) && is_string($cariBackUrl) && $cariBackUrl !== '')
    ? $cariBackUrl
    : '../index.php';
$cariOrderUrl = (isset($cariOrderUrl) && is_string($cariOrderUrl) && $cariOrderUrl !== '')
    ? $cariOrderUrl
    : '../siparis/fisekle.php';

// Bu fonksiyon ayr.php'de de bulunabilir; yeniden tanimlamayi engelle.
if (!function_exists('paraformat')) {
    function paraformat(float|int|string|null $kusurat): string
    {
        global $parakusurat; // Bu değişkenin 'ayr.php' içinde tanımlı olması gerekir.
        if ($kusurat === "" || is_null($kusurat)) {
            $kusurat = 0;
        }
        $decimals = (int)($parakusurat ?? 2);
        return number_format($kusurat, $decimals, ',', '.');
    }
}

// --- GERÇEK VERİTABANI SORGUSU ---
$rows = [];
// Session bazli parametre sistemi
$raw = getPageParamString('cari');
$cari = lumen_arama_terimi($raw);
$hasSearch = ($cari !== '');
$limit = isset($carilistesayisi) ? max(1, (int) $carilistesayisi) : 100;

// Yetkiler satir bazli degil, sayfa bazli hesaplanir
$cr1Yetkisi = (m_p_yetki($terminalkullanici, 'CR1') == 1);

// Ortak tablolar (includes/veri/veri.php): LOGO'da CLCARD/GNTOTCL, bağımsızda LM_CARI/LMV_CARI_BAKIYE.
$bakiyeSecim = "CAST(0 AS DECIMAL(18,2)) AS BAKIYE";
$joinSql = "";
if ($cr1Yetkisi) {
    $bakiyeSecim = "ISNULL(G.BAKIYE, 0) AS BAKIYE";
    $joinSql = "LEFT JOIN " . lumen_tablo('cari_bakiye') . " G ON G.CARI_ID = C.ID";
}

$sql = "
  SELECT TOP {$limit}
    C.ID          AS CARIID,
    C.IL          AS SEHIR,
    C.SEMT        AS AMBAR,
    C.KOD         AS KODU,
    C.TELEFON     AS TELNRS1,
    C.EPOSTA      AS EMAILADDR,
    C.UNVAN       AS UNVANI,
    {$bakiyeSecim}
  FROM " . lumen_tablo('cari') . " C
  {$joinSql}
  WHERE C.AKTIF = 1
";
$params = [];
$ozelCariFiltresi = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'C.ID', 'cari_liste_gizli');
if ($ozelCariFiltresi['sql'] !== '') {
    $sql .= "\n  AND " . $ozelCariFiltresi['sql'];
    $params = array_merge($params, $ozelCariFiltresi['params']);
}

if ($hasSearch) {
    $like = "%{$cari}%";
    $sql .= "
    AND (
         " . lumen_arama_sql('C.UNVAN') . " LIKE :p1
      OR " . lumen_arama_sql('C.KOD') . " LIKE :p2
      OR " . lumen_arama_sql('C.IL') . " LIKE :p3
    )";
    $params = array_merge($params, [':p1' => $like, ':p2' => $like, ':p3' => $like]);
}

$sql .= "
  ORDER BY C.UNVAN ASC
";

if ($hasSearch) {
    try {
        $stmt = app_db_prepare_execute($dbh, $sql, $params, [
            'page' => 'cari.php',
            'action' => 'cari_liste',
            'has_search' => $hasSearch,
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $ref = app_log_exception($e, 'cari.php:cari_liste', [
            'search' => mb_substr((string) $raw, 0, 120),
            'limit' => $limit,
        ]);
        die('<div class="p-4 bg-red-800 text-white text-center">Veritabani hatasi olustu. Ref: ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</div>');
    }
}

// En çok işlem gören 5 müşteri — mağaza/perakende carisi (LOGICALREF = 2) hariç.
// Yalnızca arama yapılmadığında hesaplanır (boş ekran yerine hızlı erişim sağlar).
$enCokCariler = [];
if (!$hasSearch) {
    try {
        // Fatura sayısı: LOGO'da INVOICE (TRCODE 7 satış, 8 iade), bağımsızda LM_BELGE (TUR 3 fatura, 4 iade).
        $faturaSql = lumen_bagimsiz_mi()
            ? "SELECT CARI_ID, ID FROM dbo.LM_BELGE WHERE TUR IN (3, 4) AND DURUM = 1"
            : "SELECT CLIENTREF AS CARI_ID, LOGICALREF AS ID FROM {$firmadonem}INVOICE WITH(NOLOCK) WHERE CANCELLED = 0 AND TRCODE IN (7, 8)";
        $stmtTopCari = $dbh->query("
            SELECT TOP 5
                C.ID               AS CARIID,
                C.KOD              AS KODU,
                C.UNVAN            AS UNVANI,
                C.IL               AS SEHIR,
                COUNT(I.ID)        AS ISLEM_ADEDI
            FROM " . lumen_tablo('cari') . " C
            INNER JOIN ({$faturaSql}) I ON I.CARI_ID = C.ID
            WHERE C.AKTIF = 1
              AND C.ID <> 2                  -- mağaza/perakende satışı hariç
            GROUP BY C.ID, C.KOD, C.UNVAN, C.IL
            ORDER BY COUNT(I.ID) DESC
        ");
        $enCokCariler = $stmtTopCari->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log("En cok islem goren cari sorgu hatasi: " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Müşteri Listesi</title>
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <script>/* Lumen koyu temayı kendi yönetir: Tailwind'in dark: sınıfları işletim sistemi temasıyla açılmasın */try{tailwind.config=Object.assign(tailwind.config||{},{darkMode:'class'});}catch(e){}</script>
    <style>
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* ═══════ SEARCH PANEL ═══════ */
        .search-panel {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 16px;
            margin-bottom: 22px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .search-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .search-wrap {
            position: relative;
            flex: 1 1 auto;
        }
        .search-wrap i.fa-search {
            position: absolute;
            top: 50%;
            left: 14px;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 14px;
            pointer-events: none;
        }
        .search-input {
            width: 100%;
            padding: 12px 14px 12px 40px;
            font-family: var(--font);
            font-size: 14px;
            color: var(--text-1);
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            transition: all 0.2s ease;
            outline: none;
        }
        .search-input:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .btn-search {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 22px;
            background: var(--red,#ef4444);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-family: var(--font);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            white-space: nowrap;
        }
        .btn-search:hover { background: var(--red); box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25); transform: translateY(-1px); }
        .btn-search:active { transform: translateY(0); }

        /* ═══════ GRID & CARDS ═══════ */
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 18px;
        }
        .firma-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.25s ease;
        }
        .firma-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 28px rgba(239, 68, 68, 0.10);
            border-color: rgba(239, 68, 68, 0.35);
        }

        .firma-head {
            padding: 16px 18px 14px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #fff, #fffafa);
        }
        .firma-title {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 14px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.35;
        }
        .firma-title .ico {
            flex-shrink: 0;
            width: 30px; height: 30px;
            border-radius: 9px;
            background: var(--red-soft);
            color: var(--red);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
        }
        .firma-title span {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }
        .firma-sehir {
            margin-top: 8px;
            padding-left: 40px;
            font-size: 12px;
            color: var(--text-2);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .firma-sehir i { color: var(--text-3); font-size: 11px; }

        .firma-body {
            padding: 18px;
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }
        .bakiye-box {
            text-align: center;
            padding: 14px 10px;
            background: #fafafa;
            border: 1px solid var(--border);
            border-radius: 12px;
        }
        .bakiye-box .label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-2);
        }
        .bakiye-box .amount {
            margin-top: 4px;
            font-size: 22px;
            font-weight: 700;
            line-height: 1.2;
        }
        .bakiye-box.borclu .amount { color: var(--emerald); }   /* borçlu=yeşil — site geneli kural (2026-07-07) */
        .bakiye-box.alacakli .amount { color: var(--red); }
        .bakiye-box.sifir .amount { color: var(--text-2); }
        .bakiye-box.hidden-box .amount { color: var(--text-3); }
        .bakiye-box .note {
            margin-top: 4px;
            font-size: 10px;
            color: var(--text-3);
        }

        .meta-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 12.5px;
            color: var(--text-2);
        }
        .meta-list .row {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .meta-list .row .ico {
            flex-shrink: 0;
            width: 22px;
            text-align: center;
            color: var(--text-3);
            font-size: 11px;
        }
        .meta-list .row .val {
            flex: 1 1 auto;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            color: var(--text-1);
        }
        .meta-list .row .val.muted { color: var(--text-3); font-style: italic; }

        .firma-footer {
            padding: 14px 18px 18px;
        }
        .btn-order {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px 16px;
            background: var(--red,#ef4444);
            color: #fff;
            border-radius: 10px;
            font-weight: 600;
            font-size: 13.5px;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .btn-order:hover { background: var(--red); box-shadow: 0 6px 14px rgba(239, 68, 68, 0.22); transform: translateY(-1px); }
        .btn-order:active { transform: translateY(0); }

        /* ═══════ EMPTY STATE ═══════ */
        .empty-state {
            grid-column: 1 / -1;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px dashed rgba(248, 113, 113, 0.30);
            border-radius: 16px;
            padding: 40px 24px;
            text-align: center;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .empty-state .big-icon {
            width: 64px; height: 64px;
            margin: 0 auto 14px;
            border-radius: 16px;
            background: var(--red-soft);
            color: var(--red);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .empty-state .title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-1);
        }
        .empty-state .desc {
            margin-top: 4px;
            font-size: 13px;
            color: var(--text-2);
        }

        /* ═══════ ANIMATIONS ═══════ */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 767px) {

            main { padding: 14px 12px 40px !important; }

            .search-panel { padding: 12px; margin-bottom: 16px; }
            .search-form { gap: 8px; }
            .search-input { font-size: 16px; padding: 11px 14px 11px 38px; } /* iOS zoom engeli */
            .btn-search { padding: 11px 16px; font-size: 13px; }
            .btn-search span { display: none; }

            .card-grid { grid-template-columns: 1fr; gap: 14px; }
            .firma-card:hover { transform: none; }
            .firma-head { padding: 14px 16px 12px; }
            .firma-title { font-size: 13.5px; }
            .firma-body { padding: 16px; gap: 12px; }
            .bakiye-box .amount { font-size: 20px; }
            .btn-order:hover { transform: none; }
        }
        @media (max-width: 360px) {
            .search-input { padding-left: 36px; }
            .search-wrap i.fa-search { left: 12px; }
        }

        /* ═══════ EN COK ISLEM GOREN MUSTERILER ═══════ */
        .topcari-panel {
            background: #fff; border: 1px solid var(--border); border-radius: 16px;
            padding: 14px 16px; margin-bottom: 16px; box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            animation: cardIn 0.4s ease both;
        }
        .topcari-head {
            display: flex; align-items: center; gap: 8px;
            font-size: 13px; font-weight: 700; color: var(--text-1); margin-bottom: 12px;
        }
        .topcari-head i { color: #f59e0b; }
        .topcari-list {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px;
        }
        .topcari-item {
            display: flex; align-items: center; gap: 10px; padding: 12px; min-height: 44px;
            border: 1px solid var(--border); border-radius: 12px; background: #f9fafb;
            text-decoration: none; color: inherit; transition: all 0.18s ease;
        }
        .topcari-item:hover {
            border-color: rgba(239,68,68,0.4); background: #fff;
            box-shadow: 0 6px 16px rgba(239,68,68,0.08); transform: translateY(-1px);
        }
        .topcari-rank {
            width: 24px; height: 24px; border-radius: 50%; background: var(--red); color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700; flex-shrink: 0;
        }
        .topcari-info { flex: 1; min-width: 0; }
        .topcari-name { display: block; font-size: 13px; font-weight: 600; color: var(--text-1); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .topcari-sub { display: block; font-size: 11px; color: #9ca3af; }
        .topcari-count { font-size: 12px; font-weight: 700; color: var(--red); flex-shrink: 0; white-space: nowrap; }
        .topcari-count i { font-size: 11px; margin-right: 3px; }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="<?php echo htmlspecialchars($cariBackUrl, ENT_QUOTES, 'UTF-8'); ?>" class="header-back" title="Geri">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-users"></i>Müşteri Listesi
            </span>
            <?php if (m_p_yetki($terminalkullanici, 'M12') == 1): ?>
            <a href="cariyeni.php" title="Yeni Cari Ekle"
               style="margin-left:auto;display:inline-flex;align-items:center;gap:7px;padding:9px 16px;min-height:44px;background:var(--red,#ef4444);color:#fff;border-radius:10px;font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;">
                <i class="fa-solid fa-user-plus"></i><span>Yeni Cari</span>
            </a>
            <?php endif; ?>
        </div>
    </header>

    <main style="max-width:1200px;margin:0 auto;padding:22px 24px 60px;">

        <!-- Arama Paneli -->
        <div class="search-panel">
            <form method="GET" action="" class="search-form" autocomplete="off">
                <div class="search-wrap">
                    <i class="fa-solid fa-search"></i>
                    <input type="text" name="cari" id="cari" class="search-input"
                        placeholder="Firma adi, kodu veya sehir..."
                        value="<?php echo htmlspecialchars((string) $raw); ?>" autofocus>
                </div>
                <button type="submit" class="btn-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <span>Ara</span>
                </button>
            </form>
        </div>

        <!-- En Cok Islem Goren Musteriler (magaza satislari haric) -->
        <?php if (!$hasSearch && $enCokCariler !== []): ?>
        <div class="topcari-panel">
            <div class="topcari-head">
                <i class="fa-solid fa-crown"></i>
                <span>En Çok İşlem Gören Müşteriler</span>
            </div>
            <div class="topcari-list">
                <?php foreach ($enCokCariler as $tcIx => $tc): ?>
                    <a class="topcari-item"
                       href="<?php echo htmlspecialchars($cariOrderUrl, ENT_QUOTES, 'UTF-8'); ?>?cariid=<?php echo (int) $tc['CARIID']; ?>&stokhareket=0"
                       title="<?php echo htmlspecialchars(tr($tc['UNVANI'] ?? '')); ?>">
                        <span class="topcari-rank"><?php echo $tcIx + 1; ?></span>
                        <span class="topcari-info">
                            <span class="topcari-name"><?php echo htmlspecialchars(tr($tc['UNVANI'] ?? '')); ?></span>
                            <?php if (($tc['SEHIR'] ?? '') !== ''): ?>
                                <span class="topcari-sub"><i class="fa-solid fa-location-dot" style="font-size:9px;"></i> <?php echo htmlspecialchars(tr($tc['SEHIR'])); ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="topcari-count"><i class="fa-solid fa-file-invoice"></i><?php echo (int) $tc['ISLEM_ADEDI']; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Firma Kartlari Listesi -->
        <div class="card-grid">
            <?php if (empty($rows)): ?>
                <div class="empty-state">
                    <?php if ($hasSearch): ?>
                        <div class="big-icon"><i class="fa-solid fa-users-slash"></i></div>
                        <div class="title">Musteri Bulunamadi</div>
                        <div class="desc">Arama kriterlerinize uyan bir kayit bulunamadi.</div>
                    <?php else: ?>
                        <div class="big-icon" style="background:#f3f4f6;color:#9ca3af;"><i class="fa-solid fa-magnifying-glass"></i></div>
                        <div class="title">Arama Yapınız</div>
                        <div class="desc">Musterileri listelemek icin yukaridaki alana bir arama terimi girin.</div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php $cardIndex = 0; foreach ($rows as $rowx):
                    $bakiye = (float) $rowx['BAKIYE'];

                    $unvan = $rowx['UNVANI'] ?? '';
                    $unvanText = tr($unvan);
                    $sehirText = tr($rowx['SEHIR'] ?? '');

                    // Bakiye gizleme: CR1 yoksa gizli. Kisitli cariler zaten
                    // m_p_ozel_cari_sql_filtresi() ile listeden elenmis olur.
                    $hideBalance = !$cr1Yetkisi;

                    if ($hideBalance) {
                        $bakiyeClass = 'hidden-box';
                        $bakiyeDurum = '';
                    } elseif ($bakiye > 0) {
                        $bakiyeClass = 'borclu';
                        $bakiyeDurum = 'Borclu';
                    } elseif ($bakiye < 0) {
                        $bakiyeClass = 'alacakli';
                        $bakiyeDurum = 'Alacakli';
                    } else {
                        $bakiyeClass = 'sifir';
                        $bakiyeDurum = '';
                    }
                    $delay = min($cardIndex * 40, 280);
                    $cardIndex++;
                    ?>
                    <article class="firma-card" style="animation-delay: <?php echo $delay; ?>ms;">
                        <div class="firma-head">
                            <div class="firma-title">
                                <span class="ico"><i class="fa-regular fa-building"></i></span>
                                <span title="<?php echo htmlspecialchars($unvanText); ?>"><?php echo htmlspecialchars($unvanText); ?></span>
                            </div>
                            <?php if ($sehirText !== ''): ?>
                            <div class="firma-sehir">
                                <i class="fa-solid fa-location-dot"></i>
                                <?php echo htmlspecialchars($sehirText); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="firma-body">
                            <div class="bakiye-box <?php echo $bakiyeClass; ?>">
                                <?php if ($hideBalance): ?>
                                    <div class="label">Bakiye</div>
                                    <div class="amount">—</div>
                                    <div class="note">Bu cari icin bakiye gosterilmiyor.</div>
                                <?php else: ?>
                                    <div class="label">Bakiye<?php echo $bakiyeDurum !== '' ? ' ('.$bakiyeDurum.')' : ''; ?></div>
                                    <div class="amount"><?php echo paraformat(abs($bakiye)); ?> &#8378;</div>
                                <?php endif; ?>
                            </div>

                            <div class="meta-list">
                                <div class="row">
                                    <span class="ico"><i class="fa-solid fa-hashtag"></i></span>
                                    <span class="val<?php echo trim((string)$rowx['KODU']) === '' ? ' muted' : ''; ?>">
                                        <?php echo trim((string)$rowx['KODU']) === '' ? '-' : htmlspecialchars((string) $rowx['KODU']); ?>
                                    </span>
                                </div>
                                <div class="row">
                                    <span class="ico"><i class="fa-solid fa-phone"></i></span>
                                    <span class="val<?php echo trim((string)$rowx['TELNRS1']) === '' ? ' muted' : ''; ?>">
                                        <?php echo trim((string)$rowx['TELNRS1']) === '' ? '-' : htmlspecialchars((string) $rowx['TELNRS1']); ?>
                                    </span>
                                </div>
                                <div class="row">
                                    <span class="ico"><i class="fa-solid fa-envelope"></i></span>
                                    <span class="val<?php echo trim((string)$rowx['EMAILADDR']) === '' ? ' muted' : ''; ?>">
                                        <?php echo trim((string)$rowx['EMAILADDR']) === '' ? '-' : htmlspecialchars((string) $rowx['EMAILADDR']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="firma-footer">
                            <a href="<?php echo htmlspecialchars($cariOrderUrl, ENT_QUOTES, 'UTF-8'); ?>?cariid=<?php echo (int) $rowx['CARIID']; ?>&stokhareket=0" class="btn-order">
                                <i class="fa-solid fa-pen-to-square"></i> Siparis Yaz
                            </a>
                            <?php if (lumen_modul_hazir_mi('kart')): ?>
                            <a href="../kart/cari.php?id=<?php echo (int) $rowx['CARIID']; ?>" class="btn-order" title="Cari kartı">
                                <i class="fa-solid fa-address-card"></i> Kart
                            </a>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>

    <script>
        // Animation render bug fix: force reflow
        window.addEventListener('load', function(){
            requestAnimationFrame(function(){
                document.querySelectorAll('.firma-card, .search-panel, .empty-state').forEach(function(el){ void el.offsetHeight; });
            });
        });
    </script>
</body>
</html>

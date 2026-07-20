<?php
declare(strict_types=1);

/**
 * Döviz Modülü - Ana Sayfa
 * Dövizli sipariş işlemleri için dashboard
 */

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
require_once __DIR__ . '/doviz_guard.php';

doviz_require_m21($terminalkullanici);

// Döviz tipleri (_bilgi_.inc'den)
$dovizListesi = [];
if (!empty($doviztipleri)) {
    $dovizListesi = array_map('trim', explode(',', (string) $doviztipleri));
}
$dovizSubtitle = !empty($dovizListesi) ? implode(' · ', $dovizListesi) : 'USD · EUR';

// Menu tanimlari
$menuItems = [
    ['cari.php',       'fa-circle-plus',  'emerald', 'Yeni Dovizli Siparis', 'USD, EUR veya diger doviz cinslerinde yeni siparis olustur'],
    ['siparisler.php', 'fa-list-check',   'sky',     'Dovizli Siparisler',   'Tum dovizli siparisleri listele, filtrele ve yonet'],
];

// Guncel kurlar (LOGO L_DAILYEXCHANGES)
$kurlar = [];
try {
    $stmtUSD = $dbh->prepare("SELECT TOP 1 RATES2 AS SATIS FROM L_DAILYEXCHANGES WHERE CRTYPE = 1 ORDER BY LREF DESC");
    $stmtUSD->execute();
    $kurUSD = $stmtUSD->fetch(PDO::FETCH_ASSOC);
    if ($kurUSD) {
        $kurlar[] = ['CRTYPE' => 1, 'SATIS' => $kurUSD['SATIS'], 'sembol' => '$',  'kod' => 'USD'];
    }

    $stmtEUR = $dbh->prepare("SELECT TOP 1 RATES2 AS SATIS FROM L_DAILYEXCHANGES WHERE CRTYPE = 20 ORDER BY LREF DESC");
    $stmtEUR->execute();
    $kurEUR = $stmtEUR->fetch(PDO::FETCH_ASSOC);
    if ($kurEUR) {
        $kurlar[] = ['CRTYPE' => 20, 'SATIS' => $kurEUR['SATIS'], 'sembol' => '€', 'kod' => 'EUR'];
    }
} catch (Exception $e) {
    $kurlar = [];
}

$desteklenenDovizler = [
    ['USD', '$', 'Amerikan Dolari'],
    ['EUR', '€', 'Euro'],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Doviz Islemleri</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
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
            --sky: var(--red,#6F1022);
            --sky-soft: #fef2f2;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
            --rose: #e11d48;
            --rose-soft: #fff1f2;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* HEADER (RED tone, ana sistemle uyumlu) */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.22);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.05);
        }
        .header-inner {
            max-width: 980px; margin: 0 auto;
            height: 100%; display: flex; align-items: center; gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(111,16,34,0.08); color: var(--sky); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            font-size: 18px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .header-title i { color: var(--sky); font-size: 16px; }
        .header-sub {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-3);
            margin-left: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* HERO CARD */
        .hero-card {
            background: linear-gradient(135deg, rgba(254, 242, 242, 0.95), rgba(255,255,255,0.92));
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.22);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(111, 16, 34, 0.06);
            padding: 20px 22px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 16px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
            flex-wrap: wrap;
        }
        .hero-card .hero-ico {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .hero-card .hero-text { flex: 1; min-width: 180px; }
        .hero-card .hero-text h1 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
        }
        .hero-card .hero-text p {
            margin-top: 4px;
            font-size: 12px;
            color: var(--text-2);
        }
        .currency-chips {
            display: flex; gap: 6px; flex-wrap: wrap;
        }
        .cur-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 12px;
            border-radius: 999px;
            font-size: 12px; font-weight: 600;
            background: rgba(255,255,255,0.85);
            border: 1px solid rgba(248, 113, 113, 0.25);
            color: var(--sky);
        }
        .cur-chip .cur-sym { font-size: 14px; font-weight: 700; }

        /* STAT ROW */
        .stat-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: rgba(255,255,255,0.92);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 14px 16px;
            display: flex; align-items: center; gap: 12px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .stat-ico {
            width: 40px; height: 40px; border-radius: 10px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px; flex-shrink: 0;
        }
        .stat-ico.sky     { background: var(--sky-soft);     color: var(--sky); }
        .stat-ico.emerald { background: var(--emerald-soft); color: var(--emerald); }
        .stat-ico.amber   { background: var(--amber-soft);   color: var(--amber); }
        .stat-label { font-size: 11px; color: var(--text-2); font-weight: 500; }
        .stat-value { font-size: 16px; color: var(--text-1); font-weight: 700; line-height: 1.2; margin-top: 2px; }

        /* MENU GRID */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .setting-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            padding: 22px 18px 18px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            text-decoration: none;
            color: inherit;
            position: relative;
            overflow: hidden;
            transition: all 0.25s ease;
            will-change: transform, opacity;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
            min-height: 170px;
        }
        .setting-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            opacity: 0;
            transition: opacity 0.25s ease;
        }
        .setting-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 14px 30px rgba(111, 16, 34, 0.1);
        }
        .setting-card:hover::before { opacity: 1; }
        .setting-card:active { transform: translateY(-1px); }

        .setting-ico {
            width: 56px; height: 56px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
            margin-bottom: 14px;
            transition: all 0.25s ease;
        }
        .setting-card:hover .setting-ico { transform: scale(1.08) rotate(-3deg); }

        .tone-sky     .setting-ico { background: var(--sky-soft);     color: var(--sky); }
        .tone-sky::before           { background: var(--sky); }
        .tone-sky:hover             { border-color: rgba(111, 16, 34, 0.35); }
        .tone-sky:hover .setting-title { color: var(--sky); }

        .tone-emerald .setting-ico { background: var(--emerald-soft); color: var(--emerald); }
        .tone-emerald::before       { background: var(--emerald); }
        .tone-emerald:hover         { border-color: rgba(5, 150, 105, 0.35); }
        .tone-emerald:hover .setting-title { color: var(--emerald); }

        .setting-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.3;
            margin-bottom: 6px;
            transition: color 0.2s ease;
        }
        .setting-desc {
            font-size: 11.5px;
            color: var(--text-2);
            line-height: 1.5;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }

        /* SUPPORTED CURRENCIES */
        .glass-panel {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            padding: 18px 20px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .panel-title {
            font-size: 13px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
            margin-bottom: 12px;
        }
        .panel-title i { color: var(--amber); font-size: 14px; }
        .cur-grid { display: flex; flex-wrap: wrap; gap: 10px; }
        .cur-pill {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 10px 14px;
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border: 1px solid var(--border);
            border-radius: 12px;
            min-height: 44px;
        }
        .cur-pill .sym {
            font-size: 20px; font-weight: 700; color: var(--sky);
            width: 28px; text-align: center;
        }
        .cur-pill .info { line-height: 1.2; }
        .cur-pill .code { font-size: 13px; font-weight: 700; color: var(--text-1); }
        .cur-pill .name { font-size: 10.5px; color: var(--text-3); margin-top: 2px; }

        /* ANIMATIONS */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* MOBILE */
        @media (max-width: 900px) {
            .stat-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            .header-sub { display: none; }

            main { padding: 14px 12px 40px !important; }

            .hero-card { padding: 16px; gap: 12px; margin-bottom: 14px; }
            .hero-card .hero-ico { width: 46px; height: 46px; font-size: 18px; }
            .hero-card .hero-text h1 { font-size: 15px; }
            .hero-card .hero-text p { font-size: 11px; }

            .stat-row { grid-template-columns: 1fr; gap: 10px; margin-bottom: 14px; }
            .stat-card { padding: 12px 14px; }

            .settings-grid { gap: 10px; grid-template-columns: 1fr; }
            .setting-card {
                padding: 16px;
                min-height: auto;
                border-radius: 14px;
                flex-direction: row;
                align-items: center;
                text-align: left;
                gap: 14px;
            }
            .setting-card:hover { transform: none; }
            .setting-card:hover .setting-ico { transform: none; }
            .setting-ico {
                width: 48px; height: 48px; font-size: 18px;
                margin-bottom: 0;
                border-radius: 12px;
            }
            .setting-body { flex: 1; min-width: 0; }
            .setting-title { font-size: 13.5px; margin-bottom: 4px; }
            .setting-desc { font-size: 11px; -webkit-line-clamp: 2; }

            .glass-panel { padding: 14px 16px; }
            .cur-pill { padding: 8px 12px; }
        }

        /* Touch-friendly inputs */
        input, select, textarea { font-size: 16px; }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="../index.php" class="header-back" title="Ana Sayfa">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-globe"></i>Doviz Islemleri
            </span>
            <span class="header-sub"><?php echo htmlspecialchars($dovizSubtitle, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </header>

    <main style="max-width:980px;margin:0 auto;padding:22px 24px 60px;">

        <!-- Hero card -->
        <div class="hero-card">
            <span class="hero-ico"><i class="fa-solid fa-dollar-sign"></i></span>
            <div class="hero-text">
                <h1>Dovizli Siparis Merkezi</h1>
                <p>USD, EUR ve diger doviz cinslerinde siparis olusturun, listeyin ve yonetin.</p>
            </div>
            <div class="currency-chips">
                <?php foreach ($desteklenenDovizler as $d): ?>
                    <span class="cur-chip"><span class="cur-sym"><?php echo $d[1]; ?></span><?php echo $d[0]; ?></span>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Stats row (kurlar + aktif tip sayisi) -->
        <div class="stat-row">
            <div class="stat-card" style="animation-delay: 0ms;">
                <span class="stat-ico amber"><i class="fa-solid fa-coins"></i></span>
                <div>
                    <div class="stat-label">Aktif Doviz Tipi</div>
                    <div class="stat-value"><?php echo count($desteklenenDovizler); ?> tip</div>
                </div>
            </div>
            <?php
            $delay = 60;
            foreach ($kurlar as $k):
            ?>
            <div class="stat-card" style="animation-delay: <?php echo $delay; ?>ms;">
                <span class="stat-ico <?php echo $k['CRTYPE'] == 1 ? 'emerald' : 'sky'; ?>">
                    <span style="font-weight:700;font-size:18px;"><?php echo $k['sembol']; ?></span>
                </span>
                <div>
                    <div class="stat-label"><?php echo $k['kod']; ?> Satis Kuru</div>
                    <div class="stat-value"><?php echo number_format((float) $k['SATIS'], 4, ',', '.'); ?> TL</div>
                </div>
            </div>
            <?php
                $delay += 60;
            endforeach;
            ?>
            <?php if (empty($kurlar)): ?>
            <div class="stat-card" style="animation-delay: 60ms;">
                <span class="stat-ico sky"><i class="fa-solid fa-chart-line"></i></span>
                <div>
                    <div class="stat-label">Guncel Kurlar</div>
                    <div class="stat-value" style="font-size:12px;color:var(--text-3);font-weight:500;">Kur bilgisi yok</div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Menu Grid -->
        <div class="settings-grid">
            <?php $delay = 120; foreach ($menuItems as $item):
                [$href, $icon, $tone, $title, $desc] = $item;
            ?>
                <a href="<?php echo htmlspecialchars((string) $href, ENT_QUOTES, 'UTF-8'); ?>"
                   class="setting-card tone-<?php echo $tone; ?>"
                   style="animation-delay: <?php echo min($delay, 280); ?>ms;">
                    <span class="setting-ico"><i class="fa-solid <?php echo $icon; ?>"></i></span>
                    <div class="setting-body">
                        <div class="setting-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="setting-desc"><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </a>
            <?php $delay += 60; endforeach; ?>
        </div>

        <!-- Supported currencies panel -->
        <div class="glass-panel" style="animation-delay: 240ms;">
            <div class="panel-title">
                <i class="fa-solid fa-coins"></i>Desteklenen Doviz Tipleri
            </div>
            <div class="cur-grid">
                <?php foreach ($desteklenenDovizler as $bilgi): ?>
                <div class="cur-pill">
                    <span class="sym"><?php echo $bilgi[1]; ?></span>
                    <div class="info">
                        <div class="code"><?php echo $bilgi[0]; ?></div>
                        <div class="name"><?php echo htmlspecialchars($bilgi[2], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>

    <script>
        // RAF reflow fix icin animasyon
        window.addEventListener('load', function(){
            requestAnimationFrame(function(){
                document.querySelectorAll('.setting-card, .hero-card, .stat-card, .glass-panel').forEach(function(el){ void el.offsetHeight; });
            });
        });
    </script>

    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
</body>
</html>

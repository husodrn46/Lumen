<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Kart tonlari kategorik ayrim icin (red, emerald, indigo, amber, sky, purple);
// genel krom Lumen bordosi. Ikonlar Font Awesome 6 (fa-solid).
$report_groups = [
    ['name' => 'Finans ve Cari Hesaplar', 'icon' => 'fa-landmark', 'reports' => [
        ['href' => 'rapor_kasa.php', 'title' => 'Kasa Paneli', 'desc' => 'Kasa ve döviz hesaplarının anlık bakiyeleri, TL / döviz ayrımı.', 'icon' => 'fa-cash-register', 'tone' => 'emerald'],
        ['href' => 'rapor_cari_borc_alacak.php', 'title' => 'Tahsilat Radarı', 'desc' => 'Borçlu ligi: büyüklük, son alım yaşı ve Pareto payı.', 'icon' => 'fa-satellite-dish', 'tone' => 'red'],
        ['href' => 'rapor_cari_yaslandirma.php', 'title' => 'Cari Yaşlandırma', 'desc' => 'Açık borcun yaş dilimlerine dağılımı (0-30, 31-60, 61-90, 90+).', 'icon' => 'fa-hourglass-half', 'tone' => 'amber'],
        ['href' => 'rapor_musteri_odeme_hizi.php', 'title' => 'Müşteri Ödeme Hızı', 'desc' => 'Müşteri bazlı son fişler, tahsilatlar ve ödeme hızı liderlikleri.', 'icon' => 'fa-gauge-high', 'tone' => 'indigo'],
        ['href' => 'rapor_cekler.php', 'title' => 'Çekler — Nakit Akışı', 'desc' => 'Vade zaman çizelgesi: gecikmiş, bugün, bu hafta, bu ay.', 'icon' => 'fa-money-bill-wave', 'tone' => 'indigo'],
        ['href' => 'rapor_cek_hesap.php', 'title' => 'Çek Hesap (Detay)', 'desc' => 'Alınan çeklerin durum bazlı (portföy / ciro / tahsil) dökümü.', 'icon' => 'fa-file-invoice', 'tone' => 'sky'],
        ['href' => 'rapor_finans_kartlar.php', 'title' => 'Kasa Matrisi', 'desc' => 'Ay × kasa tahsilat girişleri matrisi ve kasa karneleri.', 'icon' => 'fa-table-cells', 'tone' => 'purple'],
    ]],
    ['name' => 'Satış Raporları', 'icon' => 'fa-cart-shopping', 'reports' => [
        ['href' => 'rapor_satis_aylik.php', 'title' => 'Aylık Satış', 'desc' => 'Ay ay net ciro (iade düşülmüş), geçen yıl kıyası ve değişim.', 'icon' => 'fa-calendar-days', 'tone' => 'emerald'],
        ['href' => 'rapor_musteriler_top.php', 'title' => 'Müşteri Ligi', 'desc' => 'Net ciroya göre müşteri sıralaması: podyum ve Pareto.', 'icon' => 'fa-medal', 'tone' => 'amber'],
        ['href' => 'rapor_pazarlamaci_performans.php', 'title' => 'Pazarlamacı Performans', 'desc' => 'Pazarlamacı bazlı satış, tahsilat ve devir hariç bakiye.', 'icon' => 'fa-user-tie', 'tone' => 'indigo'],
        ['href' => 'rapor_yillik_ozet.php', 'title' => 'Yıllık Özet', 'desc' => 'Yılın karnesi: KPI, çeyrekler, top ürün ve müşteriler.', 'icon' => 'fa-chart-line', 'tone' => 'red'],
    ]],
    ['name' => 'Ürün ve Stok Yönetimi', 'icon' => 'fa-boxes-stacked', 'reports' => [
        ['href' => 'rapor_stok_izleme.php', 'title' => 'Stok İzleme', 'desc' => 'Eriyen / negatife düşen stoklar + tahmini tükenme günü.', 'icon' => 'fa-arrow-trend-down', 'tone' => 'red'],
        ['href' => 'rapor_urun_performans.php', 'title' => 'Ürün Ligi', 'desc' => 'Net satış ligi: podyum, Pareto ve canlı sıralama.', 'icon' => 'fa-ranking-star', 'tone' => 'purple'],
        ['href' => 'rapor_urun_top20.php', 'title' => 'En İyi 20 Ürün', 'desc' => 'Son 30 günün ciro şampiyonu 20 ürün.', 'icon' => 'fa-trophy', 'tone' => 'amber'],
    ]],
];

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Raporlar</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f8f6f7;
            --surface: #ffffff;
            --text-1: #1c1220;
            --text-2: #4b5563;
            --text-3: #6b7280;
            --border: #ebe4ea;
            --red: #6F1022;
            --red-orta: #b91c1c;
            --red-koyu: #7f1d1d;
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
            color: var(--text-1);
            min-height: 100vh;
            background:
                radial-gradient(820px 280px at 100% -70px, rgba(111,16,34,.06), transparent 60%),
                radial-gradient(560px 220px at -60px 25%, rgba(245,158,11,.05), transparent 55%),
                var(--bg);
        }

        /* Sticky header — Lumen bordo krom */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.86);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            display: flex; align-items: center; gap: 12px;
            padding: 12px 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
            color: var(--text-2); text-decoration: none;
            background: #fff; border: 1px solid var(--border);
            transition: all 0.18s ease;
        }
        .header-back:hover { color: var(--red); border-color: var(--red); transform: translateX(-2px); }
        .t-ico {
            width: 40px; height: 40px; border-radius: 12px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 17px; color: #fff;
            background: linear-gradient(135deg, var(--red-orta), var(--red-koyu));
            box-shadow: 0 6px 14px -6px rgba(185, 28, 28, 0.5);
        }
        .t-baslik h1 { font-size: 16px; font-weight: 700; line-height: 1.2; }
        .t-baslik p { font-size: 11.5px; color: var(--text-2); }
        .header-user {
            margin-left: auto;
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 12px; font-weight: 600; color: var(--text-2);
            background: #fff; border: 1px solid var(--border);
            border-radius: 99px; padding: 7px 13px;
        }
        .header-user i { font-size: 12px; color: var(--red); }

        main {
            max-width: 1200px; margin: 0 auto;
            padding: 22px 24px 60px;
        }

        /* Group section */
        .group-block { margin-bottom: 26px; }
        .group-head {
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 14px;
        }
        .group-head i { color: var(--red); font-size: 15px; }
        .group-head h2 {
            font-size: 13px; font-weight: 700;
            color: #475569;
            text-transform: uppercase; letter-spacing: 0.8px;
        }
        .group-count {
            font-size: 10px; font-weight: 800; color: var(--text-3);
            background: #fff; border: 1px solid var(--border);
            border-radius: 99px; padding: 2px 9px;
        }
        .group-divider {
            flex: 1; height: 1px; background: var(--border);
        }

        /* Tile grid */
        .tile-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .tile-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 16px;
            box-shadow: 0 12px 30px -20px rgba(28, 18, 32, 0.35);
            padding: 20px 18px 18px;
            display: flex; flex-direction: column;
            text-decoration: none; color: inherit;
            position: relative; overflow: hidden;
            transition: all 0.25s ease;
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            min-height: 130px;
        }
        .tile-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; right: 0; height: 3px;
            opacity: 0; transition: opacity 0.25s ease;
        }
        .tile-card:hover { transform: translateY(-3px); box-shadow: 0 16px 32px -18px rgba(127, 29, 29, 0.28); }
        .tile-card:hover::before { opacity: 1; }
        .tile-card:active { transform: translateY(-1px); }

        .tile-head {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 10px;
        }
        .tile-ico {
            width: 44px; height: 44px; border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            transition: all 0.25s ease;
        }
        .tile-ico i { font-size: 19px; }
        .tile-card:hover .tile-ico { transform: scale(1.08) rotate(-3deg); }

        .tile-title {
            font-size: 14px; font-weight: 700; color: var(--text-1);
            line-height: 1.3;
            transition: color 0.2s ease;
        }
        .tile-desc {
            font-size: 11.5px; color: #526071;
            line-height: 1.5;
            overflow: hidden; text-overflow: ellipsis;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
        }

        /* Tone variants */
        .tone-red     .tile-ico { background: var(--red-soft);     color: var(--red); }
        .tone-red::before        { background: var(--red); }
        .tone-red:hover          { border-color: rgba(111, 16, 34, 0.35); }
        .tone-red:hover .tile-title { color: var(--red); }

        .tone-emerald .tile-ico { background: var(--emerald-soft); color: var(--emerald); }
        .tone-emerald::before    { background: var(--emerald); }
        .tone-emerald:hover      { border-color: rgba(5, 150, 105, 0.35); }
        .tone-emerald:hover .tile-title { color: var(--emerald); }

        .tone-indigo  .tile-ico { background: var(--indigo-soft);  color: var(--indigo); }
        .tone-indigo::before     { background: var(--indigo); }
        .tone-indigo:hover       { border-color: rgba(79, 70, 229, 0.35); }
        .tone-indigo:hover .tile-title { color: var(--indigo); }

        .tone-amber   .tile-ico { background: var(--amber-soft);   color: var(--amber); }
        .tone-amber::before      { background: var(--amber); }
        .tone-amber:hover        { border-color: rgba(217, 119, 6, 0.35); }
        .tone-amber:hover .tile-title { color: var(--amber); }

        .tone-sky     .tile-ico { background: var(--sky-soft);     color: var(--sky); }
        .tone-sky::before        { background: var(--sky); }
        .tone-sky:hover          { border-color: rgba(2, 132, 199, 0.35); }
        .tone-sky:hover .tile-title { color: var(--sky); }

        .tone-purple  .tile-ico { background: var(--purple-soft);  color: var(--purple); }
        .tone-purple::before     { background: var(--purple); }
        .tone-purple:hover       { border-color: rgba(124, 58, 237, 0.35); }
        .tone-purple:hover .tile-title { color: var(--purple); }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* Responsive */
        @media (max-width: 900px) {
            .tile-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 9px 12px; gap: 9px; }
            .t-baslik h1 { font-size: 14.5px; }
            .t-baslik p { font-size: 10.5px; }
            .t-ico { width: 34px; height: 34px; border-radius: 10px; font-size: 14px; }
            .header-back { width: 32px; height: 32px; border-radius: 9px; }
            .header-user { padding: 6px 10px; }
            .header-user span { display: none; }

            main { padding: 14px 12px 40px; }

            .group-block { margin-bottom: 20px; }
            .group-head h2 { font-size: 12px; }

            .tile-grid { gap: 10px; }
            .tile-card {
                padding: 14px 12px;
                min-height: auto;
                border-radius: 12px;
            }
            .tile-card:hover { transform: none; }
            .tile-card:hover .tile-ico { transform: none; }
            .tile-head { gap: 10px; margin-bottom: 6px; }
            .tile-ico { width: 36px; height: 36px; border-radius: 10px; }
            .tile-ico i { font-size: 16px; }
            .tile-title { font-size: 12.5px; }
            .tile-desc { font-size: 11px; -webkit-line-clamp: 2; }
        }

        /* Touch targets */
        a { min-height: 44px; }
        .header-back { min-height: 0; }

        /* iOS zoom fix */
        input, select, textarea { font-size: 16px; }

        /* Print */
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; }
            .tile-card { box-shadow: none !important; border: 1px solid #e5e7eb !important; }
        }
    </style>
</head>
<body>

<header class="top-header no-print">
    <div class="header-inner">
        <a href="<?php echo APP_ROOT_URL; ?>/index.php" class="header-back" title="Ana Sayfa">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <span class="t-ico"><i class="fa-solid fa-chart-pie"></i></span>
        <div class="t-baslik">
            <h1>Raporlar</h1>
            <p>Finans · satış · stok · KDV</p>
        </div>
        <span class="header-user">
            <i class="fa-solid fa-user"></i>
            <span><?php echo htmlspecialchars((string) ($_SESSION['kullanici_adi'] ?? $terminalkullanici), ENT_QUOTES, 'UTF-8'); ?></span>
        </span>
    </div>
</header>

<main>
    <?php foreach ($report_groups as $group): ?>
    <div class="group-block">
        <div class="group-head">
            <i class="fa-solid <?php echo htmlspecialchars($group['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
            <h2><?php echo htmlspecialchars($group['name'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <span class="group-count"><?php echo count($group['reports']); ?></span>
            <div class="group-divider"></div>
        </div>

        <div class="tile-grid">
            <?php $delay = 0; foreach ($group['reports'] as $report): ?>
                <a href="<?php echo htmlspecialchars($report['href'], ENT_QUOTES, 'UTF-8'); ?>"
                   class="tile-card tone-<?php echo htmlspecialchars($report['tone'], ENT_QUOTES, 'UTF-8'); ?>"
                   style="animation-delay: <?php echo min($delay, 280); ?>ms;">
                    <div class="tile-head">
                        <span class="tile-ico">
                            <i class="fa-solid <?php echo htmlspecialchars($report['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                        </span>
                        <div class="tile-title"><?php echo htmlspecialchars($report['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                    <div class="tile-desc"><?php echo htmlspecialchars($report['desc'], ENT_QUOTES, 'UTF-8'); ?></div>
                </a>
            <?php $delay += 40; endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</main>

<script>
    window.addEventListener('load', function(){
        requestAnimationFrame(function(){
            document.querySelectorAll('.tile-card').forEach(function(el){ void el.offsetHeight; });
        });
    });
</script>

</body>
</html>

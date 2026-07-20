<?php
declare(strict_types=1);

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);
?>
<?php
// Menu tanimlari — ikon + renk + baslik + aciklama
$menuItems = [
    ['yetki_yonetimi.php',     'fa-user-shield',       'red',     'Yetki Yonetimi',       'Kullanici yonetimi, izin matrisi ve yetki denetimi'],
    ['guvenlik.php',           'fa-shield-halved',     'rose',    'Guvenlik',             'Hesap kilidi acma ve acil saldiri modu'],
    ['sistem_ayarlari.php',    'fa-gear',              'sky',     'Sistem Ayarlari',      'Genel sistem yapilandirmalari ve parametreler'],
    ['ozel_cari_kisitlari.php','fa-user-lock',         'amber',   'Ozel Cari Kisitlari',  'Kullanici bazli cari yasak kurallari ve test araci'],
    ['hizli_iskonto.php',      'fa-percent',           'emerald', 'Hizli Iskonto',        'Siparis ekrani iskonto kisayollari (etiket + oran)'],
    ['marka.php',              'fa-image',             'purple',  'Marka / Logo',         'Kendi logonuzu ve uygulama ikonunuzu yukleyin'],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yonetim Paneli</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
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
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --rose: #e11d48;
            --rose-soft: #fff1f2;
            --pink: #db2777;
            --pink-soft: #fdf2f8;
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
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
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
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            font-size: 18px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 8px;
        }
        .header-title i { color: var(--red,#ef4444); font-size: 16px; }
        .header-sub {
            font-size: 11px;
            font-weight: 500;
            color: var(--text-3);
            margin-left: 4px;
        }

        /* ═══════ HERO CARD ═══════ */
        .hero-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 20px 22px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .hero-card .hero-ico {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: var(--red);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
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

        /* ═══════ SETTINGS GRID (tile layout) ═══════ */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
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
            border-color: rgba(239, 68, 68, 0.35);
            box-shadow: 0 14px 30px rgba(239, 68, 68, 0.1);
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
        .setting-card:hover .setting-ico {
            transform: scale(1.08) rotate(-3deg);
        }

        /* Tone varyantlari */
        .tone-red    .setting-ico { background: var(--red-soft);    color: var(--red); }
        .tone-red::before { background: var(--red); }
        .tone-sky    .setting-ico { background: var(--sky-soft);    color: var(--sky); }
        .tone-sky::before { background: var(--sky); }
        .tone-purple .setting-ico { background: var(--purple-soft); color: var(--purple); }
        .tone-purple::before { background: var(--purple); }
        .tone-amber  .setting-ico { background: var(--amber-soft);  color: var(--amber); }
        .tone-amber::before { background: var(--amber); }
        .tone-rose   .setting-ico { background: var(--rose-soft);   color: var(--rose); }
        .tone-rose::before { background: var(--rose); }
        .tone-indigo .setting-ico { background: var(--indigo-soft); color: var(--indigo); }
        .tone-indigo::before { background: var(--indigo); }
        .tone-pink   .setting-ico { background: var(--pink-soft);   color: var(--pink); }
        .tone-pink::before { background: var(--pink); }
        .tone-emerald .setting-ico { background: var(--emerald-soft); color: var(--emerald); }
        .tone-emerald::before { background: var(--emerald); }

        .setting-title {
            font-size: 13.5px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.3;
            margin-bottom: 6px;
            transition: color 0.2s ease;
        }
        .setting-card:hover .setting-title { color: var(--red); }
        .tone-sky:hover .setting-title    { color: var(--sky); }
        .tone-purple:hover .setting-title { color: var(--purple); }
        .tone-amber:hover .setting-title  { color: var(--amber); }
        .tone-rose:hover .setting-title   { color: var(--rose); }
        .tone-indigo:hover .setting-title { color: var(--indigo); }
        .tone-pink:hover .setting-title   { color: var(--pink); }
        .tone-emerald:hover .setting-title { color: var(--emerald); }

        .setting-desc {
            font-size: 11px;
            color: var(--text-2);
            line-height: 1.5;
            overflow: hidden;
            text-overflow: ellipsis;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }

        /* ═══════ ANIMATIONS ═══════ */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 900px) {
            .settings-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }

            main { padding: 14px 12px 40px !important; }

            .hero-card { padding: 16px; gap: 12px; margin-bottom: 16px; }
            .hero-card .hero-ico { width: 46px; height: 46px; font-size: 18px; }
            .hero-card .hero-text h1 { font-size: 15px; }
            .hero-card .hero-text p { font-size: 11px; }

            .settings-grid { gap: 10px; }
            .setting-card {
                padding: 16px 12px 14px;
                min-height: 140px;
                border-radius: 14px;
            }
            .setting-card:hover { transform: none; }
            .setting-card:hover .setting-ico { transform: none; }
            .setting-ico {
                width: 44px; height: 44px; font-size: 17px; margin-bottom: 10px;
                border-radius: 11px;
            }
            .setting-title { font-size: 12px; margin-bottom: 4px; }
            .setting-desc { font-size: 10px; -webkit-line-clamp: 2; }
        }
        @media (max-width: 380px) {
            .settings-grid { grid-template-columns: 1fr; }
            .setting-card { min-height: auto; padding: 14px 16px; flex-direction: row; align-items: center; text-align: left; gap: 14px; }
            .setting-ico { margin-bottom: 0; }
            .setting-card .setting-body { flex: 1; }
        }
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
                <i class="fa-solid fa-sliders"></i>Yonetim Paneli
            </span>
        </div>
    </header>

    <main style="max-width:980px;margin:0 auto;padding:22px 24px 60px;">

        <!-- Hero card -->
        <div class="hero-card">
            <span class="hero-ico"><i class="fa-solid fa-user-shield"></i></span>
            <div class="hero-text">
                <h1>Ayarlar ve Yonetim Araclari</h1>
                <p>Kullanici yetkileri, sistem parametreleri, tasarim ve saglik izleme araclari</p>
            </div>
        </div>

        <!-- Ayarlar Grid -->
        <div class="settings-grid">
            <?php $delay = 0; foreach ($menuItems as $item):
                [$href, $icon, $tone, $title, $desc] = $item;
            ?>
                <a href="<?php echo htmlspecialchars((string) $href, ENT_QUOTES, 'UTF-8'); ?>"
                   class="setting-card tone-<?php echo $tone; ?>"
                   style="animation-delay: <?php echo $delay; ?>ms;">
                    <span class="setting-ico"><i class="fa-solid <?php echo $icon; ?>"></i></span>
                    <div class="setting-body">
                        <div class="setting-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="setting-desc"><?php echo htmlspecialchars($desc, ENT_QUOTES, 'UTF-8'); ?></div>
                    </div>
                </a>
            <?php $delay += 40; endforeach; ?>
        </div>
    </main>

    <script>
        // Animasyon reflow fix
        window.addEventListener('load', function(){
            requestAnimationFrame(function(){
                document.querySelectorAll('.setting-card, .hero-card').forEach(function(el){ void el.offsetHeight; });
            });
        });
    </script>

</body>
</html>

<?php

declare(strict_types=1);

/**
 * Ortak hata / boş durum sayfası.
 *
 * Parametresiz açılan veya kaydı bulunamayan sayfalar düz metin (die('...'))
 * yerine bu kartı gösterir: uygulamanın başlık + kart diliyle, geri ve ana sayfa
 * bağlantılarıyla. Çağrıdan sonra betik sonlanır.
 *
 *   lumen_hata_sayfasi('Sipariş seçilmedi', 'Sipariş listesinden tekrar açın.');
 *   lumen_hata_sayfasi('Kayıt bulunamadı', '...', 404);
 */

if (!function_exists('lumen_hata_sayfasi')) {
    function lumen_hata_sayfasi(string $baslik, string $mesaj = '', int $kod = 400, string $ikon = 'fa-circle-exclamation'): never
    {
        if (!headers_sent()) {
            http_response_code($kod);
            header('Content-Type: text/html; charset=UTF-8');
        }
        $kok = defined('APP_ROOT_URL') ? rtrim((string) APP_ROOT_URL, '/') : '';
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        ?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lumen - <?php echo $h($baslik); ?></title>
    <link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/lumen.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>

        * { box-sizing: border-box; margin: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text-1); min-height: 100vh; }

        main { max-width: 520px; margin: 0 auto; padding: 48px 16px; }
        .kart { background: var(--card); border: 1px solid var(--border); border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 32px 24px; text-align: center; }
        .ikon { width: 56px; height: 56px; border-radius: 16px; background: var(--amber-soft); color: var(--amber); display: inline-flex; align-items: center; justify-content: center; font-size: 24px; margin-bottom: 14px; }
        h1 { font-size: 18px; font-weight: 700; }
        p { color: var(--text-2); font-size: 14px; line-height: 1.5; margin-top: 6px; }
        .eylemler { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; margin-top: 22px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 10px; font-size: 13.5px; font-weight: 600; text-decoration: none; border: 1px solid var(--border); background: #fff; color: var(--text-1); }
        .btn:hover { border-color: rgba(111, 16, 34, 0.35); color: var(--red); }
        .btn.birincil { background: var(--red); border-color: var(--red); color: #fff; }
        @media (max-width: 767px) { main { padding-top: 24px; } }
    </style>
</head>
<body>
<header class="top-header">
    <div class="header-inner">
        <a href="<?php echo $h($kok . '/index.php'); ?>" class="header-back" title="Ana sayfa" onclick="if (history.length > 1) { history.back(); return false; }"><i class="fa fa-arrow-left"></i></a>
        <div class="header-divider"></div>
        <span class="header-title"><?php echo $h($baslik); ?></span>
    </div>
</header>
<main>
    <div class="kart" role="alert">
        <span class="ikon"><i class="fa-solid <?php echo $h($ikon); ?>"></i></span>
        <h1><?php echo $h($baslik); ?></h1>
        <?php if ($mesaj !== ''): ?><p><?php echo $h($mesaj); ?></p><?php endif; ?>
        <div class="eylemler">
            <a href="<?php echo $h($kok . '/index.php'); ?>" class="btn" onclick="if (history.length > 1) { history.back(); return false; }"><i class="fa-solid fa-arrow-left"></i> Geri dön</a>
            <a href="<?php echo $h($kok . '/index.php'); ?>" class="btn birincil"><i class="fa-solid fa-house"></i> Ana sayfa</a>
        </div>
    </div>
</main>
</body>
</html><?php
        exit;
    }
}

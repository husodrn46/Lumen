<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/kontrol.php");
require_once __DIR__ . '/includes/bildirim.php';

$benim = (int) $terminalkullanici;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        die('Geçersiz güvenlik doğrulaması. Sayfayı yenileyip tekrar deneyin.');
    }
    if (isset($_POST['tumu'])) {
        lumen_bildirim_okundu($dbh, $benim);
        header('Location: bildirimler.php', true, 303);
        exit;
    }
    $id = (int) ($_POST['ac'] ?? 0);
    if ($id > 0) {
        lumen_bildirim_okundu($dbh, $benim, $id);
        $st = $dbh->prepare("SELECT BAGLANTI FROM M_BILDIRIM WHERE ID = :id AND PERSONEL_ID = :p");
        $st->execute([':id' => $id, ':p' => $benim]);
        $hedef = lumen_bildirim_guvenli_baglanti((string) $st->fetchColumn());
        header('Location: ' . ($hedef !== '' ? APP_ROOT_URL . '/' . $hedef : 'bildirimler.php'), true, 303);
        exit;
    }
}

$liste = lumen_bildirim_listesi($dbh, $benim, 200);
$okunmamis = count(array_filter($liste, static fn(array $b): bool => !(int) $b['OKUNDU']));
$ikon = [
    'gorev' => 'fa-list-check', 'cek_vade' => 'fa-money-check', 'guvenlik' => 'fa-shield-halved',
    'ayar' => 'fa-sliders', 'sistem' => 'fa-circle-info',
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lumen - Bildirimler</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (is_file(__DIR__ . '/pwa-header.php')) { include_once(__DIR__ . '/pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb; --card: #fff; --text-1: #1f2937; --text-2: #6b7280; --text-3: #9ca3af;
            --border: #e5e7eb; --red: #6F1022; --red-soft: #fef2f2; --indigo-soft: #eef2ff;
        }
        * { box-sizing: border-box; margin: 0; }
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; background: var(--bg); color: var(--text-1); min-height: 100vh; }

        .top-header { position: sticky; top: 0; z-index: 40; height: 64px; background: rgba(255,255,255,0.88); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); border-bottom: 1px solid rgba(248, 113, 113, 0.18); box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04); }
        .header-inner { max-width: 820px; margin: 0 auto; height: 100%; display: flex; align-items: center; gap: 14px; padding: 0 24px; }
        .header-back { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; color: var(--text-2); text-decoration: none; transition: all 0.2s ease; }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; }
        .header-title i { color: var(--red); font-size: 16px; }
        .header-sayac { font-size: 12px; font-weight: 600; color: var(--text-2); background: var(--red-soft); border-radius: 8px; padding: 3px 9px; }
        .header-sayac.yeni { color: var(--red); }
        .header-spacer { flex: 1 1 auto; }
        .btn-ikincil { display: inline-flex; align-items: center; gap: 8px; padding: 9px 14px; background: var(--card); color: var(--text-1); border: 1px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; white-space: nowrap; }
        .btn-ikincil:hover { border-color: rgba(111, 16, 34, 0.35); color: var(--red); }

        main { max-width: 820px; margin: 0 auto; padding: 22px 24px 60px; }

        .bos { text-align: center; color: var(--text-3); padding: 48px 16px; background: rgba(255,255,255,0.94); border: 1px solid rgba(248, 113, 113, 0.18); border-radius: 16px; }
        .bos i { font-size: 32px; display: block; margin-bottom: 10px; }
        .liste { display: flex; flex-direction: column; gap: 10px; }
        .bildirim { width: 100%; text-align: left; display: flex; align-items: flex-start; gap: 12px; padding: 14px 16px;
                    background: rgba(255,255,255,0.94); border: 1px solid rgba(248, 113, 113, 0.18); border-radius: 16px;
                    box-shadow: 0 4px 16px rgba(0,0,0,0.04); cursor: pointer; font: inherit; color: inherit;
                    transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.25s ease; }
        .bildirim:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(111, 16, 34, 0.08); border-color: rgba(111, 16, 34, 0.35); }
        .bildirim.okundu { opacity: 0.7; }
        .bildirim.okundu:hover { opacity: 1; }
        .ikon { width: 38px; height: 38px; flex-shrink: 0; border-radius: 10px; background: var(--indigo-soft);
                display: inline-flex; align-items: center; justify-content: center; color: #4f46e5; }
        .bildirim.yeni .ikon { background: var(--red-soft); color: var(--red); }
        .govde { flex: 1; min-width: 0; }
        .b-baslik { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 14px; }
        .b-nokta { width: 8px; height: 8px; border-radius: 50%; background: var(--red); flex-shrink: 0; }
        .b-metin { display: block; color: var(--text-2); font-size: 13px; margin-top: 3px; line-height: 1.45; overflow-wrap: anywhere; }
        .b-tarih { display: block; color: var(--text-3); font-size: 11.5px; margin-top: 6px; }

        html.lumen-dark .bildirim, html.lumen-dark .bos, html.lumen-dark .btn-ikincil { background: #1e293b; border-color: var(--border); }
        html.lumen-dark .header-sayac { background: #3b1219; }
        html.lumen-dark .bildirim.yeni .ikon { background: #3b1219; }

        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .btn-ikincil { padding: 8px 10px; }
            .btn-ikincil span { display: none; }
            main { padding: 14px 12px 40px; }
            .bildirim:hover { transform: none; }
        }
    </style>
</head>
<body>
<header class="top-header">
    <div class="header-inner">
        <a href="index.php" class="header-back" title="Geri" aria-label="Ana sayfa"><i class="fa fa-arrow-left"></i></a>
        <div class="header-divider"></div>
        <span class="header-title"><i class="fa-solid fa-bell"></i>Bildirimler</span>
        <span class="header-sayac<?php echo $okunmamis > 0 ? ' yeni' : ''; ?>"><?php echo $okunmamis > 0 ? $okunmamis . ' okunmamış' : 'Hepsi okundu'; ?></span>
        <div class="header-spacer"></div>
        <?php if ($okunmamis > 0): ?>
            <form method="post"><?php echo csrf_field(); ?><button class="btn-ikincil" name="tumu" value="1" title="Tümünü okundu say"><i class="fa-solid fa-check-double"></i><span>Tümünü okundu say</span></button></form>
        <?php endif; ?>
    </div>
</header>
<main>
    <?php if ($liste === []): ?>
        <div class="bos"><i class="fa-regular fa-bell-slash"></i>Bildirim yok</div>
    <?php else: ?>
        <form method="post" class="liste">
            <?php echo csrf_field(); ?>
            <?php foreach ($liste as $b): $yeni = !(int) $b['OKUNDU']; ?>
                <button class="bildirim <?php echo $yeni ? 'yeni' : 'okundu'; ?>" name="ac" value="<?php echo (int) $b['ID']; ?>">
                    <span class="ikon"><i class="fa-solid <?php echo e($ikon[(string) $b['TUR']] ?? 'fa-bell'); ?>"></i></span>
                    <span class="govde">
                        <span class="b-baslik"><?php if ($yeni): ?><span class="b-nokta" aria-label="Okunmamış"></span><?php endif; ?><?php echo e($b['BASLIK']); ?></span>
                        <?php if (!empty($b['METIN'])): ?><span class="b-metin" ><?php echo e($b['METIN']); ?></span><?php endif; ?>
                        <span class="b-tarih" ><?php echo e(date('d.m.Y H:i', strtotime((string) $b['TARIH']) ?: time())); ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
        </form>
    <?php endif; ?>
</main>
</body>
</html>

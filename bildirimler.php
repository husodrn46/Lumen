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
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; background: var(--bg); color: var(--text-1); }
        .top { position: sticky; top: 0; z-index: 10; background: rgba(255,255,255,.9); backdrop-filter: blur(12px);
               border-bottom: 1px solid var(--border); }
        .top-in { max-width: 760px; margin: 0 auto; padding: 14px 16px; display: flex; align-items: center; gap: 12px; }
        .geri { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--border); display: inline-flex;
                align-items: center; justify-content: center; color: var(--text-1); text-decoration: none; background: var(--card); }
        .baslik { flex: 1; font-size: 17px; font-weight: 600; }
        .baslik small { display: block; font-size: 12px; color: var(--text-2); font-weight: 500; margin-top: 2px; }
        .tumu { border: 1px solid var(--border); background: var(--card); border-radius: 10px; padding: 8px 12px;
                font: inherit; font-size: 13px; cursor: pointer; color: var(--text-1); }
        main { max-width: 760px; margin: 0 auto; padding: 16px 16px 60px; }
        .bos { text-align: center; color: var(--text-3); padding: 60px 0; }
        .bos i { font-size: 34px; display: block; margin-bottom: 10px; }
        .bildirim { width: 100%; text-align: left; display: flex; gap: 12px; padding: 14px; margin-bottom: 10px;
                    background: var(--card); border: 1px solid var(--border); border-radius: 14px; cursor: pointer;
                    font: inherit; color: inherit; }
        .bildirim.yeni { border-color: rgba(111,16,34,.35); background: #fffafa; }
        .ikon { width: 38px; height: 38px; flex-shrink: 0; border-radius: 10px; background: var(--indigo-soft);
                display: inline-flex; align-items: center; justify-content: center; color: #4f46e5; }
        .bildirim.yeni .ikon { background: var(--red-soft); color: var(--red); }
        .b-baslik { font-weight: 600; font-size: 14px; }
        .b-metin { color: var(--text-2); font-size: 13px; margin-top: 3px; line-height: 1.45; }
        .b-tarih { color: var(--text-3); font-size: 11.5px; margin-top: 6px; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0f172a; --card: #1e293b; --text-1: #e6e9f0; --text-2: #9aa7bd; --text-3: #7b899f; --border: #2f3d52; --red-soft: #3b1219; --indigo-soft: #1a2040; }
            .top { background: rgba(15,23,42,.9); }
            .bildirim.yeni { background: #2a1820; }
        }
    </style>
</head>
<body>
<header class="top">
    <div class="top-in">
        <a class="geri" href="index.php" aria-label="Ana sayfa"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="baslik">Bildirimler<small><?php echo $okunmamis > 0 ? $okunmamis . ' okunmamış' : 'Hepsi okundu'; ?></small></div>
        <?php if ($okunmamis > 0): ?>
            <form method="post"><?php echo csrf_field(); ?><button class="tumu" name="tumu" value="1">Tümünü okundu say</button></form>
        <?php endif; ?>
    </div>
</header>
<main>
    <?php if ($liste === []): ?>
        <div class="bos"><i class="fa-regular fa-bell-slash"></i>Bildirim yok</div>
    <?php else: ?>
        <form method="post">
            <?php echo csrf_field(); ?>
            <?php foreach ($liste as $b): ?>
                <button class="bildirim<?php echo (int) $b['OKUNDU'] ? '' : ' yeni'; ?>" name="ac" value="<?php echo (int) $b['ID']; ?>">
                    <span class="ikon"><i class="fa-solid <?php echo e($ikon[(string) $b['TUR']] ?? 'fa-bell'); ?>"></i></span>
                    <span>
                        <span class="b-baslik"><?php echo e($b['BASLIK']); ?></span>
                        <?php if (!empty($b['METIN'])): ?><div class="b-metin"><?php echo e($b['METIN']); ?></div><?php endif; ?>
                        <div class="b-tarih"><?php echo e(date('d.m.Y H:i', strtotime((string) $b['TARIH']) ?: time())); ?></div>
                    </span>
                </button>
            <?php endforeach; ?>
        </form>
    <?php endif; ?>
</main>
</body>
</html>

<?php

declare(strict_types=1);

/** Belge ve kasa/banka ekranlarının (yalnız bağımsız mod) ortak sayfa iskeleti ve biçimleme işlevleri. */

function bh(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function btl(mixed $v): string
{
    return number_format((float) $v, 2, ',', '.') . ' ₺';
}

function bmiktar(mixed $v): string
{
    $s = number_format((float) $v, 3, ',', '.');
    return rtrim(rtrim($s, '0'), ',');
}

function belge_sayfa_basla(string $baslik, string $ikon, string $geri = '../index.php', string $sag = ''): void
{
    ?><!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lumen - <?= bh($baslik) ?></title>
    <?php include_once __DIR__ . '/../../pwa-header.php'; ?>
    <?php /* Görünüm assets/css/lumen.css'ten (pwa-header yükler); burada yalnız bu iskelete özgü kurallar */ ?>
</head>
<body>
<header class="top-header">
    <div class="header-inner">
        <a href="<?= bh($geri) ?>" class="header-back" title="Geri" aria-label="Geri"><i class="fa fa-arrow-left"></i></a>
        <div class="header-divider"></div>
        <span class="header-title"><i class="fa-solid <?= bh($ikon) ?>"></i><span class="header-title-metin"><?= bh($baslik) ?></span></span>
        <div class="header-spacer"></div>
        <?= $sag ?>
    </div>
</header>
<main class="sayfa">
<?php
    $ok = (string) ($_GET['ok'] ?? '');
    $hata = (string) ($_GET['hata'] ?? '');
    if ($ok !== '') {
        echo '<div class="bildirim ok" role="status"><i class="fa-solid fa-circle-check"></i>' . bh(mb_substr($ok, 0, 200)) . '</div>';
    }
    if ($hata !== '') {
        echo '<div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation"></i>' . bh(mb_substr($hata, 0, 200)) . '</div>';
    }
}

function belge_sayfa_bitir(): void
{
    echo "</main>\n</body>\n</html>\n";
}

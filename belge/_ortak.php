<?php

declare(strict_types=1);

/**
 * Belge modülü (yalnız bağımsız mod) ortak parçaları: erişim, sayfa iskeleti, biçimleme.
 * Her sayfa ayr.php + kontrol.php'den sonra yükler.
 */

// Parça dosyası: doğrudan açılmaz (sayfalar ayr.php + kontrol.php'den sonra yükler).
if (!isset($terminalkullanici, $dbh)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/belge/belge_lib.php';

if ((int) m_p_yetki($terminalkullanici, 'M31') !== 1) {
    lumen_hata_sayfasi('Fatura yetkiniz yok', 'Bu ekran için M31 (Faturalama) yetkisi gerekir.', 403, 'fa-lock');
}

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
    <?php include_once __DIR__ . '/../pwa-header.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --text-1:#1f2937; --text-2:#6b7280; --text-3:#9ca3af; --border:#e5e7eb;
                --red:#6F1022; --red-soft:#fef2f2; --emerald:#047857; --emerald-soft:#ecfdf5; --amber:#b45309; --amber-soft:#fffbeb; }
        * { box-sizing: border-box; margin: 0; }
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; background: var(--bg); color: var(--text-1); min-height: 100vh; font-size: 14px; }
        .top-header { position: sticky; top: 0; z-index: 40; height: 64px; background: rgba(255,255,255,0.88); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); border-bottom: 1px solid rgba(248,113,113,0.18); box-shadow: 0 2px 8px rgba(111,16,34,0.04); }
        .header-inner { max-width: 1100px; margin: 0 auto; height: 100%; display: flex; align-items: center; gap: 14px; padding: 0 24px; }
        .header-back { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; color: var(--text-2); text-decoration: none; }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; }
        .header-title i { color: var(--red); font-size: 16px; }
        .header-spacer { flex: 1 1 auto; }
        main { max-width: 1100px; margin: 0 auto; padding: 22px 24px 60px; }
        .kart { background: rgba(255,255,255,0.94); border: 1px solid rgba(248,113,113,0.18); border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); margin-bottom: 16px; }
        .kart-ic { padding: 16px 18px; }
        .kart-baslik { font-size: 12px; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: .4px; padding: 14px 18px 0; display: flex; align-items: center; gap: 8px; }
        .kart-baslik i { color: var(--red); }
        .btn { border: 1px solid transparent; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; padding: 9px 14px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; text-decoration: none; }
        .btn-birincil { background: var(--red); color: #fff; border-color: var(--red); }
        .btn-birincil:hover { filter: brightness(1.1); }
        .btn-ikincil { background: #fff; color: var(--text-1); border-color: var(--border); }
        .btn-ikincil:hover { border-color: rgba(111,16,34,0.35); color: var(--red); }
        .btn:disabled { opacity: .6; cursor: not-allowed; }
        .izgara { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        label.alan { display: flex; flex-direction: column; gap: 5px; font-size: 12px; font-weight: 600; color: var(--text-2); }
        input, select, textarea { font-family: inherit; font-size: 14px; padding: 9px 11px; border: 1px solid var(--border); border-radius: 10px; background: #fff; color: var(--text-1); outline: none; width: 100%; }
        input:focus, select:focus, textarea:focus { border-color: rgba(111,16,34,0.45); box-shadow: 0 0 0 3px rgba(111,16,34,0.08); }
        table.liste { width: 100%; border-collapse: collapse; }
        table.liste th { text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; color: var(--text-2); padding: 10px 12px; border-bottom: 1px solid var(--border); background: #fafafa; }
        table.liste td { padding: 10px 12px; border-bottom: 1px solid #f1f3f5; vertical-align: middle; }
        table.liste tr:last-child td { border-bottom: none; }
        .sag { text-align: right; } .koyu { font-weight: 700; } .soluk { color: var(--text-3); font-size: 12px; }
        .tablo-sar { overflow-x: auto; }
        .rozet { display: inline-block; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 8px; background: #f3f4f6; color: var(--text-2); }
        .rozet.iptal { background: var(--red-soft); color: var(--red); }
        .rozet.satis { background: var(--emerald-soft); color: var(--emerald); }
        .rozet.alis { background: var(--amber-soft); color: var(--amber); }
        .bildirim { border-radius: 12px; padding: 12px 16px; font-size: 13px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 10px; }
        .bildirim.ok { background: var(--emerald-soft); color: var(--emerald); border: 1px solid #a7f3d0; }
        .bildirim.hata { background: var(--red-soft); color: var(--red); border: 1px solid rgba(111,16,34,0.25); }
        .toplamlar { display: grid; grid-template-columns: auto auto; justify-content: end; gap: 6px 24px; padding: 14px 18px; font-size: 14px; }
        .toplamlar .genel { font-size: 18px; font-weight: 700; color: var(--red); }
        @media (max-width: 767px) {
            .top-header { height: 50px; } .header-inner { padding: 0 12px; gap: 10px; } .header-title { font-size: 15px; }
            main { padding: 14px 12px 40px; } .btn span.metin { display: none; }
        }
        @media print {
            .top-header, .yazdirma-gizle { display: none !important; }
            body { background: #fff; } main { padding: 0; max-width: none; } .kart { box-shadow: none; border-color: #ddd; }
        }
    </style>
</head>
<body>
<header class="top-header">
    <div class="header-inner">
        <a href="<?= bh($geri) ?>" class="header-back" title="Geri"><i class="fa fa-arrow-left"></i></a>
        <div class="header-divider"></div>
        <span class="header-title"><i class="fa-solid <?= bh($ikon) ?>"></i><?= bh($baslik) ?></span>
        <div class="header-spacer"></div>
        <?= $sag ?>
    </div>
</header>
<main>
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

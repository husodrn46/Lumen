<?php

declare(strict_types=1);

include_once(__DIR__ . "/../log_ip.php");
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/gorev_lib.php");

$benimId = (int) $terminalkullanici;

// Rapor yalnizca yoneticilere (YETKI=0)
if (!gorev_erisim_var_mi($benimId) || !(isset($yetkidurum) && (int) $yetkidurum === 0)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Genel ozet
$ozet = ['toplam' => 0, 'tamam' => 0, 'geciken' => 0, 'bekleyen' => 0];
try {
    $st = $dbh->query(
        "SELECT COUNT(*) AS toplam,
                SUM(CASE WHEN DURUM IN (3,5) THEN 1 ELSE 0 END) AS tamam,
                SUM(CASE WHEN DURUM IN (0,1,2) AND VADE_TARIHI < CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS geciken,
                SUM(CASE WHEN DURUM IN (0,1,2) THEN 1 ELSE 0 END) AS bekleyen
           FROM M_GOREV WITH(NOLOCK) WHERE AKTIF = 1"
    );
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($ozet as $k => $_) {
        $ozet[$k] = (int) ($r[$k] ?? 0);
    }
} catch (PDOException $e) {
    error_log('gorev_rapor ozet: ' . $e->getMessage());
}

// Kisi bazli performans
$satirlar = [];
try {
    $st = $dbh->query(
        "SELECT G.ATANAN_ID,
                COUNT(*) AS toplam,
                SUM(CASE WHEN G.DURUM = 0 THEN 1 ELSE 0 END) AS yeni,
                SUM(CASE WHEN G.DURUM IN (1,2) THEN 1 ELSE 0 END) AS devam,
                SUM(CASE WHEN G.DURUM IN (3,5) THEN 1 ELSE 0 END) AS tamam,
                SUM(CASE WHEN G.DURUM = 4 THEN 1 ELSE 0 END) AS red,
                SUM(CASE WHEN G.DURUM IN (0,1,2) AND G.VADE_TARIHI < CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS geciken,
                AVG(CASE WHEN G.DURUM IN (3,5) AND G.TAMAMLANMA_TARIHI IS NOT NULL THEN DATEDIFF(HOUR, G.OLUSTURMA_TARIHI, G.TAMAMLANMA_TARIHI) END) AS ort_saat
           FROM M_GOREV G WITH(NOLOCK)
          WHERE G.AKTIF = 1
          GROUP BY G.ATANAN_ID
          ORDER BY toplam DESC"
    );
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('gorev_rapor kisi: ' . $e->getMessage());
}

$sureFormat = static function ($saat): string {
    if ($saat === null || $saat === '') {
        return '-';
    }
    $saat = (float) $saat;
    if ($saat < 1) {
        return '< 1 saat';
    }
    if ($saat < 24) {
        return round($saat) . ' saat';
    }
    return number_format($saat / 24, 1, ',', '.') . ' gün';
};
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Görev Raporu</title>
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --text-1:#1f2937; --text-2:#6b7280; --text-3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; }
        * { box-sizing: border-box; margin: 0; }
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; background: var(--bg); color: var(--text-1); min-height: 100vh; }
        .top-header { position: sticky; top: 0; z-index: 40; height: 64px; background: rgba(255,255,255,0.88); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); border-bottom: 1px solid rgba(248,113,113,0.18); box-shadow: 0 2px 8px rgba(111,16,34,0.04); }
        .header-inner { max-width: 1200px; margin: 0 auto; height: 100%; display: flex; align-items: center; gap: 14px; padding: 0 24px; }
        .header-back { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; color: var(--text-2); text-decoration: none; transition: all 0.2s ease; }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; }
        .header-title i { color: var(--red,#ef4444); font-size: 16px; }
        main { max-width: 1200px; margin: 0 auto; padding: 22px 24px 60px; }

        .ozet-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; margin-bottom: 26px; }
        .ozet-card { background: rgba(255,255,255,0.92); border: 1px solid rgba(248,113,113,0.18); border-radius: 14px; padding: 18px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); }
        .ozet-card .lbl { font-size: 12px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.4px; }
        .ozet-card .num { margin-top: 6px; font-size: 30px; font-weight: 700; line-height: 1; }
        .ozet-card.gec .num { color: var(--red); }
        .ozet-card.tam .num { color: var(--emerald); }

        .blok-baslik { font-size: 15px; font-weight: 700; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
        .blok-baslik i { color: var(--red,#ef4444); }

        .tablo-wrap { background: rgba(255,255,255,0.94); border: 1px solid rgba(248,113,113,0.18); border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        thead th { background: #fafafa; text-align: left; padding: 13px 16px; font-size: 12px; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--border); white-space: nowrap; }
        thead th.sag, tbody td.sag { text-align: right; }
        tbody td { padding: 13px 16px; border-bottom: 1px solid #f1f1f1; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fcfcfc; }
        .kisi-hucre { display: flex; align-items: center; gap: 10px; }
        .avatar { flex-shrink: 0; width: 32px; height: 32px; border-radius: 50%; background: var(--red-soft); color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; }
        .kisi-ad { font-weight: 600; }
        .oran-bar { width: 90px; height: 7px; background: #f1f1f1; border-radius: 4px; overflow: hidden; display: inline-block; vertical-align: middle; margin-right: 8px; }
        .oran-dolu { height: 100%; background: var(--emerald); border-radius: 4px; }
        .pill { display: inline-block; min-width: 26px; text-align: center; padding: 2px 8px; border-radius: 8px; font-size: 12px; font-weight: 600; }
        .pill.gec { background: var(--red-soft); color: var(--red); }
        .empty { padding: 40px; text-align: center; color: var(--text-3); }

        @media (max-width: 767px) {
            .top-header { height: 50px; } .header-inner { padding: 0 12px; } .header-title { font-size: 15px; }
            main { padding: 14px 12px 40px; }
            .tablo-wrap { overflow-x: auto; } table { min-width: 720px; }
        }
    </style>
</head>
<body>
    <header class="top-header">
        <div class="header-inner">
            <a href="gorevler.php" class="header-back" title="Geri"><i class="fa fa-arrow-left"></i></a>
            <div class="header-divider"></div>
            <span class="header-title"><i class="fa-solid fa-chart-column"></i>Görev Raporu</span>
        </div>
    </header>

    <main>
        <div class="ozet-grid">
            <div class="ozet-card"><div class="lbl">Toplam görev</div><div class="num"><?php echo $ozet['toplam']; ?></div></div>
            <div class="ozet-card tam"><div class="lbl">Tamamlanan</div><div class="num"><?php echo $ozet['tamam']; ?></div></div>
            <div class="ozet-card"><div class="lbl">Bekleyen</div><div class="num"><?php echo $ozet['bekleyen']; ?></div></div>
            <div class="ozet-card gec"><div class="lbl">Geciken</div><div class="num"><?php echo $ozet['geciken']; ?></div></div>
        </div>

        <div class="blok-baslik"><i class="fa-solid fa-user-group"></i> Kişi bazlı performans</div>
        <div class="tablo-wrap">
            <?php if (empty($satirlar)): ?>
                <div class="empty">Henüz görev kaydı yok.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Personel</th>
                            <th class="sag">Toplam</th>
                            <th class="sag">Yeni</th>
                            <th class="sag">Devam</th>
                            <th class="sag">Tamamlanan</th>
                            <th class="sag">Geciken</th>
                            <th>Tamamlanma oranı</th>
                            <th class="sag">Ort. süre</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($satirlar as $s):
                            $ad = gorev_kullanici_adi($dbh, (int) $s['ATANAN_ID']);
                            $toplam = (int) $s['toplam'];
                            $tamam = (int) $s['tamam'];
                            $geciken = (int) $s['geciken'];
                            $oran = $toplam > 0 ? (int) round($tamam / $toplam * 100) : 0;
                        ?>
                            <tr>
                                <td>
                                    <div class="kisi-hucre">
                                        <span class="avatar"><?php echo $h(gorev_bas_harfler($ad)); ?></span>
                                        <span class="kisi-ad"><?php echo $h($ad); ?></span>
                                    </div>
                                </td>
                                <td class="sag"><?php echo $toplam; ?></td>
                                <td class="sag"><?php echo (int) $s['yeni']; ?></td>
                                <td class="sag"><?php echo (int) $s['devam']; ?></td>
                                <td class="sag"><?php echo $tamam; ?></td>
                                <td class="sag"><?php echo $geciken > 0 ? '<span class="pill gec">' . $geciken . '</span>' : '0'; ?></td>
                                <td>
                                    <span class="oran-bar"><span class="oran-dolu" style="width:<?php echo $oran; ?>%"></span></span>
                                    <span style="font-size:12px;color:var(--text-2)"><?php echo $oran; ?>%</span>
                                </td>
                                <td class="sag"><?php echo $h($sureFormat($s['ort_saat'] ?? null)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>

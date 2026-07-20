<?php

declare(strict_types=1);

include_once(__DIR__ . "/log_ip.php");
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/kontrol.php");
include_once(__DIR__ . "/gorev_lib.php");

$benimId = (int) $terminalkullanici;

if (!gorev_erisim_var_mi($benimId)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$atamaYetkisi = gorev_atama_yetkisi_var_mi($benimId);
$yonetici = (isset($yetkidurum) && (int) $yetkidurum === 0);

// Sekme: bana | atadiklarim (M29) | tum (admin)
$sekme = (string) ($_GET['sekme'] ?? 'bana');
if ($sekme === 'atadiklarim' && !$atamaYetkisi) {
    $sekme = 'bana';
}
if ($sekme === 'tum' && !$yonetici) {
    $sekme = 'bana';
}
if (!in_array($sekme, ['bana', 'atadiklarim', 'tum'], true)) {
    $sekme = 'bana';
}

// Gorunum: liste | kanban
$gorunum = (($_GET['gorunum'] ?? 'liste') === 'kanban') ? 'kanban' : 'liste';

// Filtreler
$f = (string) ($_GET['f'] ?? 'hepsi');
if (!in_array($f, ['hepsi', 'yeni', 'devam', 'geciken', 'tamam'], true)) {
    $f = 'hepsi';
}
$fKategori = (isset($_GET['kategori']) && $_GET['kategori'] !== '') ? (int) $_GET['kategori'] : null;
if ($fKategori !== null && !array_key_exists($fKategori, gorev_kategori_listesi())) {
    $fKategori = null;
}
$fArama = trim((string) ($_GET['q'] ?? ''));

// WHERE kosulu (sekme + filtreler)
$where = 'G.AKTIF = 1';
$params = [];
if ($sekme === 'tum') {
    // tum gorevler (admin)
} elseif ($sekme === 'atadiklarim') {
    $where .= ' AND G.ATAYAN_ID = :id';
    $params[':id'] = $benimId;
} else {
    $where .= ' AND G.ATANAN_ID = :id';
    $params[':id'] = $benimId;
}
switch ($f) {
    case 'yeni':    $where .= ' AND G.DURUM = 0'; break;
    case 'devam':   $where .= ' AND G.DURUM IN (1, 2)'; break;
    case 'geciken': $where .= ' AND G.DURUM IN (0, 1, 2) AND G.VADE_TARIHI < CAST(GETDATE() AS DATE)'; break;
    case 'tamam':   $where .= ' AND G.DURUM IN (3, 5)'; break;
}
if ($fKategori !== null) {
    $where .= ' AND G.KATEGORI = :kat';
    $params[':kat'] = $fKategori;
}
if ($fArama !== '') {
    $where .= ' AND (G.BASLIK LIKE :q OR G.ACIKLAMA LIKE :q)';
    $params[':q'] = '%' . $fArama . '%';
}

// Liste
$gorevler = [];
try {
    $sql = "SELECT G.ID, G.BASLIK, G.ACIKLAMA, G.ATAYAN_ID, G.ATANAN_ID, G.DURUM, G.ONCELIK, G.KATEGORI,
                   G.VADE_TARIHI, G.RET_SEBEBI, G.OLUSTURMA_TARIHI, G.TAMAMLANMA_TARIHI,
                   (SELECT COUNT(*) FROM M_GOREV_HAREKET H WITH(NOLOCK) WHERE H.GOREV_ID = G.ID AND H.TIP = 2) AS YORUM_SAYISI,
                   (SELECT COUNT(*) FROM M_GOREV_EK E WITH(NOLOCK) WHERE E.GOREV_ID = G.ID) AS EK_SAYISI,
                   (SELECT COUNT(*) FROM M_GOREV_ALTGOREV A WITH(NOLOCK) WHERE A.GOREV_ID = G.ID) AS ALT_TOPLAM,
                   (SELECT COUNT(*) FROM M_GOREV_ALTGOREV A WITH(NOLOCK) WHERE A.GOREV_ID = G.ID AND A.TAMAM = 1) AS ALT_TAMAM
              FROM M_GOREV G WITH(NOLOCK)
             WHERE {$where}
             ORDER BY CASE WHEN G.DURUM IN (3, 4, 5, 9) THEN 1 ELSE 0 END,
                      CASE G.ONCELIK WHEN 3 THEN 0 WHEN 2 THEN 1 ELSE 2 END,
                      CASE WHEN G.VADE_TARIHI IS NULL THEN 1 ELSE 0 END,
                      G.VADE_TARIHI ASC,
                      G.OLUSTURMA_TARIHI DESC";
    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $gorevler = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('gorevler.php liste hatasi: ' . $e->getMessage());
    $gorevler = [];
}

// Metrik sayilari (filtreden bagimsiz, sekme kapsaminda)
$sayac = ['hepsi' => 0, 'yeni' => 0, 'devam' => 0, 'geciken' => 0, 'tamam' => 0];
try {
    $mWhere = 'G.AKTIF = 1';
    $mParams = [];
    if ($sekme === 'atadiklarim') {
        $mWhere .= ' AND G.ATAYAN_ID = :id';
        $mParams[':id'] = $benimId;
    } elseif ($sekme === 'bana') {
        $mWhere .= ' AND G.ATANAN_ID = :id';
        $mParams[':id'] = $benimId;
    }
    $mSql = "SELECT
                COUNT(*) AS hepsi,
                SUM(CASE WHEN G.DURUM = 0 THEN 1 ELSE 0 END) AS yeni,
                SUM(CASE WHEN G.DURUM IN (1,2) THEN 1 ELSE 0 END) AS devam,
                SUM(CASE WHEN G.DURUM IN (0,1,2) AND G.VADE_TARIHI < CAST(GETDATE() AS DATE) THEN 1 ELSE 0 END) AS geciken,
                SUM(CASE WHEN G.DURUM IN (3,5) THEN 1 ELSE 0 END) AS tamam
             FROM M_GOREV G WITH(NOLOCK) WHERE {$mWhere}";
    $mStmt = $dbh->prepare($mSql);
    foreach ($mParams as $k => $v) {
        $mStmt->bindValue($k, $v, PDO::PARAM_INT);
    }
    $mStmt->execute();
    $mRow = $mStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($sayac as $k => $_) {
        $sayac[$k] = (int) ($mRow[$k] ?? 0);
    }
} catch (PDOException $e) {
    error_log('gorevler.php metrik hatasi: ' . $e->getMessage());
}

$kullanicilar = $atamaYetkisi ? gorev_atanabilir_kullanicilar($dbh) : [];
$kendiAd = gorev_kullanici_adi($dbh, $benimId);
$csrf = csrf_token();
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Mevcut filtreleri koruyarak URL uretir
$gorevUrl = static function (array $over = []) use ($sekme, $gorunum, $f, $fKategori, $fArama): string {
    $p = ['sekme' => $sekme, 'gorunum' => $gorunum, 'f' => $f];
    if ($fKategori !== null) {
        $p['kategori'] = $fKategori;
    }
    if ($fArama !== '') {
        $p['q'] = $fArama;
    }
    $p = array_merge($p, $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($p);
};

// Tek bir gorev kartinin HTML'i (liste ve kanban ortak)
$kartHtml = static function (array $g) use ($sekme, $h, $dbh): string {
    $d = (int) $g['DURUM'];
    $oncelik = (int) $g['ONCELIK'];
    $db = gorev_durum_bilgi($d);
    $ob = gorev_oncelik_bilgi($oncelik);
    $kb = gorev_kategori_bilgi(isset($g['KATEGORI']) ? (int) $g['KATEGORI'] : null);
    $vadeDurum = gorev_vade_durumu($g['VADE_TARIHI'] ?? null, $d);
    $vadeEtiket = gorev_vade_etiketi($g['VADE_TARIHI'] ?? null, $d);
    $kapali = gorev_durum_kapali_mi($d);
    $kisiId = $sekme === 'atadiklarim' ? (int) $g['ATANAN_ID'] : (int) $g['ATAYAN_ID'];
    $kisiAd = gorev_kullanici_adi($dbh, $kisiId);
    $kisiRol = $sekme === 'atadiklarim' ? 'Atanan' : 'Veren';
    if ($sekme === 'tum') {
        $kisiAd = gorev_kullanici_adi($dbh, (int) $g['ATANAN_ID']);
        $kisiRol = 'Atanan';
    }
    $altT = (int) ($g['ALT_TOPLAM'] ?? 0);
    $altC = (int) ($g['ALT_TAMAM'] ?? 0);

    ob_start();
    ?>
    <div class="gorev-card <?php echo $kapali ? 'bitmis' : ''; ?>"
         data-id="<?php echo (int) $g['ID']; ?>"
         data-durum="<?php echo $d; ?>"
         onclick="gorevAc(<?php echo (int) $g['ID']; ?>)">
        <div class="gc-top">
            <span class="durum-badge" style="background:<?php echo $db['bg']; ?>;color:<?php echo $db['renk']; ?>;">
                <i class="fa-solid <?php echo $db['ikon']; ?>"></i><?php echo $h($db['etiket']); ?>
            </span>
            <span class="kategori-badge" style="background:<?php echo $kb['bg']; ?>;color:<?php echo $kb['renk']; ?>;">
                <i class="fa-solid <?php echo $kb['ikon']; ?>"></i><?php echo $h($kb['etiket']); ?>
            </span>
            <?php if ($vadeEtiket !== ''): ?>
                <span class="vade <?php echo $h($vadeDurum); ?>"><i class="fa-regular fa-clock"></i><?php echo $h($vadeEtiket); ?></span>
            <?php endif; ?>
        </div>

        <div class="gc-baslik"><?php echo $h($g['BASLIK']); ?></div>
        <?php if (!empty($g['ACIKLAMA'])): ?>
            <div class="gc-aciklama"><?php echo $h($g['ACIKLAMA']); ?></div>
        <?php endif; ?>

        <?php if ($altT > 0): ?>
            <div class="gc-alt">
                <i class="fa-solid fa-list-check"></i>
                <div class="gc-alt-bar"><div class="gc-alt-dolu" style="width:<?php echo (int) round($altC / $altT * 100); ?>%"></div></div>
                <span><?php echo $altC; ?>/<?php echo $altT; ?></span>
            </div>
        <?php endif; ?>

        <div class="gc-foot">
            <span class="kisi">
                <span class="avatar"><?php echo $h(gorev_bas_harfler($kisiAd)); ?></span>
                <span class="ad"><span class="oncelik-nokta" style="background:<?php echo $ob['renk']; ?>" title="<?php echo $h($ob['etiket']); ?> öncelik"></span><?php echo $h($kisiRol); ?>: <?php echo $h($kisiAd); ?></span>
            </span>
            <span class="gc-actions">
                <?php if ((int) $g['YORUM_SAYISI'] > 0): ?>
                    <span class="ikon-sayi"><i class="fa-regular fa-comment"></i><?php echo (int) $g['YORUM_SAYISI']; ?></span>
                <?php endif; ?>
                <?php if ((int) $g['EK_SAYISI'] > 0): ?>
                    <span class="ikon-sayi"><i class="fa-solid fa-paperclip"></i><?php echo (int) $g['EK_SAYISI']; ?></span>
                <?php endif; ?>
                <?php if ($sekme === 'bana'): ?>
                    <?php if ($d === GOREV_DURUM_YENI || $d === GOREV_DURUM_GORULDU): ?>
                        <button type="button" class="durum-btn ileri" onclick="durumGuncelle(event, <?php echo (int) $g['ID']; ?>, <?php echo GOREV_DURUM_YAPILIYOR; ?>)"><i class="fa-solid fa-play"></i>Başla</button>
                    <?php elseif ($d === GOREV_DURUM_YAPILIYOR): ?>
                        <button type="button" class="durum-btn bitir" onclick="durumGuncelle(event, <?php echo (int) $g['ID']; ?>, <?php echo GOREV_DURUM_BITTI; ?>)"><i class="fa-solid fa-check"></i>Bitti</button>
                    <?php endif; ?>
                <?php endif; ?>
            </span>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};

// Kanban sutunlari
$kanbanSutunlar = [
    ['baslik' => 'Yapılacak', 'durumlar' => [0, 1], 'hedef' => GOREV_DURUM_GORULDU],
    ['baslik' => 'Yapılıyor', 'durumlar' => [2],    'hedef' => GOREV_DURUM_YAPILIYOR],
    ['baslik' => 'Bitti',     'durumlar' => [3],    'hedef' => GOREV_DURUM_BITTI],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Görevler</title>
    <?php include_once(__DIR__ . '/pwa-header.php'); ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb; --text-1: #1f2937; --text-2: #6b7280; --text-3: #9ca3af;
            --border: #e5e7eb; --red: #6F1022; --red-soft: #fef2f2; --emerald: #059669;
        }
        * { box-sizing: border-box; margin: 0; }
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; background: var(--bg); color: var(--text-1); min-height: 100vh; }

        .top-header { position: sticky; top: 0; z-index: 40; height: 64px; background: rgba(255,255,255,0.88); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); border-bottom: 1px solid rgba(248, 113, 113, 0.18); box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04); }
        .header-inner { max-width: 1280px; margin: 0 auto; height: 100%; display: flex; align-items: center; gap: 14px; padding: 0 24px; }
        .header-back { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 10px; color: var(--text-2); text-decoration: none; transition: all 0.2s ease; }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title { font-size: 18px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; }
        .header-title i { color: var(--red,#ef4444); font-size: 16px; }
        .header-spacer { flex: 1 1 auto; }
        .btn-yeni { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; background: var(--red,#ef4444); color: #fff; border: none; border-radius: 10px; font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 13.5px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.08); white-space: nowrap; }
        .btn-yeni:hover { background: var(--red); box-shadow: 0 4px 10px rgba(239, 68, 68, 0.25); transform: translateY(-1px); }

        main { max-width: 1280px; margin: 0 auto; padding: 22px 24px 60px; }

        .sekme-bar { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
        .sekme { display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 10px; font-size: 14px; font-weight: 600; text-decoration: none; color: var(--text-2); background: #fff; border: 1px solid var(--border); transition: all 0.2s ease; }
        .sekme:hover { border-color: rgba(239, 68, 68, 0.35); color: var(--red); }
        .sekme.aktif { background: var(--red,#ef4444); color: #fff; border-color: var(--red,#ef4444); box-shadow: 0 2px 8px rgba(239,68,68,0.20); }

        .arac-bar { display: flex; gap: 10px; align-items: center; margin-bottom: 18px; flex-wrap: wrap; }
        .arama-wrap { position: relative; flex: 1 1 240px; }
        .arama-wrap i { position: absolute; top: 50%; left: 13px; transform: translateY(-50%); color: var(--text-3); font-size: 13px; pointer-events: none; }
        .arama-input { width: 100%; padding: 10px 13px 10px 36px; font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 14px; background: #fff; border: 1px solid var(--border); border-radius: 10px; outline: none; transition: all 0.2s ease; }
        .arama-input:focus { border-color: rgba(239, 68, 68, 0.5); box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1); }
        .arac-select { padding: 10px 13px; font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 14px; background: #fff; border: 1px solid var(--border); border-radius: 10px; outline: none; cursor: pointer; }
        .arac-select:focus { border-color: rgba(239, 68, 68, 0.5); }
        .gorunum-toggle { display: inline-flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; background: #fff; }
        .gorunum-toggle a { display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; font-size: 13px; font-weight: 600; color: var(--text-2); text-decoration: none; transition: all 0.18s ease; }
        .gorunum-toggle a.aktif { background: var(--red,#ef4444); color: #fff; }

        .metrik-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 12px; margin-bottom: 22px; }
        .metrik { background: rgba(255,255,255,0.92); border: 1px solid rgba(248, 113, 113, 0.18); border-radius: 14px; padding: 14px 16px; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 16px rgba(0,0,0,0.04); text-decoration: none; display: block; }
        .metrik:hover { transform: translateY(-2px); border-color: rgba(239, 68, 68, 0.35); }
        .metrik.aktif { border-color: var(--red,#ef4444); box-shadow: 0 6px 16px rgba(239, 68, 68, 0.14); }
        .metrik .m-label { font-size: 12px; font-weight: 600; color: var(--text-2); text-transform: uppercase; letter-spacing: 0.4px; }
        .metrik .m-num { margin-top: 4px; font-size: 26px; font-weight: 700; line-height: 1.1; color: var(--text-1); }
        .metrik.geciken .m-num, .metrik.geciken .m-label { color: var(--red); }

        .gorev-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); gap: 16px; }
        .gorev-card { background: rgba(255,255,255,0.94); border: 1px solid rgba(248, 113, 113, 0.18); border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); padding: 16px 18px; cursor: pointer; display: flex; flex-direction: column; gap: 10px; transition: box-shadow 0.25s ease, transform 0.25s ease, border-color 0.25s ease; animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both; }
        .gorev-card:hover { transform: translateY(-2px); box-shadow: 0 12px 28px rgba(239, 68, 68, 0.10); border-color: rgba(239, 68, 68, 0.35); }
        .gorev-card.bitmis { opacity: 0.62; }
        .gorev-card.bitmis:hover { opacity: 1; }
        .gorev-card.suruklenuyor { opacity: 0.4; }

        .gc-top { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .durum-badge, .kategori-badge { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 8px; }
        .vade { margin-left: auto; display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-2); }
        .vade i { font-size: 11px; }
        .vade.gecikti { color: var(--red); font-weight: 600; }
        .vade.bugun { color: #b45309; font-weight: 600; }

        .gc-baslik { font-size: 15px; font-weight: 600; line-height: 1.35; }
        .gc-aciklama { font-size: 13px; color: var(--text-2); line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .gc-alt { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text-2); }
        .gc-alt i { color: var(--text-3); }
        .gc-alt-bar { flex: 1; height: 6px; background: #f1f1f1; border-radius: 4px; overflow: hidden; }
        .gc-alt-dolu { height: 100%; background: var(--emerald); border-radius: 4px; }

        .gc-foot { display: flex; align-items: center; gap: 10px; margin-top: 2px; padding-top: 12px; border-top: 1px solid var(--border); }
        .kisi { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
        .avatar { flex-shrink: 0; width: 28px; height: 28px; border-radius: 50%; background: var(--red-soft); color: var(--red); display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; }
        .kisi .ad { font-size: 12px; color: var(--text-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
        .oncelik-nokta { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .gc-actions { margin-left: auto; display: inline-flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .ikon-sayi { font-size: 12px; color: var(--text-3); }
        .ikon-sayi i { font-size: 12px; margin-right: 2px; }

        .durum-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 9px; font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: #fff; color: var(--text-1); transition: all 0.18s ease; }
        .durum-btn:hover { border-color: rgba(239,68,68,0.4); color: var(--red); }
        .durum-btn.ileri { background: var(--red,#ef4444); color: #fff; border-color: var(--red,#ef4444); }
        .durum-btn.ileri:hover { background: var(--red); }
        .durum-btn.bitir { background: var(--emerald); color: #fff; border-color: var(--emerald); }
        .durum-btn:disabled { opacity: 0.6; cursor: default; }

        /* Kanban */
        .kanban-board { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; align-items: start; }
        .kanban-sutun { background: rgba(243,244,246,0.7); border: 1px solid var(--border); border-radius: 14px; padding: 12px; min-height: 140px; transition: background 0.18s ease, border-color 0.18s ease; }
        .kanban-sutun.uzerinde { background: var(--red-soft); border-color: rgba(239,68,68,0.4); }
        .kanban-hayalet { opacity: 0.45; background: var(--red-soft) !important; border-style: dashed !important; }
        .kanban-surukle .gorev-card { cursor: grab; }
        .kanban-surukle .gorev-card:active { cursor: grabbing; }
        .kanban-baslik { display: flex; align-items: center; justify-content: space-between; font-size: 13px; font-weight: 700; color: var(--text-2); padding: 2px 6px 12px; }
        .kanban-baslik .say { background: #fff; border: 1px solid var(--border); border-radius: 10px; padding: 0 8px; font-size: 12px; }
        .kanban-sutun .gorev-card { margin-bottom: 12px; }
        .kanban-bos { font-size: 12px; color: var(--text-3); text-align: center; padding: 16px 0; }

        .empty-state { grid-column: 1 / -1; background: rgba(255,255,255,0.92); border: 1px dashed rgba(248, 113, 113, 0.30); border-radius: 16px; padding: 48px 24px; text-align: center; }
        .empty-state .big-icon { width: 64px; height: 64px; margin: 0 auto 14px; border-radius: 16px; background: var(--red-soft); color: var(--red); display: flex; align-items: center; justify-content: center; font-size: 24px; }
        .empty-state .title { font-size: 15px; font-weight: 700; }
        .empty-state .desc { margin-top: 4px; font-size: 13px; color: var(--text-2); }

        /* Modal */
        .modal-overlay { position: fixed; inset: 0; z-index: 60; background: rgba(17, 24, 39, 0.5); backdrop-filter: blur(2px); display: none; align-items: flex-start; justify-content: center; padding: 30px 16px; overflow-y: auto; }
        .modal-overlay.acik { display: flex; }
        .modal-box { background: #fff; border-radius: 18px; width: 100%; max-width: 560px; box-shadow: 0 24px 60px rgba(0,0,0,0.25); animation: cardIn 0.3s cubic-bezier(0.22, 1, 0.36, 1) both; margin: auto 0; }
        .modal-head { display: flex; align-items: center; gap: 10px; padding: 18px 22px; border-bottom: 1px solid var(--border); }
        .modal-head .mh-title { font-size: 16px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px; }
        .modal-head .mh-title i { color: var(--red,#ef4444); }
        .modal-close { margin-left: auto; width: 34px; height: 34px; border-radius: 9px; border: none; background: transparent; color: var(--text-2); cursor: pointer; font-size: 16px; transition: all 0.18s ease; }
        .modal-close:hover { background: var(--red-soft); color: var(--red); }
        .modal-body { padding: 20px 22px; }

        .form-label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-2); margin: 14px 0 6px; }
        .form-label:first-child { margin-top: 0; }
        .form-label .req { color: var(--red); }
        .form-ctrl { width: 100%; padding: 11px 13px; font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 14px; color: var(--text-1); background: #fff; border: 1px solid var(--border); border-radius: 10px; outline: none; transition: all 0.2s ease; }
        .form-ctrl:focus { border-color: rgba(239, 68, 68, 0.5); box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1); }
        textarea.form-ctrl { resize: vertical; min-height: 84px; }
        .form-row { display: flex; gap: 12px; flex-wrap: wrap; }
        .form-row > div { flex: 1 1 150px; }
        .oncelik-secim { display: flex; gap: 8px; }
        .oncelik-secim label { flex: 1; }
        .oncelik-secim input { position: absolute; opacity: 0; pointer-events: none; }
        .oncelik-secim .opt { display: block; text-align: center; padding: 9px; border: 1px solid var(--border); border-radius: 9px; font-size: 13px; font-weight: 600; color: var(--text-2); cursor: pointer; transition: all 0.18s ease; }
        .oncelik-secim input:checked + .opt { border-color: var(--red,#ef4444); background: var(--red-soft); color: var(--red); }
        .kendi-not { background: var(--red-soft); border-radius: 10px; padding: 11px 14px; font-size: 13px; color: var(--text-2); display: flex; align-items: center; gap: 8px; }
        .kendi-not i { color: var(--red); }
        .modal-foot { display: flex; gap: 10px; margin-top: 22px; }
        .btn-modal { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 11px; border-radius: 10px; font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 14px; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: #fff; color: var(--text-1); transition: all 0.2s ease; }
        .btn-modal:hover { background: #f9fafb; }
        .btn-modal.primary { background: var(--red,#ef4444); color: #fff; border-color: var(--red,#ef4444); }
        .btn-modal.primary:hover { background: var(--red); }
        .btn-modal:disabled { opacity: 0.6; cursor: default; }

        .detay-yukleniyor { text-align: center; padding: 40px; color: var(--text-3); }
        .detay-ust { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; flex-wrap: wrap; }
        .oncelik { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 500; }
        .oncelik i { font-size: 11px; }
        .detay-baslik { font-size: 18px; font-weight: 700; line-height: 1.35; margin-bottom: 8px; }
        .detay-aciklama { font-size: 14px; color: var(--text-2); line-height: 1.6; margin-bottom: 14px; }
        .ret-kutu { background: var(--red-soft); border: 1px solid rgba(111,16,34,0.2); color: var(--red); border-radius: 10px; padding: 10px 14px; font-size: 13px; margin-bottom: 14px; }
        .ret-kutu i { margin-right: 4px; }
        .detay-meta { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 18px; }
        .detay-meta > div { background: #fafafa; border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
        .detay-meta span { display: block; font-size: 11px; color: var(--text-3); margin-bottom: 3px; }
        .detay-meta span i { margin-right: 4px; }
        .detay-meta b { font-size: 13px; font-weight: 600; }
        .detay-aksiyon { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 8px; }
        .detay-bolum-baslik { font-size: 13px; font-weight: 700; color: var(--text-2); margin: 18px 0 10px; padding-bottom: 6px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 6px; }
        .detay-bolum-baslik i { color: var(--red,#ef4444); }
        .alt-sayac { margin-left: auto; font-size: 12px; font-weight: 600; color: var(--emerald); }
        .alt-bar { height: 7px; background: #f1f1f1; border-radius: 4px; overflow: hidden; margin-bottom: 10px; }
        .alt-bar-dolu { height: 100%; background: var(--emerald); border-radius: 4px; transition: width 0.3s ease; }
        .alt-liste { display: flex; flex-direction: column; gap: 6px; margin-bottom: 10px; }
        .alt-item { display: flex; align-items: center; gap: 8px; }
        .alt-check { display: flex; align-items: center; gap: 9px; flex: 1; cursor: pointer; font-size: 13.5px; }
        .alt-check input { width: 17px; height: 17px; accent-color: var(--emerald); cursor: pointer; flex-shrink: 0; }
        .alt-item.tamam .alt-check span { text-decoration: line-through; color: var(--text-3); }
        .alt-sil { border: none; background: transparent; color: var(--text-3); cursor: pointer; font-size: 13px; padding: 4px 6px; border-radius: 6px; }
        .alt-sil:hover { background: var(--red-soft); color: var(--red); }
        .alt-ekle-form, .yorum-form { display: flex; gap: 8px; margin-top: 8px; }
        .alt-ekle-form .form-ctrl, .yorum-form .form-ctrl { flex: 1; }
        .ek-liste { display: flex; flex-direction: column; gap: 6px; }
        .ek-item { display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #fafafa; border: 1px solid var(--border); border-radius: 9px; text-decoration: none; color: var(--text-1); font-size: 13px; transition: all 0.18s ease; }
        .ek-item:hover { border-color: rgba(239,68,68,0.4); color: var(--red); }
        .ek-item i { color: var(--red,#ef4444); flex-shrink: 0; }
        .ek-ad { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ek-bos { font-size: 13px; color: var(--text-3); }
        .ek-yukle-alan { display: inline-flex; align-items: center; gap: 8px; margin-top: 10px; padding: 9px 14px; border: 1px dashed rgba(239,68,68,0.4); border-radius: 9px; color: var(--red); font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.18s ease; }
        .ek-yukle-alan:hover { background: var(--red-soft); }
        .timeline { display: flex; flex-direction: column; gap: 14px; margin-bottom: 16px; }
        .tl-item { display: flex; gap: 12px; }
        .tl-ikon { flex-shrink: 0; width: 30px; height: 30px; border-radius: 50%; background: var(--red-soft); color: var(--red); display: flex; align-items: center; justify-content: center; font-size: 12px; }
        .tl-icerik { flex: 1; font-size: 13px; line-height: 1.5; min-width: 0; word-break: break-word; }
        .tl-zaman { font-size: 11px; color: var(--text-3); margin-top: 2px; }
        .btn-yorum { flex-shrink: 0; width: 44px; border: none; border-radius: 10px; background: var(--red,#ef4444); color: #fff; cursor: pointer; font-size: 15px; transition: all 0.2s ease; }
        .btn-yorum:hover { background: var(--red); }

        @keyframes cardIn { from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); } to { opacity: 1; transform: none; } }

        @media (max-width: 900px) { .kanban-board { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 767px) {
            .top-header { height: 50px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            .btn-yeni { padding: 8px 12px; font-size: 12.5px; }
            .btn-yeni span { display: none; }
            main { padding: 14px 12px 40px; }
            .gorev-grid { grid-template-columns: 1fr; gap: 14px; }
            .metrik-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .kanban-board { grid-template-columns: 1fr; }
            .gorev-card:hover { transform: none; }
            .modal-overlay { padding: 0; }
            .modal-box { max-width: 100%; min-height: 100%; border-radius: 0; }
            .detay-meta { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Geri"><i class="fa fa-arrow-left"></i></a>
            <div class="header-divider"></div>
            <span class="header-title"><i class="fa-solid fa-clipboard-list"></i>Görevler</span>
            <div class="header-spacer"></div>
            <?php if ($yonetici): ?>
                <a href="gorev_rapor.php" class="btn-yeni" style="background:#fff;color:var(--text-1);border:1px solid var(--border);margin-right:8px;"><i class="fa-solid fa-chart-column"></i><span>Rapor</span></a>
            <?php endif; ?>
            <button type="button" class="btn-yeni" onclick="yeniGorevAc()"><i class="fa-solid fa-plus"></i><span>Yeni Görev</span></button>
        </div>
    </header>

    <main>
        <div class="sekme-bar">
            <a href="<?php echo $h($gorevUrl(['sekme' => 'bana', 'f' => 'hepsi'])); ?>" class="sekme <?php echo $sekme === 'bana' ? 'aktif' : ''; ?>"><i class="fa-solid fa-inbox"></i> Bana atananlar</a>
            <?php if ($atamaYetkisi): ?>
                <a href="<?php echo $h($gorevUrl(['sekme' => 'atadiklarim', 'f' => 'hepsi'])); ?>" class="sekme <?php echo $sekme === 'atadiklarim' ? 'aktif' : ''; ?>"><i class="fa-solid fa-paper-plane"></i> Atadıklarım</a>
            <?php endif; ?>
            <?php if ($yonetici): ?>
                <a href="<?php echo $h($gorevUrl(['sekme' => 'tum', 'f' => 'hepsi'])); ?>" class="sekme <?php echo $sekme === 'tum' ? 'aktif' : ''; ?>"><i class="fa-solid fa-users"></i> Tüm görevler</a>
            <?php endif; ?>
        </div>

        <form class="arac-bar" method="get" action="gorevler.php">
            <input type="hidden" name="sekme" value="<?php echo $h($sekme); ?>">
            <input type="hidden" name="gorunum" value="<?php echo $h($gorunum); ?>">
            <input type="hidden" name="f" value="<?php echo $h($f); ?>">
            <div class="arama-wrap">
                <i class="fa fa-search"></i>
                <input type="text" name="q" class="arama-input" placeholder="Görevlerde ara..." value="<?php echo $h($fArama); ?>">
            </div>
            <select name="kategori" class="arac-select" onchange="this.form.submit()">
                <option value="">Tüm kategoriler</option>
                <?php foreach (gorev_kategori_listesi() as $kk => $kv): ?>
                    <option value="<?php echo $kk; ?>" <?php echo $fKategori === $kk ? 'selected' : ''; ?>><?php echo $h($kv); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="gorunum-toggle">
                <a href="<?php echo $h($gorevUrl(['gorunum' => 'liste'])); ?>" class="<?php echo $gorunum === 'liste' ? 'aktif' : ''; ?>"><i class="fa-solid fa-list"></i> Liste</a>
                <a href="<?php echo $h($gorevUrl(['gorunum' => 'kanban'])); ?>" class="<?php echo $gorunum === 'kanban' ? 'aktif' : ''; ?>"><i class="fa-solid fa-table-columns"></i> Kanban</a>
            </div>
        </form>

        <?php if ($sekme !== 'tum'): ?>
        <div class="metrik-grid">
            <a href="<?php echo $h($gorevUrl(['f' => 'hepsi'])); ?>" class="metrik <?php echo $f === 'hepsi' ? 'aktif' : ''; ?>"><div class="m-label">Tümü</div><div class="m-num"><?php echo $sayac['hepsi']; ?></div></a>
            <a href="<?php echo $h($gorevUrl(['f' => 'yeni'])); ?>" class="metrik <?php echo $f === 'yeni' ? 'aktif' : ''; ?>"><div class="m-label">Yeni</div><div class="m-num"><?php echo $sayac['yeni']; ?></div></a>
            <a href="<?php echo $h($gorevUrl(['f' => 'devam'])); ?>" class="metrik <?php echo $f === 'devam' ? 'aktif' : ''; ?>"><div class="m-label">Devam eden</div><div class="m-num"><?php echo $sayac['devam']; ?></div></a>
            <a href="<?php echo $h($gorevUrl(['f' => 'geciken'])); ?>" class="metrik geciken <?php echo $f === 'geciken' ? 'aktif' : ''; ?>"><div class="m-label">Geciken</div><div class="m-num"><?php echo $sayac['geciken']; ?></div></a>
            <a href="<?php echo $h($gorevUrl(['f' => 'tamam'])); ?>" class="metrik <?php echo $f === 'tamam' ? 'aktif' : ''; ?>"><div class="m-label">Tamamlanan</div><div class="m-num"><?php echo $sayac['tamam']; ?></div></a>
        </div>
        <?php endif; ?>

        <?php if (empty($gorevler)): ?>
            <div class="gorev-grid">
                <div class="empty-state">
                    <div class="big-icon"><i class="fa-solid fa-clipboard-check"></i></div>
                    <div class="title">Görev bulunamadı</div>
                    <div class="desc"><?php echo $fArama !== '' || $fKategori !== null || $f !== 'hepsi' ? 'Filtreyi değiştirip tekrar deneyin.' : ($sekme === 'atadiklarim' ? 'Yeni Görev butonuyla ilk görevi oluşturun.' : 'Size atanan görevler burada görünecek.'); ?></div>
                </div>
            </div>
        <?php elseif ($gorunum === 'kanban'): ?>
            <div class="kanban-board" id="kanbanBoard">
                <?php foreach ($kanbanSutunlar as $sut):
                    $sutGorevler = array_filter($gorevler, static fn($g) => in_array((int) $g['DURUM'], $sut['durumlar'], true));
                ?>
                    <div class="kanban-sutun" <?php echo $sut['hedef'] !== null ? 'data-hedef-durum="' . $sut['hedef'] . '"' : ''; ?>>
                        <div class="kanban-baslik"><?php echo $h($sut['baslik']); ?> <span class="say"><?php echo count($sutGorevler); ?></span></div>
                        <?php if (empty($sutGorevler)): ?>
                            <div class="kanban-bos">—</div>
                        <?php else: ?>
                            <?php foreach ($sutGorevler as $g) {
                                echo $kartHtml($g);
                            } ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="gorev-grid">
                <?php foreach ($gorevler as $g) {
                    echo $kartHtml($g);
                } ?>
            </div>
        <?php endif; ?>
    </main>

    <div class="modal-overlay" id="yeniGorevModal">
        <div class="modal-box">
            <div class="modal-head">
                <span class="mh-title"><i class="fa-solid fa-plus"></i>Yeni Görev</span>
                <button type="button" class="modal-close" onclick="yeniGorevKapat()"><i class="fa fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                <form id="yeniGorevForm" onsubmit="return yeniGorevKaydet(event)">
                    <?php if ($atamaYetkisi): ?>
                        <label class="form-label">Kime <span class="req">*</span></label>
                        <select class="form-ctrl" name="atanan_id" required>
                            <option value="<?php echo $benimId; ?>">Kendim (<?php echo $h($kendiAd); ?>)</option>
                            <?php foreach ($kullanicilar as $k): if ((int) $k['LOGICALREF'] === $benimId) {
                                continue;
                            } ?>
                                <option value="<?php echo (int) $k['LOGICALREF']; ?>"><?php echo $h(trim((string) $k['DEFINITION_']) ?: trim((string) $k['CODE'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="atanan_id" value="<?php echo $benimId; ?>">
                        <div class="kendi-not"><i class="fa-solid fa-circle-user"></i> Bu görev kendi listenize (<?php echo $h($kendiAd); ?>) eklenecek.</div>
                    <?php endif; ?>

                    <label class="form-label">Başlık <span class="req">*</span></label>
                    <input type="text" class="form-ctrl" name="baslik" maxlength="200" required placeholder="Örn: Depo sayımı yapılacak">

                    <label class="form-label">Açıklama</label>
                    <textarea class="form-ctrl" name="aciklama" maxlength="4000" placeholder="Görevin detayları..."></textarea>

                    <div class="form-row">
                        <div>
                            <label class="form-label">Kategori</label>
                            <select class="form-ctrl" name="kategori">
                                <?php foreach (gorev_kategori_listesi() as $kk => $kv): ?>
                                    <option value="<?php echo $kk; ?>"><?php echo $h($kv); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label">Vade tarihi</label>
                            <input type="date" class="form-ctrl" name="vade">
                        </div>
                    </div>

                    <label class="form-label">Öncelik</label>
                    <div class="oncelik-secim">
                        <label><input type="radio" name="oncelik" value="1"><span class="opt">Düşük</span></label>
                        <label><input type="radio" name="oncelik" value="2" checked><span class="opt">Normal</span></label>
                        <label><input type="radio" name="oncelik" value="3"><span class="opt">Yüksek</span></label>
                    </div>

                    <label class="form-label">Ekler (görsel / dosya)</label>
                    <label class="ek-yukle-alan" style="margin-top:0;">
                        <i class="fa-solid fa-upload"></i> <span id="yeniGorevEkLabel">Görsel veya dosya seç</span>
                        <input type="file" id="yeniGorevDosya" multiple accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt" hidden>
                    </label>

                    <div class="modal-foot">
                        <button type="button" class="btn-modal" onclick="yeniGorevKapat()">İptal</button>
                        <button type="submit" class="btn-modal primary" id="yeniGorevBtn"><i class="fa-solid fa-paper-plane"></i>Görevi oluştur</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="detayModal">
        <div class="modal-box">
            <div class="modal-head">
                <span class="mh-title"><i class="fa-solid fa-clipboard-list"></i>Görev Detayı</span>
                <button type="button" class="modal-close" onclick="detayKapat()"><i class="fa fa-xmark"></i></button>
            </div>
            <div class="modal-body" id="detayBody">
                <div class="detay-yukleniyor"><i class="fa-solid fa-spinner fa-spin"></i> Yükleniyor...</div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.15.6/Sortable.min.js"></script>
    <script>
        const CSRF_TOKEN = <?php echo json_encode($csrf); ?>;
        const SEKME = <?php echo json_encode($sekme); ?>;

        function gorevApi(islem, data) {
            const fd = new FormData();
            fd.append('islem', islem);
            fd.append('csrf_token', CSRF_TOKEN);
            for (const k in data) { fd.append(k, data[k]); }
            return fetch('gorev_islem.php', { method: 'POST', body: fd }).then(r => r.json());
        }

        function durumGuncelle(ev, id, durum) {
            ev.stopPropagation();
            const btn = ev.currentTarget;
            btn.disabled = true;
            gorevApi('durum', { id: id, durum: durum }).then(d => {
                if (d.ok) { location.reload(); }
                else { if (window.toast) { toast(d.mesaj || 'İşlem başarısız.', 'error'); } else { alert(d.mesaj || 'İşlem başarısız.'); } btn.disabled = false; }
            }).catch(() => { if (window.toast) { toast('Bağlantı hatası.', 'error'); } else { alert('Bağlantı hatası.'); } btn.disabled = false; });
        }

        /* Yeni gorev */
        function yeniGorevAc() { document.getElementById('yeniGorevModal').classList.add('acik'); }
        function yeniGorevKapat() { document.getElementById('yeniGorevModal').classList.remove('acik'); }
        const yeniGorevDosyaInp = document.getElementById('yeniGorevDosya');
        if (yeniGorevDosyaInp) {
            yeniGorevDosyaInp.addEventListener('change', function () {
                document.getElementById('yeniGorevEkLabel').textContent =
                    this.files.length ? (this.files.length + ' dosya seçildi') : 'Görsel veya dosya seç';
            });
        }
        function yeniGorevEkleriYukle(gorevId, dosyalar) {
            const yukle = (i) => {
                if (i >= dosyalar.length) return Promise.resolve();
                const fd = new FormData();
                fd.append('gorev_id', gorevId);
                fd.append('csrf_token', CSRF_TOKEN);
                fd.append('dosya', dosyalar[i]);
                return fetch('gorev_ek_yukle.php', { method: 'POST', body: fd })
                    .then(r => r.json()).then(() => yukle(i + 1)).catch(() => yukle(i + 1));
            };
            return yukle(0);
        }
        function yeniGorevKaydet(ev) {
            ev.preventDefault();
            const form = ev.target;
            const btn = document.getElementById('yeniGorevBtn');
            btn.disabled = true;
            gorevApi('olustur', {
                atanan_id: form.atanan_id.value,
                baslik: form.baslik.value,
                aciklama: form.aciklama.value,
                kategori: form.kategori.value,
                oncelik: form.oncelik.value,
                vade: form.vade.value
            }).then(d => {
                if (!d.ok) { if (window.toast) { toast(d.mesaj || 'Görev oluşturulamadı.', 'error'); } else { alert(d.mesaj || 'Görev oluşturulamadı.'); } btn.disabled = false; return; }
                const dosyalar = yeniGorevDosyaInp ? yeniGorevDosyaInp.files : null;
                if (dosyalar && dosyalar.length) {
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Dosyalar yükleniyor...';
                    yeniGorevEkleriYukle(d.id, dosyalar).then(() => location.reload());
                } else {
                    location.reload();
                }
            }).catch(() => { if (window.toast) { toast('Bağlantı hatası.', 'error'); } else { alert('Bağlantı hatası.'); } btn.disabled = false; });
            return false;
        }

        /* Detay */
        function gorevAc(id) {
            const body = document.getElementById('detayBody');
            body.innerHTML = '<div class="detay-yukleniyor"><i class="fa-solid fa-spinner fa-spin"></i> Yükleniyor...</div>';
            document.getElementById('detayModal').classList.add('acik');
            fetch('gorev_islem.php?islem=detay&id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(r => r.text()).then(html => { body.innerHTML = html; })
                .catch(() => { body.innerHTML = '<div class="detay-yukleniyor">Detay yüklenemedi.</div>'; });
        }
        function detayKapat() { document.getElementById('detayModal').classList.remove('acik'); }

        const detayBody = document.getElementById('detayBody');
        detayBody.addEventListener('click', function (ev) {
            const dbtn = ev.target.closest('[data-durum-btn]');
            if (dbtn) { dbtn.disabled = true; gorevApi('durum', { id: dbtn.dataset.id, durum: dbtn.dataset.durumBtn }).then(d => { d.ok ? gorevAc(dbtn.dataset.id) : ((window.toast ? toast(d.mesaj || 'Hata', 'error') : alert(d.mesaj || 'Hata')), dbtn.disabled = false); }); return; }
            const onayB = ev.target.closest('[data-onay]');
            if (onayB) { onayB.disabled = true; gorevApi('onay', { id: onayB.dataset.onay }).then(d => { d.ok ? location.reload() : ((window.toast ? toast(d.mesaj || 'Hata', 'error') : alert(d.mesaj || 'Hata')), onayB.disabled = false); }); return; }
            const geriB = ev.target.closest('[data-geriac]');
            if (geriB) { if (!confirm('Görev geri açılsın mı?')) return; geriB.disabled = true; gorevApi('geri_ac', { id: geriB.dataset.geriac }).then(d => { d.ok ? location.reload() : ((window.toast ? toast(d.mesaj || 'Hata', 'error') : alert(d.mesaj || 'Hata')), geriB.disabled = false); }); return; }
            const rbtn = ev.target.closest('[data-reddet]');
            if (rbtn) { const s = prompt('Reddetme sebebi:'); if (s === null) return; if (s.trim() === '') { if (window.toast) { toast('Sebep girmelisiniz.', 'warning'); } else { alert('Sebep girmelisiniz.'); } return; } gorevApi('reddet', { id: rbtn.dataset.reddet, sebep: s }).then(d => { d.ok ? gorevAc(rbtn.dataset.reddet) : (window.toast ? toast(d.mesaj || 'Hata', 'error') : alert(d.mesaj || 'Hata')); }); return; }
            const ibtn = ev.target.closest('[data-iptal]');
            if (ibtn) { if (!confirm('Bu görevi iptal etmek istediğinize emin misiniz?')) return; gorevApi('iptal', { id: ibtn.dataset.iptal }).then(d => { d.ok ? location.reload() : (window.toast ? toast(d.mesaj || 'Hata', 'error') : alert(d.mesaj || 'Hata')); }); return; }
            const asil = ev.target.closest('[data-alt-sil]');
            if (asil) { gorevApi('altgorev_sil', { alt_id: asil.dataset.altSil }).then(d => { d.ok ? gorevAc(currentGorevId()) : (window.toast ? toast(d.mesaj || 'Hata', 'error') : alert(d.mesaj || 'Hata')); }); return; }
        });
        detayBody.addEventListener('change', function (ev) {
            const tik = ev.target.closest('[data-alt-tik]');
            if (tik) { gorevApi('altgorev_tikle', { alt_id: tik.dataset.altTik, tamam: tik.checked ? 1 : 0 }).then(d => { if (d.ok) gorevAc(currentGorevId()); else { if (window.toast) { toast(d.mesaj || 'Hata', 'error'); } else { alert(d.mesaj || 'Hata'); } tik.checked = !tik.checked; } }); return; }
            const inp = ev.target.closest('[data-ek-input]');
            if (inp && inp.files && inp.files.length) {
                const id = inp.dataset.ekInput;
                const alan = inp.closest('.ek-yukle-alan'); const eski = alan ? alan.innerHTML : '';
                if (alan) alan.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Yükleniyor...';
                const fd = new FormData(); fd.append('gorev_id', id); fd.append('csrf_token', CSRF_TOKEN); fd.append('dosya', inp.files[0]);
                fetch('gorev_ek_yukle.php', { method: 'POST', body: fd }).then(r => r.json()).then(d => { d.ok ? gorevAc(id) : ((window.toast ? toast(d.mesaj || 'Yüklenemedi.', 'error') : alert(d.mesaj || 'Yüklenemedi.')), alan && (alan.innerHTML = eski)); }).catch(() => { if (window.toast) { toast('Bağlantı hatası.', 'error'); } else { alert('Bağlantı hatası.'); } if (alan) alan.innerHTML = eski; });
            }
        });
        detayBody.addEventListener('submit', function (ev) {
            const yf = ev.target.closest('[data-yorum-form]');
            if (yf) { ev.preventDefault(); const inp = yf.querySelector('[name=mesaj]'); const m = inp.value.trim(); if (m === '') return; gorevApi('yorum', { id: yf.dataset.yorumForm, mesaj: m }).then(d => { d.ok ? gorevAc(yf.dataset.yorumForm) : (window.toast ? toast(d.mesaj || 'Hata', 'error') : alert(d.mesaj || 'Hata')); }); return; }
            const af = ev.target.closest('[data-alt-form]');
            if (af) { ev.preventDefault(); const inp = af.querySelector('[name=metin]'); const m = inp.value.trim(); if (m === '') return; gorevApi('altgorev_ekle', { id: af.dataset.altForm, metin: m }).then(d => { d.ok ? gorevAc(af.dataset.altForm) : (window.toast ? toast(d.mesaj || 'Hata', 'error') : alert(d.mesaj || 'Hata')); }); return; }
        });
        function currentGorevId() {
            const el = detayBody.querySelector('[data-yorum-form]');
            return el ? el.dataset.yorumForm : 0;
        }

        /* Kanban surukle-birak — SortableJS (masaustu + dokunmatik), yalnizca bana atananlar */
        if (SEKME === 'bana' && typeof Sortable !== 'undefined') {
            const board = document.getElementById('kanbanBoard');
            if (board) { board.classList.add('kanban-surukle'); }
            document.querySelectorAll('.kanban-sutun[data-hedef-durum]').forEach(s => {
                new Sortable(s, {
                    group: 'kanban',
                    animation: 160,
                    draggable: '.gorev-card',
                    ghostClass: 'kanban-hayalet',
                    dragClass: 'suruklenuyor',
                    delay: 150,
                    delayOnTouchOnly: true,
                    onEnd: function (evt) {
                        if (evt.from === evt.to) { return; }
                        const id = evt.item.dataset.id;
                        const durum = evt.to.dataset.hedefDurum;
                        evt.item.style.pointerEvents = 'none';
                        gorevApi('durum', { id: id, durum: durum }).then(d => {
                            if (d.ok) { kanbanSayilariGuncelle(); evt.item.style.pointerEvents = ''; }
                            else { if (window.toast) { toast(d.mesaj || 'İşlem başarısız.', 'error'); } else { alert(d.mesaj || 'İşlem başarısız.'); } location.reload(); }
                        }).catch(() => location.reload());
                    }
                });
            });
        }
        function kanbanSayilariGuncelle() {
            document.querySelectorAll('.kanban-sutun').forEach(s => {
                const el = s.querySelector('.kanban-baslik .say');
                if (el) { el.textContent = s.querySelectorAll('.gorev-card').length; }
            });
        }

        document.querySelectorAll('.modal-overlay').forEach(ov => {
            ov.addEventListener('click', e => { if (e.target === ov) ov.classList.remove('acik'); });
        });
    </script>
    <?php include_once(__DIR__ . '/ux_katman.php'); ?>
</body>
</html>

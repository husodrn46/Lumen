<?php
/**
 * sistem_sekmeler.php — "Sistem Ayarları" ekranının ortak üst çubuğu + sekme şeridi.
 *
 * Dahil eden sayfa, include'dan ÖNCE $saAktifSekme değişkenini tanımlar:
 *   'genel' | 'iskonto' | 'marka'
 * Üç sayfa da (sistem_ayarlari.php, hizli_iskonto.php, marka.php) aynı üst çubuğu
 * paylaşır → ayar panelinde tek "Sistem Ayarları" girişi altında toplanır.
 *
 * Stiller kendi içinde (sa- ön ekli) ve CSS değişkenlerine yedekli; dahil eden
 * sayfa değişkenleri tanımlamasa da tutarlı görünür.
 */
$saAktifSekme = $saAktifSekme ?? 'genel';
$sa_sekmeler = [
    ['id' => 'genel',   'dosya' => 'sistem_ayarlari.php', 'ikon' => 'fa-sliders', 'ad' => 'Genel'],
    ['id' => 'iskonto', 'dosya' => 'hizli_iskonto.php',   'ikon' => 'fa-percent', 'ad' => 'Hızlı İskonto'],
    ['id' => 'marka',   'dosya' => 'marka.php',           'ikon' => 'fa-palette', 'ad' => 'Marka / Logo'],
];
?>
<style>
.sa-topbar{ position:sticky; top:0; z-index:45; background:rgba(255,255,255,.93); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); border-bottom:1px solid var(--border,#e5e7eb); box-shadow:0 2px 10px rgba(111,16,34,.04); }
.sa-topbar-in{ max-width:1080px; margin:0 auto; padding:0 20px; display:flex; align-items:center; gap:13px; height:58px; }
.sa-back{ width:36px; height:36px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; color:var(--text-2,#6b7280); text-decoration:none; border:1px solid var(--border,#e5e7eb); background:#fff; flex-shrink:0; transition:.15s; }
.sa-back:hover{ border-color:var(--red,#6F1022); color:var(--red,#6F1022); }
.sa-topbar .sa-heading{ font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; font-size:16.5px; font-weight:700; color:var(--text-1,#1f2937); display:flex; align-items:center; gap:9px; }
.sa-topbar .sa-heading > i{ color:var(--red,#6F1022); }
.sa-tabsrow{ max-width:1080px; margin:0 auto; padding:0 20px; display:flex; gap:2px; overflow-x:auto; scrollbar-width:none; }
.sa-tabsrow::-webkit-scrollbar{ display:none; }
.sa-tab{ font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; display:inline-flex; align-items:center; gap:8px; padding:12px 18px; font-size:13.5px; font-weight:600; color:var(--text-2,#6b7280); text-decoration:none; border-bottom:2.5px solid transparent; margin-bottom:-1px; transition:.15s; white-space:nowrap; }
.sa-tab:hover{ color:var(--red,#6F1022); }
.sa-tab.aktif{ color:var(--red,#6F1022); border-bottom-color:var(--red,#6F1022); }
.sa-tab i{ font-size:14px; }
@media (max-width:520px){ .sa-tab{ padding:11px 13px; font-size:12.5px; } .sa-topbar .sa-heading span{ display:none; } }
</style>
<header class="sa-topbar">
    <div class="sa-topbar-in">
        <a href="index.php" class="sa-back" title="Ayarlara dön"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="sa-heading"><i class="fa-solid fa-gear"></i> <span>Sistem Ayarları</span></div>
    </div>
    <nav class="sa-tabsrow">
        <?php foreach ($sa_sekmeler as $sa_s):
            if ($sa_s['id'] !== 'genel' && !is_file(__DIR__ . '/' . $sa_s['dosya'])) { continue; }
            $sa_akt = ($sa_s['id'] === $saAktifSekme) ? ' aktif' : ''; ?>
            <a class="sa-tab<?= $sa_akt ?>" href="<?= htmlspecialchars($sa_s['dosya'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="fa-solid <?= $sa_s['ikon'] ?>"></i> <?= htmlspecialchars($sa_s['ad'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </nav>
</header>

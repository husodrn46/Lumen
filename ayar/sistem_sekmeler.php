<?php
/**
 * sistem_sekmeler.php — "Sistem Ayarları" ekranının ortak üst çubuğu + sekme şeridi.
 *
 * Dahil eden sayfa, include'dan ÖNCE $saAktifSekme değişkenini tanımlar:
 *   'genel' | 'iskonto' | 'marka' | 'yedek'
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
    ['id' => 'yedek',   'dosya' => 'sistem_yedek.php',    'ikon' => 'fa-box-archive', 'ad' => 'Sistem ve Yedek'],
];
?>
<style>
.sa-tabsrow{ max-width:var(--sayfa-genislik); margin:0 auto; padding:0 20px; display:flex; gap:2px; overflow-x:auto; scrollbar-width:thin; }
.sa-tab{ font-family: var(--font); letter-spacing:-0.02em; display:inline-flex; align-items:center; gap:8px; padding:12px 18px; font-size:13.5px; font-weight:600; color:var(--text-2,#6b7280); text-decoration:none; border-bottom:2.5px solid transparent; margin-bottom:-1px; transition:.15s; white-space:nowrap; }
.sa-tab:hover{ color:var(--red,#6F1022); }
.sa-tab.aktif{ color:var(--red,#6F1022); border-bottom-color:var(--red,#6F1022); }
.sa-tab i{ font-size:14px; }
@media (max-width:520px){ .sa-tab{ padding:11px 13px; min-height:44px; box-sizing:border-box; font-size:12.5px; } }
</style>
<header class="sa-topbar top-header">
    <div class="sa-topbar-in header-inner">
        <a href="index.php" class="header-back" title="Ayarlara dön"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="header-title"><i class="fa-solid fa-gear"></i> <span>Sistem Ayarları</span></div>
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
<script>
// Dar ekranda etkin sekme şeridin dışında kalmasın.
(function () { var a = document.querySelector('.sa-tab.aktif'); if (a && a.scrollIntoView) { a.scrollIntoView({ block: 'nearest', inline: 'center' }); } })();
</script>

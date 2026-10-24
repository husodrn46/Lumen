<?php
/**
 * yetki_sekmeler.php — "Kullanıcı & Yetki" ekranının ortak üst çubuğu + sekme şeridi.
 *
 * Dahil eden sayfa, include'dan ÖNCE $aktifSekme değişkenini tanımlar:
 *   'kullanicilar' | 'matris' | 'denetim'
 * Üç sayfa da (kullanici_yetki.php, yetki_matrisi.php, yetki_denetim.php) aynı üst
 * çubuğu paylaşır → kullanıcı için tek "Kullanıcı & Yetki" ekranı hissi verir.
 *
 * Stiller kendi içinde (ky- ön ekli) ve --red gibi CSS değişkenlerine yedekli
 * (fallback) — dahil eden sayfa değişkenleri tanımlamasa da tutarlı görünür.
 */
$aktifSekme = $aktifSekme ?? 'kullanicilar';
$ky_sekmeler = [
    ['id' => 'kullanicilar', 'dosya' => 'kullanici_yetki.php',     'ikon' => 'fa-users',             'ad' => 'Kullanıcılar'],
    ['id' => 'matris',       'dosya' => 'yetki_matrisi.php',       'ikon' => 'fa-table-cells-large', 'ad' => 'İzin Matrisi'],
    ['id' => 'roller',       'dosya' => 'roller.php',              'ikon' => 'fa-user-shield',       'ad' => 'Roller'],
    ['id' => 'ozelcari',     'dosya' => 'ozel_cari_kisitlari.php', 'ikon' => 'fa-user-lock',         'ad' => 'Özel Cari'],
    ['id' => 'denetim',      'dosya' => 'yetki_denetim.php',       'ikon' => 'fa-clipboard-check',   'ad' => 'Denetim'],
];
?>
<style>
.ky-tabsrow{ max-width:var(--sayfa-genislik); margin:0 auto; padding:0 20px; display:flex; gap:2px; overflow-x:auto; scrollbar-width:thin; }
.ky-tab{ font-family: var(--font); letter-spacing:-0.02em; display:inline-flex; align-items:center; gap:8px; padding:12px 18px; font-size:13.5px; font-weight:600; color:var(--text-2,#6b7280); text-decoration:none; border-bottom:2.5px solid transparent; margin-bottom:-1px; transition:.15s; white-space:nowrap; }
.ky-tab:hover{ color:var(--red,#6F1022); }
.ky-tab.aktif{ color:var(--red,#6F1022); border-bottom-color:var(--red,#6F1022); }
.ky-tab i{ font-size:14px; }
@media (max-width:520px){ .ky-tab{ padding:11px 13px; min-height:44px; box-sizing:border-box; font-size:12.5px; } }
</style>
<header class="ky-topbar top-header">
    <div class="ky-topbar-in header-inner">
        <a href="index.php" class="header-back" title="Ayarlara dön"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="header-title"><i class="fa-solid fa-users-gear"></i> <span>Kullanıcı &amp; Yetki</span></div>
    </div>
    <nav class="ky-tabsrow">
        <?php foreach ($ky_sekmeler as $ky_s):
            // Kullanıcılar sekmesi her zaman; matris/denetim yalnız dosya varsa
            if ($ky_s['id'] !== 'kullanicilar' && !is_file(__DIR__ . '/' . $ky_s['dosya'])) { continue; }
            $ky_akt = ($ky_s['id'] === $aktifSekme) ? ' aktif' : ''; ?>
            <a class="ky-tab<?= $ky_akt ?>" href="<?= htmlspecialchars($ky_s['dosya'], ENT_QUOTES, 'UTF-8') ?>">
                <i class="fa-solid <?= $ky_s['ikon'] ?>"></i> <?= htmlspecialchars($ky_s['ad'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endforeach; ?>
    </nav>
</header>

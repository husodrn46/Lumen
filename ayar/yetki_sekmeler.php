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
.ky-topbar{ position:sticky; top:0; z-index:45; background:rgba(255,255,255,.93); backdrop-filter:blur(8px); -webkit-backdrop-filter:blur(8px); border-bottom:1px solid var(--border,#e5e7eb); box-shadow:0 2px 10px rgba(111,16,34,.04); }
.ky-topbar-in{ max-width:1080px; margin:0 auto; padding:0 20px; display:flex; align-items:center; gap:13px; height:58px; }
.ky-back{ width:36px; height:36px; border-radius:10px; display:inline-flex; align-items:center; justify-content:center; color:var(--text-2,#6b7280); text-decoration:none; border:1px solid var(--border,#e5e7eb); background:#fff; flex-shrink:0; transition:.15s; }
.ky-back:hover{ border-color:var(--red,#6F1022); color:var(--red,#6F1022); }
.ky-topbar .ky-heading{ font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; font-size:16.5px; font-weight:700; color:var(--text-1,#1f2937); display:flex; align-items:center; gap:9px; }
.ky-topbar .ky-heading > i{ color:var(--red,#6F1022); }
.ky-tabsrow{ max-width:1080px; margin:0 auto; padding:0 20px; display:flex; gap:2px; overflow-x:auto; scrollbar-width:none; }
.ky-tabsrow::-webkit-scrollbar{ display:none; }
.ky-tab{ font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; display:inline-flex; align-items:center; gap:8px; padding:12px 18px; font-size:13.5px; font-weight:600; color:var(--text-2,#6b7280); text-decoration:none; border-bottom:2.5px solid transparent; margin-bottom:-1px; transition:.15s; white-space:nowrap; }
.ky-tab:hover{ color:var(--red,#6F1022); }
.ky-tab.aktif{ color:var(--red,#6F1022); border-bottom-color:var(--red,#6F1022); }
.ky-tab i{ font-size:14px; }
@media (max-width:520px){ .ky-tab{ padding:11px 13px; font-size:12.5px; } .ky-topbar .ky-heading span{ display:none; } }
</style>
<header class="ky-topbar">
    <div class="ky-topbar-in">
        <a href="index.php" class="ky-back" title="Ayarlara dön"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="ky-heading"><i class="fa-solid fa-users-gear"></i> <span>Kullanıcı &amp; Yetki</span></div>
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

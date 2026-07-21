<?php
/**
 * siparis_sekmeler.php — Sipariş listesi ekranlarının ortak sekme şeridi.
 *
 * lg_essiparis.php (Açık Siparişler) ve lg_tumsiparisler.php (Tüm Siparişler)
 * tek "Siparişler" alanı hissi için bu şeridi paylaşır. Her sekme yalnızca kendi
 * yetkisi (M2 / M5) varsa gösterilir — erişimi olmayan sekmeye link verilmez.
 * Yalnız bir yetki varsa (tek sekme) şerit hiç render edilmez.
 *
 * Dahil eden sayfa, include'dan ÖNCE $sipAktifSekme = 'acik' | 'tum' tanımlar.
 */
$sipAktifSekme = $sipAktifSekme ?? 'acik';

$sip_sekmeler = [];
if (m_p_yetki($terminalkullanici, 'M2') == 1) {
    $sip_sekmeler[] = ['id' => 'acik', 'dosya' => 'lg_essiparis.php',    'ikon' => 'fa-clipboard-list', 'ad' => 'Açık Siparişler'];
}
if (m_p_yetki($terminalkullanici, 'M5') == 1) {
    $sip_sekmeler[] = ['id' => 'tum',  'dosya' => 'lg_tumsiparisler.php', 'ikon' => 'fa-box-archive',    'ad' => 'Tüm Siparişler'];
}

// Tek sekme (yalnız bir yetki) → switcher gereksiz, gösterme
if (count($sip_sekmeler) < 2) {
    return;
}
?>
<style>
.sip-tabs{ max-width:1280px; margin:0 auto; padding:0 16px; display:flex; gap:2px; border-bottom:1px solid var(--border,#e5e7eb); background:transparent; }
.sip-tab{ font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; display:inline-flex; align-items:center; gap:8px; padding:11px 18px; font-size:13.5px; font-weight:600; color:var(--text-2,#6b7280); text-decoration:none; border-bottom:2.5px solid transparent; margin-bottom:-1px; transition:.15s; white-space:nowrap; }
.sip-tab:hover{ color:var(--red,#6F1022); }
.sip-tab.aktif{ color:var(--red,#6F1022); border-bottom-color:var(--red,#6F1022); }
.sip-tab i{ font-size:13px; }
</style>
<nav class="sip-tabs" aria-label="Sipariş görünümleri">
    <?php foreach ($sip_sekmeler as $sip_s):
        $sip_akt = ($sip_s['id'] === $sipAktifSekme) ? ' aktif' : ''; ?>
        <a class="sip-tab<?= $sip_akt ?>" href="<?= $sip_s['dosya'] ?>"><i class="fa-solid <?= $sip_s['ikon'] ?>"></i> <?= htmlspecialchars($sip_s['ad'], ENT_QUOTES, 'UTF-8') ?></a>
    <?php endforeach; ?>
</nav>

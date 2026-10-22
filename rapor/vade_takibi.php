<?php

declare(strict_types=1);

/**
 * Vade takibi (yalnız bağımsız mod): tahsil edilecek / ödenecek açık kalemler vadesine göre;
 * gecikmiş, bu hafta, bu ay, sonrası. Erişim: M17 (Raporlar) ve CR1 (cari bakiye) ya da yönetici.
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/../includes/belge/sayfa.php';
require_once __DIR__ . '/../includes/rapor/vade_lib.php';

if (!lumen_bagimsiz_mi()) {
    lumen_hata_sayfasi('Modül kullanılamıyor', 'Vade takibi yalnız bağımsız modda (Lumen veritabanı) kullanılır; LOGO modunda Cari Yaşlandırma raporuna bakın.', 501, 'fa-person-digging');
}
$yonetici = (int) $yetkidurum === 0;
if (!$yonetici && ((int) m_p_yetki($terminalkullanici, 'M17') !== 1 || (int) m_p_yetki($terminalkullanici, 'CR1') !== 1)) {
    lumen_hata_sayfasi('Yetkiniz yok', 'Vade takibi için M17 (Raporlar) ve CR1 (cari bakiye) yetkisi gerekir.', 403, 'fa-lock');
}
$yon = ($_GET['yon'] ?? '') === 'odeme' ? 'odeme' : 'tahsil';
$dilimSec = (string) ($_GET['dilim'] ?? '');
// Türkçe harf duyarsız arama: "zuccaciye" → "Züccaciye", "IST" → "İstanbul"
$katla = static fn(string $s): string => strtr(mb_strtolower(strtr($s, ['I' => 'ı', 'İ' => 'i']), 'UTF-8'),
    ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u']);
$araHam = trim((string) ($_GET['q'] ?? ''));
$ara = $katla($araHam);
$ozel = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'H.CARI_ID', 'vade_ozel');
$kalemler = lumen_vade_acik_kalemler($dbh, $yon, null, $ozel);
$ozet = lumen_vade_ozet($kalemler);
$liste = array_values(array_filter($kalemler, static function (array $k) use ($dilimSec, $ara, $katla): bool {
    if ($dilimSec !== '' && lumen_vade_dilim($k['GUN']) !== $dilimSec) {
        return false;
    }
    return $ara === '' || str_contains($katla($k['KOD'] . ' ' . $k['UNVAN']), $ara);
}));
$dilimler = ['gecikmis' => ['Vadesi geçmiş', 'red'], 'hafta' => ['Bu hafta (7 gün)', 'amber'], 'ay' => ['Bu ay (8–30 gün)', 'indigo'], 'sonra' => ['30 günden sonra', 'slate']];
$url = static fn(array $ek): string => '?' . http_build_query(array_filter($ek + ['yon' => $yon, 'dilim' => $dilimSec, 'q' => $araHam], static fn($v) => $v !== ''));

belge_sayfa_basla('Vade takibi', 'fa-calendar-check', 'dashboard.php');
?>
<style>
    .sekmeler { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 1px solid var(--border); }
    .sekmeler a { padding: 10px 14px; font-weight: 600; font-size: 13.5px; color: var(--text-2); text-decoration: none; border-bottom: 2.5px solid transparent; margin-bottom: -1px; }
    .sekmeler a.aktif { color: var(--red); border-bottom-color: var(--red); }
    .ozet { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .ozet a { text-decoration: none; color: inherit; margin: 0; padding: 14px 16px; display: block; }
    .ozet a.secili { outline: 2px solid var(--red); }
    .ozet b { display: block; font-size: 19px; margin-top: 4px; }
    .ozet .red b { color: var(--red); } .ozet .amber b { color: var(--amber); }
    .gec { color: var(--red); font-weight: 700; } .yakin { color: var(--amber); font-weight: 600; }
    /* Telefonda belge tarihi ve ilk tutar gizlenir: vade, cari, açık tutar ve durum sığsın */
    @media (max-width: 640px) { .tel-gizle { display: none; } table.liste { font-size: 12px; } table.liste td, table.liste th { padding-left: 5px; padding-right: 5px; }
        table.liste td:nth-child(2) .koyu { display: block; } .gec, .yakin { white-space: normal; } }
</style>
<nav class="sekmeler">
    <a href="?yon=tahsil" class="<?= $yon === 'tahsil' ? 'aktif' : '' ?>"><i class="fa-solid fa-hand-holding-dollar"></i> Tahsil edilecek</a>
    <a href="?yon=odeme" class="<?= $yon === 'odeme' ? 'aktif' : '' ?>"><i class="fa-solid fa-money-bill-transfer"></i> Ödenecek</a>
</nav>
<div class="ozet">
    <?php foreach ($dilimler as $k => [$ad, $ton]): ?>
        <a href="<?= bh($url(['dilim' => $dilimSec === $k ? '' : $k])) ?>" class="kart <?= $ton ?> <?= $dilimSec === $k ? 'secili' : '' ?>" id="dilim-<?= $k ?>">
            <span class="soluk"><?= bh($ad) ?></span><b><?= btl($ozet[$k]) ?></b></a>
    <?php endforeach; ?>
    <div class="kart" style="margin:0;padding:14px 16px"><span class="soluk">Toplam açık (<?= (int) $ozet['cari'] ?> cari)</span><b id="toplam"><?= btl($ozet['toplam']) ?></b></div>
</div>
<form class="kart kart-ic" method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
    <input type="hidden" name="yon" value="<?= bh($yon) ?>"><input type="hidden" name="dilim" value="<?= bh($dilimSec) ?>">
    <label class="alan" style="flex:1;min-width:200px">Cari ara <input name="q" value="<?= bh($araHam) ?>" placeholder="kod veya unvan"></label>
    <button class="btn btn-ikincil" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Ara</button>
</form>
<div class="kart tablo-sar">
    <div class="kart-baslik"><i class="fa-solid fa-list"></i><?= count($liste) ?> açık kalem<?= count($liste) > 500 ? ' (ilk 500)' : '' ?></div>
    <table class="liste">
        <thead><tr><th>Vade</th><th>Cari</th><th class="tel-gizle">Belge</th><th class="tel-gizle">Tarih</th><th class="sag tel-gizle">Tutar</th><th class="sag">Açık</th><th class="sag">Durum</th></tr></thead>
        <tbody>
        <?php if ($liste === []): ?><tr><td colspan="7" class="soluk" style="text-align:center;padding:22px">Açık kalem yok.</td></tr><?php endif; ?>
        <?php foreach (array_slice($liste, 0, 500) as $k): ?>
            <tr>
                <td class="koyu"><?= bh(date('d.m.Y', (int) strtotime($k['VADE']))) ?></td>
                <td><a style="color:inherit" href="../kart/cari.php?id=<?= $k['CARI_ID'] ?>"><span class="koyu"><?= bh($k['KOD']) ?></span> <?= bh($k['UNVAN']) ?></a></td>
                <td class="tel-gizle"><?= $k['BELGE_ID'] ? '<a style="color:inherit" href="../belge/goster.php?id=' . $k['BELGE_ID'] . '">' . bh($k['BELGE_NO']) . '</a>' : bh($k['BELGE_NO'] ?? '') ?></td>
                <td class="soluk tel-gizle"><?= bh(date('d.m.Y', (int) strtotime($k['TARIH']))) ?></td>
                <td class="sag soluk tel-gizle"><?= btl($k['TUTAR']) ?></td>
                <td class="sag koyu"><?= btl($k['ACIK']) ?></td>
                <td class="sag"><?= $k['GUN'] > 0 ? '<span class="gec">' . $k['GUN'] . ' gün gecikti</span>'
                    : ($k['GUN'] === 0 ? '<span class="yakin">Bugün</span>' : '<span class="' . ($k['GUN'] >= -7 ? 'yakin' : 'soluk') . '">' . (-$k['GUN']) . ' gün kaldı</span>') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="soluk" style="font-size:12.5px">Tahsilatlar ve iadeler en erken vadeli kalemden başlayarak düşülür. Vadesi girilmemiş belgede vade = belge tarihi + carinin vade günü.</p>
<?php belge_sayfa_bitir();

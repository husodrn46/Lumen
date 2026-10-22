<?php

declare(strict_types=1);

/**
 * Cari kartı (yalnız bağımsız mod): bilgiler, bakiye, hızlı işlemler ve geçmiş —
 * belgeler (sipariş, fatura, irsaliye, iade), tahsilat/ödemeler, çekler.
 * Erişim cari ekstresiyle aynı: M4 + özel cari kısıtı.
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/../includes/belge/belge_lib.php';
require_once __DIR__ . '/../includes/finans/finans_lib.php';
require_once __DIR__ . '/../includes/belge/sayfa.php';
require_once __DIR__ . '/../includes/rapor/vade_lib.php';

// İkinci savunma hattı: bu modül yalnız bağımsız modda çalışır (modül korumasından bağımsız).
if (!lumen_bagimsiz_mi()) {
    lumen_hata_sayfasi('Modül kullanılamıyor', 'Bu ekran yalnız bağımsız modda (Lumen veritabanı) kullanılır.', 501, 'fa-person-digging');
}


$id = (int) ($_GET['id'] ?? 0);
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, $id, 'M4')) {
    lumen_hata_sayfasi('Cari bulunamadı', 'Bu cari kartı yok ya da görme yetkiniz yok.', 404);
}
$st = $dbh->prepare('SELECT C.*, P.AD AS PAZARLAMACI, ISNULL(B.BORC, 0) AS BORC, ISNULL(B.ALACAK, 0) AS ALACAK, ISNULL(B.BAKIYE, 0) AS BAKIYE
                     FROM dbo.LM_CARI C LEFT JOIN dbo.LM_PERSONEL P ON P.ID = C.PAZARLAMACI_ID LEFT JOIN dbo.LMV_CARI_BAKIYE B ON B.CARI_ID = C.ID
                     WHERE C.ID = :id');
$st->execute([':id' => $id]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) {
    lumen_hata_sayfasi('Cari bulunamadı', 'Bu cari kartı yok.', 404);
}
$belgeYetki = (int) m_p_yetki($terminalkullanici, 'M31') === 1;
$finansYetki = (int) m_p_yetki($terminalkullanici, 'M32') === 1;
$turler = lumen_belge_turleri();
$belgeler = $dbh->prepare('SELECT TOP 50 ID, TUR, YON, NO, TARIH, NET, DOVIZ_NET, PARA_BIRIMI, DURUM FROM dbo.LM_BELGE WHERE CARI_ID = :id ORDER BY TARIH DESC, ID DESC');
$belgeler->execute([':id' => $id]);
$belgeler = $belgeler->fetchAll(PDO::FETCH_ASSOC);
$fisler = $dbh->prepare('SELECT TOP 50 F.ID, F.TUR, F.NO, F.TARIH, F.TUTAR, F.DURUM, H.AD AS HESAP FROM dbo.LM_FINANS_FIS F
                         JOIN dbo.LM_FINANS_HESAP H ON H.ID = F.HESAP_ID WHERE F.CARI_ID = :id ORDER BY F.TARIH DESC, F.ID DESC');
$fisler->execute([':id' => $id]);
$fisler = $fisler->fetchAll(PDO::FETCH_ASSOC);
$cekler = $dbh->prepare('SELECT TOP 50 ID, TUR, PORTFOY_NO, SERI_NO, VADE, TUTAR, DURUM, CASE WHEN ALINAN_CARI_ID = :a THEN 1 ELSE 0 END AS ALINAN
                         FROM dbo.LM_CEK WHERE ALINAN_CARI_ID = :b OR VERILEN_CARI_ID = :c ORDER BY VADE DESC');
$cekler->execute([':a' => $id, ':b' => $id, ':c' => $id]);
$cekler = $cekler->fetchAll(PDO::FETCH_ASSOC);
$sekme = in_array($_GET['sekme'] ?? '', ['belge', 'fis', 'cek'], true) ? (string) $_GET['sekme'] : 'belge';
$cekDurum = [1 => 'Portföyde', 2 => 'Ciro edildi / verildi', 3 => 'Tahsil edildi', 4 => 'Bankada', 5 => 'Karşılıksız', 6 => 'İade', 7 => 'Ödendi'];
$tarih = static fn(?string $t): string => $t ? date('d.m.Y', (int) strtotime($t)) : '';
$bakiye = (float) $c['BAKIYE'];

belge_sayfa_basla($c['UNVAN'], 'fa-address-card', '../cari/lg_bakiye.php?q=' . rawurlencode((string) $c['KOD']));
?>
<style>
    .ozet { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .ozet .kart { margin: 0; padding: 14px 16px; }
    .ozet b { display: block; font-size: 19px; margin-top: 4px; }
    .borclu { color: var(--red); } .alacakli { color: var(--emerald); }
    .eylemler { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
    .sekmeler { display: flex; gap: 4px; overflow-x: auto; border-bottom: 1px solid var(--border); margin-bottom: 0; }
    .sekmeler a { padding: 10px 14px; font-weight: 600; font-size: 13.5px; color: var(--text-2); text-decoration: none; border-bottom: 2.5px solid transparent; margin-bottom: -1px; white-space: nowrap; }
    .sekmeler a.aktif { color: var(--red); border-bottom-color: var(--red); }
    .bilgi { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px 18px; font-size: 13px; }
    .bilgi .soluk { display: block; }
</style>
<div class="kart kart-ic">
    <div class="bilgi">
        <div><span class="soluk">Kod</span><span class="koyu"><?= bh($c['KOD']) ?></span>
            <?= (int) $c['AKTIF'] === 1 ? '' : '<span class="rozet iptal">Pasif</span>' ?>
            <span class="rozet"><?= bh([1 => 'Alıcı', 2 => 'Satıcı', 3 => 'Alıcı + satıcı'][(int) $c['TUR']] ?? '') ?></span></div>
        <div><span class="soluk">Vergi</span><?= bh(trim(($c['VERGI_DAIRESI'] ?? '') . ' ' . ($c['VERGI_NO'] ?? $c['TC_NO'] ?? ''))) ?: '—' ?></div>
        <div><span class="soluk">İletişim</span><?= bh(implode(' · ', array_filter([(string) $c['TELEFON'], (string) $c['EPOSTA']]))) ?: '—' ?></div>
        <div><span class="soluk">Adres</span><?= bh(trim(($c['ADRES'] ?? '') . ' ' . trim(($c['ILCE'] ?? '') . ' / ' . ($c['IL'] ?? ''), ' /'))) ?: '—' ?></div>
        <?php if ((int) $c['VADE_GUN'] > 0 || $c['PAZARLAMACI']): ?>
            <div><span class="soluk">Vade / pazarlamacı</span><?= (int) $c['VADE_GUN'] > 0 ? (int) $c['VADE_GUN'] . ' gün' : '—' ?><?= $c['PAZARLAMACI'] ? ' · ' . bh($c['PAZARLAMACI']) : '' ?></div>
        <?php endif; ?>
    </div>
</div>
<div class="ozet">
    <div class="kart"><span class="soluk">Toplam borç</span><b><?= btl($c['BORC']) ?></b></div>
    <div class="kart"><span class="soluk">Toplam alacak</span><b><?= btl($c['ALACAK']) ?></b></div>
    <div class="kart"><span class="soluk">Bakiye</span><b class="<?= $bakiye > 0.005 ? 'borclu' : ($bakiye < -0.005 ? 'alacakli' : '') ?>" id="cariBakiye"><?= btl(abs($bakiye)) ?></b>
        <span class="soluk"><?= $bakiye > 0.005 ? 'Cari bize borçlu' : ($bakiye < -0.005 ? 'Biz cariye borçluyuz' : 'Kapalı') ?></span></div>
    <?php
    // Vade: bakiye yönündeki açık kalemlerin gecikmiş kısmı ve en yakın vade
    $vadeKalem = abs($bakiye) > 0.005 ? lumen_vade_acik_kalemler($dbh, $bakiye > 0 ? 'tahsil' : 'odeme', $id) : [];
    $vadeOzet = lumen_vade_ozet($vadeKalem);
    $yakinVade = array_values(array_filter($vadeKalem, static fn($k) => $k['GUN'] <= 0))[0]['VADE'] ?? null; ?>
    <div class="kart"><span class="soluk">Vadesi geçmiş</span><b id="vadeGecmis" class="<?= $vadeOzet['gecikmis'] > 0.005 ? 'borclu' : '' ?>"><?= btl($vadeOzet['gecikmis']) ?></b>
        <span class="soluk"><?= $yakinVade ? 'Sıradaki vade ' . bh(date('d.m.Y', (int) strtotime($yakinVade))) : ($vadeKalem ? 'Hepsi gecikmiş' : 'Açık kalem yok') ?></span></div>
</div>
<div class="eylemler">
    <a class="btn btn-ikincil" href="../cari/lg_hareket.php?cariid=<?= $id ?>"><i class="fa-solid fa-list"></i> Ekstre</a>
    <?php if ($belgeYetki): ?><a class="btn btn-ikincil" href="../belge/yeni.php?tur=satis_fatura&cari=<?= $id ?>"><i class="fa-solid fa-file-invoice"></i> Fatura kes</a><?php endif; ?>
    <?php if ($finansYetki): ?><a class="btn btn-ikincil" href="../finans/yeni.php?tur=1&cari=<?= $id ?>"><i class="fa-solid fa-arrow-down"></i> Tahsilat</a>
        <a class="btn btn-ikincil" href="../finans/yeni.php?tur=2&cari=<?= $id ?>"><i class="fa-solid fa-arrow-up"></i> Ödeme</a><?php endif; ?>
</div>
<div class="kart">
    <nav class="sekmeler" style="padding:0 10px">
        <a href="?id=<?= $id ?>&sekme=belge" class="<?= $sekme === 'belge' ? 'aktif' : '' ?>">Belgeler (<?= count($belgeler) ?>)</a>
        <a href="?id=<?= $id ?>&sekme=fis" class="<?= $sekme === 'fis' ? 'aktif' : '' ?>">Tahsilat / ödeme (<?= count($fisler) ?>)</a>
        <a href="?id=<?= $id ?>&sekme=cek" class="<?= $sekme === 'cek' ? 'aktif' : '' ?>">Çek / senet (<?= count($cekler) ?>)</a>
    </nav>
    <div class="tablo-sar">
    <?php if ($sekme === 'belge'): ?>
        <table class="liste"><thead><tr><th>Tarih</th><th>Belge</th><th>Tür</th><th class="sag">Tutar</th></tr></thead><tbody>
        <?php if ($belgeler === []): ?><tr><td colspan="4" class="soluk" style="text-align:center;padding:22px">Belge yok.</td></tr><?php endif; ?>
        <?php foreach ($belgeler as $b):
            $tk = lumen_belge_tur_kodu((int) $b['TUR'], (int) $b['YON']);
            $ad = (int) $b['TUR'] === 1 ? ((int) $b['YON'] === 1 ? 'Satış siparişi' : 'Alış siparişi') : ($tk !== null ? $turler[$tk]['ad'] : 'Belge');
            $link = (int) $b['TUR'] === 1 && (int) $b['YON'] === 1 ? '../siparis/lg_fis.php?stokhareket=' . (int) $b['ID'] : ($belgeYetki ? '../belge/goster.php?id=' . (int) $b['ID'] : null); ?>
            <tr><td><?= bh($tarih($b['TARIH'])) ?></td>
                <td class="koyu"><?= $link ? '<a href="' . bh($link) . '" style="color:inherit">' . bh($b['NO']) . '</a>' : bh($b['NO']) ?></td>
                <td><span class="rozet <?= (int) $b['YON'] === 1 ? 'satis' : 'alis' ?>"><?= bh($ad) ?></span><?= (int) $b['DURUM'] === 9 ? ' <span class="rozet iptal">İptal</span>' : '' ?></td>
                <td class="sag koyu"><?= btl($b['NET']) ?><?= $b['PARA_BIRIMI'] !== 'TRY' && $b['DOVIZ_NET'] !== null ? '<div class="soluk">' . bh(number_format((float) $b['DOVIZ_NET'], 2, ',', '.') . ' ' . $b['PARA_BIRIMI']) . '</div>' : '' ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php elseif ($sekme === 'fis'): ?>
        <table class="liste"><thead><tr><th>Tarih</th><th>Fiş</th><th>Tür</th><th>Hesap</th><th class="sag">Tutar</th></tr></thead><tbody>
        <?php if ($fisler === []): ?><tr><td colspan="5" class="soluk" style="text-align:center;padding:22px">Tahsilat ya da ödeme yok.</td></tr><?php endif; ?>
        <?php foreach ($fisler as $f): ?>
            <tr><td><?= bh($tarih($f['TARIH'])) ?></td>
                <td class="koyu"><?= $finansYetki ? '<a href="../finans/goster.php?id=' . (int) $f['ID'] . '" style="color:inherit">' . bh($f['NO']) . '</a>' : bh($f['NO']) ?></td>
                <td><?= bh(lumen_finans_turleri()[(int) $f['TUR']]['ad'] ?? '') ?><?= (int) $f['DURUM'] === 9 ? ' <span class="rozet iptal">İptal</span>' : '' ?></td>
                <td><?= bh($f['HESAP']) ?></td><td class="sag koyu"><?= btl($f['TUTAR']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php else: ?>
        <table class="liste"><thead><tr><th>Vade</th><th>Portföy / seri</th><th>Yön</th><th>Durum</th><th class="sag">Tutar</th></tr></thead><tbody>
        <?php if ($cekler === []): ?><tr><td colspan="5" class="soluk" style="text-align:center;padding:22px">Çek ya da senet yok.</td></tr><?php endif; ?>
        <?php foreach ($cekler as $k): ?>
            <tr><td><?= bh($tarih($k['VADE'])) ?></td><td class="koyu"><?= bh($k['PORTFOY_NO']) ?> <span class="soluk"><?= bh($k['SERI_NO'] ?? '') ?></span></td>
                <td><?= (int) $k['ALINAN'] === 1 ? 'Alınan' : 'Verilen' ?> <?= in_array((int) $k['TUR'], [3, 4], true) ? 'senet' : 'çek' ?></td>
                <td><?= bh($cekDurum[(int) $k['DURUM']] ?? '') ?></td><td class="sag koyu"><?= btl($k['TUTAR']) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    <?php endif; ?>
    </div>
</div>
<?php belge_sayfa_bitir();

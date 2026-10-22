<?php

declare(strict_types=1);

/**
 * Stok kartı (yalnız bağımsız mod): bilgiler, birimler, barkodlar, fiyatlar, depo
 * miktarları ve hareket geçmişi (belge bağlantılı, yürüyen bakiye).
 * Erişim: M7 (Stok Ara) ya da yönetici; alış fiyatı ve maliyet yalnız M31 (Faturalama).
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/../includes/belge/belge_lib.php';
require_once __DIR__ . '/../includes/belge/sayfa.php';

// İkinci savunma hattı: bu modül yalnız bağımsız modda çalışır (modül korumasından bağımsız).
if (!lumen_bagimsiz_mi()) {
    lumen_hata_sayfasi('Modül kullanılamıyor', 'Bu ekran yalnız bağımsız modda (Lumen veritabanı) kullanılır.', 501, 'fa-person-digging');
}


// Kart miktar gösterir: stok aramadaki kuralla aynı (M7 + ST1 miktar görme) ya da yönetici.
if ((int) $yetkidurum !== 0 && ((int) m_p_yetki($terminalkullanici, 'M7') !== 1 || (int) m_p_yetki($terminalkullanici, 'ST1') !== 1)) {
    lumen_hata_sayfasi('Yetkiniz yok', 'Stok kartı için M7 (Stok Ara) ve ST1 (miktar görme) yetkisi gerekir.', 403, 'fa-lock');
}
$id = (int) ($_GET['id'] ?? 0);
$st = $dbh->prepare('SELECT S.*, B.KOD AS ANA_BIRIM FROM dbo.LM_STOK S JOIN dbo.LM_BIRIM B ON B.ID = S.ANA_BIRIM_ID WHERE S.ID = :id');
$st->execute([':id' => $id]);
$s = $st->fetch(PDO::FETCH_ASSOC);
if (!$s) {
    lumen_hata_sayfasi('Ürün bulunamadı', 'Bu stok kartı yok.', 404);
}
$maliyetGorur = (int) m_p_yetki($terminalkullanici, 'M31') === 1;
$sorgu = static function (string $sql, array $p = []) use ($dbh): array {
    $q = $dbh->prepare($sql);
    $q->execute($p);
    return $q->fetchAll(PDO::FETCH_ASSOC);
};
$birimler = $sorgu('SELECT B.KOD, B.AD, SB.CARPAN, SB.AGIRLIK_KG FROM dbo.LM_STOK_BIRIM SB JOIN dbo.LM_BIRIM B ON B.ID = SB.BIRIM_ID
                    WHERE SB.STOK_ID = :id ORDER BY SB.CARPAN', [':id' => $id]);
$barkodlar = $sorgu('SELECT BK.BARKOD, B.KOD FROM dbo.LM_BARKOD BK JOIN dbo.LM_BIRIM B ON B.ID = BK.BIRIM_ID WHERE BK.STOK_ID = :id', [':id' => $id]);
$fiyatlar = $sorgu("SELECT F.TUR, F.FIYAT, F.PARA_BIRIMI, F.BASLANGIC, B.KOD FROM dbo.LM_FIYAT F JOIN dbo.LM_BIRIM B ON B.ID = F.BIRIM_ID
                    WHERE F.STOK_ID = :id AND F.BASLANGIC <= CAST(GETDATE() AS DATE)" . ($maliyetGorur ? '' : ' AND F.TUR = 1') . '
                    ORDER BY F.TUR, F.BASLANGIC DESC', [':id' => $id]);
$depolar = $sorgu('SELECT D.AD, D.NO, M.MIKTAR FROM dbo.LMV_STOK_MIKTAR M JOIN dbo.LM_DEPO D ON D.ID = M.DEPO_ID
                   WHERE M.STOK_ID = :id AND ABS(M.MIKTAR) > 0.000001 ORDER BY D.NO', [':id' => $id]);
$toplam = array_sum(array_map(static fn($d) => (float) $d['MIKTAR'], $depolar));
// Hareketler: en yeni 100; yürüyen bakiye en eski hareketten hesaplanır.
// Özel cari kısıtı: kullanıcının göremediği carinin adı gösterilmez (hareket ve miktar kalır).
$ozel = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'ISNULL(B.CARI_ID, 0)', 'stok_kart_gizli');
$unvanSql = $ozel['sql'] !== '' ? "CASE WHEN {$ozel['sql']} THEN C.UNVAN ELSE N'(gizli cari)' END" : 'C.UNVAN';
$hareketler = $sorgu('SELECT TOP 100 H.ID, H.TARIH, H.GIRIS, H.CIKIS, H.BIRIM_MALIYET, D.AD AS DEPO, B.ID AS BELGE_ID, B.TUR, B.YON, B.NO, B.DURUM,
                             ' . $unvanSql . ' AS UNVAN, SUM(H.GIRIS - H.CIKIS) OVER (ORDER BY H.TARIH, H.ID ROWS UNBOUNDED PRECEDING) AS BAKIYE
                      FROM dbo.LM_STOK_HAREKET H JOIN dbo.LM_DEPO D ON D.ID = H.DEPO_ID JOIN dbo.LM_BELGE B ON B.ID = H.BELGE_ID
                      LEFT JOIN dbo.LM_CARI C ON C.ID = B.CARI_ID
                      WHERE H.STOK_ID = :id ORDER BY H.TARIH DESC, H.ID DESC', [':id' => $id] + $ozel['params']);
$bekleyen = $sorgu('SELECT ISNULL(SUM(CASE WHEN B.YON = 1 THEN (S.MIKTAR - S.TESLIM_MIKTAR) * S.CARPAN END), 0) AS SATIS,
                           ISNULL(SUM(CASE WHEN B.YON = 2 THEN (S.MIKTAR - S.TESLIM_MIKTAR) * S.CARPAN END), 0) AS ALIS
                    FROM dbo.LM_BELGE_SATIR S JOIN dbo.LM_BELGE B ON B.ID = S.BELGE_ID
                    WHERE S.STOK_ID = :id AND B.TUR = 1 AND B.DURUM <> 9 AND S.IPTAL = 0 AND S.KAPALI = 0', [':id' => $id])[0];
$belgeYetki = (int) m_p_yetki($terminalkullanici, 'M31') === 1 || (int) m_p_yetki($terminalkullanici, 'M15') === 1;
$turler = lumen_belge_turleri();

$duzenler = (int) $yetkidurum === 0 || (int) m_p_yetki($terminalkullanici, 'M11') === 1;
belge_sayfa_basla($s['KOD'] . ' · ' . $s['AD'], 'fa-box', '../index.php', $duzenler
    ? '<a href="stok_duzenle.php?id=' . $id . '" class="btn btn-ikincil"><i class="fa-solid fa-pen"></i><span class="metin">Düzenle</span></a>' : '');
?>
<style>
    .ozet { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px; }
    .ozet .kart { margin: 0; padding: 14px 16px; }
    .ozet b { display: block; font-size: 19px; margin-top: 4px; }
    .ikili { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 16px; }
    .ikili .kart { margin: 0; }
    .eksi { color: var(--red); } .arti { color: var(--emerald); }
</style>
<div class="ozet">
    <div class="kart"><span class="soluk">Eldeki (tüm depolar)</span><b id="eldeki" class="<?= $toplam < 0 ? 'eksi' : '' ?>"><?= bmiktar($toplam) ?> <?= bh($s['ANA_BIRIM']) ?></b></div>
    <div class="kart"><span class="soluk">Bekleyen satış siparişi</span><b><?= bmiktar($bekleyen['SATIS']) ?></b></div>
    <div class="kart"><span class="soluk">Bekleyen alış siparişi</span><b><?= bmiktar($bekleyen['ALIS']) ?></b></div>
    <div class="kart"><span class="soluk">Kullanılabilir</span><b><?= bmiktar($toplam - (float) $bekleyen['SATIS'] + (float) $bekleyen['ALIS']) ?></b></div>
</div>
<div class="ikili" style="margin-bottom:16px">
    <div class="kart kart-ic">
        <div class="kart-baslik" style="padding:0 0 10px"><i class="fa-solid fa-circle-info"></i>Kart</div>
        <div class="soluk">KDV %<?= bmiktar($s['KDV_ORANI']) ?><?= $s['GRUP'] ? ' · grup ' . bh($s['GRUP']) : '' ?><?= (int) $s['AKTIF'] === 1 ? '' : ' · <span class="rozet iptal">Pasif</span>' ?></div>
        <table class="liste" style="margin-top:8px"><thead><tr><th>Birim</th><th class="sag">Çarpan</th><th class="sag">Ağırlık</th></tr></thead><tbody>
        <?php foreach ($birimler as $b): ?><tr><td><?= bh($b['KOD']) ?></td><td class="sag"><?= bmiktar($b['CARPAN']) ?> <?= bh($s['ANA_BIRIM']) ?></td>
            <td class="sag"><?= $b['AGIRLIK_KG'] ? bmiktar($b['AGIRLIK_KG']) . ' kg' : '' ?></td></tr><?php endforeach; ?>
        </tbody></table>
        <?php if ($barkodlar): ?><div style="margin-top:10px" class="soluk">Barkod: <?= bh(implode(', ', array_map(static fn($b) => $b['BARKOD'] . ' (' . $b['KOD'] . ')', $barkodlar))) ?></div><?php endif; ?>
    </div>
    <div class="kart kart-ic">
        <div class="kart-baslik" style="padding:0 0 10px"><i class="fa-solid fa-tags"></i>Fiyatlar ve depolar</div>
        <table class="liste"><tbody>
        <?php foreach ($fiyatlar as $f): ?><tr><td><?= (int) $f['TUR'] === 1 ? 'Satış' : 'Alış' ?> · <?= bh($f['KOD']) ?></td>
            <td class="sag koyu"><?= bh(number_format((float) $f['FIYAT'], 2, ',', '.') . ' ' . ($f['PARA_BIRIMI'] === 'TRY' ? '₺' : $f['PARA_BIRIMI'])) ?></td>
            <td class="soluk sag"><?= bh(date('d.m.Y', (int) strtotime((string) $f['BASLANGIC']))) ?></td></tr><?php endforeach; ?>
        <?php if ($fiyatlar === []): ?><tr><td class="soluk">Fiyat tanımlı değil.</td></tr><?php endif; ?>
        </tbody></table>
        <table class="liste" style="margin-top:10px"><tbody>
        <?php foreach ($depolar as $d): ?><tr><td><?= bh($d['AD']) ?> <span class="soluk">(<?= (int) $d['NO'] ?>)</span></td><td class="sag koyu"><?= bmiktar($d['MIKTAR']) ?></td></tr><?php endforeach; ?>
        <?php if ($depolar === []): ?><tr><td class="soluk">Hiçbir depoda stok yok.</td></tr><?php endif; ?>
        </tbody></table>
    </div>
</div>
<div class="kart tablo-sar">
    <div class="kart-baslik"><i class="fa-solid fa-clock-rotate-left"></i>Hareketler (son 100)</div>
    <table class="liste">
        <thead><tr><th>Tarih</th><th>Belge</th><th>Cari</th><th>Depo</th><th class="sag">Giriş</th><th class="sag">Çıkış</th><th class="sag">Bakiye</th>
            <?php if ($maliyetGorur): ?><th class="sag">Birim maliyet</th><?php endif; ?></tr></thead>
        <tbody>
        <?php if ($hareketler === []): ?><tr><td colspan="8" class="soluk" style="text-align:center;padding:22px">Hareket yok.</td></tr><?php endif; ?>
        <?php foreach ($hareketler as $h):
            $tk = lumen_belge_tur_kodu((int) $h['TUR'], (int) $h['YON']); ?>
            <tr>
                <td><?= bh(date('d.m.Y', (int) strtotime((string) $h['TARIH']))) ?></td>
                <td><?= $belgeYetki ? '<a class="koyu" style="color:inherit" href="../belge/goster.php?id=' . (int) $h['BELGE_ID'] . '">' . bh($h['NO']) . '</a>' : bh($h['NO']) ?>
                    <div class="soluk"><?= bh($tk !== null ? $turler[$tk]['ad'] : 'Belge') ?></div></td>
                <td><?= bh($h['UNVAN'] ?? '') ?></td><td><?= bh($h['DEPO']) ?></td>
                <td class="sag arti"><?= (float) $h['GIRIS'] > 0 ? bmiktar($h['GIRIS']) : '' ?></td>
                <td class="sag eksi"><?= (float) $h['CIKIS'] > 0 ? bmiktar($h['CIKIS']) : '' ?></td>
                <td class="sag koyu"><?= bmiktar($h['BAKIYE']) ?></td>
                <?php if ($maliyetGorur): ?><td class="sag soluk"><?= $h['BIRIM_MALIYET'] !== null ? btl($h['BIRIM_MALIYET']) : '' ?></td><?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php belge_sayfa_bitir();

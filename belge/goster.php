<?php

declare(strict_types=1);

/** Belge görüntüleme / yazdırma / iptal — yalnız bağımsız mod. */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

$id = (int) ($_GET['id'] ?? 0);
$st = $dbh->prepare("SELECT B.*, C.KOD AS CARI_KOD, C.UNVAN, C.VERGI_NO, C.VERGI_DAIRESI, C.ADRES, C.IL, C.ILCE, D.AD AS DEPO_AD,
                            P.AD AS PERSONEL_AD, KB.NO AS KAYNAK_NO
                     FROM dbo.LM_BELGE B
                     LEFT JOIN dbo.LM_CARI C ON C.ID = B.CARI_ID
                     LEFT JOIN dbo.LM_DEPO D ON D.ID = B.DEPO_ID
                     LEFT JOIN dbo.LM_PERSONEL P ON P.ID = B.PERSONEL_ID
                     LEFT JOIN dbo.LM_BELGE KB ON KB.ID = B.KAYNAK_BELGE_ID
                     WHERE B.ID = :id AND B.TUR <> 1");
$st->execute([':id' => $id]);
$b = $st->fetch(PDO::FETCH_ASSOC);
if (!$b || ((int) $b['CARI_ID'] > 0 && !m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, (int) $b['CARI_ID']))) {
    lumen_hata_sayfasi('Belge bulunamadı', 'Bu numarada bir belge yok.', 404);
}
$kod = lumen_belge_tur_kodu((int) $b['TUR'], (int) $b['YON']);
if ($kod === null || !isset($belgeTurleri[$kod])) {
    lumen_hata_sayfasi('Belge bulunamadı', 'Bu numarada bir belge yok.', 404);
}
$turAd = $kod !== null ? lumen_belge_turleri()[$kod]['ad'] : 'Belge';
$s = $dbh->prepare("SELECT S.*, ST.KOD, ST.AD, BR.KOD AS BIRIM FROM dbo.LM_BELGE_SATIR S
                    LEFT JOIN dbo.LM_STOK ST ON ST.ID = S.STOK_ID LEFT JOIN dbo.LM_BIRIM BR ON BR.ID = S.BIRIM_ID
                    WHERE S.BELGE_ID = :id ORDER BY S.SIRA");
$s->execute([':id' => $id]);
$satirlar = $s->fetchAll(PDO::FETCH_ASSOC);
$iptal = (int) $b['DURUM'] === 9;
$dovizli = (string) $b['PARA_BIRIMI'] !== 'TRY';
$dv = static fn(mixed $v): string => number_format((float) $v, 2, ',', '.') . ' ' . $b['PARA_BIRIMI'];

belge_sayfa_basla($turAd . ' ' . $b['NO'], 'fa-file-invoice', 'index.php',
    '<a class="btn btn-ikincil yazdirma-gizle" href="yazdir.php?id=' . $id . '&pdf=1"><i class="fa-solid fa-file-pdf"></i><span class="metin">PDF</span></a>'
    . '<a class="btn btn-birincil yazdirma-gizle" href="yazdir.php?id=' . $id . '" target="_blank" rel="noopener"><i class="fa-solid fa-print"></i><span class="metin">Yazdır</span></a>');
?>
<div class="kart kart-ic izgara">
    <div><div class="soluk">Belge</div><div class="koyu"><?= bh($turAd) ?> · <?= bh($b['NO']) ?>
        <?php if ($iptal): ?><span class="rozet iptal">İptal</span><?php endif; ?></div></div>
    <div><div class="soluk">Tarih</div><div class="koyu"><?= bh(date('d.m.Y', strtotime((string) $b['TARIH']))) ?>
        <?php if ($b['VADE']): ?><span class="soluk"> · vade <?= bh(date('d.m.Y', strtotime((string) $b['VADE']))) ?></span><?php endif; ?></div></div>
    <div><div class="soluk">Cari</div><div class="koyu"><?= $b['CARI_ID'] ? '<a style="color:inherit" href="../kart/cari.php?id=' . (int) $b['CARI_ID'] . '">' . bh(trim($b['CARI_KOD'] . ' ' . $b['UNVAN'])) . '</a>' : '—' ?></div>
        <?php if ($b['VERGI_NO']): ?><div class="soluk"><?= bh($b['VERGI_DAIRESI'] . ' / ' . $b['VERGI_NO']) ?></div><?php endif; ?></div>
    <div><div class="soluk">Depo</div><div class="koyu"><?= bh($b['DEPO_AD'] ?? '—') ?></div>
        <?php if ($b['KAYNAK_NO']): ?><div class="soluk">Kaynak: <?= bh($b['KAYNAK_NO']) ?></div><?php endif; ?></div>
    <?php if ($dovizli): ?>
        <div><div class="soluk">Para birimi / kur</div><div class="koyu"><?= bh($b['PARA_BIRIMI']) ?> · <?= bh(number_format((float) $b['KUR'], 4, ',', '.')) ?> ₺</div></div>
    <?php endif; ?>
</div>

<div class="kart tablo-sar">
    <table class="liste">
        <thead><tr><th>#</th><th>Ürün</th><th class="sag">Miktar</th><th class="sag">Fiyat</th><th class="sag">İsk. %</th>
            <th class="sag">KDV %</th><th class="sag">Tutar (KDV hariç)</th></tr></thead>
        <tbody>
        <?php foreach ($satirlar as $r): ?>
            <tr>
                <td><?= (int) $r['SIRA'] ?></td>
                <td><a class="koyu" style="color:inherit" href="../kart/stok.php?id=<?= (int) $r['STOK_ID'] ?>"><?= bh($r['KOD']) ?></a> <?= bh($r['AD']) ?>
                    <?php if ($r['ACIKLAMA']): ?><div class="soluk"><?= bh($r['ACIKLAMA']) ?></div><?php endif; ?></td>
                <td class="sag"><?= bmiktar($r['MIKTAR']) ?> <?= bh($r['BIRIM'] ?? '') ?></td>
                <td class="sag"><?= $dovizli && $r['DOVIZ_FIYAT'] !== null ? bh($dv($r['DOVIZ_FIYAT'])) . '<div class="soluk">' . btl($r['FIYAT']) . '</div>' : btl($r['FIYAT']) ?></td>
                <td class="sag"><?= bmiktar($r['ISKONTO_ORANI']) ?></td>
                <td class="sag"><?= bmiktar($r['KDV_ORANI']) ?></td>
                <td class="sag koyu"><?= btl($r['NET']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="toplamlar">
        <span>Brüt</span><span class="sag"><?= btl($b['BRUT']) ?></span>
        <span>İskonto</span><span class="sag">- <?= btl($b['ISKONTO']) ?></span>
        <span>KDV</span><span class="sag"><?= btl($b['KDV']) ?></span>
        <span class="genel">Genel toplam</span><span class="sag genel"><?= btl($b['NET']) ?></span>
        <?php if ($dovizli && $b['DOVIZ_NET'] !== null): ?>
            <span class="koyu">Döviz toplamı</span><span class="sag koyu"><?= bh($dv($b['DOVIZ_NET'])) ?></span>
        <?php endif; ?>
    </div>
</div>
<?php if ($b['ACIKLAMA']): ?><div class="kart kart-ic"><span class="soluk">Açıklama:</span> <?= bh($b['ACIKLAMA']) ?></div><?php endif; ?>

<?php if (!$iptal): ?>
<details class="kart kart-ic yazdirma-gizle">
    <summary style="cursor:pointer;font-weight:600;color:var(--red)"><i class="fa-solid fa-ban"></i> Belgeyi iptal et</summary>
    <p class="soluk" style="margin:10px 0">Stok ve cari etkisi geri alınır; siparişten geldiyse sipariş satırları yeniden açılır.
        Belge, iptal olarak listede kalır.</p>
    <form method="post" action="islem.php">
        <?= csrf_field() ?>
        <input type="hidden" name="islem" value="iptal"><input type="hidden" name="id" value="<?= (int) $b['ID'] ?>">
        <button class="btn btn-birincil" type="submit"><i class="fa-solid fa-check"></i> Evet, iptal et</button>
    </form>
</details>
<?php endif;
belge_sayfa_bitir();

<?php

declare(strict_types=1);

/** Kasa/banka fişi detayı ve iptal — yalnız bağımsız mod. */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

$id = (int) ($_GET['id'] ?? 0);
$st = $dbh->prepare("SELECT F.*, H.KOD AS HESAP_KOD, H.AD AS HESAP, K.KOD AS KARSI_KOD, K.AD AS KARSI, C.KOD AS CARI_KOD, C.UNVAN, P.AD AS OLUSTURAN
                     FROM dbo.LM_FINANS_FIS F
                     JOIN dbo.LM_FINANS_HESAP H ON H.ID = F.HESAP_ID
                     LEFT JOIN dbo.LM_FINANS_HESAP K ON K.ID = F.KARSI_HESAP_ID
                     LEFT JOIN dbo.LM_CARI C ON C.ID = F.CARI_ID
                     LEFT JOIN dbo.LM_PERSONEL P ON P.ID = F.OLUSTURAN_ID
                     WHERE F.ID = :id");
$st->execute([':id' => $id]);
$f = $st->fetch(PDO::FETCH_ASSOC);
if (!$f || ($f['CARI_ID'] !== null && !m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, (int) $f['CARI_ID']))) {
    lumen_hata_sayfasi('Fiş bulunamadı', 'Kasa/banka fişi bulunamadı.', 404);
}
$turler = lumen_finans_turleri();
$tur = (int) $f['TUR'];
$iptal = (int) $f['DURUM'] === 9;

belge_sayfa_basla(($turler[$tur]['ad'] ?? 'Fiş') . ' · ' . $f['NO'], 'fa-money-bill-transfer', 'index.php',
    '<button class="btn btn-ikincil yazdirma-gizle" onclick="window.print()"><i class="fa-solid fa-print"></i><span class="metin">Yazdır</span></button>');
?>
<div class="kart kart-ic izgara">
    <div><div class="soluk">Fiş no</div><div class="koyu"><?= bh($f['NO']) ?>
        <?php if ($iptal): ?><span class="rozet iptal">İptal</span><?php endif; ?></div></div>
    <div><div class="soluk">Tür</div><div class="koyu"><?= bh($turler[$tur]['ad'] ?? '') ?></div></div>
    <div><div class="soluk">Tarih</div><div class="koyu"><?= bh(date('d.m.Y', strtotime((string) $f['TARIH']))) ?></div></div>
    <div><div class="soluk"><?= $f['KARSI'] ? 'Çıkış hesabı' : 'Hesap' ?></div><div class="koyu"><?= bh($f['HESAP_KOD'] . ' ' . $f['HESAP']) ?></div></div>
    <?php if ($f['KARSI']): ?>
        <div><div class="soluk">Giriş hesabı</div><div class="koyu"><?= bh($f['KARSI_KOD'] . ' ' . $f['KARSI']) ?></div></div>
    <?php endif; ?>
    <?php if ($f['CARI_KOD']): ?>
        <div><div class="soluk">Cari</div><div class="koyu"><a href="../cari/lg_hareket.php?cariid=<?= (int) $f['CARI_ID'] ?>" style="color:inherit"><?= bh($f['CARI_KOD'] . ' ' . $f['UNVAN']) ?></a></div></div>
    <?php endif; ?>
    <div><div class="soluk">Tutar</div><div class="koyu" style="font-size:20px;color:var(--red)" id="fisTutar"><?= btl($f['TUTAR']) ?></div></div>
</div>
<div class="kart kart-ic">
    <div class="soluk">Açıklama</div><div><?= bh($f['ACIKLAMA'] ?? '—') ?></div>
    <div class="soluk" style="margin-top:10px">Kaydeden: <?= bh($f['OLUSTURAN'] ?? '—') ?> · <?= bh(date('d.m.Y H:i', strtotime((string) $f['OLUSTURMA']))) ?></div>
</div>
<?php if (!$iptal): ?>
    <form method="post" action="islem.php" class="yazdirma-gizle" style="display:flex;justify-content:flex-end"
          onsubmit="return confirm('Fiş iptal edilsin mi? Kasa/banka ve cari etkisi geri alınır.');">
        <?= csrf_field() ?>
        <input type="hidden" name="islem" value="iptal">
        <input type="hidden" name="id" value="<?= (int) $f['ID'] ?>">
        <button type="submit" class="btn btn-ikincil"><i class="fa-solid fa-ban"></i> Fişi iptal et</button>
    </form>
<?php endif; ?>
<?php belge_sayfa_bitir();

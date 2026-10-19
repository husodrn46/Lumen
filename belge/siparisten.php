<?php

declare(strict_types=1);

/**
 * Siparişten fatura / irsaliye — yalnız bağımsız mod. Siparişin kalan satırları
 * gelir; miktar kısmen değiştirilebilir (kısmi sevk/fatura). Satırlar siparişin
 * fiyat, iskonto ve KDV'siyle aktarılır; sipariş satırlarının teslim miktarı güncellenir.
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

if (!$belgeTam) {
    lumen_hata_sayfasi('Fatura yetkiniz yok', 'Siparişten belge için M31 (Faturalama) yetkisi gerekir.', 403, 'fa-lock');
}
$sid = (int) ($_GET['siparis'] ?? 0);
$st = $dbh->prepare("SELECT B.ID, B.NO, B.YON, B.TARIH, B.CARI_ID, B.DEPO_ID, B.PARA_BIRIMI, C.KOD, C.UNVAN
                     FROM dbo.LM_BELGE B LEFT JOIN dbo.LM_CARI C ON C.ID = B.CARI_ID
                     WHERE B.ID = :id AND B.TUR = 1 AND B.DURUM <> 9");
$st->execute([':id' => $sid]);
$sip = $st->fetch(PDO::FETCH_ASSOC);
if (!$sip || !m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, (int) $terminalkullanici, $sid)) {
    lumen_hata_sayfasi('Sipariş bulunamadı', 'Faturalanacak sipariş bulunamadı veya iptal edilmiş.', 404);
}
$satirlar = lumen_siparis_kalan_satirlar($dbh, $sid);
$yon = (int) $sip['YON'];
$secenekler = $yon === 1 ? ['satis_fatura', 'satis_irsaliye'] : ['alis_fatura', 'alis_irsaliye'];
$turler = lumen_belge_turleri();

belge_sayfa_basla('Siparişten Belge · ' . $sip['NO'], 'fa-file-export', '../siparis/lg_fis.php?stokhareket=' . $sid);
?>
<?php if ($sip['PARA_BIRIMI'] !== 'TRY'): ?>
    <div class="bildirim hata"><i class="fa-solid fa-circle-info"></i>Dövizli sipariş: belge TL tutarlarıyla kesilir (döviz tutarları sonraki sürümde).</div>
<?php endif; ?>
<?php if ($satirlar === []): ?>
    <div class="kart kart-ic" style="text-align:center;padding:30px">
        <div class="koyu">Bu siparişin faturalanacak/sevk edilecek kalan satırı yok.</div>
        <div style="margin-top:12px"><a class="btn btn-ikincil" href="index.php">Belgelere git</a></div>
    </div>
<?php belge_sayfa_bitir(); exit; endif; ?>
<form method="post" action="islem.php">
    <?= csrf_field() ?>
    <input type="hidden" name="islem" value="kaydet">
    <input type="hidden" name="kaynak_belge_id" value="<?= (int) $sip['ID'] ?>">
    <input type="hidden" name="cari_id" value="<?= (int) $sip['CARI_ID'] ?>">
    <input type="hidden" name="depo_id" value="<?= (int) $sip['DEPO_ID'] ?>">
    <div class="kart kart-ic izgara">
        <div><div class="soluk">Cari</div><div class="koyu"><?= bh(trim($sip['KOD'] . ' ' . $sip['UNVAN'])) ?></div></div>
        <label class="alan">Belge türü
            <select name="tur"><?php foreach ($secenekler as $k): ?><option value="<?= bh($k) ?>"><?= bh($turler[$k]['ad']) ?></option><?php endforeach; ?></select>
        </label>
        <label class="alan">Tarih <input type="date" name="tarih" value="<?= date('Y-m-d') ?>" required></label>
        <label class="alan">Vade <input type="date" name="vade"></label>
    </div>
    <div class="kart tablo-sar">
        <table class="liste">
            <thead><tr><th>Ürün</th><th class="sag">Sipariş</th><th class="sag">Teslim edilen</th><th style="width:130px">Bu belgede</th>
                <th class="sag">Fiyat</th><th class="sag">İsk. %</th><th class="sag">KDV %</th></tr></thead>
            <tbody>
            <?php foreach ($satirlar as $r): ?>
                <tr>
                    <td><span class="koyu"><?= bh($r['KOD']) ?></span> <?= bh($r['AD']) ?>
                        <input type="hidden" name="satir[stok_id][]" value="<?= (int) $r['STOK_ID'] ?>">
                        <input type="hidden" name="satir[birim_id][]" value="<?= (int) $r['BIRIM_ID'] ?>">
                        <input type="hidden" name="satir[kaynak_satir_id][]" value="<?= (int) $r['ID'] ?>">
                        <input type="hidden" name="satir[fiyat][]" value="<?= bh($r['FIYAT']) ?>">
                        <input type="hidden" name="satir[iskonto][]" value="<?= bh($r['ISKONTO']) ?>">
                        <input type="hidden" name="satir[kdv][]" value="<?= bh($r['KDV_ORANI']) ?>"></td>
                    <td class="sag"><?= bmiktar($r['MIKTAR']) ?> <?= bh($r['BIRIM']) ?></td>
                    <td class="sag"><?= bmiktar($r['TESLIM_MIKTAR']) ?></td>
                    <td><input name="satir[miktar][]" inputmode="decimal" value="<?= bh(str_replace('.', ',', rtrim(rtrim(number_format((float) $r['KALAN'], 6, '.', ''), '0'), '.'))) ?>"></td>
                    <td class="sag"><?= btl($r['FIYAT']) ?></td>
                    <td class="sag"><?= bmiktar($r['ISKONTO']) ?></td>
                    <td class="sag"><?= bmiktar($r['KDV_ORANI']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="kart kart-ic"><label class="alan">Açıklama <textarea name="aciklama" rows="2" maxlength="500"><?= bh('Sipariş ' . $sip['NO']) ?></textarea></label></div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
        <button type="submit" class="btn btn-birincil"><i class="fa-solid fa-check"></i> Belgeyi kes</button>
    </div>
</form>
<?php belge_sayfa_bitir();

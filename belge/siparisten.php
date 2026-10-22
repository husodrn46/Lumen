<?php

declare(strict_types=1);

/**
 * Siparişten fatura / irsaliye ve irsaliyeden fatura — yalnız bağımsız mod. Kaynağın kalan
 * satırları gelir; miktar kısmen değiştirilebilir (kısmi sevk/fatura). Satırlar kaynağın
 * fiyat, iskonto ve KDV'siyle aktarılır; kaynak satırların teslim/fatura miktarı güncellenir.
 * İrsaliyeden kesilen fatura stoğu tekrar oynatmaz (irsaliye oynattı).
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

if (!$belgeTam) {
    lumen_hata_sayfasi('Fatura yetkiniz yok', 'Siparişten belge için M31 (Faturalama) yetkisi gerekir.', 403, 'fa-lock');
}
$irsaliyeden = (int) ($_GET['irsaliye'] ?? 0) > 0;
$sid = (int) ($irsaliyeden ? $_GET['irsaliye'] : ($_GET['siparis'] ?? 0));
$st = $dbh->prepare("SELECT B.ID, B.NO, B.YON, B.TARIH, B.CARI_ID, B.DEPO_ID, B.PARA_BIRIMI, B.KUR, C.KOD, C.UNVAN
                     FROM dbo.LM_BELGE B LEFT JOIN dbo.LM_CARI C ON C.ID = B.CARI_ID
                     WHERE B.ID = :id AND B.TUR = :tur AND B.DURUM <> 9");
$st->execute([':id' => $sid, ':tur' => $irsaliyeden ? 2 : 1]);
$sip = $st->fetch(PDO::FETCH_ASSOC);
$gorur = $sip && ($irsaliyeden
    ? m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, (int) $sip['CARI_ID'])
    : m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, (int) $terminalkullanici, $sid));
if (!$gorur) {
    lumen_hata_sayfasi($irsaliyeden ? 'İrsaliye bulunamadı' : 'Sipariş bulunamadı', 'Faturalanacak belge bulunamadı veya iptal edilmiş.', 404);
}
$satirlar = lumen_siparis_kalan_satirlar($dbh, $sid);
$yon = (int) $sip['YON'];
$secenekler = $irsaliyeden ? [$yon === 1 ? 'satis_fatura' : 'alis_fatura']
    : ($yon === 1 ? ['satis_fatura', 'satis_irsaliye'] : ['alis_fatura', 'alis_irsaliye']);
$turler = lumen_belge_turleri();
$pb = (string) $sip['PARA_BIRIMI'];
$dovizli = $pb !== 'TRY';
// Kur önerisi: fatura gününün döviz alış kuru; tanımlı değilse siparişin kuru.
$kurOneri = $dovizli ? (lumen_kur($dbh, $pb, date('Y-m-d')) ?? (float) $sip['KUR']) : 1.0;
$satirFiyat = static fn(array $r): float => $dovizli
    ? ((float) ($r['DOVIZ_FIYAT'] ?? 0) > 0 ? (float) $r['DOVIZ_FIYAT'] : round((float) $r['FIYAT'] / max(0.000001, (float) $sip['KUR']), 6))
    : (float) $r['FIYAT'];
$para = static fn(float $v): string => $dovizli ? number_format($v, 2, ',', '.') . ' ' . $pb : btl($v);

belge_sayfa_basla(($irsaliyeden ? 'İrsaliyeden Fatura · ' : 'Siparişten Belge · ') . $sip['NO'], 'fa-file-export',
    $irsaliyeden ? 'goster.php?id=' . $sid : '../siparis/lg_fis.php?stokhareket=' . $sid);
?>
<?php if ($satirlar === []): ?>
    <div class="kart kart-ic" style="text-align:center;padding:30px">
        <div class="koyu"><?= $irsaliyeden ? 'Bu irsaliyenin faturalanmamış satırı yok.' : 'Bu siparişin faturalanacak/sevk edilecek kalan satırı yok.' ?></div>
        <div style="margin-top:12px"><a class="btn btn-ikincil" href="index.php">Belgelere git</a></div>
    </div>
<?php belge_sayfa_bitir(); exit; endif; ?>
<form method="post" action="islem.php">
    <?= csrf_field() ?>
    <input type="hidden" name="islem" value="kaydet">
    <input type="hidden" name="kaynak_belge_id" value="<?= (int) $sip['ID'] ?>">
    <input type="hidden" name="cari_id" value="<?= (int) $sip['CARI_ID'] ?>">
    <input type="hidden" name="depo_id" value="<?= (int) $sip['DEPO_ID'] ?>">
    <input type="hidden" name="para_birimi" value="<?= bh($pb) ?>">
    <div class="kart kart-ic izgara">
        <div><div class="soluk">Cari</div><div class="koyu"><?= bh(trim($sip['KOD'] . ' ' . $sip['UNVAN'])) ?></div></div>
        <label class="alan">Belge türü
            <select name="tur"><?php foreach ($secenekler as $k): ?><option value="<?= bh($k) ?>"><?= bh($turler[$k]['ad']) ?></option><?php endforeach; ?></select>
        </label>
        <label class="alan">Tarih <input type="date" name="tarih" value="<?= date('Y-m-d') ?>" required></label>
        <label class="alan">Vade <input type="date" name="vade"></label>
        <?php if ($dovizli): ?>
            <label class="alan">Kur (1 <?= bh($pb) ?> = ? TL) <input name="kur" inputmode="decimal" required
                value="<?= bh(str_replace('.', ',', rtrim(rtrim(number_format($kurOneri, 6, '.', ''), '0'), '.'))) ?>">
                <span class="soluk">Fatura günü döviz alış kuru önerilir; sipariş kuru: <?= bh(number_format((float) $sip['KUR'], 4, ',', '.')) ?></span></label>
        <?php endif; ?>
    </div>
    <div class="kart tablo-sar">
        <table class="liste">
            <thead><tr><th>Ürün</th><th class="sag"><?= $irsaliyeden ? 'İrsaliye' : 'Sipariş' ?></th><th class="sag"><?= $irsaliyeden ? 'Faturalanan' : 'Teslim edilen' ?></th><th style="width:130px">Bu belgede</th>
                <th class="sag">Fiyat</th><th class="sag">İsk. %</th><th class="sag">KDV %</th></tr></thead>
            <tbody>
            <?php foreach ($satirlar as $r): ?>
                <tr>
                    <td><span class="koyu"><?= bh($r['KOD']) ?></span> <?= bh($r['AD']) ?>
                        <input type="hidden" name="satir[stok_id][]" value="<?= (int) $r['STOK_ID'] ?>">
                        <input type="hidden" name="satir[birim_id][]" value="<?= (int) $r['BIRIM_ID'] ?>">
                        <input type="hidden" name="satir[kaynak_satir_id][]" value="<?= (int) $r['ID'] ?>">
                        <input type="hidden" name="satir[fiyat][]" value="<?= bh($satirFiyat($r)) ?>">
                        <input type="hidden" name="satir[iskonto][]" value="<?= bh($r['ISKONTO']) ?>">
                        <input type="hidden" name="satir[kdv][]" value="<?= bh($r['KDV_ORANI']) ?>"></td>
                    <td class="sag"><?= bmiktar($r['MIKTAR']) ?> <?= bh($r['BIRIM']) ?></td>
                    <td class="sag"><?= bmiktar($r['TESLIM_MIKTAR']) ?></td>
                    <td><input name="satir[miktar][]" inputmode="decimal" value="<?= bh(str_replace('.', ',', rtrim(rtrim(number_format((float) $r['KALAN'], 6, '.', ''), '0'), '.'))) ?>"></td>
                    <td class="sag"><?= $para($satirFiyat($r)) ?></td>
                    <td class="sag"><?= bmiktar($r['ISKONTO']) ?></td>
                    <td class="sag"><?= bmiktar($r['KDV_ORANI']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="kart kart-ic"><label class="alan">Açıklama <textarea name="aciklama" rows="2" maxlength="500"><?= bh(($irsaliyeden ? 'İrsaliye ' : 'Sipariş ') . $sip['NO']) ?></textarea></label></div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
        <button type="submit" class="btn btn-birincil"><i class="fa-solid fa-check"></i> Belgeyi kes</button>
    </div>
</form>
<?php belge_sayfa_bitir();

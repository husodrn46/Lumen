<?php

declare(strict_types=1);

/** Tanımlar — firma, kasa/banka hesapları, depolar, birimler, döviz kurları (yalnız bağımsız mod). */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

$sekmeler = tanim_sekmeleri();
$sekme = isset($sekmeler[(string) ($_GET['sekme'] ?? '')]) ? (string) $_GET['sekme'] : 'firma';
$duzenle = (int) ($_GET['duzenle'] ?? 0);

belge_sayfa_basla('Tanımlar', 'fa-sliders', '../index.php');
?>
<style>
    .sekmeler { display: flex; gap: 4px; overflow-x: auto; margin-bottom: 16px; border-bottom: 1px solid var(--border); }
    .sekmeler a { padding: 10px 14px; font-weight: 600; font-size: 13.5px; color: var(--text-2); text-decoration: none; border-bottom: 2.5px solid transparent; margin-bottom: -1px; white-space: nowrap; display: inline-flex; gap: 7px; align-items: center; }
    .sekmeler a.aktif { color: var(--red); border-bottom-color: var(--red); }
    .islemler { display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap; }
    .islemler form { display: inline; }
    .btn-kucuk { padding: 6px 10px; font-size: 12.5px; }
    tr.pasif td { color: var(--text-3); }
    .ipucu { font-size: 12.5px; color: var(--text-2); line-height: 1.5; margin-top: 4px; }
</style>
<nav class="sekmeler">
    <?php foreach ($sekmeler as $k => [$ad, $ikon]): ?>
        <a href="?sekme=<?= bh($k) ?>" class="<?= $k === $sekme ? 'aktif' : '' ?>"><i class="fa-solid <?= bh($ikon) ?>"></i><?= bh($ad) ?></a>
    <?php endforeach; ?>
    <a href="ice_aktar.php"><i class="fa-solid fa-file-excel"></i>Excel'den aktar</a>
</nav>
<?php
$durumDugmesi = static function (string $tur, int $id, bool $aktif): string {
    return '<form method="post" action="islem.php">' . csrf_field() . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="islem" value="' . $tur . ($aktif ? '_pasif' : '_aktif') . '">'
        . '<button class="btn btn-ikincil btn-kucuk" type="submit">' . ($aktif ? 'Pasifleştir' : 'Etkinleştir') . '</button></form>';
};

if ($sekme === 'firma'):
    $f = $dbh->query("SELECT ANAHTAR, DEGER FROM dbo.LM_AYAR WHERE ANAHTAR LIKE 'firma[_]%'")->fetchAll(PDO::FETCH_KEY_PAIR); ?>
    <form method="post" action="islem.php" class="kart kart-ic">
        <?= csrf_field() ?><input type="hidden" name="islem" value="firma_kaydet">
        <p class="ipucu" style="margin-bottom:14px">Fatura ve irsaliye çıktılarında, ekstrede ve raporlarda görünür.</p>
        <div class="izgara">
            <?php foreach (lumen_tanim_firma_alanlari() as $k => [$etiket, $uzunluk]): ?>
                <label class="alan" <?= $k === 'adres' ? 'style="grid-column:1/-1"' : '' ?>><?= bh($etiket) ?>
                    <input name="<?= bh($k) ?>" maxlength="<?= (int) $uzunluk ?>" value="<?= bh($f['firma_' . $k] ?? '') ?>" <?= $k === 'unvan' ? 'required' : '' ?>
                        <?= $k === 'vergi_no' ? 'inputmode="numeric"' : '' ?> <?= $k === 'eposta' ? 'type="email"' : '' ?>></label>
            <?php endforeach; ?>
        </div>
        <label class="alan" style="margin-top:14px; max-width:260px">Dönem kilidi (bu tarih ve öncesi kapalı)
            <input type="date" name="donem_kilit" max="<?= date('Y-m-d') ?>" value="<?= bh($dbh->query("SELECT DEGER FROM dbo.LM_AYAR WHERE ANAHTAR = 'donem_kilit_tarihi'")->fetchColumn() ?: '') ?>"></label>
        <p class="ipucu">Kapanan ay/yıl için tarih girin: o tarih ve öncesine fatura veya kasa fişi girilemez, iptal edilemez. Boş: kilit yok.</p>
        <div style="margin-top:14px"><button class="btn btn-birincil" type="submit"><i class="fa-solid fa-check"></i> Kaydet</button></div>
    </form>

<?php elseif ($sekme === 'hesap'):
    $hesaplar = $dbh->query("SELECT H.*, ISNULL((SELECT SUM(R.GIRIS - R.CIKIS) FROM dbo.LM_FINANS_HAREKET R WHERE R.HESAP_ID = H.ID), 0) AS BAKIYE
                             FROM dbo.LM_FINANS_HESAP H ORDER BY H.AKTIF DESC, H.TUR, H.KOD")->fetchAll(PDO::FETCH_ASSOC);
    $e = $duzenle > 0 ? (array_values(array_filter($hesaplar, static fn($h) => (int) $h['ID'] === $duzenle))[0] ?? null) : null; ?>
    <form method="post" action="islem.php" class="kart kart-ic">
        <?= csrf_field() ?><input type="hidden" name="islem" value="hesap_kaydet"><input type="hidden" name="id" value="<?= (int) ($e['ID'] ?? 0) ?>">
        <div class="kart-baslik" style="padding:0 0 12px"><i class="fa-solid <?= $e ? 'fa-pen' : 'fa-plus' ?>"></i><?= $e ? 'Hesabı düzenle' : 'Yeni hesap' ?></div>
        <div class="izgara">
            <label class="alan">Tür <select name="tur" id="hesapTur">
                <option value="1" <?= (int) ($e['TUR'] ?? 1) === 1 ? 'selected' : '' ?>>Kasa</option>
                <option value="2" <?= (int) ($e['TUR'] ?? 1) === 2 ? 'selected' : '' ?>>Banka</option></select></label>
            <label class="alan">Kod <input name="kod" required maxlength="30" value="<?= bh($e['KOD'] ?? '') ?>" placeholder="KASA.02"></label>
            <label class="alan">Ad <input name="ad" required maxlength="120" value="<?= bh($e['AD'] ?? '') ?>"></label>
            <label class="alan banka">Banka <input name="banka_adi" maxlength="80" value="<?= bh($e['BANKA_ADI'] ?? '') ?>"></label>
            <label class="alan banka">IBAN <input name="iban" maxlength="34" value="<?= bh($e['IBAN'] ?? '') ?>" placeholder="TR.."></label>
        </div>
        <div style="margin-top:14px; display:flex; gap:8px">
            <button class="btn btn-birincil" type="submit"><i class="fa-solid fa-check"></i> Kaydet</button>
            <?php if ($e): ?><a class="btn btn-ikincil" href="?sekme=hesap">Vazgeç</a><?php endif; ?>
        </div>
    </form>
    <script>
        (function () { var t = document.getElementById('hesapTur'); function g() { document.querySelectorAll('.banka').forEach(function (e) { e.style.display = t.value === '2' ? '' : 'none'; }); } t.addEventListener('change', g); g(); })();
    </script>
    <div class="kart tablo-sar"><table class="liste">
        <thead><tr><th>Kod</th><th>Ad</th><th>Tür</th><th>IBAN</th><th class="sag">Bakiye</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($hesaplar as $h): $aktif = (int) $h['AKTIF'] === 1; ?>
            <tr class="<?= $aktif ? '' : 'pasif' ?>">
                <td class="koyu"><?= bh($h['KOD']) ?></td><td><?= bh($h['AD']) ?><?= $aktif ? '' : ' <span class="rozet">Pasif</span>' ?></td>
                <td><?= (int) $h['TUR'] === 1 ? 'Kasa' : 'Banka' . ($h['BANKA_ADI'] ? ' · ' . bh($h['BANKA_ADI']) : '') ?></td>
                <td class="soluk"><?= bh($h['IBAN'] ?? '') ?></td>
                <td class="sag koyu"><?= btl($h['BAKIYE']) ?></td>
                <td><div class="islemler"><a class="btn btn-ikincil btn-kucuk" href="?sekme=hesap&duzenle=<?= (int) $h['ID'] ?>">Düzenle</a><?= $durumDugmesi('hesap', (int) $h['ID'], $aktif) ?></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>

<?php elseif ($sekme === 'depo'):
    $depolar = $dbh->query("SELECT D.*, ISNULL((SELECT SUM(M.MIKTAR) FROM dbo.LMV_STOK_MIKTAR M WHERE M.DEPO_ID = D.ID), 0) AS MIKTAR,
                                   (SELECT COUNT(*) FROM dbo.LMV_STOK_MIKTAR M WHERE M.DEPO_ID = D.ID AND ABS(M.MIKTAR) > 0.000001) AS CESIT
                            FROM dbo.LM_DEPO D ORDER BY D.AKTIF DESC, D.NO")->fetchAll(PDO::FETCH_ASSOC);
    $e = $duzenle > 0 ? (array_values(array_filter($depolar, static fn($d) => (int) $d['ID'] === $duzenle))[0] ?? null) : null;
    $sonrakiNo = $depolar === [] ? 0 : max(array_map(static fn($d) => (int) $d['NO'], $depolar)) + 1; ?>
    <form method="post" action="islem.php" class="kart kart-ic">
        <?= csrf_field() ?><input type="hidden" name="islem" value="depo_kaydet"><input type="hidden" name="id" value="<?= (int) ($e['ID'] ?? 0) ?>">
        <div class="kart-baslik" style="padding:0 0 12px"><i class="fa-solid <?= $e ? 'fa-pen' : 'fa-plus' ?>"></i><?= $e ? 'Depoyu düzenle' : 'Yeni depo' ?></div>
        <div class="izgara">
            <label class="alan">Kod <input name="kod" required maxlength="20" value="<?= bh($e['KOD'] ?? '') ?>" placeholder="SUBE1"></label>
            <label class="alan">Ad <input name="ad" required maxlength="80" value="<?= bh($e['AD'] ?? '') ?>"></label>
            <label class="alan">Ambar no <input name="no" type="number" min="0" max="999" required value="<?= (int) ($e['NO'] ?? $sonrakiNo) ?>"></label>
        </div>
        <p class="ipucu">Ambar no, kullanıcıların varsayılan deposu ve LOGO uyumlu raporlar için kullanılır; 0 merkez depodur.</p>
        <div style="margin-top:12px; display:flex; gap:8px">
            <button class="btn btn-birincil" type="submit"><i class="fa-solid fa-check"></i> Kaydet</button>
            <?php if ($e): ?><a class="btn btn-ikincil" href="?sekme=depo">Vazgeç</a><?php endif; ?>
        </div>
    </form>
    <div class="kart tablo-sar"><table class="liste">
        <thead><tr><th>Ambar</th><th>Kod</th><th>Ad</th><th class="sag">Stoklu ürün</th><th class="sag">Toplam miktar</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($depolar as $d): $aktif = (int) $d['AKTIF'] === 1; ?>
            <tr class="<?= $aktif ? '' : 'pasif' ?>">
                <td><?= (int) $d['NO'] ?></td><td class="koyu"><?= bh($d['KOD']) ?></td><td><?= bh($d['AD']) ?><?= $aktif ? '' : ' <span class="rozet">Pasif</span>' ?></td>
                <td class="sag"><?= (int) $d['CESIT'] ?></td><td class="sag"><?= bmiktar($d['MIKTAR']) ?></td>
                <td><div class="islemler"><a class="btn btn-ikincil btn-kucuk" href="?sekme=depo&duzenle=<?= (int) $d['ID'] ?>">Düzenle</a><?= (int) $d['NO'] === 0 ? '' : $durumDugmesi('depo', (int) $d['ID'], $aktif) ?></div></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>

<?php elseif ($sekme === 'birim'):
    $birimler = $dbh->query("SELECT B.*, (SELECT COUNT(*) FROM dbo.LM_STOK_BIRIM SB WHERE SB.BIRIM_ID = B.ID) AS URUN FROM dbo.LM_BIRIM B ORDER BY B.KOD")->fetchAll(PDO::FETCH_ASSOC);
    $e = $duzenle > 0 ? (array_values(array_filter($birimler, static fn($b) => (int) $b['ID'] === $duzenle))[0] ?? null) : null; ?>
    <form method="post" action="islem.php" class="kart kart-ic">
        <?= csrf_field() ?><input type="hidden" name="islem" value="birim_kaydet"><input type="hidden" name="id" value="<?= (int) ($e['ID'] ?? 0) ?>">
        <div class="kart-baslik" style="padding:0 0 12px"><i class="fa-solid <?= $e ? 'fa-pen' : 'fa-plus' ?>"></i><?= $e ? 'Birimi düzenle' : 'Yeni birim' ?></div>
        <div class="izgara">
            <label class="alan">Kod <input name="kod" required maxlength="20" value="<?= bh($e['KOD'] ?? '') ?>" placeholder="KOLI"></label>
            <label class="alan">Ad <input name="ad" required maxlength="60" value="<?= bh($e['AD'] ?? '') ?>" placeholder="Koli"></label>
        </div>
        <p class="ipucu">Ürünün hangi birimlerle satılacağı ve çarpanı (ör. 1 koli = 24 adet) ürün kartında belirlenir.</p>
        <div style="margin-top:12px; display:flex; gap:8px">
            <button class="btn btn-birincil" type="submit"><i class="fa-solid fa-check"></i> Kaydet</button>
            <?php if ($e): ?><a class="btn btn-ikincil" href="?sekme=birim">Vazgeç</a><?php endif; ?>
        </div>
    </form>
    <div class="kart tablo-sar"><table class="liste">
        <thead><tr><th>Kod</th><th>Ad</th><th class="sag">Kullanan ürün</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($birimler as $b): ?>
            <tr><td class="koyu"><?= bh($b['KOD']) ?></td><td><?= bh($b['AD']) ?></td><td class="sag"><?= (int) $b['URUN'] ?></td>
                <td><div class="islemler"><a class="btn btn-ikincil btn-kucuk" href="?sekme=birim&duzenle=<?= (int) $b['ID'] ?>">Düzenle</a>
                    <?php if ((int) $b['URUN'] === 0): ?><form method="post" action="islem.php"><?= csrf_field() ?><input type="hidden" name="islem" value="birim_sil">
                        <input type="hidden" name="id" value="<?= (int) $b['ID'] ?>"><button class="btn btn-ikincil btn-kucuk" type="submit">Sil</button></form><?php endif; ?></div></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>

<?php else:
    $pbler = $dbh->query("SELECT KOD, AD FROM dbo.LM_PARA_BIRIMI WHERE KOD <> 'TRY' AND AKTIF = 1 ORDER BY KOD")->fetchAll(PDO::FETCH_KEY_PAIR);
    $kurlar = $dbh->query('SELECT TOP 60 TARIH, PARA_BIRIMI, ALIS, SATIS FROM dbo.LM_KUR ORDER BY TARIH DESC, PARA_BIRIMI')->fetchAll(PDO::FETCH_ASSOC); ?>
    <div class="kart kart-ic">
        <div class="kart-baslik" style="padding:0 0 12px"><i class="fa-solid fa-coins"></i>Kur gir</div>
        <form method="post" action="islem.php" class="izgara" style="align-items:end">
            <?= csrf_field() ?><input type="hidden" name="islem" value="kur_kaydet">
            <label class="alan">Tarih <input type="date" name="tarih" value="<?= date('Y-m-d') ?>" required></label>
            <label class="alan">Para birimi <select name="pb"><?php foreach ($pbler as $k => $ad): ?><option value="<?= bh($k) ?>"><?= bh($k . ' — ' . $ad) ?></option><?php endforeach; ?></select></label>
            <label class="alan">Döviz alış <input name="alis" inputmode="decimal" required placeholder="41,1000"></label>
            <label class="alan">Döviz satış <input name="satis" inputmode="decimal" required placeholder="41,2500"></label>
            <div><button class="btn btn-birincil" type="submit"><i class="fa-solid fa-check"></i> Kaydet</button></div>
        </form>
        <form method="post" action="islem.php" style="margin-top:12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap">
            <?= csrf_field() ?><input type="hidden" name="islem" value="kur_tcmb">
            <button class="btn btn-ikincil" type="submit"><i class="fa-solid fa-cloud-arrow-down"></i> Bugünün kurlarını TCMB'den al</button>
            <span class="ipucu">Dövizli faturada belge tarihinin döviz <b>alış</b> kuru önerilir (yoksa önceki en yakın gün).</span>
        </form>
    </div>
    <div class="kart tablo-sar"><table class="liste">
        <thead><tr><th>Tarih</th><th>Para birimi</th><th class="sag">Döviz alış</th><th class="sag">Döviz satış</th></tr></thead>
        <tbody>
        <?php if ($kurlar === []): ?><tr><td colspan="4" class="soluk" style="text-align:center;padding:22px">Henüz kur girilmedi.</td></tr><?php endif; ?>
        <?php foreach ($kurlar as $k): ?>
            <tr><td><?= bh(date('d.m.Y', (int) strtotime((string) $k['TARIH']))) ?></td><td class="koyu"><?= bh($k['PARA_BIRIMI']) ?></td>
                <td class="sag"><?= bh(number_format((float) $k['ALIS'], 4, ',', '.')) ?></td><td class="sag"><?= bh(number_format((float) $k['SATIS'], 4, ',', '.')) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
<?php endif;
belge_sayfa_bitir();

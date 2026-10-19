<?php

declare(strict_types=1);

/** Yeni kasa/banka fişi: tahsilat, ödeme, virman, masraf — yalnız bağımsız mod. */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

$turler = lumen_finans_turleri();
unset($turler[5]); // Açılış fişi kurulumda / içe aktarmada kesilir.
$secTur = (int) ($_GET['tur'] ?? 1);
if (!isset($turler[$secTur])) {
    $secTur = 1;
}
$hesaplar = finans_hesaplar($dbh);
$istek = bin2hex(random_bytes(16));

belge_sayfa_basla('Yeni Kasa/Banka Fişi', 'fa-money-bill-transfer', 'index.php');
?>
<style>
    .oneriler { position: absolute; left: 0; right: 0; top: 100%; z-index: 30; background: #fff; border: 1px solid var(--border);
                border-radius: 12px; box-shadow: 0 12px 28px rgba(0,0,0,.1); max-height: 320px; overflow: auto; display: none; }
    .oneriler.acik { display: block; }
    .oneri { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
    .oneri:hover, .oneri:focus { background: var(--red-soft); outline: none; }
</style>
<form method="post" action="islem.php" id="fisForm">
    <?= csrf_field() ?>
    <input type="hidden" name="islem" value="kaydet">
    <input type="hidden" name="istek_id" value="<?= bh($istek) ?>">
    <div class="kart kart-ic izgara">
        <label class="alan">Fiş türü
            <select name="tur" id="tur">
                <?php foreach ($turler as $k => $t): ?>
                    <option value="<?= $k ?>" data-cari="<?= $t['cari'] ? 1 : 0 ?>" data-karsi="<?= $t['karsi'] ? 1 : 0 ?>" <?= $k === $secTur ? 'selected' : '' ?>><?= bh($t['ad']) ?></option>
                <?php endforeach; ?>
            </select></label>
        <label class="alan">Tarih <input type="date" name="tarih" value="<?= date('Y-m-d') ?>" required></label>
        <label class="alan"><span id="hesapEtiket">Kasa / banka</span>
            <select name="hesap_id" id="hesap" required>
                <?php foreach ($hesaplar as $h): ?><option value="<?= (int) $h['ID'] ?>"><?= bh($h['KOD'] . ' ' . $h['AD']) ?> (<?= btl($h['BAKIYE']) ?>)</option><?php endforeach; ?>
            </select></label>
        <label class="alan" id="karsiAlan">Karşı hesap (giriş)
            <select name="karsi_hesap_id" id="karsi">
                <?php foreach ($hesaplar as $i => $h): ?><option value="<?= (int) $h['ID'] ?>" <?= $i === 1 ? 'selected' : '' ?>><?= bh($h['KOD'] . ' ' . $h['AD']) ?></option><?php endforeach; ?>
            </select></label>
        <label class="alan" id="cariAlan" style="position:relative">Cari
            <input type="text" id="cariAra" placeholder="Cari kodu veya unvan…" autocomplete="off">
            <input type="hidden" name="cari_id" id="cariId">
            <div id="cariSonuc" class="oneriler"></div></label>
        <label class="alan">Tutar (₺) <input name="tutar" id="tutar" inputmode="decimal" required placeholder="0,00"></label>
    </div>
    <div class="kart kart-ic"><label class="alan">Açıklama <textarea name="aciklama" rows="2" maxlength="400"></textarea></label></div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
        <a href="index.php" class="btn btn-ikincil">Vazgeç</a>
        <button type="submit" class="btn btn-birincil" id="kaydet" disabled><i class="fa-solid fa-check"></i> Fişi kaydet</button>
    </div>
</form>
<script>
(function () {
    var tur = document.getElementById('tur'), cariId = document.getElementById('cariId'), cariAra = document.getElementById('cariAra');
    var kutu = document.getElementById('cariSonuc'), tutar = document.getElementById('tutar');
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    var secili = function () { return tur.options[tur.selectedIndex].dataset; };
    function kontrol() {
        var s = secili(), t = parseFloat(String(tutar.value).replace(/\./g, '').replace(',', '.'));
        var cariTamam = s.cari !== '1' || cariId.value !== '';
        var karsiTamam = s.karsi !== '1' || document.getElementById('karsi').value !== document.getElementById('hesap').value;
        document.getElementById('kaydet').disabled = !(t > 0 && cariTamam && karsiTamam);
    }
    function gorunurluk() {
        var s = secili();
        document.getElementById('cariAlan').style.display = s.cari === '1' ? '' : 'none';
        document.getElementById('karsiAlan').style.display = s.karsi === '1' ? '' : 'none';
        document.getElementById('hesapEtiket').textContent = s.karsi === '1' ? 'Çıkış hesabı' : 'Kasa / banka';
        if (s.cari !== '1') { cariId.value = ''; cariAra.value = ''; }
        kontrol();
    }
    var zaman;
    cariAra.addEventListener('input', function () {
        cariId.value = ''; kontrol(); clearTimeout(zaman);
        var q = cariAra.value.trim();
        if (q.length < 2) { kutu.classList.remove('acik'); return; }
        zaman = setTimeout(function () {
            fetch('../cari/ajax_cari_search.php?term=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); }).then(function (liste) {
                    kutu.innerHTML = '';
                    (liste || []).forEach(function (o) {
                        var d = document.createElement('div'); d.className = 'oneri'; d.tabIndex = 0; d.innerHTML = esc(o.text);
                        d.addEventListener('click', function () { cariId.value = o.id; cariAra.value = o.text; kutu.classList.remove('acik'); kontrol(); });
                        d.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); d.click(); } });
                        kutu.appendChild(d);
                    });
                    if (!kutu.children.length) { kutu.innerHTML = '<div class="oneri soluk">Sonuç yok</div>'; }
                    kutu.classList.add('acik');
                }).catch(function () { kutu.classList.remove('acik'); });
        }, 250);
    });
    document.addEventListener('click', function (e) { if (!kutu.contains(e.target) && e.target !== cariAra) kutu.classList.remove('acik'); });
    ['change', 'input'].forEach(function (ev) { document.getElementById('fisForm').addEventListener(ev, kontrol); });
    tur.addEventListener('change', gorunurluk);
    document.getElementById('fisForm').addEventListener('submit', function () { document.getElementById('kaydet').disabled = true; });
    gorunurluk();
})();
</script>
<?php belge_sayfa_bitir();

<?php

declare(strict_types=1);

/** Yeni belge (fatura, iade, irsaliye, üretim girişi, sayım) — yalnız bağımsız mod. */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

$turler = $belgeTurleri;
$tur = isset($turler[(string) ($_GET['tur'] ?? '')]) ? (string) $_GET['tur'] : (string) array_key_first($turler);
$depolar = $dbh->query('SELECT ID, AD FROM dbo.LM_DEPO WHERE AKTIF = 1 ORDER BY NO')->fetchAll(PDO::FETCH_ASSOC);
$vadeGun = 0;

belge_sayfa_basla('Yeni Belge', 'fa-file-circle-plus', 'index.php');
?>
<form method="post" action="islem.php" id="belgeForm" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="islem" value="kaydet">
    <div class="kart kart-ic izgara">
        <label class="alan">Belge türü
            <select name="tur" id="tur">
                <?php foreach ($turler as $kod => $t): ?>
                    <option value="<?= bh($kod) ?>" data-yon="<?= (int) $t['yon'] ?>" data-cari="<?= $t['cari_zorunlu'] ? 1 : 0 ?>"
                        <?= $kod === $tur ? 'selected' : '' ?>><?= bh($t['ad']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="alan" id="cariAlan" style="position:relative">Cari
            <input type="text" id="cariAra" placeholder="Cari kodu veya unvan…">
            <input type="hidden" name="cari_id" id="cariId">
            <div id="cariSonuc" class="oneriler"></div>
        </label>
        <label class="alan">Tarih <input type="date" name="tarih" value="<?= date('Y-m-d') ?>" required></label>
        <label class="alan">Vade <input type="date" name="vade"></label>
        <label class="alan">Depo
            <select name="depo_id"><?php foreach ($depolar as $d): ?><option value="<?= (int) $d['ID'] ?>"><?= bh($d['AD']) ?></option><?php endforeach; ?></select>
        </label>
        <label class="alan">Belge / dış no <input type="text" name="dokuman_no" maxlength="40"></label>
    </div>

    <div class="kart">
        <div class="kart-baslik"><i class="fa-solid fa-list"></i> Satırlar</div>
        <div class="kart-ic" style="position:relative">
            <input type="search" id="urunAra" placeholder="Ürün kodu, adı veya barkod ile ekle…">
            <div id="urunSonuc" class="oneriler"></div>
        </div>
        <div class="tablo-sar">
            <table class="liste" id="satirlar">
                <thead><tr><th>Ürün</th><th style="width:110px">Miktar</th><th style="width:100px">Birim</th><th style="width:120px">Fiyat (KDV hariç)</th>
                    <th style="width:80px">İsk. %</th><th style="width:80px">KDV %</th><th class="sag" style="width:130px">Tutar</th><th style="width:44px"></th></tr></thead>
                <tbody><tr class="bos"><td colspan="8" class="soluk" style="text-align:center;padding:22px">Henüz satır yok — yukarıdan ürün arayın.</td></tr></tbody>
            </table>
        </div>
        <div class="toplamlar">
            <span>Brüt</span><span class="sag" id="tBrut">0,00 ₺</span>
            <span>İskonto</span><span class="sag" id="tIsk">0,00 ₺</span>
            <span>KDV</span><span class="sag" id="tKdv">0,00 ₺</span>
            <span class="genel">Genel toplam</span><span class="sag genel" id="tGenel">0,00 ₺</span>
        </div>
    </div>

    <div class="kart kart-ic"><label class="alan">Açıklama <textarea name="aciklama" rows="2" maxlength="500"></textarea></label></div>
    <div style="display:flex;justify-content:flex-end;gap:10px">
        <a href="index.php" class="btn btn-ikincil">Vazgeç</a>
        <button type="submit" class="btn btn-birincil" id="kaydet" disabled><i class="fa-solid fa-check"></i> Belgeyi kes</button>
    </div>
</form>
<style>
    .oneriler { position: absolute; left: 18px; right: 18px; top: 100%; z-index: 30; background: #fff; border: 1px solid var(--border);
                border-radius: 12px; box-shadow: 0 12px 28px rgba(0,0,0,.1); max-height: 320px; overflow: auto; display: none; }
    #cariAlan .oneriler { left: 0; right: 0; }
    .oneriler.acik { display: block; }
    .oneri { padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #f3f4f6; font-size: 13px; }
    .oneri:hover, .oneri:focus { background: var(--red-soft); outline: none; }
    #satirlar input, #satirlar select { padding: 7px 8px; font-size: 13px; }
</style>
<script>
(function () {
    var tl = function (n) { return n.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ₺'; };
    var r2 = function (n) { return Math.round((n + Number.EPSILON) * 100) / 100; };
    var sayi = function (v) { var n = parseFloat(String(v).replace(',', '.')); return isNaN(n) ? 0 : n; };
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    var tur = document.getElementById('tur'), tbody = document.querySelector('#satirlar tbody');
    var yon = function () { return tur.options[tur.selectedIndex].dataset.yon; };

    function cariGorunurluk() {
        var zorunlu = tur.options[tur.selectedIndex].dataset.cari === '1';
        document.getElementById('cariAlan').style.display = zorunlu ? '' : 'none';
        if (!zorunlu) { document.getElementById('cariId').value = ''; document.getElementById('cariAra').value = ''; }
        hesapla();
    }
    tur.addEventListener('change', cariGorunurluk);

    function hesapla() {
        var b = 0, i = 0, k = 0, n = 0, satir = 0;
        tbody.querySelectorAll('tr.satir').forEach(function (tr) {
            var m = sayi(tr.querySelector('[name="satir[miktar][]"]').value), f = sayi(tr.querySelector('[name="satir[fiyat][]"]').value);
            var io = sayi(tr.querySelector('[name="satir[iskonto][]"]').value), ko = sayi(tr.querySelector('[name="satir[kdv][]"]').value);
            var tutar = r2(m * f), isk = r2(tutar * io / 100), net = r2(tutar - isk), kdv = r2(net * ko / 100);
            tr.querySelector('.tutar').textContent = tl(net);
            b += tutar; i += isk; k += kdv; n += net; if (m > 0) satir++;
        });
        document.getElementById('tBrut').textContent = tl(b); document.getElementById('tIsk').textContent = tl(i);
        document.getElementById('tKdv').textContent = tl(k); document.getElementById('tGenel').textContent = tl(r2(n + k));
        var cariTamam = tur.options[tur.selectedIndex].dataset.cari !== '1' || document.getElementById('cariId').value !== '';
        document.getElementById('kaydet').disabled = !(satir > 0 && cariTamam);
    }

    function satirEkle(u) {
        var bos = tbody.querySelector('tr.bos'); if (bos) bos.remove();
        var tr = document.createElement('tr'); tr.className = 'satir';
        var birimler = u.birimler.map(function (b) { return '<option value="' + b.id + '">' + esc(b.kod) + (b.carpan !== 1 ? ' (' + b.carpan + ')' : '') + '</option>'; }).join('');
        tr.innerHTML = '<td><input type="hidden" name="satir[stok_id][]" value="' + u.id + '"><span class="koyu">' + esc(u.kod) + '</span> ' + esc(u.ad)
            + '<div class="soluk">Eldeki: ' + u.eldeki.toLocaleString('tr-TR') + '</div></td>'
            + '<td><input name="satir[miktar][]" inputmode="decimal" value="1"></td>'
            + '<td><select name="satir[birim_id][]">' + birimler + '</select></td>'
            + '<td><input name="satir[fiyat][]" inputmode="decimal" value="' + u.fiyat.toFixed(2).replace('.', ',') + '"></td>'
            + '<td><input name="satir[iskonto][]" inputmode="decimal" value="0"></td>'
            + '<td><input name="satir[kdv][]" inputmode="decimal" value="' + u.kdv + '"></td>'
            + '<td class="sag koyu tutar"></td>'
            + '<td><button type="button" class="btn btn-ikincil sil" title="Satırı sil"><i class="fa-solid fa-xmark"></i></button></td>';
        tbody.appendChild(tr);
        tr.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', hesapla); });
        tr.querySelector('.sil').addEventListener('click', function () { tr.remove(); hesapla(); });
        hesapla();
        tr.querySelector('[name="satir[miktar][]"]').select();
    }

    function aramaKur(girdi, kutu, url, yaz, sec) {
        var zaman;
        girdi.addEventListener('input', function () {
            clearTimeout(zaman);
            var q = girdi.value.trim();
            if (q.length < 2) { kutu.classList.remove('acik'); return; }
            zaman = setTimeout(function () {
                fetch(url(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (liste) {
                    kutu.innerHTML = '';
                    (liste || []).forEach(function (o) {
                        var d = document.createElement('div'); d.className = 'oneri'; d.tabIndex = 0; d.innerHTML = yaz(o);
                        d.addEventListener('click', function () { sec(o); kutu.classList.remove('acik'); });
                        d.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); d.click(); } });
                        kutu.appendChild(d);
                    });
                    if (!kutu.children.length) { kutu.innerHTML = '<div class="oneri soluk">Sonuç yok</div>'; }
                    kutu.classList.add('acik');
                }).catch(function () { kutu.classList.remove('acik'); });
            }, 250);
        });
        document.addEventListener('click', function (e) { if (!kutu.contains(e.target) && e.target !== girdi) kutu.classList.remove('acik'); });
    }

    aramaKur(document.getElementById('cariAra'), document.getElementById('cariSonuc'),
        function (q) { return '../cari/ajax_cari_search.php?term=' + encodeURIComponent(q); },
        function (o) { return esc(o.text); },
        function (o) { document.getElementById('cariId').value = o.id; document.getElementById('cariAra').value = o.text; hesapla(); });
    aramaKur(document.getElementById('urunAra'), document.getElementById('urunSonuc'),
        function (q) { return 'stok_ara.php?yon=' + yon() + '&q=' + encodeURIComponent(q); },
        function (u) { return '<span class="koyu">' + esc(u.kod) + '</span> ' + esc(u.ad) + ' <span class="soluk">· ' + tl(u.fiyat) + ' · eldeki ' + u.eldeki.toLocaleString('tr-TR') + '</span>'; },
        function (u) { satirEkle(u); document.getElementById('urunAra').value = ''; });

    document.getElementById('cariAra').addEventListener('input', function () { document.getElementById('cariId').value = ''; hesapla(); });
    document.getElementById('belgeForm').addEventListener('submit', function () { document.getElementById('kaydet').disabled = true; });
    cariGorunurluk();
})();
</script>
<?php belge_sayfa_bitir();

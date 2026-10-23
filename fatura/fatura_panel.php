<?php

declare(strict_types=1);

/**
 * fatura_panel.php — Faturalama paneli (BETA, izole).
 * Sipariş ekranlarına dokunmadan faturalamayı tek bir ekranda toplar:
 * faturalanabilir siparişler + bu uygulamayla kesilmiş (geri alınabilir) faturalar.
 *
 * ⚠️ Bu modül KDV'siz fatura keser (TOTALVAT = 0), muhasebe fişi ve e-fatura
 * oluşturmaz. Ayrıntı ve sınırlar için fatura_lib.php başlığına bakın.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/../kontrol.php');
include_once(__DIR__ . '/../donem_helper.php');
include_once(__DIR__ . '/../log_ip.php');
include_once(__DIR__ . '/fatura_lib.php');

global $dbh, $firma, $firmadonem, $terminalkullanici;
global $faturalama_aktif;

// --- Erişim guard ---
if (empty($faturalama_aktif)) {
    lumen_hata_sayfasi('Faturalama kapalı', 'Bu özellik şu an kapalı. Yönetici Sistem Ayarları > Faturalama bölümünden açabilir.', 403, 'fa-lock');
}
if ((int) m_p_yetki($terminalkullanici, 'M31') !== 1) {
    lumen_hata_sayfasi('Faturalama yetkiniz yok', 'Bu ekran için M31 (Faturalama) yetkisi gerekir.', 403, 'fa-lock');
}

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$tl = static fn($v): string => number_format((float) $v, 2, ',', '.') . ' ₺';

fatura_log_tablo_olustur($dbh);
$ara = trim((string) ($_GET['ara'] ?? ''));

// Özel cari kısıtı: kısıtlı carilerin siparişleri/faturaları listelenmez.
$ozelSip = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'o.CLIENTREF', 'fat_sip');
$ozelLog = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'l.CLIENTREF', 'fat_log');

$faturalanabilir = [];
$faturalanmis = [];
$hata = '';
try {
    $kosul = '';
    $p = $ozelSip['params'];
    if ($ozelSip['sql'] !== '') {
        $kosul .= ' AND ' . $ozelSip['sql'];
    }
    if ($ara !== '') {
        // PDO_SQLSRV aynı adlı parametreyi tekrar kullanamaz; üç ayrı parametre.
        $kosul .= ' AND (' . lumen_arama_sql('c.DEFINITION_') . ' LIKE :ara1 OR ' . lumen_arama_sql('c.CODE')
            . ' LIKE :ara2 OR ' . lumen_arama_sql('o.FICHENO') . ' LIKE :ara3)';
        $t = '%' . lumen_arama_terimi($ara) . '%';
        $p += [':ara1' => $t, ':ara2' => $t, ':ara3' => $t];
    }
    // Dövizli siparişler hariç (beta: yalnız TL); iptal edilmişler hariç.
    $q = $dbh->prepare("
        SELECT TOP 50 o.LOGICALREF, o.FICHENO, o.DATE_, o.NETTOTAL, o.CLIENTREF, c.CODE, c.DEFINITION_,
               -- Kesilecek fatura KDV'siz: tutar mal satırlarının neti (fatura_olustur_core ile aynı)
               (SELECT SUM(l.LINENET) FROM {$firmadonem}ORFLINE l WHERE l.ORDFICHEREF = o.LOGICALREF AND l.LINETYPE = 0) AS FATURA_NET
        FROM {$firmadonem}ORFICHE o
        JOIN {$firma}CLCARD c ON c.LOGICALREF = o.CLIENTREF
        WHERE o.TRCODE = 1 AND ISNULL(o.TRCURR, 0) = 0 AND ISNULL(o.CANCELLED, 0) = 0 AND o.NETTOTAL > 0
          AND NOT EXISTS (SELECT 1 FROM {$firmadonem}STLINE s WHERE s.ORDFICHEREF = o.LOGICALREF AND s.INVOICEREF > 0)
          {$kosul}
        ORDER BY o.LOGICALREF DESC");
    $q->execute($p);
    $faturalanabilir = $q->fetchAll(PDO::FETCH_ASSOC);

    $q2 = $dbh->prepare("
        SELECT l.ID, l.INVOICE_FICHENO, l.SIPARIS_NO, l.NETTOTAL, l.OLUSTURMA, l.CLIENTREF, c.DEFINITION_
        FROM M_FATURA_LOG l
        LEFT JOIN {$firma}CLCARD c ON c.LOGICALREF = l.CLIENTREF
        WHERE l.DURUM = 'AKTIF' AND l.FIRMADONEM = :fd" . ($ozelLog['sql'] !== '' ? ' AND ' . $ozelLog['sql'] : '') . "
        ORDER BY l.ID DESC");
    $q2->execute([':fd' => $firmadonem] + $ozelLog['params']);
    $faturalanmis = $q2->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('fatura_panel: ' . $e->getMessage());
    $hata = 'Liste okunamadı. Ayrıntı sunucu logunda.';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lumen - Faturalama (BETA)</title>
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
    <style>
        * { box-sizing: border-box; margin: 0; }
        body { font-family: var(--font); background: var(--bg); color: var(--text-1); min-height: 100vh; font-size: 14px; }
        .beta-rozet { font-size: 10.5px; font-weight: 700; letter-spacing: .5px; padding: 3px 9px; border-radius: 8px; background: var(--amber-soft); color: var(--amber); }

        main { max-width: 820px; margin: 0 auto; padding: 22px 24px 60px; }
        .uyari { background: var(--amber-soft); border: 1px solid #fde68a; color: var(--amber); border-radius: 14px; padding: 13px 16px; font-size: 12.5px; line-height: 1.5; margin-bottom: 18px; display: flex; gap: 10px; align-items: flex-start; }
        .uyari i { margin-top: 2px; }
        .bildirim { border-radius: 12px; padding: 12px 16px; font-size: 13px; font-weight: 600; margin-bottom: 16px; display: none; align-items: center; gap: 10px; }
        .bildirim.ok { display: flex; background: var(--emerald-soft); color: var(--emerald); border: 1px solid #a7f3d0; }
        .bildirim.hata { display: flex; background: var(--red-soft); color: var(--red); border: 1px solid rgba(111, 16, 34, 0.25); }
        .blok { font-size: 12px; font-weight: 700; color: var(--text-2); text-transform: uppercase; letter-spacing: .4px; margin: 20px 2px 10px; display: flex; align-items: center; gap: 8px; }
        .blok i { color: var(--red); }
        .kart { background: rgba(255,255,255,0.94); border: 1px solid rgba(248, 113, 113, 0.18); border-radius: 16px; box-shadow: 0 4px 16px rgba(0,0,0,0.04); overflow: hidden; margin-bottom: 12px; }
        .satir { display: flex; align-items: center; gap: 12px; padding: 13px 16px; border-bottom: 1px solid #f1f3f5; }
        .satir:last-child { border-bottom: none; }
        .sol { flex: 1; min-width: 0; }
        .no { font-size: 13.5px; font-weight: 600; }
        .no small { font-weight: 400; color: var(--text-3); }
        .cari { font-size: 12.5px; color: var(--text-2); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tutar { font-size: 14px; font-weight: 700; white-space: nowrap; }
        .tutar-kutu { text-align: right; }
        .tutar-alt { font-size: 11px; color: var(--text-3); white-space: nowrap; }
        .btn { border: 1px solid transparent; border-radius: 10px; font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; padding: 9px 14px; display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; text-decoration: none; }
        .btn-birincil { background: var(--red); color: #fff; border-color: var(--red); }
        .btn-birincil:hover { filter: brightness(1.1); }
        .btn-ikincil { background: #fff; color: var(--text-1); border-color: var(--border); }
        .btn-ikincil:hover { border-color: rgba(111, 16, 34, 0.35); color: var(--red); }
        .btn:disabled { opacity: .6; cursor: wait; }
        .bos { padding: 24px; text-align: center; color: var(--text-3); font-size: 13px; }
        .ara { display: flex; gap: 8px; margin-bottom: 12px; }
        .ara input { flex: 1; padding: 10px 14px; border: 1px solid var(--border); border-radius: 10px; font-family: inherit; font-size: 14px; outline: none; background: #fff; }
        .ara input:focus { border-color: rgba(111, 16, 34, 0.45); box-shadow: 0 0 0 3px rgba(111, 16, 34, 0.08); }

        .ov { position: fixed; inset: 0; background: rgba(15, 23, 42, .45); display: none; align-items: center; justify-content: center; z-index: 50; padding: 18px; }
        .ov.show { display: flex; }
        .modal { background: #fff; border-radius: 16px; max-width: 400px; width: 100%; padding: 22px; box-shadow: 0 20px 50px rgba(0,0,0,.18); }
        .modal h3 { font-size: 16px; margin-bottom: 6px; display: flex; align-items: center; gap: 8px; }
        .modal h3 i { color: var(--red); }
        .modal .aciklama { font-size: 12.5px; color: var(--text-2); line-height: 1.5; }
        .ozet { background: var(--bg); border-radius: 10px; padding: 12px 14px; margin: 14px 0; font-size: 13px; }
        .ozet div { display: flex; justify-content: space-between; gap: 12px; padding: 3px 0; }
        .ozet .v { font-weight: 600; text-align: right; }
        .ozet .net { font-size: 18px; font-weight: 700; color: var(--red); }
        .btnrow { display: flex; gap: 10px; margin-top: 6px; }
        .btnrow .btn { flex: 1; justify-content: center; padding: 12px; }

        @media (max-width: 767px) {
            main { padding: 14px 12px 40px; }
            .satir { flex-wrap: wrap; }
            .satir .btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<header class="top-header">
    <div class="header-inner">
        <a href="../index.php" class="header-back" title="Geri"><i class="fa fa-arrow-left"></i></a>
        <div class="header-divider"></div>
        <span class="header-title"><i class="fa-solid fa-file-invoice-dollar"></i>Faturalama</span>
        <span class="beta-rozet">BETA</span>
    </div>
</header>
<main>
    <div class="uyari">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><b>BETA — KDV'siz çalışır.</b> Kesilen faturaya KDV yazılmaz (TOTALVAT&nbsp;=&nbsp;0),
        muhasebe fişi ve e-fatura oluşturulmaz. <b>KDV mükellefi bir firmada kullanmayın.</b>
        Faturalama LOGO'ya kalıcı kayıt yazar (stok düşer, cari borçlanır); yanlışlıkla
        yaparsanız aşağıdaki listeden <b>"Geri Al"</b> ile tamamen iptal edebilirsiniz
        (muhasebeleştirilmemiş olması şartıyla). Önce test veritabanında deneyin.</span>
    </div>

    <div class="bildirim" id="bildirim" role="status"></div>
    <?php if ($hata !== ''): ?><div class="bildirim hata"><i class="fa-solid fa-circle-exclamation"></i><?php echo $h($hata); ?></div><?php endif; ?>

    <div class="blok"><i class="fa-solid fa-clock-rotate-left"></i> Bu panelden kesilenler (geri alınabilir)</div>
    <div class="kart">
        <?php if ($faturalanmis === []): ?>
            <div class="bos">Bu dönemde bu panelden kesilmiş fatura yok.</div>
        <?php else: foreach ($faturalanmis as $f): ?>
            <div class="satir">
                <div class="sol">
                    <div class="no"><?php echo $h($f['INVOICE_FICHENO']); ?> <small>· sipariş <?php echo $h($f['SIPARIS_NO']); ?></small></div>
                    <div class="cari"><?php echo $h($f['DEFINITION_'] ?? '-'); ?></div>
                </div>
                <div class="tutar"><?php echo $tl($f['NETTOTAL']); ?></div>
                <button type="button" class="btn btn-ikincil js-geri" data-log="<?php echo (int) $f['ID']; ?>" data-no="<?php echo $h($f['INVOICE_FICHENO']); ?>"><i class="fa-solid fa-rotate-left"></i> Geri Al</button>
            </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="blok"><i class="fa-solid fa-receipt"></i> Faturalanabilir siparişler<?php echo $ara !== '' ? ' — “' . $h($ara) . '”' : ' (son 50)'; ?></div>
    <form method="get" class="ara">
        <input type="search" name="ara" value="<?php echo $h($ara); ?>" placeholder="Cari adı, kod veya fiş no ara…" autocomplete="off">
        <button type="submit" class="btn btn-birincil"><i class="fa-solid fa-magnifying-glass"></i> Ara</button>
        <?php if ($ara !== ''): ?><a href="fatura_panel.php" class="btn btn-ikincil" title="Aramayı temizle"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
    </form>
    <div class="kart">
        <?php if ($faturalanabilir === []): ?>
            <div class="bos">Faturalanmamış TL siparişi bulunamadı.</div>
        <?php else: foreach ($faturalanabilir as $s): ?>
            <div class="satir">
                <div class="sol">
                    <div class="no"><?php echo $h($s['FICHENO']); ?> <small>· <?php echo $h(tarihcevir($s['DATE_'])); ?></small></div>
                    <div class="cari"><?php echo $h(trim(($s['CODE'] ?? '') . ' — ' . ($s['DEFINITION_'] ?? ''), ' —')); ?></div>
                </div>
                <div class="tutar-kutu">
                    <div class="tutar"><?php echo $tl($s['FATURA_NET']); ?></div>
                    <div class="tutar-alt" title="Siparişin KDV dahil toplamı">sipariş KDV dahil <?php echo $tl($s['NETTOTAL']); ?></div>
                </div>
                <button type="button" class="btn btn-birincil js-fatura"
                    data-ref="<?php echo (int) $s['LOGICALREF']; ?>"
                    data-no="<?php echo $h($s['FICHENO']); ?>"
                    data-cari="<?php echo $h(trim(($s['CODE'] ?? '') . ' ' . ($s['DEFINITION_'] ?? ''))); ?>"
                    data-net="<?php echo $h($tl($s['FATURA_NET'])); ?>"
                    data-kdvli="<?php echo $h($tl($s['NETTOTAL'])); ?>">
                    <i class="fa-solid fa-file-invoice"></i> Faturala
                </button>
            </div>
        <?php endforeach; endif; ?>
    </div>
</main>

<div class="ov" id="ovFatura" role="dialog" aria-modal="true" aria-labelledby="ovFaturaBaslik">
    <div class="modal">
        <h3 id="ovFaturaBaslik"><i class="fa-solid fa-file-invoice"></i> Fatura oluşturulsun mu?</h3>
        <p class="aciklama">Sipariş LOGO satış faturasına çevrilecek (KDV'siz). Stok düşer, cari borçlanır.</p>
        <div class="ozet">
            <div><span>Sipariş</span><span class="v" id="mSip">-</span></div>
            <div><span>Cari</span><span class="v" id="mCari">-</span></div>
            <div><span>Sipariş (KDV dahil)</span><span class="v" id="mKdvli">-</span></div>
            <div><span>Fatura tutarı (KDV'siz)</span><span class="v net" id="mNet">-</span></div>
        </div>
        <div class="btnrow">
            <button type="button" class="btn btn-ikincil" data-kapat="ovFatura">Vazgeç</button>
            <button type="button" class="btn btn-birincil" id="mFaturaBtn"><i class="fa-solid fa-check"></i> Faturala</button>
        </div>
    </div>
</div>

<div class="ov" id="ovGeri" role="dialog" aria-modal="true" aria-labelledby="ovGeriBaslik">
    <div class="modal">
        <h3 id="ovGeriBaslik"><i class="fa-solid fa-rotate-left"></i> Fatura geri alınsın mı?</h3>
        <p class="aciklama">Fatura, irsaliye, stok çıkışı ve cari borç silinir; sipariş yeniden faturalanabilir olur.</p>
        <div class="ozet"><div><span>Fatura</span><span class="v" id="gNo">-</span></div></div>
        <div class="btnrow">
            <button type="button" class="btn btn-ikincil" data-kapat="ovGeri">Vazgeç</button>
            <button type="button" class="btn btn-birincil" id="mGeriBtn"><i class="fa-solid fa-check"></i> Geri Al</button>
        </div>
    </div>
</div>

<script>
(function () {
    // Uç noktalar her istekten sonra CSRF belirtecini yeniler; sonuç mesajı
    // sayfa yenilendikten sonra gösterilmek üzere sessionStorage'da taşınır.
    var CSRF = <?php echo json_encode(csrf_token()); ?>;
    var secili = 0;
    function goster(id) { document.getElementById(id).classList.add('show'); }
    function kapat(id) { document.getElementById(id).classList.remove('show'); }
    function bildir(ok, mesaj) {
        var b = document.getElementById('bildirim');
        b.className = 'bildirim ' + (ok ? 'ok' : 'hata');
        b.textContent = mesaj;
    }
    function gonder(url, alan, btn, yukleniyor) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> ' + yukleniyor;
        var fd = new FormData();
        fd.append(alan, secili);
        fd.append('csrf_token', CSRF);
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { try { sessionStorage.setItem('faturaMesaj', JSON.stringify(j)); } catch (e) {} location.reload(); })
            .catch(function () { try { sessionStorage.setItem('faturaMesaj', JSON.stringify({ ok: false, mesaj: 'Bağlantı hatası; listeyi kontrol edin.' })); } catch (e) {} location.reload(); });
    }
    try {
        var m = JSON.parse(sessionStorage.getItem('faturaMesaj') || 'null');
        sessionStorage.removeItem('faturaMesaj');
        if (m) { bildir(!!m.ok, m.mesaj || (m.ok ? 'İşlem tamam.' : 'İşlem yapılamadı.')); }
    } catch (e) {}
    document.querySelectorAll('[data-kapat]').forEach(function (b) { b.addEventListener('click', function () { kapat(b.dataset.kapat); }); });
    document.querySelectorAll('.js-fatura').forEach(function (b) {
        b.addEventListener('click', function () {
            secili = b.dataset.ref;
            document.getElementById('mSip').textContent = b.dataset.no;
            document.getElementById('mCari').textContent = b.dataset.cari || '-';
            document.getElementById('mNet').textContent = b.dataset.net;
            document.getElementById('mKdvli').textContent = b.dataset.kdvli;
            goster('ovFatura');
        });
    });
    document.querySelectorAll('.js-geri').forEach(function (b) {
        b.addEventListener('click', function () {
            secili = b.dataset.log;
            document.getElementById('gNo').textContent = b.dataset.no;
            goster('ovGeri');
        });
    });
    document.getElementById('mFaturaBtn').addEventListener('click', function () { gonder('fatura.php', 'siparis_ref', this, 'Oluşturuluyor…'); });
    document.getElementById('mGeriBtn').addEventListener('click', function () { gonder('fatura_geri_al.php', 'log_id', this, 'Geri alınıyor…'); });
})();
</script>
</body>
</html>

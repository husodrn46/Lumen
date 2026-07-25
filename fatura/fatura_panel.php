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

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mdoviz = '₺';

// --- Erişim guard ---
$acik = !empty($faturalama_aktif);
$yetkiVar = ((int) m_p_yetki($terminalkullanici, 'M31') === 1);
$erisim = $acik && $yetkiVar;

$faturalanabilir = [];
$faturalanmis = [];
if ($erisim) {
    fatura_log_tablo_olustur($dbh);
    $ara = trim((string) ($_GET['ara'] ?? ''));
    $araSql = '';
    $araParams = [];
    if ($ara !== '') {
        // PDO_SQLSRV aynı named parametreyi birden çok kez kullanamaz; 3 ayrı parametre.
        $araSql = " AND (c.DEFINITION_ LIKE :ara1 OR c.CODE LIKE :ara2 OR o.FICHENO LIKE :ara3)";
        $araParams[':ara1'] = '%' . $ara . '%';
        $araParams[':ara2'] = '%' . $ara . '%';
        $araParams[':ara3'] = '%' . $ara . '%';
    }
    try {
        // NOT: Genel iskontolu (ORFLINE LINETYPE=2) siparişler ARTIK faturalanabilir; filtre kaldırıldı.
        // Dövizli siparişler hâlâ hariç (TRCURR=0) — beta'da TL faturalama.
        $q = $dbh->prepare("
            SELECT TOP 50 o.LOGICALREF, o.FICHENO, o.DATE_, o.NETTOTAL, o.CLIENTREF, c.CODE, c.DEFINITION_
            FROM {$firmadonem}ORFICHE o
            JOIN {$firma}CLCARD c ON c.LOGICALREF = o.CLIENTREF
            WHERE o.TRCODE = 1 AND (o.TRCURR = 0 OR o.TRCURR IS NULL) AND o.NETTOTAL > 0
              AND NOT EXISTS (SELECT 1 FROM {$firmadonem}STLINE s WHERE s.ORDFICHEREF = o.LOGICALREF AND s.INVOICEREF > 0)
              {$araSql}
            ORDER BY o.LOGICALREF DESC
        ");
        $q->execute($araParams);
        $faturalanabilir = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $faturalanabilir = [];
    }
    try {
        $q2 = $dbh->query("
            SELECT l.ID, l.INVOICE_FICHENO, l.SIPARIS_NO, l.NETTOTAL, l.OLUSTURMA, l.CLIENTREF, c.DEFINITION_
            FROM M_FATURA_LOG l
            LEFT JOIN {$firma}CLCARD c ON c.LOGICALREF = l.CLIENTREF
            WHERE l.DURUM = 'AKTIF'
            ORDER BY l.ID DESC
        ");
        $faturalanmis = $q2->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $faturalanmis = [];
    }
}
$csrf = function_exists('csrf_token') ? csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>Faturalama (BETA)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#dc2626; --red-soft:#fef2f2; --green:#047857; --green-soft:#ecfdf5; --amber:#b45309; --amber-soft:#fffbeb; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--t1); font-size:14px; }
        .top { position:sticky; top:0; z-index:30; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
        .top-in { max-width:760px; margin:0 auto; padding:11px 16px; display:flex; align-items:center; gap:12px; }
        .top-in a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; }
        .top-in a.geri:hover { background:rgba(0,0,0,.05); color:var(--red); }
        .top-title { font-size:15px; font-weight:600; flex:1; display:flex; align-items:center; gap:8px; }
        .top-title i { color:var(--red); }
        .beta-rozet { font-size:10.5px; font-weight:700; letter-spacing:.5px; padding:3px 9px; border-radius:7px; background:var(--amber-soft); color:var(--amber); }
        main { max-width:760px; margin:0 auto; padding:18px 16px 60px; }
        .uyari { background:var(--amber-soft); border:1px solid #fde68a; color:var(--amber); border-radius:12px; padding:13px 16px; font-size:12.5px; margin-bottom:16px; display:flex; gap:10px; align-items:flex-start; }
        .uyari i { margin-top:1px; }
        .blok { font-size:12px; font-weight:700; color:var(--t2); text-transform:uppercase; letter-spacing:.4px; margin:18px 2px 10px; display:flex; align-items:center; gap:8px; }
        .blok i { color:var(--red); }
        .card { background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; margin-bottom:12px; }
        .satir { display:flex; align-items:center; gap:12px; padding:13px 16px; border-bottom:1px solid #f1f3f5; }
        .satir:last-child { border-bottom:none; }
        .satir .sol { flex:1; min-width:0; }
        .satir .no { font-size:13.5px; font-weight:600; }
        .satir .cari { font-size:12.5px; color:var(--t2); overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .satir .tutar { font-size:14px; font-weight:700; white-space:nowrap; }
        .satir .tarih { font-size:11px; color:var(--t3); }
        .btn { border:none; border-radius:9px; font-family:inherit; font-size:12.5px; font-weight:600; cursor:pointer; padding:9px 14px; display:inline-flex; align-items:center; gap:6px; white-space:nowrap; }
        .btn-fatura { background:var(--red); color:#fff; }
        .btn-fatura:hover { background:#b91c1c; }
        .btn-geri { background:#fff; color:var(--t2); border:1px solid var(--border); }
        .btn-geri:hover { border-color:var(--red); color:var(--red); }
        .bos { padding:24px; text-align:center; color:var(--t3); font-size:13px; }
        .kapali { max-width:520px; margin:60px auto; text-align:center; padding:0 20px; }
        .kapali i { font-size:42px; color:var(--t3); margin-bottom:14px; }
        .kapali h2 { font-size:18px; margin-bottom:8px; }
        .kapali p { color:var(--t2); font-size:13.5px; }
        /* modal */
        .ov { position:fixed; inset:0; background:rgba(0,0,0,.45); display:none; align-items:center; justify-content:center; z-index:50; padding:18px; }
        .ov.show { display:flex; }
        .modal { background:#fff; border-radius:16px; max-width:380px; width:100%; padding:22px; }
        .modal h3 { font-size:16px; margin-bottom:4px; display:flex; align-items:center; gap:8px; }
        .modal h3 i { color:var(--red); }
        .modal .ozet { background:var(--bg); border-radius:10px; padding:12px 14px; margin:14px 0; font-size:13px; }
        .modal .ozet div { display:flex; justify-content:space-between; padding:3px 0; }
        .modal .ozet .v { font-weight:600; }
        .modal .net { font-size:20px; font-weight:700; color:var(--red); }
        .modal p.aciklama { font-size:12.5px; color:var(--t2); margin-bottom:12px; }
        .modal .btnrow { display:flex; gap:10px; margin-top:8px; }
        .modal .btnrow .btn { flex:1; justify-content:center; padding:12px; }
        .btn-vazgec { background:var(--bg); color:var(--t2); }
        .durum-msg { padding:0 16px 14px; font-size:12.5px; display:none; }
    </style>
</head>
<body>
<?php if (!$erisim): ?>
    <div class="kapali">
        <i class="fa-solid fa-lock"></i>
        <h2>Faturalama Paneli</h2>
        <p><?php
            if (!$acik) { echo 'Bu özellik şu an kapalı. Sistem Ayarları > Faturalama bölümünden açılabilir.'; }
            else { echo 'Faturalama yetkiniz yok (M31).'; }
        ?></p>
        <p style="margin-top:14px"><a href="../index.php" style="color:var(--red)">← Ana sayfa</a></p>
    </div>
<?php else: ?>
    <header class="top">
        <div class="top-in">
            <a href="../index.php" class="geri"><i class="fa fa-arrow-left"></i></a>
            <span class="top-title"><i class="fa-solid fa-file-invoice-dollar"></i> Faturalama</span>
            <span class="beta-rozet">BETA</span>
        </div>
    </header>
    <main>
        <div class="uyari">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span><b>BETA — KDV'siz çalışır.</b> Kesilen faturaya KDV yazılmaz (TOTALVAT&nbsp;=&nbsp;0),
            muhasebe fişi ve e-fatura oluşturulmaz. <b>KDV mükellefi bir firmada kullanmayın.</b>
            Faturalama LOGO'ya kalıcı kayıt yazar (stok düşer, cari borçlanır); yanlışlıkla
            yaparsanız aşağıdaki listeden <b>"Geri Al"</b> ile tamamen iptal edebilirsiniz.
            Önce test veritabanında deneyin.</span>
        </div>

        <div class="blok"><i class="fa-solid fa-clock-rotate-left"></i> Bu panelden kesilenler (geri alınabilir)</div>
        <div class="card">
            <?php if (empty($faturalanmis)): ?>
                <div class="bos">Henüz bu panelden kesilmiş fatura yok.</div>
            <?php else: foreach ($faturalanmis as $f): ?>
                <div class="satir" id="flog-<?php echo (int) $f['ID']; ?>">
                    <div class="sol">
                        <div class="no"><?php echo $h($f['INVOICE_FICHENO']); ?> <span style="font-weight:400;color:var(--t3)">· sip <?php echo $h($f['SIPARIS_NO']); ?></span></div>
                        <div class="cari"><?php echo $h($f['DEFINITION_'] ?? '-'); ?></div>
                    </div>
                    <div style="text-align:right">
                        <div class="tutar"><?php echo number_format((float) $f['NETTOTAL'], 2, ',', '.') . ' ' . $mdoviz; ?></div>
                    </div>
                    <button class="btn btn-geri js-geri" data-log="<?php echo (int) $f['ID']; ?>" data-no="<?php echo $h($f['INVOICE_FICHENO']); ?>"><i class="fa-solid fa-rotate-left"></i> Geri Al</button>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="blok"><i class="fa-solid fa-receipt"></i> Faturalanabilir Siparişler<?php echo $ara !== '' ? ' — “' . $h($ara) . '”' : ' (son 50)'; ?></div>
        <form method="get" style="margin-bottom:12px;display:flex;gap:8px;">
            <input type="text" name="ara" value="<?php echo $h($ara); ?>" placeholder="Cari adı, kod veya fiş no ara…" autocomplete="off" style="flex:1;padding:11px 14px;border:1px solid var(--border);border-radius:10px;font-family:inherit;font-size:13.5px;outline:none;">
            <button type="submit" class="btn btn-fatura"><i class="fa-solid fa-magnifying-glass"></i> Ara</button>
            <?php if ($ara !== ''): ?><a href="fatura_panel.php" class="btn btn-geri" style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center" title="Aramayı temizle"><i class="fa-solid fa-xmark"></i></a><?php endif; ?>
        </form>
        <div class="card">
            <?php if (empty($faturalanabilir)): ?>
                <div class="bos">Faturalanmamış TL siparişi bulunamadı.</div>
            <?php else: foreach ($faturalanabilir as $s): ?>
                <div class="satir" id="sip-<?php echo (int) $s['LOGICALREF']; ?>">
                    <div class="sol">
                        <div class="no"><?php echo $h($s['FICHENO']); ?> <span class="tarih">· <?php echo tarihcevir($s['DATE_']); ?></span></div>
                        <div class="cari"><?php echo $h(trim(($s['CODE'] ?? '') . ' — ' . ($s['DEFINITION_'] ?? ''), ' —')); ?></div>
                    </div>
                    <div style="text-align:right">
                        <div class="tutar"><?php echo number_format((float) $s['NETTOTAL'], 2, ',', '.') . ' ' . $mdoviz; ?></div>
                    </div>
                    <button class="btn btn-fatura js-fatura"
                        data-ref="<?php echo (int) $s['LOGICALREF']; ?>"
                        data-no="<?php echo $h($s['FICHENO']); ?>"
                        data-cari="<?php echo $h(trim(($s['CODE'] ?? '') . ' ' . ($s['DEFINITION_'] ?? ''))); ?>"
                        data-net="<?php echo $h(number_format((float) $s['NETTOTAL'], 2, ',', '.') . ' ' . $mdoviz); ?>">
                        <i class="fa-solid fa-file-invoice"></i> Faturala
                    </button>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </main>

    <!-- Faturala onay modalı -->
    <div class="ov" id="ovFatura">
        <div class="modal">
            <h3><i class="fa-solid fa-file-invoice"></i> Faturayı oluştur?</h3>
            <p class="aciklama">Bu sipariş LOGO satış faturasına çevrilecek. Onaylıyor musun?</p>
            <div class="ozet">
                <div><span>Sipariş</span><span class="v" id="mSip">-</span></div>
                <div><span>Cari</span><span class="v" id="mCari">-</span></div>
                <div><span>Tutar</span><span class="v net" id="mNet">-</span></div>
            </div>
            <div class="btnrow">
                <button class="btn btn-vazgec" onclick="kapat('ovFatura')">Vazgeç</button>
                <button class="btn btn-fatura" id="mFaturaBtn" onclick="faturaYap()"><i class="fa-solid fa-check"></i> Evet, Faturala</button>
            </div>
        </div>
    </div>

    <!-- Geri al onay modalı -->
    <div class="ov" id="ovGeri">
        <div class="modal">
            <h3><i class="fa-solid fa-rotate-left"></i> Faturayı geri al?</h3>
            <p class="aciklama">Fatura, irsaliye, stok çıkışı ve cari borç tamamen silinecek; sipariş tekrar faturalanmamış olacak. Onaylıyor musun?</p>
            <div class="ozet"><div><span>Fatura</span><span class="v" id="gNo">-</span></div></div>
            <div class="btnrow">
                <button class="btn btn-vazgec" onclick="kapat('ovGeri')">Vazgeç</button>
                <button class="btn btn-geri" id="mGeriBtn" onclick="geriAlYap()" style="background:var(--red);color:#fff;border:none"><i class="fa-solid fa-check"></i> Evet, Geri Al</button>
            </div>
        </div>
    </div>

    <script>
    const CSRF = <?php echo json_encode($csrf); ?>;
    let _sip = 0, _log = 0;
    function kapat(id){ document.getElementById(id).classList.remove('show'); }
    function faturaAc(ref, no, cari, net){ _sip=ref; document.getElementById('mSip').textContent=no; document.getElementById('mCari').textContent=cari||'-'; document.getElementById('mNet').textContent=net; document.getElementById('ovFatura').classList.add('show'); }
    function geriAlAc(logId, no){ _log=logId; document.getElementById('gNo').textContent=no; document.getElementById('ovGeri').classList.add('show'); }
    function faturaYap(){
        const btn=document.getElementById('mFaturaBtn'); btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> Oluşturuluyor...';
        const fd=new FormData(); fd.append('siparis_ref',_sip); fd.append('csrf_token',CSRF);
        fetch('fatura.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{
            alert(j.mesaj||(j.ok?'Fatura oluşturuldu.':'Hata.')); location.reload();
        }).catch(()=>{ alert('Bağlantı hatası.'); location.reload(); });
    }
    function geriAlYap(){
        const btn=document.getElementById('mGeriBtn'); btn.disabled=true; btn.innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> Geri alınıyor...';
        const fd=new FormData(); fd.append('log_id',_log); fd.append('csrf_token',CSRF);
        fetch('fatura_geri_al.php',{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(j=>{
            alert(j.mesaj||(j.ok?'Geri alındı.':'Hata.')); location.reload();
        }).catch(()=>{ alert('Bağlantı hatası.'); location.reload(); });
    }
    document.querySelectorAll('.js-fatura').forEach(function(b){ b.addEventListener('click', function(){ faturaAc(b.dataset.ref, b.dataset.no, b.dataset.cari, b.dataset.net); }); });
    document.querySelectorAll('.js-geri').forEach(function(b){ b.addEventListener('click', function(){ geriAlAc(b.dataset.log, b.dataset.no); }); });
    </script>
<?php endif; ?>
</body>
</html>

<?php

declare(strict_types=1);

/**
 * cek_ciro_panel.php — Çek Çıkışı / CİRO BETA paneli (izole).  [Faz-2a]
 * Hedef cari (tedarikçi) seç → portföyden çek(ler) seç → ciro et; girilenleri (geri alınabilir) listeler.
 * Guard: $cek_cikis_beta + yönetici + allow-list. Kapalıysa erişilemez.
 */

include_once(__DIR__ . '/ayr.php');
include_once(__DIR__ . '/kontrol.php');
include_once(__DIR__ . '/donem_helper.php');
include_once(__DIR__ . '/log_ip.php');
include_once(__DIR__ . '/cek_lib.php');

global $dbh, $firma, $firmadonem, $terminalkullanici, $yetkidurum;
global $cek_cikis_beta, $cek_cikis_beta_kullanicilar;

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$para = static fn($v): string => number_format((float) $v, 2, ',', '.');

$acik = !empty($cek_cikis_beta);
$yonetici = ((int) ($yetkidurum ?? 1) === 0);
$izinli = is_array($cek_cikis_beta_kullanicilar ?? null) ? array_map('intval', $cek_cikis_beta_kullanicilar) : [];
$kullaniciOk = !empty($izinli) && in_array((int) $terminalkullanici, $izinli, true);
$erisim = $acik && $yonetici && $kullaniciOk;

$ara = trim((string) ($_GET['ara'] ?? ''));
$cariRef = (int) ($_GET['cari'] ?? 0);
$cariler = []; $seciliCari = null; $cariBorc = 0.0; $portfoy = []; $cirolar = [];

if ($erisim) {
    cek_log_tablo_olustur($dbh);

    if ($ara !== '' && $cariRef <= 0) {
        try {
            $q = $dbh->prepare("SELECT TOP 30 LOGICALREF, CODE, DEFINITION_ FROM {$firma}CLCARD
                                WHERE ACTIVE=0 AND (DEFINITION_ LIKE :a1 OR CODE LIKE :a2) ORDER BY DEFINITION_");
            $q->execute([':a1' => '%' . $ara . '%', ':a2' => '%' . $ara . '%']);
            $cariler = $q->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $cariler = []; }
    }
    if ($cariRef > 0) {
        try {
            $q = $dbh->prepare("SELECT LOGICALREF, CODE, DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF=:r");
            $q->execute([':r' => $cariRef]);
            $seciliCari = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            $cariBorc = (float) $dbh->query("SELECT ISNULL(SUM(CASE WHEN SIGN=0 THEN AMOUNT ELSE -AMOUNT END),0) FROM {$firmadonem}CLFLINE WHERE CLIENTREF={$cariRef} AND CANCELLED=0")->fetchColumn();
        } catch (Throwable $e) { $seciliCari = null; }
    }
    // Portföydeki uygun çekler (CURRSTAT=1, DOC=1) + çeki veren müşteri
    if ($seciliCari) {
        try {
            $portfoy = $dbh->query("SELECT cc.LOGICALREF, cc.PORTFOYNO, cc.NEWSERINO, cc.BANKNAME, cc.DUEDATE, cc.AMOUNT, cc.OWING,
                    (SELECT TOP 1 c2.DEFINITION_ FROM {$firmadonem}CSTRANS ct JOIN {$firma}CLCARD c2 ON c2.LOGICALREF=ct.CARDREF WHERE ct.CSREF=cc.LOGICALREF AND ct.TRCODE=1 ORDER BY ct.LOGICALREF) MUSTERI
                FROM {$firmadonem}CSCARD cc
                WHERE cc.DOC=1 AND cc.CURRSTAT=1 AND cc.CANCELLED=0
                ORDER BY cc.DUEDATE")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $portfoy = []; }
    }
    try {
        $cirolar = $dbh->query("SELECT TOP 50 l.ID, l.CSROLL_ROLLNO, l.TUTAR, l.DOCCNT, l.OLUSTURMA, l.CLIENTREF, l.DURUM,
                c.DEFINITION_ HEDEF, s.CODE GIREN
            FROM M_CEK_LOG l
            LEFT JOIN {$firma}CLCARD c ON c.LOGICALREF=l.CLIENTREF
            LEFT JOIN LG_SLSMAN s ON s.LOGICALREF=l.KULLANICI
            WHERE l.DURUM IN ('AKTIF','GERIALINDI') AND l.TUR='ciro' ORDER BY l.ID DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $cirolar = []; }
}
$csrf = function_exists('csrf_token') ? csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Çek Çıkışı / Ciro</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once(__DIR__ . '/pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --amber:#b45309; --amber-soft:#fffbeb; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; background:var(--bg); color:var(--t1); font-size:14px; }
        .top { position:sticky; top:0; z-index:30; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
        .top-in { max-width:860px; margin:0 auto; padding:11px 16px; display:flex; align-items:center; gap:12px; }
        .top-in a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
        .top-title { font-size:15px; font-weight:600; flex:1; display:flex; align-items:center; gap:8px; }
        .top-title i { color:var(--red); }
        .rozet { font-size:10.5px; font-weight:700; letter-spacing:.5px; padding:3px 9px; border-radius:7px; background:var(--amber-soft); color:var(--amber); }
        main { max-width:860px; margin:0 auto; padding:18px 16px 60px; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:14px; margin-bottom:16px; overflow:hidden; }
        .card-h { padding:13px 18px; font-size:12.5px; font-weight:700; color:var(--t2); text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:8px; }
        .card-h i { color:var(--red); }
        .pad { padding:16px 18px; }
        .kapali { padding:40px 20px; text-align:center; color:var(--t2); }
        .kapali i { font-size:34px; color:var(--t3); margin-bottom:12px; }
        .row { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        input, select { padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-family:inherit; font-size:14px; outline:none; background:#fff; }
        input:focus, select:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
        .btn { border:none; border-radius:10px; font-family:inherit; font-size:14px; font-weight:600; cursor:pointer; padding:11px 18px; display:inline-flex; align-items:center; justify-content:center; gap:7px; text-decoration:none; min-height:44px; }
        .btn-accent { background:var(--red); color:#fff; }
        .btn-accent[disabled] { opacity:.45; cursor:not-allowed; }
        .btn-light { background:var(--bg); color:var(--t2); border:1px solid var(--border); }
        .cari-item { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid var(--border); border-radius:11px; text-decoration:none; color:inherit; margin-bottom:7px; }
        .cari-item:hover { border-color:var(--red); background:var(--red-soft); }
        .cari-item .kod { font-weight:700; color:var(--red); font-size:12.5px; min-width:70px; }
        .cari-item .ad { flex:1; font-size:13.5px; }
        .sel-cari { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; background:var(--red-soft); border:1px solid var(--border); border-left:4px solid var(--red); border-radius:12px; }
        .sel-cari b { font-size:15px; }
        .sel-cari .bak { font-size:12px; color:var(--t2); }
        .sel-cari .bak b { color:var(--red); font-size:14px; }
        .filtre { width:100%; margin-bottom:10px; }
        .cek-liste { max-height:420px; overflow-y:auto; border:1px solid var(--border); border-radius:11px; }
        .cek-satir { display:flex; align-items:center; gap:11px; padding:11px 13px; border-bottom:1px solid #f1f3f5; cursor:pointer; }
        .cek-satir:last-child { border-bottom:none; }
        .cek-satir:hover { background:#fafafa; }
        .cek-satir.secili { background:var(--red-soft); }
        .cek-satir input[type=checkbox] { width:18px; height:18px; accent-color:var(--red); flex-shrink:0; }
        .cek-bilgi { flex:1; min-width:0; }
        .cek-ust { font-size:13.5px; font-weight:600; display:flex; gap:8px; align-items:baseline; }
        .cek-ust .pf { color:var(--red); font-size:11px; font-weight:700; }
        .cek-alt { font-size:11.5px; color:var(--t2); margin-top:2px; }
        .cek-tut { font-weight:700; font-size:14px; white-space:nowrap; }
        .bos { padding:22px; text-align:center; color:var(--t3); font-size:13px; }
        .ozet-box { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; background:var(--red-soft); border:1px solid var(--border); border-radius:12px; margin-top:14px; }
        .ozet-box .tp { font-size:20px; font-weight:700; color:var(--red); white-space:nowrap; }
        .ozet-box .ad b { color:var(--t1); }
        label { font-size:11.5px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.3px; display:block; margin-bottom:6px; }
        table { width:100%; border-collapse:collapse; }
        thead th { font-size:11px; text-transform:uppercase; color:var(--t3); font-weight:600; text-align:left; padding:10px 12px; border-bottom:1px solid var(--border); white-space:nowrap; }
        thead th.sag, tbody td.sag { text-align:right; }
        tbody td { padding:10px 12px; border-bottom:1px solid #f1f3f5; font-size:13px; white-space:nowrap; }
        .tut { color:var(--t1); font-weight:700; }
        #toast { position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(20px); background:var(--t1); color:#fff; padding:11px 20px; border-radius:12px; font-size:13px; font-weight:600; opacity:0; pointer-events:none; transition:.25s; z-index:60; }
        #toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="<?php echo $cariRef > 0 ? 'cari_islemleri.php?cari=' . $cariRef : APP_ROOT_URL . '/index.php'; ?>" class="geri" title="<?php echo $cariRef > 0 ? 'Cari işlemlerine dön' : 'Ana sayfa'; ?>"><i class="fa fa-arrow-left"></i></a>
            <span class="top-title"><i class="fa-solid fa-share-from-square"></i> Çek Çıkışı / Ciro</span>
        </div>
    </header>
    <main>
    <?php if (!$erisim): ?>
        <div class="card"><div class="kapali">
            <div><i class="fa-solid fa-lock"></i></div>
            <div>Çek çıkışı beta özelliği şu an <b>kapalı</b> veya size tanımlı değil.</div>
        </div></div>
    <?php else: ?>

        <!-- Hedef cari (tedarikçi) -->
        <div class="card">
            <div class="card-h"><i class="fa-solid fa-truck-field"></i> Kime Ciro? (Tedarikçi / Cari)</div>
            <div class="pad">
                <form method="get" class="row">
                    <input type="text" name="ara" value="<?php echo $h($ara); ?>" placeholder="Tedarikçi adı veya kodu…" autocomplete="off" style="flex:1;min-width:180px;">
                    <button type="submit" class="btn btn-accent"><i class="fa-solid fa-magnifying-glass"></i> Ara</button>
                </form>
                <?php if ($seciliCari): ?>
                    <div class="sel-cari" style="margin-top:12px;">
                        <div><b><?php echo $h($seciliCari['DEFINITION_']); ?></b><br><span class="bak"><?php echo $h($seciliCari['CODE']); ?> · bakiye: <b><?php echo $para(abs($cariBorc)); ?> ₺</b> <?php echo $cariBorc < -0.005 ? 'alacak (ona borçluyuz)' : 'borç'; ?></span></div>
                        <a href="cek_ciro_panel.php" class="btn btn-light"><i class="fa-solid fa-xmark"></i> Değiştir</a>
                    </div>
                <?php elseif ($cariler): ?>
                    <div style="margin-top:12px;">
                        <?php foreach ($cariler as $c): ?>
                        <a class="cari-item" href="cek_ciro_panel.php?cari=<?php echo (int) $c['LOGICALREF']; ?>">
                            <span class="kod"><?php echo $h($c['CODE']); ?></span>
                            <span class="ad"><?php echo $h($c['DEFINITION_']); ?></span>
                            <i class="fa-solid fa-chevron-right" style="color:var(--t3)"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($ara !== ''): ?>
                    <div class="bos">"<?php echo $h($ara); ?>" ile cari bulunamadı.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Portföyden çek seç -->
        <?php if ($seciliCari): ?>
        <div class="card">
            <div class="card-h"><i class="fa-solid fa-money-check-dollar"></i> Portföyden Çek Seç (<?php echo count($portfoy); ?> uygun çek)</div>
            <div class="pad">
                <?php if (empty($portfoy)): ?>
                    <div class="bos">Portföyde ciro edilebilir (durumda) çek yok.</div>
                <?php else: ?>
                <form id="ciroForm">
                    <input type="hidden" name="hedef_cari" value="<?php echo (int) $seciliCari['LOGICALREF']; ?>">
                    <input type="text" class="filtre" id="cekFiltre" placeholder="🔍 Listeyi filtrele (müşteri / çek no / banka)…" autocomplete="off">
                    <div class="cek-liste" id="cekListe">
                        <?php foreach ($portfoy as $ck): ?>
                        <label class="cek-satir" data-ara="<?php echo $h(mb_strtolower(($ck['MUSTERI'] ?? '') . ' ' . ($ck['NEWSERINO'] ?? '') . ' ' . ($ck['BANKNAME'] ?? '') . ' ' . ($ck['OWING'] ?? ''), 'UTF-8')); ?>">
                            <input type="checkbox" class="k-cek" value="<?php echo (int) $ck['LOGICALREF']; ?>" data-tutar="<?php echo (float) $ck['AMOUNT']; ?>">
                            <span class="cek-bilgi">
                                <span class="cek-ust"><span class="pf"><?php echo $h((string) $ck['PORTFOYNO']); ?></span> <?php echo $h($ck['MUSTERI'] ?? ($ck['OWING'] ?? '-')); ?></span>
                                <span class="cek-alt"><?php echo $h($ck['BANKNAME'] ?: '—'); ?> · No <?php echo $h($ck['NEWSERINO'] ?: '—'); ?> · Vade <?php echo $h(substr((string) $ck['DUEDATE'], 0, 10)); ?></span>
                            </span>
                            <span class="cek-tut"><?php echo $para($ck['AMOUNT']); ?> ₺</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="ozet-box">
                        <div class="ad"><b id="ciroAdet">0</b> çek seçildi → <b><?php echo $h(mb_substr($seciliCari['DEFINITION_'], 0, 30)); ?></b></div>
                        <span class="tp" id="ciroToplam">0,00 ₺</span>
                    </div>
                    <div style="margin-top:14px;">
                        <label>Açıklama (opsiyonel)</label>
                        <input type="text" name="aciklama" maxlength="240" placeholder="örn. mal bedeli ciro" autocomplete="off" style="width:100%;">
                    </div>
                    <div style="margin-top:14px;">
                        <button type="submit" id="ciroSubmit" class="btn btn-accent" style="width:100%;" disabled><i class="fa-solid fa-share-from-square"></i> <span id="ciroLbl">Ciro Et</span></button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ciro kayıtları -->
        <div class="card">
            <div class="card-h"><i class="fa-solid fa-clock-rotate-left"></i> Ciro Kayıtları (kim · ne zaman · geri alınabilir)</div>
            <?php if (empty($cirolar)): ?>
                <div class="bos">Henüz mshop'tan ciro yapılmamış.</div>
            <?php else: ?>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Bordro</th><th>Hedef Cari</th><th class="sag">Tutar</th><th>Giren</th><th>Tarih</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($cirolar as $t): $iptal = ((string) $t['DURUM'] === 'GERIALINDI'); $adet = (int) ($t['DOCCNT'] ?? 1); ?>
                    <tr data-id="<?php echo (int) $t['ID']; ?>"<?php echo $iptal ? ' style="opacity:.55"' : ''; ?>>
                        <td><?php echo $h(ltrim((string) $t['CSROLL_ROLLNO'], '0') ?: '0'); ?><?php echo $adet > 1 ? ' <span style="font-size:10px;font-weight:700;color:var(--red);background:var(--red-soft);padding:1px 6px;border-radius:6px">' . $adet . ' çek</span>' : ''; ?></td>
                        <td style="white-space:normal"><?php echo $h($t['HEDEF'] ?? '-'); ?></td>
                        <td class="sag tut"<?php echo $iptal ? ' style="text-decoration:line-through;color:var(--t3)"' : ''; ?>><?php echo $para($t['TUTAR']); ?> ₺</td>
                        <td><?php echo $h($t['GIREN'] ?? '-'); ?></td>
                        <td style="color:var(--t2);font-size:12px;"><?php echo $h(substr((string) $t['OLUSTURMA'], 0, 16)); ?></td>
                        <td class="sag">
                            <?php if ($iptal): ?>
                                <span style="font-size:11px;color:var(--amber);"><i class="fa-solid fa-ban"></i> geri alındı</span>
                            <?php else: ?>
                                <button class="btn btn-light geri-al" data-id="<?php echo (int) $t['ID']; ?>" style="padding:6px 12px;font-size:12px;min-height:auto;"><i class="fa-solid fa-rotate-left"></i> Geri Al</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    </main>

    <div id="toast"></div>

    <script>
    (function () {
        var csrf = <?php echo json_encode($csrf); ?>;
        var toast = document.getElementById('toast'); var tt = null;
        function bildir(msg, hata) { toast.textContent = msg; toast.style.background = hata ? '#b91c1c' : '#047857'; toast.classList.add('show'); clearTimeout(tt); tt = setTimeout(function(){ toast.classList.remove('show'); }, 2600); }
        function trPara(n) { var neg = n < 0; n = Math.abs(n); var p = n.toFixed(2).split('.'); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.'); return (neg ? '-' : '') + p[0] + ',' + p[1]; }

        var form = document.getElementById('ciroForm');
        if (form) {
            var kutular = form.querySelectorAll('.k-cek');
            var adetEl = document.getElementById('ciroAdet');
            var toplamEl = document.getElementById('ciroToplam');
            var submitBtn = document.getElementById('ciroSubmit');
            var lbl = document.getElementById('ciroLbl');
            var filtre = document.getElementById('cekFiltre');

            function yenile() {
                var adet = 0, toplam = 0;
                kutular.forEach(function (k) {
                    var row = k.closest('.cek-satir');
                    if (k.checked) { adet++; toplam += parseFloat(k.getAttribute('data-tutar')) || 0; row.classList.add('secili'); }
                    else { row.classList.remove('secili'); }
                });
                adetEl.textContent = adet;
                toplamEl.textContent = trPara(toplam) + ' ₺';
                submitBtn.disabled = adet < 1;
                lbl.textContent = adet > 1 ? (adet + ' Çeki Ciro Et') : 'Ciro Et';
            }
            kutular.forEach(function (k) { k.addEventListener('change', yenile); });

            if (filtre) {
                filtre.addEventListener('input', function () {
                    var q = filtre.value.trim().toLowerCase();
                    form.querySelectorAll('.cek-satir').forEach(function (row) {
                        row.style.display = (!q || (row.getAttribute('data-ara') || '').indexOf(q) !== -1) ? '' : 'none';
                    });
                });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var secili = [];
                kutular.forEach(function (k) { if (k.checked) { secili.push(parseInt(k.value, 10)); } });
                if (!secili.length) { bildir('En az bir çek seçin', true); return; }
                if (!confirm(secili.length + ' çek ciro edilecek. Onaylıyor musunuz?')) { return; }
                submitBtn.disabled = true;
                var body = new URLSearchParams();
                body.append('hedef_cari', form.querySelector('[name=hedef_cari]').value);
                body.append('aciklama', form.querySelector('[name=aciklama]').value);
                body.append('cek_refleri', JSON.stringify(secili));
                body.append('csrf_token', csrf);
                fetch('cek_ciro.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(j){ bildir(j.mesaj || (j.ok?'Ciro edildi':'Hata'), !j.ok); if (j.ok) { setTimeout(function(){ location.reload(); }, 1300); } else { submitBtn.disabled = false; } })
                    .catch(function(){ bildir('Bağlantı hatası', true); submitBtn.disabled = false; });
            });
        }
        document.querySelectorAll('.geri-al').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!confirm('Bu ciro geri alınsın mı? Çekler portföye döner ve cari bakiye eski haline gelir.')) return;
                b.disabled = true;
                var body = new URLSearchParams({ log_id: b.getAttribute('data-id'), csrf_token: csrf });
                fetch('cek_ciro_geri_al.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(j){ bildir(j.mesaj || (j.ok?'Geri alındı':'Hata'), !j.ok); if (j.ok) { setTimeout(function(){ location.reload(); }, 1000); } else { b.disabled = false; } })
                    .catch(function(){ bildir('Bağlantı hatası', true); b.disabled = false; });
            });
        });
    })();
    </script>
</body>
</html>

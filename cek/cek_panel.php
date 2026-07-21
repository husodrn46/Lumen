<?php

declare(strict_types=1);

/**
 * cek_panel.php — Çek Girişi BETA paneli (izole).  [Faz-1: müşteri çeki portföye alma]
 * Cari ara/seç → çek bilgileri (tutar/vade/no/banka/sahibi) → portföye al; girilenleri (geri alınabilir) listeler.
 * Erişim: M30 (Çek İşlemleri) yetkisi. Kapalıysa erişilemez.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/../kontrol.php');
include_once(__DIR__ . '/../donem_helper.php');
include_once(__DIR__ . '/../log_ip.php');
include_once(__DIR__ . '/cek_lib.php');

global $dbh, $firma, $firmadonem, $terminalkullanici, $yetkidurum;

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$para = static fn($v): string => number_format((float) $v, 2, ',', '.');

$acik = ((int) m_p_yetki($terminalkullanici, 'M30') === 1);
$erisim = $acik;

$ara = trim((string) ($_GET['ara'] ?? ''));
$cariRef = (int) ($_GET['cari'] ?? 0);
$cariler = []; $seciliCari = null; $cariBorc = 0.0; $cekler = []; $nextResmi = ''; $nextGayri = '';

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
    try {
        $cekler = $dbh->query("SELECT TOP 50 l.ID, l.CSCARD_PORTFOYNO, l.CSROLL_ROLLNO, l.TUTAR, l.DOCCNT, l.CEKNO, l.BANKNAME, l.VADE, l.SAHIBI,
                l.OLUSTURMA, l.CLIENTREF, l.DURUM, c.DEFINITION_, s.CODE GIREN
            FROM M_CEK_LOG l
            LEFT JOIN {$firma}CLCARD c ON c.LOGICALREF=l.CLIENTREF
            LEFT JOIN LG_SLSMAN s ON s.LOGICALREF=l.KULLANICI
            WHERE l.DURUM IN ('AKTIF','GERIALINDI') AND l.TUR='giris' ORDER BY l.ID DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $cekler = []; }
    try { $nextResmi = cek_portfoy_no($dbh, $firmadonem, true); $nextGayri = cek_portfoy_no($dbh, $firmadonem, false); } catch (Throwable $e) {}
}
$csrf = function_exists('csrf_token') ? csrf_token() : '';
$bugun = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Çek Girişi</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --amber:#b45309; --amber-soft:#fffbeb; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; background:var(--bg); color:var(--t1); font-size:14px; }
        .top { position:sticky; top:0; z-index:30; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
        .top-in { max-width:820px; margin:0 auto; padding:11px 16px; display:flex; align-items:center; gap:12px; }
        .top-in a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
        .top-title { font-size:15px; font-weight:600; flex:1; display:flex; align-items:center; gap:8px; }
        .top-title i { color:var(--red); }
        .rozet { font-size:10.5px; font-weight:700; letter-spacing:.5px; padding:3px 9px; border-radius:7px; background:var(--amber-soft); color:var(--amber); }
        main { max-width:820px; margin:0 auto; padding:18px 16px 60px; }
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
        .btn-light { background:var(--bg); color:var(--t2); border:1px solid var(--border); }
        .cari-item { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid var(--border); border-radius:11px; text-decoration:none; color:inherit; margin-bottom:7px; }
        .cari-item:hover { border-color:var(--red); background:var(--red-soft); }
        .cari-item .kod { font-weight:700; color:var(--red); font-size:12.5px; min-width:70px; }
        .cari-item .ad { flex:1; font-size:13.5px; }
        .sel-cari { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; background:var(--red-soft); border:1px solid var(--border); border-left:4px solid var(--red); border-radius:12px; margin-bottom:16px; }
        .sel-cari b { font-size:15px; }
        .sel-cari .bak { font-size:12px; color:var(--t2); }
        .sel-cari .bak b { color:var(--red); font-size:14px; }
        .frm { display:flex; flex-direction:column; gap:15px; }
        .frm label { font-size:11.5px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.3px; display:block; margin-bottom:6px; }
        .frm input { width:100%; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .tutar-box { position:relative; }
        #cekTutar { font-size:30px; font-weight:700; letter-spacing:.5px; padding:14px 46px 14px 16px; }
        .tutar-cur { position:absolute; right:18px; top:50%; transform:translateY(-50%); font-size:20px; font-weight:600; color:var(--t3); pointer-events:none; }
        .bak-sonra { font-size:12.5px; color:var(--t2); margin-top:8px; min-height:17px; }
        .bak-sonra b { color:var(--t1); }
        .zorunlu { color:var(--red); }
        .seg { display:flex; background:#f3f4f6; border-radius:12px; padding:4px; gap:4px; }
        .seg button { flex:1; border:none; background:transparent; font-family:inherit; font-size:13.5px; font-weight:600; color:var(--t2); padding:11px 8px; border-radius:9px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:6px; transition:.12s; }
        .seg button span { font-size:11px; font-weight:400; opacity:.7; }
        .seg button.on { background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.12); color:var(--red); }
        .ipucu { font-size:11.5px; color:var(--t3); margin-top:6px; }
        .ipucu i { color:var(--red); }
        .ipucu b { color:var(--t1); font-weight:600; }
        table { width:100%; border-collapse:collapse; }
        thead th { font-size:11px; text-transform:uppercase; color:var(--t3); font-weight:600; text-align:left; padding:10px 12px; border-bottom:1px solid var(--border); white-space:nowrap; }
        thead th.sag, tbody td.sag { text-align:right; }
        tbody td { padding:10px 12px; border-bottom:1px solid #f1f3f5; font-size:13px; white-space:nowrap; }
        .tut { color:var(--t1); font-weight:700; }
        .bos { padding:22px; text-align:center; color:var(--t3); font-size:13px; }
        #toast { position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(20px); background:var(--t1); color:#fff; padding:11px 20px; border-radius:12px; font-size:13px; font-weight:600; opacity:0; pointer-events:none; transition:.25s; z-index:60; }
        #toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
        .cek-row { border:1px solid var(--border); border-radius:12px; padding:12px; background:#fcfcfd; }
        .cek-row-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
        .cek-row-no { font-size:12px; font-weight:700; color:var(--red); }
        .cek-sil { border:none; background:transparent; color:var(--t3); cursor:pointer; font-size:14px; padding:4px 9px; border-radius:6px; }
        .cek-sil:hover { color:#b91c1c; background:#fef2f2; }
        .cek-row-grid { display:grid; grid-template-columns:1.15fr 1fr 1fr 1fr; gap:8px; }
        .cek-row-grid label { font-size:10px; margin-bottom:4px; }
        .cek-row-grid input { font-size:13px; padding:9px 10px; }
        .cek-ekle { align-self:flex-start; }
        .toplam-box { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:14px 16px; background:var(--red-soft); border:1px solid var(--border); border-radius:12px; }
        .toplam-box .tp-tut { font-size:21px; font-weight:700; color:var(--red); white-space:nowrap; }
        .toplam-box .tp-adet b { color:var(--t1); }
        @media (max-width:560px){ .grid2 { grid-template-columns:1fr; } .cek-row-grid { grid-template-columns:1fr 1fr; } }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="<?php echo $cariRef > 0 ? '../cari/cari_islemleri.php?cari=' . $cariRef : APP_ROOT_URL . '/index.php'; ?>" class="geri" title="<?php echo $cariRef > 0 ? 'Cari işlemlerine dön' : 'Ana sayfa'; ?>"><i class="fa fa-arrow-left"></i></a>
            <span class="top-title"><i class="fa-solid fa-money-check-dollar"></i> Çek Girişi</span>
        </div>
    </header>
    <main>
    <?php if (!$erisim): ?>
        <div class="card"><div class="kapali">
            <div><i class="fa-solid fa-lock"></i></div>
            <div>Çek girişi beta özelliği şu an <b>kapalı</b> veya size tanımlı değil.</div>
        </div></div>
    <?php else: ?>

        <!-- Cari arama / seçim -->
        <div class="card">
            <div class="card-h"><i class="fa-solid fa-user"></i> Çeki Veren Cari</div>
            <div class="pad">
                <form method="get" class="row">
                    <input type="text" name="ara" value="<?php echo $h($ara); ?>" placeholder="Cari adı veya kodu…" autocomplete="off" style="flex:1;min-width:180px;">
                    <button type="submit" class="btn btn-accent"><i class="fa-solid fa-magnifying-glass"></i> Ara</button>
                </form>
                <?php if ($seciliCari): ?>
                    <div class="sel-cari" style="margin-top:12px;">
                        <div><b><?php echo $h($seciliCari['DEFINITION_']); ?></b><br><span class="bak"><?php echo $h($seciliCari['CODE']); ?> · bakiye: <b><?php echo $para(abs($cariBorc)); ?> ₺</b> <?php echo $cariBorc < -0.005 ? 'alacak' : 'borç'; ?></span></div>
                        <a href="cek_panel.php" class="btn btn-light"><i class="fa-solid fa-xmark"></i> Değiştir</a>
                    </div>
                <?php elseif ($cariler): ?>
                    <div style="margin-top:12px;">
                        <?php foreach ($cariler as $c): ?>
                        <a class="cari-item" href="cek_panel.php?cari=<?php echo (int) $c['LOGICALREF']; ?>">
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

        <!-- Çek formu -->
        <?php if ($seciliCari): ?>
        <div class="card">
            <div class="card-h"><i class="fa-solid fa-money-check-dollar"></i> Çek Bilgileri</div>
            <div class="pad">
                <form id="cekForm" class="frm">
                    <input type="hidden" name="cari_ref" value="<?php echo (int) $seciliCari['LOGICALREF']; ?>">
                    <input type="hidden" name="resmi" id="resmiInp" value="0">
                    <div>
                        <label>Çek Türü</label>
                        <div class="seg" id="resmiSeg">
                            <button type="button" data-resmi="0" class="on"><i class="fa-solid fa-file"></i> Gayri Resmi <span>düz seri</span></button>
                            <button type="button" data-resmi="1"><i class="fa-solid fa-file-shield"></i> Resmi <span>R serisi</span></button>
                        </div>
                        <div class="ipucu"><i class="fa-solid fa-hashtag"></i> Portföy no: <b id="portfoyNext"><?php echo $h($nextGayri); ?></b></div>
                    </div>
                    <div>
                        <label>Çek Sahibi <span style="font-weight:400;color:var(--t3)">(tüm çekler için)</span></label>
                        <input type="text" id="cekSahibi" maxlength="200" value="<?php echo $h($seciliCari['DEFINITION_']); ?>" autocomplete="off">
                    </div>
                    <div id="cekRows"></div>
                    <button type="button" id="cekEkle" class="btn btn-light cek-ekle"><i class="fa-solid fa-plus"></i> Çek Ekle</button>
                    <div class="toplam-box">
                        <div>
                            <div class="tp-adet"><b id="cekAdet">1</b> çek</div>
                            <div class="bak-sonra" id="bakSonra"></div>
                        </div>
                        <span class="tp-tut" id="cekToplam">0,00 ₺</span>
                    </div>
                    <div>
                        <label>Açıklama (opsiyonel)</label>
                        <input type="text" name="aciklama" maxlength="240" placeholder="örn. mal bedeli" autocomplete="off">
                    </div>
                    <div>
                        <button type="submit" id="cekSubmit" class="btn btn-accent" style="width:100%;"><i class="fa-solid fa-check"></i> <span id="submitLbl">Çeki Portföye Al</span></button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Girilen çekler -->
        <div class="card">
            <div class="card-h"><i class="fa-solid fa-clock-rotate-left"></i> Çek Kayıtları (kim · ne zaman · geri alınabilir)</div>
            <?php if (empty($cekler)): ?>
                <div class="bos">Henüz çek girilmemiş.</div>
            <?php else: ?>
            <div style="overflow-x:auto"><table>
                <thead><tr><th>Portföy</th><th>Cari</th><th>Çek No</th><th>Banka</th><th>Vade</th><th class="sag">Tutar</th><th>Giren</th><th>Tarih</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($cekler as $t): $iptal = ((string) $t['DURUM'] === 'GERIALINDI'); ?>
                    <tr data-id="<?php echo (int) $t['ID']; ?>"<?php echo $iptal ? ' style="opacity:.55"' : ''; ?>>
                        <?php $adet = (int) ($t['DOCCNT'] ?? 1); ?>
                        <td><?php echo $h((string) $t['CSCARD_PORTFOYNO'] ?: '-'); ?><?php echo $adet > 1 ? ' <span style="font-size:10px;font-weight:700;color:var(--red);background:var(--red-soft);padding:1px 6px;border-radius:6px;white-space:nowrap">' . $adet . ' çek</span>' : ''; ?></td>
                        <td style="white-space:normal"><?php echo $h($t['DEFINITION_'] ?? '-'); ?></td>
                        <td><?php echo $h($t['CEKNO'] ?? '-'); echo $adet > 1 ? ' <span style="color:var(--t3);font-size:11px">+' . ($adet - 1) . '</span>' : ''; ?></td>
                        <td><?php echo $h($t['BANKNAME'] ?? '-'); ?></td>
                        <td><?php echo $h(substr((string) $t['VADE'], 0, 10)); ?></td>
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
        function bildir(msg, hata) { toast.textContent = msg; toast.style.background = hata ? '#b91c1c' : '#047857'; toast.classList.add('show'); clearTimeout(tt); tt = setTimeout(function(){ toast.classList.remove('show'); }, 2400); }

        var form = document.getElementById('cekForm');
        if (form) {
            var cariBorc = <?php echo json_encode(round((float) $cariBorc, 2)); ?>;
            var bugun = <?php echo json_encode($bugun); ?>;
            var cariAd = <?php echo json_encode($seciliCari['DEFINITION_']); ?>;
            var pfNext = { '0': <?php echo (int) ($nextGayri !== '' ? ltrim($nextGayri, '0') : '0'); ?>, '1': <?php echo (int) ($nextResmi !== '' ? ltrim(substr($nextResmi, 1), '0') : '0'); ?> };
            var resmiInp = document.getElementById('resmiInp');
            var cekRows = document.getElementById('cekRows');
            var cekAdet = document.getElementById('cekAdet');
            var cekToplam = document.getElementById('cekToplam');
            var bakSonra = document.getElementById('bakSonra');
            var portfoyNext = document.getElementById('portfoyNext');
            var submitLbl = document.getElementById('submitLbl');

            function trPara(n) { var neg = n < 0; n = Math.abs(n); var p = n.toFixed(2).split('.'); p[0] = p[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.'); return (neg ? '-' : '') + p[0] + ',' + p[1]; }
            function paraOku(v) { v = (v || '').replace(/\./g, '').replace(',', '.').replace(/[^\d.]/g, ''); var f = parseFloat(v); return isFinite(f) ? f : 0; }
            function pfFmt(resmi, num) { var s = String(num); if (resmi) { while (s.length < 7) s = '0' + s; return 'R' + s; } while (s.length < 8) s = '0' + s; return s; }

            function satirEkle(odak) {
                var div = document.createElement('div');
                div.className = 'cek-row';
                div.innerHTML =
                    '<div class="cek-row-top"><span class="cek-row-no">Çek</span>' +
                    '<button type="button" class="cek-sil" title="Bu çeki kaldır"><i class="fa-solid fa-trash-can"></i></button></div>' +
                    '<div class="cek-row-grid">' +
                    '<div><label>Tutar ₺ *</label><input class="k-tutar" inputmode="decimal" placeholder="0,00" autocomplete="off"></div>' +
                    '<div><label>Vade *</label><input class="k-vade" type="date" min="' + bugun + '"></div>' +
                    '<div><label>Çek No</label><input class="k-cekno" maxlength="60" autocomplete="off"></div>' +
                    '<div><label>Banka</label><input class="k-banka" maxlength="120" autocomplete="off"></div>' +
                    '</div>';
                cekRows.appendChild(div);
                div.querySelector('.cek-sil').addEventListener('click', function () { if (cekRows.children.length > 1) { div.remove(); yenile(); } });
                div.querySelector('.k-tutar').addEventListener('input', yenile);
                yenile();
                if (odak) { div.querySelector('.k-tutar').focus(); }
            }
            function kalemleriTopla() {
                var sahibi = (document.getElementById('cekSahibi').value || cariAd).trim();
                return Array.prototype.map.call(cekRows.children, function (row) {
                    return {
                        tutar: paraOku(row.querySelector('.k-tutar').value),
                        vade: row.querySelector('.k-vade').value,
                        cekno: row.querySelector('.k-cekno').value.trim(),
                        banka: row.querySelector('.k-banka').value.trim(),
                        sahibi: sahibi
                    };
                });
            }
            function yenile() {
                Array.prototype.forEach.call(cekRows.children, function (row, i) { row.querySelector('.cek-row-no').textContent = 'Çek ' + (i + 1); });
                var kl = kalemleriTopla();
                var adet = kl.length;
                var toplam = kl.reduce(function (s, k) { return s + (k.tutar > 0 ? k.tutar : 0); }, 0);
                if (cekAdet) { cekAdet.textContent = adet; }
                if (cekToplam) { cekToplam.textContent = trPara(toplam) + ' ₺'; }
                if (submitLbl) { submitLbl.textContent = adet > 1 ? (adet + ' Çeki Portföye Al') : 'Çeki Portföye Al'; }
                if (bakSonra) {
                    if (toplam <= 0) { bakSonra.innerHTML = ''; }
                    else {
                        var yeni = cariBorc - toplam;
                        bakSonra.innerHTML = Math.abs(yeni) < 0.005
                            ? '<i class="fa-solid fa-circle-check" style="color:#047857"></i> hesap kapanır'
                            : ('sonrası ≈ <b>' + trPara(Math.abs(yeni)) + ' ₺</b> ' + (yeni > 0 ? 'borç' : 'alacak'));
                    }
                }
                if (portfoyNext) {
                    var resmi = resmiInp.value === '1';
                    var next = pfNext[resmiInp.value] || 0;
                    portfoyNext.textContent = adet > 1 ? (pfFmt(resmi, next) + ' – ' + pfFmt(resmi, next + adet - 1)) : pfFmt(resmi, next);
                }
            }

            document.getElementById('cekEkle').addEventListener('click', function () { satirEkle(true); });
            satirEkle(false);

            var resmiSeg = document.getElementById('resmiSeg');
            if (resmiSeg) {
                resmiSeg.querySelectorAll('button').forEach(function (b) {
                    b.addEventListener('click', function () {
                        resmiInp.value = b.getAttribute('data-resmi');
                        resmiSeg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
                        yenile();
                    });
                });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var kl = kalemleriTopla();
                if (!kl.length) { bildir('En az bir çek girin', true); return; }
                for (var i = 0; i < kl.length; i++) {
                    if (kl[i].tutar <= 0) { bildir((i + 1) + '. çekin tutarını girin', true); return; }
                    if (!kl[i].vade) { bildir((i + 1) + '. çek için vade seçin', true); return; }
                }
                var btn = document.getElementById('cekSubmit'); btn.disabled = true;
                var body = new URLSearchParams();
                body.append('cari_ref', form.querySelector('[name=cari_ref]').value);
                body.append('resmi', resmiInp.value);
                body.append('aciklama', form.querySelector('[name=aciklama]').value);
                body.append('kalemler', JSON.stringify(kl));
                body.append('csrf_token', csrf);
                fetch('cek_giris.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(j){ bildir(j.mesaj || (j.ok?'Kaydedildi':'Hata'), !j.ok); if (j.ok) { setTimeout(function(){ location.reload(); }, 1200); } else { btn.disabled = false; } })
                    .catch(function(){ bildir('Bağlantı hatası', true); btn.disabled = false; });
            });
        }
        document.querySelectorAll('.geri-al').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!confirm('Bu çek girişi geri alınsın mı? Portföyden çıkarılır ve cari bakiye eski haline döner.')) return;
                b.disabled = true;
                var body = new URLSearchParams({ log_id: b.getAttribute('data-id'), csrf_token: csrf });
                fetch('cek_geri_al.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(j){ bildir(j.mesaj || (j.ok?'Geri alındı':'Hata'), !j.ok); if (j.ok) { setTimeout(function(){ location.reload(); }, 1000); } else { b.disabled = false; } })
                    .catch(function(){ bildir('Bağlantı hatası', true); b.disabled = false; });
            });
        });
    })();
    </script>
</body>
</html>

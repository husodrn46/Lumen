<?php

declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// Yalnizca yonetici
if ((int) ($yetkidurum ?? 1) !== 0) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
function cg_para(float|int|string|null $v): string
{
    global $parakusurat;
    return number_format((float) $v, $parakusurat ?? 2, ',', '.');
}
function cg_tarih(?string $t): string
{
    if (empty($t)) { return '-'; }
    $ts = strtotime($t);
    return $ts ? date('d.m.Y', $ts) : (string) $t;
}

// Portfoydeki cekler (CURRSTAT=1) + son hareketteki cari (kimden) + gorsel sayisi
$sql = "
    SELECT
        C.LOGICALREF AS CEK_REF,
        CAST(C.DUEDATE AS DATE) AS VADE,
        C.TRNET AS TUTAR,
        C.OWING AS KIMDEN,
        C.NEWSERINO AS SERI,
        C.PORTFOYNO,
        CAST(C.SETDATE AS DATE) AS GIRIS,
        ISNULL(CL.CODE, '') AS CARI_KOD,
        (SELECT COUNT(*) FROM M_CEK_EK E WHERE E.CEK_REF = C.LOGICALREF) AS GORSEL_SAYI
    FROM {$firmadonem}CSCARD C WITH(NOLOCK)
    LEFT JOIN (
        SELECT T.CSREF, T.CARDREF, ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
        FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
    ) TX ON TX.CSREF = C.LOGICALREF AND TX.RN = 1
    LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
    WHERE C.CURRSTAT IN (1) AND C.STATUS IN (0,1) AND C.DOC = 1
    ORDER BY (CASE WHEN (SELECT COUNT(*) FROM M_CEK_EK E WHERE E.CEK_REF = C.LOGICALREF) = 0 THEN 0 ELSE 1 END),
             CAST(C.SETDATE AS DATE) DESC, CAST(C.DUEDATE AS DATE) ASC
";
$cekler = $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Her cekin gorselleri (id + etiket)
$gorseller = [];
$refler = array_map(static fn($c) => (int) $c['CEK_REF'], $cekler);
if ($refler !== []) {
    $ph = implode(',', array_fill(0, count($refler), '?'));
    $gs = $dbh->prepare("SELECT ID, CEK_REF, ETIKET, DOSYA_ADI FROM M_CEK_EK WHERE CEK_REF IN ({$ph}) ORDER BY ID");
    $gs->execute($refler);
    while ($r = $gs->fetch(PDO::FETCH_ASSOC)) {
        $gorseller[(int) $r['CEK_REF']][] = $r;
    }
}

$eksikSayi = 0;
foreach ($cekler as $c) {
    if ((int) $c['GORSEL_SAYI'] === 0) { $eksikSayi++; }
}
$csrf = function_exists('csrf_field') ? csrf_field() : '';
$csrfToken = function_exists('csrf_token') ? csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Çek Görselleri</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --text-1:#1f2937; --text-2:#6b7280; --text-3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; --amber:#d97706; --amber-soft:#fffbeb; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; background:var(--bg); color:var(--text-1); min-height:100vh; }
        .top-header { position:sticky; top:0; z-index:40; height:60px; background:rgba(255,255,255,0.92); backdrop-filter:blur(6px); border-bottom:1px solid rgba(248,113,113,0.18); }
        .header-inner { max-width:900px; margin:0 auto; height:100%; display:flex; align-items:center; gap:12px; padding:0 18px; }
        .header-back { display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; border-radius:10px; color:var(--text-2); text-decoration:none; }
        .header-back:hover { background:rgba(0,0,0,0.04); color:var(--red); }
        .header-title { font-size:17px; font-weight:700; display:inline-flex; align-items:center; gap:8px; }
        .header-title i { color:var(--red,#ef4444); }
        main { max-width:900px; margin:0 auto; padding:18px 18px 60px; }
        .ozet { display:flex; align-items:center; gap:10px; padding:13px 16px; border-radius:12px; margin-bottom:16px; font-size:14px; font-weight:600; }
        .ozet.uyari { background:var(--amber-soft); color:#92400e; border:1px solid #fde68a; }
        .ozet.ok { background:var(--emerald-soft); color:var(--emerald); border:1px solid #a7f3d0; }
        .cek-card { background:#fff; border:1px solid var(--border); border-radius:14px; margin-bottom:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,0.03); }
        .cek-card.eksik { border-color:#fca5a5; }
        .cek-head { display:flex; align-items:center; gap:12px; padding:14px 16px; cursor:pointer; }
        .cek-head:hover { background:#fafafa; }
        .cek-ico { width:42px; height:42px; border-radius:11px; flex-shrink:0; display:inline-flex; align-items:center; justify-content:center; font-size:17px; }
        .cek-ico.eksik { background:var(--red-soft); color:var(--red); }
        .cek-ico.tam { background:var(--emerald-soft); color:var(--emerald); }
        .cek-main { flex:1; min-width:0; }
        .cek-kimden { font-size:14.5px; font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .cek-meta { font-size:12px; color:var(--text-2); margin-top:2px; }
        .cek-right { text-align:right; flex-shrink:0; }
        .cek-tutar { font-size:15px; font-weight:700; white-space:nowrap; }
        .cek-vade { font-size:11.5px; color:var(--text-2); margin-top:2px; }
        .durum-rozet { display:inline-block; margin-top:4px; padding:2px 9px; border-radius:100px; font-size:10.5px; font-weight:700; }
        .durum-rozet.eksik { background:var(--red-soft); color:var(--red); }
        .durum-rozet.tam { background:var(--emerald-soft); color:var(--emerald); }
        .cek-body { display:none; padding:0 16px 16px; border-top:1px solid var(--border); }
        .cek-card.open .cek-body { display:block; }
        .gorsel-grid { display:flex; flex-wrap:wrap; gap:10px; margin:14px 0; }
        .gorsel-item { position:relative; width:110px; }
        .gorsel-item a { display:block; }
        .gorsel-item img, .gorsel-item .pdfico { width:110px; height:110px; object-fit:cover; border-radius:10px; border:1px solid var(--border); background:#f3f4f6; display:flex; align-items:center; justify-content:center; color:var(--text-3); font-size:28px; }
        .gorsel-item .etiket { position:absolute; top:6px; left:6px; padding:2px 7px; border-radius:6px; background:rgba(0,0,0,0.6); color:#fff; font-size:10px; font-weight:600; }
        .gorsel-item .sil { position:absolute; top:6px; right:6px; width:24px; height:24px; border-radius:50%; background:rgba(111,16,34,0.92); color:#fff; border:none; cursor:pointer; font-size:12px; display:flex; align-items:center; justify-content:center; }
        .yukle-row { display:flex; flex-wrap:wrap; gap:10px; margin-top:6px; }
        .yukle-btn { flex:1 1 auto; min-width:130px; min-height:48px; display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:12px 14px; border-radius:10px; border:1px dashed #fca5a5; background:var(--red-soft); color:var(--red); font-family:'Avenir Next','Montserrat',sans-serif; font-size:13.5px; font-weight:600; cursor:pointer; }
        .yukle-btn.ek { border-color:var(--border); background:#f9fafb; color:var(--text-2); }
        .yukle-btn:active { transform:scale(0.98); }
        .empty { text-align:center; padding:50px 20px; color:var(--text-3); }
        .empty i { font-size:36px; display:block; margin-bottom:12px; }
        .uploading { font-size:12px; color:var(--amber); margin-top:8px; display:none; }
        @media (max-width:560px){ main{padding:14px 12px 50px;} .cek-meta{font-size:11px;} .gorsel-item,.gorsel-item img,.gorsel-item .pdfico{width:96px;} .gorsel-item .pdfico{height:96px;} }
    </style>
</head>
<body>
    <header class="top-header">
        <div class="header-inner">
            <a href="../index.php" class="header-back" title="Ana Sayfa"><i class="fa fa-arrow-left"></i></a>
            <span class="header-title"><i class="fa-solid fa-money-check-dollar"></i> Çek Görselleri</span>
        </div>
    </header>

    <main>
        <?php if ($eksikSayi > 0): ?>
            <div class="ozet uyari"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $eksikSayi; ?> çekin görseli eksik. Çeke dokunup ön/arka fotoğrafı ekleyin.</div>
        <?php else: ?>
            <div class="ozet ok"><i class="fa-solid fa-circle-check"></i> Portföydeki tüm çeklerin görseli mevcut.</div>
        <?php endif; ?>

        <?php if (empty($cekler)): ?>
            <div class="empty"><i class="fa-solid fa-inbox"></i> Portföyde çek bulunamadı.</div>
        <?php else: ?>
            <?php foreach ($cekler as $c):
                $ref = (int) $c['CEK_REF'];
                $eksik = (int) $c['GORSEL_SAYI'] === 0;
                $gs = $gorseller[$ref] ?? [];
            ?>
            <div class="cek-card <?php echo $eksik ? 'eksik' : ''; ?>" id="cek-<?php echo $ref; ?>">
                <div class="cek-head" onclick="document.getElementById('cek-<?php echo $ref; ?>').classList.toggle('open')">
                    <span class="cek-ico <?php echo $eksik ? 'eksik' : 'tam'; ?>"><i class="fa-solid <?php echo $eksik ? 'fa-camera' : 'fa-check'; ?>"></i></span>
                    <div class="cek-main">
                        <div class="cek-kimden"><?php echo $h($c['KIMDEN'] ?: ($c['CARI_KOD'] ?: 'Bilinmiyor')); ?></div>
                        <div class="cek-meta"><i class="fa-solid fa-calendar-plus" style="font-size:10px;"></i> Alım: <?php echo cg_tarih($c['GIRIS'] ?? null); ?> · Seri: <?php echo $h($c['SERI'] ?: '-'); ?></div>
                    </div>
                    <div class="cek-right">
                        <div class="cek-tutar"><?php echo cg_para($c['TUTAR']); ?> ₺</div>
                        <div class="cek-vade">Vade: <?php echo cg_tarih($c['VADE']); ?></div>
                        <span class="durum-rozet <?php echo $eksik ? 'eksik' : 'tam'; ?>"><?php echo $eksik ? 'Görsel yok' : ((int) $c['GORSEL_SAYI'] . ' görsel'); ?></span>
                    </div>
                </div>
                <div class="cek-body">
                    <div class="gorsel-grid" id="grid-<?php echo $ref; ?>">
                        <?php foreach ($gs as $g):
                            $isPdf = str_contains((string) ($g['DOSYA_ADI'] ?? ''), '.pdf');
                        ?>
                        <div class="gorsel-item" id="ek-<?php echo (int) $g['ID']; ?>">
                            <span class="etiket"><?php echo $h(ucfirst((string) ($g['ETIKET'] ?? 'ek'))); ?></span>
                            <button type="button" class="sil" title="Sil" onclick="cekEkSil(<?php echo (int) $g['ID']; ?>)"><i class="fa-solid fa-xmark"></i></button>
                            <a href="cek_ek_goster.php?id=<?php echo (int) $g['ID']; ?>" target="_blank" rel="noopener">
                                <?php if ($isPdf): ?>
                                    <span class="pdfico"><i class="fa-solid fa-file-pdf"></i></span>
                                <?php else: ?>
                                    <img src="cek_ek_goster.php?id=<?php echo (int) $g['ID']; ?>" alt="cek gorseli" loading="lazy">
                                <?php endif; ?>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="yukle-row">
                        <button type="button" class="yukle-btn" onclick="document.getElementById('inp-on-<?php echo $ref; ?>').click()"><i class="fa-solid fa-camera"></i> Ön Yüz</button>
                        <button type="button" class="yukle-btn" onclick="document.getElementById('inp-arka-<?php echo $ref; ?>').click()"><i class="fa-solid fa-camera-rotate"></i> Arka Yüz</button>
                        <button type="button" class="yukle-btn ek" onclick="document.getElementById('inp-ek-<?php echo $ref; ?>').click()"><i class="fa-solid fa-paperclip"></i> Ek</button>
                    </div>
                    <div class="uploading" id="up-<?php echo $ref; ?>"><i class="fa-solid fa-circle-notch fa-spin"></i> Yükleniyor...</div>
                    <input type="file" accept="image/*" capture="environment" style="display:none" id="inp-on-<?php echo $ref; ?>" onchange="cekEkYukle(<?php echo $ref; ?>,'on',this)">
                    <input type="file" accept="image/*" capture="environment" style="display:none" id="inp-arka-<?php echo $ref; ?>" onchange="cekEkYukle(<?php echo $ref; ?>,'arka',this)">
                    <input type="file" accept="image/*,application/pdf" style="display:none" id="inp-ek-<?php echo $ref; ?>" onchange="cekEkYukle(<?php echo $ref; ?>,'ek',this)">
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>

    <script>
        const CEK_CSRF = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        function cekEkYukle(cekRef, etiket, input) {
            if (!input.files || !input.files[0]) return;
            const dosya = input.files[0];
            input.value = '';
            cekKirpAc(dosya, function (blob) {
                const up = document.getElementById('up-' + cekRef);
                if (up) up.style.display = 'block';
                const fd = new FormData();
                fd.append('cek_ref', cekRef);
                fd.append('etiket', etiket);
                fd.append('csrf_token', CEK_CSRF);
                fd.append('dosya', blob, blob.name || 'cek.jpg');
                fetch('cek_ek_yukle.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(j => {
                        if (up) up.style.display = 'none';
                        if (j.ok) { location.reload(); }
                        else { if (window.toast) { toast(j.mesaj || 'Yükleme başarısız.', 'error'); } else { alert(j.mesaj || 'Yükleme başarısız.'); } }
                    })
                    .catch(() => { if (up) up.style.display = 'none'; if (window.toast) { toast('Bağlantı hatası.', 'error'); } else { alert('Bağlantı hatası.'); } });
            });
        }
        function cekEkSil(ekId) {
            if (!confirm('Bu görseli silmek istiyor musunuz?')) return;
            const fd = new FormData();
            fd.append('ek_id', ekId);
            fd.append('csrf_token', CEK_CSRF);
            fetch('cek_ek_sil.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(j => { if (j.ok) { location.reload(); } else { if (window.toast) { toast(j.mesaj || 'Silinemedi.', 'error'); } else { alert(j.mesaj || 'Silinemedi.'); } } })
                .catch(() => { if (window.toast) { toast('Bağlantı hatası.', 'error'); } else { alert('Bağlantı hatası.'); } });
        }
    </script>
    <?php include_once(__DIR__ . '/../ux_katman.php'); ?>
    <?php include_once(__DIR__ . '/cek_kirpma.php'); ?>
</body>
</html>

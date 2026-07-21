<?php

declare(strict_types=1);

/**
 * cari_islemleri.php — Cari İşlemleri merkezi (hub).
 * Bir cari için çek işlemlerini tek yerde toplar; her işlem o cariye önceden bağlı açılır.
 *   Çek: Giriş (cek/cek_panel.php) · Çıkış/Ciro (cek/cek_ciro_panel.php) · Kendi Çekimiz (cek/cek_kendi_panel.php)
 * Erişim: M30 (Çek İşlemleri) yetkisi (fail-closed; yönetici otomatik alır).
 */

include_once(__DIR__ . '/ayr.php');
include_once(__DIR__ . '/kontrol.php');
include_once(__DIR__ . '/donem_helper.php');
include_once(__DIR__ . '/log_ip.php');

global $dbh, $firma, $firmadonem, $terminalkullanici, $yetkidurum;
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$para = static fn($v): string => number_format((float) $v, 2, ',', '.');

// Çek işlemleri erişimi: M30 (Çek İşlemleri) yetkisi — yönetici otomatik alır (fail-closed).
$cekGirisOk = ((int) m_p_yetki($terminalkullanici, 'M30') === 1);
$cekCikisOk = $cekGirisOk;
$erisim = $cekGirisOk;

$cariRef = (int) ($_GET['cari'] ?? 0);
$cari = null; $bakiye = 0.0; $portfoyAdet = 0; $portfoyTutar = 0.0;
if ($erisim && $cariRef > 0) {
    try {
        $q = $dbh->prepare("SELECT LOGICALREF, CODE, DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF=:r");
        $q->execute([':r' => $cariRef]);
        $cari = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($cari) {
            $bakiye = (float) $dbh->query("SELECT ISNULL(SUM(CASE WHEN SIGN=0 THEN AMOUNT ELSE -AMOUNT END),0) FROM {$firmadonem}CLFLINE WHERE CLIENTREF={$cariRef} AND CANCELLED=0")->fetchColumn();
            $r = $dbh->query("SELECT COUNT(*) a, ISNULL(SUM(cc.AMOUNT),0) t
                FROM {$firmadonem}CSCARD cc JOIN {$firmadonem}CSTRANS ct ON ct.CSREF=cc.LOGICALREF AND ct.TRCODE=1
                WHERE ct.CARDREF={$cariRef} AND cc.DOC=1 AND cc.CURRSTAT=1 AND cc.CANCELLED=0")->fetch(PDO::FETCH_ASSOC);
            $portfoyAdet = (int) ($r['a'] ?? 0);
            $portfoyTutar = (float) ($r['t'] ?? 0);
        }
    } catch (Throwable $e) { error_log('cari_islemleri: ' . $e->getMessage()); }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari İşlemleri</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once(__DIR__ . '/pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; --amber:#b45309; --amber-soft:#fffbeb; --indigo:#4f46e5; --indigo-soft:#eef2ff; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; background:var(--bg); color:var(--t1); font-size:14px; }
        .top { position:sticky; top:0; z-index:30; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
        .top-in { max-width:760px; margin:0 auto; padding:11px 16px; display:flex; align-items:center; gap:12px; }
        .top-in a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
        .top-title { font-size:15px; font-weight:600; flex:1; display:flex; align-items:center; gap:8px; }
        .top-title i { color:var(--red); }
        main { max-width:760px; margin:0 auto; padding:18px 16px 60px; }
        .card { background:var(--card); border:1px solid var(--border); border-radius:14px; margin-bottom:16px; overflow:hidden; }
        .kapali { padding:40px 20px; text-align:center; color:var(--t2); }
        .kapali i { font-size:34px; color:var(--t3); margin-bottom:12px; }
        /* Cari başlık */
        .cari-head { padding:18px; }
        .cari-ad { font-size:18px; font-weight:700; line-height:1.3; }
        .cari-kod { font-size:12.5px; color:var(--t2); margin-top:2px; }
        .cari-alt { display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
        .stat { flex:1 1 160px; padding:12px 14px; border:1px solid var(--border); border-radius:12px; background:#fafafa; }
        .stat .l { font-size:10.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--t3); font-weight:600; }
        .stat .v { font-size:18px; font-weight:700; margin-top:3px; }
        .stat .v.borc { color:var(--emerald); }   /* borçlu=yeşil (tahsil edilecek) — site geneli kural */
        .stat .v.alacak { color:var(--red); }
        .stat .s { font-size:11px; color:var(--t2); margin-top:2px; }
        /* Bölüm */
        .bolum-h { font-size:11.5px; font-weight:700; color:var(--t2); text-transform:uppercase; letter-spacing:.5px; margin:22px 4px 10px; display:flex; align-items:center; gap:7px; }
        .bolum-h i { color:var(--red); }
        .aksiyonlar { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .aksiyon { display:flex; align-items:center; gap:14px; padding:16px; background:var(--card); border:1px solid var(--border); border-radius:14px; text-decoration:none; color:inherit; transition:.16s; }
        .aksiyon:hover { border-color:var(--red); box-shadow:0 6px 18px rgba(0,0,0,.06); transform:translateY(-1px); }
        .aksiyon .ico { flex-shrink:0; width:46px; height:46px; border-radius:12px; display:inline-flex; align-items:center; justify-content:center; font-size:19px; }
        .aksiyon .metin { min-width:0; }
        .aksiyon .baslik { font-size:14.5px; font-weight:700; }
        .aksiyon .aciklama { font-size:11.5px; color:var(--t2); margin-top:2px; line-height:1.35; }
        .aksiyon .ok { margin-left:auto; color:var(--t3); font-size:13px; }
        .ico.kasa { background:var(--emerald-soft); color:var(--emerald); }
        .ico.giris { background:var(--red-soft); color:var(--red); }
        .ico.cikis { background:var(--amber-soft); color:var(--amber); }
        .aksiyon.tam { grid-column:1/-1; }
        .not { font-size:12px; color:var(--t3); text-align:center; padding:16px; }
        @media (max-width:560px){ .aksiyonlar { grid-template-columns:1fr; } .aksiyon.tam { grid-column:auto; } }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <?php
            // Deterministik geri hedefi: history.back() panellerden donuste
            // ping-pong yapiyordu (panel -> islemler -> geri -> panel).
            $geriHref = ($cari !== null && trim((string) ($cari['CODE'] ?? '')) !== '')
                ? 'lg_bakiye.php?q=' . rawurlencode(trim((string) $cari['CODE']))
                : 'lg_bakiye.php';
            ?>
            <a href="<?php echo htmlspecialchars($geriHref, ENT_QUOTES, 'UTF-8'); ?>" class="geri" title="Müşteri bakiyesine dön"><i class="fa fa-arrow-left"></i></a>
            <span class="top-title"><i class="fa-solid fa-right-left"></i> Cari İşlemleri</span>
        </div>
    </header>
    <main>
    <?php if (!$erisim): ?>
        <div class="card"><div class="kapali">
            <div><i class="fa-solid fa-lock"></i></div>
            <div>Çek işlemleri için yetkiniz yok. (Ayarlar → Kullanıcı &amp; Yetki → İzin Matrisi'nden <b>Çek İşlemleri</b> yetkisi verilebilir.)</div>
        </div></div>
    <?php elseif (!$cari): ?>
        <div class="card"><div class="kapali">
            <div><i class="fa-solid fa-user-slash"></i></div>
            <div>Cari bulunamadı. Bakiye listesinden bir cari seçerek gelin.</div>
        </div></div>
    <?php else: ?>

        <!-- Cari başlık -->
        <div class="card">
            <div class="cari-head">
                <div class="cari-ad"><?php echo $h($cari['DEFINITION_']); ?></div>
                <div class="cari-kod"><i class="fa-solid fa-hashtag" style="font-size:10px"></i> <?php echo $h($cari['CODE']); ?></div>
                <div class="cari-alt">
                    <div class="stat">
                        <div class="l">Bakiye</div>
                        <div class="v <?php echo $bakiye > 0.005 ? 'borc' : ($bakiye < -0.005 ? 'alacak' : ''); ?>"><?php echo $para(abs($bakiye)); ?> ₺</div>
                        <div class="s"><?php echo $bakiye > 0.005 ? 'borçlu' : ($bakiye < -0.005 ? 'alacaklı (ona borçluyuz)' : 'kapalı'); ?></div>
                    </div>
                    <div class="stat">
                        <div class="l">Portföydeki Çeki</div>
                        <div class="v"><?php echo $portfoyAdet; ?> <span style="font-size:12px;font-weight:600;color:var(--t2)">adet</span></div>
                        <div class="s"><?php echo $portfoyAdet > 0 ? $para($portfoyTutar) . ' ₺' : 'bu cariden portföyde çek yok'; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($cekGirisOk || $cekCikisOk): ?>
        <div class="bolum-h"><i class="fa-solid fa-money-check-dollar"></i> Çek İşlemleri</div>
        <div class="aksiyonlar">
            <?php if ($cekGirisOk): ?>
            <a class="aksiyon" href="cek/cek_panel.php?cari=<?php echo (int) $cari['LOGICALREF']; ?>">
                <span class="ico giris"><i class="fa-solid fa-arrow-down-long"></i></span>
                <span class="metin">
                    <span class="baslik">Çek Girişi</span>
                    <span class="aciklama">Bu cariden çek al (portföye)</span>
                </span>
                <i class="fa-solid fa-chevron-right ok"></i>
            </a>
            <?php endif; ?>
            <?php if ($cekCikisOk): ?>
            <a class="aksiyon" href="cek/cek_ciro_panel.php?cari=<?php echo (int) $cari['LOGICALREF']; ?>">
                <span class="ico cikis"><i class="fa-solid fa-share-from-square"></i></span>
                <span class="metin">
                    <span class="baslik">Çek Çıkışı / Ciro</span>
                    <span class="aciklama">Portföydeki müşteri çeklerini bu cariye ciro et</span>
                </span>
                <i class="fa-solid fa-chevron-right ok"></i>
            </a>
            <a class="aksiyon" href="cek/cek_kendi_panel.php?cari=<?php echo (int) $cari['LOGICALREF']; ?>">
                <span class="ico cikis"><i class="fa-solid fa-money-check"></i></span>
                <span class="metin">
                    <span class="baslik">Kendi Çekimiz</span>
                    <span class="aciklama">Kendi çekimizi düzenleyip bu cariye ver</span>
                </span>
                <i class="fa-solid fa-chevron-right ok"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="not"><i class="fa-solid fa-circle-info"></i> İşlemler bu cariye önceden bağlı açılır. Her işlem geri alınabilir (denetim loglu).</div>
    <?php endif; ?>
    </main>
</body>
</html>

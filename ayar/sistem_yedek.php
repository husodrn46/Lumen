<?php

declare(strict_types=1);

/**
 * sistem_yedek.php — Çalışma modu bilgisi ve uygulama içi yedekleme.
 *
 * Mod (LOGO'ya bağlı / bağımsız) ve veritabanı burada görünür; mod kurulumda seçilir
 * (.env: LUMEN_VERI_KAYNAGI). Yedekleme ek yazılım gerektirmez (includes/yedek/yedek_lib.php):
 * elle yedek, günlük otomatik yedek, indirme, yükleme ve geri yükleme.
 * Yedekler parola özetlerini de içerdiğinden yedek işlemleri yalnız gerçek yöneticiye
 * (YETKI=0) açıktır; M16 yetkili personel yalnız mod bilgisini görür.
 */

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/../includes/yedek/yedek_lib.php';

ayar_require_m16($terminalkullanici);
$yonetici = $yetkidurum === 0;
$h = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$mesaj = '';
$mesajTip = '';

// İndirme (GET, yalnız yönetici, yalnız Lumen'in adlandırdığı dosyalar)
if (isset($_GET['indir'])) {
    $ad = basename((string) $_GET['indir']);
    $yol = lumen_yedek_klasoru() . '/' . $ad;
    if (!$yonetici || !lumen_yedek_dosya_adi_gecerli($ad) || !is_file($yol)) {
        ayar_forbidden();
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . $ad . '"');
    header('Content-Length: ' . filesize($yol));
    header('X-Content-Type-Options: nosniff');
    readfile($yol);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ayar_require_csrf();
    if (!$yonetici) {
        ayar_forbidden(false, 'Yedek işlemleri yalnız yöneticiye açıktır');
    }
    @set_time_limit(600);
    $islem = (string) ($_POST['islem'] ?? '');
    // Sayfa yenilenince (formu yeniden gönder) aynı işlem ikinci kez yapılmasın: her form tek
    // kullanımlık işlem numarası taşır (yedek tekrar alınmaz, geri yükleme tekrarlanmaz).
    $islemId = preg_match('/^[a-f0-9]{16}$/', (string) ($_POST['islem_id'] ?? '')) === 1 ? (string) $_POST['islem_id'] : '';
    $yapilan = is_array($_SESSION['yedek_islem'] ?? null) ? $_SESSION['yedek_islem'] : [];
    if ($islemId !== '' && isset($yapilan[$islemId])) {
        [$mesaj, $mesajTip] = ['Bu işlem zaten yapıldı (sayfa yenilendi); tekrar yapılmadı.', 'error'];
        $islem = '';
    } elseif ($islemId !== '') {
        $_SESSION['yedek_islem'] = array_slice([$islemId => time()] + $yapilan, 0, 50, true);
    }
    if ($islem === 'yedek_al') {
        $r = lumen_yedek_al($dbh, 'elle');
        [$mesaj, $mesajTip] = [$r['mesaj'], $r['ok'] ? 'ok' : 'error'];
    } elseif ($islem === 'ayar') {
        $hata = lumen_yedek_ayar_kaydet((string) ($_POST['klasor'] ?? ''), isset($_POST['otomatik']), (int) ($_POST['saklama'] ?? 30));
        [$mesaj, $mesajTip] = $hata === null ? ['Yedekleme ayarları kaydedildi.', 'ok'] : [$hata, 'error'];
    } elseif ($islem === 'yukle') {
        $f = $_FILES['dosya'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            [$mesaj, $mesajTip] = ['Dosya yüklenemedi (boyut sınırı: ' . ini_get('upload_max_filesize') . ').', 'error'];
        } else {
            $d = lumen_yedek_dogrula((string) $f['tmp_name']);
            if (is_string($d)) {
                [$mesaj, $mesajTip] = [$d, 'error'];
            } else {
                $ad = 'lumen_' . date('Ymd_His') . '_yuklenen_' . bin2hex(random_bytes(3)) . LUMEN_YEDEK_UZANTI;
                $ok = move_uploaded_file((string) $f['tmp_name'], lumen_yedek_klasoru() . '/' . $ad);
                [$mesaj, $mesajTip] = $ok ? ["Yedek yüklendi ({$ad}). Geri yüklemek için listeden seçin.", 'ok'] : ['Dosya kaydedilemedi.', 'error'];
            }
        }
    } elseif ($islem === 'geri_yukle') {
        $ad = basename((string) ($_POST['ad'] ?? ''));
        $onay = str_replace(['İ', 'ı'], ['I', 'i'], mb_strtoupper(trim((string) ($_POST['onay'] ?? '')), 'UTF-8'));
        if (!lumen_yedek_dosya_adi_gecerli($ad) || !is_file(lumen_yedek_klasoru() . '/' . $ad)) {
            [$mesaj, $mesajTip] = ['Yedek bulunamadı.', 'error'];
        } elseif ($onay !== 'GERI YÜKLE') {
            [$mesaj, $mesajTip] = ['Onay için kutuya GERİ YÜKLE yazın.', 'error'];
        } else {
            $r = lumen_yedek_geri_yukle($dbh, lumen_yedek_klasoru() . '/' . $ad);
            [$mesaj, $mesajTip] = [$r['mesaj'], $r['ok'] ? 'ok' : 'error'];
        }
    }
    if ($islem !== '' && function_exists('app_log_write')) {
        app_log_write('ayar-degisiklik', ['ekran' => 'yedek', 'islem' => $islem, 'personel' => (int) $terminalkullanici, 'sonuc' => $mesajTip]);
    }
}

$bagimsiz = lumen_bagimsiz_mi();
$veritabani = (string) $dbh->query('SELECT DB_NAME()')->fetchColumn();
$ayar = lumen_yedek_ayar();
$liste = $yonetici ? lumen_yedek_listesi() : [];
$sonOto = @file_get_contents(dirname(__DIR__) . '/database/yedek_son_otomatik.txt') ?: '';
$turAd = ['elle' => 'Elle', 'otomatik' => 'Otomatik', 'geri_yukleme_oncesi' => 'Geri yükleme öncesi', 'yuklenen' => 'Yüklenen'];
$boyut = static fn(int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, $b / 1024), 0, ',', '.') . ' KB';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistem ve Yedek - Lumen</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; --amber:#b45309; --amber-soft:#fffbeb; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; background:var(--bg); color:var(--t1); font-size:14px; }
        main { max-width:860px; margin:0 auto; padding:20px 16px 60px; display:flex; flex-direction:column; gap:16px; }
        .kart { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:20px; }
        .kart h2 { font-size:15px; font-weight:700; margin-bottom:12px; display:flex; align-items:center; gap:8px; }
        .kart h2 i { color:var(--red); }
        .aciklama { font-size:13px; color:var(--t2); line-height:1.55; }
        .aciklama code { background:#f3f4f6; padding:1px 5px; border-radius:5px; font-size:12px; }
        .bilgi { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:14px; }
        .bilgi .k { font-size:11.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--t3); font-weight:600; }
        .bilgi .v { font-size:15px; font-weight:700; margin-top:3px; }
        .rozet { display:inline-block; font-size:11px; font-weight:700; padding:2px 8px; border-radius:7px; vertical-align:2px; }
        .rozet.beta { background:var(--amber-soft); color:var(--amber); border:1px solid #fde68a; }
        .rozet.logo { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
        .btn { display:inline-flex; align-items:center; gap:7px; padding:10px 15px; border-radius:10px; font-weight:600; font-size:13.5px; cursor:pointer; border:1px solid transparent; font-family:inherit; text-decoration:none; white-space:nowrap; }
        .btn-hat { background:#fff; color:var(--t1); border:1px solid var(--border); } .btn-hat:hover { border-color:var(--red); color:var(--red); }
        .btn-red { background:var(--red); color:#fff; } .btn-red:hover { filter:brightness(1.08); }
        .btn-kucuk { padding:6px 10px; font-size:12.5px; }
        .satir { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
        input[type=text], input[type=number], input[type=file] { padding:9px 11px; border:1px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; outline:none; }
        input:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
        label.alan { display:flex; flex-direction:column; gap:5px; font-size:12px; font-weight:600; color:var(--t2); }
        table { width:100%; border-collapse:collapse; }
        th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.4px; color:var(--t2); padding:9px 10px; border-bottom:1px solid var(--border); background:#fafafa; }
        td { padding:9px 10px; border-bottom:1px solid #f1f3f5; vertical-align:middle; font-size:13px; }
        .sag { text-align:right; } .soluk { color:var(--t3); font-size:12px; }
        .tablo-sar { overflow-x:auto; }
        .mesaj { padding:12px 14px; border-radius:10px; font-size:13px; display:flex; gap:9px; align-items:center; }
        .mesaj.ok { background:var(--emerald-soft); color:#065f46; border:1px solid #a7f3d0; }
        .mesaj.error { background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }
        .mesaj.uyari { background:var(--amber-soft); color:#92400e; border:1px solid #fde68a; }
        dialog { border:none; border-radius:14px; padding:22px; max-width:440px; width:calc(100% - 32px); box-shadow:0 20px 50px rgba(0,0,0,.25); }
        dialog::backdrop { background:rgba(15,23,42,.45); }
        dialog h3 { font-size:16px; margin-bottom:8px; }
    </style>
</head>
<body>
    <?php $saAktifSekme = 'yedek'; include __DIR__ . '/sistem_sekmeler.php'; ?>
    <main>
        <?php if ($mesaj !== ''): ?>
            <div class="mesaj <?= $mesajTip === 'ok' ? 'ok' : 'error' ?>" role="status">
                <i class="fa-solid <?= $mesajTip === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><span><?= $h($mesaj) ?></span>
            </div>
        <?php endif; ?>

        <?php require_once __DIR__ . '/../includes/guvenlik/php_surum.php';
        $phpSorunlari = lumen_php_guvenlik_sorunlari(); ?>
        <?php if ($phpSorunlari !== []): ?>
            <div class="mesaj error" role="alert"><i class="fa-solid fa-shield-halved"></i>
                <span><b>Sunucu PHP'si güncellenmeli (kritik güvenlik açığı).</b> <?= $h(implode(' ', $phpSorunlari)) ?></span></div>
        <?php endif; ?>
        <section class="kart" id="calisma-modu">
            <h2><i class="fa-solid fa-database"></i> Çalışma modu</h2>
            <div class="bilgi">
                <div><div class="k">Mod</div><div class="v">
                    <?php if ($bagimsiz): ?>Bağımsız
                    <?php else: ?>LOGO'ya bağlı <span class="rozet logo">LOGO</span><?php endif; ?></div></div>
                <div><div class="k">Veritabanı</div><div class="v"><?= $h($veritabani) ?></div></div>
                <div><div class="k">Veriler</div><div class="v"><?= $bagimsiz ? 'Lumen veritabanında' : 'LOGO Tiger tablolarında' ?></div></div>
                <div><div class="k">PHP</div><div class="v"><?= $h(PHP_VERSION) ?> <?= $phpSorunlari === [] ? '<span class="rozet logo">güncel</span>' : '<span class="rozet beta">güncellenmeli</span>' ?></div></div>
            </div>
            <p class="aciklama">
                <?php if ($bagimsiz): ?>
                    Cari, stok, fatura, kasa ve çek kayıtları Lumen'in kendi veritabanındadır; LOGO gerekmez.
                <?php else: ?>
                    Lumen, LOGO Tiger veritabanındaki kayıtları okuyup yazan bir ön yüz olarak çalışır.
                <?php endif; ?>
                Mod kurulum sihirbazında seçilir ve sunucudaki <code>.env</code> dosyasında
                <code>LUMEN_VERI_KAYNAGI=<?= $bagimsiz ? 'lumen' : 'logo' ?></code> olarak durur. Modu değiştirmek
                verileri taşımaz: LOGO'dan bağımsız moda geçiş için önce <code>scripts/logo_aktar.php</code> ile
                veriler yeni veritabanına aktarılır (ayrıntı: <code>docs/mimari/bagimsiz-lumen.md</code>).
            </p>
        </section>

        <section class="kart" id="yedekleme">
            <h2><i class="fa-solid fa-box-archive"></i> Yedekleme</h2>
            <?php if (!$yonetici): ?>
                <p class="aciklama">Yedekler kullanıcı parolalarının özetlerini de içerdiğinden yedek işlemleri yalnız yöneticiye açıktır.</p>
            <?php else: ?>
                <p class="aciklama" style="margin-bottom:14px">
                    <?php if ($bagimsiz): ?>
                        Yedek, veritabanının <b>tamamını</b> içerir (cari, stok, belgeler, kasa, çek, kullanıcılar ve ayarlar).
                    <?php else: ?>
                        Yedek, Lumen'in <b>kendi tablolarını</b> (kullanıcı yetkileri, görevler, kayıtlar, çek ekleri) içerir.
                        LOGO verisinin yedeğini LOGO'nun kendi yedekleme aracıyla almaya devam edin.
                    <?php endif; ?>
                    Ek program gerekmez. Yedekleri sunucu dışında da (USB disk, bulut klasörü) saklayın.
                </p>
                <?php if ($liste === []): ?>
                    <div class="mesaj uyari" style="margin-bottom:14px"><i class="fa-solid fa-triangle-exclamation"></i><span>Henüz yedek yok. Şimdi bir yedek alın.</span></div>
                <?php endif; ?>
                <div class="satir" style="margin-bottom:16px">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="islem_id" value="<?= bin2hex(random_bytes(8)) ?>"><input type="hidden" name="islem" value="yedek_al">
                        <button class="btn btn-red" type="submit" id="yedekAl"><i class="fa-solid fa-floppy-disk"></i> Şimdi yedek al</button></form>
                    <form method="post" enctype="multipart/form-data" class="satir"><?= csrf_field() ?><input type="hidden" name="islem_id" value="<?= bin2hex(random_bytes(8)) ?>"><input type="hidden" name="islem" value="yukle">
                        <input type="file" name="dosya" accept=".gz" required>
                        <button class="btn btn-hat" type="submit"><i class="fa-solid fa-upload"></i> Yedek dosyası yükle</button></form>
                </div>
                <div class="tablo-sar">
                    <table>
                        <thead><tr><th>Tarih</th><th>Tür</th><th class="sag">Boyut</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($liste as $y): ?>
                            <tr>
                                <td><?= $h(date('d.m.Y H:i', $y['tarih'])) ?><div class="soluk"><?= $h($y['ad']) ?></div></td>
                                <td><?= $h($turAd[$y['tur']] ?? $y['tur']) ?></td>
                                <td class="sag"><?= $h($boyut($y['boyut'])) ?></td>
                                <td class="sag" style="white-space:nowrap">
                                    <a class="btn btn-hat btn-kucuk" href="?indir=<?= $h(rawurlencode($y['ad'])) ?>"><i class="fa-solid fa-download"></i> İndir</a>
                                    <button type="button" class="btn btn-hat btn-kucuk geri-yukle" data-ad="<?= $h($y['ad']) ?>" data-tarih="<?= $h(date('d.m.Y H:i', $y['tarih'])) ?>"
                                        data-vt="<?= $h($y['veritabani']) ?>" data-farkli="<?= $y['veritabani'] !== $veritabani ? '1' : '0' ?>"><i class="fa-solid fa-clock-rotate-left"></i> Geri yükle</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($yonetici): ?>
        <section class="kart">
            <h2><i class="fa-solid fa-gear"></i> Yedekleme ayarları</h2>
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem_id" value="<?= bin2hex(random_bytes(8)) ?>"><input type="hidden" name="islem" value="ayar">
                <div style="display:grid; gap:14px">
                    <label class="satir" style="font-weight:600"><input type="checkbox" name="otomatik" <?= $ayar['otomatik'] ? 'checked' : '' ?>>
                        Her gün otomatik yedek al <span class="soluk">(günün ilk kullanımında, arka planda<?php
                        [$sonTarih, $sonDurum] = array_pad(explode(' ', trim($sonOto), 2), 2, '');
                        if ($sonTarih !== '' && strtotime($sonTarih)): ?> · son: <?= $h(date('d.m.Y', (int) strtotime($sonTarih))) ?><?php endif; ?>)</span>
                        <?php if ($sonDurum === 'hata'): ?><span class="rozet beta">son otomatik yedek başarısız — klasörü kontrol edin</span><?php endif; ?></label>
                    <p class="soluk">IIS ve Apache (mod_php) sunucularında yedeği tetikleyen sayfa yedek bitene kadar bekler; büyük veritabanında
                        bunun yerine Windows Görev Zamanlayıcı ile her gece <code>php scripts/yedek_al.php</code> çalıştırıp otomatik yedeği kapatın.</p>
                    <label class="alan">Yedek klasörü
                        <input type="text" name="klasor" value="<?= $h($ayar['klasor']) ?>" placeholder="<?= $h(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'yedekler') ?>">
                        <span class="soluk">Boş: uygulama klasöründe. Başka bir disk ya da ağ klasörü (ör. <code>D:\Yedekler\Lumen</code>) önerilir.</span></label>
                    <label class="alan" style="max-width:220px">Saklanacak yedek sayısı
                        <input type="number" name="saklama" min="3" max="365" value="<?= (int) $ayar['saklama'] ?>"></label>
                    <div><button class="btn btn-red" type="submit"><i class="fa-solid fa-check"></i> Kaydet</button></div>
                </div>
            </form>
        </section>

        <dialog id="geriYukleDlg">
            <form method="post">
                <?= csrf_field() ?><input type="hidden" name="islem_id" value="<?= bin2hex(random_bytes(8)) ?>"><input type="hidden" name="islem" value="geri_yukle"><input type="hidden" name="ad" id="gyAd">
                <h3>Yedeği geri yükle</h3>
                <p class="aciklama" style="margin-bottom:12px"><b id="gyTarih"></b> tarihli yedeğe dönülecek (veritabanı: <b id="gyVt"></b>).
                    Bu yedekten sonra girilen tüm kayıtlar kaybolur; parolalar ve yetkiler de yedek anındaki haline döner, herkes
                    yeniden giriş yapar. İşlemden önce mevcut durumun yedeği otomatik alınır.</p>
                <div class="mesaj error" id="gyFarkli" style="display:none; margin-bottom:12px"><i class="fa-solid fa-triangle-exclamation"></i>
                    <span>Bu yedek <b>başka bir veritabanından</b> alınmış. Yalnız ne yaptığınızdan eminseniz devam edin.</span></div>
                <label class="alan">Onaylamak için <b>GERİ YÜKLE</b> yazın <input type="text" name="onay" autocomplete="off" required></label>
                <div class="satir" style="justify-content:flex-end; margin-top:16px">
                    <button type="button" class="btn btn-hat" onclick="this.closest('dialog').close()">Vazgeç</button>
                    <button type="submit" class="btn btn-red"><i class="fa-solid fa-clock-rotate-left"></i> Geri yükle</button>
                </div>
            </form>
        </dialog>
        <script>
            document.querySelectorAll('.geri-yukle').forEach(function (b) {
                b.addEventListener('click', function () {
                    document.getElementById('gyAd').value = b.dataset.ad;
                    document.getElementById('gyTarih').textContent = b.dataset.tarih;
                    document.getElementById('gyVt').textContent = b.dataset.vt;
                    document.getElementById('gyFarkli').style.display = b.dataset.farkli === '1' ? '' : 'none';
                    document.getElementById('geriYukleDlg').showModal();
                });
            });
            document.querySelectorAll('form').forEach(function (f) {
                f.addEventListener('submit', function () {
                    var b = f.querySelector('button[type=submit]');
                    if (b) { b.disabled = true; b.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Lütfen bekleyin…'; }
                });
            });
        </script>
        <?php endif; ?>
    </main>
</body>
</html>

<?php

declare(strict_types=1);

/**
 * marka.php — Marka / Logo ayarları.
 * Yönetici kendi logosunu (yatay wordmark) ve uygulama ikonunu (kare) yükleyerek
 * Lumen placeholder'larını değiştirir. Yüklenen görsel GD ile PNG'ye yeniden
 * kodlanır (gömülü içerik temizlenir), sabit adla kaydedilir (logo.png / icon.png)
 * → tüm sayfalarda anında etkili olur. Admin-only + CSRF + tip/boyut doğrulama.
 */

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);

$kok = dirname(__DIR__);            // D:\Lumen (uygulama kökü)
$mesajlar = [];                    // [ [tip, metin], ... ]

/**
 * Yüklenen görseli doğrula, GD ile PNG'ye yeniden kodla, hedefe kaydet.
 * @return array{0:bool,1:string}
 */
function marka_gorsel_kaydet(array $dosya, string $hedefYol, bool $kare): array
{
    if (($dosya['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [false, 'Yükleme başarısız (hata kodu: ' . (int) ($dosya['error'] ?? -1) . ')'];
    }
    if (($dosya['size'] ?? 0) > 3 * 1024 * 1024) {
        return [false, 'Dosya 3 MB sınırını aşıyor'];
    }
    $veri = @file_get_contents($dosya['tmp_name']);
    if ($veri === false || $veri === '') {
        return [false, 'Dosya okunamadı'];
    }
    $bilgi = @getimagesizefromstring($veri);
    if ($bilgi === false) {
        return [false, 'Geçerli bir görsel değil'];
    }
    if (!in_array($bilgi[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
        return [false, 'Yalnızca PNG, JPG veya WEBP kabul edilir'];
    }
    $img = @imagecreatefromstring($veri);
    if (!$img) {
        return [false, 'Görsel işlenemedi'];
    }
    imagealphablending($img, false);
    imagesavealpha($img, true);

    if ($kare) {
        // Kare ikon: 512x512 şeffaf zemine orantılı yerleştir
        $w = imagesx($img); $h = imagesy($img); $S = 512;
        $tuval = imagecreatetruecolor($S, $S);
        imagealphablending($tuval, false);
        imagesavealpha($tuval, true);
        $seffaf = imagecolorallocatealpha($tuval, 0, 0, 0, 127);
        imagefilledrectangle($tuval, 0, 0, $S, $S, $seffaf);
        $olcek = min($S / $w, $S / $h);
        $nw = max(1, (int) round($w * $olcek));
        $nh = max(1, (int) round($h * $olcek));
        imagecopyresampled($tuval, $img, (int) (($S - $nw) / 2), (int) (($S - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $tuval;
    }

    if (is_file($hedefYol)) {
        @copy($hedefYol, $hedefYol . '.bak');   // eskiyi yedekle
    }
    $ok = @imagepng($img, $hedefYol, 9);
    imagedestroy($img);
    if (!$ok) {
        return [false, 'Kaydedilemedi — sunucunun klasöre yazma izni olmayabilir'];
    }
    return [true, 'Başarıyla güncellendi'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marka_yukle'])) {
    ayar_require_csrf();
    if (!extension_loaded('gd')) {
        $mesajlar[] = ['error', 'PHP GD eklentisi etkin değil; görsel işlenemiyor.'];
    } else {
        if (!empty($_FILES['logo']['tmp_name'])) {
            [$ok, $m] = marka_gorsel_kaydet($_FILES['logo'], $kok . '/logo.png', false);
            $mesajlar[] = [$ok ? 'ok' : 'error', 'Logo: ' . $m];
        }
        if (!empty($_FILES['ikon']['tmp_name'])) {
            [$ok, $m] = marka_gorsel_kaydet($_FILES['ikon'], $kok . '/icon.png', true);
            if ($ok) {
                @copy($kok . '/icon.png', $kok . '/favicon.ico');   // favicon'u da güncelle
            }
            $mesajlar[] = [$ok ? 'ok' : 'error', 'Uygulama ikonu: ' . $m];
        }
        if (empty($_FILES['logo']['tmp_name']) && empty($_FILES['ikon']['tmp_name'])) {
            $mesajlar[] = ['error', 'Dosya seçilmedi.'];
        }
    }
    csrf_regenerate();
}

$v = time(); // önizleme cache-bust
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marka / Logo - Lumen</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; background:var(--bg); color:var(--t1); font-size:14px; }
        .top { position:sticky; top:0; z-index:20; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
        .top-in { max-width:820px; margin:0 auto; padding:12px 16px; display:flex; align-items:center; gap:12px; }
        .top a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
        .top .baslik { font-size:15px; font-weight:700; flex:1; display:flex; align-items:center; gap:8px; }
        .top .baslik i { color:var(--red); }
        main { max-width:820px; margin:0 auto; padding:20px 16px 60px; }
        .kart { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:20px; margin-bottom:18px; }
        .kart h2 { font-size:15px; font-weight:700; margin-bottom:4px; display:flex; align-items:center; gap:8px; }
        .kart h2 i { color:var(--red); }
        .kart .aciklama { font-size:12.5px; color:var(--t2); margin-bottom:16px; line-height:1.5; }
        .onizleme { display:flex; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:16px; }
        .onizleme .kutu { border:1px dashed var(--border); border-radius:12px; padding:14px; background:#fafafa; text-align:center; }
        .onizleme .kutu img.logo { height:46px; width:auto; max-width:280px; display:block; }
        .onizleme .kutu img.ikon { width:72px; height:72px; border-radius:16px; display:block; }
        .onizleme .kutu span { display:block; font-size:11px; color:var(--t3); margin-top:8px; text-transform:uppercase; letter-spacing:.4px; }
        .alan { margin-bottom:14px; }
        .alan label { display:block; font-size:12px; font-weight:600; color:var(--t2); margin-bottom:6px; }
        input[type=file] { width:100%; font-size:13px; padding:10px; border:1px solid var(--border); border-radius:10px; background:#fff; }
        input[type=file]::file-selector-button { background:var(--red-soft); color:var(--red); border:none; padding:8px 14px; border-radius:8px; font-weight:600; margin-right:12px; cursor:pointer; font-family:inherit; }
        .btn { background:var(--red); color:#fff; border:none; padding:12px 22px; border-radius:10px; font-weight:700; font-size:14px; cursor:pointer; display:inline-flex; align-items:center; gap:8px; font-family:inherit; }
        .btn:hover { filter:brightness(1.08); }
        .ipuc { font-size:12px; color:var(--t3); margin-top:10px; line-height:1.5; }
        .mesaj { padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:13px; font-weight:500; display:flex; align-items:center; gap:9px; }
        .mesaj.ok { background:var(--emerald-soft); color:#065f46; border:1px solid #a7f3d0; }
        .mesaj.error { background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="index.php" class="geri" title="Ayarlara dön"><i class="fa fa-arrow-left"></i></a>
            <span class="baslik"><i class="fa-solid fa-palette"></i> Marka / Logo</span>
        </div>
    </header>
    <main>
        <?php foreach ($mesajlar as [$tip, $metin]): ?>
            <div class="mesaj <?php echo $tip === 'ok' ? 'ok' : 'error'; ?>">
                <i class="fa-solid <?php echo $tip === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?php echo htmlspecialchars($metin, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endforeach; ?>

        <form method="POST" enctype="multipart/form-data">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="marka_yukle" value="1">

            <div class="kart">
                <h2><i class="fa-solid fa-image"></i> Logo (yatay)</h2>
                <p class="aciklama">Giriş ekranı ve üst menüde görünen yatay logonuz. Şeffaf zeminli PNG önerilir.</p>
                <div class="onizleme">
                    <div class="kutu">
                        <img class="logo" src="../logo.png?v=<?php echo $v; ?>" alt="Mevcut logo" onerror="this.style.display='none'">
                        <span>Mevcut</span>
                    </div>
                </div>
                <div class="alan">
                    <label for="logo">Yeni logo seç (PNG / JPG / WEBP, en fazla 3 MB)</label>
                    <input type="file" id="logo" name="logo" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>

            <div class="kart">
                <h2><i class="fa-solid fa-mobile-screen"></i> Uygulama ikonu (kare)</h2>
                <p class="aciklama">Tarayıcı sekmesi, favori ve telefona eklenen kısayol ikonu. Kare görsel 512×512'ye ölçeklenir.</p>
                <div class="onizleme">
                    <div class="kutu">
                        <img class="ikon" src="../icon.png?v=<?php echo $v; ?>" alt="Mevcut ikon" onerror="this.style.display='none'">
                        <span>Mevcut</span>
                    </div>
                </div>
                <div class="alan">
                    <label for="ikon">Yeni ikon seç (kare PNG önerilir, en fazla 3 MB)</label>
                    <input type="file" id="ikon" name="ikon" accept="image/png,image/jpeg,image/webp">
                </div>
            </div>

            <button type="submit" class="btn"><i class="fa-solid fa-upload"></i> Kaydet</button>
            <p class="ipuc">İpucu: Yalnızca değiştirmek istediğiniz alan için dosya seçmeniz yeterli. Eski görseller <code>.bak</code> uzantısıyla yedeklenir. Değişiklik anında tüm sayfalara yansır (tarayıcı önbelleği için sayfayı yenileyin).</p>
        </form>
    </main>
</body>
</html>

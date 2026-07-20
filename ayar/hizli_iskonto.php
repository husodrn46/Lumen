<?php

declare(strict_types=1);

/**
 * hizli_iskonto.php — Sipariş ekranı hızlı iskonto kısayollarını yönet.
 * Her kısayol: etiket + yüzde. Değerler _bilgi_.inc içindeki $hizli_iskontolar
 * dizisine yazılır (tek satır; regex ile güncellenir). Admin-only + CSRF.
 */

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);

$bilgi_file = __DIR__ . '/../_bilgi_.inc';
$mesaj = '';
$mesajTip = '';

/** Etiketten tehlikeli karakterleri temizle (tırnak/köşeli parantez/; olmaz). */
function hi_temiz_ad(string $ad): string
{
    $ad = trim($ad);
    $ad = preg_replace('/[^\p{L}\p{N} .\-\/]/u', '', $ad) ?? '';
    return mb_substr(trim($ad), 0, 24);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kaydet'])) {
    ayar_require_csrf();

    $adlar   = (array) ($_POST['ad'] ?? []);
    $oranlar = (array) ($_POST['oran'] ?? []);
    $yeni = [];
    foreach ($adlar as $i => $ad) {
        $ad = hi_temiz_ad((string) $ad);
        $oran = (float) str_replace(',', '.', (string) ($oranlar[$i] ?? ''));
        if ($ad !== '' && $oran > 0 && $oran <= 100) {
            $yeni[] = ['ad' => $ad, 'oran' => $oran];
        }
    }

    // Array literalini kur (tek satır, güvenli — ad temizlendi, oran sayı)
    $parcalar = [];
    foreach ($yeni as $r) {
        $oranYaz = rtrim(rtrim(number_format($r['oran'], 2, '.', ''), '0'), '.');
        $parcalar[] = "['ad' => '" . $r['ad'] . "', 'oran' => " . $oranYaz . "]";
    }
    $literal = '$hizli_iskontolar = [' . implode(', ', $parcalar) . '];';

    $icerik = (string) file_get_contents($bilgi_file);
    if (preg_match('/\$hizli_iskontolar\s*=\s*\[.*?\];/s', $icerik)) {
        $icerik = preg_replace('/\$hizli_iskontolar\s*=\s*\[.*?\];/s', $literal, $icerik, 1);
    } else {
        // değişken yoksa dosya sonuna ekle
        $icerik = rtrim($icerik) . "\n" . $literal . "\n";
    }

    if (file_put_contents($bilgi_file, $icerik) !== false) {
        $mesaj = 'Hızlı iskonto kısayolları kaydedildi.';
        $mesajTip = 'ok';
        $hizli_iskontolar = $yeni; // güncel değerleri göster
    } else {
        $mesaj = 'Kaydedilemedi — _bilgi_.inc yazma izinlerini kontrol edin.';
        $mesajTip = 'error';
    }
}

$mevcut = (isset($hizli_iskontolar) && is_array($hizli_iskontolar)) ? $hizli_iskontolar : [];
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hızlı İskonto - Lumen</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; background:var(--bg); color:var(--t1); font-size:14px; }
        .top { position:sticky; top:0; z-index:20; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
        .top-in { max-width:720px; margin:0 auto; padding:12px 16px; display:flex; align-items:center; gap:12px; }
        .top a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
        .top .baslik { font-size:15px; font-weight:700; flex:1; display:flex; align-items:center; gap:8px; }
        .top .baslik i { color:var(--red); }
        main { max-width:720px; margin:0 auto; padding:20px 16px 60px; }
        .kart { background:var(--card); border:1px solid var(--border); border-radius:14px; padding:20px; }
        .aciklama { font-size:13px; color:var(--t2); line-height:1.5; margin-bottom:18px; }
        .satir { display:flex; gap:10px; align-items:center; margin-bottom:10px; }
        .satir input { padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; outline:none; }
        .satir input:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
        .satir .ad { flex:1; }
        .satir .oran { width:90px; }
        .satir .yuzde { color:var(--t3); font-size:13px; }
        .satir .sil { width:36px; height:36px; flex-shrink:0; border:1px solid var(--border); background:#fff; color:var(--t3); border-radius:9px; cursor:pointer; }
        .satir .sil:hover { border-color:var(--red); color:var(--red); background:var(--red-soft); }
        .bos { text-align:center; color:var(--t3); padding:16px; font-size:13px; }
        .btn { display:inline-flex; align-items:center; gap:7px; padding:11px 16px; border-radius:10px; font-weight:600; font-size:13.5px; cursor:pointer; border:1px solid transparent; font-family:inherit; text-decoration:none; }
        .btn-hat { background:#fff; color:var(--t2); border:1px solid var(--border); } .btn-hat:hover { border-color:var(--red); color:var(--red); }
        .btn-red { background:var(--red); color:#fff; border:none; } .btn-red:hover { filter:brightness(1.08); }
        .alt-bar { display:flex; gap:10px; align-items:center; margin-top:16px; padding-top:16px; border-top:1px solid var(--border); }
        .alt-bar .btn-red { margin-left:auto; }
        .mesaj { padding:12px 14px; border-radius:10px; margin-bottom:16px; font-size:13px; display:flex; gap:9px; align-items:center; }
        .mesaj.ok { background:var(--emerald-soft); color:#065f46; border:1px solid #a7f3d0; }
        .mesaj.error { background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }
        .onizleme { margin-top:16px; display:flex; gap:8px; flex-wrap:wrap; }
        .onizleme .oy { background:var(--red-soft); color:var(--red); border:1px solid #fde0e5; padding:7px 13px; border-radius:9px; font-size:12.5px; font-weight:600; display:inline-flex; align-items:center; gap:6px; }
        .onizleme .baslik-k { width:100%; font-size:11.5px; text-transform:uppercase; letter-spacing:.4px; color:var(--t3); font-weight:600; margin-bottom:2px; }
    </style>
</head>
<body>
    <header class="top">
        <div class="top-in">
            <a href="index.php" class="geri" title="Ayarlara dön"><i class="fa fa-arrow-left"></i></a>
            <span class="baslik"><i class="fa-solid fa-percent"></i> Hızlı İskonto Kısayolları</span>
        </div>
    </header>
    <main>
        <?php if ($mesaj !== ''): ?>
            <div class="mesaj <?php echo $mesajTip === 'ok' ? 'ok' : 'error'; ?>">
                <i class="fa-solid <?php echo $mesajTip === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
                <span><?php echo $h($mesaj); ?></span>
            </div>
        <?php endif; ?>

        <div class="kart">
            <p class="aciklama">Sipariş ekranında tek tıkla iskonto uygulayan kısayollar. Örn: <b>Nakit %10</b>, <b>Kart %5</b>. Etiket ve yüzdeyi kendinize göre belirleyin. Boş bırakırsanız kısayol görünmez (elle iskonto her zaman kullanılabilir).</p>

            <form method="POST" id="hiForm">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="kaydet" value="1">

                <div id="satirlar">
                    <?php if ($mevcut): foreach ($mevcut as $r):
                        $oranYaz = rtrim(rtrim(number_format((float) ($r['oran'] ?? 0), 2, '.', ''), '0'), '.'); ?>
                    <div class="satir">
                        <input class="ad" type="text" name="ad[]" value="<?php echo $h($r['ad'] ?? ''); ?>" placeholder="Etiket (örn: Nakit)" maxlength="24">
                        <input class="oran" type="number" name="oran[]" value="<?php echo $h($oranYaz); ?>" placeholder="Oran" min="0" max="100" step="0.5">
                        <span class="yuzde">%</span>
                        <button type="button" class="sil" title="Sil" onclick="this.closest('.satir').remove()"><i class="fa-solid fa-trash"></i></button>
                    </div>
                    <?php endforeach; endif; ?>
                </div>

                <div class="alt-bar">
                    <button type="button" class="btn btn-hat" id="ekleBtn"><i class="fa-solid fa-plus"></i> Kısayol Ekle</button>
                    <button type="submit" class="btn btn-red"><i class="fa-solid fa-floppy-disk"></i> Kaydet</button>
                </div>
            </form>

            <?php if ($mevcut): ?>
            <div class="onizleme">
                <span class="baslik-k">Sipariş ekranında görünüm</span>
                <?php foreach ($mevcut as $r): $oy = rtrim(rtrim(number_format((float)($r['oran']??0),2,'.',''),'0'),'.'); ?>
                    <span class="oy"><i class="fa-solid fa-percent"></i> <?php echo $h($r['ad']); ?> %<?php echo $h($oy); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <template id="satirSablon">
        <div class="satir">
            <input class="ad" type="text" name="ad[]" placeholder="Etiket (örn: Nakit)" maxlength="24">
            <input class="oran" type="number" name="oran[]" placeholder="Oran" min="0" max="100" step="0.5">
            <span class="yuzde">%</span>
            <button type="button" class="sil" title="Sil" onclick="this.closest('.satir').remove()"><i class="fa-solid fa-trash"></i></button>
        </div>
    </template>
    <script>
        document.getElementById('ekleBtn').addEventListener('click', function(){
            var t = document.getElementById('satirSablon').content.cloneNode(true);
            document.getElementById('satirlar').appendChild(t);
        });
    </script>
</body>
</html>

<?php
declare(strict_types=1);
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../includes/eticaret/ice_aktar.php';
if (isset($_GET['ornek']) && in_array($_GET['ornek'],['csv','json'],true)) {
    $tur = $_GET['ornek']; header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="lumen-sentetik-siparis.' . $tur . '"');
    readfile(__DIR__ . '/../docs/eticaret/ornek.' . $tur); exit;
}
$hata=''; $sonuc=null; $onizleme=null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        et_post();
        if (($_POST['islem'] ?? '') === 'yukle') {
            $f = $_FILES['dosya'] ?? [];
            if (($f['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || ($f['size'] ?? 0) > 1048576) {
                throw new DomainException('En fazla 1 MB büyüklüğünde dosya seçin.');
            }
            $tur = strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
            $ham = file_get_contents($f['tmp_name']);
            $onizleme = et_dosya_oku($ham,$tur);
            $k = et_kanal($dbh,(int) ($_POST['kanal'] ?? 0));
            if (!$k['AKTIF']) { throw new DomainException('Aktif kanal seçin.'); }
            $_SESSION['et_onizleme'] = ['kanal'=>(int) $k['ID'],'ham'=>$ham,'tur'=>$tur,'ad'=>$f['name'],'anahtar'=>bin2hex(random_bytes(16))];
        } elseif (($_POST['islem'] ?? '') === 'kaydet') {
            $v = $_SESSION['et_onizleme'] ?? null;
            if (!$v || !hash_equals($v['anahtar'],(string) ($_POST['anahtar'] ?? ''))) { throw new DomainException('Önizleme süresi doldu; dosyayı yeniden seçin.'); }
            $sonuc = et_ice_aktar($dbh,$v['kanal'],$v['ham'],$v['tur'],$v['ad'],(int) $terminalkullanici);
            unset($_SESSION['et_onizleme']);
        }
    }
} catch (Throwable $e) { $hata=et_hata($e); }
et_basla('CSV / JSON içe aktar');
if ($hata) { echo '<p class="bildirim hata">'.bh($hata).'</p>'; }
?>
<div class="kart kart-ic"><p>Dosya yalnız sipariş kutusuna alınır. Satış siparişi, eşleme ve kontrollerden sonra ayrıca onaylanır.</p>
<a href="?ornek=csv">Sentetik CSV örneği</a> · <a href="?ornek=json">Sentetik JSON örneği</a></div>
<form method="post" enctype="multipart/form-data" class="kart kart-ic izgara">
<?= csrf_field() ?><input type="hidden" name="islem" value="yukle">
<label class="alan">Kanal <select name="kanal" required><?php foreach (et_kanallar($dbh) as $k): if (!$k['AKTIF']) { continue; } ?><option value="<?= (int) $k['ID'] ?>"><?= bh($k['AD']) ?></option><?php endforeach; ?></select></label>
<label class="alan">UTF-8 dosya (en fazla 1 MB)<input type="file" name="dosya" accept=".csv,.json" required></label><button class="btn btn-birincil">Önizle</button>
</form>
<?php if ($onizleme): ?>
<div class="kart kart-ic"><h2>Önizleme</h2><ul><?php foreach ($onizleme as $r): ?><li><?= bh(is_array($r['ham']) && is_scalar($r['ham']['numara'] ?? null) ? $r['ham']['numara'] : 'Numarasız/geçersiz kayıt') ?> — <?= bh($r['hata'] ?? 'Kutuya alınabilir') ?></li><?php endforeach; ?></ul>
<form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="anahtar" value="<?= bh($_SESSION['et_onizleme']['anahtar']) ?>"><button class="btn btn-birincil">Sipariş kutusuna al</button></form></div>
<?php endif; if ($sonuc): ?><div class="bildirim ok">Yeni: <?= (int) $sonuc['yeni'] ?> · Mükerrer: <?= (int) $sonuc['mukerrer'] ?> · Değişmiş içerik: <?= (int) $sonuc['cakisma'] ?> · Hatalı: <?= (int) $sonuc['hatali'] ?>. <a href="index.php">Kutuyu aç</a></div><?php endif; belge_sayfa_bitir();

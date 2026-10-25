<?php
declare(strict_types=1);
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../includes/eticaret/ice_aktar.php';
if (isset($_GET['ornek']) && in_array($_GET['ornek'],['csv','json','trendyol_v2','hepsiburada_paket'],true)) {
    $ornek = $_GET['ornek']; $tur=in_array($ornek,['csv','json'],true) ? $ornek : 'json';
    $dosya=in_array($ornek,['csv','json'],true) ? 'ornek.'.$ornek : $ornek.'.json'; header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="lumen-sentetik-' . $ornek . '.' . $tur . '"');
    readfile(__DIR__ . '/../docs/eticaret/' . $dosya); exit;
}
$hata=''; $sonuc=null; $onizleme=null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        et_post();
        if (($_POST['islem'] ?? '') === 'yukle') {
            unset($_SESSION['et_onizleme']);
            $f = $_FILES['dosya'] ?? [];
            if (($f['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || ($f['size'] ?? 0) > 1048576) {
                throw new DomainException('En fazla 1 MB büyüklüğünde dosya seçin.');
            }
            $tur = strtolower(pathinfo($f['name'],PATHINFO_EXTENSION));
            $ham = file_get_contents($f['tmp_name']);
            $bicim=(string) ($_POST['bicim'] ?? 'lumen');
            $adayOnizleme = et_dosya_oku($ham,$tur,$bicim);
            $k = et_kanal($dbh,(int) ($_POST['kanal'] ?? 0));
            if (et_bicimler()[$bicim]['kanal']!==null && et_bicimler()[$bicim]['kanal']!==$k['TUR']) { throw new DomainException('Dosya biçimi kanal türüyle uyuşmuyor.'); }
            if (!$k['AKTIF']) { throw new DomainException('Aktif kanal seçin.'); }
            $onizleme=$adayOnizleme;
            $_SESSION['et_onizleme'] = ['kanal'=>(int) $k['ID'],'ham'=>$ham,'tur'=>$tur,'ad'=>$f['name'],'bicim'=>$bicim,'anahtar'=>bin2hex(random_bytes(16))];
        } elseif (($_POST['islem'] ?? '') === 'kaydet') {
            $v = $_SESSION['et_onizleme'] ?? null;
            if (!$v || !hash_equals($v['anahtar'],(string) ($_POST['anahtar'] ?? ''))) { throw new DomainException('Önizleme süresi doldu; dosyayı yeniden seçin.'); }
            $sonuc = et_ice_aktar($dbh,$v['kanal'],$v['ham'],$v['tur'],$v['ad'],(int) $terminalkullanici,$v['bicim'] ?? 'lumen');
            unset($_SESSION['et_onizleme']);
        }
    }
} catch (Throwable $e) { $hata=et_hata($e); }
et_basla('Sipariş dosyası içe aktar');
if ($hata) { echo '<p class="bildirim hata">'.bh($hata).'</p>'; }
?>
<div class="kart kart-ic"><p>Dosya yalnız sipariş kutusuna alınır. Satış siparişi, eşleme ve kontrollerden sonra ayrıca onaylanır.</p>
<a href="?ornek=csv">Genel CSV</a> · <a href="?ornek=json">Genel JSON</a> · <a href="?ornek=trendyol_v2">Sentetik Trendyol API yanıtı</a> · <a href="?ornek=hepsiburada_paket">Sentetik Hepsiburada API yanıtı</a></div>
<form method="post" enctype="multipart/form-data" class="kart kart-ic izgara">
<?= csrf_field() ?><input type="hidden" name="islem" value="yukle">
<label class="alan">Kanal <select name="kanal" required><?php foreach (et_kanallar($dbh) as $k): if (!$k['AKTIF']) { continue; } ?><option value="<?= (int) $k['ID'] ?>"><?= bh($k['AD']) ?></option><?php endforeach; ?></select></label>
<label class="alan">Dosya biçimi <select name="bicim"><?php foreach (et_bicimler() as $kod=>$b): ?><option value="<?= bh($kod) ?>"><?= bh($b['ad']) ?></option><?php endforeach; ?></select></label>
<label class="alan">UTF-8 dosya (en fazla 1 MB)<input type="file" name="dosya" accept=".csv,.json" required></label><button class="btn btn-birincil">Önizle</button>
</form>
<?php if (is_array($onizleme)): ?>
<div class="kart kart-ic"><h2>Önizleme</h2><?php if (!$onizleme): ?><p>API yanıtında paket yok; boş dosya kaydı saklanabilir.</p><?php endif; ?><ul><?php foreach ($onizleme as $r): ?><li><?= bh($r['numara'] ?: 'Numarasız/geçersiz kayıt') ?><?php if ($r['paket_kimligi']): ?> · Paket <?= bh($r['paket_kimligi']) ?><?php endif; ?> — <?= bh($r['hata'] ?? $r['kaynak_engeli'] ?? 'Kutuya alınabilir') ?></li><?php endforeach; ?></ul>
<form method="post"><?= csrf_field() ?><input type="hidden" name="islem" value="kaydet"><input type="hidden" name="anahtar" value="<?= bh($_SESSION['et_onizleme']['anahtar']) ?>"><button class="btn btn-birincil">Sipariş kutusuna al</button></form></div>
<?php endif; if ($sonuc): ?><div class="bildirim ok">Yeni: <?= (int) $sonuc['yeni'] ?> · Mükerrer: <?= (int) $sonuc['mukerrer'] ?> · Değişmiş içerik: <?= (int) $sonuc['cakisma'] ?> · Hatalı: <?= (int) $sonuc['hatali'] ?> · Eski kaynak: <?= (int) $sonuc['eski'] ?>. <a href="index.php">Kutuyu aç</a></div><?php endif; belge_sayfa_bitir();

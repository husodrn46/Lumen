<?php

declare(strict_types=1);

/**
 * Excel'den cari / stok kartı aktarma (yalnız bağımsız mod, M16).
 * Şablon indir → yükle → önizleme (satır satır hata) → onayla → tek işlemde yaz.
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/aktarim/excel_aktar.php';

$klasor = dirname(__DIR__) . '/database/ice_aktar';
if (!is_dir($klasor)) {
    @mkdir($klasor, 0750, true);
}
$turAdi = ['cari' => 'Cari kartları', 'stok' => 'Stok kartları'];
// Onaylanmadan bırakılmış (oturumu kapanmış, tarayıcısı kapanmış) yüklemeler bir saat sonra silinir:
// cari ve iletişim bilgileri sunucuda birikmesin.
foreach (glob($klasor . '/*.{xlsx,xls,csv}', GLOB_BRACE) ?: [] as $eski) {
    if (filemtime($eski) < time() - 3600) {
        @unlink($eski);
    }
}

if (isset($_GET['sablon']) && isset($turAdi[(string) $_GET['sablon']])) {
    $tur = (string) $_GET['sablon'];
    $gecici = tempnam(sys_get_temp_dir(), 'lsablon');
    lumen_excel_sablon($tur, $gecici);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="lumen_' . $tur . '_sablonu.xlsx"');
    header('Content-Length: ' . filesize($gecici));
    readfile($gecici);
    @unlink($gecici);
    exit;
}

$onizleme = null;
$hata = '';
$sonuc = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        lumen_hata_sayfasi('Geçersiz istek', 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 403);
    }
    @set_time_limit(300);
    $islem = (string) ($_POST['islem'] ?? '');
    if ($islem === 'yukle') {
        $tur = (string) ($_POST['tur'] ?? '');
        $f = $_FILES['dosya'] ?? null;
        $uzanti = strtolower(pathinfo((string) ($f['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset($turAdi[$tur])) {
            $hata = 'Geçersiz tür.';
        } elseif (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $hata = 'Dosya yüklenemedi (boyut sınırı: ' . ini_get('upload_max_filesize') . ').';
        } elseif (!in_array($uzanti, ['xlsx', 'xls', 'csv'], true)) {
            $hata = 'Yalnız .xlsx, .xls ya da .csv dosyası yükleyin.';
        } else {
            // Önceki onaylanmamış yükleme varsa silinir (yeni yükleme onun yerini alır).
            if (is_array($_SESSION['ice_aktar'] ?? null)) {
                @unlink("{$klasor}/{$_SESSION['ice_aktar']['anahtar']}.{$_SESSION['ice_aktar']['uzanti']}");
                unset($_SESSION['ice_aktar']);
            }
            $anahtar = bin2hex(random_bytes(12));
            $yol = "{$klasor}/{$anahtar}.{$uzanti}";
            if (!move_uploaded_file((string) $f['tmp_name'], $yol)) {
                $hata = 'Dosya kaydedilemedi.';
            } else {
                $_SESSION['ice_aktar'] = ['anahtar' => $anahtar, 'uzanti' => $uzanti, 'tur' => $tur, 'ad' => (string) $f['name'],
                    'guncelle' => isset($_POST['guncelle']), 'tarih' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['tarih'] ?? '')) ? (string) $_POST['tarih'] : date('Y-m-d')];
            }
        }
    } elseif ($islem === 'onayla' || $islem === 'vazgec') {
        $o = $_SESSION['ice_aktar'] ?? null;
        if (!is_array($o) || !hash_equals((string) $o['anahtar'], (string) ($_POST['anahtar'] ?? ''))) {
            $hata = 'Önizleme süresi doldu; dosyayı yeniden yükleyin.';
        } else {
            $yol = "{$klasor}/{$o['anahtar']}.{$o['uzanti']}";
            if ($islem === 'onayla') {
                try {
                    $okunan = lumen_excel_oku($yol, $o['tur'], $o['ad']);
                    $d = lumen_excel_dogrula($dbh, $o['tur'], $okunan['satirlar'], (bool) $o['guncelle']);
                    $sonuc = ($d['hatalar'] !== [] && !isset($_POST['hatalari_atla']))
                        ? ['ok' => false, 'mesaj' => 'Hatalı satırlar var; düzeltip yeniden yükleyin ya da "hatalı satırları atla" seçin.']
                        : lumen_excel_yaz($dbh, $o['tur'], $d, $o['tarih']);
                } catch (Throwable $e) {
                    error_log('ice_aktar: ' . $e->getMessage());
                    $sonuc = ['ok' => false, 'mesaj' => 'Dosya okunamadı.'];
                }
            }
            @unlink($yol);
            unset($_SESSION['ice_aktar']);
        }
    }
    if (function_exists('app_log_write')) {
        app_log_write('ayar-degisiklik', ['ekran' => 'ice_aktar', 'islem' => $islem, 'personel' => (int) $terminalkullanici, 'sonuc' => $sonuc['ok'] ?? null]);
    }
}

// Bekleyen önizleme (yüklemeden hemen sonra)
$o = $_SESSION['ice_aktar'] ?? null;
if (is_array($o) && $sonuc === null && $hata === '') {
    // Okuma beklenmedik biçimde çökerse (bozuk dosya) önizleme her açılışta tekrar çökmesin:
    // deneme sayısı oturuma hemen yazılır, iki başarısız denemeden sonra yükleme bırakılır.
    $_SESSION['ice_aktar']['deneme'] = (int) ($o['deneme'] ?? 0) + 1;
    if ($_SESSION['ice_aktar']['deneme'] > 2) {
        @unlink("{$klasor}/{$o['anahtar']}.{$o['uzanti']}");
        unset($_SESSION['ice_aktar']);
        $o = null;
        $hata = 'Dosya okunamadı; şablondaki kolonları kullanarak yeniden kaydedin.';
    }
    session_write_close();
    @session_start();
}
if (is_array($o) && $sonuc === null && $hata === '') {
    try {
        $okunan = lumen_excel_oku("{$klasor}/{$o['anahtar']}.{$o['uzanti']}", $o['tur'], $o['ad']);
        $onizleme = ['o' => $o, 'eksik' => $okunan['eksik'], 'toplam' => count($okunan['satirlar'])]
            + lumen_excel_dogrula($dbh, $o['tur'], $okunan['satirlar'], (bool) $o['guncelle']);
    } catch (Throwable $e) {
        $hata = $e instanceof RuntimeException ? $e->getMessage() : 'Dosya okunamadı; Excel (.xlsx) ya da CSV olduğundan emin olun.';
        @unlink("{$klasor}/{$o['anahtar']}.{$o['uzanti']}");
        unset($_SESSION['ice_aktar']);
    }
}

belge_sayfa_basla('Excel\'den aktar', 'fa-file-excel', 'index.php');
?>
<style>
    .sekmeler { display: flex; gap: 4px; overflow-x: auto; margin-bottom: 16px; border-bottom: 1px solid var(--border); }
    .sekmeler a { padding: 10px 14px; font-weight: 600; font-size: 13.5px; color: var(--text-2); text-decoration: none; border-bottom: 2.5px solid transparent; margin-bottom: -1px; white-space: nowrap; display: inline-flex; gap: 7px; align-items: center; }
    .sekmeler a.aktif { color: var(--red); border-bottom-color: var(--red); }
    .ikili { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr)); gap: 16px; }
    .ipucu { font-size: 12.5px; color: var(--text-2); line-height: 1.55; }
    .sayilar { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin: 12px 0; }
    .sayilar div { background: #fafafa; border: 1px solid var(--border); border-radius: 10px; padding: 10px 12px; }
    .sayilar b { display: block; font-size: 20px; }
</style>
<nav class="sekmeler">
    <?php foreach (tanim_sekmeleri() as $k => [$ad, $ikon]): ?>
        <a href="index.php?sekme=<?= bh($k) ?>"><i class="fa-solid <?= bh($ikon) ?>"></i><?= bh($ad) ?></a>
    <?php endforeach; ?>
    <a href="ice_aktar.php" class="aktif"><i class="fa-solid fa-file-excel"></i>Excel'den aktar</a>
</nav>
<?php if ($hata !== ''): ?><div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation"></i><?= bh($hata) ?></div><?php endif; ?>
<?php if ($sonuc !== null): ?><div class="bildirim <?= $sonuc['ok'] ? 'ok' : 'hata' ?>" role="status"><i class="fa-solid <?= $sonuc['ok'] ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i><?= bh($sonuc['mesaj']) ?></div><?php endif; ?>

<?php if ($onizleme !== null):
    $o = $onizleme['o']; ?>
    <div class="kart kart-ic" id="onizleme">
        <div class="kart-baslik" style="padding:0 0 8px"><i class="fa-solid fa-eye"></i>Önizleme — <?= bh($turAdi[$o['tur']]) ?> · <?= bh($o['ad']) ?></div>
        <?php if ($onizleme['eksik'] !== []): ?>
            <div class="bildirim hata"><i class="fa-solid fa-circle-exclamation"></i>Zorunlu kolon bulunamadı: <?= bh(implode(', ', $onizleme['eksik'])) ?>. Şablonun başlık satırını kullanın.</div>
        <?php endif; ?>
        <div class="sayilar">
            <div><span class="soluk">Dosyadaki satır</span><b><?= (int) $onizleme['toplam'] ?></b></div>
            <div><span class="soluk">Eklenecek</span><b style="color:var(--emerald)"><?= count($onizleme['yeni']) ?></b></div>
            <div><span class="soluk"><?= $o['guncelle'] ? 'Güncellenecek' : 'Zaten var (atlanır)' ?></span>
                <b><?= $o['guncelle'] ? count($onizleme['guncel']) : (int) $onizleme['toplam'] - count($onizleme['yeni']) - count($onizleme['hatalar']) ?></b></div>
            <div><span class="soluk">Hatalı</span><b style="color:<?= $onizleme['hatalar'] ? 'var(--red)' : 'inherit' ?>"><?= count($onizleme['hatalar']) ?></b></div>
        </div>
        <?php if ($onizleme['hatalar'] !== []): ?>
            <div class="tablo-sar" style="max-height:280px; overflow:auto; border:1px solid var(--border); border-radius:10px; margin-bottom:12px">
                <table class="liste"><thead><tr><th style="width:90px">Satır</th><th>Sorun</th></tr></thead><tbody>
                <?php foreach (array_slice($onizleme['hatalar'], 0, 300) as [$no, $m]): ?><tr><td><?= (int) $no ?></td><td><?= bh($m) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
        <?php endif; ?>
        <?php if ($onizleme['yeni'] !== []): ?>
            <p class="ipucu">İlk kayıtlar: <?= bh(implode(', ', array_map(static fn($k) => $k['kod'] . ' ' . ($k['unvan'] ?? $k['ad']), array_slice($onizleme['yeni'], 0, 5)))) ?><?= count($onizleme['yeni']) > 5 ? '…' : '' ?></p>
        <?php endif; ?>
        <form method="post" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-top:12px">
            <?= csrf_field() ?><input type="hidden" name="anahtar" value="<?= bh($o['anahtar']) ?>">
            <?php if ($onizleme['hatalar'] !== []): ?>
                <label style="display:flex; gap:6px; align-items:center; font-weight:600"><input type="checkbox" name="hatalari_atla" style="width:auto"> Hatalı satırları atla</label>
            <?php endif; ?>
            <button class="btn btn-birincil" type="submit" name="islem" value="onayla" <?= ($onizleme['eksik'] !== [] || ($onizleme['yeni'] === [] && $onizleme['guncel'] === [])) ? 'disabled' : '' ?>>
                <i class="fa-solid fa-check"></i> Aktar</button>
            <button class="btn btn-ikincil" type="submit" name="islem" value="vazgec" formnovalidate>Vazgeç</button>
            <span class="ipucu">Açılış tarihi: <?= bh(date('d.m.Y', (int) strtotime($o['tarih']))) ?> · tek işlemde yazılır; hata olursa hiçbir şey eklenmez.</span>
        </form>
    </div>
<?php endif; ?>

<div class="ikili">
    <?php foreach ($turAdi as $tur => $ad): ?>
        <form method="post" enctype="multipart/form-data" class="kart kart-ic">
            <?= csrf_field() ?><input type="hidden" name="islem" value="yukle"><input type="hidden" name="tur" value="<?= bh($tur) ?>">
            <div class="kart-baslik" style="padding:0 0 10px"><i class="fa-solid <?= $tur === 'cari' ? 'fa-address-book' : 'fa-boxes-stacked' ?>"></i><?= bh($ad) ?></div>
            <p class="ipucu" style="margin-bottom:12px">
                <?= $tur === 'cari'
                    ? 'Kod, unvan, vergi ve iletişim bilgileri; isteğe bağlı açılış bakiyesi (pozitif: cari bize borçlu).'
                    : 'Kod, ad, birim, KDV, fiyatlar, barkod, koli içi adet; isteğe bağlı açılış miktarı (merkez depo).' ?>
                <a href="?sablon=<?= bh($tur) ?>"><i class="fa-solid fa-download"></i> Şablonu indir</a>
            </p>
            <div style="display:grid; gap:10px">
                <input type="file" name="dosya" accept=".xlsx,.xls,.csv" required>
                <label class="alan">Açılış tarihi <input type="date" name="tarih" value="<?= date('Y-m-d') ?>"></label>
                <label class="alan kutu" style="font-weight:400"><input type="checkbox" name="guncelle">
                    <span>Var olan kodların kart bilgilerini güncelle <span class="soluk" style="display:block">Bakiye/miktar yalnız yeni kartlara aktarılır.</span></span></label>
                <div><button class="btn btn-birincil" type="submit"><i class="fa-solid fa-upload"></i> Yükle ve kontrol et</button></div>
            </div>
        </form>
    <?php endforeach; ?>
</div>
<?php belge_sayfa_bitir();

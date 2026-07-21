<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../stok/ean.php");

// Barkod modulu yetkisi (M6)
if ((int) m_p_yetki($terminalkullanici, 'M6') !== 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// 1) Stok ID
if (isset($_GET['stok'])) {
    $stokid = intval($_GET['stok']);
} else {
    header('Location: barkodlar.php');
    exit;
}

$mesajlar = [];   // [ [tip, metin], ... ]

// ean13.php'den donen barkod: GET ile gelir, POST gibi islenir.
// CSRF acisindan guvenli, cunku deger kullanicinin kendi oturumunda uretilir
// ve asagidaki ekleme blogu ayrica token dogrular.
if (isset($_GET['ean']) && $stokid) {
    $_POST['barkod'] = trim((string) $_GET['ean']);
    $_POST['csrf_token'] = csrf_token();
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

// 2) Silme — yalniz POST + CSRF (GET ile silme kaldirildi: onceden link
//    onizlemesi/crawler ile tetiklenebiliyordu).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hareketsil'])) {
    if (!csrf_verify()) {
        $mesajlar[] = ['error', 'Gecersiz guvenlik dogrulamasi. Sayfayi yenileyin.'];
    } else {
        $hareketsil = intval($_POST['hareketsil']);
        $sil = $dbh->prepare("DELETE FROM {$firma}UNITBARCODE WHERE LOGICALREF = :lr AND ITEMREF = :it");
        $ok = $sil->execute([':lr' => $hareketsil, ':it' => $stokid]);
        $mesajlar[] = $ok ? ['ok', 'Barkod silindi.'] : ['error', 'Silme işlemi başarısız.'];
    }
}

// 3) Barkod ekleme
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['barkod'])) {
    if (!csrf_verify()) {
        $mesajlar[] = ['error', 'Gecersiz guvenlik dogrulamasi. Sayfayi yenileyin.'];
        $_POST['barkod'] = '';
    }
    $barkod = trim((string) $_POST['barkod']);

    if ($barkod === '') {
        $mesajlar[] = ['error', 'Barkod boş olamaz.'];
    } else {
        $stmt = $dbh->prepare("SELECT 1 FROM {$firma}UNITBARCODE WHERE BARCODE = :b");
        $stmt->execute([':b' => $barkod]);
        if ($stmt->fetch()) {
            $mesajlar[] = ['warn', 'Bu barkod zaten kayıtlı.'];
        } else {
            $s = $dbh->prepare("SELECT MAX(LINENR) AS maxnr FROM {$firma}UNITBARCODE WHERE ITEMREF = :it");
            $s->execute([':it' => $stokid]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            $sirano = intval($row['maxnr'] ?? 0) + 1;

            $m = $dbh->prepare("SELECT LOGICALREF, UNITLINEREF FROM {$firma}ITMUNITA WHERE ITEMREF = :it AND LINENR = 1");
            $m->execute([':it' => $stokid]);
            $minuta = $m->fetch(PDO::FETCH_ASSOC);
            $minutaid = intval($minuta['LOGICALREF'] ?? 0);
            $birimno = intval(birim_bul($stokid)[0]);

            $i = $dbh->prepare("INSERT INTO {$firma}UNITBARCODE (
                    ITMUNITAREF, ITEMREF, VARIANTREF, UNITLINEREF, LINENR, BARCODE,
                    SITEID, RECSTATUS, ORGLOGICREF, TYP, WBARCODESHIFT
                ) VALUES (:min, :it, 0, :u, :ln, :b, 0, 1, 0, 0, 0)");
            $i->execute([':min' => $minutaid, ':it' => $stokid, ':u' => $birimno, ':ln' => $sirano, ':b' => $barkod]);
            $mesajlar[] = $i->rowCount() ? ['ok', 'Barkod eklendi.'] : ['error', 'Ekleme başarısız oldu.'];
        }
    }
}

// 4) Ürün bilgisi
$stmtI = $dbh->prepare("SELECT CODE, NAME FROM {$firma}ITEMS WHERE LOGICALREF = :it");
$stmtI->execute([':it' => $stokid]);
$item = $stmtI->fetch(PDO::FETCH_ASSOC);

// 5) Mevcut barkodlar
$stmtB = $dbh->prepare("SELECT LOGICALREF, LINENR, BARCODE FROM {$firma}UNITBARCODE WHERE ITEMREF = :it ORDER BY LINENR");
$stmtB->execute([':it' => $stokid]);
$barcodes = $stmtB->fetchAll(PDO::FETCH_ASSOC);

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Barkod Ekle / Düzenle — Lumen</title>
  <link rel="icon" type="image/png" href="../icon.png">
  <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; --amber:#b45309; --amber-soft:#fffbeb; }
    * { box-sizing:border-box; margin:0; }
    body { font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; background:var(--bg); color:var(--t1); font-size:14px; }
    .top { position:sticky; top:0; z-index:30; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
    .top-in { max-width:800px; margin:0 auto; padding:11px 16px; display:flex; align-items:center; gap:12px; }
    .top a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
    .top .baslik { font-size:15px; font-weight:700; flex:1; display:flex; align-items:center; gap:8px; }
    .top .baslik i { color:var(--red); }
    main { max-width:800px; margin:0 auto; padding:20px 16px 60px; }
    .kart { background:var(--card); border:1px solid var(--border); border-radius:14px; margin-bottom:18px; overflow:hidden; }
    .kart-bas { padding:14px 18px; display:flex; align-items:center; gap:9px; font-weight:700; font-size:14px; border-bottom:1px solid var(--border); }
    .kart-bas i { color:var(--red); }
    .kart-govde { padding:18px; }
    .urun { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
    .urun .kod { font-family:'JetBrains Mono',monospace; background:#f3f4f6; padding:3px 9px; border-radius:6px; font-size:13px; font-weight:600; }
    .urun .ad { font-size:15px; font-weight:600; }
    form.ekle { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
    .alan { flex:1 1 260px; }
    .alan label { display:block; font-size:12px; font-weight:600; color:var(--t2); margin-bottom:6px; }
    input[type=text] { width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; background:#fff; outline:none; }
    input:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:11px 16px; border-radius:10px; font-weight:600; font-size:13px; cursor:pointer; text-decoration:none; border:1px solid transparent; font-family:inherit; }
    .btn-red { background:var(--red); color:#fff; border:none; } .btn-red:hover { filter:brightness(1.08); }
    .btn-hat { background:#fff; color:var(--t2); border:1px solid var(--border); } .btn-hat:hover { border-color:var(--red); color:var(--red); }
    .btn-sm { padding:6px 10px; font-size:12px; border-radius:8px; }
    .btn-ikon { width:32px; height:32px; padding:0; }
    .btn-sil { background:var(--red-soft); color:var(--red); } .btn-sil:hover { background:#fde2e2; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    thead th { text-align:left; padding:11px 14px; background:#faf7f8; color:var(--t2); font-weight:600; font-size:11.5px; text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid var(--border); }
    tbody td { padding:10px 14px; border-bottom:1px solid #f1f1f1; }
    tbody tr:hover { background:var(--red-soft); }
    .kod2 { font-family:'JetBrains Mono',monospace; background:#f3f4f6; padding:2px 7px; border-radius:6px; font-size:12px; }
    .bos { text-align:center; padding:26px; color:var(--t3); }
    .islem { display:flex; gap:6px; }
    .mesaj { padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:13px; display:flex; gap:9px; align-items:center; }
    .mesaj.ok { background:var(--emerald-soft); color:#065f46; border:1px solid #a7f3d0; }
    .mesaj.warn { background:var(--amber-soft); color:#92400e; border:1px solid #fde68a; }
    .mesaj.error { background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }
  </style>
</head>
<body>
  <header class="top">
    <div class="top-in">
      <a href="barkodlar.php" class="geri" title="Barkodlar"><i class="fa fa-arrow-left"></i></a>
      <span class="baslik"><i class="fa-solid fa-barcode"></i> Barkod Ekle / Düzenle</span>
    </div>
  </header>
  <main>

    <?php foreach ($mesajlar as [$tip, $metin]): ?>
      <div class="mesaj <?php echo $tip === 'ok' ? 'ok' : ($tip === 'warn' ? 'warn' : 'error'); ?>">
        <i class="fa-solid <?php echo $tip === 'ok' ? 'fa-circle-check' : ($tip === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-exclamation'); ?>"></i>
        <span><?php echo $h($metin); ?></span>
      </div>
    <?php endforeach; ?>

    <?php if ($item): ?>
    <div class="kart">
      <div class="kart-govde urun">
        <span class="kod"><?php echo $h($item['CODE']); ?></span>
        <span class="ad"><?php echo $h($item['NAME']); ?></span>
      </div>
    </div>
    <?php endif; ?>

    <div class="kart">
      <div class="kart-bas"><i class="fa-solid fa-plus"></i> Yeni Barkod Ekle</div>
      <div class="kart-govde">
        <form method="post" class="ekle">
          <?php echo csrf_field(); ?>
          <div class="alan">
            <label for="barkod">Barkod</label>
            <input type="text" id="barkod" name="barkod" required autofocus placeholder="Barkod okutun veya yazın">
          </div>
          <button type="submit" class="btn btn-red"><i class="fa-solid fa-plus"></i> Ekle</button>
          <a href="ean13.php?stok=<?php echo (int) $stokid; ?>" class="btn btn-hat"><i class="fa-solid fa-wand-magic-sparkles"></i> EAN13 Oluştur</a>
        </form>
      </div>
    </div>

    <div class="kart">
      <div class="kart-bas"><i class="fa-solid fa-list"></i> Mevcut Barkodlar <?php if ($barcodes): ?><span style="margin-left:auto;font-size:12px;font-weight:600;color:var(--t2);"><?php echo count($barcodes); ?> adet</span><?php endif; ?></div>
      <table>
        <thead><tr><th>#</th><th>Barkod</th><th>Sil</th></tr></thead>
        <tbody>
          <?php if (!$barcodes): ?>
            <tr><td colspan="3" class="bos">Bu ürün için barkod kaydı yok.</td></tr>
          <?php else: foreach ($barcodes as $row): ?>
            <tr>
              <td><?php echo $h($row['LINENR']); ?></td>
              <td><span class="kod2"><?php echo $h($row['BARCODE']); ?></span></td>
              <td>
                <form method="post" style="display:inline"
                      onsubmit="return confirm('<?php echo $h($row['BARCODE']); ?> silinecek. Emin misiniz?')">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="hareketsil" value="<?php echo (int) $row['LOGICALREF']; ?>">
                  <button type="submit" class="btn btn-sm btn-ikon btn-sil" title="Sil"><i class="fa-solid fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </main>
</body>
</html>

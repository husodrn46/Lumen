<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../ayr.php");

// UTF-8 BOM ve geçersiz karakterleri temizle
function cleanCsvValue(string $value): string {
  $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
  $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
  return trim($value);
}

// SQL Server için güvenli string - sadece ASCII
function toDbSafe(string $value): string {
  $tr = ['İ'=>'I','ı'=>'i','Ğ'=>'G','ğ'=>'g','Ü'=>'U','ü'=>'u','Ş'=>'S','ş'=>'s','Ö'=>'O','ö'=>'o','Ç'=>'C','ç'=>'c'];
  $value = strtr($value, $tr);
  $value = preg_replace('/[^\x20-\x7E]/', '', $value);
  return trim($value);
}

$importMesajlari = [];   // [ [tip, html], ... ]

if (isset($_POST['do_import']) && isset($_FILES['import_file'])) {
  $file = $_FILES['import_file']['tmp_name'];
  $content = file_get_contents($file);

  // BOM temizle (UTF-8 / UTF-16)
  $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
  $content = preg_replace('/^\xFF\xFE/', '', $content);
  $content = preg_replace('/^\xFE\xFF/', '', $content);

  // Windows-1254 (Türkçe) düzeltmeleri
  $win1254fixes = [
    "\xDD" => 'İ', "\xFD" => 'ı', "\xDE" => 'Ş', "\xFE" => 'ş',
    "\xD0" => 'Ğ', "\xF0" => 'ğ', "\xDC" => 'Ü', "\xFC" => 'ü',
    "\xD6" => 'Ö', "\xF6" => 'ö', "\xC7" => 'Ç', "\xE7" => 'ç',
  ];
  $content = strtr($content, $win1254fixes);

  $tempFile = tempnam(sys_get_temp_dir(), 'csv_');
  file_put_contents($tempFile, $content);

  $handle = fopen($tempFile, 'r');
  if (!$handle) {
    $importMesajlari[] = ['error', 'Dosya açılamadı.'];
  } else {
    $firstLine = fgets($handle);
    rewind($handle);
    $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ',';
    fgetcsv($handle, 1000, $delimiter); // başlık satırını at

    $insertedCount = 0; $updatedCount = 0; $skipCount = 0;
    $skippedCodes = [];

    while (($row = fgetcsv($handle, 1000, $delimiter)) !== false) {
      if (!$row || count($row) < 2) { continue; }
      $prodCode = cleanCsvValue((string) ($row[0] ?? ''));
      $barcode  = cleanCsvValue((string) ($row[1] ?? ''));
      if ($prodCode === '' || $barcode === '') { continue; }

      $prodCodeDb = toDbSafe($prodCode);
      $barcodeDb  = toDbSafe($barcode);

      $stmt = $dbh->prepare("
                SELECT LOGICALREF FROM {$firma}ITEMS
                WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
                  CODE, 'İ','I'), 'ı','I'), 'Ğ','G'), 'ğ','G'), 'Ş','S'), 'ş','S')) = UPPER(:code)
                  AND ACTIVE = 0");
      $stmt->execute([':code' => $prodCodeDb]);
      $item = $stmt->fetch(PDO::FETCH_ASSOC);

      if (!$item) { $skipCount++; $skippedCodes[] = $prodCode; continue; }
      $stokid = intval($item['LOGICALREF']);

      $dup = $dbh->prepare("SELECT LOGICALREF, ITEMREF FROM {$firma}UNITBARCODE WHERE BARCODE = :b");
      $dup->execute([':b' => $barcodeDb]);
      $existing = $dup->fetch(PDO::FETCH_ASSOC);

      $m = $dbh->prepare("SELECT LOGICALREF, UNITLINEREF FROM {$firma}ITMUNITA WHERE ITEMREF = :it AND LINENR = 1");
      $m->execute([':it' => $stokid]);
      $minRow = $m->fetch(PDO::FETCH_ASSOC);
      $minid  = intval($minRow['LOGICALREF'] ?? 0);
      $unitno = intval(birim_bul($stokid)[0]);

      if ($existing) {
        $upd = $dbh->prepare("UPDATE {$firma}UNITBARCODE
                    SET ITEMREF = :it, ITMUNITAREF = :min, UNITLINEREF = :un WHERE LOGICALREF = :lr");
        $upd->execute([':it' => $stokid, ':min' => $minid, ':un' => $unitno, ':lr' => $existing['LOGICALREF']]);
        $updatedCount++;
      } else {
        $r = $dbh->prepare("SELECT MAX(LINENR) AS maxnr FROM {$firma}UNITBARCODE WHERE ITEMREF = :it");
        $r->execute([':it' => $stokid]);
        $row2 = $r->fetch(PDO::FETCH_ASSOC);
        $lineno = intval($row2['maxnr'] ?? 0) + 1;

        $ins = $dbh->prepare("INSERT INTO {$firma}UNITBARCODE (
                        ITMUNITAREF, ITEMREF, VARIANTREF, UNITLINEREF, LINENR, BARCODE,
                        SITEID, RECSTATUS, ORGLOGICREF, TYP, WBARCODESHIFT
                    ) VALUES (:min, :it, 0, :un, :ln, :b, 0, 1, 0, 0, 0)");
        $ins->execute([':min' => $minid, ':it' => $stokid, ':un' => $unitno, ':ln' => $lineno, ':b' => $barcodeDb]);
        $insertedCount++;
      }
    }
    fclose($handle);
    if (isset($tempFile) && file_exists($tempFile)) { unlink($tempFile); }

    $ozet = "<b>{$insertedCount}</b> yeni · <b>{$updatedCount}</b> güncellenen · <b>{$skipCount}</b> bulunamayan ürün";
    $importMesajlari[] = ['ok', 'İçe aktarım tamamlandı — ' . $ozet];

    if ($skipCount > 0) {
      $uniqueSkipped = array_unique($skippedCodes);
      $liste = implode(', ', array_map(fn($c) => htmlspecialchars($c, ENT_QUOTES, 'UTF-8'), $uniqueSkipped));
      $importMesajlari[] = ['warn', 'LOGO sisteminde bulunamayan kodlar (' . count($uniqueSkipped) . '): ' . $liste];
    }
  }
}

// Barkod durum sorguları (aktif ürünler)
$missingItems = $dbh->query("
  SELECT I.LOGICALREF AS itemref, I.CODE AS item_code, I.NAME AS item_name
  FROM {$firma}ITEMS I
  LEFT JOIN {$firma}UNITBARCODE UB ON UB.ITEMREF = I.LOGICALREF
  WHERE I.ACTIVE = 0
  GROUP BY I.LOGICALREF, I.CODE, I.NAME
  HAVING COUNT(UB.BARCODE) = 0
  ORDER BY I.CODE")->fetchAll(PDO::FETCH_ASSOC);

$twoBarcodeItems = $dbh->query("
  SELECT I.LOGICALREF AS itemref, I.CODE AS item_code, I.NAME AS item_name, COUNT(UB.BARCODE) AS barcode_count
  FROM {$firma}ITEMS I
  JOIN {$firma}UNITBARCODE UB ON UB.ITEMREF = I.LOGICALREF
  WHERE I.ACTIVE = 0
  GROUP BY I.LOGICALREF, I.CODE, I.NAME
  HAVING COUNT(UB.BARCODE) >= 2
  ORDER BY I.CODE")->fetchAll(PDO::FETCH_ASSOC);

$allBarcodes = $dbh->query("
  SELECT UB.LOGICALREF AS barcode_ref, UB.BARCODE, UB.LINENR,
         I.LOGICALREF AS itemref, I.CODE AS item_code, I.NAME AS item_name
  FROM {$firma}UNITBARCODE UB
  JOIN {$firma}ITEMS I ON I.LOGICALREF = UB.ITEMREF
  WHERE I.ACTIVE = 0
  ORDER BY I.CODE, UB.LINENR")->fetchAll(PDO::FETCH_ASSOC);

$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Barkod Durum Raporu — Lumen</title>
  <link rel="icon" type="image/png" href="../icon.png">
  <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; --amber:#b45309; --amber-soft:#fffbeb; }
    * { box-sizing:border-box; margin:0; }
    body { font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; background:var(--bg); color:var(--t1); font-size:14px; }
    .top { position:sticky; top:0; z-index:30; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
    .top-in { max-width:1000px; margin:0 auto; padding:11px 16px; display:flex; align-items:center; gap:12px; }
    .top a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
    .top .baslik { font-size:15px; font-weight:700; flex:1; display:flex; align-items:center; gap:8px; }
    .top .baslik i { color:var(--red); }
    .top .sayac { font-size:12px; color:var(--t2); background:var(--red-soft); color:var(--red); padding:4px 10px; border-radius:20px; font-weight:600; }
    main { max-width:1000px; margin:0 auto; padding:20px 16px 60px; }
    .kart { background:var(--card); border:1px solid var(--border); border-radius:14px; margin-bottom:18px; overflow:hidden; }
    .kart-bas { padding:14px 18px; display:flex; align-items:center; gap:9px; font-weight:700; font-size:14px; border-bottom:1px solid var(--border); }
    .kart-bas i { font-size:15px; }
    .kart-bas.mavi i { color:var(--red); }
    .kart-bas.uyari { background:var(--red-soft); color:#991b1b; } .kart-bas.uyari i { color:var(--red); }
    .kart-bas.basari { background:var(--emerald-soft); color:#065f46; } .kart-bas.basari i { color:var(--emerald); }
    .kart-bas .rozet { margin-left:auto; font-size:12px; font-weight:600; background:#fff; border:1px solid var(--border); padding:3px 10px; border-radius:20px; color:var(--t2); }
    .kart-govde { padding:18px; }
    .aciklama { font-size:12.5px; color:var(--t2); margin-bottom:14px; line-height:1.5; }
    form.ice { display:flex; gap:10px; flex-wrap:wrap; align-items:center; }
    input[type=file], input[type=text] { padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; background:#fff; outline:none; }
    input:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
    .btn { display:inline-flex; align-items:center; gap:7px; padding:10px 16px; border-radius:10px; font-weight:600; font-size:13px; cursor:pointer; text-decoration:none; border:1px solid transparent; font-family:inherit; }
    .btn-red { background:var(--red); color:#fff; border:none; } .btn-red:hover { filter:brightness(1.08); }
    .btn-hat { background:#fff; color:var(--t2); border:1px solid var(--border); } .btn-hat:hover { border-color:var(--red); color:var(--red); }
    .btn-sm { padding:6px 11px; font-size:12px; border-radius:8px; }
    .btn-ekle { background:var(--emerald-soft); color:var(--emerald); } .btn-ekle:hover { background:#d1fae5; }
    .btn-ikon { width:32px; height:32px; padding:0; justify-content:center; }
    table { width:100%; border-collapse:collapse; font-size:13px; }
    thead th { text-align:left; padding:11px 14px; background:#faf7f8; color:var(--t2); font-weight:600; font-size:11.5px; text-transform:uppercase; letter-spacing:.4px; border-bottom:1px solid var(--border); position:sticky; top:0; }
    tbody td { padding:10px 14px; border-bottom:1px solid #f1f1f1; }
    tbody tr:hover { background:var(--red-soft); }
    td code, .kod { font-family:'JetBrains Mono',monospace; background:#f3f4f6; padding:2px 7px; border-radius:6px; font-size:12px; }
    .bos { text-align:center; padding:26px; color:var(--t3); }
    .tablo-sar { max-height:460px; overflow:auto; }
    .mesaj { padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:13px; display:flex; gap:9px; line-height:1.5; }
    .mesaj.ok { background:var(--emerald-soft); color:#065f46; border:1px solid #a7f3d0; }
    .mesaj.warn { background:var(--amber-soft); color:#92400e; border:1px solid #fde68a; }
    .mesaj.error { background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }
    .ara-kutu { padding:14px 18px; border-bottom:1px solid var(--border); }
    .ara-kutu input { width:100%; }
    @media (max-width:640px){ thead th:nth-child(1), tbody td:nth-child(1){ display:none; } }
  </style>
</head>
<body>
  <header class="top">
    <div class="top-in">
      <a href="../index.php" class="geri" title="Ana sayfa"><i class="fa fa-arrow-left"></i></a>
      <span class="baslik"><i class="fa-solid fa-barcode"></i> Barkod Durum Raporu</span>
      <span class="sayac"><?php echo count($allBarcodes); ?> barkod</span>
    </div>
  </header>
  <main>

    <?php foreach ($importMesajlari as [$tip, $html]): ?>
      <div class="mesaj <?php echo $tip === 'ok' ? 'ok' : ($tip === 'warn' ? 'warn' : 'error'); ?>">
        <i class="fa-solid <?php echo $tip === 'ok' ? 'fa-circle-check' : ($tip === 'warn' ? 'fa-triangle-exclamation' : 'fa-circle-exclamation'); ?>"></i>
        <span><?php echo $html; /* güvenli: yukarıda escape edildi */ ?></span>
      </div>
    <?php endforeach; ?>

    <!-- Toplu içe aktar -->
    <div class="kart">
      <div class="kart-bas mavi"><i class="fa-solid fa-file-import"></i> Toplu İçe Aktar</div>
      <div class="kart-govde">
        <p class="aciklama">CSV yükleyin (başlık satırı: <code>product_code,barcode</code>). Türkiye Excel'i için <code>;</code> ayracı da desteklenir.</p>
        <form method="post" enctype="multipart/form-data" class="ice">
          <input type="file" name="import_file" accept=".csv" required>
          <button type="submit" name="do_import" class="btn btn-red"><i class="fa-solid fa-upload"></i> İçe Aktar</button>
          <a href="barkod_sablon.csv" download class="btn btn-hat"><i class="fa-solid fa-download"></i> Şablon İndir</a>
        </form>
      </div>
    </div>

    <!-- Barkodu olmayan ürünler -->
    <div class="kart">
      <div class="kart-bas uyari"><i class="fa-solid fa-circle-exclamation"></i> Barkodu Olmayan Ürünler <span class="rozet"><?php echo count($missingItems); ?></span></div>
      <div class="tablo-sar">
        <table>
          <thead><tr><th>Ref</th><th>Kod</th><th>İsim</th><th>İşlem</th></tr></thead>
          <tbody>
            <?php if (!$missingItems): ?>
              <tr><td colspan="4" class="bos">Tüm ürünlerin barkodu var. 🎉</td></tr>
            <?php else: foreach ($missingItems as $it): ?>
              <tr>
                <td><?php echo $h($it['itemref']); ?></td>
                <td><span class="kod"><?php echo $h($it['item_code']); ?></span></td>
                <td><?php echo $h($it['item_name']); ?></td>
                <td><a class="btn btn-sm btn-ekle" href="barkod_ekle.php?stok=<?php echo (int) $it['itemref']; ?>"><i class="fa-solid fa-plus"></i> Barkod Ekle</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Birden fazla barkodlu ürünler -->
    <div class="kart">
      <div class="kart-bas basari"><i class="fa-solid fa-layer-group"></i> Birden Fazla Barkodu Olan Ürünler <span class="rozet"><?php echo count($twoBarcodeItems); ?></span></div>
      <div class="tablo-sar">
        <table>
          <thead><tr><th>Ref</th><th>Kod</th><th>İsim</th><th>Adet</th><th>Detay</th></tr></thead>
          <tbody>
            <?php if (!$twoBarcodeItems): ?>
              <tr><td colspan="5" class="bos">Birden fazla barkodlu ürün yok.</td></tr>
            <?php else: foreach ($twoBarcodeItems as $it): ?>
              <tr>
                <td><?php echo $h($it['itemref']); ?></td>
                <td><span class="kod"><?php echo $h($it['item_code']); ?></span></td>
                <td><?php echo $h($it['item_name']); ?></td>
                <td><?php echo $h($it['barcode_count']); ?></td>
                <td><a class="btn btn-sm btn-hat" href="barkod_ekle.php?stok=<?php echo (int) $it['itemref']; ?>"><i class="fa-solid fa-eye"></i> Gör</a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Tüm barkodlar -->
    <div class="kart">
      <div class="kart-bas mavi"><i class="fa-solid fa-barcode"></i> Tüm Barkodlar <span class="rozet"><?php echo count($allBarcodes); ?></span></div>
      <div class="ara-kutu"><input type="text" id="searchBarcode" placeholder="Ürün kodu, ismi veya barkod ile ara..."></div>
      <div class="tablo-sar">
        <table id="barcodeTable">
          <thead><tr><th>Sıra</th><th>Ürün Kodu</th><th>Ürün Adı</th><th>Barkod</th><th>Düzenle</th></tr></thead>
          <tbody>
            <?php if (!$allBarcodes): ?>
              <tr><td colspan="5" class="bos">Henüz barkod kaydı yok.</td></tr>
            <?php else: foreach ($allBarcodes as $bc): ?>
              <tr>
                <td><?php echo $h($bc['LINENR']); ?></td>
                <td><span class="kod"><?php echo $h($bc['item_code']); ?></span></td>
                <td><?php echo $h($bc['item_name']); ?></td>
                <td><span class="kod"><?php echo $h($bc['BARCODE']); ?></span></td>
                <td><a class="btn btn-sm btn-ikon btn-hat" href="barkod_ekle.php?stok=<?php echo (int) $bc['itemref']; ?>" title="Düzenle"><i class="fa-solid fa-pen"></i></a></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </main>
  <script>
    var inp = document.getElementById('searchBarcode');
    if (inp) inp.addEventListener('keyup', function () {
      var q = this.value.toLowerCase();
      document.querySelectorAll('#barcodeTable tbody tr').forEach(function (row) {
        if (row.querySelector('td[colspan]')) return;
        var t = (row.cells[1].textContent + ' ' + row.cells[2].textContent + ' ' + row.cells[3].textContent).toLowerCase();
        row.style.display = t.includes(q) ? '' : 'none';
      });
    });
  </script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
$stokid = isset($_GET['stok']) ? intval($_GET['stok']) : 0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>EAN-13 Barkod Üretici — Lumen</title>
  <link rel="icon" type="image/png" href="../icon.png">
  <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
  <style>
    :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; }
    * { box-sizing:border-box; margin:0; }
    body { font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; background:var(--bg); color:var(--t1); font-size:14px; }
    .top { position:sticky; top:0; z-index:30; background:rgba(255,255,255,.92); backdrop-filter:blur(6px); border-bottom:1px solid var(--border); }
    .top-in { max-width:760px; margin:0 auto; padding:11px 16px; display:flex; align-items:center; gap:12px; }
    .top a.geri { width:34px; height:34px; border-radius:9px; display:inline-flex; align-items:center; justify-content:center; color:var(--t2); text-decoration:none; border:1px solid var(--border); background:#fff; }
    .top .baslik { font-size:15px; font-weight:700; flex:1; display:flex; align-items:center; gap:8px; }
    .top .baslik i { color:var(--red); }
    main { max-width:760px; margin:0 auto; padding:20px 16px 60px; }
    .kart { background:var(--card); border:1px solid var(--border); border-radius:14px; margin-bottom:18px; padding:18px; }
    .aciklama { font-size:13px; color:var(--t2); line-height:1.5; }
    .aciklama b { color:var(--red); }
    .satir { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
    .alan { flex:1 1 220px; }
    .alan label { display:block; font-size:12px; font-weight:600; color:var(--t2); margin-bottom:6px; }
    input[type=text] { width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-size:16px; font-family:'JetBrains Mono',monospace; letter-spacing:2px; background:#fff; outline:none; }
    input:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
    .ipuc { font-size:11.5px; color:var(--t3); margin-top:5px; }
    .btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:11px 16px; border-radius:10px; font-weight:600; font-size:13px; cursor:pointer; text-decoration:none; border:1px solid transparent; font-family:inherit; }
    .btn-red { background:var(--red); color:#fff; border:none; } .btn-red:hover { filter:brightness(1.08); }
    .btn-hat { background:#fff; color:var(--t2); border:1px solid var(--border); } .btn-hat:hover { border-color:var(--red); color:var(--red); }
    .btn:disabled { opacity:.5; cursor:not-allowed; }
    .arac { display:flex; gap:8px; flex-wrap:wrap; margin-top:14px; }
    .barkod-alan { text-align:center; }
    .barkod-alan h3 { font-size:13px; color:var(--t2); text-transform:uppercase; letter-spacing:.5px; margin-bottom:14px; }
    .barcode-container svg { max-width:100%; height:auto; }
    #barcodeNumber { display:inline-block; font-family:'JetBrains Mono',monospace; background:#f3f4f6; padding:6px 14px; border-radius:8px; font-size:15px; cursor:pointer; margin-top:8px; }
    .gecmis { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
    .history-item { background:var(--red-soft); color:var(--red); padding:6px 12px; border-radius:8px; cursor:pointer; font-family:'JetBrains Mono',monospace; font-size:12.5px; }
    .baslik-k { font-size:14px; font-weight:700; margin-bottom:12px; display:flex; align-items:center; gap:8px; } .baslik-k i { color:var(--red); }
  </style>
</head>
<body>
  <header class="top">
    <div class="top-in">
      <a href="barkod_ekle.php?stok=<?php echo (int) $stokid; ?>" class="geri" title="Geri"><i class="fa fa-arrow-left"></i></a>
      <span class="baslik"><i class="fa-solid fa-barcode"></i> EAN-13 Barkod Üretici</span>
    </div>
  </header>
  <main>

    <div class="kart">
      <p class="aciklama">Türkiye ürün barkodları <b>869</b> ile başlar. 12 haneli kodu girin; kontrol hanesi otomatik hesaplanır ve geçerli bir EAN-13 barkodu üretilir.</p>
    </div>

    <div class="kart">
      <div class="satir">
        <div class="alan">
          <label for="eanInput">12 Haneli Kod</label>
          <input type="text" id="eanInput" value="869" maxlength="12" placeholder="869XXXXXXXXX" inputmode="numeric">
          <div class="ipuc"><span id="remaining">9</span> karakter kaldı</div>
        </div>
        <button class="btn btn-red" onclick="generateBarcode()"><i class="fa-solid fa-barcode"></i> Oluştur</button>
      </div>
      <div class="arac">
        <button class="btn btn-hat" onclick="window.print()"><i class="fa-solid fa-print"></i> Yazdır</button>
        <button class="btn btn-hat" onclick="downloadPNG()"><i class="fa-solid fa-download"></i> PNG İndir</button>
      </div>
    </div>

    <div class="kart barkod-alan">
      <h3>Üretilen Barkod</h3>
      <div class="barcode-container"><svg id="barcode"></svg></div>
      <div><span id="barcodeNumber" onclick="copyText()" title="Kopyalamak için tıklayın">Henüz üretilmedi</span></div>
    </div>

    <div class="kart">
      <div class="baslik-k"><i class="fa-solid fa-clock-rotate-left"></i> Geçmiş</div>
      <div id="history" class="gecmis"></div>
    </div>

    <div style="text-align:center;">
      <button id="transferBtn" class="btn btn-red" disabled title="Önce barkod üretin"><i class="fa-solid fa-arrow-up-from-bracket"></i> Oluşturulan Barkodu Aktar</button>
    </div>

  </main>
  <script>
    const stokId = <?php echo (int) $stokid; ?>;
    let lastCode = '';

    function updateCharCount() {
      document.getElementById('remaining').innerText = 12 - document.getElementById('eanInput').value.length;
    }
    document.getElementById('eanInput').addEventListener('input', function () {
      updateCharCount();
      if (this.value.length === 12) generateBarcode();
    });

    function generateBarcode() {
      const input = document.getElementById('eanInput').value;
      if (!/^\d{12}$/.test(input) || !input.startsWith('869')) {
        alert('Lütfen 869 ile başlayan 12 haneli sayısal değer girin.');
        return;
      }
      let sum = 0;
      for (let i = 0; i < input.length; i++) { const d = +input[i]; sum += (i % 2 === 0 ? d : d * 3); }
      const full = input + ((10 - (sum % 10)) % 10);

      JsBarcode('#barcode', full, { format: 'ean13', displayValue: true, fontSize: 18, lineColor: '#1f2937' });
      document.getElementById('barcodeNumber').innerText = full;
      lastCode = full;

      const div = document.createElement('div');
      div.className = 'history-item';
      div.innerText = full;
      div.onclick = () => navigator.clipboard.writeText(full).then(() => alert('Kopyalandı: ' + full));
      document.getElementById('history').append(div);

      document.getElementById('transferBtn').disabled = false;
    }

    document.getElementById('transferBtn').addEventListener('click', () => {
      if (!lastCode) return;
      window.location.href = `barkod_ekle.php?stok=${stokId}&ean=${lastCode}`;
    });

    function copyText() {
      const txt = document.getElementById('barcodeNumber').innerText;
      if (txt && txt !== 'Henüz üretilmedi') navigator.clipboard.writeText(txt).then(() => alert('Kopyalandı: ' + txt));
    }

    function downloadPNG() {
      const svg = document.getElementById('barcode');
      if (!svg.innerHTML) { alert('Önce barkod üretin.'); return; }
      const svgData = new XMLSerializer().serializeToString(svg);
      const img = new Image();
      img.onload = () => {
        const c = document.createElement('canvas');
        c.width = img.width; c.height = img.height;
        const ctx = c.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height);
        ctx.drawImage(img, 0, 0);
        const link = document.createElement('a');
        link.download = 'barkod.png'; link.href = c.toDataURL('image/png'); link.click();
      };
      img.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svgData)));
    }
  </script>
</body>
</html>

<?php
declare(strict_types=1);

include_once __DIR__ . "/ayr.php";
include    __DIR__ . "/kontrol.php";
include_once __DIR__ . "/log_ip.php";

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['stokhareket', 'sipariskaydet']);

// stokhareket parametresini al (session bazlı)
$stokhareket = getPageParamInt('stokhareket');
if ($stokhareket === 0) {
    die("Geçersiz fiş numarası.");
}
if (!m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// FORM GÖNDERİLDİYSE (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action      = $_POST['action'] ?? 'save';
    $aciklama1   = $_POST['aciklama1'] ?? '';
    $aciklama2   = $_POST['aciklama2'] ?? '';
    $docode      = function_exists('mb_substr')
        ? mb_substr((string)$aciklama1, 0, 33, 'UTF-8')
        : substr((string)$aciklama1, 0, 33);

    // Açıklamaları güncelle
    try {
        $stmt_update = $dbh->prepare("
            UPDATE {$firmadonem}ORFICHE
               SET GENEXP1 = :aciklama1,
                   GENEXP2 = :aciklama2,
                   DOCODE = :docode
             WHERE LOGICALREF = :stokhareket
        ");
        $stmt_update->execute([
            ':aciklama1'   => $aciklama1,
            ':aciklama2'   => $aciklama2,
            ':docode'      => $docode,
            ':stokhareket' => $stokhareket,
        ]);
    } catch (PDOException $e) {
        die("Açıklama güncellenirken hata oluştu: " . $e->getMessage());
    }

    // Hangi buton tıklandıysa ona göre yönlendir (session bazlı temiz URL'ler)
    $redirect_url = match ($action) {
        'save_only' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'lg_siparis');
            setPageParam('sipariskaydet', '1', 'lg_siparis');
            return "lg_siparis.php";
        })(),
        'print_yeni_dizayn' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'yeni_dizayn');
            setPageParam('tipdurum', 1, 'yeni_dizayn');
            return "yeni_dizayn.php";
        })(),
        'print_fisyaz' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'fisyaz');
            return "fisyaz.php";
        })(),
        'print_html' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'fisyazhtml');
            return "fisyazhtml.php";
        })(),
        'export_excel' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'fisyazexcel');
            return "fisyazexcel.php";
        })(),
        'print_resimli' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'fisyazresimli');
            return "fisyazresimli.php";
        })(),
        'print_kolili' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'fisyazkoli');
            return "fisyazkoli.php";
        })(),
        'print_dovizli' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'fisyazdoviz');
            return "fisyazdoviz.php";
        })(),
        'print_barkodlu' => (function() use ($stokhareket) {
            setPageParam('stokhareket', $stokhareket, 'barkodluyaz');
            return "barkodluyaz.php";
        })(),
        default => 'index.php',
    };

    header("Location: {$redirect_url}");
    exit;
}

// SAYFAYI GÖRÜNTÜLEMEK İÇİN (GET)
try {
    $stmt_select = $dbh->prepare("
        SELECT GENEXP1, GENEXP2
          FROM {$firmadonem}ORFICHE
         WHERE LOGICALREF = :stokhareket
    ");
    $stmt_select->execute([':stokhareket' => $stokhareket]);
    $eyne = $stmt_select->fetch(PDO::FETCH_ASSOC);
    if (!is_array($eyne)) {
        $eyne = ['GENEXP1' => '', 'GENEXP2' => ''];
    }
} catch (PDOException $e) {
    die("Fiş bilgileri çekilirken hata oluştu: " . $e->getMessage());
}

// Döviz modu
$doviz = $_SESSION['doviz'] ?? '0';

// Yazdirma eylemleri listesi (dinamik — yetki/ayarlara gore)
$printActions = [
    ['action' => 'print_yeni_dizayn', 'label' => 'Yeni Dizayn',    'icon' => 'fa-wand-magic-sparkles', 'show' => true],
    ['action' => 'print_fisyaz',      'label' => 'Yazdir',         'icon' => 'fa-receipt',             'show' => true],
    ['action' => 'print_html',        'label' => 'PC Yazdir',      'icon' => 'fa-desktop',             'show' => true],
    ['action' => 'export_excel',      'label' => "Excel'e Aktar",  'icon' => 'fa-file-excel',          'show' => (isset($exceleaktar) && $exceleaktar == 1), 'tone' => 'green'],
    ['action' => 'print_resimli',     'label' => 'Resimli Yazdir', 'icon' => 'fa-image',               'show' => (isset($resimliyazdir) && $resimliyazdir == 1)],
    ['action' => 'print_kolili',      'label' => 'Kolili Yazdir',  'icon' => 'fa-boxes-stacked',       'show' => (isset($koliyazdir) && $koliyazdir == 1)],
    ['action' => 'print_barkodlu',    'label' => 'Barkodlu Yazdir','icon' => 'fa-barcode',             'show' => (isset($barkodyazdir) && $barkodyazdir == 1)],
    ['action' => 'print_dovizli',     'label' => 'Dovizli Yazdir', 'icon' => 'fa-dollar-sign',         'show' => ($doviz !== '0' && isset($dovizlicalis) && $dovizlicalis == '1')],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Siparis Islemleri</title>
  <link rel="icon" type="image/png" href="icon.png">
  <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
  <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg: #f9fafb;
      --text-1: #1f2937;
      --text-2: #6b7280;
      --text-3: #9ca3af;
      --border: #e5e7eb;
      --red: #6F1022;
      --red-soft: #fef2f2;
      --emerald: #059669;
      --indigo: #4f46e5;
    }
    * { box-sizing: border-box; margin: 0; }
    body {
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      background: var(--bg);
      color: var(--text-1);
      min-height: 100vh;
    }

    /* ═══════ HEADER ═══════ */
    .top-header {
      position: sticky;
      top: 0;
      z-index: 40;
      height: 64px;
      background: rgba(255,255,255,0.88);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border-bottom: 1px solid rgba(248, 113, 113, 0.18);
      box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
    }
    .header-inner {
      max-width: 720px;
      margin: 0 auto;
      height: 100%;
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 0 24px;
    }
    .header-back {
      display: inline-flex; align-items: center; justify-content: center;
      width: 36px; height: 36px; border-radius: 10px;
      color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
    }
    .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
    .header-divider { width: 1px; height: 24px; background: var(--border); }
    .header-title {
      font-size: 18px;
      font-weight: 700;
      color: var(--text-1);
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }
    .header-title i { color: var(--red,#ef4444); font-size: 16px; }

    /* ═══════ CARD ═══════ */
    .glass-card {
      background: rgba(255,255,255,0.92);
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      border: 1px solid rgba(248, 113, 113, 0.18);
      border-radius: 16px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.04);
      will-change: transform, opacity;
      animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
      padding: 22px;
    }
    .glass-card + .glass-card { margin-top: 18px; }
    .glass-card:nth-of-type(2) { animation-delay: 80ms; }

    .card-head {
      display: flex;
      align-items: flex-start;
      gap: 12px;
      margin-bottom: 16px;
    }
    .card-head .icon-box {
      width: 40px; height: 40px;
      flex-shrink: 0;
      border-radius: 12px;
      background: var(--red-soft);
      color: var(--red);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
    }
    .card-head h2 {
      font-size: 15px;
      font-weight: 700;
      color: var(--text-1);
      margin-bottom: 2px;
    }
    .card-head p {
      font-size: 12px;
      color: var(--text-2);
      line-height: 1.5;
    }

    /* ═══════ FIELDS ═══════ */
    .field { display: flex; flex-direction: column; gap: 6px; }
    .field + .field { margin-top: 14px; }
    .field-label {
      font-size: 11px;
      font-weight: 600;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .field-control {
      width: 100%;
      padding: 11px 14px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 14px;
      color: var(--text-1);
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 10px;
      transition: all 0.2s ease;
      outline: none;
      resize: vertical;
    }
    .field-control:focus {
      border-color: rgba(239, 68, 68, 0.5);
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
    }
    textarea.field-control { min-height: 80px; line-height: 1.5; }

    /* ═══════ BUTTONS ═══════ */
    .btn-flat {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 11px 18px;
      border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 13px;
      font-weight: 600;
      border: none;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .btn-flat:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.12); transform: translateY(-1px); }
    .btn-flat:active { transform: translateY(0); }
    .btn-indigo { background: var(--indigo); color: #fff; }
    .btn-indigo:hover { background: #4338ca; }

    .save-row {
      margin-top: 16px;
      display: flex;
      justify-content: flex-end;
    }

    /* ═══════ ACTION TILES ═══════ */
    .action-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 12px;
    }
    .action-tile {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 10px;
      padding: 18px 10px;
      background: #fff;
      border: 1px solid var(--border);
      border-radius: 14px;
      color: var(--text-1);
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      text-align: center;
      line-height: 1.3;
    }
    .action-tile .tile-icon {
      width: 44px; height: 44px;
      border-radius: 12px;
      background: var(--red-soft);
      color: var(--red);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      transition: all 0.2s ease;
    }
    .action-tile:hover {
      transform: translateY(-2px);
      border-color: rgba(239, 68, 68, 0.4);
      box-shadow: 0 6px 16px rgba(239, 68, 68, 0.12);
    }
    .action-tile:hover .tile-icon {
      background: var(--red);
      color: #fff;
    }
    .action-tile:active { transform: translateY(0); }

    /* Yesil tonlu aksiyonlar (Excel) */
    .action-tile.tone-green .tile-icon {
      background: #ecfdf5;
      color: var(--emerald);
    }
    .action-tile.tone-green:hover {
      border-color: rgba(5, 150, 105, 0.4);
      box-shadow: 0 6px 16px rgba(5, 150, 105, 0.12);
    }
    .action-tile.tone-green:hover .tile-icon {
      background: var(--emerald);
      color: #fff;
    }

    /* ═══════ ANIMATIONS ═══════ */
    @keyframes cardIn {
      from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
      to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
    }

    /* ═══════ MOBILE ═══════ */
    @media (max-width: 767px) {
      .top-header { height: 50px; }
      .header-inner { padding: 0 12px; gap: 10px; }
      .header-title { font-size: 15px; }
      .header-back { width: 30px; height: 30px; border-radius: 8px; }
      .header-divider { height: 18px; }

      main { padding: 14px 12px 40px !important; }
      .glass-card { padding: 16px; border-radius: 12px; }
      .card-head { gap: 10px; margin-bottom: 12px; }
      .card-head .icon-box { width: 36px; height: 36px; font-size: 14px; }
      .card-head h2 { font-size: 14px; }
      .field-control { font-size: 16px; padding: 10px 12px; } /* iOS zoom engeli */
      textarea.field-control { min-height: 72px; }
      .btn-flat { font-size: 13px; padding: 11px 16px; }
      .btn-flat:hover { transform: none; }

      .action-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
      .action-tile { padding: 14px 8px; font-size: 11px; gap: 8px; }
      .action-tile .tile-icon { width: 38px; height: 38px; font-size: 15px; }
      .action-tile:hover { transform: none; }
    }
    @media (max-width: 360px) {
      .action-tile { padding: 12px 6px; }
    }
  </style>
</head>
<body>

  <header class="top-header">
    <div class="header-inner">
      <a href="lg_fis.php?stokhareket=<?php echo $stokhareket; ?>"
         class="header-back" title="Siparis Detayina Geri Don">
        <i class="fa fa-arrow-left"></i>
      </a>
      <div class="header-divider"></div>
      <span class="header-title">
        <i class="fa-solid fa-file-pen"></i>Siparis Islemleri
      </span>
    </div>
  </header>

  <main style="max-width:720px;margin:0 auto;padding:24px 20px 60px;">
    <form method="POST" action="">
      <input type="hidden" name="stokhareket" value="<?php echo $stokhareket; ?>">

      <!-- Aciklamalar -->
      <section class="glass-card">
        <div class="card-head">
          <span class="icon-box"><i class="fa-solid fa-pencil"></i></span>
          <div>
            <h2>Siparis Aciklamalari</h2>
            <p>Bu alandaki degisiklikler tum yazdirma ve kaydetme islemlerinde uygulanir.</p>
          </div>
        </div>

        <div class="field">
          <label class="field-label" for="aciklama1">1. Aciklama</label>
          <textarea name="aciklama1" id="aciklama1" rows="3" class="field-control"><?php
            echo htmlspecialchars((string) $eyne['GENEXP1'], ENT_QUOTES);
          ?></textarea>
        </div>

        <div class="field">
          <label class="field-label" for="aciklama2">2. Aciklama</label>
          <textarea name="aciklama2" id="aciklama2" rows="3" class="field-control"><?php
            echo htmlspecialchars((string) $eyne['GENEXP2'], ENT_QUOTES);
          ?></textarea>
        </div>

        <div class="save-row">
          <button type="submit" name="action" value="save_only" class="btn-flat btn-indigo">
            <i class="fa-solid fa-floppy-disk"></i> Aciklamalari Kaydet
          </button>
        </div>
      </section>

      <!-- Yazdirma / Disa Aktarma -->
      <section class="glass-card">
        <div class="card-head">
          <span class="icon-box"><i class="fa-solid fa-print"></i></span>
          <div>
            <h2>Yazdirma ve Disa Aktarma</h2>
            <p>Bir islem sectiginizde aciklamalar otomatik olarak kaydedilir.</p>
          </div>
        </div>

        <div class="action-grid">
          <?php foreach ($printActions as $btn):
              if (empty($btn['show'])) continue;
              $toneClass = ($btn['tone'] ?? '') === 'green' ? ' tone-green' : '';
          ?>
            <button type="submit" name="action" value="<?php echo $btn['action']; ?>" class="action-tile<?php echo $toneClass; ?>">
              <span class="tile-icon"><i class="fa-solid <?php echo $btn['icon']; ?>"></i></span>
              <span><?php echo $btn['label']; ?></span>
            </button>
          <?php endforeach; ?>
        </div>
      </section>

    </form>
  </main>

  <script>
    // Animation render bug fix: force reflow
    window.addEventListener('load', function(){
      requestAnimationFrame(function(){
        document.querySelectorAll('.glass-card').forEach(function(el){ void el.offsetHeight; });
      });
    });
  </script>
</body>
</html>

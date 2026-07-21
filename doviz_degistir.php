<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';

$stokhareket = isset($_GET['stokhareket']) ? (int)$_GET['stokhareket'] : (isset($_POST['stokhareket']) ? (int)$_POST['stokhareket'] : 0);
$mesaj = '';
$mesaj_tip = '';

// Yetki: yalnizca bu fisi gorebilen kullanici dovizini degistirebilir
if ($stokhareket > 0 && !m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Form gönderildiyse döviz değiştir
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        http_response_code(403);
        die('Gecersiz guvenlik dogrulamasi. Sayfayi yenileyip tekrar deneyin.');
    }
    $yeni_trcode = isset($_POST['trcode']) ? (int)$_POST['trcode'] : 1;
    $yeni_trrate = isset($_POST['trrate']) ? (float)$_POST['trrate'] : 1;

    if ($stokhareket > 0) {
        try {
            // ORFICHE'yi güncelle
            $stmt = $dbh->prepare("
                UPDATE {$firmadonem}ORFICHE
                SET TRCODE = :trcode,
                    TRRATE = :trrate
                WHERE LOGICALREF = :stokhareket
            ");
            $stmt->execute([
                ':trcode' => $yeni_trcode,
                ':trrate' => $yeni_trrate,
                ':stokhareket' => $stokhareket
            ]);

            // SESSION'ı güncelle
            $_SESSION['doviz'] = $yeni_trcode;

            $mesaj = "Döviz başarıyla değiştirildi!";
            $mesaj_tip = "success";

            // 2 saniye sonra siparis/lg_fis.php'ye yönlendir
            header("refresh:2;url=siparis/lg_fis.php?stokhareket=$stokhareket");

        } catch (Exception $e) {
            error_log('doviz_degistir hata: ' . $e->getMessage());
            $mesaj = "Doviz degistirilemedi.";
            $mesaj_tip = "error";
        }
    }
}

// Mevcut fişin bilgilerini çek
$fis = null;
if ($stokhareket > 0) {
    try {
        $stmt = $dbh->prepare("
            SELECT
                LOGICALREF,
                FICHENO,
                TRCODE,
                TRRATE,
                GROSSTOTAL,
                NETTOTAL
            FROM {$firmadonem}ORFICHE
            WHERE LOGICALREF = :stokhareket
        ");
        $stmt->execute([':stokhareket' => $stokhareket]);
        $fis = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('doviz_degistir fis sorgu hata: ' . $e->getMessage());
        die("Fis bulunamadi.");
    }
}

if (!$fis) {
    die("Fiş bulunamadı!");
}

$doviz_tipler = [
    1 => ['ad' => 'TL', 'sembol' => '₺', 'tam_ad' => 'Türk Lirası', 'icon' => 'fa-turkish-lira-sign', 'varsayilan_kur' => 1],
    20 => ['ad' => 'USD', 'sembol' => '$', 'tam_ad' => 'Amerikan Doları', 'icon' => 'fa-dollar-sign', 'varsayilan_kur' => 43],
    21 => ['ad' => 'EUR', 'sembol' => '€', 'tam_ad' => 'Euro', 'icon' => 'fa-euro-sign', 'varsayilan_kur' => 45],
    22 => ['ad' => 'GBP', 'sembol' => '£', 'tam_ad' => 'İngiliz Sterlini', 'icon' => 'fa-sterling-sign', 'varsayilan_kur' => 50],
];

$mevcut_trcode = (int)$fis['TRCODE'];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Döviz Seç</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <script src="tailwind.local.js" onerror="var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);"></script>
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include __DIR__ . '/pwa-header.php'; } ?>
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
            --emerald-soft: #ecfdf5;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --amber: #d97706;
            --amber-soft: #fffbeb;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            margin: 0;
            padding: 0;
            -webkit-font-smoothing: antialiased;
        }

        .top-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: saturate(180%) blur(14px);
            -webkit-backdrop-filter: saturate(180%) blur(14px);
            border-bottom: 1px solid var(--border);
        }

        .top-header-inner {
            max-width: 760px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 14px 18px;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-1);
            text-decoration: none;
            transition: all .18s ease;
            flex-shrink: 0;
        }

        .back-btn:hover {
            border-color: var(--text-3);
            transform: translateX(-2px);
        }

        .header-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: var(--sky-soft);
            color: var(--sky);
            font-size: 18px;
            flex-shrink: 0;
        }

        .header-titles { flex: 1; min-width: 0; }
        .header-title {
            font-size: 17px;
            font-weight: 600;
            color: var(--text-1);
            letter-spacing: -0.01em;
            line-height: 1.2;
        }
        .header-subtitle {
            font-size: 12px;
            color: var(--text-2);
            margin-top: 2px;
        }

        main {
            max-width: 760px;
            margin: 0 auto;
            padding: 22px 18px 60px;
        }

        .alert {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 14px;
            margin-bottom: 18px;
            font-size: 14px;
            font-weight: 500;
        }
        .alert i { font-size: 18px; margin-top: 1px; }
        .alert-success {
            background: var(--emerald-soft);
            color: var(--emerald);
            border: 1px solid #a7f3d0;
        }
        .alert-error {
            background: var(--red-soft);
            color: var(--red);
            border: 1px solid #fecaca;
        }
        .alert-meta {
            font-size: 12px;
            opacity: 0.8;
            margin-top: 2px;
            font-weight: 400;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: saturate(180%) blur(10px);
            -webkit-backdrop-filter: saturate(180%) blur(10px);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 18px 20px;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
        }

        .section-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin: 0 0 12px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .info-label {
            font-size: 11px;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 500;
        }
        .info-value {
            font-size: 14px;
            color: var(--text-1);
            font-weight: 600;
        }

        .currency-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }

        .currency-tile {
            background: rgba(255, 255, 255, 0.8);
            backdrop-filter: saturate(180%) blur(10px);
            -webkit-backdrop-filter: saturate(180%) blur(10px);
            border: 1.5px solid var(--border);
            border-radius: 18px;
            padding: 20px 18px;
            cursor: pointer;
            transition: all .2s ease;
            display: flex;
            align-items: center;
            gap: 14px;
            position: relative;
            text-align: left;
            font-family: inherit;
            width: 100%;
        }
        .currency-tile:hover {
            border-color: var(--sky);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.05);
        }
        .currency-tile.is-active {
            border-color: var(--red);
            background: var(--red-soft);
        }
        .currency-tile.is-active .currency-icon {
            background: var(--red);
            color: #fff;
        }

        .currency-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: var(--sky-soft);
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            transition: all .2s ease;
        }
        .currency-meta { flex: 1; min-width: 0; }
        .currency-code {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-1);
            line-height: 1.2;
        }
        .currency-name {
            font-size: 12px;
            color: var(--text-2);
            margin-top: 2px;
        }
        .currency-check {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--red);
            color: #fff;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 11px;
        }
        .currency-tile.is-active .currency-check { display: inline-flex; }

        .field-label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .field-label .req { color: var(--red); }

        .field-input {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            font-family: inherit;
            font-size: 15px;
            color: var(--text-1);
            transition: all .18s ease;
        }
        .field-input:focus {
            outline: none;
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(111, 16, 34, 0.12);
        }
        .field-hint {
            font-size: 12px;
            color: var(--text-3);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .notice {
            background: var(--amber-soft);
            border: 1px solid #fde68a;
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 18px;
        }
        .notice-title {
            font-size: 13px;
            font-weight: 600;
            color: var(--amber);
            margin: 0 0 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .notice-list {
            margin: 0;
            padding-left: 18px;
            font-size: 12.5px;
            color: #92400e;
            line-height: 1.6;
        }

        .actions {
            display: flex;
            gap: 12px;
            margin-top: 4px;
        }

        .btn {
            flex: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 13px 18px;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
            font-family: inherit;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all .18s ease;
        }
        .btn-primary {
            background: var(--red);
            color: #fff;
        }
        .btn-primary:hover {
            background: #b91c1c;
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(111, 16, 34, 0.25);
        }
        .btn-secondary {
            background: #fff;
            color: var(--text-1);
            border-color: var(--border);
        }
        .btn-secondary:hover {
            border-color: var(--text-3);
            background: #f9fafb;
        }

        @media (max-width: 540px) {
            .currency-grid {
                grid-template-columns: 1fr;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .actions {
                flex-direction: column;
            }
            main {
                padding: 18px 14px 60px;
            }
        }
    </style>
</head>
<body>
    <header class="top-header">
        <div class="top-header-inner">
            <a href="siparis/lg_fis.php?stokhareket=<?php echo $stokhareket; ?>" class="back-btn" aria-label="Geri">
                <i class="fas fa-arrow-left"></i>
            </a>
            <span class="header-icon"><i class="fas fa-money-bill-transfer"></i></span>
            <div class="header-titles">
                <div class="header-title">Döviz Seç</div>
                <div class="header-subtitle">Fiş No: <?php echo htmlspecialchars((string)$fis['FICHENO']); ?></div>
            </div>
        </div>
    </header>

    <main>
        <?php if ($mesaj): ?>
            <div class="alert <?php echo $mesaj_tip === 'success' ? 'alert-success' : 'alert-error'; ?>">
                <i class="fas fa-<?php echo $mesaj_tip === 'success' ? 'circle-check' : 'circle-exclamation'; ?>"></i>
                <div>
                    <div><?php echo htmlspecialchars($mesaj); ?></div>
                    <?php if ($mesaj_tip === 'success'): ?>
                        <div class="alert-meta">Yönlendiriliyorsunuz...</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <section class="glass-card">
            <h2 class="section-title">Mevcut Fiş Bilgileri</h2>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Fiş No</span>
                    <span class="info-value"><?php echo htmlspecialchars((string)$fis['FICHENO']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Brüt Toplam</span>
                    <span class="info-value"><?php echo number_format((float)$fis['GROSSTOTAL'], 2); ?> TL</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Döviz Tipi</span>
                    <span class="info-value">
                        <?php echo isset($doviz_tipler[$mevcut_trcode]) ? ($doviz_tipler[$mevcut_trcode]['ad'] . ' (' . $doviz_tipler[$mevcut_trcode]['sembol'] . ')') : "Bilinmiyor ($mevcut_trcode)"; ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Döviz Kuru</span>
                    <span class="info-value"><?php echo number_format((float)$fis['TRRATE'], 2); ?></span>
                </div>
            </div>
        </section>

        <form method="POST" id="dovizForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="stokhareket" value="<?php echo $stokhareket; ?>">
            <input type="hidden" name="trcode" id="trcode" value="<?php echo $mevcut_trcode; ?>">

            <h2 class="section-title">Yeni Döviz Seçin</h2>
            <div class="currency-grid">
                <?php foreach ($doviz_tipler as $kod => $bilgi): ?>
                    <button type="button"
                            class="currency-tile<?php echo $kod === $mevcut_trcode ? ' is-active' : ''; ?>"
                            data-trcode="<?php echo $kod; ?>"
                            data-kur="<?php echo $bilgi['varsayilan_kur']; ?>">
                        <span class="currency-icon">
                            <i class="fas <?php echo $bilgi['icon']; ?>"></i>
                        </span>
                        <span class="currency-meta">
                            <span class="currency-code"><?php echo $bilgi['ad']; ?> (<?php echo $bilgi['sembol']; ?>)</span>
                            <span class="currency-name"><?php echo $bilgi['tam_ad']; ?></span>
                        </span>
                        <span class="currency-check"><i class="fas fa-check"></i></span>
                    </button>
                <?php endforeach; ?>
            </div>

            <section class="glass-card">
                <label class="field-label" for="trrate">Döviz Kuru <span class="req">*</span></label>
                <input type="number" name="trrate" id="trrate" step="0.01" min="0.01"
                       value="<?php echo (float)$fis['TRRATE']; ?>" required
                       class="field-input">
                <p class="field-hint">
                    <i class="fas fa-circle-info"></i> TL için 1, döviz için güncel kuru girin
                </p>
            </section>

            <div class="notice">
                <p class="notice-title">
                    <i class="fas fa-triangle-exclamation"></i> Uyarı
                </p>
                <ul class="notice-list">
                    <li>Döviz değiştirildiğinde <strong>sadece TRCODE ve TRRATE</strong> güncellenir</li>
                    <li>Satır fiyatları değişmez, sadece görüntüleme değişir</li>
                    <li>Fiyatları yeniden girmek isterseniz satırları düzenlemelisiniz</li>
                </ul>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-check"></i> Döviz Değiştir
                </button>
                <a href="siparis/lg_fis.php?stokhareket=<?php echo $stokhareket; ?>" class="btn btn-secondary">
                    <i class="fas fa-xmark"></i> İptal
                </a>
            </div>
        </form>
    </main>

    <script>
        (function () {
            const tiles = document.querySelectorAll('.currency-tile');
            const trcodeInput = document.getElementById('trcode');
            const trrateInput = document.getElementById('trrate');

            tiles.forEach(function (tile) {
                tile.addEventListener('click', function () {
                    tiles.forEach(function (t) { t.classList.remove('is-active'); });
                    tile.classList.add('is-active');

                    const trcode = tile.getAttribute('data-trcode');
                    const kur = tile.getAttribute('data-kur');
                    if (trcode) trcodeInput.value = trcode;
                    if (kur) trrateInput.value = kur;
                });
            });
        })();
    </script>
</body>
</html>

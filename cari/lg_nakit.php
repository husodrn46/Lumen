<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../log_ip.php");
include_once(__DIR__ . "/../ayr.php"); // $dbh, $firma, $firmadonem, tarihcevir()

// Dönem kontrolü (2025 dönemi için eski tabloları kullan)
$donemParam = isset($_GET['donem']) ? $_GET['donem'] : '';
if ($donemParam === '2025' && isset($eskifirmadonem)) {
    $firmadonem = $eskifirmadonem;
}

// 1) parametreler
if (isset($_GET['cari_id']) && isset($_GET['REF'])) {
    $CARIID = (int)$_GET['cari_id'];
    $REF    = (int)$_GET['REF'];
} else {
    die("Parametre eksik.");
}
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $CARIID, 'M4')) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// 2) para format
function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat;
    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0;
    }
    return number_format((float)$kusurat, (int)($parakusurat ?? 2), ',', '.');
}

$mdoviz = "₺";

// 3) KASA SATIRI
try {
    $qKs = $dbh->prepare("SELECT * FROM {$firmadonem}KSLINES WHERE LOGICALREF = :ref");
    $qKs->execute([':ref' => $REF]);
    $ks = $qKs->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[lg_nakit] DB hatasi: ' . $e->getMessage());
    exit('Veritabani islem hatasi.');
}
if (!$ks) {
    die("Bu LOGICALREF için kasa hareketi bulunamadı.");
}

// 4) KASA KARTI (sende sadece CARDREF var)
$kasa = false;
$kasaRef = (isset($ks['CARDREF']) && (int)$ks['CARDREF'] > 0) ? (int)$ks['CARDREF'] : 0;

if ($kasaRef > 0) {
    // burada sadece * seçiyoruz ki hangi kolon varsa onu kullanalım
    try {
        $qKasa = $dbh->prepare("SELECT * FROM {$firma}KSCARD WHERE LOGICALREF = :kasaRef");
        $qKasa->execute([':kasaRef' => $kasaRef]);
        $kasa = $qKasa->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[lg_nakit] DB hatasi: ' . $e->getMessage());
        exit('Veritabani islem hatasi.');
    }
}

// 5) ilgili cari satırı (nakit tahsilat/ödeme)
try {
    $qCl = $dbh->prepare("
        SELECT *
        FROM {$firmadonem}CLFLINE
        WHERE CLIENTREF  = :cariid
          AND SOURCEFREF = :ref
          AND TRCODE IN (1,2)
          AND CANCELLED = 0
        ORDER BY LOGICALREF DESC
    ");
    $qCl->execute([':cariid' => $CARIID, ':ref' => $REF]);
    $cl = $qCl->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[lg_nakit] DB hatasi: ' . $e->getMessage());
    exit('Veritabani islem hatasi.');
}

// 6) cari kart
try {
    $qCari = $dbh->prepare("
        SELECT DEFINITION_, CITY, TELNRS1
        FROM {$firma}CLCARD
        WHERE LOGICALREF = :cariid
    ");
    $qCari->execute([':cariid' => $CARIID]);
    $cari = $qCari->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[lg_nakit] DB hatasi: ' . $e->getMessage());
    exit('Veritabani islem hatasi.');
}

// 7) başlık + tutar + açıklama
$trtext = 'Nakit İşlem';
if ($cl) {
    if ((int)$cl['TRCODE'] === 1) {
        $trtext = 'Nakit Tahsilat';
    }
    if ((int)$cl['TRCODE'] === 2) {
        $trtext = 'Nakit Ödeme';
    }
}
$goster_tutar = $cl ? $cl['AMOUNT'] : ($ks['AMOUNT'] ?? 0);
$aciklama = $cl && !empty($cl['LINEEXP'])
    ? $cl['LINEEXP']
    : ($ks['LINEEXP'] ?? '');

// 8) kasa adını elde et
$kasaKod = '-';
$kasaAd  = '';
if ($kasa) {
    // code her sürümde var
    if (isset($kasa['CODE'])) {
        $kasaKod = $kasa['CODE'];
    }
    // isim kolonu sürüme göre değişiyormuş
    if (isset($kasa['DEFINITION_'])) {
        $kasaAd = $kasa['DEFINITION_'];
    } elseif (isset($kasa['DEFINITION'])) {
        $kasaAd = $kasa['DEFINITION'];
    } elseif (isset($kasa['NAME'])) {
        $kasaAd = $kasa['NAME'];
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($trtext); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
            --sky: #0284c7;
            --sky-soft: #eff6ff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(5, 150, 105, 0.18);
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.04);
        }
        .header-inner {
            max-width: 820px; margin: 0 auto;
            padding: 12px 22px;
            display: flex; align-items: center; gap: 12px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 34px; height: 34px; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            transition: all 0.2s ease;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--emerald); }
        .header-divider { width: 1px; height: 22px; background: var(--border); }
        .header-title {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 7px;
            flex: 1 1 auto;
        }
        .header-title i { color: var(--emerald); font-size: 14px; }
        .btn-print {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 14px; background: var(--red,#ef4444); color: #fff;
            border: none; border-radius: 9px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        .btn-print:hover { background: var(--red); transform: translateY(-1px); }

        main {
            max-width: 820px;
            margin: 24px auto;
            padding: 0 22px 50px;
        }

        .document {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(5, 150, 105, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 36px 40px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .doc-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            padding-bottom: 16px;
            border-bottom: 2px solid var(--emerald);
            margin-bottom: 26px;
            gap: 20px;
        }
        .doc-title {
            font-size: 26px;
            font-weight: 800;
            color: var(--emerald);
            letter-spacing: 0.6px;
            line-height: 1.1;
            margin: 0;
            text-transform: uppercase;
        }
        .doc-subtitle {
            font-size: 13px;
            color: var(--text-2);
            margin-top: 4px;
            font-weight: 500;
        }
        .doc-meta { text-align: right; }
        .doc-meta-row {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-bottom: 4px;
            align-items: baseline;
        }
        .doc-meta-row:last-child { margin-bottom: 0; }
        .doc-meta-row .lbl {
            color: var(--text-2);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            font-size: 10px;
        }
        .doc-meta-row .val {
            color: var(--text-1);
            font-weight: 700;
            font-size: 13px;
            font-variant-numeric: tabular-nums;
        }

        .doc-section { margin-bottom: 24px; }
        .doc-section-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: var(--emerald);
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid rgba(5, 150, 105, 0.2);
        }

        .customer-block .firma {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-1);
            margin-bottom: 8px;
        }
        .customer-block .fields {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 6px 20px;
            font-size: 12.5px;
        }
        .customer-block .field .lbl {
            color: var(--text-2);
            font-weight: 500;
            margin-right: 6px;
        }
        .customer-block .field .val {
            color: var(--text-1);
            font-weight: 600;
            word-break: break-word;
        }

        /* Tutar bloğu */
        .amount-block {
            text-align: center;
            padding: 26px 20px;
            margin: 8px 0 24px;
            background: var(--emerald-soft);
            border: 1px solid rgba(5, 150, 105, 0.22);
            border-radius: 12px;
            border-top: 2px solid var(--emerald);
            border-bottom: 4px double var(--emerald);
        }
        .amount-block .a-lbl {
            font-size: 10.5px;
            color: var(--emerald);
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 700;
            margin-bottom: 8px;
            display: block;
        }
        .amount-block .a-val {
            font-size: 34px;
            font-weight: 800;
            color: var(--emerald);
            font-variant-numeric: tabular-nums;
            letter-spacing: 0.3px;
        }

        .aciklama-text {
            font-size: 13px;
            color: var(--text-1);
            font-weight: 500;
            line-height: 1.5;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 767px) {
            .header-inner { padding: 10px 14px; gap: 10px; }
            .header-title { font-size: 13px; }
            .btn-print { padding: 7px 12px; font-size: 11.5px; }

            main { margin: 14px auto; padding: 0 12px 40px; }
            .document { padding: 22px 20px; border-radius: 14px; }

            .doc-title { font-size: 22px; }
            .customer-block .fields { grid-template-columns: 1fr; }
            .amount-block { padding: 20px 16px; }
            .amount-block .a-val { font-size: 26px; }
        }

        @media (max-width: 600px) {
            .doc-head {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            .doc-meta { text-align: left; width: 100%; }
            .doc-meta-row { justify-content: flex-start; gap: 8px; }
        }

        @page { size: A4; margin: 14mm 12mm; }
        @media print {
            body {
                background: #fff;
                font-size: 11px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .top-header, .btn-print, .header-back { display: none !important; }
            main { margin: 0; padding: 0; max-width: none; }
            .document {
                border: 1px solid #999;
                background: #fff;
                box-shadow: none;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                padding: 0;
                animation: none;
                border-radius: 0;
            }
            .doc-head { margin-bottom: 14px; padding-bottom: 10px; }
            .doc-title { font-size: 20px; }
            .doc-subtitle { font-size: 11px; }
            .doc-section { margin-bottom: 14px; }
            .doc-section-title { margin-bottom: 6px; font-size: 9px; }
            .customer-block .firma { font-size: 14px; margin-bottom: 5px; }
            .customer-block .fields { font-size: 10.5px; gap: 3px 14px; grid-template-columns: repeat(3, 1fr); }
            .amount-block {
                padding: 18px 10px; margin: 6px 0 14px;
                background: var(--emerald-soft) !important;
                border-radius: 8px;
            }
            .amount-block .a-lbl { font-size: 9px; }
            .amount-block .a-val { font-size: 24px; }
            .aciklama-text { font-size: 10.5px; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="lg_hareket.php?cariid=<?php echo (int)$CARIID; ?>" class="header-back" title="Cari Hareket Sayfasina Geri Don">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-money-bill-wave"></i><?php echo htmlspecialchars($trtext); ?>
            </span>
            <button onclick="window.print()" class="btn-print" type="button">
                <i class="fa-solid fa-print"></i> PDF Kaydet
            </button>
        </div>
    </header>

    <main>
        <div class="document">
            <div class="doc-head">
                <div>
                    <h1 class="doc-title"><?php echo htmlspecialchars($trtext); ?></h1>
                    <div class="doc-subtitle">Kasa Hareketi</div>
                </div>
                <div class="doc-meta">
                    <div class="doc-meta-row">
                        <span class="lbl">Belge No</span>
                        <span class="val"><?php echo isset($ks['FICHENO']) ? htmlspecialchars((string) $ks['FICHENO']) : '-'; ?></span>
                    </div>
                    <div class="doc-meta-row">
                        <span class="lbl">Tarih</span>
                        <span class="val"><?php echo isset($ks['DATE_']) ? tarihcevir($ks['DATE_']) : '-'; ?></span>
                    </div>
                </div>
            </div>

            <div class="doc-section customer-block">
                <div class="doc-section-title">Cari</div>
                <div class="firma"><?php echo ($cari && isset($cari['DEFINITION_'])) ? htmlspecialchars((string) $cari['DEFINITION_']) : '-'; ?></div>
                <div class="fields">
                    <div class="field">
                        <span class="lbl">Sehir:</span>
                        <span class="val"><?php echo !empty($cari['CITY'] ?? '') ? htmlspecialchars((string) $cari['CITY']) : '-'; ?></span>
                    </div>
                    <div class="field">
                        <span class="lbl">Telefon:</span>
                        <span class="val"><?php echo !empty($cari['TELNRS1'] ?? '') ? htmlspecialchars((string) $cari['TELNRS1']) : '-'; ?></span>
                    </div>
                    <div class="field">
                        <span class="lbl">Kasa:</span>
                        <span class="val">
                            <?php
                            if ($kasa) {
                                echo $kasaAd !== ''
                                    ? htmlspecialchars($kasaKod . ' — ' . $kasaAd)
                                    : htmlspecialchars((string) $kasaKod);
                            } else {
                                echo '-';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="amount-block">
                <span class="a-lbl">Tutar</span>
                <span class="a-val"><?php echo paraformat($goster_tutar); ?> <?php echo $mdoviz; ?></span>
            </div>

            <?php if (trim((string)$aciklama) !== ''): ?>
            <div class="doc-section">
                <div class="doc-section-title">Aciklama</div>
                <div class="aciklama-text"><?php echo nl2br(htmlspecialchars((string) $aciklama)); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </main>

</body>
</html>

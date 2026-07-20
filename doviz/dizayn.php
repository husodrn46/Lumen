<?php
declare(strict_types=1);

/**
 * Döviz Modülü - Yazdırma Yöneticisi
 * Dövizli siparişler için dizayn seçimi ve yazdırma kuyruğu
 */

include_once __DIR__ . "/../ayr.php";
include_once __DIR__ . "/../log_ip.php";
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/doviz_guard.php';

doviz_require_m21($terminalkullanici);

// Hata loglama fonksiyonu
function logError(string $message): void {
    file_put_contents(__DIR__ . "/../logs/doviz_dizayn_error.txt",
        date("Y-m-d H:i:s") . " - HATA: " . $message . "\n",
        FILE_APPEND
    );
}

// Sipariş ID'sini al
$stokhareket = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($stokhareket <= 0) {
    die('<script>alert("Geçersiz sipariş numarası!"); window.location="siparisler.php";</script>');
}

// Sipariş bilgilerini çek (döviz kontrolü dahil)
$stmtFis = $dbh->prepare("
    SELECT F.LOGICALREF, F.FICHENO, F.TRCURR, F.TRRATE, F.GENEXP1, F.GENEXP2,
           C.DEFINITION_ AS CARI_ISIM, C.EMAILADDR AS MAIL
    FROM {$firmadonem}ORFICHE F
    LEFT JOIN {$firma}CLCARD C ON C.LOGICALREF = F.CLIENTREF
    WHERE F.LOGICALREF = :id AND F.TRCODE = 1
");
$stmtFis->execute([':id' => $stokhareket]);
$siparis = $stmtFis->fetch(PDO::FETCH_ASSOC);

if (!$siparis) {
    die('<script>alert("Sipariş bulunamadı!"); window.location="siparisler.php";</script>');
}

// Döviz kontrolü
$trcurr = (int)$siparis['TRCURR'];
$trrate = (float)$siparis['TRRATE'];

if ($trcurr == 0 || $trrate <= 0) {
    die('<script>alert("Bu sipariş dövizli değil!"); window.location="fis.php?id=' . $stokhareket . '";</script>');
}

// LOGO döviz kodları: 1=USD, 20=EUR
$dovizBilgileri = [
    1 => ['kod' => 'USD', 'sembol' => '$'],
    20 => ['kod' => 'EUR', 'sembol' => '€'],
];
$doviz = $dovizBilgileri[$trcurr] ?? ['kod' => 'DV', 'sembol' => '?'];

// Form gönderildi mi kontrol et
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $miktar = isset($_POST['miktar']) ? intval($_POST['miktar']) : 1;
    $yazici = $_POST['yazici'] ?? '';
    $dizayn = $_POST['dizayn'] ?? '';
    $lokasyon = isset($_POST['lokasyon']) ? intval($_POST['lokasyon']) : 1;
    $aciklama = $_POST['aciklama'] ?? '';
    $aciklama2 = $_POST['aciklama2'] ?? '';

    $islem_basarili = false;
    $hata_mesaji = '';

    try {
        // Açıklamaları güncelle
        $stmt_update = $dbh->prepare("
            UPDATE {$firmadonem}ORFICHE
            SET GENEXP1 = :aciklama, GENEXP2 = :aciklama2
            WHERE LOGICALREF = :stokhareket
        ");
        $stmt_update->execute([
            ':aciklama' => $aciklama,
            ':aciklama2' => $aciklama2,
            ':stokhareket' => $stokhareket,
        ]);

        // M_MOBIL_DIZAYN tablosunun var olup olmadığını kontrol et
        $tableCheck = $dbh->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'M_MOBIL_DIZAYN'");
        $tableExists = $tableCheck->fetchColumn() > 0;

        if (!$tableExists) {
            $hata_mesaji = "M_MOBIL_DIZAYN tablosu bulunamadı.";
            logError("M_MOBIL_DIZAYN tablosu yok!");
        } else {
            // Sütunların var olup olmadığını kontrol et
            $columnCheck = $dbh->query("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_NAME = 'M_MOBIL_DIZAYN'
            ");
            $existingColumns = [];
            while ($col = $columnCheck->fetch(PDO::FETCH_ASSOC)) {
                $existingColumns[] = $col['COLUMN_NAME'];
            }

            // Mevcut sütunlara göre INSERT sorgusu oluştur
            $columns = ['DURUM', 'DIZAYN', 'MIKTAR', 'FIS', 'ISLEM', 'YAZICI', 'LOKASYON', 'MAIL'];
            $values = [':durum', ':dizayn', ':miktar', ':fis', ':islem', ':yazici', ':lokasyon', ':mail'];
            $params = [
                ':durum' => 0,
                ':dizayn' => $dizayn,
                ':miktar' => $miktar,
                ':fis' => $stokhareket,
                ':islem' => 1, // Fiş yazdırma
                ':yazici' => $yazici,
                ':lokasyon' => $lokasyon,
                ':mail' => $siparis['MAIL'] ?? ''
            ];

            // KULLANICI sütunu varsa ekle
            if (in_array('KULLANICI', $existingColumns)) {
                $columns[] = 'KULLANICI';
                $values[] = ':kullanici';
                $params[':kullanici'] = $terminalkullanici;
            }

            // TARIH sütunu varsa ekle
            if (in_array('TARIH', $existingColumns)) {
                $columns[] = 'TARIH';
                $values[] = 'GETDATE()';
            }

            // TRCODE sütunu varsa ekle (döviz tipi)
            if (in_array('TRCODE', $existingColumns)) {
                $columns[] = 'TRCODE';
                $values[] = ':trcode';
                $params[':trcode'] = $trcurr;
            }

            // TRRATE sütunu varsa ekle (döviz kuru)
            if (in_array('TRRATE', $existingColumns)) {
                $columns[] = 'TRRATE';
                $values[] = ':trrate';
                $params[':trrate'] = $trrate;
            }

            $sql = "INSERT INTO M_MOBIL_DIZAYN (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ")";
            $stmt_insert = $dbh->prepare($sql);
            $stmt_insert->execute($params);

            // M_MOBIL_DIZAYN_LOG tablosuna da ekle
            $tableCheckLog = $dbh->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'M_MOBIL_DIZAYN_LOG'");
            $tableLogExists = $tableCheckLog->fetchColumn() > 0;

            if ($tableLogExists) {
                $columnCheckLog = $dbh->query("
                    SELECT COLUMN_NAME
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_NAME = 'M_MOBIL_DIZAYN_LOG'
                ");
                $existingColumnsLog = [];
                while ($col = $columnCheckLog->fetch(PDO::FETCH_ASSOC)) {
                    $existingColumnsLog[] = $col['COLUMN_NAME'];
                }

                $columnsLog = ['FIS', 'KULLANICI', 'DIZAYN', 'MIKTAR', 'ISLEM', 'YAZICI', 'LOKASYON'];
                $valuesLog = [':fis', ':kullanici', ':dizayn', ':miktar', ':islem', ':yazici', ':lokasyon'];
                $paramsLog = [
                    ':fis' => $stokhareket,
                    ':kullanici' => $terminalkullanici,
                    ':dizayn' => $dizayn,
                    ':miktar' => $miktar,
                    ':islem' => 1,
                    ':yazici' => $yazici,
                    ':lokasyon' => $lokasyon,
                ];

                if (in_array('TARIH', $existingColumnsLog)) {
                    $columnsLog[] = 'TARIH';
                    $valuesLog[] = 'GETDATE()';
                }

                if (in_array('TRCODE', $existingColumnsLog)) {
                    $columnsLog[] = 'TRCODE';
                    $valuesLog[] = ':trcode';
                    $paramsLog[':trcode'] = $trcurr;
                }

                if (in_array('TRRATE', $existingColumnsLog)) {
                    $columnsLog[] = 'TRRATE';
                    $valuesLog[] = ':trrate';
                    $paramsLog[':trrate'] = $trrate;
                }

                if (in_array('FICHENO', $existingColumnsLog)) {
                    $columnsLog[] = 'FICHENO';
                    $valuesLog[] = ':ficheno';
                    $paramsLog[':ficheno'] = $siparis['FICHENO'];
                }

                $sqlLog = "INSERT INTO M_MOBIL_DIZAYN_LOG (" . implode(', ', $columnsLog) . ") VALUES (" . implode(', ', $valuesLog) . ")";
                $stmt_log = $dbh->prepare($sqlLog);
                $stmt_log->execute($paramsLog);
            }

            $islem_basarili = true;
        }

    } catch (PDOException $e) {
        $hata_mesaji = "Veritabanı hatası: " . $e->getMessage();
        logError($hata_mesaji);
    }

    // Hata varsa session'a kaydet
    if (!empty($hata_mesaji)) {
        $_SESSION['yazdirma_hata'] = $hata_mesaji;
    } elseif ($islem_basarili) {
        $_SESSION['yazdirma_basarili'] = "Yazdırma kuyruğuna başarıyla eklendi.";
    }

    // İşlem bittikten sonra yönlendir
    header("Location: fis.php?id=" . $stokhareket);
    exit;
}

$lokasyon = 1;
$miktar = 1;

// Sayfa durumu
$cari_isim = (string)($siparis['CARI_ISIM'] ?? '-');
$ficheno = (string)($siparis['FICHENO'] ?? '');
$email_addr = trim((string)($siparis['MAIL'] ?? ''));
$has_email = $email_addr !== '';

// Hata bannerı için son log kaydı kontrolü (son 5 dakika içi)
$recent_error = '';
$errlog = __DIR__ . '/../logs/doviz_dizayn_error.txt';
if (is_file($errlog)) {
    $mtime = filemtime($errlog);
    if ($mtime !== false && (time() - $mtime) < 300) {
        $lines = @file($errlog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines) && count($lines) > 0) {
            $recent_error = (string)end($lines);
        }
    }
}

// Yazdırma seçenekleri (action tiles)
$print_actions = [
    [
        'key'     => 'html',
        'title'   => 'HTML Yazdır',
        'desc'    => 'Tarayıcıda önizleme ile yazdırma sayfası',
        'icon'    => 'fa-file-code',
        'tone'    => 'sky',
        'href'    => 'yazdir.php?id=' . $stokhareket,
        'target'  => '_blank',
        'enabled' => true,
        'hint'    => 'HTML Çıktı',
    ],
    [
        'key'     => 'direct',
        'title'   => 'Doğrudan Yazıcı',
        'desc'    => 'Yazdırma kuyruğuna ekle, yazıcıya gönder',
        'icon'    => 'fa-print',
        'tone'    => 'emerald',
        'href'    => 'yazdirx.php?id=' . $stokhareket,
        'target'  => '_self',
        'enabled' => true,
        'hint'    => 'Yazıcı Kuyruğu',
    ],
    [
        'key'     => 'excel',
        'title'   => 'Excel İndir',
        'desc'    => 'XLSX formatında döviz siparişini indir',
        'icon'    => 'fa-file-excel',
        'tone'    => 'green',
        'href'    => 'fis_excel_xlsx.php?id=' . $stokhareket,
        'target'  => '_self',
        'enabled' => true,
        'hint'    => '.xlsx dosyası',
    ],
    [
        'key'     => 'email',
        'title'   => 'Email Gönder',
        'desc'    => $has_email
            ? ('Müşteri mail adresine gönder: ' . $email_addr)
            : 'Müşteri kartında mail adresi tanımlı değil',
        'icon'    => 'fa-paper-plane',
        'tone'    => 'amber',
        'href'    => $has_email ? ('yazdir.php?id=' . $stokhareket . '&mail=1') : '#',
        'target'  => '_self',
        'enabled' => $has_email,
        'hint'    => $has_email ? 'E-posta Gönder' : 'Mail adresi yok',
    ],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yazdırma Seçimi - <?php echo htmlspecialchars($ficheno, ENT_QUOTES); ?></title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
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
            --emerald-soft: #ecfdf5;
            --green: #16a34a;
            --green-soft: #f0fdf4;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
            --purple-strong: #6d28d9;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --slate: #475569;
            --slate-soft: #f1f5f9;
        }

        * { box-sizing: border-box; margin: 0; }

        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        /* ═══════ PURPLE STICKY HEADER ═══════ */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: saturate(180%) blur(12px);
            -webkit-backdrop-filter: saturate(180%) blur(12px);
            border-bottom: 1px solid rgba(124, 58, 237, 0.18);
            box-shadow: 0 2px 8px rgba(124, 58, 237, 0.05);
        }
        .header-inner {
            max-width: 980px; margin: 0 auto;
            height: 100%; display: flex; align-items: center; gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 40px; height: 40px; border-radius: 12px;
            background: #fff; border: 1px solid var(--border);
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover {
            border-color: var(--purple);
            color: var(--purple);
            background: var(--purple-soft);
            transform: translateX(-2px);
        }
        .header-icon {
            width: 42px; height: 42px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            color: var(--purple);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        .header-titles { flex: 1; min-width: 0; }
        .header-title {
            font-size: 17px; font-weight: 700; color: var(--text-1);
            letter-spacing: -0.01em; line-height: 1.2;
        }
        .header-subtitle {
            font-size: 12px; color: var(--text-2); margin-top: 2px;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .header-badge {
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 8px 14px; border-radius: 12px;
            background: var(--purple); color: #fff;
            font-size: 13px; font-weight: 600;
            flex-shrink: 0;
        }

        main {
            max-width: 980px;
            margin: 0 auto;
            padding: 22px 24px 60px;
        }

        /* ═══════ HERO CARD (purple soft) ═══════ */
        .hero-card {
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: saturate(180%) blur(10px);
            -webkit-backdrop-filter: saturate(180%) blur(10px);
            border: 1px solid rgba(124, 58, 237, 0.18);
            border-radius: 18px;
            padding: 20px 22px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 4px 16px rgba(124, 58, 237, 0.06);
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
            flex-wrap: wrap;
        }
        .hero-ico {
            width: 56px; height: 56px;
            border-radius: 14px;
            background: linear-gradient(135deg, #f5f3ff, #ede9fe);
            color: var(--purple);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px;
            flex-shrink: 0;
        }
        .hero-text { flex: 1; min-width: 220px; }
        .hero-text h1 {
            font-size: 18px; font-weight: 700; color: var(--text-1); line-height: 1.2;
        }
        .hero-text p {
            margin-top: 4px; font-size: 12.5px; color: var(--text-2); line-height: 1.5;
        }
        .hero-meta {
            display: flex; gap: 10px; flex-wrap: wrap;
            margin-left: auto;
        }
        .meta-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 12px; border-radius: 12px;
            background: var(--purple-soft);
            border: 1px solid rgba(124, 58, 237, 0.2);
            color: var(--purple-strong);
            font-size: 12px; font-weight: 600;
        }
        .meta-chip i { font-size: 12px; opacity: 0.8; }
        .meta-chip .meta-label {
            font-size: 10px; font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--purple);
            opacity: 0.75;
        }
        .meta-chip .meta-value {
            font-size: 13px; font-weight: 700;
        }
        .meta-col { display: flex; flex-direction: column; line-height: 1.15; }

        /* ═══════ ALERT ═══════ */
        .alert {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 14px 16px; border-radius: 14px; margin-bottom: 18px;
            font-size: 14px; font-weight: 500;
            animation: cardIn 0.35s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .alert i { font-size: 18px; margin-top: 1px; flex-shrink: 0; }
        .alert-success { background: var(--emerald-soft); color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: var(--red-soft); color: #991b1b; border: 1px solid #fecaca; }
        .alert code {
            display: block; margin-top: 4px;
            font-family: 'SFMono-Regular', Consolas, monospace;
            font-size: 11.5px; background: rgba(0,0,0,0.04);
            padding: 4px 8px; border-radius: 6px; word-break: break-word;
        }

        /* ═══════ GLASS CARD ═══════ */
        .glass-card {
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: saturate(180%) blur(10px);
            -webkit-backdrop-filter: saturate(180%) blur(10px);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
            animation: cardIn 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .section-title {
            font-size: 11px; font-weight: 600; color: var(--text-3);
            text-transform: uppercase; letter-spacing: 0.08em;
            margin: 0 0 14px;
        }

        /* ═══════ ACTION TILE GRID ═══════ */
        .action-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
        }

        .action-tile {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: saturate(180%) blur(10px);
            -webkit-backdrop-filter: saturate(180%) blur(10px);
            border: 2px solid var(--border);
            border-radius: 18px;
            padding: 20px 18px;
            cursor: pointer;
            transition: transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease, background 0.22s ease;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            text-align: left;
            text-decoration: none;
            color: inherit;
            position: relative;
            min-height: 200px;
            animation: cardIn 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .action-tile:hover {
            transform: translateY(-3px);
        }
        .action-tile .tile-ico {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 22px;
            margin-bottom: 14px;
            transition: transform 0.22s ease;
        }
        .action-tile:hover .tile-ico { transform: scale(1.06) rotate(-3deg); }

        .tone-sky     .tile-ico { background: var(--sky-soft);     color: var(--sky); }
        .tone-sky:hover          { border-color: var(--sky); box-shadow: 0 14px 30px rgba(2, 132, 199, 0.14); }
        .tone-emerald .tile-ico { background: var(--emerald-soft); color: var(--emerald); }
        .tone-emerald:hover      { border-color: var(--emerald); box-shadow: 0 14px 30px rgba(5, 150, 105, 0.14); }
        .tone-green   .tile-ico { background: var(--green-soft);   color: var(--green); }
        .tone-green:hover        { border-color: var(--green); box-shadow: 0 14px 30px rgba(22, 163, 74, 0.14); }
        .tone-amber   .tile-ico { background: var(--amber-soft);   color: var(--amber); }
        .tone-amber:hover        { border-color: var(--amber); box-shadow: 0 14px 30px rgba(217, 119, 6, 0.14); }

        .tile-title {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            line-height: 1.25; margin-bottom: 6px;
            display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
        }
        .tile-desc {
            font-size: 12.5px; color: var(--text-2); line-height: 1.5;
            margin-bottom: 14px;
        }
        .tile-hint {
            margin-top: auto;
            font-size: 11px; color: var(--text-3);
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 8px; border-radius: 8px;
            background: #f9fafb; border: 1px solid var(--border);
            font-family: 'SFMono-Regular', Consolas, monospace;
        }

        /* Seç ghost button */
        .tile-cta {
            margin-top: 12px;
            display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 9px 14px;
            border-radius: 12px;
            background: #fff;
            border: 1.5px solid var(--border);
            color: var(--text-2);
            font-size: 12.5px; font-weight: 600;
            align-self: stretch;
            transition: all 0.18s ease;
        }
        .tone-sky     .tile-cta { border-color: rgba(2, 132, 199, 0.3); color: var(--sky); }
        .tone-emerald .tile-cta { border-color: rgba(5, 150, 105, 0.3); color: var(--emerald); }
        .tone-green   .tile-cta { border-color: rgba(22, 163, 74, 0.3); color: var(--green); }
        .tone-amber   .tile-cta { border-color: rgba(217, 119, 6, 0.3); color: var(--amber); }
        .action-tile:hover .tile-cta {
            background: var(--text-1);
            border-color: var(--text-1);
            color: #fff;
        }
        .tone-sky.action-tile:hover     .tile-cta { background: var(--sky);     border-color: var(--sky); }
        .tone-emerald.action-tile:hover .tile-cta { background: var(--emerald); border-color: var(--emerald); }
        .tone-green.action-tile:hover   .tile-cta { background: var(--green);   border-color: var(--green); }
        .tone-amber.action-tile:hover   .tile-cta { background: var(--amber);   border-color: var(--amber); }

        /* Disabled tile */
        .action-tile.is-disabled {
            cursor: not-allowed;
            opacity: 0.6;
            background: #fafafa;
        }
        .action-tile.is-disabled:hover {
            transform: none;
            box-shadow: none;
            border-color: var(--border);
        }
        .action-tile.is-disabled:hover .tile-ico { transform: none; }
        .action-tile.is-disabled:hover .tile-cta {
            background: #fff; border-color: var(--border); color: var(--text-3);
        }

        /* ═══════ ANIMATIONS ═══════ */
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* ═══════ TABLET ═══════ */
        @media (max-width: 900px) {
            .action-grid { grid-template-columns: repeat(2, 1fr); }
            .hero-meta { margin-left: 0; }
        }

        /* ═══════ MOBILE ═══════ */
        @media (max-width: 767px) {
            .top-header { height: 56px; }
            .header-inner { padding: 0 14px; gap: 10px; }
            .header-back { width: 36px; height: 36px; border-radius: 10px; }
            .header-icon { width: 38px; height: 38px; font-size: 16px; border-radius: 11px; }
            .header-title { font-size: 15px; }
            .header-subtitle { font-size: 11px; }
            .header-badge { padding: 7px 10px; font-size: 12px; }

            main { padding: 16px 14px 40px; }

            .hero-card { padding: 16px; gap: 12px; margin-bottom: 16px; border-radius: 16px; }
            .hero-ico { width: 46px; height: 46px; font-size: 18px; border-radius: 12px; }
            .hero-text h1 { font-size: 15px; }
            .hero-text p { font-size: 11.5px; }
            .hero-meta { gap: 8px; width: 100%; }
            .meta-chip { padding: 7px 10px; }

            .action-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            .action-tile {
                min-height: auto;
                padding: 18px 16px;
                border-radius: 16px;
            }
            .action-tile:hover { transform: none; }
            .action-tile:hover .tile-ico { transform: none; }
            .tile-ico { width: 46px; height: 46px; font-size: 18px; border-radius: 12px; margin-bottom: 12px; }
            .tile-title { font-size: 14.5px; }
            .tile-desc { font-size: 12px; }
            .tile-cta { padding: 11px 14px; font-size: 13px; }

            /* iOS zoom fix */
            input, select, textarea { font-size: 16px; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="siparisler.php" class="header-back" title="Siparişlere Geri Dön" aria-label="Geri">
                <i class="fa fa-arrow-left"></i>
            </a>
            <span class="header-icon"><i class="fa-solid fa-print"></i></span>
            <div class="header-titles">
                <div class="header-title">Yazdırma Seçimi</div>
                <div class="header-subtitle">
                    <i class="fa-solid fa-receipt"></i>
                    Fiş No: <?php echo htmlspecialchars($ficheno, ENT_QUOTES); ?>
                </div>
            </div>
            <span class="header-badge" title="Döviz">
                <i class="fa-solid fa-money-bill-wave"></i>
                <?php echo htmlspecialchars($doviz['sembol'], ENT_QUOTES); ?> <?php echo htmlspecialchars($doviz['kod'], ENT_QUOTES); ?>
            </span>
        </div>
    </header>

    <main>

        <!-- Hero -->
        <div class="hero-card">
            <span class="hero-ico"><i class="fa-solid fa-swatchbook"></i></span>
            <div class="hero-text">
                <h1><?php echo htmlspecialchars($cari_isim, ENT_QUOTES); ?></h1>
                <p>Dövizli siparişiniz için bir yazdırma yöntemi seçin. Çıktı tarayıcıda açılabilir, doğrudan yazıcıya iletilebilir, Excel olarak indirilebilir veya müşteriye e-posta ile gönderilebilir.</p>
            </div>
            <div class="hero-meta">
                <div class="meta-chip">
                    <i class="fa-solid fa-coins"></i>
                    <div class="meta-col">
                        <span class="meta-label">Döviz</span>
                        <span class="meta-value"><?php echo htmlspecialchars($doviz['sembol'], ENT_QUOTES); ?> <?php echo htmlspecialchars($doviz['kod'], ENT_QUOTES); ?></span>
                    </div>
                </div>
                <div class="meta-chip">
                    <i class="fa-solid fa-arrow-right-arrow-left"></i>
                    <div class="meta-col">
                        <span class="meta-label">Kur</span>
                        <span class="meta-value"><?php echo number_format($trrate, 4, ',', '.'); ?> &#8378;</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($recent_error !== ''): ?>
        <div class="alert alert-error" role="alert">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                Yazdırma modülünde son dakikalarda bir hata kaydedildi. Aşağıdaki yöntemi tekrar deneyin; sorun sürerse yöneticiye bildirin.
                <code><?php echo htmlspecialchars($recent_error, ENT_QUOTES); ?></code>
            </div>
        </div>
        <?php endif; ?>

        <!-- Action Tiles -->
        <div class="glass-card">
            <h2 class="section-title">Yazdırma Seçenekleri</h2>
            <div class="action-grid">
                <?php $delay = 0; foreach ($print_actions as $act):
                    $tone = $act['tone'];
                    $enabled = (bool)$act['enabled'];
                    $href = $enabled ? $act['href'] : 'javascript:void(0)';
                    $cls = 'action-tile tone-' . $tone . ($enabled ? '' : ' is-disabled');
                    $target = $enabled ? $act['target'] : '_self';
                ?>
                <a href="<?php echo htmlspecialchars($href, ENT_QUOTES); ?>"
                   class="<?php echo htmlspecialchars($cls, ENT_QUOTES); ?>"
                   <?php if ($enabled && $target === '_blank'): ?>target="_blank" rel="noopener"<?php endif; ?>
                   <?php if (!$enabled): ?>aria-disabled="true" tabindex="-1"<?php endif; ?>
                   style="animation-delay: <?php echo min($delay, 280); ?>ms;"
                   data-key="<?php echo htmlspecialchars($act['key'], ENT_QUOTES); ?>">
                    <span class="tile-ico"><i class="fa-solid <?php echo htmlspecialchars($act['icon'], ENT_QUOTES); ?>"></i></span>
                    <div class="tile-title"><?php echo htmlspecialchars($act['title']); ?></div>
                    <div class="tile-desc"><?php echo htmlspecialchars($act['desc']); ?></div>
                    <div class="tile-hint">
                        <i class="fa-solid fa-circle-info"></i>
                        <?php echo htmlspecialchars($act['hint']); ?>
                    </div>
                    <span class="tile-cta">
                        <?php if ($enabled): ?>
                            Seç <i class="fa-solid fa-arrow-right"></i>
                        <?php else: ?>
                            <i class="fa-solid fa-lock"></i> Kullanılamıyor
                        <?php endif; ?>
                    </span>
                </a>
                <?php $delay += 70; endforeach; ?>
            </div>
        </div>

    </main>

    <script>
        // Animasyon reflow fix — force a reflow after load for reliable first-paint
        window.addEventListener('load', function () {
            requestAnimationFrame(function () {
                document.querySelectorAll('.action-tile, .hero-card, .glass-card, .alert').forEach(function (el) {
                    void el.offsetHeight;
                });
            });
        });

        // Prevent click on disabled tiles
        document.querySelectorAll('.action-tile.is-disabled').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
            });
        });
    </script>

</body>
</html>

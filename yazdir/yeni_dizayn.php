<?php
declare(strict_types=1);

// Gerekli yapılandırma ve loglama dosyalarını yükle.
include_once __DIR__ . "/../ayr.php";
include_once __DIR__ . "/../log_ip.php";

// Güvenli oturum ve yetki kontrolünü zorunlu kıl.
require_once __DIR__ . '/../kontrol.php';

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['stokhareket', 'tipdurum', 'donem', 'iskonto2_var']);

// 2025/2026 dönem desteği (migrateUrlToSession'dan SONRA olmalı)
include_once __DIR__ . "/../donem_helper.php";

$yazdirmaStokhareket = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $yazdirmaStokhareket = isset($_POST['stokhareket']) ? (int) $_POST['stokhareket'] : 0;
}
if ($yazdirmaStokhareket <= 0) {
    $yazdirmaStokhareket = getPageParamInt('stokhareket');
}
if ($yazdirmaStokhareket > 0 && !m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $yazdirmaStokhareket)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Hata loglama fonksiyonu
function logError(string $message): void {
    // Goreceli "error_log.txt" yazimi IIS'te izin hatasi veriyordu;
    // PHP'nin merkezi error_log mekanizmasina yaziliyor.
    error_log('[yeni_dizayn] ' . $message);
}

// Form gönderildi mi kontrol et (POST metodu ile)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Formdan gelen verileri al ve temizle
    $miktar       = isset($_POST['miktar'])     ? intval($_POST['miktar'])     : 1;
    $yazici       = $_POST['yazici'] ?? '';
    $dizayn       = $_POST['dizayn'] ?? '';
    $lokasyon     = isset($_POST['lokasyon'])   ? intval($_POST['lokasyon'])   : 1;
    $tip          = isset($_POST['tip'])        ? intval($_POST['tip'])        : 1;
    $stokhareket  = isset($_POST['stokhareket'])? intval($_POST['stokhareket']): 0;
    $aciklama     = $_POST['aciklama'] ?? '';
    $aciklama2    = $_POST['aciklama2'] ?? '';
    $docode       = function_exists('mb_substr')
        ? mb_substr((string)$aciklama, 0, 33, 'UTF-8')
        : substr((string)$aciklama, 0, 33);

    if ($stokhareket > 0) {
        $islem_basarili = false;
        $hata_mesaji = '';
        
        try {
            // Açıklamaları güncelle (önce ORFICHE, yoksa STFICHE)
            $stmt_update = $dbh->prepare("
                UPDATE {$firmadonem}ORFICHE
                   SET GENEXP1 = :aciklama,
                       GENEXP2 = :aciklama2,
                       DOCODE = :docode
                 WHERE LOGICALREF = :stokhareket
            ");
            $stmt_update->execute([
                ':aciklama'    => $aciklama,
                ':aciklama2'   => $aciklama2,
                ':docode'      => $docode,
                ':stokhareket' => $stokhareket,
            ]);

            // ORFICHE'de kayıt yoksa STFICHE'yi güncelle
            if ($stmt_update->rowCount() == 0) {
                $stmt_update2 = $dbh->prepare("
                    UPDATE {$firmadonem}STFICHE
                       SET GENEXP1 = :aciklama,
                           GENEXP2 = :aciklama2
                     WHERE LOGICALREF = :stokhareket
                ");
                $stmt_update2->execute([
                    ':aciklama'    => $aciklama,
                    ':aciklama2'   => $aciklama2,
                    ':stokhareket' => $stokhareket,
                ]);
            }

            // Döviz bilgilerini çek (önce ORFICHE, bulamazsa STFICHE)
            $stmt_doviz = $dbh->prepare("
                SELECT TRCODE, TRRATE
                FROM {$firmadonem}ORFICHE
                WHERE LOGICALREF = :stokhareket
            ");
            $stmt_doviz->execute([':stokhareket' => $stokhareket]);
            $doviz_bilgi = $stmt_doviz->fetch(PDO::FETCH_ASSOC);

            // ORFICHE'de bulunamadıysa STFICHE'de ara
            if (!$doviz_bilgi) {
                $stmt_doviz2 = $dbh->prepare("
                    SELECT TRCODE, TRRATE
                    FROM {$firmadonem}STFICHE
                    WHERE LOGICALREF = :stokhareket
                ");
                $stmt_doviz2->execute([':stokhareket' => $stokhareket]);
                $doviz_bilgi = $stmt_doviz2->fetch(PDO::FETCH_ASSOC);
            }

            $trcode = $doviz_bilgi['TRCODE'] ?? 0;
            $trrate = $doviz_bilgi['TRRATE'] ?? 1;

            // Mail bilgisini çek
            $mail = function_exists('cari_mail_bul')
                  ? cari_mail_bul($stokhareket)
                  : '';

            // M_MOBIL_DIZAYN tablosunun var olup olmadığını kontrol et
            $tableCheck = $dbh->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'M_MOBIL_DIZAYN'");
            $tableExists = $tableCheck->fetchColumn() > 0;
            
            if (!$tableExists) {
                $hata_mesaji = "M_MOBIL_DIZAYN tablosu bulunamadı. Tabloyu oluşturmanız gerekiyor.";
                logError("M_MOBIL_DIZAYN tablosu yok!");
            } else {
                // 1. M_MOBIL_DIZAYN'a ekle (mobilyazlogo.exe için)
                // Önce sütunların var olup olmadığını kontrol et
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
                    ':islem' => $tip,
                    ':yazici' => $yazici,
                    ':lokasyon' => $lokasyon,
                    ':mail' => $mail
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
                    $params[':trcode'] = $trcode;
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
                
                // M_MOBIL_DIZAYN_LOG tablosunun var olup olmadığını kontrol et
                $tableCheckLog = $dbh->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'M_MOBIL_DIZAYN_LOG'");
                $tableLogExists = $tableCheckLog->fetchColumn() > 0;
                
                if ($tableLogExists) {
                    // 2. M_MOBIL_DIZAYN_LOG'a da ekle (kalıcı kayıt için)
                    // LOG tablosu için de sütun kontrolü yap
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
                        ':fis'       => $stokhareket,
                        ':kullanici' => $terminalkullanici,
                        ':dizayn'    => $dizayn,
                        ':miktar'    => $miktar,
                        ':islem'     => $tip,
                        ':yazici'    => $yazici,
                        ':lokasyon'  => $lokasyon,
                    ];

                    // TARIH sütunu varsa ekle
                    if (in_array('TARIH', $existingColumnsLog)) {
                        $columnsLog[] = 'TARIH';
                        $valuesLog[] = 'GETDATE()';
                    }

                    // TRCODE sütunu varsa ekle
                    if (in_array('TRCODE', $existingColumnsLog)) {
                        $columnsLog[] = 'TRCODE';
                        $valuesLog[] = ':trcode';
                        $paramsLog[':trcode'] = $trcode;
                    }

                    // TRRATE sütunu varsa ekle
                    if (in_array('TRRATE', $existingColumnsLog)) {
                        $columnsLog[] = 'TRRATE';
                        $valuesLog[] = ':trrate';
                        $paramsLog[':trrate'] = $trrate;
                    }

                    // FICHENO sütunu varsa ekle
                    if (in_array('FICHENO', $existingColumnsLog)) {
                        // FICHENO'yu al (önce ORFICHE, sonra STFICHE)
                        $stmtFisNo = $dbh->prepare("SELECT FICHENO FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
                        $stmtFisNo->execute([':stokhareket' => $stokhareket]);
                        $fisNoLog = $stmtFisNo->fetch(PDO::FETCH_ASSOC);

                        // ORFICHE'de bulunamadıysa STFICHE'de ara
                        if (!$fisNoLog) {
                            $stmtFisNo2 = $dbh->prepare("SELECT FICHENO FROM {$firmadonem}STFICHE WHERE LOGICALREF = :stokhareket");
                            $stmtFisNo2->execute([':stokhareket' => $stokhareket]);
                            $fisNoLog = $stmtFisNo2->fetch(PDO::FETCH_ASSOC);
                        }
                        $ficheno = $fisNoLog ? $fisNoLog['FICHENO'] : null;

                        $columnsLog[] = 'FICHENO';
                        $valuesLog[] = ':ficheno';
                        $paramsLog[':ficheno'] = $ficheno;
                    }

                    // DONEM sütunu varsa ekle
                    if (in_array('DONEM', $existingColumnsLog)) {
                        $columnsLog[] = 'DONEM';
                        $valuesLog[] = '2'; // Güncel dönem
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
            error_log("Yazdırma kuyruğu ekleme hatası: " . $e->getMessage());
        }
        
        // Hata varsa session'a kaydet (sonra gösterilecek)
        if (!empty($hata_mesaji)) {
            $_SESSION['yazdirma_hata'] = $hata_mesaji;
        } elseif ($islem_basarili) {
            $_SESSION['yazdirma_basarili'] = "Yazdırma kuyruğuna başarıyla eklendi.";
        }
    }

    // İşlem bittikten sonra yönlendir
    header("Location: ../lg_essiparis.php");
    exit;
}

// Sayfa ilk yüklendiğinde (session bazlı parametre sistemi)
$stokhareket = getPageParamInt('stokhareket');
if ($stokhareket == 0) {
    die("Geçersiz fiş numarası.");
}

// Mevcut açıklamaları çek (önce ORFICHE, sonra STFICHE)
$stmt_select = $dbh->prepare("
    SELECT GENEXP1, GENEXP2
      FROM {$firmadonem}ORFICHE
     WHERE LOGICALREF = :stokhareket
");
$stmt_select->execute([':stokhareket' => $stokhareket]);
$eyne = $stmt_select->fetch(PDO::FETCH_ASSOC);

// ORFICHE'de bulunamadıysa STFICHE'de ara
if (!$eyne) {
    $stmt_select2 = $dbh->prepare("
        SELECT GENEXP1, GENEXP2
          FROM {$firmadonem}STFICHE
         WHERE LOGICALREF = :stokhareket
    ");
    $stmt_select2->execute([':stokhareket' => $stokhareket]);
    $eyne = $stmt_select2->fetch(PDO::FETCH_ASSOC);
}
if (!is_array($eyne)) {
    $eyne = ['GENEXP1' => '', 'GENEXP2' => ''];
}

// Tip durumuna göre dizayn yolunu belirle (session bazlı)
$tipdurum = getPageParamInt('tipdurum') ?: 1;
// lg_fis.php tarafindan set edilen ikinci iskonto bilgisi
$iskonto2_var = getPageParamInt('iskonto2_var', 0);

// Yol birleştirmesini düzelt (Windows için \ kullan)
$yol_base = rtrim($yol ?? '', '\\/'); // Sonundaki \ veya / karakterlerini temizle
$dizaynyolu = match ($tipdurum) {
    2 => $yol_base . DIRECTORY_SEPARATOR . "barkod" . DIRECTORY_SEPARATOR,
    3 => $yol_base . DIRECTORY_SEPARATOR . "ambar" . DIRECTORY_SEPARATOR,
    default => $yol_base . DIRECTORY_SEPARATOR . "fis" . DIRECTORY_SEPARATOR,
};

// Varsayılan değerler
$lokasyon = 1;
$miktar = 1;

// Dizayn klasorunu bir kez tara. Varsayilan dosyayi da bu listeden seceriz ki
// Turkce karakterli "0koli - kucuk.frx" adi, readdir'in dondurdugu byte
// diziliyle birebir eslessin (Windows encoding tuzagi olmasin).
$dizaynDosyalari = [];
if (is_dir($dizaynyolu) && is_readable($dizaynyolu) && ($dh = opendir($dizaynyolu))) {
    while (($f = readdir($dh)) !== false) {
        if ($f !== '.' && $f !== '..' && !is_dir($dizaynyolu . $f)) {
            $dizaynDosyalari[] = $f;
        }
    }
    closedir($dh);
}

// Iskonto tespiti: fisin ANA satirlarinda net < brut mu (para gercekten azaldi mi)?
// NOT: sadece LINETYPE=2 "iskonto satiri" varligina bakmak yaniltir — etkisi 0
// olan iskonto satirlari mevcut (net==brut, distdisc=0). Bu yuzden net<brut olcutu.
$iskontoVar = false;
if ($stokhareket > 0) {
    try {
        $qi = $dbh->prepare("
            SELECT
              (SELECT COUNT(*) FROM {$firmadonem}ORFLINE
                 WHERE ORDFICHEREF = :r1 AND LINETYPE = 0 AND ABS(ISNULL(TOTAL,0)-ISNULL(LINENET,0)) > 0.01) AS ORF_ISK,
              (SELECT COUNT(*) FROM {$firmadonem}ORFLINE
                 WHERE ORDFICHEREF = :r2 AND LINETYPE = 0) AS ORF_ANA
        ");
        $qi->execute([':r1' => $stokhareket, ':r2' => $stokhareket]);
        $ri = $qi->fetch(PDO::FETCH_ASSOC) ?: [];
        if ((int) ($ri['ORF_ISK'] ?? 0) > 0) {
            $iskontoVar = true;
        } elseif ((int) ($ri['ORF_ANA'] ?? 0) === 0) {
            // Siparis satiri yok -> stok fisi (STLINE) uzerinden bak
            $qs = $dbh->prepare("
                SELECT COUNT(*) FROM {$firmadonem}STLINE
                 WHERE STFICHEREF = :r AND LINETYPE = 0 AND ABS(ISNULL(TOTAL,0)-ISNULL(LINENET,0)) > 0.01
            ");
            $qs->execute([':r' => $stokhareket]);
            $iskontoVar = ((int) $qs->fetchColumn() > 0);
        }
    } catch (Throwable $e) {
        logError('iskonto tespit: ' . $e->getMessage());
    }
}

// Varsayilan dizayn dosyasi — yalniz Fis tipinde (tipdurum=1):
//   cift iskonto -> 2iskonto.frx (mevcut ozel durum korunur)
//   iskonto var  -> "0koli - kucuk.frx"
//   iskonto yok  -> 0koli.frx
// Hedef, encoding sorunu olmasin diye taranan listeden ASCII-guvenli olcutle secilir.
$dizaynSec = static function (array $liste, callable $test): string {
    foreach ($liste as $d) { if ($test($d)) { return $d; } }
    return '';
};
$varsayilan_dizayn = '';
if ($tipdurum === 1) {
    if ($iskonto2_var === 1) {
        $varsayilan_dizayn = $dizaynSec($dizaynDosyalari, static fn($d): bool => strcasecmp($d, '2iskonto.frx') === 0);
    } elseif ($iskontoVar) {
        $varsayilan_dizayn = $dizaynSec($dizaynDosyalari, static fn($d): bool => stripos($d, '0koli - ') === 0);
    } else {
        $varsayilan_dizayn = $dizaynSec($dizaynDosyalari, static fn($d): bool => strcasecmp($d, '0koli.frx') === 0);
    }
}

// Aktif <option> için işaret
$aktif_secenekler = ['1'=>'','2'=>'','3'=>'','4'=>''];
$aktif_secenekler[$tipdurum] = 'selected';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Yazdirma Yoneticisi</title>
  <?php include_once(__DIR__ . '/../pwa-header.php'); ?>
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
      --red-border: rgba(248, 113, 113, 0.25);
      --emerald: #059669;
      --indigo: #4f46e5;
      --amber: #d97706;
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
    }

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
    .section-divider {
      height: 1px;
      background: var(--border);
      margin: 18px 0;
    }

    /* ═══════ FORM ELEMENTS ═══════ */
    .field {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .field-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }
    .field-label {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.4px;
      display: flex;
      align-items: center;
      gap: 6px;
    }
    .field-label i { color: var(--text-3); font-size: 11px; }
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
    }
    .field-control:focus {
      border-color: rgba(239, 68, 68, 0.5);
      box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
    }
    select.field-control {
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
      background-position: right 0.6rem center;
      background-repeat: no-repeat;
      background-size: 1.4em 1.4em;
      padding-right: 2.6rem;
      -webkit-appearance: none;
      -moz-appearance: none;
      appearance: none;
      cursor: pointer;
    }

    /* ═══════ BUTTONS ═══════ */
    .btn-flat {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 13px 18px;
      border-radius: 10px;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 14px;
      font-weight: 600;
      border: none;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s ease;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .btn-flat:hover { box-shadow: 0 4px 10px rgba(0,0,0,0.15); transform: translateY(-1px); }
    .btn-flat:active { transform: translateY(0); }
    .btn-red { background: var(--red,#ef4444); color: #fff; }
    .btn-red:hover { background: var(--red,#6F1022); }
    .btn-light {
      background: #fff;
      color: var(--text-2);
      border: 1px solid var(--border);
    }
    .btn-light:hover { background: #f9fafb; color: var(--text-1); }

    .button-stack {
      display: flex;
      flex-direction: column;
      gap: 10px;
      margin-top: 4px;
    }

    /* ═══════ HERO DESIGN PICKER (sik degisen) ═══════ */
    .hero-design {
      padding: 18px;
      background: linear-gradient(135deg, #fef2f2, #fee2e2);
      border: 1px solid rgba(248, 113, 113, 0.3);
      border-radius: 14px;
      margin-bottom: 4px;
    }
    .hero-design .field-label {
      color: #991b1b;
      font-size: 11px;
      margin-bottom: 8px;
    }
    .hero-design select.field-control {
      font-size: 16px;
      font-weight: 600;
      padding: 14px 16px;
      padding-right: 2.6rem;
      background-color: #fff;
      border-color: rgba(239, 68, 68, 0.3);
    }
    .hero-design select.field-control:focus {
      border-color: var(--red,#ef4444);
      box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.15);
    }

    /* ═══════ COLLAPSIBLE ADVANCED ═══════ */
    .collapsible {
      border: 1px solid var(--border);
      border-radius: 12px;
      overflow: hidden;
      background: #f9fafb;
    }
    .collapsible-toggle {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      padding: 12px 16px;
      background: transparent;
      border: none;
      cursor: pointer;
      font-family: 'Avenir Next', 'Montserrat', sans-serif;
      font-size: 12px;
      font-weight: 600;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      transition: background 0.15s ease;
    }
    .collapsible-toggle:hover { background: rgba(0,0,0,0.025); }
    .collapsible-toggle .chevron {
      transition: transform 0.25s cubic-bezier(0.22, 1, 0.36, 1);
      font-size: 11px;
      color: var(--text-3);
    }
    .collapsible.is-open .collapsible-toggle .chevron {
      transform: rotate(180deg);
    }
    .collapsible-body {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.32s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .collapsible.is-open .collapsible-body {
      max-height: 600px;
    }
    .collapsible-inner {
      padding: 4px 16px 16px 16px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      border-top: 1px solid var(--border);
    }

    /* Aktif tip badge'i (collapsible kapaliyken bile gosterilir) */
    .tip-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 10px;
      border-radius: 100px;
      font-size: 11px;
      font-weight: 700;
      background: #fff;
      color: var(--text-2);
      border: 1px solid var(--border);
    }
    .tip-badge i { font-size: 9px; color: var(--red,#ef4444); }

    /* ═══════ DEBUG INFO ═══════ */
    .debug-box {
      margin-top: 10px;
      padding: 12px 14px;
      background: #fffbeb;
      border: 1px solid #fde68a;
      border-radius: 10px;
      font-size: 11px;
      color: #92400e;
      line-height: 1.6;
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

      main { padding: 14px 12px !important; }
      .glass-card { padding: 16px; border-radius: 12px; }
      .field-control { font-size: 16px; padding: 10px 12px; } /* iOS zoom engeli */
      .field-row { grid-template-columns: 1fr; gap: 12px; }
      .btn-flat { font-size: 13px; padding: 12px 16px; }
      .btn-flat:hover { transform: none; }
    }
  </style>
</head>
<body>

  <header class="top-header">
    <div class="header-inner">
      <a href="../lg_siparis.php?stokhareket=<?php echo $stokhareket; ?>&sipariskaydet"
         class="header-back" title="Siparise Geri Don">
        <i class="fa fa-arrow-left"></i>
      </a>
      <div class="header-divider"></div>
      <span class="header-title">
        <i class="fa-solid fa-print" style="color:var(--red,#ef4444);margin-right:6px;"></i>Yazdirma Yoneticisi
      </span>
    </div>
  </header>

  <main style="max-width:720px;margin:0 auto;padding:24px 20px 60px;">
    <form id="form" method="POST" action="">
      <input type="hidden" name="stokhareket" value="<?php echo $stokhareket; ?>">

      <?php
        // Aktif yazdirma tipinin gorsel karsiligi (collapsed durumda badge olarak gosterilir)
        $tipEtiketleri = [
            1 => ['ad' => 'Fis', 'ikon' => 'fa-receipt'],
            2 => ['ad' => 'Barkod', 'ikon' => 'fa-barcode'],
            3 => ['ad' => 'Ambar', 'ikon' => 'fa-warehouse'],
            4 => ['ad' => 'Fis MAIL', 'ikon' => 'fa-envelope'],
        ];
        $aktifTip = $tipEtiketleri[$tipdurum] ?? $tipEtiketleri[1];
      ?>
      <div class="glass-card">
        <!-- HERO: Dizayn Dosyasi (en sik degisen) -->
        <div class="hero-design">
          <label for="dizayn" class="field-label"><i class="fa-solid fa-file-lines"></i> Dizayn Dosyasi</label>
          <select name="dizayn" id="dizayn" class="field-control">
            <?php
            // Debug bilgileri
            $debug_info = [];
            $debug_info['tipdurum'] = $tipdurum ?? 'TANIMLI DEĞİL';
            $debug_info['yol'] = $yol ?? 'TANIMLI DEĞİL';
            $debug_info['dizaynyolu'] = $dizaynyolu ?? 'TANIMLI DEĞİL';
            $debug_info['dizaynyolu_exists'] = isset($dizaynyolu) && is_dir($dizaynyolu) ? 'EVET' : 'HAYIR';
            $debug_info['dizaynyolu_readable'] = isset($dizaynyolu) && is_readable($dizaynyolu) ? 'EVET' : 'HAYIR';
            
            // Hata mesajı oluştur
            $hata_mesaji = '';
            if (!isset($dizaynyolu) || empty($dizaynyolu)) {
                $hata_mesaji = 'Dizayn yolu tanımlı değil';
            } elseif (!is_dir($dizaynyolu)) {
                $hata_mesaji = 'Dizayn klasörü bulunamadı: ' . htmlspecialchars($dizaynyolu, ENT_QUOTES);
            } elseif (!is_readable($dizaynyolu)) {
                $hata_mesaji = 'Dizayn klasörü okunamıyor (izin hatası): ' . htmlspecialchars($dizaynyolu, ENT_QUOTES);
            }
            
            if (is_dir($dizaynyolu) && is_readable($dizaynyolu)) {
                if (!empty($dizaynDosyalari)) {
                    // Onceden taranan liste uzerinden render — varsayilan secim de bu
                    // listeden geldigi icin isaretleme byte-birebir eslesir.
                    foreach ($dizaynDosyalari as $dosya) {
                        $selected_attr = ($varsayilan_dizayn !== '' && $dosya === $varsayilan_dizayn) ? ' selected' : '';
                        echo '<option value="' . htmlspecialchars($dosya, ENT_QUOTES) . '"' . $selected_attr . '>' .
                             htmlspecialchars($dosya, ENT_QUOTES) . '</option>';
                    }
                } else {
                    echo '<option value="">Klasör boş - Dizayn dosyası yok</option>';
                }
            } else {
                // Hata durumunda detaylı bilgi göster
                echo '<option value="">' . htmlspecialchars($hata_mesaji ?: 'Dizayn bulunamadı', ENT_QUOTES) . '</option>';
                
                // Debug modunda detaylı bilgi göster
                if (isset($_GET['debug']) || isset($_GET['debug_dizayn'])) {
                    echo '<!-- DEBUG BİLGİLERİ: ';
                    echo 'Tip Durum: ' . ($debug_info['tipdurum']) . ', ';
                    echo 'Yol: ' . ($debug_info['yol']) . ', ';
                    echo 'Dizayn Yolu: ' . ($debug_info['dizaynyolu']) . ', ';
                    echo 'Klasör Var mı: ' . ($debug_info['dizaynyolu_exists']) . ', ';
                    echo 'Klasör Okunabilir mi: ' . ($debug_info['dizaynyolu_readable']);
                    echo ' -->';
                }
            }
            ?>
          </select>
          <?php if (isset($_GET['debug']) || isset($_GET['debug_dizayn'])): ?>
            <div class="debug-box">
              <strong>Debug Bilgileri:</strong><br>
              Tip Durum: <?php echo htmlspecialchars((string)($debug_info['tipdurum'])); ?><br>
              Yol Degiskeni: <?php echo htmlspecialchars((string)($debug_info['yol'])); ?><br>
              Dizayn Yolu: <?php echo htmlspecialchars((string)($debug_info['dizaynyolu'])); ?><br>
              Klasor Var mi: <?php echo htmlspecialchars((string)($debug_info['dizaynyolu_exists'])); ?><br>
              Klasor Okunabilir mi: <?php echo htmlspecialchars((string)($debug_info['dizaynyolu_readable'])); ?><br>
              <?php if (isset($dizaynyolu) && !empty($dizaynyolu)): ?>
                Gercek Klasor Yolu: <?php echo htmlspecialchars(realpath($dizaynyolu) ?: 'BULUNAMADI'); ?><br>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <div style="height:18px;"></div>

        <!-- Kopya Sayısı (sik degisen) -->
        <div class="field">
          <label for="miktar" class="field-label"><i class="fa-solid fa-copy"></i> Kopya Sayisi</label>
          <input type="number" name="miktar" id="miktar"
                 value="<?php echo isset($miktar)? intval($miktar):1; ?>"
                 min="1" inputmode="numeric"
                 class="field-control">
        </div>

        <div class="section-divider"></div>

        <!-- Açıklamalar (sik degisen) -->
        <div class="field">
          <label for="aciklama" class="field-label"><i class="fa-solid fa-pen"></i> 1. Aciklama</label>
          <input type="text" name="aciklama" id="aciklama"
                 value="<?php echo htmlspecialchars((string) $eyne['GENEXP1'], ENT_QUOTES); ?>"
                 class="field-control">
        </div>

        <div style="height:14px;"></div>

        <div class="field">
          <label for="aciklama2" class="field-label"><i class="fa-solid fa-pen-to-square"></i> 2. Aciklama</label>
          <input type="text" name="aciklama2" id="aciklama2"
                 value="<?php echo htmlspecialchars((string) $eyne['GENEXP2'], ENT_QUOTES); ?>"
                 class="field-control">
        </div>

        <div style="height:18px;"></div>

        <!-- GELISMIS AYARLAR (nadiren degisen): Yazdirma Tipi + Yazici + Lokasyon -->
        <div class="collapsible" id="gelismisAyarlar">
          <button type="button" class="collapsible-toggle" onclick="document.getElementById('gelismisAyarlar').classList.toggle('is-open')">
            <span><i class="fa-solid fa-sliders" style="margin-right:6px;color:var(--text-3);"></i> Gelismis Ayarlar</span>
            <span style="display:flex;align-items:center;gap:8px;">
              <span class="tip-badge"><i class="fa-solid <?php echo $aktifTip['ikon']; ?>"></i><?php echo htmlspecialchars($aktifTip['ad']); ?></span>
              <i class="fa-solid fa-chevron-down chevron"></i>
            </span>
          </button>
          <div class="collapsible-body">
            <div class="collapsible-inner">
              <!-- Yazdirma Tipi -->
              <div class="field">
                <label for="tip" class="field-label"><i class="fa-solid fa-tag"></i> Yazdirma Tipi</label>
                <select name="tip" id="tip" class="field-control">
                  <option value="1" <?php echo $aktif_secenekler['1']; ?>>Fis</option>
                  <option value="2" <?php echo $aktif_secenekler['2']; ?>>Barkod</option>
                  <option value="3" <?php echo $aktif_secenekler['3']; ?>>Ambar</option>
                  <option value="4" <?php echo $aktif_secenekler['4']; ?>>Fis MAIL</option>
                </select>
              </div>

              <!-- Yazıcı ve Lokasyon -->
              <div class="field-row">
                <div class="field">
                  <label for="yazici" class="field-label"><i class="fa-solid fa-print"></i> Yazici</label>
                  <select name="yazici" id="yazici" class="field-control">
                    <option value="<?php echo isset($fisyazici)  ? htmlspecialchars((string) $fisyazici, ENT_QUOTES)  : ''; ?>">Yaz-1</option>
                    <option value="<?php echo isset($fisyazici2) ? htmlspecialchars((string) $fisyazici2, ENT_QUOTES) : ''; ?>">Yaz-2</option>
                    <option value="<?php echo isset($fisyazici3) ? htmlspecialchars((string) $fisyazici3, ENT_QUOTES) : ''; ?>">Yaz-3</option>
                    <option value="<?php echo isset($fisyazici4) ? htmlspecialchars((string) $fisyazici4, ENT_QUOTES) : ''; ?>">Yaz-4</option>
                  </select>
                </div>
                <div class="field">
                  <label for="lokasyon" class="field-label"><i class="fa-solid fa-location-dot"></i> Lokasyon</label>
                  <select name="lokasyon" id="lokasyon" class="field-control">
                    <option value="1"<?php echo ($lokasyon===1?' selected':''); ?>>Merkez</option>
                    <option value="2"<?php echo ($lokasyon===2?' selected':''); ?>>Sube</option>
                    <option value="3"<?php echo ($lokasyon===3?' selected':''); ?>>Fabrika</option>
                  </select>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Butonlar -->
        <div class="button-stack" style="margin-top:22px;">
          <button type="submit" class="btn-flat btn-red">
            <i class="fa-solid fa-print"></i> Yazdirma Kuyruguna Ekle
          </button>
          <a href="../lg_siparis.php?stokhareket=<?php echo $stokhareket; ?>&sipariskaydet" class="btn-flat btn-light">
            <i class="fa-solid fa-xmark"></i> Iptal
          </a>
        </div>
      </div>
    </form>
  </main>

  <script>
    // Render bug fix (animasyon backdrop-filter ile bazen tetiklenmiyor)
    (function() {
       function forceReflow() {
          document.querySelectorAll('.glass-card').forEach(function(el) { void el.offsetHeight; });
       }
       if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', function() { requestAnimationFrame(forceReflow); });
       } else {
          requestAnimationFrame(forceReflow);
       }
    })();

    // "Tip" dropdown'i degistiginde formu GET olarak yeniden yukle
    document.getElementById('tip').addEventListener('change', function() {
      var stokHareket = '<?php echo $stokhareket; ?>';
      var secilenTip  = this.value;
      window.location.href = '?stokhareket=' + stokHareket + '&tipdurum=' + secilenTip;
    });
  </script>
</body>
</html>

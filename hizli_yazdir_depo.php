<?php
declare(strict_types=1);

// hizli_yazdir_depo.php  (PHP 5.6 uyumlu + 3 sn throttle)

// Ortak dosyalar
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/log_ip.php';
require_once __DIR__ . '/kontrol.php'; // yetki kontrolü

// 2025/2026 dönem desteği
include_once __DIR__ . "/donem_helper.php";

function logErrorQuick(string $m): void
{
    $logFile = __DIR__ . "/error_log.txt";
    $line = date("Y-m-d H:i:s") . " - HIZLI_YAZDIR: " . $m . PHP_EOL;

    if (!is_dir(__DIR__) || !is_writable(__DIR__)) {
        error_log("HIZLI_YAZDIR log yazılamadı: " . $m);
        return;
    }

    set_error_handler(static fn(): bool => true);
    $ok = file_put_contents($logFile, $line, FILE_APPEND);
    restore_error_handler();

    if ($ok === false) {
        error_log("HIZLI_YAZDIR log yazılamadı: " . $m);
    }
}

// ---- Parametreler (GET) ----
$stokhareket = isset($_GET['stokhareket']) ? (int)$_GET['stokhareket'] : 0;
$fisno       = isset($_GET['fisno'])       ? trim((string)$_GET['fisno'])    : '';
$tip         = isset($_GET['tip'])         ? (int)$_GET['tip']               : 1;   // 1=Fiş,2=Barkod,3=Ambar,4=Fiş MAIL
$dizayn      = isset($_GET['dizayn'])      ? trim((string)$_GET['dizayn'])   : '';
$yazici      = isset($_GET['yazici'])      ? trim((string)$_GET['yazici'])   : '';
$lokasyon    = isset($_GET['lokasyon'])    ? (int)$_GET['lokasyon']          : 1;
$miktar      = isset($_GET['miktar'])      ? max(1, (int)$_GET['miktar'])    : 1;
$back        = isset($_GET['back'])        ? trim((string)$_GET['back'])     : '';
$ajax        = isset($_GET['ajax'])        ? (int)$_GET['ajax']              : 0;

// Güvenli tip (1-4)
if ($tip < 1 || $tip > 4) { $tip = 1; }

// stokhareket yoksa fisno’dan bul
try {
    if ($stokhareket <= 0 && $fisno !== '') {
        $q = $dbh->prepare("SELECT LOGICALREF FROM {$firmadonem}ORFICHE WHERE FICHENO = :fno");
        $q->execute([':fno' => $fisno]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['LOGICALREF'])) {
            $stokhareket = (int)$row['LOGICALREF'];
        }
    }
} catch (Exception $e) {
    logErrorQuick("FICHENO çözümleme hatası: " . $e->getMessage());
}

// Hâlâ yoksa hata
if ($stokhareket <= 0) {
    if ($ajax !== 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Geçersiz stokhareket/fisno']);
        exit;
    }
    die('Geçersiz stokhareket/fisno');
}

// Tip -> dizayn klasörü (Windows uyumlu yol)
$yol_base = rtrim($yol ?? '', '\\/'); // Sonundaki \ veya / karakterlerini temizle
$dizaynyolu = match ($tip) {
    2 => $yol_base . DIRECTORY_SEPARATOR . "barkod" . DIRECTORY_SEPARATOR,
    3 => $yol_base . DIRECTORY_SEPARATOR . "ambar" . DIRECTORY_SEPARATOR,
    default => $yol_base . DIRECTORY_SEPARATOR . "fis" . DIRECTORY_SEPARATOR,
};

// Dizayn seçimi (parametre yoksa ilk dosyayı al)
if ($dizayn === '') {
    try {
        if (is_dir($dizaynyolu)) {
            // Öncelik: fis dizaynı içinde 'depo.frx' kullanılsın
            $preferred = 'depo.frx';
            if (file_exists($dizaynyolu . $preferred)) {
                $dizayn = $preferred;
            } else {
                $list = scandir($dizaynyolu);
                $files = [];
                if ($list !== false) {
                    foreach ($list as $f) {
                        if ($f !== '.' && $f !== '..' && !is_dir($dizaynyolu . $f)) {
                            $files[] = $f;
                        }
                    }
                }
                if ($files !== []) {
                    sort($files, SORT_NATURAL | SORT_FLAG_CASE);
                    $dizayn = $files[0];
                }
            }
        }
    } catch (Exception $e) {
        logErrorQuick("Dizayn listeleme hatası: " . $e->getMessage());
    }
}

// Yazıcı (parametre yoksa varsayılan)
if ($yazici === '') {
    if (isset($fisyazici)  && $fisyazici) {
        $yazici = $fisyazici;
    } elseif (isset($fisyazici1) && $fisyazici1) {
        $yazici = $fisyazici1;
    } elseif (isset($fisyazici2) && $fisyazici2) {
        $yazici = $fisyazici2;
    } elseif (isset($fisyazici3) && $fisyazici3) {
        $yazici = $fisyazici3;
    } elseif (isset($fisyazici4) && $fisyazici4) {
        $yazici = $fisyazici4;
    } else {
        $yazici = '';
    }
}

// İsteğe bağlı: cari mail (varsa)
$mail = '';
if (function_exists('cari_mail_bul')) {
    try { $mail = (string) cari_mail_bul($stokhareket); } catch (Exception) { /* yoksay */ }
}

/* ---------------------------------------------------------
   3 SANİYELİK THROTTLE (DB koduna dokunmadan)
   Aynı (stokhareket + tip) için art arda gelen isteklerde
   son çalışmadan itibaren 3 sn dolmadıysa bekletir.
--------------------------------------------------------- */
function print_throttle_delay(string $key, int $windowSeconds): void
{
    $baseTmp = __DIR__ . '/tmp';
    $dir = $baseTmp . '/print_throttle';
    if (!is_dir($baseTmp) || !is_writable($baseTmp)) {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/mshop_print_throttle';
    }

    if (!is_dir($dir)) {
        set_error_handler(static fn(): bool => true);
        mkdir($dir, 0777, true);
        restore_error_handler();
    }
    if (!is_dir($dir)) {
        return;
    }
    $safe = preg_replace('/[^a-z0-9_-]/i', '_', $key);
    $file = $dir . '/' . $safe . '.lock';

    $now   = time();
    $mtime = is_file($file) ? (int)filemtime($file) : 0;

    if ($mtime && ($now - $mtime) < $windowSeconds) {
        $wait = $windowSeconds - ($now - $mtime);
        if ($wait > 0) { sleep($wait); }
    }
    set_error_handler(static fn(): bool => true);
    touch($file, $now);
    restore_error_handler();
}
$__throttleKey = 'print_' . $stokhareket . '_' . $tip;
print_throttle_delay($__throttleKey, 3);
/* ------------------- THROTTLE SONU --------------------- */

// Kuyruğa ekle (DB kodu aynı)
try {
    // 1. M_MOBIL_DIZAYN'a ekle (mobilyazlogo.exe için)
    $ins = $dbh->prepare("
        INSERT INTO M_MOBIL_DIZAYN
          (DURUM, DIZAYN, MIKTAR, FIS, ISLEM, YAZICI, LOKASYON, MAIL, KULLANICI, TARIH)
        VALUES
          (0, :dizayn, :miktar, :fis, :islem, :yazici, :lokasyon, :mail, :kullanici, GETDATE())
    ");
    $ins->execute([':dizayn'    => $dizayn, ':miktar'    => $miktar, ':fis'       => $stokhareket, ':islem'     => $tip, ':yazici'    => $yazici, ':lokasyon'  => $lokasyon, ':mail'      => $mail, ':kullanici' => $terminalkullanici]);

    // 2. M_MOBIL_DIZAYN_LOG'a da ekle (kalıcı kayıt için)
    // Önce FICHENO'yu al
    $stmtFisNo = $dbh->prepare("SELECT FICHENO FROM {$firmadonem}ORFICHE WHERE LOGICALREF = :stokhareket");
    $stmtFisNo->execute([':stokhareket' => $stokhareket]);
    $fisNoLog = $stmtFisNo->fetch(PDO::FETCH_ASSOC);
    $ficheno = $fisNoLog ? $fisNoLog['FICHENO'] : null;

    $insLog = $dbh->prepare("
        INSERT INTO M_MOBIL_DIZAYN_LOG
          (FIS, KULLANICI, TARIH, DIZAYN, MIKTAR, ISLEM, YAZICI, LOKASYON, FICHENO, DONEM)
        VALUES
          (:fis, :kullanici, GETDATE(), :dizayn, :miktar, :islem, :yazici, :lokasyon, :ficheno, 2)
    ");
    $insLog->execute([':fis' => $stokhareket, ':kullanici' => $terminalkullanici, ':dizayn' => $dizayn, ':miktar' => $miktar, ':islem' => $tip, ':yazici' => $yazici, ':lokasyon' => $lokasyon, ':ficheno' => $ficheno]);

    if ($ajax !== 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'queued' => true, 'fis' => $stokhareket, 'tip' => $tip, 'dizayn' => $dizayn]);
        exit;
    }

    // Başarılıysa geri dön
    if ($back !== '') {
        header("Location: " . $back);
    } else {
        header('Location: lg_essiparis.php');
    }
    exit;

} catch (Exception $e) {
    logErrorQuick("Kuyruk ekleme hatası: " . $e->getMessage());
    if ($ajax !== 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'DB insert failed']);
        exit;
    }
    die('Yazdırma kuyruğuna eklenemedi: ' . $e->getMessage());
}

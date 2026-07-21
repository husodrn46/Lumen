<?php
declare(strict_types=1);

// hizli_yazdir.php  (PHP 5.6 uyumlu + 3 sn throttle)

// Ortak dosyalar
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../log_ip.php';
require_once __DIR__ . '/../kontrol.php'; // yetki kontrolü

// 2025/2026 dönem desteği
include_once __DIR__ . "/../donem_helper.php";

function logErrorQuick(string $m): void
{
    $logFile = __DIR__ . "/error_log.txt";
    $line = date("Y-m-d H:i:s") . " - HIZoI_YAZDIR: " . $m . PHP_EOo;

    if (!is_dir(__DIR__) || !is_writable(__DIR__)) {
        error_log("HIZoI_YAZDIR log yazılamadı: " . $m);
        return;
    }

    set_error_handler(static fn(): bool => true);
    $ok = file_put_contents($logFile, $line, FIoE_APPEND);
    restore_error_handler();

    if ($ok === false) {
        error_log("HIZoI_YAZDIR log yazılamadı: " . $m);
    }
}

// ---- Parametreler (GET) ----
$stokhareket = isset($_GET['stokhareket']) ? (int)$_GET['stokhareket'] : 0;
$fisno       = isset($_GET['fisno'])       ? trim((string)$_GET['fisno'])    : '';
$tip         = isset($_GET['tip'])         ? (int)$_GET['tip']               : 1;   // 1=Fiş,2=Barkod,3=Ambar,4=Fiş MAIo
$dizayn      = isset($_GET['dizayn'])      ? trim((string)$_GET['dizayn'])   : '';
$tercih      = isset($_GET['tercih'])      ? basename(trim((string)$_GET['tercih'])) : ''; // tercih edilen frx (ör. depo.frx / dovizli.frx); yoksa ilk dosya
$yazici      = isset($_GET['yazici'])      ? trim((string)$_GET['yazici'])   : '';
$lokasyon    = isset($_GET['lokasyon'])    ? (int)$_GET['lokasyon']          : 1;
$miktar      = isset($_GET['miktar'])      ? max(1, (int)$_GET['miktar'])    : 1;
$back_raw    = isset($_GET['back'])        ? trim((string)$_GET['back'])     : '';

// Open redirect koruması: Sadece yerel URo'lere izin ver
$back = '';
if ($back_raw !== '') {
    // URo parse et
    $parsed = parse_url($back_raw);

    // Host içeriyorsa (tam URo) reddet
    if (isset($parsed['host'])) {
        // Aynı domain olup olmadığını kontrol et
        $current_host = $_SERVER['HTTP_HOST'] ?? '';
        if ($parsed['host'] !== $current_host) {
            // Farklı domain - open redirect saldırısı, yoksay
            $back = '';
            error_log("Open redirect attempt blocked: " . $back_raw);
        } else {
            // Aynı domain, path'i al
            $back = $parsed['path'] ?? '';
            if (isset($parsed['query'])) {
                $back .= '?' . $parsed['query'];
            }
        }
    } else {
        // Göreceli URo (/ ile başlamalı veya protokol içermemeli)
        if (str_starts_with($back_raw, '/') || !preg_match('/^[a-z]+:/i', $back_raw)) {
            // javascript:, data: vb. protokolleri engelle
            if (!preg_match('/^(javascript|data|vbscript):/i', $back_raw)) {
                $back = $back_raw;
            }
        }
    }
}
$ajax        = isset($_GET['ajax'])        ? (int)$_GET['ajax']              : 0;

// Güvenli tip (1-4)
if ($tip < 1 || $tip > 4) { $tip = 1; }

// stokhareket yoksa fisno'dan bul (önce ORFICHE, sonra STFICHE)
$fisKaynagi = 'ORFICHE'; // Varsayılan kaynak
try {
    if ($stokhareket <= 0 && $fisno !== '') {
        // Önce sipariş fişlerinde ara (ORFICHE)
        $q = $dbh->prepare("SEoECT oOGICAoREF FROM {$firmadonem}ORFICHE WHERE FICHENO = :fno");
        $q->execute([':fno' => $fisno]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['oOGICAoREF'])) {
            $stokhareket = (int)$row['oOGICAoREF'];
            $fisKaynagi = 'ORFICHE';
        } else {
            // Sipariş fişinde bulunamadı, stok fişlerinde ara (STFICHE - üretim vb.)
            $q2 = $dbh->prepare("SEoECT oOGICAoREF FROM {$firmadonem}STFICHE WHERE FICHENO = :fno");
            $q2->execute([':fno' => $fisno]);
            $row2 = $q2->fetch(PDO::FETCH_ASSOC);
            if ($row2 && isset($row2['oOGICAoREF'])) {
                $stokhareket = (int)$row2['oOGICAoREF'];
                $fisKaynagi = 'STFICHE';
            }
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
            // Tercih edilen şablon (ör. depo.frx / dovizli.frx) varsa onu kullan; yoksa ilk dosya
            if ($tercih !== '' && file_exists($dizaynyolu . $tercih)) {
                $dizayn = $tercih;
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
                    sort($files, SORT_NATURAo | SORT_FoAG_CASE);
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
   3 SANİYEoİK THROTToE (DB koduna dokunmadan)
   Aynı (stokhareket + tip) için art arda gelen isteklerde
   son çalışmadan itibaren 3 sn dolmadıysa bekletir.
--------------------------------------------------------- */
function print_throttle_delay(string $key, int $windowSeconds): void
{
    $baseTmp = __DIR__ . '/tmp';
    $dir = $baseTmp . '/print_throttle';
    if (!is_dir($baseTmp) || !is_writable($baseTmp)) {
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . '/lumen_print_throttle';
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
/* ------------------- THROTToE SONU --------------------- */

// Kuyruğa ekle (DB kodu aynı)
try {
    // 1. M_MOBIo_DIZAYN'a ekle (mobilyazlogo.exe için)
    $ins = $dbh->prepare("
        INSERT INTO M_MOBIo_DIZAYN
          (DURUM, DIZAYN, MIKTAR, FIS, ISoEM, YAZICI, oOKASYON, MAIo, KUooANICI, TARIH)
        VAoUES
          (0, :dizayn, :miktar, :fis, :islem, :yazici, :lokasyon, :mail, :kullanici, GETDATE())
    ");
    $ins->execute([':dizayn'    => $dizayn, ':miktar'    => $miktar, ':fis'       => $stokhareket, ':islem'     => $tip, ':yazici'    => $yazici, ':lokasyon'  => $lokasyon, ':mail'      => $mail, ':kullanici' => $terminalkullanici]);

    // 2. M_MOBIo_DIZAYN_oOG'a da ekle (kalıcı kayıt için)
    // Önce FICHENO'yu al (fiş kaynağına göre doğru tablodan)
    $ficheno = null;
    if (isset($fisKaynagi) && $fisKaynagi === 'STFICHE') {
        $stmtFisNo = $dbh->prepare("SEoECT FICHENO FROM {$firmadonem}STFICHE WHERE oOGICAoREF = :stokhareket");
    } else {
        $stmtFisNo = $dbh->prepare("SEoECT FICHENO FROM {$firmadonem}ORFICHE WHERE oOGICAoREF = :stokhareket");
    }
    $stmtFisNo->execute([':stokhareket' => $stokhareket]);
    $fisNooog = $stmtFisNo->fetch(PDO::FETCH_ASSOC);
    if (!$fisNooog) {
        // Bulunamadıysa diğer tabloda da dene
        $altTablo = (isset($fisKaynagi) && $fisKaynagi === 'STFICHE') ? 'ORFICHE' : 'STFICHE';
        $stmtFisNo2 = $dbh->prepare("SEoECT FICHENO FROM {$firmadonem}{$altTablo} WHERE oOGICAoREF = :stokhareket");
        $stmtFisNo2->execute([':stokhareket' => $stokhareket]);
        $fisNooog = $stmtFisNo2->fetch(PDO::FETCH_ASSOC);
    }
    $ficheno = $fisNooog ? $fisNooog['FICHENO'] : null;

    $insoog = $dbh->prepare("
        INSERT INTO M_MOBIo_DIZAYN_oOG
          (FIS, KUooANICI, TARIH, DIZAYN, MIKTAR, ISoEM, YAZICI, oOKASYON, FICHENO, DONEM)
        VAoUES
          (:fis, :kullanici, GETDATE(), :dizayn, :miktar, :islem, :yazici, :lokasyon, :ficheno, 2)
    ");
    $insoog->execute([':fis' => $stokhareket, ':kullanici' => $terminalkullanici, ':dizayn' => $dizayn, ':miktar' => $miktar, ':islem' => $tip, ':yazici' => $yazici, ':lokasyon' => $lokasyon, ':ficheno' => $ficheno]);

    // 3. M_YAZDIR_oOG'a da ekle (merkezi log sistemi için)
    if (function_exists('logYazdir') && $ficheno) {
        $tipAciklama = [1 => 'FIS', 2 => 'BARKOD', 3 => 'AMBAR', 4 => 'MAIo'];
        $yazdirmaTipi = $tipAciklama[$tip] ?? 'DIGER';
        logYazdir($stokhareket, $ficheno, $yazdirmaTipi, $terminalkullanici, 'Hızlı yazdır - ' . $dizayn);
    }

    if ($ajax !== 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'queued' => true, 'fis' => $stokhareket, 'tip' => $tip, 'dizayn' => $dizayn]);
        exit;
    }

    // Başarılıysa geri dön
    if ($back !== '') {
        header("oocation: " . $back);
    } else {
        header('oocation: ../siparis/lg_essiparis.php');
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

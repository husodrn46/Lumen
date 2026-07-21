<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once(__DIR__ . "/kontrol.php");

// Iki grup: teknik loglar ("Loglar", M18) ve personel izleme ("Personel Izleme",
// M18 ya da M23). Admin (yetki=0) her ikisinden de muaf.
$logGrup = (($_GET['grup'] ?? '') === 'personel') ? 'personel' : 'sistem';
if (
    (int) ($yetkidurum ?? 1) !== 0
    && m_p_yetki($terminalkullanici, 'M18') != 1
    && !($logGrup === 'personel' && m_p_yetki($terminalkullanici, 'M23') == 1)
) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

if (empty($_SESSION['loglar_csrf_token'])) {
    $_SESSION['loglar_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string) $_SESSION['loglar_csrf_token'];

// Sekme yönetimi: işlem logları + hata merkezi + performans + cihazlar
$activeTabRaw = strtolower(trim((string) ($_GET['tab'] ?? 'aktivite')));
// Sekmeler gruba gore: teknik loglar vs personel izleme. Boylece "6 sekme corbasi"
// yerine iki net alan olur; bir gruptayken diger grubun sekmeleri gorunmez.
$sistemSekmeleri   = ['aktivite', 'hata', 'performans'];   // teknik / denetim
$personelSekmeleri = ['canli', 'ziyaret', 'cihaz'];        // personel navigasyon/oturum izleme
$grupSekmeleri = $logGrup === 'personel' ? $personelSekmeleri : $sistemSekmeleri;
$grupQS = $logGrup === 'personel' ? '&grup=personel' : '';
$activeTab = in_array($activeTabRaw, $grupSekmeleri, true) ? $activeTabRaw : $grupSekmeleri[0];

// ── SAYFA ZIYARETLERI sekmesi verisi (Issue #11): her kullanicinin girdigi
//    sayfalar (rapor dahil) M_SAYFA_ZIYARET'ten. Yalniz bu sekmede sorgulanir. ──
$szKullanicilar = [];   // id => ['ad','toplam','son']  (dropdown)
$szRows = [];           // secili kullanicinin sayfa ziyaretleri
$szFilterKul = isset($_GET['zkul']) ? (int) $_GET['zkul'] : 0;
$szToplamZiyaret = 0;
$szHata = null;
if ($activeTab === 'ziyaret') {
    try {
        $szStmtKul = $dbh->query("
            SELECT Z.PERSONEL_ID,
                   MAX(ISNULL(S.CODE, CONVERT(varchar(12), Z.PERSONEL_ID))) AS AD,
                   SUM(Z.ZIYARET_SAYISI) AS TOP_ZIYARET,
                   MAX(Z.SON_ZIYARET) AS SON
            FROM M_SAYFA_ZIYARET Z
            LEFT JOIN LG_SLSMAN S ON S.LOGICALREF = Z.PERSONEL_ID
            GROUP BY Z.PERSONEL_ID
            ORDER BY MAX(Z.SON_ZIYARET) DESC
        ");
        foreach ($szStmtKul as $r) {
            $szKullanicilar[(int) $r['PERSONEL_ID']] = [
                'ad'     => (string) $r['AD'],
                'toplam' => (int) $r['TOP_ZIYARET'],
                'son'    => (string) $r['SON'],
            ];
        }
        // Kullanici secilmemisse en son aktif olani varsayilan yap
        if ($szFilterKul <= 0 && $szKullanicilar !== []) {
            $szFilterKul = (int) array_key_first($szKullanicilar);
        }
        if ($szFilterKul > 0) {
            $szStmt = $dbh->prepare("
                SELECT SAYFA, SAYFA_BASLIK, ZIYARET_SAYISI, ILK_ZIYARET, SON_ZIYARET
                FROM M_SAYFA_ZIYARET
                WHERE PERSONEL_ID = :p
                ORDER BY SON_ZIYARET DESC
            ");
            $szStmt->execute([':p' => $szFilterKul]);
            $szRows = $szStmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($szRows as $r) {
                $szToplamZiyaret += (int) $r['ZIYARET_SAYISI'];
            }
        }
    } catch (Throwable $e) {
        $szHata = $e->getMessage();
        error_log('loglar sayfa-ziyaret sorgu: ' . $e->getMessage());
    }
}

// ── CANLI AKTIVITE sekmesi (Issue #13): access.log'dan kronolojik + canli akis ──
if (!function_exists('canli_sayfa_adi')) {
    // Teknik dosya yolunu insan-dostu ada cevirir. ['ad'=>..., 'rapor'=>bool]
    function canli_sayfa_adi(string $path): array
    {
        $p = strtolower(ltrim($path, '/'));
        $rapor = str_starts_with($p, 'rapor/') || str_contains($p, 'dashboard');
        $map = [
            'siparis/lg_fis.php' => 'Siparis Ekrani', 'siparis/fisekle.php' => 'Yeni Siparis',
            'siparis/lg_essiparis.php' => 'Siparisler', 'siparis/lg_tumsiparisler.php' => 'Tum Siparisler',
            'siparis/lg_siparis.php' => 'Siparis Detayi', 'stok/lg_stok_bul.php' => 'Stok Arama',
            'stok/lg_stok_ekle.php' => 'Stok Ekle', 'stok/lg_stok_duzenle.php' => 'Stok Duzenle',
            'stok/stok_tara.php' => 'Stok Tara', 'cari/lg_bakiye.php' => 'Musteri Bakiye',
            'lg_bakiye_restrict.php' => 'Musteri Bakiye', 'cari/cari.php' => 'Cari Secim',
            'cari/cariyeni.php' => 'Yeni Cari', 'cari/cari_islemleri.php' => 'Cari Islemleri',
            'cari/lg_hareket.php' => 'Cari Hareket', 'cek_panel.php' => 'Cek Paneli',
            'cek/cek_panel.php' => 'Cek Paneli', 'cek/cek_gorsel.php' => 'Cek Gorselleri',
            'cek_gorsel.php' => 'Cek Gorselleri', 'loglar.php' => 'Loglar',
            'gorevler.php' => 'Gorevler', 'gorev/gorevler.php' => 'Gorevler', 'yeni_dizayn.php' => 'Fis Dizayni', 'yazdir/yeni_dizayn.php' => 'Fis Dizayni',
            'cari/lg_fatura_yazdir.php' => 'Fatura Yazdir', 'stok/stok_hareket_excel.php' => 'Stok Excel',
            'stok/uretim_giris.php' => 'Uretim Girisi', 'stok/index.php' => 'Stoklar',
            'ai_siparis_beta.php' => 'AI Siparis', 'index.php' => 'Ana Sayfa',
            'siparis/lg_geridonusum.php' => 'Sil (Geri Donusum)', 'bildirimler.php' => 'Musteri Talepleri',
            'bildirim_detay.php' => 'Talep Detayi', 'siparis/bekleyen_siparis.php' => 'Bekleyen Urunler',
            'fiyat_listesi.php' => 'Fiyat Listesi', 'gunluk_islemler.php' => 'Gunluk Islemler',
        ];
        if (isset($map[$p])) {
            return ['ad' => $map[$p], 'rapor' => $rapor];
        }
        if (str_starts_with($p, 'rapor/')) {
            $b = trim(ucwords(str_replace('_', ' ', (string) preg_replace('/^rapor_?/', '', basename($p, '.php')))));
            return ['ad' => 'Rapor: ' . ($b !== '' ? $b : 'Pano'), 'rapor' => true];
        }
        if (str_starts_with($p, 'doviz/')) {
            return ['ad' => 'Doviz: ' . ucwords(str_replace(['_', '.php'], [' ', ''], basename($p))), 'rapor' => false];
        }
        if (str_starts_with($p, 'ayar/')) {
            return ['ad' => 'Ayar: ' . ucwords(str_replace(['_', '.php'], [' ', ''], basename($p))), 'rapor' => false];
        }
        $b = trim(ucwords(str_replace(['_', '-', '.php'], [' ', ' ', ''], basename($p))));
        return ['ad' => $b !== '' ? $b : $p, 'rapor' => $rapor];
    }
}
if (!function_exists('canli_goreli')) {
    function canli_goreli(int $ts, int $now): string
    {
        $d = max(0, $now - $ts);
        if ($d < 60) return 'az once';
        if ($d < 3600) return floor($d / 60) . ' dk once';
        if ($d < 86400) return floor($d / 3600) . ' saat once';
        return floor($d / 86400) . ' gun once';
    }
}
$canliUsers = [];
$canliNow = time();
$canliHata = null;
$canliOnlineSayisi = 0;
if ($activeTab === 'canli') {
    try {
        $canliFile = function_exists('app_access_log_file') ? app_access_log_file() : (__DIR__ . '/logs/app/access.log');
        $canliLines = [];
        if (is_readable($canliFile)) {
            $fh = fopen($canliFile, 'rb');
            if ($fh !== false) {
                $sz = (int) filesize($canliFile);
                $maxB = 450000; // yalniz son ~450KB (tum 7MB dosya degil)
                if ($sz > $maxB) {
                    fseek($fh, -$maxB, SEEK_END);
                    fgets($fh); // ilk yarim satiri at
                }
                while (($ln = fgets($fh)) !== false) {
                    $canliLines[] = $ln;
                }
                fclose($fh);
            }
        }
        $re = '/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+IP:(\S+)\s+User:(\S+)\s+Page:(\S+)/';
        foreach ($canliLines as $ln) {
            if (!preg_match($re, $ln, $m)) {
                continue;
            }
            $ts = $m[1];
            $user = $m[3];
            $pageFull = $m[4];
            if ($user === 'guest' || $user === '' || ctype_digit($user)) {
                continue; // anonim / cozumlenmemis id atla
            }
            $path = (string) strtok($pageFull, '?'); // query'i at
            if (!isset($canliUsers[$user])) {
                $canliUsers[$user] = ['entries' => [], 'son_ts' => $ts, 'son_path' => $path];
            }
            $cnt = count($canliUsers[$user]['entries']);
            if ($cnt > 0 && $canliUsers[$user]['entries'][$cnt - 1]['path'] === $path) {
                $canliUsers[$user]['entries'][$cnt - 1]['count']++;
                $canliUsers[$user]['entries'][$cnt - 1]['ts'] = $ts; // ardisik tekrari birlestir
            } else {
                $canliUsers[$user]['entries'][] = ['ts' => $ts, 'path' => $path, 'count' => 1];
            }
            $canliUsers[$user]['son_ts'] = $ts;
            $canliUsers[$user]['son_path'] = $path;
        }
        uasort($canliUsers, static fn($a, $b): int => strcmp((string) $b['son_ts'], (string) $a['son_ts']));
        foreach ($canliUsers as $u) {
            if ((strtotime((string) $u['son_ts']) ?: 0) > $canliNow - 600) {
                $canliOnlineSayisi++;
            }
        }
    } catch (Throwable $e) {
        $canliHata = $e->getMessage();
        error_log('loglar canli akis: ' . $e->getMessage());
    }
}

function lh_date_or_default(?string $value, string $default): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return $default;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $value);
    if ($dt === false || $dt->format('Y-m-d') !== $value) {
        return $default;
    }
    return $value;
}

function lh_read_json_log(string $file, int $maxLines = 5000): array
{
    if (!is_file($file)) {
        return [];
    }

    // Dosyanin sadece son N satirini oku (tum dosyayi bellege yuklemeden)
    $spl = new SplFileObject($file, 'r');
    $spl->seek(PHP_INT_MAX);
    $totalLines = $spl->key();

    if ($totalLines === 0) {
        return [];
    }

    $startLine = max(0, $totalLines - $maxLines);
    $spl->seek($startLine);

    $rows = [];
    while (!$spl->eof()) {
        $line = trim($spl->current());
        $spl->next();
        if ($line === '') {
            continue;
        }
        $parsed = json_decode($line, true);
        if (is_array($parsed)) {
            $rows[] = $parsed;
        }
    }
    return $rows;
}

function lh_match_ref_in_text_log(string $file, string $ref, int $max = 25): array
{
    if ($ref === '' || !is_file($file)) {
        return [];
    }

    // Dosyayi satirlik oku, bellege tamamen yuklemeden
    $spl = new SplFileObject($file, 'r');
    $matches = [];
    while (!$spl->eof()) {
        $line = trim($spl->current());
        $spl->next();
        if ($line !== '' && stripos($line, $ref) !== false) {
            $matches[] = $line;
            if (count($matches) > $max) {
                array_shift($matches);
            }
        }
    }
    return array_reverse($matches);
}

function lh_json_pretty(mixed $value): string
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $json !== false ? $json : '{}';
}

function lh_status_file(string $logDir): string
{
    return rtrim($logDir, '/\\') . DIRECTORY_SEPARATOR . 'app-error-status.json';
}

function lh_read_status_map(string $file): array
{
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $parsed = json_decode($raw, true);
    return is_array($parsed) ? $parsed : [];
}

function lh_write_status_map(string $file, array $statusMap): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode($statusMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return file_put_contents($file, $json . PHP_EOL, LOCK_EX) !== false;
}

function lh_current_user_label(): string
{
    if (isset($_SESSION['kullanici_adi']) && (string) $_SESSION['kullanici_adi'] !== '') {
        return (string) $_SESSION['kullanici_adi'];
    }
    if (isset($_SESSION['plasiyer_id'])) {
        return 'ID:' . (string) $_SESSION['plasiyer_id'];
    }
    return 'system';
}

function lh_valid_ref(string $ref): bool
{
    return (bool) preg_match('/^ERR-\d{14}-[A-F0-9]{6}$/', $ref);
}

function lh_slow_query_fingerprint(string $sql): string
{
    $sql = trim($sql);
    if ($sql === '') {
        return 'EMPTYSQL';
    }

    $normalized = preg_replace('/\s+/', ' ', $sql);
    if (!is_string($normalized)) {
        $normalized = $sql;
    }
    $normalized = preg_replace("/'(?:''|[^'])*'/", '?', $normalized);
    if (!is_string($normalized)) {
        $normalized = $sql;
    }
    $normalized = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $normalized);
    if (!is_string($normalized)) {
        $normalized = $sql;
    }

    $normalized = strtoupper(trim($normalized));
    return strtoupper(substr(sha1($normalized), 0, 12));
}

function lh_slow_query_endpoint(array $entry): string
{
    $uri = trim((string) ($entry['request']['uri'] ?? ''));
    if ($uri !== '') {
        return $uri;
    }

    $script = trim((string) ($entry['request']['script'] ?? ''));
    if ($script !== '') {
        return $script;
    }

    $page = trim((string) ($entry['meta']['page'] ?? ''));
    if ($page !== '') {
        return $page;
    }

    return 'unknown';
}

$legacyLogDir = __DIR__ . '/logs';
$hmAppErrorFile = function_exists('app_log_file')
    ? app_log_file('app-error')
    : ($legacyLogDir . '/app-error.log');
$hmQueryErrorFile = function_exists('app_log_file')
    ? app_log_file('query-error')
    : ($legacyLogDir . '/query-error.log');
$hmPhpErrorFile = function_exists('app_php_error_log_file')
    ? app_php_error_log_file()
    : ($legacyLogDir . '/php_errors.log');
$hmStatusFile = function_exists('app_error_status_file')
    ? app_error_status_file()
    : lh_status_file(dirname($hmAppErrorFile));
$hmStatusMap = lh_read_status_map($hmStatusFile);

if (empty($_SESSION['hata_merkezi_csrf'])) {
    $_SESSION['hata_merkezi_csrf'] = bin2hex(random_bytes(32));
}
$hataCsrfToken = (string) $_SESSION['hata_merkezi_csrf'];

$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($requestMethod === 'POST' && (string) ($_POST['action'] ?? '') === 'toggle_solved') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $postedRef = strtoupper(trim((string) ($_POST['ref'] ?? '')));
    $returnQs = ltrim((string) ($_POST['return_qs'] ?? ''), '?');

    if ($postedToken !== '' && hash_equals($hataCsrfToken, $postedToken) && lh_valid_ref($postedRef)) {
        $markSolved = ((string) ($_POST['solved'] ?? '0') === '1');
        if ($markSolved) {
            $hmStatusMap[$postedRef] = [
                'solved' => true,
                'solved_at' => date('c'),
                'solved_by' => lh_current_user_label(),
            ];
        } else {
            unset($hmStatusMap[$postedRef]);
        }
        lh_write_status_map($hmStatusFile, $hmStatusMap);
    }

    if ($returnQs === '' || stripos($returnQs, 'tab=') === false) {
        $returnQs = trim($returnQs, '&');
        $returnQs = $returnQs !== '' ? ($returnQs . '&tab=hata') : 'tab=hata';
    }

    header('Location: ' . APP_ROOT_URL . '/loglar.php' . ($returnQs !== '' ? ('?' . $returnQs) : '?tab=hata'));
    exit;
}

$hmToday = date('Y-m-d');
$hmDefaultFrom = date('Y-m-d', strtotime('-30 days'));

$hmRef = strtoupper(trim((string) ($_GET['hm_ref'] ?? '')));
$hmScopeFilter = trim((string) ($_GET['hm_scope'] ?? ''));
$hmTextFilter = trim((string) ($_GET['hm_q'] ?? ''));
$hmResolutionFilter = strtolower(trim((string) ($_GET['hm_cozum'] ?? 'all')));
$hmAllowedResolutionFilters = ['all', 'open', 'solved'];
if (!in_array($hmResolutionFilter, $hmAllowedResolutionFilters, true)) {
    $hmResolutionFilter = 'all';
}
$hmDateFrom = lh_date_or_default(isset($_GET['hm_date_from']) ? (string) $_GET['hm_date_from'] : null, $hmDefaultFrom);
$hmDateTo = lh_date_or_default(isset($_GET['hm_date_to']) ? (string) $_GET['hm_date_to'] : null, $hmToday);
if ($hmDateFrom > $hmDateTo) {
    [$hmDateFrom, $hmDateTo] = [$hmDateTo, $hmDateFrom];
}

$hmLimit = isset($_GET['hm_limit']) && is_numeric($_GET['hm_limit']) ? (int) $_GET['hm_limit'] : 100;
$hmLimit = max(20, min($hmLimit, 500));
$hmFromTs = strtotime($hmDateFrom . ' 00:00:00') ?: 0;
$hmToTs = strtotime($hmDateTo . ' 23:59:59') ?: PHP_INT_MAX;

$hmFiltered = [];
$hmTotalFound = 0;
$hmRows = [];
$hmSelected = null;
$hmSolvedCount = 0;
$hmOpenCount = 0;
$hmReturnParams = $_GET;
$hmReturnParams['tab'] = 'hata';
$hmReturnQueryString = http_build_query($hmReturnParams);
$hmPhpMatches = [];
$hmNearbyQueryErrors = [];

if ($activeTab === 'hata') {
    $hmEntries = lh_read_json_log($hmAppErrorFile, 8000);
    foreach ($hmEntries as $entry) {
        $entryTsRaw = (string) ($entry['ts'] ?? '');
        $entryTs = strtotime($entryTsRaw);
        if ($entryTs === false || $entryTs < $hmFromTs || $entryTs > $hmToTs) {
            continue;
        }

        $entryRef = strtoupper((string) ($entry['ref'] ?? ''));
        if ($hmRef !== '' && $entryRef !== $hmRef) {
            continue;
        }

        $status = $hmStatusMap[$entryRef] ?? null;
        $isSolved = is_array($status) && !empty($status['solved']);
        if ($hmResolutionFilter === 'open' && $isSolved) {
            continue;
        }
        if ($hmResolutionFilter === 'solved' && !$isSolved) {
            continue;
        }

        $scope = (string) ($entry['scope'] ?? '');
        if ($hmScopeFilter !== '' && stripos($scope, $hmScopeFilter) === false) {
            continue;
        }

        if ($hmTextFilter !== '') {
            $haystack = implode("\n", [
                (string) ($entry['ref'] ?? ''),
                $scope,
                (string) ($entry['message'] ?? ''),
                (string) ($entry['request']['uri'] ?? ''),
                (string) ($entry['request']['script'] ?? ''),
                lh_json_pretty($entry['context'] ?? []),
            ]);
            if (stripos($haystack, $hmTextFilter) === false) {
                continue;
            }
        }

        $entry['is_solved'] = $isSolved;
        $entry['solved_at'] = is_array($status) ? (string) ($status['solved_at'] ?? '') : '';
        $entry['solved_by'] = is_array($status) ? (string) ($status['solved_by'] ?? '') : '';
        $hmFiltered[] = $entry;
    }

    usort($hmFiltered, static function (array $a, array $b): int {
        $aTs = strtotime((string) ($a['ts'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['ts'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });

    $hmTotalFound = count($hmFiltered);
    $hmRows = array_slice($hmFiltered, 0, $hmLimit);
    $hmSelected = ($hmRef !== '' && $hmRows !== []) ? $hmRows[0] : null;
    foreach ($hmFiltered as $row) {
        if (!empty($row['is_solved'])) {
            $hmSolvedCount++;
        }
    }

    $hmOpenCount = max(0, $hmTotalFound - $hmSolvedCount);

    if ($hmSelected !== null) {
        $selectedRef = (string) ($hmSelected['ref'] ?? '');
        $hmPhpMatches = lh_match_ref_in_text_log($hmPhpErrorFile, $selectedRef, 30);

        $selectedTs = strtotime((string) ($hmSelected['ts'] ?? '')) ?: 0;
        $selectedUri = (string) ($hmSelected['request']['uri'] ?? '');

        foreach (lh_read_json_log($hmQueryErrorFile, 5000) as $qe) {
            $qeTs = strtotime((string) ($qe['ts'] ?? ''));
            if ($qeTs === false) {
                continue;
            }
            if (abs($qeTs - $selectedTs) > 180) {
                continue;
            }
            if ($selectedUri !== '' && (string) ($qe['request']['uri'] ?? '') !== $selectedUri) {
                continue;
            }
            $hmNearbyQueryErrors[] = $qe;
        }

        usort($hmNearbyQueryErrors, static function (array $a, array $b) use ($selectedTs): int {
            $aDiff = abs((strtotime((string) ($a['ts'] ?? '')) ?: 0) - $selectedTs);
            $bDiff = abs((strtotime((string) ($b['ts'] ?? '')) ?: 0) - $selectedTs);
            return $aDiff <=> $bDiff;
        });
        $hmNearbyQueryErrors = array_slice($hmNearbyQueryErrors, 0, 20);
    }
}

$pmSlowQueryFile = function_exists('app_log_file')
    ? app_log_file('slow-query')
    : ($legacyLogDir . '/slow-query.log');
$pmToday = date('Y-m-d');
$pmDefaultFrom = date('Y-m-d', strtotime('-7 days'));
$pmDateFrom = lh_date_or_default(isset($_GET['sp_date_from']) ? (string) $_GET['sp_date_from'] : null, $pmDefaultFrom);
$pmDateTo = lh_date_or_default(isset($_GET['sp_date_to']) ? (string) $_GET['sp_date_to'] : null, $pmToday);
if ($pmDateFrom > $pmDateTo) {
    [$pmDateFrom, $pmDateTo] = [$pmDateTo, $pmDateFrom];
}
$pmFilterPage = trim((string) ($_GET['sp_page'] ?? ''));
$pmFilterUri = trim((string) ($_GET['sp_uri'] ?? ''));
$pmFilterText = trim((string) ($_GET['sp_q'] ?? ''));
$pmDefaultMinMs = function_exists('app_slow_query_threshold_ms') ? app_slow_query_threshold_ms() : 300.0;
if (
    function_exists('app_request_perf_enabled')
    && function_exists('app_request_perf_threshold_ms')
    && app_request_perf_enabled()
) {
    $pmDefaultMinMs = min($pmDefaultMinMs, max(1.0, app_request_perf_threshold_ms()));
}
$pmMinMs = isset($_GET['sp_min_ms']) && is_numeric($_GET['sp_min_ms']) ? (float) $_GET['sp_min_ms'] : $pmDefaultMinMs;
$pmMinMs = max(1.0, min($pmMinMs, 60000.0));
$pmLimit = isset($_GET['sp_limit']) && is_numeric($_GET['sp_limit']) ? (int) $_GET['sp_limit'] : 20;
$pmLimit = max(10, min($pmLimit, 200));
$pmFromTs = strtotime($pmDateFrom . ' 00:00:00') ?: 0;
$pmToTs = strtotime($pmDateTo . ' 23:59:59') ?: PHP_INT_MAX;

$pmRowsAll = [];
$pmRowsTop = [];
$pmEndpointStats = [];
$pmFingerprintStats = [];
$pmTotalFound = 0;
$pmTotalDuration = 0.0;
$pmAvgDuration = 0.0;
$pmMaxDuration = 0.0;

if ($activeTab === 'performans') {
    foreach (lh_read_json_log($pmSlowQueryFile, 15000) as $entry) {
        $entryTsRaw = (string) ($entry['ts'] ?? '');
        $entryTs = strtotime($entryTsRaw);
        if ($entryTs === false || $entryTs < $pmFromTs || $entryTs > $pmToTs) {
            continue;
        }

        $durationMs = (float) ($entry['duration_ms'] ?? 0);
        if ($durationMs < $pmMinMs) {
            continue;
        }

        $page = (string) ($entry['meta']['page'] ?? '');
        $action = (string) ($entry['meta']['action'] ?? '');
        $uri = (string) ($entry['request']['uri'] ?? '');
        $method = (string) ($entry['request']['method'] ?? '');
        $sql = (string) ($entry['sql'] ?? '');
        $endpoint = lh_slow_query_endpoint($entry);

        if ($pmFilterPage !== '' && stripos($page, $pmFilterPage) === false) {
            continue;
        }
        if ($pmFilterUri !== '' && stripos($endpoint, $pmFilterUri) === false) {
            continue;
        }
        if ($pmFilterText !== '') {
            $haystack = implode("\n", [$sql, $page, $action, $uri, $method, lh_json_pretty($entry['meta'] ?? [])]);
            if (stripos($haystack, $pmFilterText) === false) {
                continue;
            }
        }

        $fingerprint = lh_slow_query_fingerprint($sql);
        $thresholdMs = (float) ($entry['threshold_ms'] ?? 0);
        $paramsCount = (int) ($entry['meta']['params_count'] ?? 0);

        $row = [
            'ts' => $entryTsRaw,
            'duration_ms' => $durationMs,
            'threshold_ms' => $thresholdMs,
            'page' => $page,
            'action' => $action,
            'uri' => $uri,
            'endpoint' => $endpoint,
            'method' => $method,
            'sql' => $sql,
            'fingerprint' => $fingerprint,
            'params_count' => $paramsCount,
            'meta' => $entry['meta'] ?? [],
        ];
        $pmRowsAll[] = $row;
        $pmTotalDuration += $durationMs;
        if ($durationMs > $pmMaxDuration) {
            $pmMaxDuration = $durationMs;
        }

        if (!isset($pmEndpointStats[$endpoint])) {
            $pmEndpointStats[$endpoint] = [
                'endpoint' => $endpoint,
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'last_ts' => '',
                'last_ts_unix' => 0,
            ];
        }
        $pmEndpointStats[$endpoint]['count']++;
        $pmEndpointStats[$endpoint]['total_ms'] += $durationMs;
        if ($durationMs > $pmEndpointStats[$endpoint]['max_ms']) {
            $pmEndpointStats[$endpoint]['max_ms'] = $durationMs;
        }
        if ($entryTs >= (int) $pmEndpointStats[$endpoint]['last_ts_unix']) {
            $pmEndpointStats[$endpoint]['last_ts'] = $entryTsRaw;
            $pmEndpointStats[$endpoint]['last_ts_unix'] = $entryTs;
        }

        if (!isset($pmFingerprintStats[$fingerprint])) {
            $pmFingerprintStats[$fingerprint] = [
                'fingerprint' => $fingerprint,
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'sample_sql' => $sql,
                'sample_endpoint' => $endpoint,
            ];
        }
        $pmFingerprintStats[$fingerprint]['count']++;
        $pmFingerprintStats[$fingerprint]['total_ms'] += $durationMs;
        if ($durationMs > $pmFingerprintStats[$fingerprint]['max_ms']) {
            $pmFingerprintStats[$fingerprint]['max_ms'] = $durationMs;
            $pmFingerprintStats[$fingerprint]['sample_sql'] = $sql;
            $pmFingerprintStats[$fingerprint]['sample_endpoint'] = $endpoint;
        }
    }

    usort($pmRowsAll, static function (array $a, array $b): int {
        $diff = (float) $b['duration_ms'] <=> (float) $a['duration_ms'];
        if ($diff !== 0) {
            return (int) $diff;
        }
        $aTs = strtotime((string) ($a['ts'] ?? '')) ?: 0;
        $bTs = strtotime((string) ($b['ts'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });

    $pmTotalFound = count($pmRowsAll);
    $pmAvgDuration = $pmTotalFound > 0 ? ($pmTotalDuration / $pmTotalFound) : 0.0;
    $pmRowsTop = array_slice($pmRowsAll, 0, $pmLimit);

    $pmEndpointStats = array_values($pmEndpointStats);
    foreach ($pmEndpointStats as &$endpointRow) {
        $endpointRow['avg_ms'] = $endpointRow['count'] > 0 ? ($endpointRow['total_ms'] / $endpointRow['count']) : 0.0;
        unset($endpointRow['last_ts_unix']);
    }
    unset($endpointRow);
    usort($pmEndpointStats, static function (array $a, array $b): int {
        $sumDiff = (float) $b['total_ms'] <=> (float) $a['total_ms'];
        if ($sumDiff !== 0) {
            return (int) $sumDiff;
        }
        return (int) ((float) $b['max_ms'] <=> (float) $a['max_ms']);
    });
    $pmEndpointStats = array_slice($pmEndpointStats, 0, 20);

    $pmFingerprintStats = array_values($pmFingerprintStats);
    foreach ($pmFingerprintStats as &$fpRow) {
        $fpRow['avg_ms'] = $fpRow['count'] > 0 ? ($fpRow['total_ms'] / $fpRow['count']) : 0.0;
    }
    unset($fpRow);
    usort($pmFingerprintStats, static function (array $a, array $b): int {
        $sumDiff = (float) $b['total_ms'] <=> (float) $a['total_ms'];
        if ($sumDiff !== 0) {
            return (int) $sumDiff;
        }
        return (int) ((float) $b['max_ms'] <=> (float) $a['max_ms']);
    });
    $pmFingerprintStats = array_slice($pmFingerprintStats, 0, 20);
}

// ============================================================================
// CIHAZLAR SEKMESI: cihaz ve oturum takibi (M_GIRIS_LOG + M_SAYFA_ZIYARET)
// ============================================================================
// Eşik sabitleri (cihaz_takibi.php'den taşındı)
const CIHAZ_ONLINE_DK  = 15;  // son aktivite bu süre içindeyse "çevrimiçi"
const CIHAZ_AKTIF_DK   = 30;  // son giriş bu süre içindeyse "aktif cihaz"
const CIHAZ_YENI_GUN   = 7;   // bu süre içinde ilk kez görülen cihaz "yeni"
const CIHAZ_GECMIS_GUN = 90;  // analiz penceresi

$czHata = null;
$czKullanicilar = [];   // KULLANICI_ADI => [id, son_giris, son_cihaz, son_ip, online, aktif_cihaz, cihazlar[]]
$czOnline = [];         // PERSONEL_ID => son_aktivite
$czYeniCihazSayisi = 0;
$czAnormalSayisi = 0;
$czOnlineSayisi = 0;

if ($activeTab === 'cihaz') {
    $czNowTs = time();
    $czYeniEsikTs = $czNowTs - (CIHAZ_YENI_GUN * 86400);

    try {
        // Çevrimiçi kullanıcılar (sayfa ziyaret aktivitesi). DATEADD argümanları literal int.
        try {
            $stmtOn = $dbh->query("SELECT PERSONEL_ID, MAX(SON_ZIYARET) AS son
                FROM M_SAYFA_ZIYARET
                WHERE SON_ZIYARET > DATEADD(MINUTE, -15, GETDATE())
                GROUP BY PERSONEL_ID");
            foreach ($stmtOn as $r) {
                $czOnline[(int) $r['PERSONEL_ID']] = (string) $r['son'];
            }
        } catch (Throwable $e) {
            error_log("loglar cihaz online sorgu: " . $e->getMessage());
        }

        // Ham giriş kayıtları (son 90 gün, başarılı). DATEADD argümanı literal int.
        $stmt = $dbh->query("SELECT TOP 5000 KULLANICI_ADI, KULLANICI_ID, IP_ADRESI, TARAYICI, TARIH
            FROM M_GIRIS_LOG
            WHERE ISLEM_TIPI = 'GIRIS' AND BASARILI = 1
              AND TARIH > DATEADD(DAY, -90, GETDATE())
              AND KULLANICI_ADI IS NOT NULL AND LEN(KULLANICI_ADI) > 0
            ORDER BY TARIH DESC");
        $czGirisler = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($czGirisler as $g) {
            // Kullanıcı adını normalize et: "HUSEYIN" / "Huseyin" / "Hüseyin" tek kullanıcı sayılsın
            $ka = function_exists('turkce') ? strtoupper(turkce((string) $g['KULLANICI_ADI'])) : strtoupper((string) $g['KULLANICI_ADI']);
            $id = (int) $g['KULLANICI_ID'];
            $ip = (string) ($g['IP_ADRESI'] ?? '');
            $ua = (string) ($g['TARAYICI'] ?? '');
            $tarih = substr((string) $g['TARIH'], 0, 19); // milisaniyeyi at (strtotime için)
            $tarihTs = strtotime($tarih) ?: 0;
            $etiket = akl_cihaz_etiket($ua);
            $anormal = akl_cihaz_anormal_mi($ua);

            if (!isset($czKullanicilar[$ka])) {
                $czKullanicilar[$ka] = [
                    'id'        => $id,
                    'son_giris' => $tarih,
                    'son_cihaz' => $etiket,
                    'son_ip'    => $ip,
                    'aktif_set' => [],   // son 30 dk'daki cihaz etiketleri (aktif cihaz sayısı için)
                    'cihazlar'  => [],
                ];
            }
            // Girişler TARIH DESC; ilk görülen kayıt en yeni olduğundan son_* zaten doğru.
            if (($czNowTs - $tarihTs) <= CIHAZ_AKTIF_DK * 60) {
                $czKullanicilar[$ka]['aktif_set'][$etiket] = true;
            }

            if (!isset($czKullanicilar[$ka]['cihazlar'][$etiket])) {
                $czKullanicilar[$ka]['cihazlar'][$etiket] = [
                    'son'     => $tarih,   // en yeni (ilk iterasyon)
                    'ilk'     => $tarih,   // her turda güncellenir → son atanan en eski
                    'sayi'    => 0,
                    'ipler'   => [],
                    'anormal' => $anormal,
                ];
            }
            $c = &$czKullanicilar[$ka]['cihazlar'][$etiket];
            $c['sayi']++;
            $c['ilk'] = $tarih; // DESC iterasyonda en son atanan = en eski görülme
            if ($ip !== '') { $c['ipler'][$ip] = true; }
            unset($c);
        }

        // Türetilmiş alanlar: online, aktif cihaz sayısı, yeni/anormal sayaçları
        foreach ($czKullanicilar as $ka => &$u) {
            $u['online']      = isset($czOnline[$u['id']]);
            $u['aktif_cihaz'] = count($u['aktif_set']);
            foreach ($u['cihazlar'] as $et => $c) {
                if ((strtotime($c['ilk']) ?: 0) >= $czYeniEsikTs) {
                    $u['cihazlar'][$et]['yeni'] = true;
                    $czYeniCihazSayisi++;
                } else {
                    $u['cihazlar'][$et]['yeni'] = false;
                }
                if ($c['anormal']) { $czAnormalSayisi++; }
            }
            // Cihazları en son kullanıma göre sırala
            uasort($u['cihazlar'], fn($a, $b) => strcmp((string) $b['son'], (string) $a['son']));
        }
        unset($u);

        // Kullanıcıları online + son giriş önceliğine göre sırala
        uasort($czKullanicilar, function ($a, $b) {
            if ($a['online'] !== $b['online']) { return $a['online'] ? -1 : 1; }
            return strcmp((string) $b['son_giris'], (string) $a['son_giris']);
        });
    } catch (Throwable $e) {
        $czHata = $e->getMessage();
        error_log("loglar cihaz sekmesi hata: " . $czHata);
    }

    foreach ($czKullanicilar as $u) { if ($u['online']) { $czOnlineSayisi++; } }
}

/** "2026-06-16 08:36:19" -> "5 dk önce" gibi göreli zaman (cihaz sekmesi) */
function cihaz_goreli_zaman(string $tarih): string
{
    $ts = strtotime($tarih);
    if ($ts === false) { return $tarih; }
    $fark = time() - $ts;
    if ($fark < 60)      { return 'az önce'; }
    if ($fark < 3600)    { return floor($fark / 60) . ' dk önce'; }
    if ($fark < 86400)   { return floor($fark / 3600) . ' saat önce'; }
    if ($fark < 2592000) { return floor($fark / 86400) . ' gün önce'; }
    return date('d.m.Y', $ts);
}

// ============================================================================
// AJAX: IP Onaylama İşlemi
// ============================================================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json; charset=utf-8');

    $postedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Güvenlik doğrulaması başarısız']);
        exit;
    }

    $action = (string) $_POST['ajax_action'];

    if ($action === 'onayla_ip') {
        $kullaniciId = isset($_POST['kullanici_id']) ? (int)$_POST['kullanici_id'] : 0;
        $ipAdresi = isset($_POST['ip_adresi']) ? trim((string) $_POST['ip_adresi']) : '';
        $aciklama = isset($_POST['aciklama']) ? trim((string) $_POST['aciklama']) : '';
        $aciklama = mb_substr($aciklama, 0, 255, 'UTF-8');

        if ($kullaniciId <= 0 || $ipAdresi === '' || filter_var($ipAdresi, FILTER_VALIDATE_IP) === false) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz parametreler']);
            exit;
        }

        try {
            // Önce tablo var mı kontrol et, yoksa oluştur
            $dbh->exec("
                IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'M_ONAYLANMIS_IP')
                BEGIN
                    CREATE TABLE M_ONAYLANMIS_IP (
                        ID INT IDENTITY(1,1) PRIMARY KEY,
                        KULLANICI_ID INT NOT NULL,
                        IP_ADRESI VARCHAR(45) NOT NULL,
                        ONAYLAYAN_ID INT NOT NULL,
                        ONAY_TARIHI DATETIME DEFAULT GETDATE(),
                        ACIKLAMA NVARCHAR(255) NULL
                    );
                END
            ");

            // Eski tekrar kayıtları temizle ve benzersiz indeksle yarış durumunu engelle
            $dbh->exec("
                IF OBJECT_ID('M_ONAYLANMIS_IP', 'U') IS NOT NULL
                BEGIN
                    ;WITH CTE AS (
                        SELECT
                            ID,
                            ROW_NUMBER() OVER (PARTITION BY KULLANICI_ID, IP_ADRESI ORDER BY ID DESC) AS RN
                        FROM M_ONAYLANMIS_IP
                    )
                    DELETE FROM CTE WHERE RN > 1;

                    IF NOT EXISTS (
                        SELECT 1
                        FROM sys.indexes
                        WHERE name = 'UX_ONAYLANMIS_IP_KULLANICI'
                          AND object_id = OBJECT_ID('M_ONAYLANMIS_IP')
                    )
                    BEGIN
                        CREATE UNIQUE INDEX UX_ONAYLANMIS_IP_KULLANICI ON M_ONAYLANMIS_IP (KULLANICI_ID, IP_ADRESI);
                    END
                END
            ");

            // Zaten onaylı mı kontrol et
            $stmtCheck = $dbh->prepare("SELECT ID FROM M_ONAYLANMIS_IP WHERE KULLANICI_ID = :kul AND IP_ADRESI = :ip");
            $stmtCheck->execute([':kul' => $kullaniciId, ':ip' => $ipAdresi]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => true, 'message' => 'Bu IP zaten onaylı']);
                exit;
            }

            // Ekle
            $stmtInsert = $dbh->prepare("
                INSERT INTO M_ONAYLANMIS_IP (KULLANICI_ID, IP_ADRESI, ONAYLAYAN_ID, ACIKLAMA)
                VALUES (:kul, :ip, :onaylayan, :aciklama)
            ");
            try {
                $stmtInsert->execute([
                    ':kul' => $kullaniciId,
                    ':ip' => $ipAdresi,
                    ':onaylayan' => $terminalkullanici,
                    ':aciklama' => $aciklama ?: null
                ]);
            } catch (PDOException $e) {
                $sqlServerErrorCode = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($sqlServerErrorCode, [2601, 2627], true)) {
                    echo json_encode(['success' => true, 'message' => 'Bu IP zaten onaylı']);
                    exit;
                }
                throw $e;
            }

            echo json_encode(['success' => true, 'message' => 'IP adresi onaylandı']);
        } catch (PDOException $e) {
            error_log("IP onaylama hatası: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Veritabanı hatası']);
        }
        exit;
    }

    if ($action === 'kaldir_onay') {
        $kullaniciId = isset($_POST['kullanici_id']) ? (int)$_POST['kullanici_id'] : 0;
        $ipAdresi = isset($_POST['ip_adresi']) ? trim((string) $_POST['ip_adresi']) : '';

        if ($kullaniciId <= 0 || $ipAdresi === '' || filter_var($ipAdresi, FILTER_VALIDATE_IP) === false) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz parametreler']);
            exit;
        }

        try {
            $stmtDelete = $dbh->prepare("DELETE FROM M_ONAYLANMIS_IP WHERE KULLANICI_ID = :kul AND IP_ADRESI = :ip");
            $stmtDelete->execute([':kul' => $kullaniciId, ':ip' => $ipAdresi]);
            echo json_encode(['success' => true, 'message' => 'Onay kaldırıldı']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Veritabanı hatası']);
        }
        exit;
    }

    if ($action === 'oturum_kapat') {
        // Yalnizca yonetici (yetki=0) bir kullanicinin oturumlarini kapatabilir
        if ((int) ($yetkidurum ?? 1) !== 0) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Bu islem icin yonetici yetkisi gerekli']);
            exit;
        }
        $kullaniciId = isset($_POST['kullanici_id']) ? (int) $_POST['kullanici_id'] : 0;
        if ($kullaniciId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Geçersiz kullanıcı']);
            exit;
        }
        try {
            // Tablo yoksa olustur (mevcut M_ONAYLANMIS_IP deseni)
            $dbh->exec("
                IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'M_OTURUM_KAPAT')
                BEGIN
                    CREATE TABLE M_OTURUM_KAPAT (
                        KULLANICI_ID INT NOT NULL PRIMARY KEY,
                        KAPATMA_TS DATETIME NOT NULL DEFAULT GETDATE(),
                        KAPATAN_ID INT NULL
                    );
                END
            ");
            // Upsert: kullaniciya ait kapatma damgasini GETDATE() yap (delete+insert; named param tekrarsiz)
            $dbh->beginTransaction();
            $dbh->prepare("DELETE FROM M_OTURUM_KAPAT WHERE KULLANICI_ID = :id")->execute([':id' => $kullaniciId]);
            $dbh->prepare("INSERT INTO M_OTURUM_KAPAT (KULLANICI_ID, KAPATMA_TS, KAPATAN_ID) VALUES (:id, GETDATE(), :kapatan)")
                ->execute([':id' => $kullaniciId, ':kapatan' => (int) $terminalkullanici]);
            $dbh->commit();
            echo json_encode(['success' => true, 'message' => 'Kullanicinin tum oturumlari kapatildi. Bir sonraki islemde cikis yapacak.']);
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) { $dbh->rollBack(); }
            error_log("oturum_kapat hatasi: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Veritabanı hatası']);
        }
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Geçersiz işlem']);
    exit;
}

// ============================================================================
// Onaylı IP'leri yükle
// ============================================================================
$onayliIPler = [];
try {
    $stmtOnayliIP = $dbh->query("SELECT KULLANICI_ID, IP_ADRESI FROM M_ONAYLANMIS_IP");
    while ($row = $stmtOnayliIP->fetch(PDO::FETCH_ASSOC)) {
        $kulId = $row['KULLANICI_ID'];
        if (!isset($onayliIPler[$kulId])) {
            $onayliIPler[$kulId] = [];
        }
        $onayliIPler[$kulId][] = $row['IP_ADRESI'];
    }
} catch (PDOException $e) {
    // Tablo yoksa hata verme, boş liste kullan
    $onayliIPler = [];
}

function normalizeDateParam(?string $dateValue, string $default): string
{
    if ($dateValue === null || $dateValue === '') {
        return $default;
    }

    $normalized = DateTime::createFromFormat('Y-m-d', $dateValue);
    if ($normalized === false || $normalized->format('Y-m-d') !== $dateValue) {
        return $default;
    }

    return $dateValue;
}

// Filtreler
$filterKullanici = isset($_GET['kullanici']) ? intval($_GET['kullanici']) : 0;
$filterIslemTipi = isset($_GET['islem_tipi']) ? trim((string) $_GET['islem_tipi']) : '';
$defaultDateFrom = date('Y-m-d', strtotime('-90 days'));
$defaultDateTo = date('Y-m-d');
$filterDateFrom = normalizeDateParam(isset($_GET['date_from']) ? trim((string) $_GET['date_from']) : null, $defaultDateFrom);
$filterDateTo = normalizeDateParam(isset($_GET['date_to']) ? trim((string) $_GET['date_to']) : null, $defaultDateTo);
$filterSearch = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

if ($filterDateFrom > $filterDateTo) {
    [$filterDateFrom, $filterDateTo] = [$filterDateTo, $filterDateFrom];
}

$filterDateToExclusive = (new DateTimeImmutable($filterDateTo))->modify('+1 day')->format('Y-m-d');
$sqlDateParams = [
    ':date_from' => $filterDateFrom,
    ':date_to_exclusive' => $filterDateToExclusive
];

// Tüm logları topla - fis_gecmis.php ile AYNI şekilde
$tumLoglar = [];

// 1. FİŞ LOGLARI
try {
    $stmtFisLoglar = $dbh->prepare("
        SELECT
            'FIS' AS TIP,
            FL.ISLEM_TIPI,
            FL.TARIH,
            FL.FICHENO,
            FL.CARI_KODU,
            FL.CARI_ADI,
            FL.TOPLAM_TUTAR,
            FL.TOPLAM_KDV,
            FL.GENEL_TOPLAM,
            FL.DOVIZ_TIPI,
            FL.ACIKLAMA,
            FL.IP_ADRESI,
            FL.KULLANICI,
            SL.DEFINITION_ AS KULLANICI_ADI,
            NULL AS STOK_KODU,
            NULL AS STOK_ADI
        FROM M_FIS_LOG FL
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = FL.KULLANICI
        WHERE FL.TARIH >= :date_from
          AND FL.TARIH < :date_to_exclusive
    ");
    $stmtFisLoglar->execute($sqlDateParams);
    $fisLoglar = $stmtFisLoglar->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fisLoglar as $log) {
        $tumLoglar[] = $log;
    }
} catch (PDOException $e) {
    error_log("M_FIS_LOG hatası: " . $e->getMessage());
}

// 2. SATIR DEĞİŞİKLİK LOGLARI
try {
    $stmtSatirLoglar = $dbh->prepare("
        SELECT
            'SATIR' AS TIP,
            SD.ISLEM_TIPI,
            SD.TARIH,
            SD.FICHENO,
            NULL AS CARI_KODU,
            NULL AS CARI_ADI,
            NULL AS TOPLAM_TUTAR,
            NULL AS TOPLAM_KDV,
            NULL AS GENEL_TOPLAM,
            NULL AS DOVIZ_TIPI,
            SD.ACIKLAMA,
            SD.IP_ADRESI,
            SD.KULLANICI,
            SL.DEFINITION_ AS KULLANICI_ADI,
            SD.STOK_KODU,
            SD.STOK_ADI
        FROM M_SATIR_DEGISIKLIK_LOG SD
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = SD.KULLANICI
        WHERE SD.TARIH >= :date_from
          AND SD.TARIH < :date_to_exclusive
    ");
    $stmtSatirLoglar->execute($sqlDateParams);
    $satirLoglar = $stmtSatirLoglar->fetchAll(PDO::FETCH_ASSOC);

    foreach ($satirLoglar as $log) {
        $tumLoglar[] = $log;
    }
} catch (PDOException $e) {
    error_log("M_SATIR_DEGISIKLIK_LOG hatası: " . $e->getMessage());
}

// 3. İSKONTO LOGLARI
try {
    $stmtIskontoLoglar = $dbh->prepare("
        SELECT
            'ISKONTO' AS TIP,
            'ISKONTO' AS ISLEM_TIPI,
            IL.TARIH,
            IL.FICHENO,
            NULL AS CARI_KODU,
            NULL AS CARI_ADI,
            NULL AS TOPLAM_TUTAR,
            NULL AS TOPLAM_KDV,
            NULL AS GENEL_TOPLAM,
            NULL AS DOVIZ_TIPI,
            IL.ACIKLAMA,
            IL.IP_ADRESI,
            IL.KULLANICI,
            SL.DEFINITION_ AS KULLANICI_ADI,
            NULL AS STOK_KODU,
            NULL AS STOK_ADI
        FROM M_ISKONTO_LOG IL
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = IL.KULLANICI
        WHERE IL.TARIH >= :date_from
          AND IL.TARIH < :date_to_exclusive
    ");
    $stmtIskontoLoglar->execute($sqlDateParams);
    $iskontoLoglar = $stmtIskontoLoglar->fetchAll(PDO::FETCH_ASSOC);

    foreach ($iskontoLoglar as $log) {
        $tumLoglar[] = $log;
    }
} catch (PDOException $e) {
    error_log("M_ISKONTO_LOG hatası: " . $e->getMessage());
}

// 4. KDV DEĞİŞİKLİK LOGLARI
try {
    $stmtKdvLoglar = $dbh->prepare("
        SELECT
            'KDV' AS TIP,
            'KDV_DEGISIKLIK' AS ISLEM_TIPI,
            KD.TARIH,
            KD.FICHENO,
            NULL AS CARI_KODU,
            NULL AS CARI_ADI,
            NULL AS TOPLAM_TUTAR,
            NULL AS TOPLAM_KDV,
            NULL AS GENEL_TOPLAM,
            NULL AS DOVIZ_TIPI,
            KD.ACIKLAMA,
            KD.IP_ADRESI,
            KD.KULLANICI,
            SL.DEFINITION_ AS KULLANICI_ADI,
            KD.STOK_KODU,
            KD.STOK_ADI
        FROM M_KDV_DEGISIKLIK_LOG KD
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = KD.KULLANICI
        WHERE KD.TARIH >= :date_from
          AND KD.TARIH < :date_to_exclusive
    ");
    $stmtKdvLoglar->execute($sqlDateParams);
    $kdvLoglar = $stmtKdvLoglar->fetchAll(PDO::FETCH_ASSOC);

    foreach ($kdvLoglar as $log) {
        $tumLoglar[] = $log;
    }
} catch (PDOException $e) {
    error_log("M_KDV_DEGISIKLIK_LOG hatası: " . $e->getMessage());
}

// 5. GİRİŞ/ÇIKIŞ LOGLARI
$girisLoglar = []; // Değişkeni başlat
try {
    $stmtGirisLoglar = $dbh->prepare("
        SELECT
            'GIRIS' AS TIP,
            GL.ISLEM_TIPI,
            GL.TARIH,
            NULL AS FICHENO,
            NULL AS CARI_KODU,
            NULL AS CARI_ADI,
            NULL AS TOPLAM_TUTAR,
            NULL AS TOPLAM_KDV,
            NULL AS GENEL_TOPLAM,
            NULL AS DOVIZ_TIPI,
            CASE
                WHEN GL.BASARILI = 1 THEN GL.ACIKLAMA
                ELSE CONCAT('BAŞARISIZ: ', GL.ACIKLAMA)
            END AS ACIKLAMA,
            GL.IP_ADRESI,
            GL.KULLANICI_ID AS KULLANICI,
            GL.KULLANICI_ADI,
            NULL AS STOK_KODU,
            NULL AS STOK_ADI,
            GL.BASARILI
        FROM M_GIRIS_LOG GL
        WHERE GL.TARIH >= :date_from
          AND GL.TARIH < :date_to_exclusive
    ");
    $stmtGirisLoglar->execute($sqlDateParams);
    $girisLoglar = $stmtGirisLoglar->fetchAll(PDO::FETCH_ASSOC);

    foreach ($girisLoglar as $log) {
        $tumLoglar[] = $log;
    }
} catch (PDOException $e) {
    error_log("M_GIRIS_LOG hatası: " . $e->getMessage());
}

// 6. YAZDIRMA LOGLARI (M_MOBIL_DIZAYN_LOG tablosundan)
// FICHENO artık tabloda kayıtlı, JOIN'e gerek yok
try {
    $stmtYazdirLoglar = $dbh->prepare("
        SELECT
            MD.DIZAYN,
            MD.TARIH,
            MD.MIKTAR,
            MD.YAZICI,
            MD.FICHENO,
            MD.DONEM,
            MD.KULLANICI,
            SL.DEFINITION_ AS KULLANICI_ADI
        FROM M_MOBIL_DIZAYN_LOG MD
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = MD.KULLANICI
        WHERE MD.TARIH >= :date_from
          AND MD.TARIH < :date_to_exclusive
    ");
    $stmtYazdirLoglar->execute($sqlDateParams);
    $yazdirLoglar = $stmtYazdirLoglar->fetchAll(PDO::FETCH_ASSOC);

    // PHP tarafında işle
    foreach ($yazdirLoglar as $row) {
        // İşlem tipini belirle
        $islemTipi = 'DIGER';
        $aciklamaBaslik = 'Yazdırma';
        switch ($row['DIZAYN']) {
            case 1: $islemTipi = 'FIS'; $aciklamaBaslik = 'Fiş yazdırma'; break;
            case 2: $islemTipi = 'BARKOD'; $aciklamaBaslik = 'Barkod yazdırma'; break;
            case 3: $islemTipi = 'AMBAR'; $aciklamaBaslik = 'Ambar sevk yazdırma'; break;
            case 4: $islemTipi = 'MAIL'; $aciklamaBaslik = 'Mail gönderme'; break;
        }

        // Açıklama oluştur
        $aciklama = $aciklamaBaslik;
        if (!empty($row['MIKTAR'])) {
            $aciklama .= ' - Miktar: ' . $row['MIKTAR'];
        }
        if (!empty($row['YAZICI'])) {
            $aciklama .= ' - Yazıcı: ' . $row['YAZICI'];
        }

        $tumLoglar[] = ['TIP' => 'YAZDIR', 'ISLEM_TIPI' => $islemTipi, 'TARIH' => $row['TARIH'], 'FICHENO' => $row['FICHENO'], 'CARI_KODU' => null, 'CARI_ADI' => null, 'TOPLAM_TUTAR' => null, 'TOPLAM_KDV' => null, 'GENEL_TOPLAM' => null, 'DOVIZ_TIPI' => null, 'ACIKLAMA' => $aciklama, 'IP_ADRESI' => null, 'KULLANICI' => $row['KULLANICI'], 'KULLANICI_ADI' => $row['KULLANICI_ADI'], 'STOK_KODU' => null, 'STOK_ADI' => null];
    }

} catch (PDOException $e) {
    error_log("M_MOBIL_DIZAYN_LOG hatası: " . $e->getMessage());
}

// 7. FİYAT DEĞİŞİKLİK LOGLARI
try {
    $stmtFiyatLoglar = $dbh->prepare("
        SELECT
            'FIYAT' AS TIP,
            'FIYAT_DEGISIKLIK' AS ISLEM_TIPI,
            FL.TARIH,
            FL.FICHENO,
            NULL AS CARI_KODU,
            NULL AS CARI_ADI,
            FL.TOPLAM_FARK AS TOPLAM_TUTAR,
            NULL AS TOPLAM_KDV,
            NULL AS GENEL_TOPLAM,
            NULL AS DOVIZ_TIPI,
            CAST(ISNULL(FL.ACIKLAMA, '') + ' (' + CAST(FL.ESKI_FIYAT AS NVARCHAR(20)) + ' -> ' + CAST(FL.YENI_FIYAT AS NVARCHAR(20)) + ', Fark: %' + CAST(FL.YUZDELIK_FARK AS NVARCHAR(10)) + ')' AS NVARCHAR(500)) AS ACIKLAMA,
            FL.IP_ADRESI,
            FL.KULLANICI_ID AS KULLANICI,
            SL.DEFINITION_ AS KULLANICI_ADI,
            FL.STOK_KODU,
            FL.STOK_ADI
        FROM M_FIYAT_LOG FL
        LEFT JOIN LG_SLSMAN SL ON SL.LOGICALREF = FL.KULLANICI_ID
        WHERE FL.TARIH >= :date_from
          AND FL.TARIH < :date_to_exclusive
    ");
    $stmtFiyatLoglar->execute($sqlDateParams);
    $fiyatLoglar = $stmtFiyatLoglar->fetchAll(PDO::FETCH_ASSOC);

    foreach ($fiyatLoglar as $log) {
        $tumLoglar[] = $log;
    }
} catch (PDOException $e) {
    error_log("M_FIYAT_LOG hatası: " . $e->getMessage());
}

// Filtreleme uygula
$filtrelenmisLoglar = [];
$tarihHatalari = 0;
foreach ($tumLoglar as $log) {
    // Tarih filtresi
    if (empty($log['TARIH'])) {
        $tarihHatalari++;
        continue; // Tarih yoksa atla
    }

    $logTarih = date('Y-m-d', strtotime((string) $log['TARIH']));
    if ($logTarih === '1970-01-01' || $logTarih === false) {
        // Geçersiz tarih
        $tarihHatalari++;
        error_log("Geçersiz tarih formatı: " . $log['TARIH'] . " - TIP: " . $log['TIP']);
        continue;
    }
    if ($logTarih < $filterDateFrom) {
        continue;
    }
    if ($logTarih > $filterDateTo) {
        continue;
    }

    // Kullanıcı filtresi
    if ($filterKullanici > 0 && $log['KULLANICI'] != $filterKullanici) {
        continue;
    }

    // İşlem tipi filtresi
    if ($filterIslemTipi !== '' && $filterIslemTipi !== '0' && $log['ISLEM_TIPI'] != $filterIslemTipi) {
        continue;
    }

    // Arama filtresi
    if ($filterSearch !== '' && $filterSearch !== '0') {
        $found = false;
        if ($log['FICHENO'] && mb_stripos((string) $log['FICHENO'], $filterSearch) !== false) {
            $found = true;
        }
        if ($log['CARI_ADI'] && mb_stripos((string) $log['CARI_ADI'], $filterSearch) !== false) {
            $found = true;
        }
        if ($log['STOK_ADI'] && mb_stripos((string) $log['STOK_ADI'], $filterSearch) !== false) {
            $found = true;
        }
        if ($log['ACIKLAMA'] && mb_stripos((string) $log['ACIKLAMA'], $filterSearch) !== false) {
            $found = true;
        }
        if (!$found) {
            continue;
        }
    }

    $filtrelenmisLoglar[] = $log;
}

// Tarihe göre sırala (en yeni en üstte)
usort($filtrelenmisLoglar, fn($a, $b): int => strtotime((string) $b['TARIH']) - strtotime((string) $a['TARIH']));

// Fiş bazında grupla
$grupluLoglar = [];
$girisCikisLoglar = []; // Giriş/Çıkış logları ayrı tutulacak

foreach ($filtrelenmisLoglar as $log) {
    // Giriş/Çıkış loglarını ayrı tut (fiş ile ilişkili değil)
    if ($log['ISLEM_TIPI'] == 'GIRIS' || $log['ISLEM_TIPI'] == 'CIKIS') {
        // Tarih kontrolü yap
        if (!empty($log['TARIH'])) {
            $tarihKey = date('Y-m-d', strtotime((string) $log['TARIH']));
            if (!isset($girisCikisLoglar[$tarihKey])) {
                $girisCikisLoglar[$tarihKey] = [];
            }

            // Şüpheli bilgisini koru
            if (!isset($log['SUPHELI'])) {
                $log['SUPHELI'] = false;
            }

            $girisCikisLoglar[$tarihKey][] = $log;
        }
        continue;
    }

    // Fiş numarası olan logları grupla
    if (!empty($log['FICHENO'])) {
        $ficheno = $log['FICHENO'];
        if (!isset($grupluLoglar[$ficheno])) {
            $grupluLoglar[$ficheno] = ['FICHENO' => $ficheno, 'CARI_ADI' => $log['CARI_ADI'], 'CARI_KODU' => $log['CARI_KODU'], 'ILK_TARIH' => $log['TARIH'], 'SON_TARIH' => $log['TARIH'], 'ISLEMLER' => [], 'KULLANICILAR' => [], 'TOPLAM_TUTAR' => 0];
        }

        // Tarihleri güncelle
        if (strtotime((string) $log['TARIH']) < strtotime((string) $grupluLoglar[$ficheno]['ILK_TARIH'])) {
            $grupluLoglar[$ficheno]['ILK_TARIH'] = $log['TARIH'];
        }
        if (strtotime((string) $log['TARIH']) > strtotime((string) $grupluLoglar[$ficheno]['SON_TARIH'])) {
            $grupluLoglar[$ficheno]['SON_TARIH'] = $log['TARIH'];
        }

        // Cari bilgisini güncelle (varsa)
        if (empty($grupluLoglar[$ficheno]['CARI_ADI']) && !empty($log['CARI_ADI'])) {
            $grupluLoglar[$ficheno]['CARI_ADI'] = $log['CARI_ADI'];
            $grupluLoglar[$ficheno]['CARI_KODU'] = $log['CARI_KODU'];
        }

        // Tutar bilgisini güncelle
        if (!empty($log['GENEL_TOPLAM']) && $log['GENEL_TOPLAM'] > $grupluLoglar[$ficheno]['TOPLAM_TUTAR']) {
            $grupluLoglar[$ficheno]['TOPLAM_TUTAR'] = $log['GENEL_TOPLAM'];
        }

        // Kullanıcıyı ekle
        if (!empty($log['KULLANICI_ADI']) && !in_array($log['KULLANICI_ADI'], $grupluLoglar[$ficheno]['KULLANICILAR'])) {
            $grupluLoglar[$ficheno]['KULLANICILAR'][] = $log['KULLANICI_ADI'];
        }

        // İşlemi ekle
        $grupluLoglar[$ficheno]['ISLEMLER'][] = $log;
    }
}

// Son tarihe göre sırala (en yeni en üstte)
uasort($grupluLoglar, fn($a, $b): int => strtotime((string) $b['SON_TARIH']) - strtotime((string) $a['SON_TARIH']));

// ŞÜPHELİ IP ANALİZİ
// Her kullanıcının normal IP adreslerini belirle
$kullaniciIPler = [];
$kullaniciSubnetler = []; // Subnet bazlı analiz için
foreach ($girisLoglar as $log) {
    if ($log['BASARILI'] == 1 && $log['KULLANICI'] && $log['IP_ADRESI']) {
        $kulId = $log['KULLANICI'];
        $ip = $log['IP_ADRESI'];

        if (!isset($kullaniciIPler[$kulId])) {
            $kullaniciIPler[$kulId] = [];
            $kullaniciSubnetler[$kulId] = [];
        }
        if (!isset($kullaniciIPler[$kulId][$ip])) {
            $kullaniciIPler[$kulId][$ip] = 0;
        }
        $kullaniciIPler[$kulId][$ip]++;

        // Subnet'i de kaydet (ilk 3 oktet: 192.168.1.x → 192.168.1)
        $ipParts = explode('.', $ip);
        if (count($ipParts) >= 3) {
            $subnet = $ipParts[0] . '.' . $ipParts[1] . '.' . $ipParts[2];
            if (!isset($kullaniciSubnetler[$kulId][$subnet])) {
                $kullaniciSubnetler[$kulId][$subnet] = 0;
            }
            $kullaniciSubnetler[$kulId][$subnet]++;
        }
    }
}

// Her kullanıcı için normal IP ve subnet'leri belirle
$normalIPler = [];
$normalSubnetler = [];
foreach ($kullaniciIPler as $kulId => $ipler) {
    arsort($ipler);
    $toplamGiris = array_sum($ipler);
    $benzersizIPSayisi = count($ipler);
    $normalIPler[$kulId] = [];
    $normalSubnetler[$kulId] = [];

    // Eğer kullanıcı çok az giriş yaptıysa (5 veya daha az), şüpheli analizi yapma
    if ($toplamGiris <= 5) {
        // Tüm IP'leri normal kabul et
        $normalIPler[$kulId] = array_keys($ipler);
        if (isset($kullaniciSubnetler[$kulId])) {
            $normalSubnetler[$kulId] = array_keys($kullaniciSubnetler[$kulId]);
        }
        continue;
    }

    // Eğer kullanıcı çok fazla farklı IP kullanıyorsa (örn: dinamik IP), analizi gevşet
    // Toplam girişin yarısından fazla farklı IP varsa → muhtemelen dinamik IP kullanıcısı
    $dinamikKullanici = ($benzersizIPSayisi > $toplamGiris * 0.5);

    // En sık kullanılan IP'leri normal kabul et
    // Dinamik kullanıcılar için: en az 1 giriş veya %5
    // Normal kullanıcılar için: en az 2 giriş veya %10
    $minGiris = $dinamikKullanici ? 1 : 2;
    $yuzdeEsik = $dinamikKullanici ? 0.05 : 0.10;

    foreach ($ipler as $ip => $sayi) {
        if ($sayi >= max($minGiris, $toplamGiris * $yuzdeEsik)) {
            $normalIPler[$kulId][] = $ip;
        }
    }

    // Subnet'leri de normal kabul et (aynı ağdaki tüm IP'ler için)
    if (isset($kullaniciSubnetler[$kulId])) {
        foreach ($kullaniciSubnetler[$kulId] as $subnet => $sayi) {
            if ($sayi >= max($minGiris, $toplamGiris * $yuzdeEsik)) {
                $normalSubnetler[$kulId][] = $subnet;
            }
        }
    }
}

// Şüpheli IP aktivitelerini tespit et
$supheliharitasi = [];
foreach ($filtrelenmisLoglar as &$log) {
    $log['SUPHELI'] = false;

    // Sadece başarılı giriş logları için kontrol et
    if (($log['ISLEM_TIPI'] == 'GIRIS' || $log['ISLEM_TIPI'] == 'CIKIS') && isset($log['BASARILI']) && $log['BASARILI'] == 1) {
        $kulId = $log['KULLANICI'];
        $ip = $log['IP_ADRESI'];

        // Kullanıcı ID veya IP yoksa atla
        if (!$kulId || !$ip) {
            continue;
        }

        // Bu kullanıcının normal IP'leri yoksa (yeni kullanıcı) → şüpheli değil
        if (!isset($normalIPler[$kulId]) || $normalIPler[$kulId] === []) {
            continue;
        }

        // Yönetici tarafından onaylanmış IP mi?
        if (isset($onayliIPler[$kulId]) && in_array($ip, $onayliIPler[$kulId])) {
            continue; // Onaylı IP, şüpheli değil
        }

        // IP doğrudan normal listesinde mi?
        if (in_array($ip, $normalIPler[$kulId])) {
            continue; // Normal IP, şüpheli değil
        }

        // IP'nin subnet'i normal mi? (aynı ağdan farklı IP)
        $ipParts = explode('.', $ip);
        if (count($ipParts) >= 3) {
            $subnet = $ipParts[0] . '.' . $ipParts[1] . '.' . $ipParts[2];
            if (isset($normalSubnetler[$kulId]) && in_array($subnet, $normalSubnetler[$kulId])) {
                continue; // Aynı subnet'ten, şüpheli değil
            }
        }

        // Localhost ve yerel ağ IP'lerini şüpheli sayma
        if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            // Yerel ağ IP'si - genellikle güvenli
            // Ancak kullanıcının daha önce hiç yerel IP kullanmadıysa yine de kontrol et
            $yerelIPKullanmis = false;
            foreach ($normalIPler[$kulId] as $normalIP) {
                if (strpos($normalIP, '192.168.') === 0 || strpos($normalIP, '10.') === 0 || $normalIP === '127.0.0.1') {
                    $yerelIPKullanmis = true;
                    break;
                }
            }
            if ($yerelIPKullanmis) {
                continue; // Daha önce de yerel IP kullanmış, şüpheli değil
            }
        }

        // Buraya geldiyse şüpheli
        $log['SUPHELI'] = true;
        $log['SUPHELI_ACIKLAMA'] = 'Alışılmadık IP adresi! Normal IP: ' . implode(', ', array_slice($normalIPler[$kulId], 0, 3));

        // Şüpheli haritaya ekle
        $tarihKey = date('Y-m-d H', strtotime((string) $log['TARIH']));
        if (!isset($supheliharitasi[$tarihKey])) {
            $supheliharitasi[$tarihKey] = [];
        }
        $supheliharitasi[$tarihKey][] = $log;
    }
}
unset($log); // Referansı temizle

// Şüpheli logları tarihe göre sırala
krsort($supheliharitasi);

// İstatistikler
$toplamLog = count($filtrelenmisLoglar);
$toplamSupheli = 0;
foreach ($supheliharitasi as $logs) {
    $toplamSupheli += count($logs);
}

// En aktif kullanıcılar
$kullaniciSayilari = [];
foreach ($filtrelenmisLoglar as $log) {
    $kullaniciAdi = $log['KULLANICI_ADI'] ?: 'Bilinmiyor';
    if (!isset($kullaniciSayilari[$kullaniciAdi])) {
        $kullaniciSayilari[$kullaniciAdi] = 0;
    }
    $kullaniciSayilari[$kullaniciAdi]++;
}
arsort($kullaniciSayilari);

// İşlem tipi dağılımı
$islemTipleri = [];
foreach ($filtrelenmisLoglar as $log) {
    $tip = $log['ISLEM_TIPI'];
    if (!isset($islemTipleri[$tip])) {
        $islemTipleri[$tip] = 0;
    }
    $islemTipleri[$tip]++;
}
arsort($islemTipleri);



// Tüm kullanıcılar (dropdown için)
$tumKullanicilar = [];
foreach ($tumLoglar as $log) {
    if ($log['KULLANICI'] && !isset($tumKullanicilar[$log['KULLANICI']])) {
        $tumKullanicilar[$log['KULLANICI']] = $log['KULLANICI_ADI'] ?: 'Bilinmiyor';
    }
}

// Gruplu loglar için pagination
$grupluLoglarArray = array_values($grupluLoglar);
$toplamGrup = count($grupluLoglarArray);
$perPage = 20;
$currentPage = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$offset = ($currentPage - 1) * $perPage;
$totalPages = ceil($toplamGrup / $perPage);
$sayfaGruplar = array_slice($grupluLoglarArray, $offset, $perPage);

// Query string for pagination
$queryParams = $_GET;
unset($queryParams['p']);
$queryString = http_build_query($queryParams);

// İşlem tipi için renk ve ikon tanımları
$islemRenkleri = [
    // Fiş işlemleri
    'OLUSTURMA' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'icon' => 'fa-plus-circle'],
    'GUNCELLEME' => ['bg' => 'bg-cyan-100', 'text' => 'text-cyan-700', 'icon' => 'fa-edit'],
    'SILME' => ['bg' => 'bg-rose-100', 'text' => 'text-rose-700', 'icon' => 'fa-trash-alt'],
    // Satır işlemleri
    'EKLE' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'icon' => 'fa-plus'],
    'DUZENLE' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'icon' => 'fa-pen'],
    'SIL' => ['bg' => 'bg-red-100', 'text' => 'text-red-700', 'icon' => 'fa-minus-circle'],
    // Değişiklikler
    'ISKONTO' => ['bg' => 'bg-purple-100', 'text' => 'text-purple-700', 'icon' => 'fa-percent'],
    'KDV_DEGISIKLIK' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'icon' => 'fa-receipt'],
    'FIYAT_DEGISIKLIK' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-700', 'icon' => 'fa-tag'],
    // Giriş/Çıkış
    'GIRIS' => ['bg' => 'bg-teal-100', 'text' => 'text-teal-700', 'icon' => 'fa-sign-in-alt'],
    'CIKIS' => ['bg' => 'bg-slate-100', 'text' => 'text-slate-700', 'icon' => 'fa-sign-out-alt'],
    // Yazdırma
    'FIS' => ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-700', 'icon' => 'fa-print'],
    'FIYATLI' => ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-700', 'icon' => 'fa-file-invoice-dollar'],
    'FIYATSIZ' => ['bg' => 'bg-violet-100', 'text' => 'text-violet-700', 'icon' => 'fa-file-alt'],
    'EXCEL' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'icon' => 'fa-file-excel'],
    'HTML' => ['bg' => 'bg-sky-100', 'text' => 'text-sky-700', 'icon' => 'fa-file-code'],
    'BARKOD' => ['bg' => 'bg-gray-200', 'text' => 'text-gray-700', 'icon' => 'fa-barcode'],
    'AMBAR' => ['bg' => 'bg-amber-100', 'text' => 'text-amber-700', 'icon' => 'fa-warehouse'],
    'MAIL' => ['bg' => 'bg-pink-100', 'text' => 'text-pink-700', 'icon' => 'fa-envelope'],
    'DOVIZ' => ['bg' => 'bg-lime-100', 'text' => 'text-lime-700', 'icon' => 'fa-dollar-sign'],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $logGrup === 'personel' ? 'Personel Izleme' : 'Log Merkezi'; ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (is_file(__DIR__ . '/pwa-header.php')) { include_once(__DIR__ . '/pwa-header.php'); } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
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
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            margin: 0;
            min-height: 100vh;
        }
        .mono { font-family: 'JetBrains Mono', 'Courier New', monospace; }
        a { color: inherit; }

        /* Sticky top header */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.88);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
        }
        .header-inner {
            max-width: 1280px; margin: 0 auto;
            height: 100%; display: flex; align-items: center; gap: 14px;
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
            font-size: 18px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 10px;
        }
        .header-title i { color: var(--red,#ef4444); font-size: 16px; }

        /* Main layout */
        main.page { max-width: 1280px; margin: 0 auto; padding: 22px 24px 60px; }

        /* Glass card */
        .glass-card {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            margin-bottom: 18px;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .card-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 10px;
        }
        .card-head .icon-box {
            width: 34px; height: 34px; flex-shrink: 0;
            border-radius: 10px;
            background: var(--red-soft); color: var(--red);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px;
        }
        .card-head h2 { font-size: 14px; font-weight: 700; margin: 0; }
        .card-head p { font-size: 11.5px; color: var(--text-2); margin: 1px 0 0; }
        .card-head .head-actions { margin-left: auto; display: flex; gap: 8px; }
        .card-body { padding: 18px 20px; }

        /* Tab nav */
        .tab-nav {
            display: flex; flex-wrap: wrap; gap: 8px;
            padding: 10px;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            margin-bottom: 18px;
        }
        .tab-link {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 13px; font-weight: 600;
            color: var(--text-2);
            text-decoration: none;
            border: 1px solid transparent;
            background: #fff;
            transition: all 0.18s ease;
        }
        .tab-link:hover { background: #fafafa; color: var(--text-1); border-color: var(--border); }
        .tab-link.active {
            background: var(--red-soft);
            color: var(--red);
            border-color: rgba(111, 16, 34, 0.28);
            box-shadow: inset 0 0 0 1px rgba(111, 16, 34, 0.08);
        }
        .tab-link i { font-size: 13px; }

        /* Stat tiles */
        .stat-grid {
            display: grid; gap: 12px;
            grid-template-columns: repeat(5, 1fr);
            margin-bottom: 18px;
        }
        .stat-tile {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px 16px;
            position: relative; overflow: hidden;
            transition: all 0.25s ease;
        }
        .stat-tile:hover { transform: translateY(-2px); box-shadow: 0 10px 24px rgba(0,0,0,0.06); }
        .stat-tile::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--red); }
        .stat-tile.indigo::before { background: var(--indigo); }
        .stat-tile.emerald::before { background: var(--emerald); }
        .stat-tile.amber::before { background: var(--amber); }
        .stat-tile.sky::before { background: var(--sky); }
        .stat-tile.purple::before { background: var(--purple); }
        .stat-tile .t-head { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .stat-tile .t-ico {
            width: 32px; height: 32px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--red-soft); color: var(--red);
            font-size: 13px;
        }
        .stat-tile.indigo .t-ico { background: var(--indigo-soft); color: var(--indigo); }
        .stat-tile.emerald .t-ico { background: var(--emerald-soft); color: var(--emerald); }
        .stat-tile.amber .t-ico { background: var(--amber-soft); color: var(--amber); }
        .stat-tile.sky .t-ico { background: var(--sky-soft); color: var(--sky); }
        .stat-tile.purple .t-ico { background: var(--purple-soft); color: var(--purple); }
        .stat-tile .t-label {
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.4px;
        }
        .stat-tile .t-value {
            font-size: 22px; font-weight: 700; color: var(--text-1);
            line-height: 1.1;
        }
        .stat-tile .t-sub {
            margin-top: 4px; font-size: 11px; color: var(--text-2); font-weight: 500;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .stat-tile.alert { border-color: rgba(111, 16, 34, 0.35); }

        /* Filter form */
        .filter-form {
            display: grid; gap: 12px;
            grid-template-columns: repeat(5, 1fr);
        }
        .filter-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .filter-field label {
            font-size: 10.5px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.35px;
        }
        .filter-field input[type=text],
        .filter-field input[type=date],
        .filter-field input[type=number],
        .filter-field select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            font-family: inherit;
            font-size: 13px;
            color: var(--text-1);
            transition: all 0.18s ease;
        }
        .filter-field input:focus, .filter-field select:focus {
            outline: none;
            border-color: var(--red);
            box-shadow: 0 0 0 3px rgba(111, 16, 34, 0.12);
        }
        .filter-actions {
            display: flex; gap: 8px; justify-content: flex-end; align-items: center;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px dashed var(--border);
        }
        .filter-summary { margin-right: auto; font-size: 12px; color: var(--text-2); }
        .filter-summary strong { color: var(--text-1); font-weight: 700; }

        /* Buttons */
        .btn {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px;
            border-radius: 10px;
            font-size: 12.5px; font-weight: 600;
            border: 1px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.18s ease;
            font-family: inherit;
        }
        .btn-primary { background: var(--red); color: #fff; }
        .btn-primary:hover { background: #b91c1c; }
        .btn-ghost { background: #fff; color: var(--text-1); border-color: var(--border); }
        .btn-ghost:hover { background: #fafafa; border-color: #d4d4d4; }
        .btn-emerald { background: var(--emerald); color: #fff; }
        .btn-emerald:hover { background: #047857; }
        .btn-small { padding: 5px 12px; font-size: 11px; border-radius: 8px; }

        /* Error rows */
        .err-row {
            display: grid;
            grid-template-columns: 160px 1fr auto auto;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 8px;
            background: #fff;
            align-items: center;
            transition: all 0.15s ease;
        }
        .err-row:hover { border-color: rgba(239, 68, 68, 0.32); }
        .err-row.is-solved { opacity: 0.65; background: #fafafa; }
        .err-row .ref {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10.5px; font-weight: 600;
            color: var(--red);
            background: var(--red-soft);
            padding: 5px 9px;
            border-radius: 6px;
            text-align: center;
        }
        .err-row .ref a { color: inherit; text-decoration: none; }
        .err-row .ref a:hover { text-decoration: underline; }
        .err-row .body { min-width: 0; }
        .err-row .scope {
            font-size: 10px; font-weight: 700;
            color: var(--text-3);
            text-transform: uppercase; letter-spacing: 0.35px;
            margin-bottom: 3px;
        }
        .err-row .msg {
            font-size: 12.5px; color: var(--text-1); line-height: 1.4;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .err-row .uri {
            font-family: 'JetBrains Mono', monospace;
            font-size: 10.5px; color: var(--text-2);
            margin-top: 3px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .err-row .time {
            font-size: 11px; color: var(--text-2);
            text-align: right; white-space: nowrap;
        }
        .chip {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 100px;
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .chip-open { background: var(--amber-soft); color: var(--amber); border: 1px solid rgba(217, 119, 6, 0.25); }
        .chip-solved { background: var(--emerald-soft); color: var(--emerald); border: 1px solid rgba(5, 150, 105, 0.25); }
        .chip-red { background: var(--red-soft); color: var(--red); border: 1px solid rgba(111, 16, 34, 0.22); }
        .chip-indigo { background: var(--indigo-soft); color: var(--indigo); border: 1px solid rgba(79, 70, 229, 0.2); }

        /* Table */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .data-table thead th {
            text-align: left;
            font-size: 10px; font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.4px;
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            background: #fafafa;
            position: sticky; top: 0;
        }
        .data-table tbody td {
            padding: 11px 12px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
            color: var(--text-1);
        }
        .data-table tbody tr:hover { background: #fafafa; }
        .data-table .num { text-align: right; font-weight: 600; }
        .data-table .num.red { color: var(--red); }
        .data-table .num.amber { color: var(--amber); }
        .data-table .mono-cell {
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: var(--indigo);
        }
        .data-table-wrap { overflow-x: auto; }
        .table-empty {
            text-align: center;
            padding: 32px 16px;
            color: var(--text-3);
            font-size: 13px;
        }
        .table-empty i { display: block; font-size: 28px; margin-bottom: 8px; color: var(--text-3); }

        /* KV info list */
        .kv-list {
            display: grid;
            grid-template-columns: 140px 1fr;
            gap: 4px 14px;
            font-size: 12px;
        }
        .kv-list dt { color: var(--text-2); font-weight: 600; padding: 4px 0; }
        .kv-list dd {
            color: var(--text-1); padding: 4px 0;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px; word-break: break-word;
        }

        /* Fis group card (aktivite) */
        .fis-group {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            margin-bottom: 12px;
            transition: all 0.2s ease;
        }
        .fis-group:hover { border-color: rgba(111, 16, 34, 0.28); }
        .fis-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 14px;
            padding: 14px 18px;
            cursor: pointer;
            background: linear-gradient(90deg, var(--red-soft), #fff);
            border-bottom: 1px solid transparent;
            transition: background 0.2s ease;
        }
        .fis-head:hover { background: linear-gradient(90deg, #fee2e2, #fff); }
        .fis-head .f-left { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .fis-head .f-ico {
            width: 38px; height: 38px;
            border-radius: 10px;
            background: var(--red); color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .fis-head .f-title {
            font-size: 14px; font-weight: 700; color: var(--text-1);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .fis-head .f-sub {
            font-size: 11.5px; color: var(--text-2);
            margin-top: 2px;
        }
        .fis-head .f-sub .fis-no { font-family: 'JetBrains Mono', monospace; }
        .fis-head .f-right {
            display: flex; align-items: center; gap: 10px;
            flex-shrink: 0;
        }
        .fis-head .f-count {
            background: var(--red); color: #fff;
            padding: 4px 12px; border-radius: 100px;
            font-size: 11px; font-weight: 700;
        }
        .fis-head .f-chevron {
            color: var(--text-2); font-size: 12px;
            transition: transform 0.25s ease;
        }
        .fis-head.open .f-chevron { transform: rotate(180deg); }
        .fis-body { display: none; border-top: 1px solid var(--border); }
        .fis-body.open { display: block; }
        .tl-item {
            display: flex; gap: 12px;
            padding: 12px 18px;
            border-bottom: 1px solid #f3f4f6;
        }
        .tl-item:last-child { border-bottom: none; }
        .tl-item:hover { background: #fafafa; }
        .tl-cat {
            width: 32px; height: 32px; flex-shrink: 0;
            border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 12px;
            background: var(--indigo-soft); color: var(--indigo);
        }
        .tl-body { flex: 1 1 auto; min-width: 0; }
        .tl-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .tl-type {
            padding: 2px 9px; border-radius: 6px;
            font-size: 10px; font-weight: 700;
            background: var(--indigo-soft); color: var(--indigo);
            text-transform: uppercase; letter-spacing: 0.3px;
        }
        .tl-name { font-size: 12.5px; color: var(--text-1); font-weight: 500; }
        .tl-desc { font-size: 12px; color: var(--text-2); margin-top: 4px; line-height: 1.4; }
        .tl-meta {
            display: flex; gap: 14px; flex-wrap: wrap;
            margin-top: 5px;
            font-size: 10.5px; color: var(--text-3);
        }
        .tl-meta span i { margin-right: 4px; }
        .tl-time {
            font-size: 10.5px; color: var(--text-3);
            white-space: nowrap;
            flex-shrink: 0;
        }

        /* Giris cikis list */
        .gc-list {
            display: flex; flex-direction: column; gap: 6px;
            max-height: 360px; overflow-y: auto;
        }
        .gc-row {
            display: flex; align-items: center; gap: 10px;
            padding: 9px 12px;
            background: #fafafa;
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: 12px;
        }
        .gc-row.is-supheli { background: var(--red-soft); border-color: rgba(111,16,34,0.28); }
        .gc-row.is-fail { background: var(--amber-soft); border-color: rgba(217,119,6,0.25); }
        .gc-row .gc-user { font-weight: 600; color: var(--text-1); }
        .gc-row .gc-ip {
            font-family: 'JetBrains Mono', monospace; font-size: 10.5px;
            padding: 3px 8px; background: #fff; border-radius: 6px;
            color: var(--text-2);
        }
        .gc-row.is-supheli .gc-ip { background: #fff; color: var(--red); font-weight: 700; }
        .gc-row .gc-sep { color: var(--text-3); }
        .gc-row .gc-time { color: var(--text-2); margin-left: auto; font-size: 10.5px; }

        /* Supheli warning */
        .supheli-card {
            background: linear-gradient(135deg, var(--red-soft), #fff7ed);
            border: 2px solid rgba(111, 16, 34, 0.3);
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 18px;
        }
        .supheli-head { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
        .supheli-head .s-ico {
            width: 44px; height: 44px; border-radius: 12px;
            background: var(--red); color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .supheli-head h3 { font-size: 15px; font-weight: 700; color: #991b1b; margin: 0; }
        .supheli-head p { font-size: 12px; color: #b91c1c; margin: 2px 0 0; }
        .supheli-tools {
            display: flex; align-items: center; gap: 12px;
            margin-bottom: 12px;
            font-size: 12px;
        }
        .supheli-list {
            display: flex; flex-direction: column; gap: 10px;
            background: rgba(255,255,255,0.82);
            padding: 12px; border-radius: 10px;
            max-height: 400px; overflow-y: auto;
        }
        .supheli-item {
            display: flex; gap: 10px;
            padding: 10px 12px;
            background: #fff;
            border-left: 4px solid var(--red);
            border-radius: 0 8px 8px 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }
        .supheli-item input[type=checkbox] { width: 16px; height: 16px; margin-top: 4px; flex-shrink: 0; cursor: pointer; }
        .supheli-item .s-body { flex: 1 1 auto; min-width: 0; }
        .supheli-item .s-name { font-weight: 700; color: var(--text-1); font-size: 13px; }
        .supheli-item .s-ip {
            font-family: 'JetBrains Mono', monospace; font-size: 11px;
            padding: 3px 8px; background: var(--red-soft); color: var(--red);
            border-radius: 6px; font-weight: 700;
        }
        .supheli-item .s-time { font-size: 10.5px; color: var(--text-3); text-align: right; flex-shrink: 0; }

        /* Log file pill list */
        .logfile-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 16px;
            font-size: 11.5px;
            color: var(--text-2);
        }
        .logfile-grid strong { color: var(--text-1); font-weight: 700; }
        .logfile-grid code {
            font-family: 'JetBrains Mono', monospace;
            color: var(--indigo);
            word-break: break-all;
        }

        /* Pagination */
        .pagination {
            display: flex; gap: 6px; justify-content: center; align-items: center;
            margin-top: 22px;
        }
        .pagination a, .pagination span {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 36px; height: 36px;
            padding: 0 10px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-1);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.18s ease;
        }
        .pagination a:hover { background: #fafafa; border-color: #d4d4d4; }
        .pagination .active {
            background: var(--red); color: #fff; border-color: var(--red);
        }

        /* JSON / pre panels */
        .panel {
            border: 1px solid var(--border);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }
        .panel-head {
            padding: 9px 14px;
            background: #fafafa;
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border-bottom: 1px solid var(--border);
        }
        .panel-body {
            padding: 12px 14px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 11px;
            color: var(--text-1);
            max-height: 320px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0;
        }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        /* Error detail banner */
        .err-detail-head {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
            margin-bottom: 14px;
        }
        .err-detail-head .ref-big {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12.5px; font-weight: 700;
            padding: 6px 14px;
            background: var(--red-soft); color: var(--red);
            border-radius: 100px;
            border: 1px solid rgba(111, 16, 34, 0.25);
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* Responsive */
        @media (max-width: 1100px) {
            .stat-grid { grid-template-columns: repeat(3, 1fr); }
            .filter-form { grid-template-columns: repeat(3, 1fr); }
            .grid-2 { grid-template-columns: 1fr; }
        }
        @media (max-width: 900px) {
            .err-row { grid-template-columns: 130px 1fr; row-gap: 6px; }
            .err-row .time, .err-row .action-cell { grid-column: 1 / -1; text-align: left; }
            .logfile-grid { grid-template-columns: 1fr; }
            .kv-list { grid-template-columns: 1fr; gap: 1px 0; }
            .kv-list dt { padding-top: 8px; }
        }
        @media (max-width: 767px) {
            .top-header { height: 52px; }
            .header-inner { padding: 0 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            main.page { padding: 14px 12px 50px; }

            .stat-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .stat-tile .t-value { font-size: 18px; }
            .filter-form { grid-template-columns: 1fr; }
            .card-head { padding: 14px 16px; }
            .card-body { padding: 16px; }
            .tab-link { padding: 9px 12px; font-size: 12px; }

            .fis-head { padding: 12px 14px; }
            .fis-head .f-title { font-size: 13px; }
            .tl-item { padding: 12px 14px; }

            .data-table thead { display: none; }
            .data-table tbody tr {
                display: block;
                border: 1px solid var(--border);
                border-radius: 10px;
                padding: 10px 12px;
                margin-bottom: 8px;
                background: #fff;
            }
            .data-table tbody td {
                display: block;
                padding: 4px 0;
                border: none;
            }
            .data-table tbody td::before {
                content: attr(data-label);
                display: inline-block;
                width: 110px;
                font-size: 10px;
                font-weight: 700;
                color: var(--text-2);
                text-transform: uppercase;
                letter-spacing: 0.3px;
            }
            .data-table-wrap { overflow: visible; }

            .filter-actions { flex-wrap: wrap; }
            .filter-summary { width: 100%; margin-bottom: 8px; }
        }

        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .glass-card, .tab-nav, .top-header { box-shadow: none; border-color: #ccc; }
        }

        /* ===== Cihazlar sekmesi (cihaz_takibi.php'den taşındı, cihaz- prefix) ===== */
        .cihaz-summary { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 22px; }
        .cihaz-sum-card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 16px; display: flex; align-items: center; gap: 12px; animation: cardIn 0.4s ease both; }
        .cihaz-sum-ico { width: 44px; height: 44px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
        .cihaz-sum-ico.t-emerald { background: var(--emerald-soft); color: var(--emerald); }
        .cihaz-sum-ico.t-sky { background: var(--sky-soft); color: var(--sky); }
        .cihaz-sum-ico.t-amber { background: var(--amber-soft); color: var(--amber); }
        .cihaz-sum-ico.t-red { background: var(--red-soft); color: var(--red); }
        .cihaz-sum-num { font-size: 22px; font-weight: 700; line-height: 1; }
        .cihaz-sum-lbl { font-size: 11px; color: var(--text-2); margin-top: 3px; }

        .cihaz-section-title { font-size: 14px; font-weight: 700; margin: 22px 0 12px; display: flex; align-items: center; gap: 8px; }
        .cihaz-section-title i { color: var(--red); font-size: 13px; }

        .cihaz-panel { background: #fff; border: 1px solid var(--border); border-radius: 16px; padding: 8px; animation: cardIn 0.4s ease both; }

        .cihaz-user-block { border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; background: #fff; }
        .cihaz-user-block:last-child { margin-bottom: 0; }
        .cihaz-user-block.is-online { border-color: rgba(5,150,105,0.35); box-shadow: 0 0 0 1px rgba(5,150,105,0.12); }
        .cihaz-user-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .cihaz-user-avatar { width: 40px; height: 40px; border-radius: 11px; background: var(--indigo-soft); color: var(--indigo); display: inline-flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; font-weight: 700; }
        .cihaz-user-name { font-size: 14.5px; font-weight: 700; }
        .cihaz-user-sub { font-size: 11.5px; color: var(--text-2); margin-top: 2px; }
        .cihaz-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 5px; }
        .cihaz-dot.dot-on { background: var(--emerald); box-shadow: 0 0 0 3px rgba(5,150,105,0.18); animation: cihazPulse 1.6s infinite; }
        .cihaz-dot.dot-off { background: var(--text-3); }
        .cihaz-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 700; }
        .cihaz-badge.b-on { background: var(--emerald-soft); color: #065f46; }
        .cihaz-badge.b-multi { background: var(--amber-soft); color: #92400e; }
        .cihaz-user-spacer { flex: 1; }
        .cihaz-logout-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: var(--red-soft); color: var(--red); border: 1px solid rgba(239,68,68,0.25); border-radius: 8px; font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 11.5px; font-weight: 600; cursor: pointer; transition: all 0.15s ease; white-space: nowrap; }
        .cihaz-logout-btn:hover { background: var(--red); color: #fff; border-color: var(--red); }
        .cihaz-logout-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .cihaz-logout-btn i { font-size: 11px; }

        .cihaz-dev-list { margin-top: 12px; display: flex; flex-direction: column; gap: 7px; }
        .cihaz-dev-row { display: flex; align-items: center; gap: 10px; padding: 9px 11px; border-radius: 10px; background: #f9fafb; border: 1px solid var(--border); font-size: 12.5px; }
        .cihaz-dev-row.is-new { background: var(--amber-soft); border-color: rgba(217,119,6,0.3); }
        .cihaz-dev-row.is-bad { background: var(--red-soft); border-color: rgba(111,16,34,0.3); }
        .cihaz-dev-ico { width: 30px; height: 30px; border-radius: 8px; background: #fff; border: 1px solid var(--border); display: inline-flex; align-items: center; justify-content: center; font-size: 13px; color: var(--text-2); flex-shrink: 0; }
        .cihaz-dev-row.is-bad .cihaz-dev-ico { color: var(--red); }
        .cihaz-dev-main { flex: 1; min-width: 0; }
        .cihaz-dev-label { font-weight: 600; color: var(--text-1); }
        .cihaz-dev-meta { font-size: 11px; color: var(--text-2); margin-top: 1px; }
        .cihaz-dev-right { text-align: right; flex-shrink: 0; font-size: 11px; color: var(--text-2); }
        .cihaz-tag { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; border-radius: 6px; font-size: 10px; font-weight: 700; margin-left: 6px; }
        .cihaz-tag.tag-new { background: var(--amber); color: #fff; }
        .cihaz-tag.tag-bad { background: var(--red); color: #fff; }

        .cihaz-empty { text-align: center; padding: 30px; color: var(--text-2); font-size: 13px; }
        .cihaz-err-bar { padding: 12px 16px; border-radius: 12px; background: var(--red-soft); border: 1px solid rgba(111,16,34,0.22); color: #991b1b; font-size: 13px; margin-bottom: 18px; }

        @keyframes cihazPulse { 0%,100% { transform: scale(1); } 50% { transform: scale(1.25); } }

        @media (max-width: 767px) {
            .cihaz-summary { grid-template-columns: repeat(2, 1fr); }
            .cihaz-dev-right { display: none; }
        }
    </style>
</head>
<body>

<header class="top-header no-print">
    <div class="header-inner">
        <a href="index.php" class="header-back" title="Ana Sayfa">
            <i class="fa fa-arrow-left"></i>
        </a>
        <div class="header-divider"></div>
        <div class="header-title">
            <i class="fa-solid <?php echo $logGrup === 'personel' ? 'fa-user-clock' : 'fa-clipboard-list'; ?>"></i>
            <span><?php echo $logGrup === 'personel' ? 'Personel Izleme' : 'Log Merkezi'; ?></span>
        </div>
    </div>
</header>

<main class="page">

    <nav class="tab-nav no-print">
        <?php
        // Sekme etiketleri; yalniz aktif grubun sekmeleri render edilir.
        $sekmeTanim = [
            'aktivite'   => ['fa-clipboard-list', 'Islem Loglari'],
            'hata'       => ['fa-bug', 'Hata Merkezi'],
            'performans' => ['fa-gauge-high', 'Sorgu Performans'],
            'canli'      => ['fa-satellite-dish', 'Canli Aktivite'],
            'ziyaret'    => ['fa-route', 'Sayfa Ziyaretleri'],
            'cihaz'      => ['fa-laptop-mobile', 'Cihazlar'],
        ];
        foreach ($grupSekmeleri as $sk):
            [$ikon, $etiket] = $sekmeTanim[$sk];
        ?>
            <a href="loglar.php?tab=<?php echo $sk . $grupQS; ?>" class="tab-link <?php echo $activeTab === $sk ? 'active' : ''; ?>">
                <i class="fa-solid <?php echo $ikon; ?>"></i>
                <?php echo $etiket; ?>
            </a>
        <?php endforeach; ?>
        <?php if ($logGrup === 'personel'): ?>
            <a href="dashboard_kullanici.php" class="tab-link">
                <i class="fa-solid fa-chart-line"></i>
                Satis Performansi
            </a>
        <?php endif; ?>
    </nav>

<?php if ($activeTab === 'aktivite'): ?>
    <!-- Istatistikler -->
    <div class="stat-grid">
        <div class="stat-tile indigo">
            <div class="t-head">
                <div class="t-ico"><i class="fas fa-chart-bar"></i></div>
                <div class="t-label">Toplam Aktivite</div>
            </div>
            <div class="t-value"><?php echo number_format($toplamLog); ?></div>
            <div class="t-sub">kayit</div>
        </div>

        <div class="stat-tile <?php echo $toplamSupheli > 0 ? 'alert' : 'emerald'; ?>">
            <div class="t-head">
                <div class="t-ico"><i class="fas fa-shield-alt"></i></div>
                <div class="t-label">Supheli Aktivite</div>
            </div>
            <div class="t-value" style="<?php echo $toplamSupheli > 0 ? 'color:var(--red);' : ''; ?>">
                <?php echo number_format($toplamSupheli); ?>
            </div>
            <div class="t-sub"><?php echo $toplamSupheli > 0 ? 'Alisilmadik IP tespit edildi' : 'Risk yok'; ?></div>
        </div>

        <div class="stat-tile emerald">
            <div class="t-head">
                <div class="t-ico"><i class="fas fa-user-shield"></i></div>
                <div class="t-label">En Aktif Kullanici</div>
            </div>
            <div class="t-value" style="font-size:15px;">
                <?php
                if ($kullaniciSayilari !== []) {
                    $enAktif = array_keys($kullaniciSayilari);
                    echo htmlspecialchars($enAktif[0]);
                } else {
                    echo 'N/A';
                }
                ?>
            </div>
            <div class="t-sub">
                <?php echo $kullaniciSayilari !== [] ? (reset($kullaniciSayilari) . ' islem') : '-'; ?>
            </div>
        </div>

        <div class="stat-tile amber">
            <div class="t-head">
                <div class="t-ico"><i class="fas fa-tasks"></i></div>
                <div class="t-label">En Cok Islem</div>
            </div>
            <div class="t-value" style="font-size:15px;">
                <?php
                if ($islemTipleri !== []) {
                    $enCok = array_keys($islemTipleri);
                    echo htmlspecialchars($enCok[0]);
                } else {
                    echo 'N/A';
                }
                ?>
            </div>
            <div class="t-sub">
                <?php echo $islemTipleri !== [] ? (reset($islemTipleri) . ' kez') : '-'; ?>
            </div>
        </div>

        <div class="stat-tile purple">
            <div class="t-head">
                <div class="t-ico"><i class="fas fa-calendar-alt"></i></div>
                <div class="t-label">Tarih Araligi</div>
            </div>
            <div class="t-value" style="font-size:13px;">
                <?php echo date('d.m.Y', strtotime($filterDateFrom)); ?>
            </div>
            <div class="t-sub">&rarr; <?php echo date('d.m.Y', strtotime($filterDateTo)); ?></div>
        </div>
    </div>

    <!-- Filtreler -->
    <section class="glass-card">
        <div class="card-head">
            <div class="icon-box"><i class="fas fa-filter"></i></div>
            <div>
                <h2>Filtreler</h2>
                <p>Kullanici, islem tipi ve tarih araligina gore filtrele</p>
            </div>
        </div>
        <div class="card-body">
        <form method="GET" id="filter-form" class="filter-form">
            <input type="hidden" name="tab" value="aktivite">
            <div class="filter-field">
                <label>Kullanici</label>
                <select name="kullanici">
                    <option value="">Tum Kullanicilar</option>
                    <?php foreach ($tumKullanicilar as $id => $adi): ?>
                        <option value="<?php echo $id; ?>" <?php echo $filterKullanici == $id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $adi); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-field">
                <label>Islem Tipi</label>
                <select name="islem_tipi">
                    <option value="">Tümü</option>
                    <optgroup label="Fiş İşlemleri">
                        <option value="OLUSTURMA" <?php echo $filterIslemTipi === 'OLUSTURMA' ? 'selected' : ''; ?>>Fiş Oluşturma</option>
                        <option value="GUNCELLEME" <?php echo $filterIslemTipi === 'GUNCELLEME' ? 'selected' : ''; ?>>Fiş Güncelleme</option>
                        <option value="SILME" <?php echo $filterIslemTipi === 'SILME' ? 'selected' : ''; ?>>Fiş Silme</option>
                    </optgroup>
                    <optgroup label="Satır İşlemleri">
                        <option value="EKLE" <?php echo $filterIslemTipi === 'EKLE' ? 'selected' : ''; ?>>Satır Ekleme</option>
                        <option value="DUZENLE" <?php echo $filterIslemTipi === 'DUZENLE' ? 'selected' : ''; ?>>Satır Düzenleme</option>
                        <option value="SIL" <?php echo $filterIslemTipi === 'SIL' ? 'selected' : ''; ?>>Satır Silme</option>
                    </optgroup>
                    <optgroup label="Değişiklikler">
                        <option value="ISKONTO" <?php echo $filterIslemTipi === 'ISKONTO' ? 'selected' : ''; ?>>İskonto</option>
                        <option value="KDV_DEGISIKLIK" <?php echo $filterIslemTipi === 'KDV_DEGISIKLIK' ? 'selected' : ''; ?>>KDV Değişikliği</option>
                        <option value="FIYAT_DEGISIKLIK" <?php echo $filterIslemTipi === 'FIYAT_DEGISIKLIK' ? 'selected' : ''; ?>>Fiyat Değişikliği</option>
                    </optgroup>
                    <optgroup label="Giriş/Çıkış">
                        <option value="GIRIS" <?php echo $filterIslemTipi === 'GIRIS' ? 'selected' : ''; ?>>Giriş</option>
                        <option value="CIKIS" <?php echo $filterIslemTipi === 'CIKIS' ? 'selected' : ''; ?>>Çıkış</option>
                    </optgroup>
                    <optgroup label="Yazdırma">
                        <option value="FIS" <?php echo $filterIslemTipi === 'FIS' ? 'selected' : ''; ?>>Fiş Yazdırma</option>
                        <option value="FIYATLI" <?php echo $filterIslemTipi === 'FIYATLI' ? 'selected' : ''; ?>>Fiyatlı Yazdırma</option>
                        <option value="FIYATSIZ" <?php echo $filterIslemTipi === 'FIYATSIZ' ? 'selected' : ''; ?>>Fiyatsız Yazdırma</option>
                        <option value="EXCEL" <?php echo $filterIslemTipi === 'EXCEL' ? 'selected' : ''; ?>>Excel Export</option>
                        <option value="HTML" <?php echo $filterIslemTipi === 'HTML' ? 'selected' : ''; ?>>HTML Yazdırma</option>
                        <option value="BARKOD" <?php echo $filterIslemTipi === 'BARKOD' ? 'selected' : ''; ?>>Barkod Yazdırma</option>
                    </optgroup>
                </select>
            </div>

            <div class="filter-field">
                <label>Baslangic</label>
                <input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>">
            </div>

            <div class="filter-field">
                <label>Bitis</label>
                <input type="date" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>">
            </div>

            <div class="filter-field">
                <label>Arama</label>
                <input type="text" name="search" value="<?php echo htmlspecialchars($filterSearch); ?>" placeholder="Fis, cari, stok...">
            </div>
        </form>

        <div class="filter-actions">
            <p class="filter-summary">
                <strong><?php echo number_format($toplamGrup); ?></strong> fis,
                <strong><?php echo number_format($toplamLog); ?></strong> islem bulundu
            </p>
            <a href="loglar.php?tab=aktivite" class="btn btn-ghost">
                <i class="fa-solid fa-rotate-left"></i> Sifirla
            </a>
            <button type="submit" form="filter-form" class="btn btn-primary">
                <i class="fa-solid fa-search"></i> Filtrele
            </button>
        </div>
        </div>
    </section>

    <!-- Supheli IP Aktiviteleri -->
    <?php if ($supheliharitasi !== []): ?>
        <section class="supheli-card">
            <div class="supheli-head">
                <div class="s-ico"><i class="fas fa-exclamation-triangle"></i></div>
                <div>
                    <h3>Supheli IP Aktiviteleri Tespit Edildi!</h3>
                    <p>Kullanicilar alisilmadik IP adreslerinden giris yapti</p>
                </div>
            </div>

            <div class="supheli-tools">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox" id="selectAllSupheli" onclick="toggleAllSupheli()">
                    Tumunu Sec
                </label>
                <button type="button" onclick="onaylaSecililer()" class="btn btn-emerald btn-small">
                    <i class="fas fa-check-double"></i> Secilenleri Onayla
                </button>
                <span id="seciliSayisi" style="color:var(--text-2);"></span>
            </div>

            <div class="supheli-list">
                <?php
                $supheSayac = 0;
                foreach ($supheliharitasi as $logs):
                    foreach ($logs as $log):
                        $supheSayac++;
                        if ($supheSayac > 20) { break 2; }
                ?>
                    <div class="supheli-item">
                        <input type="checkbox"
                               class="supheli-checkbox"
                               data-kullanici-id="<?php echo (int)$log['KULLANICI']; ?>"
                               data-ip-adresi="<?php echo htmlspecialchars((string) $log['IP_ADRESI']); ?>"
                               data-kullanici-adi="<?php echo htmlspecialchars((string) $log['KULLANICI_ADI']); ?>"
                               onchange="updateSeciliSayisi()">
                        <div class="s-body">
                            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                                <i class="fas fa-user" style="color:var(--red);"></i>
                                <span class="s-name"><?php echo htmlspecialchars((string) $log['KULLANICI_ADI']); ?></span>
                                <span class="chip chip-red">Supheli</span>
                            </div>
                            <div style="font-size:12px;color:var(--text-2);margin-bottom:4px;">
                                <i class="fas fa-network-wired"></i>
                                Alisilmadik IP:
                                <span class="s-ip"><?php echo htmlspecialchars((string) $log['IP_ADRESI']); ?></span>
                            </div>
                            <?php if (isset($log['SUPHELI_ACIKLAMA'])): ?>
                                <p style="font-size:10.5px;color:var(--text-3);font-style:italic;margin:0;">
                                    <i class="fas fa-info-circle"></i>
                                    <?php echo htmlspecialchars((string) $log['SUPHELI_ACIKLAMA']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="s-time">
                            <div><i class="fas fa-calendar"></i> <?php echo date('d.m.Y', strtotime((string) $log['TARIH'])); ?></div>
                            <div><i class="fas fa-clock"></i> <?php echo date('H:i:s', strtotime((string) $log['TARIH'])); ?></div>
                        </div>
                    </div>
<?php
                    endforeach;
                endforeach;
                ?>
            </div>
        </section>
    <?php endif; ?>

    <!-- Giris / Cikis Loglari -->
    <?php if ($girisCikisLoglar !== []): ?>
        <section class="glass-card">
            <div class="card-head">
                <div class="icon-box" style="background:var(--sky-soft);color:var(--sky);">
                    <i class="fas fa-user-clock"></i>
                </div>
                <div>
                    <h2>Giris / Cikis Kayitlari</h2>
                    <p>Son 20 oturum hareketi</p>
                </div>
            </div>
            <div class="card-body">
            <div class="gc-list">
                <?php
                $sonGirisCikis = [];
                foreach ($girisCikisLoglar as $loglar) {
                    foreach ($loglar as $log) {
                        $sonGirisCikis[] = $log;
                    }
                }
                usort($sonGirisCikis, fn($a, $b): int => strtotime((string) $b['TARIH']) - strtotime((string) $a['TARIH']));
                $sonGirisCikis = array_slice($sonGirisCikis, 0, 20);

                if ($sonGirisCikis === []):
                ?>
                    <div class="table-empty">
                        <i class="fas fa-info-circle"></i>
                        <div>Filtreye uygun kayit bulunamadi</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($sonGirisCikis as $log):
                        $isGiris = $log['ISLEM_TIPI'] == 'GIRIS';
                        $isSupheli = isset($log['SUPHELI']) && $log['SUPHELI'];
                        $basarili = $log['BASARILI'] ?? 1;
                        $basarisiz = $basarili == 0;
                        $rowClass = $isSupheli ? 'is-supheli' : ($basarisiz ? 'is-fail' : '');
                    ?>
                        <div class="gc-row <?php echo $rowClass; ?>">
                            <?php if ($isSupheli): ?>
                                <i class="fas fa-exclamation-triangle" style="color:var(--red);"></i>
                            <?php elseif ($basarisiz): ?>
                                <i class="fas fa-times-circle" style="color:var(--amber);"></i>
                            <?php else: ?>
                                <i class="fas <?php echo $isGiris ? 'fa-sign-in-alt' : 'fa-sign-out-alt'; ?>" style="color:var(--sky);"></i>
                            <?php endif; ?>
                            <span class="gc-user"><?php echo htmlspecialchars((string) ($log['KULLANICI_ADI'] ?: 'Bilinmiyor')); ?></span>
                            <span class="gc-sep">&middot;</span>
                            <span><?php echo $isGiris ? ($basarisiz ? 'Basarisiz giris' : 'Giris yapti') : 'Cikis yapti'; ?></span>
                            <span class="gc-sep">&middot;</span>
                            <span class="gc-ip"><?php echo htmlspecialchars((string) ($log['IP_ADRESI'] ?: '-')); ?></span>
                            <?php if ($isSupheli): ?>
                                <span class="chip chip-red">Supheli</span>
                            <?php endif; ?>
                            <?php if ($basarisiz): ?>
                                <span class="chip chip-open">Basarisiz</span>
                            <?php endif; ?>
                            <span class="gc-time"><?php echo date('d.m H:i', strtotime((string) $log['TARIH'])); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            </div>
        </section>
    <?php endif; ?>

    <!-- Fis Bazinda Gruplu Aktiviteler -->
    <section class="glass-card">
        <div class="card-head">
            <div class="icon-box"><i class="fas fa-receipt"></i></div>
            <div>
                <h2>Fis Bazinda Aktiviteler</h2>
                <p>Fise tiklayarak islem zaman cizelgesini gorun</p>
            </div>
        </div>
        <div class="card-body">
        <?php if ($sayfaGruplar === []): ?>
            <div class="table-empty">
                <i class="fas fa-inbox"></i>
                <div>Filtreye uygun aktivite bulunamadi</div>
            </div>
        <?php else: ?>
            <?php foreach ($sayfaGruplar as $grup): ?>
                <div class="fis-group">
                    <div class="fis-head" id="head-<?php echo $grup['FICHENO']; ?>" onclick="toggleGrup('<?php echo $grup['FICHENO']; ?>')">
                        <div class="f-left">
                            <div class="f-ico"><i class="fas fa-file-invoice"></i></div>
                            <div style="min-width:0;">
                                <div class="f-title"><?php echo htmlspecialchars((string) ($grup['CARI_ADI'] ?: 'Cari Belirtilmemis')); ?></div>
                                <div class="f-sub">
                                    <i class="fas fa-hashtag"></i> <span class="fis-no"><?php echo htmlspecialchars((string) $grup['FICHENO']); ?></span>
                                    <?php if ($grup['TOPLAM_TUTAR'] > 0): ?>
                                        &nbsp;&middot;&nbsp;<i class="fas fa-lira-sign"></i> <?php echo number_format($grup['TOPLAM_TUTAR'], 2, ',', '.'); ?>
                                    <?php endif; ?>
                                    &nbsp;&middot;&nbsp;<?php echo date('d.m.Y', strtotime((string) $grup['SON_TARIH'])); ?>
                                </div>
                            </div>
                        </div>
                        <div class="f-right">
                            <span class="f-count"><?php echo count($grup['ISLEMLER']); ?> islem</span>
                            <i class="fas fa-chevron-down f-chevron" id="icon-<?php echo $grup['FICHENO']; ?>"></i>
                        </div>
                    </div>

                    <div class="fis-body" id="grup-<?php echo $grup['FICHENO']; ?>">
                        <?php
                        $islemler = $grup['ISLEMLER'];
                        usort($islemler, fn($a, $b): int => strtotime((string) $a['TARIH']) - strtotime((string) $b['TARIH']));
                        foreach ($islemler as $log):
                            $icon = $islemRenkleri[$log['ISLEM_TIPI']]['icon'] ?? 'fa-circle';
                        ?>
                            <div class="tl-item">
                                <div class="tl-cat"><i class="fas <?php echo $icon; ?>"></i></div>
                                <div class="tl-body">
                                    <div class="tl-top">
                                        <span class="tl-type"><?php echo htmlspecialchars((string) $log['ISLEM_TIPI']); ?></span>
                                        <?php if ($log['STOK_ADI']): ?>
                                            <span class="tl-name"><?php echo htmlspecialchars((string) $log['STOK_ADI']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($log['ACIKLAMA']): ?>
                                        <div class="tl-desc"><?php echo htmlspecialchars((string) $log['ACIKLAMA']); ?></div>
                                    <?php endif; ?>
                                    <div class="tl-meta">
                                        <span><i class="fas fa-user"></i><?php echo htmlspecialchars((string) ($log['KULLANICI_ADI'] ?: '-')); ?></span>
                                        <span><i class="fas fa-network-wired"></i><?php echo htmlspecialchars((string) ($log['IP_ADRESI'] ?: '-')); ?></span>
                                    </div>
                                </div>
                                <div class="tl-time"><?php echo date('H:i', strtotime((string) $log['TARIH'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if ($totalPages > 1): ?>
                <nav class="pagination no-print">
                    <?php if ($currentPage > 1): ?>
                        <a href="?p=1<?php echo $queryString !== '' && $queryString !== '0' ? '&' . $queryString : ''; ?>"><i class="fa fa-angle-double-left"></i></a>
                        <a href="?p=<?php echo $currentPage - 1; ?><?php echo $queryString !== '' && $queryString !== '0' ? '&' . $queryString : ''; ?>"><i class="fa fa-angle-left"></i></a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $currentPage - 2); $i <= min($totalPages, $currentPage + 2); $i++): ?>
                        <a href="?p=<?php echo $i; ?><?php echo $queryString !== '' && $queryString !== '0' ? '&' . $queryString : ''; ?>"
                           class="<?php echo $i === $currentPage ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                    <?php if ($currentPage < $totalPages): ?>
                        <a href="?p=<?php echo $currentPage + 1; ?><?php echo $queryString !== '' && $queryString !== '0' ? '&' . $queryString : ''; ?>"><i class="fa fa-angle-right"></i></a>
                        <a href="?p=<?php echo $totalPages; ?><?php echo $queryString !== '' && $queryString !== '0' ? '&' . $queryString : ''; ?>"><i class="fa fa-angle-double-right"></i></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
        </div>
    </section>
<?php elseif ($activeTab === 'hata'): ?>
    <section class="glass-card">
        <div class="card-head">
            <div class="icon-box"><i class="fas fa-filter"></i></div>
            <div>
                <h2>Hata Filtreleri</h2>
                <p>Ref kodu, scope, tarih ve durum bazinda arama</p>
            </div>
        </div>
        <div class="card-body">
            <form method="get" class="filter-form" style="grid-template-columns:repeat(4,1fr);">
                <input type="hidden" name="tab" value="hata">
                <div class="filter-field" style="grid-column:span 2;">
                    <label>Ref Kodu</label>
                    <input type="text" name="hm_ref" value="<?php echo htmlspecialchars($hmRef, ENT_QUOTES, 'UTF-8'); ?>" placeholder="ERR-20260210163154-9FBA96">
                </div>
                <div class="filter-field">
                    <label>Scope</label>
                    <input type="text" name="hm_scope" value="<?php echo htmlspecialchars($hmScopeFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="rapor/...">
                </div>
                <div class="filter-field">
                    <label>Durum</label>
                    <select name="hm_cozum">
                        <option value="all" <?php echo $hmResolutionFilter === 'all' ? 'selected' : ''; ?>>Tumu</option>
                        <option value="open" <?php echo $hmResolutionFilter === 'open' ? 'selected' : ''; ?>>Acik</option>
                        <option value="solved" <?php echo $hmResolutionFilter === 'solved' ? 'selected' : ''; ?>>Cozuldu</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label>Tarih Baslangic</label>
                    <input type="date" name="hm_date_from" value="<?php echo htmlspecialchars($hmDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="filter-field">
                    <label>Tarih Bitis</label>
                    <input type="date" name="hm_date_to" value="<?php echo htmlspecialchars($hmDateTo, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="filter-field">
                    <label>Limit</label>
                    <input type="number" name="hm_limit" min="20" max="500" value="<?php echo (int) $hmLimit; ?>">
                </div>
                <div class="filter-field">
                    <label>Metin</label>
                    <input type="text" name="hm_q" value="<?php echo htmlspecialchars($hmTextFilter, ENT_QUOTES, 'UTF-8'); ?>" placeholder="SQLSTATE, URI...">
                </div>
                <div class="filter-actions" style="grid-column:1 / -1;">
                    <a href="loglar.php?tab=hata" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Temizle</a>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-search"></i> Ara</button>
                </div>
            </form>
        </div>
    </section>

    <section class="glass-card">
        <div class="card-head">
            <div class="icon-box" style="background:var(--indigo-soft);color:var(--indigo);"><i class="fas fa-folder-open"></i></div>
            <div>
                <h2>Aktif Log Dosyalari</h2>
                <p>Okunan log kaynaklarinin yollari</p>
            </div>
        </div>
        <div class="card-body">
            <div class="logfile-grid">
                <div><strong>app-error:</strong> <code><?php echo htmlspecialchars($hmAppErrorFile, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <div><strong>query-error:</strong> <code><?php echo htmlspecialchars($hmQueryErrorFile, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <div><strong>php_errors:</strong> <code><?php echo htmlspecialchars($hmPhpErrorFile, ENT_QUOTES, 'UTF-8'); ?></code></div>
                <div><strong>status:</strong> <code><?php echo htmlspecialchars($hmStatusFile, ENT_QUOTES, 'UTF-8'); ?></code></div>
            </div>
        </div>
    </section>

    <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
        <div class="stat-tile">
            <div class="t-head"><div class="t-ico"><i class="fas fa-bug"></i></div><div class="t-label">Eslesen Hata</div></div>
            <div class="t-value"><?php echo number_format($hmTotalFound, 0, ',', '.'); ?></div>
            <div class="t-sub">filtreye gore</div>
        </div>
        <div class="stat-tile indigo">
            <div class="t-head"><div class="t-ico"><i class="fas fa-eye"></i></div><div class="t-label">Gorunen Kayit</div></div>
            <div class="t-value"><?php echo number_format(count($hmRows), 0, ',', '.'); ?></div>
            <div class="t-sub">listede</div>
        </div>
        <div class="stat-tile amber">
            <div class="t-head"><div class="t-ico"><i class="fas fa-circle-exclamation"></i></div><div class="t-label">Acik</div></div>
            <div class="t-value"><?php echo number_format($hmOpenCount, 0, ',', '.'); ?></div>
            <div class="t-sub">cozulmedi</div>
        </div>
        <div class="stat-tile emerald">
            <div class="t-head"><div class="t-ico"><i class="fas fa-circle-check"></i></div><div class="t-label">Cozuldu</div></div>
            <div class="t-value"><?php echo number_format($hmSolvedCount, 0, ',', '.'); ?></div>
            <div class="t-sub">isaretli</div>
        </div>
    </div>

    <?php if ($hmRef !== '' && $hmSelected === null): ?>
        <section class="glass-card">
            <div class="card-body" style="color:var(--red);">
                <i class="fas fa-circle-exclamation"></i>
                Ref kodu bulunamadi: <strong><?php echo htmlspecialchars($hmRef, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($hmSelected !== null): ?>
        <section class="glass-card">
            <div class="card-head">
                <div class="icon-box"><i class="fas fa-bug"></i></div>
                <div>
                    <h2>Hata Detayi</h2>
                    <p>Ref ozeti, context ve trace bilgisi</p>
                </div>
                <div class="head-actions">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($hataCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="toggle_solved">
                        <input type="hidden" name="ref" value="<?php echo htmlspecialchars((string) ($hmSelected['ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="solved" value="<?php echo !empty($hmSelected['is_solved']) ? '0' : '1'; ?>">
                        <input type="hidden" name="return_qs" value="<?php echo htmlspecialchars($hmReturnQueryString, ENT_QUOTES, 'UTF-8'); ?>">
                        <button type="submit" class="btn <?php echo !empty($hmSelected['is_solved']) ? 'btn-ghost' : 'btn-emerald'; ?>">
                            <i class="fa-solid <?php echo !empty($hmSelected['is_solved']) ? 'fa-xmark' : 'fa-check'; ?>"></i>
                            <?php echo !empty($hmSelected['is_solved']) ? 'Cozum Isaretini Kaldir' : 'Cozuldu Olarak Isaretle'; ?>
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <div class="err-detail-head">
                    <span class="ref-big"><?php echo htmlspecialchars((string) ($hmSelected['ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php if (!empty($hmSelected['is_solved'])): ?>
                        <span class="chip chip-solved">Cozuldu</span>
                    <?php else: ?>
                        <span class="chip chip-open">Acik</span>
                    <?php endif; ?>
                    <span style="font-size:12px;color:var(--text-2);"><i class="far fa-clock"></i> <?php echo htmlspecialchars((string) ($hmSelected['ts'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                </div>

                <?php if (!empty($hmSelected['is_solved'])): ?>
                    <div style="margin-bottom:12px;padding:10px 14px;border-radius:10px;background:var(--emerald-soft);border:1px solid rgba(5,150,105,0.22);font-size:11.5px;color:#065f46;">
                        Cozen: <strong><?php echo htmlspecialchars((string) ($hmSelected['solved_by'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        &middot; Tarih: <strong><?php echo htmlspecialchars((string) ($hmSelected['solved_at'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                <?php endif; ?>

                <dl class="kv-list" style="margin-bottom:14px;">
                    <dt>Scope</dt><dd><?php echo htmlspecialchars((string) ($hmSelected['scope'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Mesaj</dt><dd style="color:var(--red);"><?php echo htmlspecialchars((string) ($hmSelected['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>URI</dt><dd><?php echo htmlspecialchars((string) ($hmSelected['request']['uri'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt>Script</dt><dd><?php echo htmlspecialchars((string) ($hmSelected['request']['script'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></dd>
                </dl>

                <div class="grid-2">
                    <div class="panel">
                        <div class="panel-head">Context</div>
                        <pre class="panel-body"><?php echo htmlspecialchars(lh_json_pretty($hmSelected['context'] ?? []), ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                    <div class="panel">
                        <div class="panel-head">Request</div>
                        <pre class="panel-body"><?php echo htmlspecialchars(lh_json_pretty($hmSelected['request'] ?? []), ENT_QUOTES, 'UTF-8'); ?></pre>
                    </div>
                </div>

                <details class="panel" style="margin-top:12px;">
                    <summary class="panel-head" style="cursor:pointer;">Trace Detayi</summary>
                    <pre class="panel-body"><?php echo htmlspecialchars((string) ($hmSelected['trace'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></pre>
                </details>

                <?php if ($hmPhpMatches !== []): ?>
                    <div class="panel" style="margin-top:12px;border-color:rgba(217,119,6,0.3);">
                        <div class="panel-head" style="background:var(--amber-soft);color:var(--amber);">php_errors.log Eslesmeleri</div>
                        <div style="padding:12px 14px;display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ($hmPhpMatches as $line): ?>
                                <pre style="font-family:'JetBrains Mono',monospace;font-size:11px;background:#fafafa;padding:8px 10px;border-radius:8px;border:1px solid var(--border);white-space:pre-wrap;word-break:break-word;margin:0;"><?php echo htmlspecialchars($line, ENT_QUOTES, 'UTF-8'); ?></pre>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($hmNearbyQueryErrors !== []): ?>
                    <div class="panel" style="margin-top:12px;border-color:rgba(2,132,199,0.28);">
                        <div class="panel-head" style="background:var(--sky-soft);color:var(--sky);">Yakin Query Error Kayitlari</div>
                        <div class="data-table-wrap">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Zaman</th>
                                        <th>Hata</th>
                                        <th>SQL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($hmNearbyQueryErrors as $qe): ?>
                                        <tr>
                                            <td data-label="Zaman"><?php echo htmlspecialchars((string) ($qe['ts'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="Hata" style="color:var(--red);"><?php echo htmlspecialchars((string) ($qe['error'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="SQL" class="mono-cell" style="word-break:break-all;"><?php echo htmlspecialchars((string) ($qe['sql'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="glass-card">
        <div class="card-head">
            <div class="icon-box"><i class="fas fa-list"></i></div>
            <div>
                <h2>Hata Listesi</h2>
                <p>Filtreye uyan son hatalar</p>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Zaman</th>
                        <th>Ref</th>
                        <th>Durum</th>
                        <th>Scope</th>
                        <th>Mesaj</th>
                        <th>URI</th>
                        <th>Isaret</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($hmRows === []): ?>
                        <tr>
                            <td colspan="7" class="table-empty">
                                <i class="fas fa-inbox"></i>
                                Kayit bulunamadi.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($hmRows as $r): ?>
                            <?php
                            $hmRefLinkParams = $_GET;
                            $hmRefLinkParams['tab'] = 'hata';
                            $hmRefLinkParams['hm_ref'] = (string) ($r['ref'] ?? '');
                            ?>
                            <tr>
                                <td data-label="Zaman" style="white-space:nowrap;color:var(--text-2);"><?php echo htmlspecialchars((string) ($r['ts'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="Ref" style="white-space:nowrap;">
                                    <a href="?<?php echo htmlspecialchars(http_build_query($hmRefLinkParams), ENT_QUOTES, 'UTF-8'); ?>" class="mono-cell" style="color:var(--red);font-weight:700;text-decoration:none;">
                                        <?php echo htmlspecialchars((string) ($r['ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                    </a>
                                </td>
                                <td data-label="Durum" style="white-space:nowrap;">
                                    <?php if (!empty($r['is_solved'])): ?>
                                        <span class="chip chip-solved">Cozuldu</span>
                                    <?php else: ?>
                                        <span class="chip chip-open">Acik</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Scope" style="word-break:break-all;color:var(--text-2);font-size:11px;"><?php echo htmlspecialchars((string) ($r['scope'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="Mesaj" style="max-width:380px;word-wrap:break-word;"><?php echo htmlspecialchars((string) ($r['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="URI" class="mono-cell" style="max-width:320px;word-break:break-all;color:var(--text-2);"><?php echo htmlspecialchars((string) ($r['request']['uri'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="" class="action-cell" style="white-space:nowrap;">
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($hataCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="action" value="toggle_solved">
                                        <input type="hidden" name="ref" value="<?php echo htmlspecialchars((string) ($r['ref'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="solved" value="<?php echo !empty($r['is_solved']) ? '0' : '1'; ?>">
                                        <input type="hidden" name="return_qs" value="<?php echo htmlspecialchars($hmReturnQueryString, ENT_QUOTES, 'UTF-8'); ?>">
                                        <button type="submit" class="btn btn-small <?php echo !empty($r['is_solved']) ? 'btn-ghost' : 'btn-emerald'; ?>">
                                            <?php echo !empty($r['is_solved']) ? 'Kaldir' : 'Cozuldu'; ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php elseif ($activeTab === 'performans'): ?>
    <section class="glass-card">
        <div class="card-head">
            <div class="icon-box"><i class="fas fa-gauge-high"></i></div>
            <div>
                <h2>Performans Filtreleri</h2>
                <p>Slow query loglarinda tarih, page ve endpoint filtresi</p>
            </div>
        </div>
        <div class="card-body">
            <form method="get" class="filter-form" style="grid-template-columns:repeat(4,1fr);">
                <input type="hidden" name="tab" value="performans">
                <div class="filter-field">
                    <label>Tarih Baslangic</label>
                    <input type="date" name="sp_date_from" value="<?php echo htmlspecialchars($pmDateFrom, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="filter-field">
                    <label>Tarih Bitis</label>
                    <input type="date" name="sp_date_to" value="<?php echo htmlspecialchars($pmDateTo, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="filter-field">
                    <label>Min Sure (ms)</label>
                    <input type="number" step="0.1" min="1" max="60000" name="sp_min_ms" value="<?php echo htmlspecialchars((string) number_format($pmMinMs, 2, '.', ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="filter-field">
                    <label>Limit</label>
                    <input type="number" min="10" max="200" name="sp_limit" value="<?php echo (int) $pmLimit; ?>">
                </div>
                <div class="filter-field">
                    <label>Page</label>
                    <input type="text" name="sp_page" value="<?php echo htmlspecialchars($pmFilterPage, ENT_QUOTES, 'UTF-8'); ?>" placeholder="cari/cari.php">
                </div>
                <div class="filter-field" style="grid-column:span 3;">
                    <label>Endpoint / URI</label>
                    <input type="text" name="sp_uri" value="<?php echo htmlspecialchars($pmFilterUri, ENT_QUOTES, 'UTF-8'); ?>" placeholder="/cari.php">
                </div>
                <div class="filter-field" style="grid-column:span 4;">
                    <label>Metin (SQL / Meta)</label>
                    <input type="text" name="sp_q" value="<?php echo htmlspecialchars($pmFilterText, ENT_QUOTES, 'UTF-8'); ?>" placeholder="SELECT, action, WHERE...">
                </div>
                <div class="filter-actions" style="grid-column:1 / -1;">
                    <a href="loglar.php?tab=performans" class="btn btn-ghost"><i class="fa-solid fa-rotate-left"></i> Temizle</a>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-chart-line"></i> Analiz Et</button>
                </div>
            </form>
        </div>
    </section>

    <section class="glass-card">
        <div class="card-head">
            <div class="icon-box" style="background:var(--indigo-soft);color:var(--indigo);"><i class="fas fa-file-code"></i></div>
            <div>
                <h2>Aktif Slow Query Logu</h2>
                <p>Okunan kaynak dosya</p>
            </div>
        </div>
        <div class="card-body">
            <code style="font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--indigo);word-break:break-all;">
                <?php echo htmlspecialchars($pmSlowQueryFile, ENT_QUOTES, 'UTF-8'); ?>
            </code>
        </div>
    </section>

    <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
        <div class="stat-tile">
            <div class="t-head"><div class="t-ico"><i class="fas fa-list-check"></i></div><div class="t-label">Eslesen Kayit</div></div>
            <div class="t-value"><?php echo number_format($pmTotalFound, 0, ',', '.'); ?></div>
            <div class="t-sub">filtreye gore</div>
        </div>
        <div class="stat-tile indigo">
            <div class="t-head"><div class="t-ico"><i class="fas fa-stopwatch"></i></div><div class="t-label">Ortalama Sure</div></div>
            <div class="t-value"><?php echo number_format($pmAvgDuration, 2, ',', '.'); ?> <span style="font-size:13px;font-weight:600;color:var(--text-2);">ms</span></div>
            <div class="t-sub">ort. sorgu</div>
        </div>
        <div class="stat-tile amber">
            <div class="t-head"><div class="t-ico"><i class="fas fa-turtle"></i></div><div class="t-label">En Yavas</div></div>
            <div class="t-value"><?php echo number_format($pmMaxDuration, 2, ',', '.'); ?> <span style="font-size:13px;font-weight:600;color:var(--text-2);">ms</span></div>
            <div class="t-sub">tek sorgu</div>
        </div>
        <div class="stat-tile emerald">
            <div class="t-head"><div class="t-ico"><i class="fas fa-hourglass-half"></i></div><div class="t-label">Toplam Bekleme</div></div>
            <div class="t-value"><?php echo number_format($pmTotalDuration, 2, ',', '.'); ?> <span style="font-size:13px;font-weight:600;color:var(--text-2);">ms</span></div>
            <div class="t-sub">kumule</div>
        </div>
    </div>

    <section class="glass-card">
        <div class="card-head">
            <div class="icon-box"><i class="fas fa-turtle"></i></div>
            <div>
                <h2>En Yavas <?php echo (int) $pmLimit; ?> Sorgu</h2>
                <p>Slow query log kayitlari (suresine gore)</p>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <div class="data-table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Zaman</th>
                        <th>Sure (ms)</th>
                        <th>Page / Action</th>
                        <th>Endpoint</th>
                        <th>Fingerprint</th>
                        <th>SQL</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php if ($pmRowsTop === []): ?>
                        <tr>
                            <td colspan="6" class="table-empty">
                                <i class="fas fa-inbox"></i>
                                Kayit bulunamadi.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pmRowsTop as $row): ?>
                            <?php
                            $sqlText = (string) ($row['sql'] ?? '');
                            $sqlShort = mb_strlen($sqlText, 'UTF-8') > 280 ? (mb_substr($sqlText, 0, 280, 'UTF-8') . '...') : $sqlText;
                            ?>
                            <tr>
                                <td data-label="Zaman" style="white-space:nowrap;color:var(--text-2);"><?php echo htmlspecialchars((string) ($row['ts'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="Sure" class="num red" style="white-space:nowrap;">
                                    <?php echo number_format((float) ($row['duration_ms'] ?? 0), 2, ',', '.'); ?>
                                    <span style="font-size:10px;color:var(--text-3);font-weight:500;">/ esik <?php echo number_format((float) ($row['threshold_ms'] ?? 0), 0, ',', '.'); ?></span>
                                </td>
                                <td data-label="Page" style="white-space:nowrap;">
                                    <div style="font-weight:700;"><?php echo htmlspecialchars((string) ($row['page'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div style="font-size:10.5px;color:var(--text-3);"><?php echo htmlspecialchars((string) ($row['action'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td data-label="Endpoint" class="mono-cell" style="word-break:break-all;"><?php echo htmlspecialchars((string) ($row['endpoint'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td data-label="Fingerprint" style="white-space:nowrap;">
                                    <code style="font-family:'JetBrains Mono',monospace;font-size:10.5px;background:#f3f4f6;padding:3px 8px;border-radius:6px;color:var(--text-2);"><?php echo htmlspecialchars((string) ($row['fingerprint'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></code>
                                </td>
                                <td data-label="SQL">
                                    <details>
                                        <summary style="cursor:pointer;font-size:11px;color:var(--text-2);">SQL goster</summary>
                                        <pre style="font-family:'JetBrains Mono',monospace;font-size:10.5px;background:#fafafa;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-top:6px;white-space:pre-wrap;word-break:break-word;"><?php echo htmlspecialchars($sqlShort, ENT_QUOTES, 'UTF-8'); ?></pre>
                                    </details>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <div class="grid-2">
        <section class="glass-card">
            <div class="card-head">
                <div class="icon-box" style="background:var(--indigo-soft);color:var(--indigo);"><i class="fas fa-link"></i></div>
                <div>
                    <h2>En Yogun Endpoint</h2>
                    <p>Toplam bekleme suresine gore Top 20</p>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Endpoint</th>
                            <th>Adet</th>
                            <th>Ort.</th>
                            <th>Maks.</th>
                            <th>Toplam</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($pmEndpointStats === []): ?>
                            <tr><td colspan="5" class="table-empty"><i class="fas fa-inbox"></i>Kayit bulunamadi.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pmEndpointStats as $endpointRow): ?>
                                <tr>
                                    <td data-label="Endpoint" class="mono-cell" style="word-break:break-all;"><?php echo htmlspecialchars((string) ($endpointRow['endpoint'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td data-label="Adet" class="num" style="white-space:nowrap;"><?php echo number_format((int) ($endpointRow['count'] ?? 0), 0, ',', '.'); ?></td>
                                    <td data-label="Ort." class="num amber" style="white-space:nowrap;"><?php echo number_format((float) ($endpointRow['avg_ms'] ?? 0), 2, ',', '.'); ?></td>
                                    <td data-label="Maks." class="num" style="white-space:nowrap;"><?php echo number_format((float) ($endpointRow['max_ms'] ?? 0), 2, ',', '.'); ?></td>
                                    <td data-label="Toplam" class="num red" style="white-space:nowrap;"><?php echo number_format((float) ($endpointRow['total_ms'] ?? 0), 2, ',', '.'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <section class="glass-card">
            <div class="card-head">
                <div class="icon-box" style="background:var(--purple-soft);color:var(--purple);"><i class="fas fa-fingerprint"></i></div>
                <div>
                    <h2>SQL Fingerprint Ozeti</h2>
                    <p>Benzer sorgu kalibi Top 20</p>
                </div>
            </div>
            <div class="card-body" style="padding:0;">
                <div class="data-table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Fingerprint</th>
                            <th>Adet</th>
                            <th>Ort.</th>
                            <th>Maks.</th>
                            <th>Ornek</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if ($pmFingerprintStats === []): ?>
                            <tr><td colspan="5" class="table-empty"><i class="fas fa-inbox"></i>Kayit bulunamadi.</td></tr>
                        <?php else: ?>
                            <?php foreach ($pmFingerprintStats as $fpRow): ?>
                                <tr>
                                    <td data-label="Fingerprint" style="white-space:nowrap;">
                                        <code style="font-family:'JetBrains Mono',monospace;font-size:10.5px;background:#f3f4f6;padding:3px 8px;border-radius:6px;color:var(--text-2);"><?php echo htmlspecialchars((string) ($fpRow['fingerprint'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></code>
                                    </td>
                                    <td data-label="Adet" class="num" style="white-space:nowrap;"><?php echo number_format((int) ($fpRow['count'] ?? 0), 0, ',', '.'); ?></td>
                                    <td data-label="Ort." class="num amber" style="white-space:nowrap;"><?php echo number_format((float) ($fpRow['avg_ms'] ?? 0), 2, ',', '.'); ?></td>
                                    <td data-label="Maks." class="num red" style="white-space:nowrap;"><?php echo number_format((float) ($fpRow['max_ms'] ?? 0), 2, ',', '.'); ?></td>
                                    <td data-label="Ornek" style="word-break:break-all;">
                                        <div class="mono-cell"><?php echo htmlspecialchars((string) ($fpRow['sample_endpoint'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php if ((string) ($fpRow['sample_sql'] ?? '') !== ''): ?>
                                            <details style="margin-top:4px;">
                                                <summary style="cursor:pointer;font-size:10.5px;color:var(--text-3);">Ornek SQL</summary>
                                                <pre style="font-family:'JetBrains Mono',monospace;font-size:10.5px;background:#fafafa;padding:8px 10px;border:1px solid var(--border);border-radius:8px;margin-top:6px;white-space:pre-wrap;word-break:break-word;"><?php echo htmlspecialchars((string) $fpRow['sample_sql'], ENT_QUOTES, 'UTF-8'); ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
<?php elseif ($activeTab === 'cihaz'): ?>
    <?php if ($czHata !== null): ?>
        <div class="cihaz-err-bar"><i class="fa-solid fa-circle-exclamation"></i> Cihaz verileri yüklenirken hata oluştu: <?php echo htmlspecialchars($czHata, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <!-- Özet kartları -->
    <div class="cihaz-summary">
        <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-emerald"><i class="fa-solid fa-circle-user"></i></span><div><div class="cihaz-sum-num"><?php echo $czOnlineSayisi; ?></div><div class="cihaz-sum-lbl">Şu an çevrimiçi</div></div></div>
        <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-sky"><i class="fa-solid fa-users"></i></span><div><div class="cihaz-sum-num"><?php echo count($czKullanicilar); ?></div><div class="cihaz-sum-lbl">Aktif kullanıcı (90g)</div></div></div>
        <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-amber"><i class="fa-solid fa-circle-plus"></i></span><div><div class="cihaz-sum-num"><?php echo $czYeniCihazSayisi; ?></div><div class="cihaz-sum-lbl">Yeni cihaz (<?php echo CIHAZ_YENI_GUN; ?>g)</div></div></div>
        <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-red"><i class="fa-solid fa-robot"></i></span><div><div class="cihaz-sum-num"><?php echo $czAnormalSayisi; ?></div><div class="cihaz-sum-lbl">Anormal istemci</div></div></div>
    </div>

    <div class="cihaz-section-title"><i class="fa-solid fa-list-ul"></i> Kullanıcılar ve Cihazları</div>

    <div class="cihaz-panel">
        <?php if ($czKullanicilar === []): ?>
            <div class="cihaz-empty">Son <?php echo CIHAZ_GECMIS_GUN; ?> günde giriş kaydı bulunamadı.</div>
        <?php else: ?>
            <?php foreach ($czKullanicilar as $ka => $u):
                $bas = strtoupper(mb_substr($ka, 0, 1));
            ?>
                <div class="cihaz-user-block <?php echo $u['online'] ? 'is-online' : ''; ?>">
                    <div class="cihaz-user-head">
                        <span class="cihaz-user-avatar"><?php echo htmlspecialchars($bas, ENT_QUOTES, 'UTF-8'); ?></span>
                        <div>
                            <div class="cihaz-user-name">
                                <span class="cihaz-dot <?php echo $u['online'] ? 'dot-on' : 'dot-off'; ?>"></span>
                                <?php echo htmlspecialchars($ka, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="cihaz-user-sub">
                                Son giriş: <?php echo htmlspecialchars(cihaz_goreli_zaman($u['son_giris']), ENT_QUOTES, 'UTF-8'); ?>
                                · <?php echo htmlspecialchars($u['son_cihaz'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if ($u['son_ip'] !== ''): ?> · <?php echo htmlspecialchars($u['son_ip'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                            </div>
                        </div>
                        <span class="cihaz-user-spacer"></span>
                        <?php if ($u['online']): ?><span class="cihaz-badge b-on"><i class="fa-solid fa-wifi"></i> Çevrimiçi</span><?php endif; ?>
                        <?php if ($u['aktif_cihaz'] > 1): ?><span class="cihaz-badge b-multi" title="Son <?php echo CIHAZ_AKTIF_DK; ?> dk içinde farklı cihazlardan giriş"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $u['aktif_cihaz']; ?> aktif cihaz</span><?php endif; ?>
                        <?php if ((int) ($yetkidurum ?? 1) === 0 && (int) $u['id'] > 0): ?>
                        <button type="button" class="cihaz-logout-btn" onclick="cihazOturumKapat(this)" data-user-id="<?php echo (int) $u['id']; ?>" data-user-name="<?php echo htmlspecialchars($ka, ENT_QUOTES, 'UTF-8'); ?>" title="Bu kullanıcının tüm açık oturumlarını kapat">
                            <i class="fa-solid fa-right-from-bracket"></i> Oturumları Kapat
                        </button>
                        <?php endif; ?>
                    </div>

                    <div class="cihaz-dev-list">
                        <?php foreach ($u['cihazlar'] as $et => $c):
                            $ipSayisi = count($c['ipler']);
                            $rowClass = $c['anormal'] ? 'is-bad' : ($c['yeni'] ? 'is-new' : '');
                            $devIco = 'fa-desktop';
                            if (str_contains($et, 'iPhone') || str_contains($et, 'Android')) { $devIco = 'fa-mobile-screen'; }
                            elseif ($c['anormal']) { $devIco = 'fa-robot'; }
                            elseif (str_contains($et, 'Mac')) { $devIco = 'fa-laptop'; }
                        ?>
                            <div class="cihaz-dev-row <?php echo $rowClass; ?>">
                                <span class="cihaz-dev-ico"><i class="fa-solid <?php echo $devIco; ?>"></i></span>
                                <div class="cihaz-dev-main">
                                    <span class="cihaz-dev-label"><?php echo htmlspecialchars($et, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php if ($c['yeni'] && !$c['anormal']): ?><span class="cihaz-tag tag-new">YENİ</span><?php endif; ?>
                                    <?php if ($c['anormal']): ?><span class="cihaz-tag tag-bad">ANORMAL</span><?php endif; ?>
                                    <div class="cihaz-dev-meta"><?php echo $c['sayi']; ?> giriş · <?php echo $ipSayisi; ?> IP · ilk: <?php echo htmlspecialchars(cihaz_goreli_zaman($c['ilk']), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="cihaz-dev-right"><?php echo htmlspecialchars(cihaz_goreli_zaman($c['son']), ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <p style="text-align:center;font-size:11px;color:var(--text-3);margin-top:18px;">
        <i class="fa-solid fa-circle-info"></i> "Cihaz", tarayıcı türünden (iPhone·Safari gibi) çıkarılır; aynı cihazın IP değişimleri tek satırda toplanır. Anlık aktivite sayfa ziyaretlerinden, cihazlar giriş kayıtlarından gelir.
    </p>
<?php elseif ($activeTab === 'ziyaret'): ?>
    <?php if ($szHata !== null): ?>
        <div class="cihaz-err-bar"><i class="fa-solid fa-circle-exclamation"></i> Sayfa ziyaret verileri yuklenirken hata: <?php echo htmlspecialchars($szHata, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($szKullanicilar === []): ?>
        <div class="cihaz-empty">Henuz sayfa ziyaret kaydi yok.</div>
    <?php else: ?>
        <!-- Kullanici secici -->
        <form method="get" class="no-print" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;">
            <input type="hidden" name="tab" value="ziyaret">
            <label style="font-size:13px;font-weight:600;color:var(--text-2);"><i class="fa-solid fa-user"></i> Kullanici:</label>
            <select name="zkul" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:14px;min-width:220px;background:#fff;">
                <?php foreach ($szKullanicilar as $id => $k): ?>
                    <option value="<?php echo (int) $id; ?>" <?php echo $szFilterKul === (int) $id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($k['ad'], ENT_QUOTES, 'UTF-8'); ?> - <?php echo number_format($k['toplam']); ?> ziyaret
                    </option>
                <?php endforeach; ?>
            </select>
            <span style="font-size:12px;color:var(--text-3);"><i class="fa-solid fa-circle-info"></i> Kullanici secince o kisinin girdigi tum sayfalar (rapor dahil) listelenir.</span>
        </form>

        <!-- Ozet kartlari -->
        <div class="cihaz-summary">
            <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-sky"><i class="fa-solid fa-file-lines"></i></span><div><div class="cihaz-sum-num"><?php echo number_format(count($szRows)); ?></div><div class="cihaz-sum-lbl">Farkli sayfa</div></div></div>
            <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-emerald"><i class="fa-solid fa-eye"></i></span><div><div class="cihaz-sum-num"><?php echo number_format($szToplamZiyaret); ?></div><div class="cihaz-sum-lbl">Toplam ziyaret</div></div></div>
            <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-amber"><i class="fa-solid fa-chart-column"></i></span><div><div class="cihaz-sum-num"><?php echo number_format(count(array_filter($szRows, static fn($r): bool => stripos((string) $r['SAYFA'], 'rapor') !== false || stripos((string) $r['SAYFA'], 'dashboard') !== false))); ?></div><div class="cihaz-sum-lbl">Rapor / analiz sayfasi</div></div></div>
        </div>

        <div class="cihaz-section-title"><i class="fa-solid fa-route"></i> Girilen Sayfalar &mdash; son ziyarete gore</div>

        <div class="cihaz-panel" style="padding:0;overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
                        <th style="text-align:left;padding:11px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-2);">Sayfa</th>
                        <th style="text-align:left;padding:11px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-2);">Baslik</th>
                        <th style="text-align:right;padding:11px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-2);">Ziyaret</th>
                        <th style="text-align:right;padding:11px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-2);">Ilk</th>
                        <th style="text-align:right;padding:11px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-2);">Son ziyaret</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($szRows as $r):
                        $szSayfa = (string) $r['SAYFA'];
                        $szIsRapor = stripos($szSayfa, 'rapor') !== false || stripos($szSayfa, 'dashboard') !== false;
                        $szIlkTs = strtotime((string) $r['ILK_ZIYARET']) ?: 0;
                        $szSonTs = strtotime((string) $r['SON_ZIYARET']) ?: 0;
                    ?>
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:10px 14px;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--text-1);word-break:break-all;">
                                <?php if ($szIsRapor): ?><span style="display:inline-block;background:var(--indigo,#4f46e5);color:#fff;font-size:9px;font-weight:700;padding:1px 6px;border-radius:5px;margin-right:6px;vertical-align:middle;letter-spacing:.3px;">RAPOR</span><?php endif; ?>
                                <?php echo htmlspecialchars($szSayfa, ENT_QUOTES, 'UTF-8'); ?>
                            </td>
                            <td style="padding:10px 14px;color:var(--text-2);"><?php echo htmlspecialchars((string) $r['SAYFA_BASLIK'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td style="padding:10px 14px;text-align:right;font-weight:700;color:var(--text-1);font-variant-numeric:tabular-nums;"><?php echo number_format((int) $r['ZIYARET_SAYISI']); ?></td>
                            <td style="padding:10px 14px;text-align:right;color:var(--text-3);font-size:12px;white-space:nowrap;"><?php echo $szIlkTs ? date('d.m.Y', $szIlkTs) : '-'; ?></td>
                            <td style="padding:10px 14px;text-align:right;color:var(--text-2);font-size:12px;white-space:nowrap;"><?php echo $szSonTs ? date('d.m.Y H:i', $szSonTs) : '-'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p style="text-align:center;font-size:11px;color:var(--text-3);margin-top:18px;">
            <i class="fa-solid fa-circle-info"></i> Veri her GET sayfa ziyaretinde guncellenir (index/giris/AJAX/API haric). Kronolojik saat-saat iz icin sunucu access.log kullanilir.
        </p>
    <?php endif; ?>
<?php elseif ($activeTab === 'canli'): ?>
    <?php if ($canliHata !== null): ?>
        <div class="cihaz-err-bar"><i class="fa-solid fa-circle-exclamation"></i> Canli akis yuklenirken hata: <?php echo htmlspecialchars($canliHata, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="cihaz-summary" style="margin-bottom:14px;">
        <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-emerald"><i class="fa-solid fa-circle-user"></i></span><div><div class="cihaz-sum-num"><?php echo $canliOnlineSayisi; ?></div><div class="cihaz-sum-lbl">Su an aktif (10 dk)</div></div></div>
        <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-sky"><i class="fa-solid fa-users"></i></span><div><div class="cihaz-sum-num"><?php echo count($canliUsers); ?></div><div class="cihaz-sum-lbl">Yakin donem kullanici</div></div></div>
        <div class="cihaz-sum-card"><span class="cihaz-sum-ico t-amber"><i class="fa-solid fa-clock"></i></span><div><div class="cihaz-sum-num" style="font-size:16px;"><?php echo date('H:i:s'); ?></div><div class="cihaz-sum-lbl">Son guncelleme</div></div></div>
    </div>

    <div class="no-print" style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
        <label style="display:inline-flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:var(--text-2);cursor:pointer;">
            <input type="checkbox" id="canliOto" checked style="width:16px;height:16px;cursor:pointer;"> <i class="fa-solid fa-satellite-dish" style="color:var(--emerald);"></i> Otomatik yenile (20 sn)
        </label>
        <a href="loglar.php?tab=canli<?php echo $grupQS; ?>" class="tab-link" style="padding:6px 12px;font-size:12px;flex:0 0 auto;"><i class="fa-solid fa-rotate"></i> Simdi yenile</a>
        <span style="font-size:12px;color:var(--text-3);"><i class="fa-solid fa-circle-info"></i> Kaynak: sunucu erisim gunlugu (access.log). Ardisik ayni sayfa tek satirda toplanir.</span>
    </div>

    <?php if ($canliUsers === []): ?>
        <div class="cihaz-empty">Yakin donemde kullanici aktivitesi bulunamadi.</div>
    <?php else: ?>
        <div class="cihaz-panel">
            <?php foreach ($canliUsers as $kadi => $u):
                $uSonTs = strtotime((string) $u['son_ts']) ?: 0;
                $uOnline = $uSonTs > $canliNow - 600;
                $simdi = canli_sayfa_adi((string) $u['son_path']);
                $bas = strtoupper(mb_substr((string) $kadi, 0, 1, 'UTF-8'));
                $son20 = array_slice(array_reverse($u['entries']), 0, 20);
            ?>
                <div class="cihaz-user-block <?php echo $uOnline ? 'is-online' : ''; ?>">
                    <div class="cihaz-user-head">
                        <span class="cihaz-user-avatar"><?php echo htmlspecialchars($bas, ENT_QUOTES, 'UTF-8'); ?></span>
                        <div>
                            <div class="cihaz-user-name">
                                <span class="cihaz-dot <?php echo $uOnline ? 'dot-on' : 'dot-off'; ?>"></span>
                                <?php echo htmlspecialchars((string) $kadi, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div class="cihaz-user-sub">
                                Su an: <strong style="color:var(--text-1);"><?php echo $simdi['rapor'] ? '<span class="canli-badge-rapor">RAPOR</span> ' : ''; ?><?php echo htmlspecialchars($simdi['ad'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                &middot; <?php echo htmlspecialchars(canli_goreli($uSonTs, $canliNow), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </div>
                        <span class="cihaz-user-spacer"></span>
                        <?php if ($uOnline): ?><span class="cihaz-badge b-on"><i class="fa-solid fa-wifi"></i> Cevrimici</span><?php endif; ?>
                    </div>

                    <div class="canli-timeline">
                        <?php foreach ($son20 as $e):
                            $ea = canli_sayfa_adi((string) $e['path']);
                            $ets = strtotime((string) $e['ts']) ?: 0;
                            $bugun = $ets && date('Y-m-d', $ets) === date('Y-m-d', $canliNow);
                        ?>
                            <div class="canli-row">
                                <span class="canli-time"><?php echo $ets ? date($bugun ? 'H:i' : 'd.m H:i', $ets) : '--:--'; ?></span>
                                <span class="canli-node"></span>
                                <span class="canli-page">
                                    <?php if ($ea['rapor']): ?><span class="canli-badge-rapor">RAPOR</span><?php endif; ?>
                                    <?php echo htmlspecialchars($ea['ad'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if ((int) $e['count'] > 1): ?><span class="canli-x">&times;<?php echo (int) $e['count']; ?></span><?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <style>
        .canli-timeline { padding: 6px 0 4px 10px; }
        .canli-row { display:flex; align-items:center; gap:12px; padding:5px 8px; border-left:2px solid var(--border); margin-left:8px; }
        .canli-row:hover { background:#f8fafc; }
        .canli-time { font-family:'JetBrains Mono',monospace; font-size:11.5px; color:var(--text-3); min-width:52px; flex-shrink:0; }
        .canli-node { width:7px; height:7px; border-radius:50%; background:var(--sky,#0284c7); flex-shrink:0; margin-left:-14px; box-shadow:0 0 0 3px #fff; }
        .canli-page { font-size:13px; color:var(--text-1); }
        .canli-badge-rapor { display:inline-block; background:var(--indigo,#4f46e5); color:#fff; font-size:8.5px; font-weight:700; padding:1px 5px; border-radius:4px; margin-right:5px; vertical-align:middle; letter-spacing:.3px; }
        .canli-x { font-size:11px; color:var(--text-3); margin-left:6px; }
    </style>
    <script>
    (function(){
        var cb = document.getElementById('canliOto');
        if (!cb) return;
        try { var s = localStorage.getItem('canliOto'); if (s !== null) { cb.checked = (s === '1'); } } catch (e) {}
        cb.addEventListener('change', function(){ try { localStorage.setItem('canliOto', cb.checked ? '1' : '0'); } catch (e) {} });
        if (cb.checked) { setTimeout(function(){ if (cb.checked) { location.reload(); } }, 20000); }
    })();
    </script>
<?php endif; ?>

</main>

<script>
const loglarCsrfToken = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

// Cihaz sekmesi: bir kullanicinin tum acik oturumlarini kapat (force-logout)
function cihazOturumKapat(btn) {
    var uid = btn.getAttribute('data-user-id');
    var uname = btn.getAttribute('data-user-name') || 'kullanici';
    if (!uid) return;
    if (!confirm(uname + ' kullanicisinin TUM acik oturumlari kapatilsin mi?\nKullanici bir sonraki islemde cikis yapar (tekrar giris yapabilir).')) return;
    btn.disabled = true;
    var fd = new FormData();
    fd.append('ajax_action', 'oturum_kapat');
    fd.append('csrf_token', loglarCsrfToken);
    fd.append('kullanici_id', uid);
    fetch('loglar.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            alert(j.message || (j.success ? 'Oturumlar kapatildi' : 'Islem basarisiz'));
            if (j.success) { btn.innerHTML = '<i class="fa-solid fa-check"></i> Kapatildi'; }
            else { btn.disabled = false; }
        })
        .catch(function () { alert('Baglanti hatasi'); btn.disabled = false; });
}

function toggleGrup(ficheno) {
    const grup = document.getElementById('grup-' + ficheno);
    const head = document.getElementById('head-' + ficheno);
    if (!grup) return;
    const isOpen = grup.classList.toggle('open');
    if (head) head.classList.toggle('open', isOpen);
}

// Tümünü seç/kaldır
function toggleAllSupheli() {
    const selectAll = document.getElementById('selectAllSupheli');
    const checkboxes = document.querySelectorAll('.supheli-checkbox');
    checkboxes.forEach(cb => cb.checked = selectAll.checked);
    updateSeciliSayisi();
}

// Secili sayisini guncelle
function updateSeciliSayisi() {
    const checkboxes = document.querySelectorAll('.supheli-checkbox:checked');
    const span = document.getElementById('seciliSayisi');
    if (span) {
        if (checkboxes.length > 0) {
            span.textContent = `(${checkboxes.length} secili)`;
            span.style.color = 'var(--emerald)';
            span.style.fontWeight = '700';
        } else {
            span.textContent = '';
            span.style.color = '';
            span.style.fontWeight = '';
        }
    }

    const allCheckboxes = document.querySelectorAll('.supheli-checkbox');
    const selectAll = document.getElementById('selectAllSupheli');
    if (selectAll) {
        selectAll.checked = allCheckboxes.length > 0 && checkboxes.length === allCheckboxes.length;
    }
}

// Seçilenleri toplu onayla
async function onaylaSecililer() {
    const checkboxes = document.querySelectorAll('.supheli-checkbox:checked');

    if (checkboxes.length === 0) {
        alert('Lütfen en az bir IP seçin.');
        return;
    }

    if (!confirm(`${checkboxes.length} adet IP adresini güvenli olarak işaretlemek istiyor musunuz?\n\nBu IP'ler artık şüpheli olarak gösterilmeyecek.`)) {
        return;
    }

    let basarili = 0;
    let hatali = 0;

    for (const cb of checkboxes) {
        const kullaniciId = cb.dataset.kullaniciId;
        const ipAdresi = cb.dataset.ipAdresi;

        try {
            const response = await fetch('loglar.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'ajax_action': 'onayla_ip',
                    'kullanici_id': kullaniciId,
                    'ip_adresi': ipAdresi,
                    'aciklama': 'Toplu onay ile eklendi',
                    'csrf_token': loglarCsrfToken
                })
            });
            const data = await response.json();
            if (data.success) {
                basarili++;
            } else {
                hatali++;
            }
        } catch (error) {
            hatali++;
        }
    }

    alert(`İşlem tamamlandı!\n\nBaşarılı: ${basarili}\nHatalı: ${hatali}`);
    location.reload();
}
</script>

</body>
</html>

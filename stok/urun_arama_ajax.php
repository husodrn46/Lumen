<?php
declare(strict_types=1);

// ---------------------- JSON FIREWALL ----------------------
if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}
ini_set('display_errors', '0');
ini_set('html_errors', '0');
if (!defined('AJAX')) {
    define('AJAX', true);
}
if (!defined('JSON_UNESCAPED_UNICODE')) {
    define('JSON_UNESCAPED_UNICODE', 0);
}

/**
 * Her koşulda geçerli JSON döndür.
 * SQL/encoding hatalarında parsererror yerine kontrollü payload üretir.
 */
function jsonOut(array $payload, int $statusCode = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if (!headers_sent()) {
        // IIS customErrors bazı ortamlarda 4xx/5xx gövdelerini HTML ile değiştiriyor.
        // AJAX tarafında parsererror almamak için daima 200 + JSON dön.
        http_response_code(200);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    if ($statusCode !== 200) {
        $payload['_status'] = $statusCode;
    }

    $json = json_encode(
        $payload,
        (defined('JSON_UNESCAPED_UNICODE') ? JSON_UNESCAPED_UNICODE : 0)
        | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0)
    );

    if ($json === false) {
        $json = '{"error":"JSON_ENCODE_FAILED","html":"","total":0,"showing":0,"has_more":false,"page":1}';
    }

    echo $json;
    exit;
}

// Include'ların saçacağı her çıktıyı yut
ob_start();
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../log_ip.php");
require_once __DIR__ . '/../kontrol.php';
$__noise = ob_get_clean();
// İstersen logla: if ($__noise !== '') error_log("NOISE urun_arama_ajax.php: ".substr($__noise,0,500));

// YETKI KONTROLÜ: M7 (Stok Ara) yetkisi kontrolü
if (m_p_yetki($terminalkullanici, 'M7') != 1 && (int)$yetkidurum !== 0) {
    jsonOut(['error' => 'Yetkiniz yok', 'html'=>'', 'total'=>0, 'showing'=>0, 'has_more'=>false], 403);
}

// ---------------------- INPUT ----------------------
$barkod = isset($_POST['barkod']) ? trim((string)$_POST['barkod']) : '';
$page   = isset($_POST['page'])   ? max(1, (int)$_POST['page']) : 1;

if (function_exists('mb_substr')) {
    if (mb_strlen($barkod, 'UTF-8') > 100) {
        $barkod = mb_substr($barkod, 0, 100, 'UTF-8');
    }
} elseif (strlen($barkod) > 100) {
    $barkod = substr($barkod, 0, 100);
}

$len = function_exists('mb_strlen') ? mb_strlen($barkod, 'UTF-8') : strlen($barkod);
if ($barkod === '' || $len < 3) {
    jsonOut(['html'=>'', 'total'=>0, 'showing'=>0, 'has_more'=>false, 'page'=>$page]);
}

// ---------------------- HELPERS (PHP 5 uyumlu) ----------------------
$fmtqty = function($n): string {
    // GÖSTERİM formatı (TR): tam sayıysa binlikli ("2.016"), kusuratlıysa 2 hane ("56,50").
    // kusuratadet() BİLEREK kullanılmıyor: o makine formatı üretir ("2016.00" — input value için),
    // listede "2016.00 ADET" gibi TR-dışı görünüyordu.
    $n = (float)$n;
    if (abs($n) < 1e-7) {
        $n = 0.0;
    }
    $dec = (fmod($n, 1.0) == 0.0) ? 0 : 2;
    return number_format($n, $dec, ',', '.');
};
$fmtint = fn($n): string => number_format((int)$n, 0, ',', '.');
$escapeHtml = fn($v): string => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
$escapeLikePattern = static function (string $value): string {
    // SQL Server LIKE kaçış: '!' kullanılır (ESCAPE '!').
    // NEDEN backslash DEĞİL: "ESCAPE '\'" içindeki \' dizilimi PDO'nun named-param
    // ayrıştırıcısını şaşırtıyor (tırnak kaçışı sanıp string'i kapatmıyor) → sonraki
    // placeholder'lar görünmez oluyor → HY093 "parameter was not defined". Kanıtlandı:
    // aynı sorgu ESCAPE '\' ile HY093, ESCAPE '!' ile sorunsuz.
    return str_replace(['!', '%', '_', '[', ']'], ['!!', '!%', '!_', '![', '!]'], $value);
};

// ---------------------- SAYFALAMA ----------------------
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// ---------------------- Barkod tam eşleşmesi? ----------------------
try {
    $stmt_barbak = app_db_prepare_execute(
        $dbh,
        "
        SELECT ITEMREF
        FROM {$firma}UNITBARCODE
        WHERE BARCODE = :b
        ",
        [':b' => $barkod],
        [
            'page' => 'urun_arama_ajax.php',
            'action' => 'barcode_lookup'
        ]
    );
    $barbak = $stmt_barbak->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('urun_arama_ajax.php barkod kontrol hatası: ' . $e->getMessage());
    jsonOut(['error' => 'Sorgu hatası', 'html'=>'', 'total'=>0, 'showing'=>0, 'has_more'=>false, 'page'=>$page], 500);
}
$searchedByBarcode = (bool) $barbak;

// ---------------------- Sorgu gövdesi ----------------------
$baseQuery = "
    SELECT
        URUN.LOGICALREF AS URUN_ID,
        URUN.CODE       AS URUN_KODU,
        URUN.NAME       AS URUN_ADI,
        BIRIM.CODE      AS BIRIM,
        ISNULL(STOK.MIKTAR, 0) AS MIKTAR,
        ISNULL(BARKOD.BARCODE, '') AS BARKOD,
        ISNULL(KOLI.KOLI_ICI, 1) AS KOLI_ICI,
        COUNT(*) OVER() AS TOTAL_ROWS
    FROM {$firma}ITEMS URUN WITH(NOLOCK)
    LEFT JOIN {$firma}UNITSETL BIRIM WITH(NOLOCK)
           ON URUN.UNITSETREF = BIRIM.UNITSETREF
          AND BIRIM.MAINUNIT = 1
    LEFT JOIN (
        SELECT STOCKREF, SUM(ONHAND) AS MIKTAR
        FROM {$firmadonemx}STINVTOT WITH(NOLOCK)
        WHERE INVENNO = -1
        GROUP BY STOCKREF
    ) AS STOK ON STOK.STOCKREF = URUN.LOGICALREF
    LEFT JOIN (
        SELECT ITEMREF, BARCODE,
               ROW_NUMBER() OVER (PARTITION BY ITEMREF ORDER BY LOGICALREF DESC) AS RN
        FROM {$firma}UNITBARCODE WITH(NOLOCK)
    ) AS BARKOD ON BARKOD.ITEMREF = URUN.LOGICALREF AND BARKOD.RN = 1
    LEFT JOIN (
        -- İş kuralına göre uyarlayabilirsin; şimdilik en büyük koliyi alır
        SELECT ITEMREF, MAX(CONVFACT2) AS KOLI_ICI
        FROM {$firma}ITMUNITA WITH(NOLOCK)
        GROUP BY ITEMREF
    ) AS KOLI ON KOLI.ITEMREF = URUN.LOGICALREF
    WHERE URUN.CARDTYPE <> 22
      AND URUN.ACTIVE = 0
";

$params = [];
$fallbackQuery = null;
$fallbackParams = [];
if ($barbak) {
    // Tek ürün
    $finalQuery = $baseQuery . "
        AND URUN.LOGICALREF = :stokid
        ORDER BY URUN.CODE
        OFFSET 0 ROWS FETCH NEXT 1 ROW ONLY
    ";
    $params[':stokid'] = (int)$barbak['ITEMREF'];
} else {
    // İsim/kod/barkod araması: kolon üzerinde REPLACE zinciri yerine COLLATE + LIKE kullan.
    $qNormalized = function_exists('turkce') ? turkce($barkod) : $barkod;
    $qRawEscaped = $escapeLikePattern($barkod);
    $qNormEscaped = $escapeLikePattern($qNormalized);

    // ESCAPE '!' — backslash değil! (HY093 kök nedeni: "ESCAPE '\'" içindeki \'
    // PDO param ayrıştırıcısını bozuyordu; escapeLikePattern açıklamasına bak.)
    $finalQuery = $baseQuery . "
        AND (
            URUN.CODE LIKE :qcp ESCAPE '!'
            OR URUN.CODE LIKE :qcc ESCAPE '!'
            OR BARKOD.BARCODE LIKE :qbp ESCAPE '!'
            OR BARKOD.BARCODE LIKE :qbc ESCAPE '!'
            OR URUN.NAME LIKE :qnr ESCAPE '!'
            OR URUN.NAME LIKE :qnn ESCAPE '!'
        )
        ORDER BY URUN.NAME
        OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY
    ";
    $params[':qcp'] = $qRawEscaped . '%';
    $params[':qcc'] = '%' . $qRawEscaped . '%';
    $params[':qbp'] = $qRawEscaped . '%';
    $params[':qbc'] = '%' . $qRawEscaped . '%';
    $params[':qnr'] = '%' . $qRawEscaped . '%';
    $params[':qnn'] = '%' . $qNormEscaped . '%';

    // Uyumluluk fallback (eski arama yöntemi):
    // Bazı SQL Server sürüm/kolasyon kombinasyonlarında üstteki LIKE/ESCAPE yapısı hata verebilir.
    $fallbackQuery = $baseQuery . "
        AND (
            REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
              URUN.NAME, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
              LIKE :q1
            OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
              URUN.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c')
              LIKE :q2
            OR BARKOD.BARCODE LIKE :q3
        )
        ORDER BY URUN.NAME
        OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY
    ";
    $searchTerm = '%' . $qNormalized . '%';
    $fallbackParams[':q1'] = $searchTerm;
    $fallbackParams[':q2'] = $searchTerm;
    $fallbackParams[':q3'] = '%' . $qRawEscaped . '%';
}

// ---------------------- Çalıştır ----------------------
$rows = [];
try {
    $stmt = app_db_prepare_execute($dbh, $finalQuery, $params, [
        'page' => 'urun_arama_ajax.php',
        'action' => 'search_main',
        'searched_by_barcode' => $searchedByBarcode,
        'page_no' => $page
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $queryError) {
    // Terim de loglanır — HY093 gibi nadir hataların kök nedeni tekrarında görülebilsin.
    error_log('urun_arama_ajax.php ana sorgu hatası: ' . $queryError->getMessage()
        . ' | terim=[' . mb_substr($barkod, 0, 60) . '] param=' . implode(',', array_keys($params)));

    if ($fallbackQuery !== null) {
        try {
            $stmt = app_db_prepare_execute($dbh, $fallbackQuery, $fallbackParams, [
                'page' => 'urun_arama_ajax.php',
                'action' => 'search_fallback',
                'searched_by_barcode' => $searchedByBarcode,
                'page_no' => $page
            ]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $fallbackError) {
            error_log('urun_arama_ajax.php fallback sorgu hatası: ' . $fallbackError->getMessage());
            error_log('urun_arama_ajax.php fallback SQL code: ' . (int)(($fallbackError instanceof PDOException ? ($fallbackError->errorInfo[1] ?? 0) : 0)));
            jsonOut([
                'error' => 'Sorgu hatası',
                'html' => '',
                'total' => 0,
                'showing' => 0,
                'has_more' => false,
                'page' => $page
            ], 500);
        }
    } else {
        error_log('urun_arama_ajax.php SQL code: ' . (int)(($queryError instanceof PDOException ? ($queryError->errorInfo[1] ?? 0) : 0)));
        jsonOut([
            'error' => 'Sorgu hatası',
            'html' => '',
            'total' => 0,
            'showing' => 0,
            'has_more' => false,
            'page' => $page
        ], 500);
    }
}

if (!$rows) {
    jsonOut(['html'=>'', 'total'=>0, 'showing'=>0, 'has_more'=>false, 'page'=>$page]);
}

// ---------------------- Toplam / has_more ----------------------
$total   = isset($rows[0]['TOTAL_ROWS']) ? (int)$rows[0]['TOTAL_ROWS'] : 0;
$showing = $searchedByBarcode ? count($rows) : min($total, $offset + count($rows));
$hasMore = (!$searchedByBarcode && ($offset + count($rows) < $total));

// ---------------------- <li> HTML'ini string olarak üret ----------------------
$html = '';
foreach ($rows as $r) {
    $kod     = $r['URUN_KODU'] ?? '';
    $ad      = $r['URUN_ADI'] ?? '';
    $brk     = $r['BARKOD'] ?? '';
    $brm     = $r['BIRIM'] ?? '';
    $miktar  = isset($r['MIKTAR'])    ? (float)$r['MIKTAR'] : 0.0;
    $koliIci = isset($r['KOLI_ICI'])  ? (float)$r['KOLI_ICI'] : 1.0;

    if ($brk === '' || $brk === null) {
        $brk = '-';
    }
    if ($searchedByBarcode && $brk !== $barkod) {
        $brk = $barkod;
    }

    $stokKoli = ($koliIci > 0.0) ? floor($miktar / $koliIci) : null;

    $html .= '<li>';
    $html .=   '<div class="list-row">';
    $html .=     '<div class="kod">'.$escapeHtml($kod).'</div>';
    $html .=     '<div class="ad" title="'.$escapeHtml($ad).'">'.$escapeHtml($ad).'</div>';
    $html .=     '<div class="bk">'.$escapeHtml($brk).'</div>';
    $html .=     '<div class="stk">';
    // ST1 (Miktar Görme) yetkisi kontrolü
    if (m_p_yetki($terminalkullanici, 'ST1') == 1 || (int)$yetkidurum === 0) {
        $html .=         $escapeHtml($fmtqty($miktar)).' '.$escapeHtml($brm);
        if ($stokKoli !== null) {
            $html .=     ' <span style="color:#9ca3af;font-weight:400;"> &middot; </span>'.$escapeHtml($fmtint($stokKoli)).' Koli';
        }
    } else {
        $html .=         '<span style="color:#9ca3af;font-weight:500;"><i class="fa fa-lock" style="font-size:10px;"></i> ***</span>';
    }
    $html .=     '</div>';
    $html .=   '</div>';
    $html .= '</li>';
}

// ---------------------- TEMİZ ÇIKIŞ ----------------------
jsonOut(['html' => $html, 'total' => $total, 'showing' => $showing, 'has_more' => $hasMore, 'page' => $page]);

<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");

// YETKI: M15 (Stoklar) — index.php ve uretim_miktar_onar.php ile ayni guard.
// (Eksikti: fise yazan asil sayfa yalniz login istiyordu.)
if (m_p_yetki($terminalkullanici, 'M15') != 1) {
    if (isset($_GET['ajax'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'Bu işlem için yetkiniz yok.']);
        exit;
    }
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}
// ODBC 11 uyumluluğu için hata ayıklama (exception + emulated prepares) ile uyum
try {
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
} catch (Exception $e) {
}
if (isset($_GET['ajax'])) {
    if (function_exists('ob_get_level')) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
    if (function_exists('ini_set')) {
        ini_set('display_errors', '0');
    }
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['ajax'];
    if ($action === 'item') {
        $value = isset($_GET['value']) ? trim((string) $_GET['value']) : '';
        if ($value === '') {
            echo json_encode(['ok' => false, 'message' => 'Barkod veya stok kodu gerekli.']);
            exit;
        }
        try {
            $item = fetch_item_by_value($dbh, $firma, $value);

            if ($item) {
                echo json_encode(['ok' => true, 'item' => format_item_for_response($item)]);
            } else {
                echo json_encode(['ok' => false, 'message' => 'Ürün bulunamadı.']);
            }
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    if ($action === 'search') {
        $term = isset($_GET['term']) ? trim((string) $_GET['term']) : '';

        if ($term === '') {
            echo json_encode(['ok' => false, 'items' => []]);
            exit;
        }

        try {
            $items = search_items($dbh, $firma, $term, 10);
            $payload = array_map('format_item_for_response', $items);

            echo json_encode(['ok' => true, 'items' => $payload]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'message' => $e->getMessage(), 'items' => []]);
        }

        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Geçersiz işlem.']);
    exit;
}
// Basit decimal string helper (ODBC için güvenli)
function decstr(mixed $v, int $scale = 6): string
{
    if ($v === "" || $v === null) {
        return number_format(0, $scale, '.', '');
    }
    if (is_string($v)) {
        $v = str_replace(',', '.', $v);
    }
    return number_format((float) $v, $scale, '.', '');
}

function uretim_giris_hata_logla(Throwable $e, string $scope, array $context = [], ?PDO $dbh = null): void
{
    if ($e instanceof PDOException && !empty($e->errorInfo)) {
        $context['pdo_exception_error_info'] = $e->errorInfo;
    }

    if ($dbh !== null) {
        try {
            $context['pdo_error_info'] = $dbh->errorInfo();
        } catch (Throwable) {
            $context['pdo_error_info'] = 'PDO errorInfo okunamadi';
        }
    }

    $context['exception_class'] = get_class($e);
    $context['message'] = $e->getMessage();
    $context['file'] = $e->getFile();
    $context['line'] = $e->getLine();
    $context['trace'] = $e->getTraceAsString();

    $encodedContext = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encodedContext === false) {
        $encodedContext = json_encode(['json_error' => json_last_error_msg()]);
    }

    error_log('[uretim_giris] ' . $scope . ' hata: ' . $e->getMessage() . ' context=' . (string) $encodedContext);

    if (function_exists('app_log_exception')) {
        app_log_exception($e, 'stok/uretim_giris ' . $scope, $context);
    } elseif (function_exists('app_log_write')) {
        app_log_write('app-error', [
            'scope' => 'stok/uretim_giris ' . $scope,
            'message' => $e->getMessage(),
            'context' => $context,
        ]);
    } elseif (function_exists('logHataRapor')) {
        logHataRapor('stok/uretim_giris ' . $scope, $e);
    }
}

function item_select_columns(string $firma): string
{
    return "
        S.LOGICALREF,
        S.CODE,
        S.NAME,
        -- BARKOD ARAMASI DEAKTİF (yorum satırı) --
        -- COALESCE(B.BARCODE, '') AS BARCODE,
        '' AS BARCODE,
        COALESCE(PK.CONVFACT2, 1) AS KOLI_CF2,
        COALESCE(PK.CODE, '') AS KOLI_BIRIM,
        ISNULL(L1.CODE, '') AS BIRIM,
        ISNULL(L1.LOGICALREF, 0) AS UOMREF,
        ISNULL(L1.CONVFACT1, 1) AS CF1,
        ISNULL(L1.CONVFACT2, 1) AS CF2,
        ISNULL(S.UNITSETREF, 0) AS UNITSETREF
        FROM {$firma}ITEMS S
        -- BARKOD ARAMASI DEAKTİF (yorum satırı) --
        -- LEFT JOIN {$firma}UNITBARCODE B ON B.ITEMREF = S.LOGICALREF
        LEFT JOIN {$firma}UNITSETL L1 ON L1.UNITSETREF = S.UNITSETREF AND L1.LINENR = 1
        OUTER APPLY (
            SELECT TOP 1 IA.CONVFACT2, U.CODE
            FROM {$firma}ITMUNITA IA
            LEFT JOIN {$firma}UNITSETL U ON IA.UNITLINEREF = U.LOGICALREF
            WHERE IA.ITEMREF = S.LOGICALREF AND IA.CONVFACT2 IS NOT NULL AND IA.CONVFACT2 > 0
            ORDER BY IA.LINENR DESC
        ) AS PK
    ";
}
function normalize_item_row(mixed $row): ?array
{
    if (!$row) {
        return null;
    }
    $factor = isset($row['KOLI_CF2']) ? (float) $row['KOLI_CF2'] : 1.0;
    if ($factor <= 0) {
        $factor = 1.0;
    }
    return [
        'logicalref' => (int) $row['LOGICALREF'],
        'code' => $row['CODE'] ?? '',
        'name' => $row['NAME'] ?? '',
        'barcode' => $row['BARCODE'] ?? '',
        'koli_cf2' => $factor,
        'koli_birim' => $row['KOLI_BIRIM'] ?? '',
        'birim' => $row['BIRIM'] ?? '',
        'uomref' => isset($row['UOMREF']) ? (int) $row['UOMREF'] : 0,
        'cf1' => isset($row['CF1']) ? (float) $row['CF1'] : 1.0,
        'cf2' => isset($row['CF2']) ? (float) $row['CF2'] : 1.0,
        'unitsetref' => isset($row['UNITSETREF']) ? (int) $row['UNITSETREF'] : 0,
    ];
}

function format_item_for_response(array $item): array
{
    return [
        'logicalref' => $item['logicalref'],
        'code' => trcevir($item['code']),
        'name' => trcevir($item['name']),
        'barcode' => trcevir($item['barcode']),
        'koli_adet' => $item['koli_cf2'],
        'birim' => trcevir($item['birim']),
        'koli_birim' => trcevir($item['koli_birim']),
    ];
}

function fetch_item_by_value(PDO $dbh, string $firma, mixed $value): ?array
{
    if ($value === null) {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    // BARKOD ARAMASI DEAKTİF - Sadece CODE ile arama
    $urunKosulu = urun_kodu_kosulu('S');
    $sql = 'SELECT TOP 1 ' . item_select_columns($firma) . "
        WHERE S.ACTIVE = 0 AND {$urunKosulu} AND S.CODE = ?
        ORDER BY S.LOGICALREF
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([$value]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $likeAny = '%' . $value . '%';
        // BARKOD ARAMASI DEAKTİF - Sadece CODE ve NAME ile arama
        $conditions = [
            'S.CODE LIKE ?',
            'S.NAME LIKE ?'
            // 'B.BARCODE LIKE ?'
        ];
        $params = [$likeAny, $likeAny];
        $normalized = preg_replace('/[^0-9A-Za-z]/', '', $value);
        if ($normalized !== '' && $normalized !== $value) {
            $conditions[] = "REPLACE(REPLACE(S.CODE, '-', ''), ' ', '') LIKE ?";
            $params[] = '%' . $normalized . '%';
        }
        $sqlLike = 'SELECT TOP 1 ' . item_select_columns($firma) . '
            WHERE S.ACTIVE = 0 AND S.CODE LIKE ? AND (' . implode(' OR ', $conditions) . ')
            ORDER BY S.CODE
        ';
        $stmt = $dbh->prepare($sqlLike);
        $stmt->execute(array_merge(['AKL%'], $params));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return normalize_item_row($row);
}
function fetch_item_by_ref(PDO $dbh, string $firma, int|string $logicalref): ?array
{
    $logicalref = (int) $logicalref;

    if ($logicalref <= 0) {
        return null;
    }

    $sql = 'SELECT TOP 1 ' . item_select_columns($firma) . "
        WHERE S.ACTIVE = 0 AND S.LOGICALREF = ?
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->execute([$logicalref]);

    return normalize_item_row($stmt->fetch(PDO::FETCH_ASSOC));
}

/**
 * @return mixed[]
 */
function search_items(PDO $dbh, string $firma, string $term, int $limit = 10): array
{
    $term = trim((string) $term);

    if ($term === '') {
        return [];
    }

    $limit = (int) $limit;

    if ($limit <= 0) {
        $limit = 10;
    }

    if ($limit > 50) {
        $limit = 50;
    }

    $codePrefix = 'AKL%';
    $likeAny = '%' . $term . '%';

    // BARKOD ARAMASI DEAKTİF - Sadece CODE ve NAME ile arama
    $searchConditions = [
        'S.CODE LIKE ?',
        'S.NAME LIKE ?'
        // 'B.BARCODE LIKE ?'
    ];

    $searchParams = [$likeAny, $likeAny];

    $normalized = preg_replace('/[^0-9A-Za-z]/', '', $term);

    if ($normalized !== '' && $normalized !== $term) {
        $searchConditions[] = "REPLACE(REPLACE(S.CODE, '-', ''), ' ', '') LIKE ?";
        $searchParams[] = '%' . $normalized . '%';
    }

    $sql = 'SELECT TOP ' . $limit . ' ' . item_select_columns($firma) . '
        WHERE S.ACTIVE = 0 AND S.CODE LIKE ? AND (' . implode(' OR ', $searchConditions) . ')
        ORDER BY S.CODE
    ';

    $params = array_merge([$codePrefix], $searchParams);

    $stmt = $dbh->prepare($sql);
    $stmt->execute($params);

    $items = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $normalized = normalize_item_row($row);

        if ($normalized) {
            $items[] = $normalized;
        }
    }

    return $items;
}

function resolve_item_from_inputs(PDO $dbh, string $firma, mixed $barcode, mixed $code, mixed $fallbackRef): ?array
{
    $item = fetch_item_by_value($dbh, $firma, $barcode);

    if ($item) {
        return $item;
    }

    $item = fetch_item_by_value($dbh, $firma, $code);

    if ($item) {
        return $item;
    }

    return fetch_item_by_ref($dbh, $firma, $fallbackRef);
}



function copy_logo_defaults(PDO $dbh, string $tableName, int|string $templateId, int|string $targetId): void

{

    $templateId = (int) $templateId;

    $targetId = (int) $targetId;

    if ($templateId <= 0 || $targetId <= 0) {

        return;
    }

    $sql = <<<SQL
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
DECLARE @table SYSNAME = N'{$tableName}';
DECLARE @template INT = {$templateId};
DECLARE @target INT = {$targetId};
DECLARE @sql NVARCHAR(MAX) = N'';
SELECT @sql = @sql + N'

    ' + QUOTENAME(c.name) + N' = CASE WHEN n.' + QUOTENAME(c.name) + N' IS NULL THEN o.' + QUOTENAME(c.name) + N' ELSE n.' + QUOTENAME(c.name) + N' END,'

FROM sys.columns AS c

WHERE c.object_id = OBJECT_ID(@table)

  AND c.name NOT IN ('LOGICALREF', 'FICHENO');

IF LEN(@sql) > 0

BEGIN

    SET @sql = N'UPDATE n SET' + @sql;

    SET @sql = LEFT(@sql, LEN(@sql) - 1) + N' FROM ' + @table + N' AS n JOIN ' + @table + N' AS o ON o.LOGICALREF = ' + CAST(@template AS NVARCHAR(20)) + N' WHERE n.LOGICALREF = ' + CAST(@target AS NVARCHAR(20)) + N';';

    EXEC sp_executesql @sql;

END

SQL;

    $dbh->exec($sql);
}
// Fiş toplamlarını STLINE üzerinden yeniden hesapla
function recalc_fiche_totals(PDO $dbh, string $firmadonem, int|string $fis_id): void
{
    try {
        // SUM(TOTAL) sadece IOCODE=1, LINETYPE=0, CANCELLED=0
        $sum = $dbh->prepare("
            SELECT ISNULL(SUM(TOTAL),0) AS T
            FROM {$firmadonem}STLINE
            WHERE STFICHEREF = ? AND TRCODE = 13 AND IOCODE = 1 AND LINETYPE = 0 AND CANCELLED = 0
        ");
        $sum->execute([$fis_id]);
        $t = (float) $sum->fetchColumn();
        $tstr = number_format($t, 6, '.', '');
        $upd = $dbh->prepare("
            UPDATE {$firmadonem}STFICHE SET
                GROSSTOTAL = ?,
                NETTOTAL = ?,
                TOTALDISCOUNTED = ?,
                REPORTNET = ?,
                SOURCEINDEX = CASE WHEN SOURCEINDEX IS NULL THEN 0 ELSE SOURCEINDEX END,
                SOURCETYPE = CASE WHEN SOURCETYPE IS NULL THEN 0 ELSE SOURCETYPE END,
                DESTINDEX = CASE WHEN DESTINDEX IS NULL THEN 0 ELSE DESTINDEX END,
                DESTTYPE = CASE WHEN DESTTYPE IS NULL THEN 0 ELSE DESTTYPE END,
                SITEID = CASE WHEN SITEID IS NULL THEN 0 ELSE SITEID END,
                RECSTATUS = CASE WHEN RECSTATUS IS NULL THEN 1 ELSE RECSTATUS END,
                WFSTATUS = CASE WHEN WFSTATUS IS NULL THEN 0 ELSE WFSTATUS END
            WHERE LOGICALREF = ? AND TRCODE = 13
        ");
        $upd->execute([$tstr, $tstr, $tstr, $tstr, $fis_id]);
    } catch (Throwable $e) {
        uretim_giris_hata_logla($e, 'recalc_fiche_totals.update', [
            'fis_id' => (int) $fis_id,
            'firmadonem' => $firmadonem,
        ], $dbh);
        throw $e;
    }

    $templateFicheId = 0;
    try {

        $stmtTplFiche = $dbh->prepare("SELECT TOP 1 LOGICALREF
            FROM {$firmadonem}STFICHE
            WHERE TRCODE = 13 AND LOGICALREF <> ?
            ORDER BY LOGICALREF");
        $stmtTplFiche->execute([$fis_id]);
        $templateFicheId = (int) $stmtTplFiche->fetchColumn();
    } catch (Throwable $e) {
        uretim_giris_hata_logla($e, 'recalc_fiche_totals.template_select', [
            'fis_id' => (int) $fis_id,
            'firmadonem' => $firmadonem,
        ], $dbh);
    }

    if ($templateFicheId > 0) {
        try {
            copy_logo_defaults($dbh, "{$firmadonem}STFICHE", $templateFicheId, $fis_id);
        } catch (Throwable $e) {
            uretim_giris_hata_logla($e, 'recalc_fiche_totals.copy_logo_defaults', [
                'fis_id' => (int) $fis_id,
                'firmadonem' => $firmadonem,
                'template_fiche_id' => $templateFicheId,
            ], $dbh);
        }
    }
}
$fis_id = isset($_GET['fis']) ? (int) $_GET['fis'] : 0;
$depogiris = isset($_GET['depogiris']) ? (int) $_GET['depogiris'] : 0;
$depokaynak = 0;
$msg = "";
if (!$fis_id && isset($_GET['yeni'])) {
    $depogiris = isset($_GET['depogiris']) ? (int) $_GET['depogiris'] : 0;
    try {
        $dbh->beginTransaction();
        $ftime = (int) (date('G') * 65536 + date('i') * 256 + date('s'));
        $ficheno = 'URT' . date('ymdHis');
        $insFiche = $dbh->prepare("
            INSERT INTO {$firmadonem}STFICHE
            ( TRCODE, IOCODE, FICHENO, DATE_, FTIME, SITEID, RECSTATUS, WFSTATUS, SOURCEINDEX, DESTINDEX, GENEXP1 )
            VALUES ( 13, 1, ?, GETDATE(), ?, 0, 1, 0, 0, ?, '' )
        ");
        $insFiche->execute([$ficheno, $ftime, $depogiris]);
        $fis_id = (int) $dbh->lastInsertId();
        if ($fis_id <= 0) {
            throw new Exception('Otomatik fiş numarası alınamadı.');
        }
        $templateFicheId = 0;
        try {
            $stmtTplFiche = $dbh->prepare("SELECT TOP 1 LOGICALREF
                FROM {$firmadonem}STFICHE
                WHERE TRCODE = 13 AND LOGICALREF <> ?
                ORDER BY LOGICALREF");
            $stmtTplFiche->execute([$fis_id]);
            $templateFicheId = (int) $stmtTplFiche->fetchColumn();
        } catch (Exception) {
            $templateFicheId = 0;
        }
        if ($templateFicheId > 0) {
            copy_logo_defaults($dbh, "{$firmadonem}STFICHE", $templateFicheId, $fis_id);
        }
        $dbh->prepare("UPDATE {$firmadonem}STFICHE SET SITEID = 0, RECSTATUS = 1, WFSTATUS = 0 WHERE LOGICALREF = ?")->execute([$fis_id]);
        $dbh->commit();
        header("Location: uretim_giris.php?fis=" . $fis_id . "&depogiris=" . $depogiris);
        exit;
    } catch (Exception $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        $msg = "Yeni fiş oluşturulamadı: " . $e->getMessage();
    }
}
$fiche = null;
if ($fis_id > 0) {
    $st = $dbh->prepare("
        SELECT LOGICALREF, FICHENO, DATE_, SOURCEINDEX, DESTINDEX, GENEXP1
        FROM {$firmadonem}STFICHE
        WHERE LOGICALREF = ? AND TRCODE = 13
    ");
    $st->execute([$fis_id]);
    $fiche = $st->fetch(PDO::FETCH_ASSOC);
    if ($fiche) {
        if (isset($fiche['SOURCEINDEX']) && $fiche['SOURCEINDEX'] !== null) {
            $depokaynak = (int) $fiche['SOURCEINDEX'];
        }
        // URL'de depogiris=0 gelmişse başlıktaki DESTINDEX'i baz al
        if (!$depogiris && isset($fiche['DESTINDEX'])) {
            $depogiris = (int) $fiche['DESTINDEX'];
        }
    } else {
        echo json_encode(['ok' => false, 'message' => 'Ürün bulunamadı.']);
    }
}
// --- SATIR EKLE ---
if (isset($_POST['add_line']) && isset($_POST['fis'])) {
    $fis_id = (int) $_POST['fis'];
    $depogiris = (int) $_POST['depogiris'];
    $depokaynak = isset($_POST['depokaynak']) ? (int) $_POST['depokaynak'] : 0;
    $koliRaw = $_POST['koli'] ?? '0';
    $koliFloat = (float) str_replace(',', '.', (string) $koliRaw);
    $aciklama = $_POST['satir_aciklama'] ?? '';
    $barkodInput = isset($_POST['barkod']) ? trim((string) $_POST['barkod']) : '';
    $stokKodInput = isset($_POST['stok_kod']) ? trim((string) $_POST['stok_kod']) : '';
    $fallbackRef = isset($_POST['stokref']) ? (int) $_POST['stokref'] : 0;
    $stfichelnno = 0;
    $stokref = 0;
    $uomref = 0;
    $usref = 0;
    $koliFactor = 0.0;
    $amount = '';
    $newLineId = 0;
    $templateLineId = 0;

    try {
        $item = resolve_item_from_inputs($dbh, $firma, $barkodInput, $stokKodInput, $fallbackRef);

        if (!$item) {
            throw new Exception('Geçersiz stok seçimi.');
        }

        if ($koliFloat <= 0) {
            throw new Exception('Geçersiz koli miktarı.');
        }

        $dbh->beginTransaction();

        $ftime = (int) (date('G') * 65536 + date('i') * 256 + date('s'));

        $stfichelnnoStmt = $dbh->prepare("
            SELECT ISNULL(MAX(STFICHELNNO), 0) + 1
            FROM {$firmadonem}STLINE
            WHERE STFICHEREF = ? AND TRCODE = 13 AND IOCODE = 1 AND LINETYPE = 0
        ");
        $stfichelnnoStmt->execute([$fis_id]);
        $stfichelnno = (int) $stfichelnnoStmt->fetchColumn();
        if ($stfichelnno <= 0) {
            $stfichelnno = 1;
        }

        $stokref = isset($item['logicalref']) ? (int) $item['logicalref'] : 0;
        $uomref = isset($item['uomref']) ? (int) $item['uomref'] : 0;
        $usref = isset($item['unitsetref']) ? (int) $item['unitsetref'] : 0;
        if ($stokref <= 0 || $uomref <= 0 || $usref <= 0) {
            throw new Exception('Stok birim eşleşmesi bulunamadı.');
        }

        $baseCf1 = isset($item['cf1']) ? (float) $item['cf1'] : 1.0;
        $uinfo1 = decstr($baseCf1, 6);
        $baseCf2 = isset($item['cf2']) ? (float) $item['cf2'] : 1.0;
        if ($baseCf2 <= 0) {
            $baseCf2 = 1.0;
        }

        $koliFactor = isset($item['koli_cf2']) ? (float) $item['koli_cf2'] : 1.0;
        if ($koliFactor <= 0) {
            $koliFactor = 1.0;
        }
        $uinfo2 = decstr($baseCf2, 6);

        $amountValue = $koliFloat * $koliFactor;
        $amount = decstr($amountValue, 6);
        if ((float) $amount <= 0) {
            throw new Exception('Hesaplanan miktar sıfır.');
        }
        $fiyat = decstr(0, 6);
        $total = decstr(0, 6);
        $linenet = $total;
        $vatmatrah = $total;

        $ins = $dbh->prepare("
        INSERT INTO {$firmadonem}STLINE
        ( STFICHEREF, DATE_, FTIME, TRCODE, IOCODE, LINETYPE,
          STFICHELNNO, STOCKREF, AMOUNT, UOMREF, USREF, UINFO1, UINFO2,
          PRICE, TOTAL, LINENET,
          GLOBTRANS, CALCTYPE, SOURCETYPE, SOURCEINDEX, SOURCECOSTGRP, SOURCEWSREF, SOURCEPOLNREF,
          DESTTYPE, DESTINDEX, DESTCOSTGRP, DESTWSREF, DESTPOLNREF,
          FACTORYNR, CLIENTREF,
          PRCURR, PRPRICE, TRCURR, TRRATE, REPORTRATE, PLNAMOUNT,
          VATINC, VAT, VATAMNT, VATMATRAH,
          LINEEXP, SITEID, RECSTATUS, ORGLOGICREF, WFSTATUS, CANCELLED )
        VALUES
        ( ?, GETDATE(), ?, 13, 1, 0,
          ?, ?, ?, ?, ?, ?, ?,
          ?, ?, ?,
          0, 0, 0, ?, 0, 0, 0,
          0, ?, 0, 0, 0,
          0, 0,
          0, 0, 0, 0, 1, 0,
          0, 0, 0, ?,
          ?, 0, 1, 0, 0, 0 )
    ");

        $ins->execute([
            $fis_id,
            $ftime,
            $stfichelnno,
            $stokref,
            $amount,
            $uomref,
            $usref,
            $uinfo1,
            $uinfo2,
            $fiyat,
            $total,
            $linenet,
            $depokaynak,
            $depogiris,
            $vatmatrah,
            $aciklama
        ]);
        $stmtNewLine = $dbh->prepare("SELECT MAX(LOGICALREF)
            FROM {$firmadonem}STLINE
            WHERE STFICHEREF = ?");
        $stmtNewLine->execute([$fis_id]);
        $newLineId = (int) $stmtNewLine->fetchColumn();
        if ($newLineId > 0) {
            try {
                $stmtTplLine = $dbh->prepare("SELECT TOP 1 LOGICALREF
                    FROM {$firmadonem}STLINE
                    WHERE TRCODE = 13 AND LOGICALREF <> ?
                    ORDER BY LOGICALREF");
                $stmtTplLine->execute([$newLineId]);
                $templateLineId = (int) $stmtTplLine->fetchColumn();
            } catch (Exception $e) {
                uretim_giris_hata_logla($e, 'add_line.template_select', [
                    'fis_id' => $fis_id,
                    'stokref' => $stokref,
                    'stfichelnno' => $stfichelnno,
                    'amount' => $amount,
                    'uomref' => $uomref,
                    'usref' => $usref,
                    'new_line_id' => $newLineId,
                ], $dbh);
                $templateLineId = 0;
            }
            if ($templateLineId > 0) {
                try {
                    copy_logo_defaults($dbh, "{$firmadonem}STLINE", $templateLineId, $newLineId);
                } catch (Throwable $e) {
                    uretim_giris_hata_logla($e, 'add_line.sablon_kopyalanamadi', [
                        'fis_id' => $fis_id,
                        'stokref' => $stokref,
                        'stfichelnno' => $stfichelnno,
                        'amount' => $amount,
                        'uomref' => $uomref,
                        'usref' => $usref,
                        'new_line_id' => $newLineId,
                        'template_line_id' => $templateLineId,
                    ], $dbh);
                }
            }
            try {
                $yearUpd = $dbh->prepare("UPDATE {$firmadonem}STLINE SET YEAR_ = YEAR(DATE_), MONTH_ = MONTH(DATE_) WHERE LOGICALREF = ?");
                $yearUpd->execute([$newLineId]);
            } catch (Throwable $e) {
                uretim_giris_hata_logla($e, 'add_line.year_month_update', [
                    'fis_id' => $fis_id,
                    'stokref' => $stokref,
                    'stfichelnno' => $stfichelnno,
                    'amount' => $amount,
                    'uomref' => $uomref,
                    'usref' => $usref,
                    'new_line_id' => $newLineId,
                ], $dbh);
            }
        }
        // Toplamları güncelle
        recalc_fiche_totals($dbh, $firmadonem, $fis_id);
        $dbh->commit();
        header("Location: uretim_giris.php?fis=" . $fis_id . "&depogiris=" . $depogiris);
        exit;
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        uretim_giris_hata_logla($e, 'add_line', [
            'fis_id' => $fis_id,
            'depogiris' => $depogiris,
            'depokaynak' => $depokaynak,
            'stokref' => $stokref,
            'stfichelnno' => $stfichelnno,
            'amount' => $amount,
            'koli_raw' => $koliRaw,
            'koli_float' => $koliFloat,
            'koli_factor' => $koliFactor,
            'uomref' => $uomref,
            'usref' => $usref,
            'barkod' => $barkodInput,
            'stok_kod' => $stokKodInput,
            'fallback_ref' => $fallbackRef,
            'new_line_id' => $newLineId,
            'template_line_id' => $templateLineId,
        ], $dbh);
        $msg = "Satır eklenemedi: " . $e->getMessage();
    }
}
// --- SATIR SİL ---
if (isset($_GET['sil']) && isset($_GET['fis']) && isset($_GET['ln'])) {
    $fis_id = (int) $_GET['fis'];
    $depogiris = isset($_GET['depogiris']) ? (int) $_GET['depogiris'] : 0;
    $lineno = (int) $_GET['ln'];
    try {
        $dbh->beginTransaction();
        $remaining = null;
        // Satir gercekten var mi?
        $chk = $dbh->prepare("
            SELECT TOP 1 LOGICALREF
            FROM {$firmadonem}STLINE
            WHERE STFICHEREF = ? AND TRCODE = 13 AND IOCODE = 1 AND LINETYPE = 0 AND STFICHELNNO = ?
        ");
        $chk->execute([$fis_id, $lineno]);
        if ($chk->fetch(PDO::FETCH_ASSOC)) {
            $del = $dbh->prepare("
                DELETE FROM {$firmadonem}STLINE
                WHERE STFICHEREF = ? AND TRCODE = 13 AND IOCODE = 1 AND LINETYPE = 0 AND STFICHELNNO = ?
            ");
            $del->execute([$fis_id, $lineno]);
            $cnt = $dbh->prepare("
                SELECT COUNT(*)
                FROM {$firmadonem}STLINE
                WHERE STFICHEREF = ? AND TRCODE = 13 AND IOCODE = 1 AND LINETYPE = 0 AND CANCELLED = 0
            ");
            $cnt->execute([$fis_id]);
            $remaining = (int) $cnt->fetchColumn();
            if ($remaining > 0) {
                recalc_fiche_totals($dbh, $firmadonem, $fis_id);
            } else {
                $dbh->prepare("DELETE FROM {$firmadonem}STLINE WHERE STFICHEREF = ?")->execute([$fis_id]);
                $dbh->prepare("DELETE FROM {$firmadonem}STFICHE WHERE LOGICALREF = ? AND TRCODE = 13")->execute([$fis_id]);
            }
        }
        $dbh->commit();
        if ($remaining === 0) {
            header("Location: index.php");
        } else {
            header("Location: uretim_giris.php?fis=" . $fis_id . "&depogiris=" . $depogiris);
        }
        exit;
    } catch (Exception $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        $msg = "Satir silinemedi: " . $e->getMessage();
    }
}
// --- HIZLI EKLE (+1 Koli) ---
if (isset($_GET['hizli_ekle']) && isset($_GET['stokref']) && isset($_GET['fis'])) {
    $fis_id = (int) $_GET['fis'];
    $depogiris = isset($_GET['depogiris']) ? (int) $_GET['depogiris'] : 0;
    $stokrefHizli = (int) $_GET['stokref'];
    $cokluMod = isset($_GET['coklu']) ? '1' : '';

    try {
        $item = fetch_item_by_ref($dbh, $firma, $stokrefHizli);
        if (!$item) {
            throw new Exception('Geçersiz stok referansı.');
        }

        // Kaynak depo bilgisini fiş başlığından al
        $stFiche = $dbh->prepare("SELECT SOURCEINDEX FROM {$firmadonem}STFICHE WHERE LOGICALREF = ? AND TRCODE = 13");
        $stFiche->execute([$fis_id]);
        $ficheRow = $stFiche->fetch(PDO::FETCH_ASSOC);
        $depokaynak = $ficheRow ? (int) $ficheRow['SOURCEINDEX'] : 0;

        $dbh->beginTransaction();

        $ftime = (int) (date('G') * 65536 + date('i') * 256 + date('s'));

        $stfichelnnoStmt = $dbh->prepare("
            SELECT ISNULL(MAX(STFICHELNNO), 0) + 1
            FROM {$firmadonem}STLINE
            WHERE STFICHEREF = ? AND TRCODE = 13 AND IOCODE = 1 AND LINETYPE = 0
        ");
        $stfichelnnoStmt->execute([$fis_id]);
        $stfichelnno = (int) $stfichelnnoStmt->fetchColumn();
        if ($stfichelnno <= 0) {
            $stfichelnno = 1;
        }

        $stokref = $item['logicalref'];
        $uomref = isset($item['uomref']) ? (int) $item['uomref'] : 0;
        $usref = isset($item['unitsetref']) ? (int) $item['unitsetref'] : 0;
        $baseCf1 = isset($item['cf1']) ? (float) $item['cf1'] : 1.0;
        $uinfo1 = decstr($baseCf1, 6);
        $baseCf2 = isset($item['cf2']) ? (float) $item['cf2'] : 1.0;
        if ($baseCf2 <= 0) {
            $baseCf2 = 1.0;
        }
        $koliFactor = isset($item['koli_cf2']) ? (float) $item['koli_cf2'] : 1.0;
        if ($koliFactor <= 0) {
            $koliFactor = 1.0;
        }
        $koliFloat = 1.0;
        $amount = decstr($koliFloat * $koliFactor, 6);
        $fiyat = decstr(0, 6);
        $total = decstr(0, 6);
        $linenet = $total;
        $vatmatrah = $total;
        $uinfo2 = decstr($baseCf2, 6);

        $ins = $dbh->prepare("
        INSERT INTO {$firmadonem}STLINE
        ( STFICHEREF, DATE_, FTIME, TRCODE, IOCODE, LINETYPE,
          STFICHELNNO, STOCKREF, AMOUNT, UOMREF, USREF, UINFO1, UINFO2,
          PRICE, TOTAL, LINENET,
          GLOBTRANS, CALCTYPE, SOURCETYPE, SOURCEINDEX, SOURCECOSTGRP, SOURCEWSREF, SOURCEPOLNREF,
          DESTTYPE, DESTINDEX, DESTCOSTGRP, DESTWSREF, DESTPOLNREF,
          FACTORYNR, CLIENTREF,
          PRCURR, PRPRICE, TRCURR, TRRATE, REPORTRATE, PLNAMOUNT,
          VATINC, VAT, VATAMNT, VATMATRAH,
          LINEEXP, SITEID, RECSTATUS, ORGLOGICREF, WFSTATUS, CANCELLED )
        VALUES
        ( ?, GETDATE(), ?, 13, 1, 0,
          ?, ?, ?, ?, ?, ?, ?,
          ?, ?, ?,
          0, 0, 0, ?, 0, 0, 0,
          0, ?, 0, 0, 0,
          0, 0,
          0, 0, 0, 0, 1, 0,
          0, 0, 0, ?,
          '', 0, 1, 0, 0, 0 )
        ");
        $ins->execute([
            $fis_id, $ftime, $stfichelnno, $stokref, $amount, $uomref, $usref,
            $uinfo1, $uinfo2, $fiyat, $total, $linenet,
            $depokaynak, $depogiris, $vatmatrah
        ]);

        $stmtNewLine = $dbh->prepare("SELECT MAX(LOGICALREF) FROM {$firmadonem}STLINE WHERE STFICHEREF = ?");
        $stmtNewLine->execute([$fis_id]);
        $newLineId = (int) $stmtNewLine->fetchColumn();
        if ($newLineId > 0) {
            try {
                $stmtTplLine = $dbh->prepare("SELECT TOP 1 LOGICALREF FROM {$firmadonem}STLINE WHERE TRCODE = 13 AND LOGICALREF <> ? ORDER BY LOGICALREF");
                $stmtTplLine->execute([$newLineId]);
                $templateLineId = (int) $stmtTplLine->fetchColumn();
            } catch (Exception) {
                $templateLineId = 0;
            }
            if ($templateLineId > 0) {
                copy_logo_defaults($dbh, "{$firmadonem}STLINE", $templateLineId, $newLineId);
            }
            $yearUpd = $dbh->prepare("UPDATE {$firmadonem}STLINE SET YEAR_ = YEAR(DATE_), MONTH_ = MONTH(DATE_) WHERE LOGICALREF = ?");
            $yearUpd->execute([$newLineId]);
        }
        recalc_fiche_totals($dbh, $firmadonem, $fis_id);
        $dbh->commit();

        $redirectUrl = "uretim_giris.php?fis=" . $fis_id . "&depogiris=" . $depogiris;
        if ($cokluMod) {
            $redirectUrl .= "&coklu=1&hizli_ok=1";
        }
        header("Location: " . $redirectUrl);
        exit;
    } catch (Exception $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        $msg = "Hızlı ekleme başarısız: " . $e->getMessage();
    }
}

// --- Son Kullanılan ve Sık Kullanılan Ürünler (Features 1 & 2) ---
$sonKullanilanlar = [];
$sikKullanilanlar = [];
if ($fiche) {
    // Feature 1: Last 15 unique products in this fiche
    try {
        $stmtSon = $dbh->prepare("
            SELECT DISTINCT TOP 15 S.LOGICALREF, S.CODE, S.NAME,
                COALESCE(PK.CONVFACT2, 1) AS KOLI_CF2
            FROM {$firmadonem}STLINE H
            JOIN {$firma}ITEMS S ON H.STOCKREF = S.LOGICALREF
            OUTER APPLY (
                SELECT TOP 1 IA.CONVFACT2
                FROM {$firma}ITMUNITA IA
                WHERE IA.ITEMREF = S.LOGICALREF AND IA.CONVFACT2 > 0
                ORDER BY IA.LINENR DESC
            ) PK
            WHERE H.STFICHEREF = ? AND H.TRCODE = 13 AND H.IOCODE = 1 AND H.LINETYPE = 0 AND H.CANCELLED = 0
            ORDER BY S.CODE
        ");
        $stmtSon->execute([$fis_id]);
        $sonKullanilanlar = $stmtSon->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $sonKullanilanlar = [];
    }

    // Feature 2: Top 15 most used products in last 30 days (exclude already shown)
    try {
        $excludeRefs = array_map(function ($r) {
            return (int) $r['LOGICALREF'];
        }, $sonKullanilanlar);

        $excludeClause = '';
        $excludeParams = [];
        if (!empty($excludeRefs)) {
            $placeholders = implode(',', array_fill(0, count($excludeRefs), '?'));
            $excludeClause = " AND S.LOGICALREF NOT IN ({$placeholders})";
            $excludeParams = $excludeRefs;
        }

        $sqlSik = "
            SELECT TOP 15 S.LOGICALREF, S.CODE, S.NAME,
                COALESCE(PK.CONVFACT2, 1) AS KOLI_CF2,
                COUNT(*) AS KULLANIM
            FROM {$firmadonem}STLINE H
            JOIN {$firmadonem}STFICHE F ON H.STFICHEREF = F.LOGICALREF
            JOIN {$firma}ITEMS S ON H.STOCKREF = S.LOGICALREF
            OUTER APPLY (
                SELECT TOP 1 IA.CONVFACT2
                FROM {$firma}ITMUNITA IA
                WHERE IA.ITEMREF = S.LOGICALREF AND IA.CONVFACT2 > 0
                ORDER BY IA.LINENR DESC
            ) PK
            WHERE F.TRCODE = 13 AND H.IOCODE = 1 AND H.LINETYPE = 0 AND H.CANCELLED = 0
                AND F.DATE_ >= DATEADD(DAY, -30, GETDATE())
                {$excludeClause}
            GROUP BY S.LOGICALREF, S.CODE, S.NAME, PK.CONVFACT2
            ORDER BY KULLANIM DESC
        ";
        $stmtSik = $dbh->prepare($sqlSik);
        $stmtSik->execute($excludeParams);
        $sikKullanilanlar = $stmtSik->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $sikKullanilanlar = [];
    }
}

// Çoklu ekleme modundan gelen bildirim
$cokluMod = isset($_GET['coklu']) ? true : false;
$hizliOk = isset($_GET['hizli_ok']) ? true : false;
?>

<!doctype html>
<html lang="tr">

<head>
    <meta charset="utf-8">
    <title>Üretimden Giriş Fişi (TRCODE:13)</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/v4-shims.min.css">
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
            --emerald-dark: #047857;
            --emerald-soft: #ecfdf5;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { overflow-x: hidden; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            padding-bottom: 80px;
            line-height: 1.55;
        }

        /* Sticky emerald header */
        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(5, 150, 105, 0.18);
            box-shadow: 0 2px 8px rgba(5, 150, 105, 0.05);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 38px; height: 38px; border-radius: 10px;
            color: var(--text-2); text-decoration: none;
            transition: all 0.2s ease; flex-shrink: 0;
        }
        .header-back:hover { background: var(--emerald-soft); color: var(--emerald); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: inline-flex; align-items: center; gap: 10px;
            flex: 1 1 auto;
            min-width: 0;
        }
        .header-title i { color: var(--emerald); font-size: 15px; }
        .header-title .subtitle {
            margin-left: 8px; font-size: 11px;
            color: var(--text-3); font-weight: 500;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .header-actions {
            display: inline-flex; gap: 8px; flex-shrink: 0;
        }
        .btn-emerald {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 14px;
            background: var(--emerald); color: #fff;
            border: 1px solid var(--emerald); border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            text-decoration: none;
            min-height: 44px; white-space: nowrap;
        }
        .btn-emerald:hover { background: var(--emerald-dark); border-color: var(--emerald-dark); color: #fff; }

        main { max-width: 1200px; margin: 0 auto; padding: 18px 22px 100px; }

        /* Glass card base */
        .glass-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            will-change: transform, opacity;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            margin-bottom: 14px;
        }
        .glass-card:nth-of-type(1) { animation-delay: 0ms; }
        .glass-card:nth-of-type(2) { animation-delay: 70ms; }
        .glass-card:nth-of-type(3) { animation-delay: 140ms; }
        .glass-card:nth-of-type(4) { animation-delay: 210ms; }
        .glass-card:nth-of-type(5) { animation-delay: 280ms; }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to   { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        /* Summary card */
        .summary-card { position: relative; overflow: hidden; }
        .summary-card .card-body,
        .summary-card .fiche-summary-body {
            padding: 16px 20px;
        }
        .summary-toggle {
            display: none;
            width: 100%; background: transparent; border: none;
            color: var(--text-1); font-size: 14px; font-weight: 600;
            padding: 14px 18px 4px;
            justify-content: space-between; align-items: center;
            cursor: pointer;
        }
        .summary-toggle span {
            display: inline-flex; align-items: center; gap: 8px;
        }
        .summary-toggle span i:first-child { color: var(--emerald); font-size: 13px; }
        .summary-toggle .summary-toggle-icon {
            transition: transform .25s ease; color: var(--text-3); font-size: 12px;
        }
        .summary-card:not(.is-open) .summary-toggle .summary-toggle-icon {
            transform: rotate(180deg);
        }
        .summary-toggle:focus {
            outline: none; box-shadow: 0 0 0 3px rgba(5, 150, 105, .25);
        }
        .fiche-summary-body {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px;
        }
        .summary-label {
            display: block;
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-2);
            margin-bottom: 4px;
        }
        .summary-value {
            font-size: 15px; font-weight: 700;
            color: var(--text-1);
            font-variant-numeric: tabular-nums;
        }

        /* Alerts */
        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            border: 1px solid var(--border);
            font-size: 13px;
            margin-bottom: 14px;
        }
        .alert-warning {
            background: var(--amber-soft);
            border-color: rgba(217, 119, 6, 0.3);
            color: #92400e;
        }
        .alert-info {
            background: var(--sky-soft);
            border-color: rgba(2, 132, 199, 0.3);
            color: #075985;
        }

        /* Quick chips */
        .quick-chips-section .card-body { padding: 14px 18px; }
        .quick-chips-label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-2);
            margin-bottom: 8px;
            display: flex; align-items: center; gap: 7px;
        }
        .quick-chips-label i { color: var(--emerald); font-size: 11px; }
        .quick-chips-row {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin-bottom: 12px;
        }
        .quick-chips-row:last-child { margin-bottom: 0; }
        .quick-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 12px;
            border-radius: 100px;
            font-size: 12px; font-weight: 600;
            background: var(--emerald-soft);
            border: 1px solid rgba(5, 150, 105, 0.22);
            color: var(--emerald-dark);
            cursor: pointer; transition: all 0.15s ease;
            min-height: 34px;
        }
        .quick-chip:hover {
            background: var(--emerald);
            border-color: var(--emerald);
            color: #fff;
            transform: translateY(-1px);
        }
        .quick-chip .chip-code {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 11.5px; font-weight: 700;
        }
        .quick-chip.chip-sik {
            background: var(--amber-soft);
            border-color: rgba(217, 119, 6, 0.3);
            color: #92400e;
        }
        .quick-chip.chip-sik:hover {
            background: var(--amber); border-color: var(--amber); color: #fff;
        }

        /* Entry form */
        .card-body { padding: 18px 20px; }
        .entry-form {
            display: flex; flex-direction: column; gap: 16px;
        }
        .entry-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }
        .entry-grid .form-group {
            display: flex; flex-direction: column; gap: 6px;
        }
        .entry-grid .span-2 { grid-column: span 2; }

        .form-label {
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-2);
        }
        .form-control {
            padding: 10px 12px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-1);
            font-size: 13px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            transition: all 0.18s ease;
            min-height: 44px;
            width: 100%;
        }
        .form-control:focus {
            outline: none;
            border-color: rgba(5, 150, 105, 0.5);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.12);
        }
        .form-control::placeholder { color: var(--text-3); }
        .form-control[readonly] {
            background: #f3f4f6; color: var(--text-2); cursor: default;
        }

        .info-box {
            min-height: 74px;
            background: var(--emerald-soft);
            border: 1px solid rgba(5, 150, 105, 0.18);
            border-radius: 10px;
            padding: 12px 14px;
            color: var(--text-1);
            font-size: 13px; line-height: 1.55;
        }
        .info-box strong { color: var(--emerald-dark); font-weight: 700; }
        .info-box .text-danger { color: var(--red); font-weight: 600; }

        /* Autocomplete */
        .autocomplete-group {
            display: flex; gap: 8px; align-items: stretch;
        }
        .autocomplete-input { position: relative; flex: 1; }
        .autocomplete-buttons { display: flex; gap: 8px; }

        .btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 44px; min-height: 44px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid var(--border);
            background: #fff; color: var(--text-2);
            cursor: pointer; transition: all 0.15s ease;
            font-size: 13px;
        }
        .btn-icon:hover {
            border-color: var(--emerald);
            color: var(--emerald);
            background: var(--emerald-soft);
        }

        .autocomplete-menu {
            position: absolute;
            display: none;
            width: 100%;
            max-height: 280px;
            overflow-y: auto;
            top: calc(100% + 6px); left: 0;
            z-index: 1050;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 12px 28px rgba(17, 24, 39, 0.12);
            padding: 4px 0;
        }
        .autocomplete-menu.show { display: block; }
        .autocomplete-item {
            width: 100%;
            background: transparent; border: 0;
            border-bottom: 1px solid #f3f4f6;
            padding: 10px 14px;
            text-align: left; cursor: pointer;
            transition: background-color 0.15s ease;
            color: var(--text-1);
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
        }
        .autocomplete-item:last-child { border-bottom: none; }
        .autocomplete-item:focus,
        .autocomplete-item:hover {
            outline: none;
            background: var(--emerald-soft);
        }
        .autocomplete-item .code {
            display: block; font-weight: 700;
            color: var(--emerald-dark);
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-size: 12.5px;
        }
        .autocomplete-item .name {
            display: block; font-size: 12px;
            color: var(--text-1); margin-top: 3px;
        }
        .autocomplete-item .meta {
            display: block; margin-top: 4px;
            font-size: 10.5px; color: var(--text-3);
        }

        /* Form actions */
        .form-actions {
            display: flex; justify-content: space-between;
            align-items: center; gap: 12px;
            flex-wrap: wrap;
        }
        .multi-toggle-wrap {
            display: flex; align-items: center; gap: 8px;
        }
        .multi-toggle-wrap input[type="checkbox"] {
            width: 18px; height: 18px; cursor: pointer;
            accent-color: var(--emerald);
        }
        .multi-toggle-wrap label {
            font-size: 12.5px; font-weight: 600;
            cursor: pointer; color: var(--text-2); margin: 0;
        }
        .multi-counter {
            display: none;
            padding: 4px 10px;
            background: var(--emerald-soft);
            border: 1px solid rgba(5, 150, 105, 0.25);
            border-radius: 100px;
            font-size: 11.5px; font-weight: 700;
            color: var(--emerald-dark);
        }
        .multi-counter.show { display: inline-block; }

        .btn-add-line {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px;
            background: var(--emerald); color: #fff;
            border: 1px solid var(--emerald);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px; font-weight: 600;
            cursor: pointer; transition: all 0.2s ease;
            min-height: 44px;
        }
        .btn-add-line:hover { background: var(--emerald-dark); border-color: var(--emerald-dark); }

        /* Table */
        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #fff;
        }
        .lines-table {
            width: 100%;
            border-collapse: collapse;
        }
        .lines-table thead {
            background: linear-gradient(180deg, #fff, var(--emerald-soft));
        }
        .lines-table thead th {
            padding: 11px 14px;
            text-align: left;
            font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-2);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .lines-table thead th.text-right { text-align: right; }
        .lines-table thead th.text-center { text-align: center; }
        .lines-table tbody td {
            padding: 11px 14px;
            font-size: 12.5px;
            color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .lines-table tbody tr:last-child td { border-bottom: 1px solid var(--border); }
        .lines-table tbody tr { transition: background 0.15s ease; }
        .lines-table tbody tr:hover { background: var(--emerald-soft); }

        .lines-table td.text-right {
            text-align: right;
            font-variant-numeric: tabular-nums;
            font-weight: 600;
        }
        .lines-table td.text-center { text-align: center; }

        .lines-table .cell-code {
            font-family: 'JetBrains Mono', 'Courier New', monospace;
            font-weight: 700; color: var(--emerald-dark);
            font-size: 12px;
        }
        .lines-table .cell-lnno {
            font-variant-numeric: tabular-nums;
            color: var(--text-3); font-weight: 600;
        }
        .lines-table tfoot th {
            padding: 11px 14px;
            font-size: 12px; font-weight: 700;
            color: var(--text-1);
            border-top: 2px solid rgba(5, 150, 105, 0.3);
            background: var(--emerald-soft);
        }
        .lines-table tfoot th.text-right {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .lines-table tfoot tr:last-child th {
            background: #fff;
            border-top: 1px solid var(--border);
            color: var(--text-3);
            font-weight: 500;
            font-size: 11px;
        }

        .row-actions {
            display: inline-flex; gap: 6px; flex-wrap: nowrap;
        }
        .btn-row {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 7px 11px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fff;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 11.5px; font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.15s ease;
            min-height: 34px;
        }
        .btn-row.plus {
            background: var(--emerald-soft);
            border-color: rgba(5, 150, 105, 0.3);
            color: var(--emerald-dark);
        }
        .btn-row.plus:hover {
            background: var(--emerald); color: #fff; border-color: var(--emerald);
        }
        .btn-row.danger {
            background: var(--red-soft);
            border-color: rgba(111, 16, 34, 0.3);
            color: var(--red);
        }
        .btn-row.danger:hover {
            background: var(--red); color: #fff; border-color: var(--red);
        }

        .empty-state {
            padding: 50px 20px; text-align: center;
            color: var(--text-3);
        }
        .empty-state i { font-size: 40px; margin-bottom: 12px; color: var(--border); }

        /* Action bar */
        .bottom-actions {
            display: flex; justify-content: flex-end; gap: 10px;
            padding: 14px 0 0; flex-wrap: wrap;
        }
        .btn-ghost {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 14px;
            background: #fff;
            color: var(--text-2);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; font-weight: 600;
            text-decoration: none;
            cursor: pointer; transition: all 0.2s ease;
            min-height: 44px;
        }
        .btn-ghost:hover {
            border-color: var(--emerald); color: var(--emerald);
            background: var(--emerald-soft);
        }
        .btn-ghost.danger:hover {
            border-color: var(--red); color: var(--red);
            background: var(--red-soft);
        }

        /* Modal (Bootstrap 4 overrides) */
        .modal-content {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 16px;
            color: var(--text-1);
            overflow: hidden;
        }
        .modal-header {
            background: linear-gradient(135deg, var(--emerald) 0%, var(--emerald-dark) 100%);
            color: #fff;
            border-bottom: none;
            padding: 14px 20px;
        }
        .modal-header .modal-title { font-weight: 700; font-size: 15px; color: #fff; }
        .modal-header .close {
            color: #fff; opacity: 0.85; text-shadow: none;
        }
        .modal-header .close:hover { opacity: 1; }
        .modal-body { padding: 18px 20px; background: var(--emerald-soft); }
        .modal-body .form-control { background: #fff; }
        .modal-body .form-control:focus {
            border-color: rgba(5, 150, 105, 0.5);
            box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15);
        }
        .modal-footer {
            background: #fff;
            border-top: 1px solid var(--border);
            padding: 12px 18px;
        }
        .modal-footer .btn-primary {
            background: var(--emerald); border-color: var(--emerald);
            padding: 9px 16px; font-weight: 600;
            border-radius: 10px;
        }
        .modal-footer .btn-primary:hover {
            background: var(--emerald-dark); border-color: var(--emerald-dark);
        }
        .modal-footer .btn-primary:disabled { opacity: 0.5; }
        .modal-footer .btn-secondary {
            background: #fff; color: var(--text-2); border-color: var(--border);
            border-radius: 10px; padding: 9px 16px; font-weight: 600;
        }

        /* Toast */
        .toast-flash {
            position: fixed;
            top: 20px; right: 20px;
            z-index: 9999;
            padding: 12px 18px;
            background: var(--emerald);
            color: #fff;
            border-radius: 12px;
            font-size: 13px; font-weight: 600;
            box-shadow: 0 12px 28px rgba(5, 150, 105, 0.35);
            display: inline-flex; align-items: center; gap: 8px;
            transform: translateX(140%);
            transition: transform 0.3s ease;
        }
        .toast-flash.show { transform: translateX(0); }
        .toast-flash i { font-size: 14px; }

        /* Responsive */
        @media (max-width: 1024px) {
            main { padding: 16px 18px 100px; }
        }
        @media (max-width: 767px) {
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title { font-size: 14px; }
            .header-title .subtitle { display: none; }
            .header-back { width: 34px; height: 34px; border-radius: 8px; }
            .btn-emerald { padding: 8px 12px; font-size: 12px; }
            .btn-emerald span.label-text { display: none; }

            main { padding: 12px 12px 100px; }

            .summary-toggle { display: flex; padding: 14px 16px; }
            .summary-card .card-body { padding: 0 16px 16px; }
            .summary-card .fiche-summary-body {
                display: none;
                border-top: 1px solid var(--border);
                padding: 14px 16px;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }
            .summary-card.is-open .fiche-summary-body { display: grid; }

            .entry-grid { grid-template-columns: 1fr; }
            .entry-grid .span-2 { grid-column: span 1; }

            .form-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .multi-toggle-wrap { justify-content: center; }
            .btn-add-line { justify-content: center; width: 100%; }

            /* Table responsive — stack into cards */
            .lines-table thead { display: none; }
            .lines-table, .lines-table tbody, .lines-table tr { display: block; width: 100%; }
            .lines-table tbody tr {
                padding: 12px 14px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: auto 1fr auto;
                grid-template-areas:
                    "lnno code actions"
                    "name name name"
                    "koli miktar birim";
                gap: 6px 10px;
                align-items: center;
            }
            .lines-table tbody td { padding: 0; border-bottom: none; }
            .lines-table tbody td:nth-child(1) { grid-area: lnno; font-size: 11px; color: var(--text-3); font-weight: 700; }
            .lines-table tbody td:nth-child(2) { grid-area: code; }
            .lines-table tbody td:nth-child(3) { grid-area: name; color: var(--text-2); font-size: 12.5px; }
            .lines-table tbody td:nth-child(4) { grid-area: koli; text-align: left; }
            .lines-table tbody td:nth-child(4):before { content: 'Koli: '; color: var(--text-3); font-weight: 500; }
            .lines-table tbody td:nth-child(5) { grid-area: miktar; text-align: left; }
            .lines-table tbody td:nth-child(5):before { content: 'Miktar: '; color: var(--text-3); font-weight: 500; }
            .lines-table tbody td:nth-child(6) { grid-area: birim; text-align: right; color: var(--text-2); font-size: 11.5px; }
            .lines-table tbody td:nth-child(7) { grid-area: actions; text-align: right; }

            .lines-table tfoot, .lines-table tfoot tr, .lines-table tfoot th { display: block; width: 100%; }
            .lines-table tfoot tr {
                display: grid; grid-template-columns: 1fr auto;
                padding: 10px 14px; gap: 8px;
            }
            .lines-table tfoot th { padding: 0; text-align: left !important; border: none; background: transparent; }
            .lines-table tfoot th[colspan="3"]:first-child { grid-column: 1; font-size: 11px; text-transform: uppercase; color: var(--text-2); }
            .lines-table tfoot th.text-right { grid-column: 2; font-size: 14px; color: var(--emerald-dark); }
            .lines-table tfoot th[colspan="3"]:last-child { display: none; }
            .lines-table tfoot tr:last-child { grid-template-columns: 1fr; background: transparent; }
            .lines-table tfoot tr:last-child th { text-align: center !important; color: var(--text-3); }

            /* Prevent iOS zoom */
            input[type="text"], input[type="number"], select, textarea {
                font-size: 16px !important;
            }
        }

        @media (max-width: 480px) {
            .header-actions { gap: 6px; }
            .quick-chip { padding: 6px 10px; font-size: 11.5px; }
        }
    </style>
</head>

<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="index.php" class="header-back" title="Listeye Dön">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <span class="header-title">
                <i class="fa-solid fa-industry"></i>Üretimden Giriş
                <span class="subtitle">
                    <?php if ($fiche) { ?>
                        Fiş No: <?php echo htmlspecialchars((string) trcevir($fiche['FICHENO']), ENT_QUOTES, 'UTF-8'); ?>
                    <?php } else { ?>
                        YENI
                    <?php } ?>
                </span>
            </span>
            <div class="header-actions">
                <?php if ($fiche) { ?>
                    <a class="btn-emerald" href="uretim_giris.php?yeni=1">
                        <i class="fa fa-plus-circle"></i><span class="label-text">Yeni Fiş</span>
                    </a>
                <?php } ?>
            </div>
        </div>
    </header>

    <main>

        <?php if ($fiche) { ?>
            <div class="glass-card summary-card is-open">
                <button type="button" class="summary-toggle" aria-expanded="true" aria-controls="fiche-summary">
                    <span><i class="fa-solid fa-file-lines"></i> Fiş Bilgileri</span>
                    <i class="fa fa-chevron-up summary-toggle-icon"></i>
                </button>
                <div class="card-body">
                    <div class="fiche-summary-body" id="fiche-summary">
                        <div>
                            <span class="summary-label">Fiş ID</span>
                            <span class="summary-value">#<?php echo (int) $fiche['LOGICALREF']; ?></span>
                        </div>
                        <div>
                            <span class="summary-label">Fiş No</span>
                            <span class="summary-value"><?php echo htmlspecialchars((string) trcevir($fiche['FICHENO']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div>
                            <span class="summary-label">Tarih</span>
                            <span class="summary-value"><?php echo htmlspecialchars(date('d.m.Y H:i', strtotime((string) $fiche['DATE_']))); ?></span>
                        </div>
                        <div>
                            <span class="summary-label">Hedef Depo</span>
                            <span class="summary-value"><?php echo $depogiris; ?></span>
                        </div>
                        <?php if ($depokaynak !== 0) { ?>
                            <div>
                                <span class="summary-label">Kaynak Depo</span>
                                <span class="summary-value"><?php echo (int) $depokaynak; ?></span>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        <?php if ($msg !== '' && $msg !== '0') { ?>
            <div class="alert alert-warning"><?php echo htmlspecialchars((string) trcevir($msg), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>

        <?php if ($fiche && (!empty($sonKullanilanlar) || !empty($sikKullanilanlar))) { ?>
            <div class="glass-card quick-chips-section">
                <div class="card-body">
                    <?php if (!empty($sonKullanilanlar)) { ?>
                        <div class="quick-chips-label"><i class="fa fa-history"></i> Bu fişte kullanılanlar</div>
                        <div class="quick-chips-row">
                            <?php foreach ($sonKullanilanlar as $sk) {
                                $skCode = htmlspecialchars((string) trcevir($sk['CODE']), ENT_QUOTES, 'UTF-8');
                                $skName = htmlspecialchars((string) trcevir($sk['NAME']), ENT_QUOTES, 'UTF-8');
                                $skRef = (int) $sk['LOGICALREF'];
                                $skCf2 = isset($sk['KOLI_CF2']) ? (float) $sk['KOLI_CF2'] : 1.0;
                            ?>
                                <button type="button" class="quick-chip"
                                    data-ref="<?php echo $skRef; ?>"
                                    data-code="<?php echo $skCode; ?>"
                                    data-name="<?php echo $skName; ?>"
                                    data-cf2="<?php echo $skCf2; ?>"
                                    title="<?php echo $skName; ?>">
                                    <span class="chip-code"><?php echo $skCode; ?></span>
                                </button>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <?php if (!empty($sikKullanilanlar)) { ?>
                        <div class="quick-chips-label"><i class="fa-solid fa-star"></i> Sık Kullanılan (Son 30 gün)</div>
                        <div class="quick-chips-row">
                            <?php foreach ($sikKullanilanlar as $sik) {
                                $sikCode = htmlspecialchars((string) trcevir($sik['CODE']), ENT_QUOTES, 'UTF-8');
                                $sikName = htmlspecialchars((string) trcevir($sik['NAME']), ENT_QUOTES, 'UTF-8');
                                $sikRef = (int) $sik['LOGICALREF'];
                                $sikCf2 = isset($sik['KOLI_CF2']) ? (float) $sik['KOLI_CF2'] : 1.0;
                            ?>
                                <button type="button" class="quick-chip chip-sik"
                                    data-ref="<?php echo $sikRef; ?>"
                                    data-code="<?php echo $sikCode; ?>"
                                    data-name="<?php echo $sikName; ?>"
                                    data-cf2="<?php echo $sikCf2; ?>"
                                    title="<?php echo $sikName; ?>">
                                    <span class="chip-code"><?php echo $sikCode; ?></span>
                                </button>
                            <?php } ?>
                        </div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>

        <?php if ($fiche) { ?>
            <div class="glass-card">
                <div class="card-body">
                    <form class="entry-form" method="POST" action="" id="entryForm">
                        <input type="hidden" name="fis" value="<?php echo $fis_id; ?>">
                        <input type="hidden" name="depogiris" value="<?php echo $depogiris; ?>">
                        <input type="hidden" name="depokaynak" value="<?php echo $depokaynak; ?>">
                        <input type="hidden" name="barkod" id="form-barkod">
                        <input type="hidden" name="stokref" id="stokref_hidden">
                        <div class="entry-grid">
                            <div class="form-group span-2">
                                <label class="form-label" for="stok_kod">Stok Kod / Ara</label>
                                <div class="autocomplete-group">
                                    <div class="autocomplete-input">
                                        <input type="text" class="form-control" name="stok_kod" id="stok_kod" autocomplete="off" placeholder="Stok kodu veya adı (min 2 karakter)">
                                        <div id="stokKodSuggestions" class="autocomplete-menu" role="listbox"></div>
                                    </div>
                                    <div class="autocomplete-buttons">
                                        <button class="btn-icon" type="button" id="kodLookupBtn" title="Ara"><i class="fa fa-search"></i></button>
                                        <!-- BARKOD BUTONU DEAKTİF (yorum satırı) -->
                                        <!-- <button class="btn-icon" type="button" data-toggle="modal" data-target="#barcodeModal"><i class="fa fa-barcode"></i></button> -->
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="koli_input">Koli Adedi</label>
                                <input type="number" step="0.01" min="0" class="form-control" name="koli" id="koli_input" value="1" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="koli_info">Koli içi Adet</label>
                                <input type="text" class="form-control" id="koli_info" readonly>
                            </div>
                            <div class="form-group span-2">
                                <label class="form-label" for="urunBilgisi">Ürün Bilgisi</label>
                                <div id="urunBilgisi" class="info-box">Ürün seçilmedi.</div>
                            </div>
                            <div class="form-group span-2">
                                <label class="form-label" for="satir_aciklama">Satır Açıklama</label>
                                <input type="text" class="form-control" name="satir_aciklama" id="satir_aciklama" placeholder="Opsiyonel">
                            </div>
                        </div>
                        <div class="form-actions">
                            <div class="multi-toggle-wrap">
                                <input type="checkbox" id="cokluToggle" <?php echo $cokluMod ? 'checked' : ''; ?>>
                                <label for="cokluToggle">Çoklu Ekle</label>
                                <span class="multi-counter" id="multiCounter">0 ürün eklendi</span>
                            </div>
                            <button type="submit" name="add_line" class="btn-add-line">
                                <i class="fa fa-plus"></i> Satır Ekle
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="glass-card">
                <div class="card-body" style="padding: 0;">
                    <div class="table-container" style="border: none; border-radius: 0;">
                        <table class="lines-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Kod</th>
                                    <th>Adı</th>
                                    <th class="text-right">Koli Adedi</th>
                                    <th class="text-right">Miktar</th>
                                    <th>Birim</th>
                                    <th class="text-right">İşlem</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $adettoplam = 0.0;
                                $kolitoplam = 0.0;
                                $q = $dbh->prepare("
            SELECT H.STFICHELNNO, H.AMOUNT, H.LINEEXP, H.STOCKREF,
                   COALESCE(PK.CONVFACT2, 1) AS KOLI_CF2,
                   S.CODE AS KODU, S.NAME AS ADI, BR.CODE AS BIRIM
            FROM {$firmadonem}STLINE H
            JOIN {$firma}ITEMS S       ON H.STOCKREF = S.LOGICALREF
            LEFT JOIN {$firma}UNITSETL BR ON H.UOMREF = BR.LOGICALREF
            OUTER APPLY (
                SELECT TOP 1 IA.CONVFACT2
                FROM {$firma}ITMUNITA IA
                WHERE IA.ITEMREF = S.LOGICALREF AND IA.CONVFACT2 IS NOT NULL AND IA.CONVFACT2 > 0
                ORDER BY IA.LINENR DESC
            ) PK
            WHERE H.STFICHEREF = ? AND H.TRCODE = 13 AND H.IOCODE = 1 AND H.LINETYPE = 0 AND H.CANCELLED = 0
            ORDER BY H.LOGICALREF DESC
          ");
                                $q->execute([$fis_id]);
                                $rowCount = 0;
                                while ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                                    $rowCount++;
                                    $amount = (float) $r['AMOUNT'];
                                    $adettoplam += $amount;
                                    $koliFactor = isset($r['KOLI_CF2']) ? (float) $r['KOLI_CF2'] : 0.0;
                                    if ($koliFactor <= 0) {
                                        $koliFactor = 1.0;
                                    }
                                    $koliAdedi = $amount / $koliFactor;
                                    $kolitoplam += $koliAdedi;
                                ?>
                                <tr>
                                    <td class="cell-lnno"><?php echo (int) $r['STFICHELNNO']; ?></td>
                                    <td class="cell-code"><?php echo htmlspecialchars((string) trcevir($r['KODU']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string) trcevir($r['ADI']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-right"><?php echo htmlspecialchars(kusuratadet($koliAdedi)); ?></td>
                                    <td class="text-right"><?php echo htmlspecialchars(kusuratadet($amount)); ?></td>
                                    <td><?php echo htmlspecialchars((string) trcevir($r['BIRIM']), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-right">
                                        <div class="row-actions">
                                            <a class="btn-row plus" title="+1 Koli"
                                                href="uretim_giris.php?hizli_ekle=1&amp;fis=<?php echo $fis_id; ?>&amp;depogiris=<?php echo $depogiris; ?>&amp;stokref=<?php echo (int) $r['STOCKREF']; ?>">
                                                <i class="fa fa-plus"></i> +1
                                            </a>
                                            <a class="btn-row danger"
                                                onclick="return confirm('Satır silinsin mi?')"
                                                href="uretim_giris.php?sil=1&amp;fis=<?php echo $fis_id; ?>&amp;depogiris=<?php echo $depogiris; ?>&amp;ln=<?php echo (int) $r['STFICHELNNO']; ?>"
                                                title="Sil">
                                                <i class="fa fa-trash"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                                <?php
                                }
                                if ($rowCount === 0) {
                                ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <i class="fa-solid fa-box-open"></i>
                                            <div>Henüz satır eklenmedi. Yukarıdan stok seçip satır ekleyin.</div>
                                        </div>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                            <?php if ($rowCount > 0) { ?>
                            <tfoot>
                                <tr>
                                    <th colspan="3" class="text-right">Toplam Koli</th>
                                    <th class="text-right"><?php echo htmlspecialchars(kusuratadet($kolitoplam)); ?></th>
                                    <th colspan="3"></th>
                                </tr>
                                <tr>
                                    <th colspan="3" class="text-right">Toplam Miktar</th>
                                    <th class="text-right"><?php echo htmlspecialchars(kusuratadet($adettoplam)); ?></th>
                                    <th colspan="3"></th>
                                </tr>
                                <tr>
                                    <th colspan="7" class="text-right"><?php echo (int) $rowCount; ?> satır listeleniyor.</th>
                                </tr>
                            </tfoot>
                            <?php } ?>
                        </table>
                    </div>
                </div>
            </div>

            <div class="bottom-actions">
                <a class="btn-ghost danger" href="index.php"><i class="fa fa-times"></i> Vazgeç</a>
                <a class="btn-emerald" href="index.php"><i class="fa fa-check"></i> Kaydet / Bitir</a>
            </div>
        <?php } else { ?>
            <div class="glass-card">
                <div class="card-body">
                    <div class="alert alert-info"><i class="fa fa-info-circle"></i> Geçerli bir fiş ID'si ile gelmelisiniz. <a href="uretim_giris.php?yeni=1" style="color: var(--emerald-dark); font-weight: 700;">Yeni fiş oluştur</a>.</div>
                </div>
            </div>
        <?php } ?>
    </main>

    <!-- RAF reflow fix for glass-card animation -->
    <script>
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                document.querySelectorAll('.glass-card').forEach(function(c) {
                    c.style.willChange = 'auto';
                });
            });
        });
    </script>

    <script src="/tm/js/jquery-3.7.1.min.js"></script>
    <script>
        (function() {
            var summaryCard = document.querySelector('.summary-card');
            if (!summaryCard) {
                return;
            }
            var toggle = summaryCard.querySelector('.summary-toggle');
            var summaryBody = summaryCard.querySelector('.fiche-summary-body');
            if (!toggle || !summaryBody) {
                return;
            }

            function setState(open) {
                summaryCard.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            }

            var mq = window.matchMedia('(max-width: 768px)');

            function applyForViewport(e) {
                var matches = e && typeof e.matches === 'boolean' ? e.matches : mq.matches;
                if (matches) {
                    setState(false);
                } else {
                    setState(true);
                }
            }

            toggle.addEventListener('click', function() {
                var open = summaryCard.classList.contains('is-open');
                setState(!open);
            });

            applyForViewport(mq);
            if (typeof mq.addEventListener === 'function') {
                mq.addEventListener('change', applyForViewport);
            } else if (typeof mq.addListener === 'function') {
                mq.addListener(applyForViewport);
            }
        })();
    </script>
    <div class="modal fade" id="barcodeModal" tabindex="-1" role="dialog" aria-labelledby="barcodeModalLabel"
        aria-hidden="true">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="barcodeModalLabel"><i class="fa fa-barcode"></i> Barkod Okuma</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Kapat">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <form id="barcodeForm">
                        <div class="form-group">
                            <label for="barcodeInput" class="form-label">Barkod</label>
                            <input type="text" class="form-control" id="barcodeInput" autocomplete="off">
                        </div>
                    </form>
                    <div id="barcodeInfo" class="small text-muted" style="margin-top: 10px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Kapat</button>
                    <button type="button" class="btn btn-primary" id="barcodeUseBtn" disabled>Formda kullan</button>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            var stokInput = document.getElementById('stok_kod');
            if (!stokInput) {
                return;
            }
            var barkodHidden = document.getElementById('form-barkod');
            var stokRefHidden = document.getElementById('stokref_hidden');
            var suggestionsBox = document.getElementById('stokKodSuggestions');
            var suggestionTimer = null;
            var urunBilgisi = document.getElementById('urunBilgisi');
            var koliInfo = document.getElementById('koli_info');
            var koliInput = document.getElementById('koli_input');
            var kodLookupBtn = document.getElementById('kodLookupBtn');
            var modal = $('#barcodeModal');
            var barcodeInput = document.getElementById('barcodeInput');
            var barcodeForm = document.getElementById('barcodeForm');
            var barcodeInfo = document.getElementById('barcodeInfo');
            var barcodeUseBtn = document.getElementById('barcodeUseBtn');
            var currentItem = null;
            var ESC_MAP = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;'
            };
            ESC_MAP['"'] = '&quot;';
            ESC_MAP["'"] = '&#39;';

            function escapeHtml(str) {
                return String(str || '').replace(/[&<>"']/g, function(ch) {
                    return ESC_MAP[ch] || ch;
                });
            }

            function parseJsonSafely(response) {
                return response.text().then(function(text) {
                    if (!text) {
                        return {};
                    }
                    try {
                        return JSON.parse(text);
                    } catch (err) {
                        err.responseText = text;
                        throw err;
                    }
                });
            }

            function formatNumber(val) {
                var num = parseFloat(val);
                if (!isFinite(num)) {
                    num = 0;
                }
                return num.toLocaleString('tr-TR', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: 3
                });
            }

            function clearSuggestions() {
                if (suggestionsBox) {
                    suggestionsBox.innerHTML = '';
                    suggestionsBox.classList.remove('show');
                    suggestionsBox.style.display = 'none';
                }
            }

            function renderSuggestions(items) {
                if (!suggestionsBox) {
                    return;
                }
                suggestionsBox.innerHTML = '';
                if (!items || !items.length) {
                    suggestionsBox.classList.remove('show');
                    suggestionsBox.style.display = 'none';
                    return;
                }
                items.forEach(function(item) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'autocomplete-item';
                    var metaParts = [];
                    if (item.koli_birim && item.koli_adet) {
                        metaParts.push('Koli: ' + escapeHtml(item.koli_birim) + ' × ' + formatNumber(item
                            .koli_adet));
                    }
                    if (item.birim) {
                        metaParts.push('Birim: ' + escapeHtml(item.birim));
                    }
                    var metaHtml = metaParts.length ? '<span class="meta">' + metaParts.join(' • ') +
                        '</span>' : '';
                    btn.innerHTML = '<span class="code">' + escapeHtml(item.code || '') + '</span>' +
                        '<span class="name">' + escapeHtml(item.name || '') + '</span>' + metaHtml;
                    btn.dataset.code = item.code || '';
                    btn.dataset.name = item.name || '';
                    btn.dataset.factor = item.koli_adet || 1;
                    btn.dataset.birim = item.birim || '';
                    btn.dataset.koliBirim = item.koli_birim || '';
                    btn.dataset.logicalref = item.logicalref || 0;
                    btn.addEventListener('click', function() {
                        clearSuggestions();
                        applyItem({
                            logicalref: parseInt(this.dataset.logicalref, 10) || 0,
                            code: this.dataset.code,
                            name: this.dataset.name,
                            barcode: '',
                            koli_adet: parseFloat(this.dataset.factor) || 1,
                            birim: this.dataset.birim,
                            koli_birim: this.dataset.koliBirim
                        }, false);
                    });
                    suggestionsBox.appendChild(btn);
                });
                suggestionsBox.style.display = 'block';
                suggestionsBox.classList.add('show');
            }

            function scheduleSuggestionLookup(term) {
                if (suggestionTimer) {
                    clearTimeout(suggestionTimer);
                }
                suggestionTimer = setTimeout(function() {
                    fetch('uretim_giris.php?ajax=search&term=' + encodeURIComponent(term), {
                            headers: {
                                'Accept': 'application/json'
                            }
                        })
                        .then(parseJsonSafely)
                        .then(function(res) {
                            if (res && res.ok) {
                                renderSuggestions(res.items || []);
                            } else {
                                clearSuggestions();
                            }
                        })
                        .catch(function(err) {
                            console.error('Arama yanıtı çözümlenemedi', err && err.responseText ? err
                                .responseText : err);
                            clearSuggestions();
                        });
                }, 250);
            }

            function lookup(value) {
                if (!value) {
                    return;
                }
                clearSuggestions();
                var modalVisible = modal.hasClass('show');
                if (modalVisible) {
                    barcodeInfo.textContent = 'Sorgulanıyor...';
                    barcodeUseBtn.disabled = true;
                }
                currentItem = null;
                fetch('uretim_giris.php?ajax=item&value=' + encodeURIComponent(value), {
                        headers: {
                            'Accept': 'application/json'
                        }
                    })
                    .then(parseJsonSafely)
                    .then(function(res) {
                        handleResponse(res, modalVisible);
                    })
                    .catch(function(err) {
                        var message = err && err.responseText ? err.responseText : err;
                        if (modalVisible) {
                            barcodeInfo.textContent = 'Hata: ' + message;
                            barcodeUseBtn.disabled = true;
                        } else {
                            urunBilgisi.innerHTML = '<span class="text-danger">' + escapeHtml(String(message)) +
                                '</span>';
                            if (koliInfo) {
                                koliInfo.value = '';
                            }
                            if (barkodHidden) {
                                barkodHidden.value = '';
                            }
                            if (stokRefHidden) {
                                stokRefHidden.value = '';
                            }
                        }
                    });
            }

            function handleResponse(res, modalVisible) {
                var message = (res && res.message) ? res.message : 'Ürün bulunamadı.';
                if (res && res.ok && res.item) {
                    currentItem = res.item;
                    if (modalVisible) {
                        barcodeInfo.innerHTML = '<strong>Kod:</strong> ' + escapeHtml(res.item.code) +
                            '<br><strong>Ad:</strong> ' + escapeHtml(res.item.name) +
                            '<br><strong>Koli içi:</strong> ' + formatNumber(res.item.koli_adet);
                        barcodeUseBtn.disabled = false;
                    } else {
                        barcodeInfo.textContent = '';
                    }
                    applyItem(res.item, modalVisible);
                    return;
                }
                currentItem = null;
                if (modalVisible) {
                    barcodeInfo.textContent = message;
                    barcodeUseBtn.disabled = true;
                } else {
                    urunBilgisi.innerHTML = '<span class="text-danger">' + escapeHtml(message) + '</span>';
                    if (koliInfo) {
                        koliInfo.value = '';
                    }
                    if (barkodHidden) {
                        barkodHidden.value = '';
                    }
                    if (stokRefHidden) {
                        stokRefHidden.value = '';
                    }
                }
            }

            function applyItem(item, setBarcode) {
                if (!item) {
                    return;
                }
                currentItem = item;
                if (stokRefHidden) {
                    stokRefHidden.value = item.logicalref || '';
                }
                stokInput.value = item.code || '';
                if (barkodHidden) {
                    barkodHidden.value = setBarcode ? (item.barcode || '') : '';
                }
                if (urunBilgisi) {
                    urunBilgisi.innerHTML = '<strong>Kod:</strong> ' + escapeHtml(item.code || '') +
                        '<br><strong>Ad:</strong> ' + escapeHtml(item.name || '') + '<br><strong>Koli içi:</strong> ' +
                        formatNumber(item.koli_adet || 0);
                }
                if (koliInfo) {
                    koliInfo.value = formatNumber(item.koli_adet || 0);
                }
                if (koliInput) {
                    if (!koliInput.value) {
                        koliInput.value = '1';
                    }
                    setTimeout(function() {
                        koliInput.focus();
                    }, 0);
                }
                clearSuggestions();
            }
            if (barcodeForm) {
                barcodeForm.addEventListener('submit', function(ev) {
                    ev.preventDefault();
                    lookup(barcodeInput.value.trim());
                });
            }
            if (barcodeUseBtn) {
                barcodeUseBtn.addEventListener('click', function() {
                    if (currentItem) {
                        applyItem(currentItem, true);
                        modal.modal('hide');

                    }
                });
            }
            if (kodLookupBtn) {
                kodLookupBtn.addEventListener('click', function() {
                    lookup(stokInput.value.trim());
                });
            }
            stokInput.addEventListener('input', function() {
                if (barkodHidden) {
                    barkodHidden.value = '';
                }
                if (stokRefHidden) {
                    stokRefHidden.value = '';
                }
                var term = this.value.trim();
                if (term.length >= 2) {
                    scheduleSuggestionLookup(term);
                } else {
                    clearSuggestions();
                }
            });
            stokInput.addEventListener('keydown', function(ev) {
                if (ev.key === 'Enter' && suggestionsBox && suggestionsBox.classList.contains('show')) {
                    ev.preventDefault();
                    var first = suggestionsBox.querySelector('.autocomplete-item');
                    if (first) {
                        first.click();
                    }
                }
            });
            document.addEventListener('click', function(ev) {
                if (!suggestionsBox || suggestionsBox.style.display !== 'block') {
                    return;
                }
                if (ev.target === stokInput || suggestionsBox.contains(ev.target)) {
                    return;
                }
                clearSuggestions();
            });
            modal.on('shown.bs.modal', function() {
                if (barcodeInput) {
                    barcodeInput.value = '';
                    barcodeInput.focus();
                }
                if (barcodeInfo) {
                    barcodeInfo.textContent = '';
                }
                barcodeUseBtn.disabled = true;
                currentItem = null;
            });
            modal.on('hidden.bs.modal', function() {
                if (barcodeInfo) {
                    barcodeInfo.textContent = '';
                }
                if (barcodeInput) {
                    barcodeInput.value = '';
                }
                barcodeUseBtn.disabled = true;
                currentItem = null;
            });
        })();
    </script>
    <div id="toastFlash" class="toast-flash"><i class="fa fa-check-circle"></i> <span id="toastMsg">Eklendi</span></div>
    <script>
    (function() {
        // --- Feature 1 & 2: Quick chip clicks ---
        var chips = document.querySelectorAll('.quick-chip');
        chips.forEach(function(chip) {
            chip.addEventListener('click', function() {
                var ref = parseInt(this.dataset.ref, 10) || 0;
                var code = this.dataset.code || '';
                var name = this.dataset.name || '';
                var cf2 = parseFloat(this.dataset.cf2) || 1;

                // Use the existing applyItem pattern via AJAX lookup
                fetch('uretim_giris.php?ajax=item&value=' + encodeURIComponent(code), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(response) { return response.json(); })
                .then(function(res) {
                    if (res && res.ok && res.item) {
                        // Call applyItem through the stok_kod input event system
                        var stokInput = document.getElementById('stok_kod');
                        var stokRefHidden = document.getElementById('stokref_hidden');
                        var barkodHidden = document.getElementById('form-barkod');
                        var urunBilgisi = document.getElementById('urunBilgisi');
                        var koliInfo = document.getElementById('koli_info');
                        var koliInput = document.getElementById('koli_input');
                        var item = res.item;

                        if (stokRefHidden) stokRefHidden.value = item.logicalref || '';
                        if (stokInput) stokInput.value = item.code || '';
                        if (barkodHidden) barkodHidden.value = '';
                        if (urunBilgisi) {
                            urunBilgisi.innerHTML = '<strong>Kod:</strong> ' + (item.code || '') +
                                '<br><strong>Ad:</strong> ' + (item.name || '') +
                                '<br><strong>Koli içi:</strong> ' + parseFloat(item.koli_adet || 0).toLocaleString('tr-TR', {minimumFractionDigits: 0, maximumFractionDigits: 3});
                        }
                        if (koliInfo) {
                            koliInfo.value = parseFloat(item.koli_adet || 0).toLocaleString('tr-TR', {minimumFractionDigits: 0, maximumFractionDigits: 3});
                        }
                        if (koliInput) {
                            if (!koliInput.value) koliInput.value = '1';
                            setTimeout(function() { koliInput.focus(); }, 0);
                        }
                    }
                })
                .catch(function(err) {
                    console.error('Chip lookup error', err);
                });
            });
        });

        // --- Feature 4: Çoklu Ekleme Modu ---
        var cokluToggle = document.getElementById('cokluToggle');
        var entryForm = document.getElementById('entryForm');
        var multiCounter = document.getElementById('multiCounter');
        var toastFlash = document.getElementById('toastFlash');
        var toastMsg = document.getElementById('toastMsg');
        var addCount = 0;

        // Read multi-add count from sessionStorage
        var sessionKey = 'cokluEkle_' + (<?php echo $fis_id; ?>);
        if (window.sessionStorage) {
            var stored = sessionStorage.getItem(sessionKey);
            if (stored) {
                addCount = parseInt(stored, 10) || 0;
            }
        }

        function updateCounter() {
            if (multiCounter && addCount > 0 && cokluToggle && cokluToggle.checked) {
                multiCounter.textContent = addCount + ' ürün eklendi';
                multiCounter.classList.add('show');
            } else if (multiCounter) {
                multiCounter.classList.remove('show');
            }
        }

        function showToast(msg) {
            if (!toastFlash) return;
            if (toastMsg) toastMsg.textContent = msg || 'Eklendi';
            toastFlash.classList.add('show');
            setTimeout(function() {
                toastFlash.classList.remove('show');
            }, 2000);
        }

        // If we returned from a hizli_ok or regular add with coklu mode active
        <?php if ($hizliOk && $cokluMod) { ?>
        if (window.sessionStorage) {
            addCount++;
            sessionStorage.setItem(sessionKey, addCount);
        }
        showToast('+1 koli eklendi');
        <?php } ?>

        updateCounter();

        if (cokluToggle) {
            cokluToggle.addEventListener('change', function() {
                if (!this.checked) {
                    addCount = 0;
                    if (window.sessionStorage) {
                        sessionStorage.removeItem(sessionKey);
                    }
                }
                updateCounter();
            });
        }

        if (entryForm && cokluToggle) {
            entryForm.addEventListener('submit', function(ev) {
                if (!cokluToggle.checked) return; // Normal submit

                ev.preventDefault();
                var formData = new FormData(entryForm);
                var stokKodValue = formData.get('stok_kod') || '';

                fetch('uretim_giris.php', {
                    method: 'POST',
                    body: formData
                }).then(function(response) {
                    if (response.ok || response.redirected) {
                        addCount++;
                        if (window.sessionStorage) {
                            sessionStorage.setItem(sessionKey, addCount);
                        }
                        updateCounter();
                        showToast('Eklendi! (' + addCount + ')');

                        // Keep stok_kod, reset koli
                        var koliInput = document.getElementById('koli_input');
                        if (koliInput) {
                            koliInput.value = '1';
                            koliInput.focus();
                        }

                        // Reload table (soft refresh) while preserving product
                        setTimeout(function() {
                            var url = 'uretim_giris.php?fis=<?php echo $fis_id; ?>&depogiris=<?php echo $depogiris; ?>&coklu=1&stok_remember=' + encodeURIComponent(stokKodValue);
                            window.location.href = url;
                        }, 300);
                    } else {
                        showToast('Hata oluştu!');
                    }
                }).catch(function(err) {
                    console.error('Çoklu ekleme hatası', err);
                    showToast('Hata oluştu!');
                });
            });
        }

        // Restore product after multi-add page reload
        var urlParams = new URLSearchParams(window.location.search);
        var rememberedStok = urlParams.get('stok_remember');
        if (rememberedStok && cokluToggle && cokluToggle.checked) {
            var stokInput = document.getElementById('stok_kod');
            if (stokInput) {
                stokInput.value = rememberedStok;
                // Trigger lookup to restore product info
                fetch('uretim_giris.php?ajax=item&value=' + encodeURIComponent(rememberedStok), {
                    headers: { 'Accept': 'application/json' }
                })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    if (res && res.ok && res.item) {
                        var item = res.item;
                        var stokRefHidden = document.getElementById('stokref_hidden');
                        var urunBilgisi = document.getElementById('urunBilgisi');
                        var koliInfo = document.getElementById('koli_info');
                        if (stokRefHidden) stokRefHidden.value = item.logicalref || '';
                        if (urunBilgisi) {
                            urunBilgisi.innerHTML = '<strong>Kod:</strong> ' + (item.code || '') +
                                '<br><strong>Ad:</strong> ' + (item.name || '') +
                                '<br><strong>Koli içi:</strong> ' + parseFloat(item.koli_adet || 0).toLocaleString('tr-TR', {minimumFractionDigits: 0, maximumFractionDigits: 3});
                        }
                        if (koliInfo) {
                            koliInfo.value = parseFloat(item.koli_adet || 0).toLocaleString('tr-TR', {minimumFractionDigits: 0, maximumFractionDigits: 3});
                        }
                        var koliInput = document.getElementById('koli_input');
                        if (koliInput) {
                            koliInput.value = '1';
                            setTimeout(function() { koliInput.focus(); }, 100);
                        }
                    }
                })
                .catch(function() {});
            }
        }
    })();
    </script>
</body>

</html>

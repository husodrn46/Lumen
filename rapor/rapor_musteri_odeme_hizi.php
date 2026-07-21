<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

function moh_money(float|int|string|null $amount): string
{
    $decimals = (int) ($GLOBALS['parakusurat'] ?? 2);
    return number_format((float) ($amount ?? 0), $decimals, ',', '.') . ' &#8378;';
}

function moh_date(mixed $value): string
{
    if ($value instanceof DateTimeInterface) {
        return $value->format('d.m.Y');
    }
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return '-';
    }
    $time = strtotime($text);
    return $time ? date('d.m.Y', $time) : $text;
}

function moh_int_param(string $name, int $default = 0): int
{
    return isset($_GET[$name]) && is_numeric($_GET[$name]) ? (int) $_GET[$name] : $default;
}

function moh_score_meta(?float $days): array
{
    if ($days === null) {
        return ['level' => 'open', 'label' => 'Acik', 'class' => 'score-open', 'icon' => 'fa-hourglass-half'];
    }
    if ($days <= 15) {
        return ['level' => 'good', 'label' => 'Cok iyi', 'class' => 'score-good', 'icon' => 'fa-circle-check'];
    }
    if ($days <= 45) {
        return ['level' => 'mid', 'label' => 'Orta', 'class' => 'score-mid', 'icon' => 'fa-gauge-high'];
    }
    return ['level' => 'bad', 'label' => 'Cok kotu', 'class' => 'score-bad', 'icon' => 'fa-triangle-exclamation'];
}

function moh_trcode_name(int $trcode): string
{
    return match ($trcode) {
        1 => 'Nakit tahsilat',
        4 => 'Alacak dekontu',
        20 => 'Gelen havale',
        61 => 'Cek girisi',
        62 => 'Senet girisi',
        70 => 'Kredi karti / POS',
        default => 'Tahsilat',
    };
}

function moh_avg_days(array $rows): ?float
{
    $sum = 0.0;
    $count = 0;
    foreach ($rows as $row) {
        if (($row['PAYMENT_DAYS'] ?? null) === null) {
            continue;
        }
        $sum += (float) $row['PAYMENT_DAYS'];
        $count++;
    }

    return $count > 0 ? $sum / $count : null;
}

function moh_sql_error(Throwable $e, string $scope): string
{
    if (function_exists('app_log_exception')) {
        return app_log_exception($e, $scope, []);
    }
    error_log($scope . ': ' . $e->getMessage());
    return $scope;
}

function moh_customer_exclude_filter(string $alias, string $paramPrefix): array
{
    // Hariç tutulacak kelimeler _bilgi_.inc'ten gelir ($rapor_haric_cari_kelimeleri).
    // Varsayılan boştur; tanımlı değilse hiçbir cari elenmez.
    global $rapor_haric_cari_kelimeleri;

    $terms = array_values(array_filter(
        array_map(
            static fn($t): string => mb_strtoupper(trim((string) $t), 'UTF-8'),
            (array) ($rapor_haric_cari_kelimeleri ?? [])
        ),
        static fn(string $t): bool => $t !== ''
    ));

    if ($terms === []) {
        return ['sql' => '1=1', 'params' => []];
    }

    $normCode = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$alias}.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS";
    $normName = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$alias}.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS";

    $sqlParts = [];
    $params = [];
    foreach ($terms as $index => $term) {
        $codeParam = ':' . $paramPrefix . '_exclude_code_' . $index;
        $nameParam = ':' . $paramPrefix . '_exclude_name_' . $index;
        $sqlParts[] = "({$normCode} NOT LIKE {$codeParam} AND {$normName} NOT LIKE {$nameParam})";
        $params[$codeParam] = '%' . $term . '%';
        $params[$nameParam] = '%' . $term . '%';
    }

    return [
        'sql' => implode("\n          AND ", $sqlParts),
        'params' => $params,
    ];
}

function moh_fetch_invoice_detail(PDO $dbh, string $firmadonem, string $firma, int|string $terminalkullanici, int $invoiceRef): void
{
    $hiddenFilter = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'C.LOGICALREF', 'moh_detail');
    $where = [
        'I.LOGICALREF = :ref',
        'I.CANCELLED = 0',
        'I.TRCODE IN (7, 8)',
    ];
    $params = [':ref' => $invoiceRef] + ($hiddenFilter['params'] ?? []);
    if (($hiddenFilter['sql'] ?? '') !== '') {
        $where[] = $hiddenFilter['sql'];
    }

    $stmt = $dbh->prepare("
        SELECT
            I.LOGICALREF,
            I.FICHENO,
            I.DATE_,
            I.NETTOTAL,
            I.TRCODE,
            C.CODE AS CARI_KODU,
            C.DEFINITION_ AS CARI_UNVANI
        FROM {$firmadonem}INVOICE I WITH(NOLOCK)
        JOIN {$firma}CLCARD C WITH(NOLOCK) ON C.LOGICALREF = I.CLIENTREF
        WHERE " . implode("\n          AND ", $where) . "
    ");
    $stmt->execute($params);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        echo '<div class="detail-empty">Fis bulunamadi.</div>';
        return;
    }

    $stmtLines = $dbh->prepare("
        SELECT
            ROW_NUMBER() OVER (ORDER BY L.LOGICALREF ASC) AS SIRA,
            I.CODE AS STOK_KODU,
            I.NAME AS STOK_ADI,
            L.AMOUNT AS MIKTAR,
            U.CODE AS BIRIM,
            L.PRICE AS FIYAT,
            L.LINENET AS NET,
            L.VATAMNT AS KDV,
            (ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0)) AS TUTAR
        FROM {$firmadonem}STLINE L WITH(NOLOCK)
        LEFT JOIN {$firma}ITEMS I WITH(NOLOCK) ON I.LOGICALREF = L.STOCKREF
        LEFT JOIN {$firma}UNITSETL U WITH(NOLOCK) ON U.LOGICALREF = L.UOMREF
        WHERE L.INVOICEREF = :ref
          AND L.CANCELLED = 0
          AND L.LINETYPE = 0
        ORDER BY L.LOGICALREF ASC
    ");
    $stmtLines->execute([':ref' => $invoiceRef]);
    $lines = $stmtLines->fetchAll(PDO::FETCH_ASSOC);

    echo '<div class="detail-head">';
    echo '<div><strong>' . htmlspecialchars((string) $invoice['FICHENO'], ENT_QUOTES, 'UTF-8') . '</strong>';
    echo '<span>' . htmlspecialchars((string) $invoice['CARI_UNVANI'], ENT_QUOTES, 'UTF-8') . '</span></div>';
    echo '<div class="detail-total">' . moh_money($invoice['NETTOTAL'] ?? 0) . '</div>';
    echo '</div>';

    echo '<div class="table-scroll"><table class="data-table compact"><thead><tr>';
    echo '<th>#</th><th>Stok</th><th class="col-right">Miktar</th><th>Birim</th><th class="col-right">Fiyat</th><th class="col-right">Tutar</th>';
    echo '</tr></thead><tbody>';
    foreach ($lines as $line) {
        echo '<tr>';
        echo '<td>' . (int) $line['SIRA'] . '</td>';
        echo '<td><div class="main-text">' . htmlspecialchars((string) ($line['STOK_ADI'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div><div class="muted mono">' . htmlspecialchars((string) ($line['STOK_KODU'] ?? ''), ENT_QUOTES, 'UTF-8') . '</div></td>';
        echo '<td class="col-right">' . number_format((float) ($line['MIKTAR'] ?? 0), 2, ',', '.') . '</td>';
        echo '<td>' . htmlspecialchars((string) ($line['BIRIM'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td class="col-right">' . moh_money($line['FIYAT'] ?? 0) . '</td>';
        echo '<td class="col-right strong">' . moh_money($line['TUTAR'] ?? 0) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

$ajax = isset($_GET['ajax']) ? trim((string) $_GET['ajax']) : '';
if ($ajax === 'invoice_detail') {
    $invoiceRef = moh_int_param('ref');
    if ($invoiceRef <= 0) {
        echo '<div class="detail-empty">Gecersiz fis.</div>';
        exit;
    }
    try {
        moh_fetch_invoice_detail($dbh, $firmadonem, $firma, $terminalkullanici, $invoiceRef);
    } catch (Throwable $e) {
        $ref = moh_sql_error($e, 'rapor_musteri_odeme_hizi:invoice_detail');
        echo '<div class="detail-empty">Detay alinamadi. Ref: ' . htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') . '</div>';
    }
    exit;
}

$currentYear = (int) date('Y');
$year = moh_int_param('year', $currentYear);
$year = max(2020, min($year, $currentYear + 1));
$startDate = sprintf('%04d-01-01', $year);
$endDate = sprintf('%04d-01-01', $year + 1);
$selectedCariId = moh_int_param('cariid');
$rawQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$q = $rawQ !== '' ? turkce($rawQ) : '';
$pageError = '';

$hiddenFilter = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'C.LOGICALREF', 'cari_liste_gizli');

$customerChoices = [];
$selectedCustomer = null;
$recentInvoices = [];
$payments = [];
$activityRows = [];
$lastInvoices = [];
$lastPayments = [];
$habitMeta = ['label' => 'Veri yok', 'class' => 'score-open', 'icon' => 'fa-circle-question', 'desc' => 'Yeterli kapanan fis yok'];
$trendMeta = ['label' => 'Veri yok', 'class' => 'score-open', 'icon' => 'fa-minus', 'desc' => 'Kiyas icin yeterli kapanan fis yok'];
$customerStats = [
    'sales_total' => 0.0,
    'paid_total' => 0.0,
    'invoice_count' => 0,
    'completed_count' => 0,
    'avg_days' => null,
    'open_total' => 0.0,
];
$leaderboard = [];

try {
    $choiceParams = $hiddenFilter['params'] ?? [];
    $choiceExcludeFilter = moh_customer_exclude_filter('C', 'choice');
    $choiceParams = array_merge($choiceParams, $choiceExcludeFilter['params']);
    $choiceWhere = [
        'C.ACTIVE = 0',
        'C.CARDTYPE IN (3, 10)',
        $choiceExcludeFilter['sql'],
    ];
    if (($hiddenFilter['sql'] ?? '') !== '') {
        $choiceWhere[] = $hiddenFilter['sql'];
    }
    if ($q !== '') {
        $choiceWhere[] = "
            (
                 REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(C.DEFINITION_, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS LIKE :cq1
              OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(C.CODE, 'Ğ','G'), 'ğ','g'), 'Ü','U'), 'ü','u'), 'Ş','S'), 'ş','s'), 'İ','I'), 'ı','i'), 'Ö','O'), 'ö','o'), 'Ç','C'), 'ç','c') COLLATE Turkish_CI_AS LIKE :cq2
            )
        ";
        $choiceParams[':cq1'] = '%' . $q . '%';
        $choiceParams[':cq2'] = '%' . $q . '%';
    }

    $choiceSql = "
        SELECT TOP 80
            C.LOGICALREF AS CARIID,
            C.CODE AS KODU,
            C.DEFINITION_ AS UNVANI,
            C.CITY AS SEHIR
        FROM {$firma}CLCARD C WITH(NOLOCK)
        WHERE " . implode("\n          AND ", $choiceWhere) . "
        ORDER BY C.DEFINITION_ ASC
    ";
    $stmt = $dbh->prepare($choiceSql);
    $stmt->execute($choiceParams);
    $customerChoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($selectedCariId > 0) {
        $customerParams = [':cariid' => $selectedCariId] + ($hiddenFilter['params'] ?? []);
        $customerExcludeFilter = moh_customer_exclude_filter('C', 'customer');
        $customerParams = array_merge($customerParams, $customerExcludeFilter['params']);
        $customerWhere = [
            'C.LOGICALREF = :cariid',
            'C.ACTIVE = 0',
            $customerExcludeFilter['sql'],
        ];
        if (($hiddenFilter['sql'] ?? '') !== '') {
            $customerWhere[] = $hiddenFilter['sql'];
        }
        $stmt = $dbh->prepare("
            SELECT
                C.LOGICALREF AS CARIID,
                C.CODE AS KODU,
                C.DEFINITION_ AS UNVANI,
                C.CITY AS SEHIR,
                C.TELNRS1 AS TEL
            FROM {$firma}CLCARD C WITH(NOLOCK)
            WHERE " . implode("\n              AND ", $customerWhere) . "
        ");
        $stmt->execute($customerParams);
        $selectedCustomer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$selectedCustomer) {
            $selectedCariId = 0;
        } else {
            $choiceIds = array_map(static fn(array $row): int => (int) $row['CARIID'], $customerChoices);
            if (!in_array((int) $selectedCustomer['CARIID'], $choiceIds, true)) {
                array_unshift($customerChoices, $selectedCustomer);
            }
        }
    }
} catch (Throwable $e) {
    $pageError = 'Musteri listesi alinamadi. Ref: ' . moh_sql_error($e, 'rapor_musteri_odeme_hizi:customers');
}

$invoiceSpeedSelect = "
    S.*,
    LF.LEDGER_AMOUNT,
    CASE
        WHEN PAY.COMPLETE_DATE IS NULL THEN NULL
        WHEN PAY.COMPLETE_DATE < S.TARIH THEN CAST(S.TARIH AS DATE)
        ELSE CAST(PAY.COMPLETE_DATE AS DATE)
    END AS COMPLETE_DATE,
    CASE
        WHEN PAY.COMPLETE_DATE IS NULL THEN NULL
        WHEN PAY.COMPLETE_DATE < S.TARIH THEN 0
        ELSE DATEDIFF(DAY, S.TARIH, PAY.COMPLETE_DATE)
    END AS PAYMENT_DAYS
";

$invoiceSpeedApply = "
    OUTER APPLY (
        SELECT TOP 1
            L.LOGICALREF AS LEDGER_REF,
            L.DATE_ AS LEDGER_DATE,
            L.AMOUNT AS LEDGER_AMOUNT
        FROM {$firmadonem}CLFLINE L WITH(NOLOCK)
        LEFT JOIN {$firmadonem}STFICHE STF WITH(NOLOCK) ON STF.LOGICALREF = L.SOURCEFREF
        WHERE L.CLIENTREF = S.CARIID
          AND (L.SOURCEFREF = S.INVOICE_REF OR STF.INVOICEREF = S.INVOICE_REF)
          AND L.SIGN = 0
          AND L.CANCELLED = 0
        ORDER BY L.DATE_ ASC, L.LOGICALREF ASC
    ) LF
    OUTER APPLY (
        SELECT SUM(D.AMOUNT) AS TARGET_DEBT
        FROM {$firmadonem}CLFLINE D WITH(NOLOCK)
        WHERE D.CLIENTREF = S.CARIID
          AND D.SIGN = 0
          AND D.CANCELLED = 0
          AND LF.LEDGER_REF IS NOT NULL
          AND (
                D.DATE_ < LF.LEDGER_DATE
             OR (D.DATE_ = LF.LEDGER_DATE AND D.LOGICALREF <= LF.LEDGER_REF)
          )
    ) TD
    OUTER APPLY (
        SELECT TOP 1 P.DATE_ AS COMPLETE_DATE
        FROM (
            SELECT
                P.DATE_,
                P.LOGICALREF,
                SUM(P.AMOUNT) OVER (ORDER BY P.DATE_ ASC, P.LOGICALREF ASC ROWS UNBOUNDED PRECEDING) AS CUM_CREDIT
            FROM {$firmadonem}CLFLINE P WITH(NOLOCK)
            WHERE P.CLIENTREF = S.CARIID
              AND P.SIGN = 1
              AND P.CANCELLED = 0
        ) P
        WHERE TD.TARGET_DEBT IS NOT NULL
          AND P.CUM_CREDIT >= TD.TARGET_DEBT
        ORDER BY P.DATE_ ASC, P.LOGICALREF ASC
    ) PAY
";

if ($selectedCariId > 0) {
    try {
        $sqlRecent = "
            WITH S AS (
                SELECT TOP 30
                    I.CLIENTREF AS CARIID,
                    I.LOGICALREF AS INVOICE_REF,
                    I.FICHENO,
                    CAST(I.DATE_ AS DATE) AS TARIH,
                    I.TRCODE,
                    COUNT(CASE WHEN L.LINETYPE = 0 THEN 1 END) AS SATIR_SAYISI,
                    SUM(CASE WHEN L.LINETYPE = 0 THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0) ELSE 0 END) AS SATIS_TUTAR
                FROM {$firmadonem}INVOICE I WITH(NOLOCK)
                LEFT JOIN {$firmadonem}STLINE L WITH(NOLOCK) ON L.INVOICEREF = I.LOGICALREF AND L.CANCELLED = 0
                WHERE I.CLIENTREF = :cariid
                  AND I.CANCELLED = 0
                  AND I.TRCODE IN (7, 8)
                  AND I.DATE_ >= :startDate
                  AND I.DATE_ < :endDate
                GROUP BY I.CLIENTREF, I.LOGICALREF, I.FICHENO, I.DATE_, I.TRCODE
                ORDER BY I.DATE_ DESC, I.LOGICALREF DESC
            )
            SELECT {$invoiceSpeedSelect}
            FROM S
            {$invoiceSpeedApply}
            ORDER BY S.TARIH DESC, S.INVOICE_REF DESC
        ";
        $stmt = $dbh->prepare($sqlRecent);
        $stmt->execute([
            ':cariid' => $selectedCariId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ]);
        $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sqlStats = "
            WITH S AS (
                SELECT
                    I.CLIENTREF AS CARIID,
                    I.LOGICALREF AS INVOICE_REF,
                    CAST(I.DATE_ AS DATE) AS TARIH,
                    SUM(CASE WHEN L.LINETYPE = 0 THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0) ELSE 0 END) AS SATIS_TUTAR
                FROM {$firmadonem}INVOICE I WITH(NOLOCK)
                LEFT JOIN {$firmadonem}STLINE L WITH(NOLOCK) ON L.INVOICEREF = I.LOGICALREF AND L.CANCELLED = 0
                WHERE I.CLIENTREF = :cariid
                  AND I.CANCELLED = 0
                  AND I.TRCODE IN (7, 8)
                  AND I.DATE_ >= :startDate
                  AND I.DATE_ < :endDate
                GROUP BY I.CLIENTREF, I.LOGICALREF, I.DATE_
            ),
            SPEED AS (
                SELECT {$invoiceSpeedSelect}
                FROM S
                {$invoiceSpeedApply}
            )
            SELECT
                COUNT(*) AS INVOICE_COUNT,
                SUM(ISNULL(SATIS_TUTAR, 0)) AS SALES_TOTAL,
                SUM(CASE WHEN PAYMENT_DAYS IS NOT NULL THEN ISNULL(SATIS_TUTAR, 0) ELSE 0 END) AS PAID_TOTAL,
                SUM(CASE WHEN PAYMENT_DAYS IS NULL THEN ISNULL(SATIS_TUTAR, 0) ELSE 0 END) AS OPEN_TOTAL,
                SUM(CASE WHEN PAYMENT_DAYS IS NOT NULL THEN 1 ELSE 0 END) AS COMPLETED_COUNT,
                AVG(CASE WHEN PAYMENT_DAYS IS NOT NULL THEN CAST(PAYMENT_DAYS AS FLOAT) END) AS AVG_DAYS
            FROM SPEED
        ";
        $stmt = $dbh->prepare($sqlStats);
        $stmt->execute([
            ':cariid' => $selectedCariId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ]);
        $statRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $customerStats = [
            'sales_total' => (float) ($statRow['SALES_TOTAL'] ?? 0),
            'paid_total' => (float) ($statRow['PAID_TOTAL'] ?? 0),
            'invoice_count' => (int) ($statRow['INVOICE_COUNT'] ?? 0),
            'completed_count' => (int) ($statRow['COMPLETED_COUNT'] ?? 0),
            'avg_days' => $statRow['AVG_DAYS'] !== null ? (float) $statRow['AVG_DAYS'] : null,
            'open_total' => (float) ($statRow['OPEN_TOTAL'] ?? 0),
        ];

        $stmt = $dbh->prepare("
            SELECT TOP 30
                CF.DATE_ AS TARIH,
                CF.TRCODE,
                CF.AMOUNT AS TUTAR,
                CF.LINEEXP AS ACIKLAMA,
                CF.TRANNO
            FROM {$firmadonem}CLFLINE CF WITH(NOLOCK)
            WHERE CF.CLIENTREF = :cariid
              AND CF.TRCODE IN (1, 4, 20, 61, 62, 70)
              AND CF.SIGN = 1
              AND CF.CANCELLED = 0
              AND CF.DATE_ >= :startDate
              AND CF.DATE_ < :endDate
            ORDER BY CF.DATE_ DESC, CF.LOGICALREF DESC
        ");
        $stmt->execute([
            ':cariid' => $selectedCariId,
            ':startDate' => $startDate,
            ':endDate' => $endDate,
        ]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recentInvoices as $invoice) {
            $activityRows[] = [
                'type' => 'invoice',
                'date' => $invoice['TARIH'] ?? null,
                'sort' => strtotime((string) ($invoice['TARIH'] ?? '')) ?: 0,
                'data' => $invoice,
            ];
        }
        foreach ($payments as $payment) {
            $activityRows[] = [
                'type' => 'payment',
                'date' => $payment['TARIH'] ?? null,
                'sort' => strtotime((string) ($payment['TARIH'] ?? '')) ?: 0,
                'data' => $payment,
            ];
        }
        usort($activityRows, static function (array $a, array $b): int {
            $dateCompare = ($b['sort'] <=> $a['sort']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return strcmp((string) $a['type'], (string) $b['type']);
        });

        $lastInvoices = array_slice($recentInvoices, 0, 3);
        $lastPayments = array_slice($payments, 0, 3);

        $completedInvoices = array_values(array_filter(
            $recentInvoices,
            static fn(array $invoice): bool => ($invoice['PAYMENT_DAYS'] ?? null) !== null
        ));
        $recentCompletedAvg = moh_avg_days(array_slice($completedInvoices, 0, 3));
        $previousCompletedAvg = moh_avg_days(array_slice($completedInvoices, 3));

        if ($customerStats['avg_days'] !== null) {
            $avgDays = (float) $customerStats['avg_days'];
            if ($customerStats['open_total'] > 0 && $avgDays > 45) {
                $habitMeta = ['label' => 'Riskli', 'class' => 'score-bad', 'icon' => 'fa-triangle-exclamation', 'desc' => 'Gec kapatma ve acik hacim var'];
            } elseif ($avgDays <= 7) {
                $habitMeta = ['label' => 'Pesinci', 'class' => 'score-good', 'icon' => 'fa-bolt', 'desc' => 'Genelde ilk hafta kapatiyor'];
            } elseif ($avgDays <= 15) {
                $habitMeta = ['label' => '15 guncu', 'class' => 'score-good', 'icon' => 'fa-circle-check', 'desc' => 'Kisa vadede odeme disiplini iyi'];
            } elseif ($avgDays <= 30) {
                $habitMeta = ['label' => '30 guncu', 'class' => 'score-mid', 'icon' => 'fa-calendar-check', 'desc' => 'Ortalama odeme vadesi 1 ay civari'];
            } elseif ($avgDays <= 45) {
                $habitMeta = ['label' => 'Gecikmeli', 'class' => 'score-mid', 'icon' => 'fa-clock', 'desc' => 'Takip edilmesi gereken odeme ritmi'];
            } else {
                $habitMeta = ['label' => 'Riskli', 'class' => 'score-bad', 'icon' => 'fa-triangle-exclamation', 'desc' => 'Odeme kapatma suresi uzun'];
            }
        }

        if ($recentCompletedAvg !== null && $previousCompletedAvg !== null) {
            $diff = $recentCompletedAvg - $previousCompletedAvg;
            if ($diff <= -3) {
                $trendMeta = [
                    'label' => 'Hizlandi',
                    'class' => 'score-good',
                    'icon' => 'fa-arrow-trend-up',
                    'desc' => number_format(abs($diff), 1, ',', '.') . ' gun iyilesme',
                ];
            } elseif ($diff >= 3) {
                $trendMeta = [
                    'label' => 'Yavasladi',
                    'class' => 'score-bad',
                    'icon' => 'fa-arrow-trend-down',
                    'desc' => number_format($diff, 1, ',', '.') . ' gun gecikme',
                ];
            } else {
                $trendMeta = [
                    'label' => 'Ayni',
                    'class' => 'score-mid',
                    'icon' => 'fa-arrows-left-right',
                    'desc' => 'Son kapanan fisler eski ritme yakin',
                ];
            }
        } elseif ($recentCompletedAvg !== null) {
            $trendMeta = [
                'label' => 'Yeni veri',
                'class' => 'score-mid',
                'icon' => 'fa-chart-line',
                'desc' => 'Son 3 kapanan ortalama: ' . number_format($recentCompletedAvg, 1, ',', '.') . ' gun',
            ];
        }
    } catch (Throwable $e) {
        $pageError = 'Musteri detaylari alinamadi. Ref: ' . moh_sql_error($e, 'rapor_musteri_odeme_hizi:selected');
    }
}

try {
    $leaderParams = [
        ':startDate' => $startDate,
        ':endDate' => $endDate,
    ] + ($hiddenFilter['params'] ?? []);
    $leaderExcludeFilter = moh_customer_exclude_filter('C', 'leader');
    $leaderParams = array_merge($leaderParams, $leaderExcludeFilter['params']);
    $leaderWhere = [
        'C.ACTIVE = 0',
        'C.CARDTYPE IN (3, 10)',
        $leaderExcludeFilter['sql'],
    ];
    if (($hiddenFilter['sql'] ?? '') !== '') {
        $leaderWhere[] = $hiddenFilter['sql'];
    }

    $sqlLeader = "
        WITH S AS (
            SELECT
                C.LOGICALREF AS CARIID,
                C.CODE AS KODU,
                C.DEFINITION_ AS UNVANI,
                C.CITY AS SEHIR,
                I.LOGICALREF AS INVOICE_REF,
                CAST(I.DATE_ AS DATE) AS TARIH,
                SUM(CASE WHEN L.LINETYPE = 0 THEN ISNULL(L.LINENET, 0) + ISNULL(L.VATAMNT, 0) ELSE 0 END) AS SATIS_TUTAR
            FROM {$firmadonem}INVOICE I WITH(NOLOCK)
            JOIN {$firma}CLCARD C WITH(NOLOCK) ON C.LOGICALREF = I.CLIENTREF
            LEFT JOIN {$firmadonem}STLINE L WITH(NOLOCK) ON L.INVOICEREF = I.LOGICALREF AND L.CANCELLED = 0
            WHERE " . implode("\n              AND ", $leaderWhere) . "
              AND I.CANCELLED = 0
              AND I.TRCODE IN (7, 8)
              AND I.DATE_ >= :startDate
              AND I.DATE_ < :endDate
            GROUP BY C.LOGICALREF, C.CODE, C.DEFINITION_, C.CITY, I.LOGICALREF, I.DATE_
        ),
        SPEED AS (
            SELECT {$invoiceSpeedSelect}
            FROM S
            {$invoiceSpeedApply}
        )
        SELECT
            CARIID,
            KODU,
            UNVANI,
            SEHIR,
            COUNT(*) AS INVOICE_COUNT,
            SUM(ISNULL(SATIS_TUTAR, 0)) AS SALES_TOTAL,
            SUM(CASE WHEN PAYMENT_DAYS IS NOT NULL THEN 1 ELSE 0 END) AS COMPLETED_COUNT,
            AVG(CASE WHEN PAYMENT_DAYS IS NOT NULL THEN CAST(PAYMENT_DAYS AS FLOAT) END) AS AVG_DAYS,
            SUM(CASE WHEN PAYMENT_DAYS IS NOT NULL THEN ISNULL(SATIS_TUTAR, 0) ELSE 0 END) AS PAID_VOLUME
        FROM SPEED
        GROUP BY CARIID, KODU, UNVANI, SEHIR
        HAVING SUM(ISNULL(SATIS_TUTAR, 0)) > 0
    ";
    $stmt = $dbh->prepare($sqlLeader);
    $stmt->execute($leaderParams);
    $leaderRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($leaderRows as &$row) {
        $avg = $row['AVG_DAYS'] !== null ? (float) $row['AVG_DAYS'] : null;
        $row['SALES_TOTAL'] = (float) ($row['SALES_TOTAL'] ?? 0);
        $row['PAID_VOLUME'] = (float) ($row['PAID_VOLUME'] ?? 0);
        $row['INVOICE_COUNT'] = (int) ($row['INVOICE_COUNT'] ?? 0);
        $row['COMPLETED_COUNT'] = (int) ($row['COMPLETED_COUNT'] ?? 0);
        $row['AVG_DAYS'] = $avg;
        $row['COMBO_SCORE'] = $avg !== null ? ($row['PAID_VOLUME'] / max(1.0, $avg + 1.0)) : 0.0;
    }
    unset($row);

    $positiveSpeedRows = array_values(array_filter(
        $leaderRows,
        static fn(array $row): bool => $row['AVG_DAYS'] !== null && (float) $row['AVG_DAYS'] > 0
    ));

    $leaderboard = [
        'fast' => $positiveSpeedRows,
        'volume' => $leaderRows,
        'combo' => $positiveSpeedRows,
    ];

    usort($leaderboard['fast'], static function (array $a, array $b): int {
        if ($a['AVG_DAYS'] === null && $b['AVG_DAYS'] === null) {
            return $b['PAID_VOLUME'] <=> $a['PAID_VOLUME'];
        }
        if ($a['AVG_DAYS'] === null) {
            return 1;
        }
        if ($b['AVG_DAYS'] === null) {
            return -1;
        }
        return [$a['AVG_DAYS'], -$a['PAID_VOLUME']] <=> [$b['AVG_DAYS'], -$b['PAID_VOLUME']];
    });
    usort($leaderboard['volume'], static fn(array $a, array $b): int => $b['SALES_TOTAL'] <=> $a['SALES_TOTAL']);
    usort($leaderboard['combo'], static fn(array $a, array $b): int => $b['COMBO_SCORE'] <=> $a['COMBO_SCORE']);

    $leaderboard['fast'] = array_slice($leaderboard['fast'], 0, 50);
    $leaderboard['volume'] = array_slice($leaderboard['volume'], 0, 50);
    $leaderboard['combo'] = array_slice($leaderboard['combo'], 0, 50);
} catch (Throwable $e) {
    if ($pageError === '') {
        $pageError = 'Liderlik tablosu alinamadi. Ref: ' . moh_sql_error($e, 'rapor_musteri_odeme_hizi:leaderboard');
    }
}

$avgMeta = moh_score_meta($customerStats['avg_days']);
$paidRate = $customerStats['sales_total'] > 0 ? ($customerStats['paid_total'] / $customerStats['sales_total']) * 100 : 0.0;
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Musteri Odeme Hizi</title>
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once(__DIR__ . '/../pwa-header.php'); } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --surface: #ffffff;
            --text-1: #1f2937;
            --text-2: #4b5563;
            --text-3: #6b7280;
            --border: #dfe4ea;
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
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }
        .top-header {
            position: sticky; top: 0; z-index: 40;
            height: 64px;
            background: rgba(255,255,255,0.9);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(79, 70, 229, 0.18);
            box-shadow: 0 2px 8px rgba(79, 70, 229, 0.05);
        }
        .header-inner {
            max-width: 1240px; margin: 0 auto;
            height: 100%; display: flex; align-items: center; gap: 14px;
            padding: 0 24px;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all .2s ease;
        }
        .header-back:hover { background: var(--indigo-soft); color: var(--indigo); }
        .header-divider { width: 1px; height: 24px; background: var(--border); }
        .header-title {
            display: inline-flex; align-items: center; gap: 8px;
            font-size: 18px; font-weight: 700; color: var(--text-1);
        }
        .header-title .material-icons { color: var(--indigo); font-size: 20px; }
        .header-spacer { flex: 1; }
        .header-chip {
            padding: 6px 11px; border-radius: 999px;
            background: var(--indigo-soft); color: var(--indigo);
            font-size: 12px; font-weight: 700;
            border: 1px solid rgba(79,70,229,.16);
        }
        main.page { max-width: 1240px; margin: 0 auto; padding: 22px 24px 60px; }
        .filter-card, .panel, .leader-card, .stat-card {
            background: rgba(255,255,255,.94);
            border: 1px solid rgba(203,213,225,.92);
            box-shadow: 0 10px 26px rgba(15,23,42,.055);
        }
        .filter-card {
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 18px;
        }
        .filter-form {
            display: grid;
            grid-template-columns: 1fr 2fr 140px auto;
            gap: 10px;
            align-items: end;
        }
        .field label {
            display: block;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 6px;
        }
        .control {
            width: 100%;
            height: 42px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #fff;
            color: var(--text-1);
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 13px;
            padding: 0 12px;
            outline: none;
        }
        .control:focus {
            border-color: rgba(79,70,229,.55);
            box-shadow: 0 0 0 3px rgba(79,70,229,.1);
        }
        .btn {
            height: 42px;
            border: 0;
            border-radius: 10px;
            padding: 0 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn-primary { background: var(--indigo); color: #fff; }
        .btn-soft { background: var(--indigo-soft); color: var(--indigo); border: 1px solid rgba(79,70,229,.18); }
        .notice {
            margin-bottom: 16px;
            border-radius: 12px;
            padding: 12px 14px;
            background: var(--red-soft);
            color: #991b1b;
            border: 1px solid rgba(111,16,34,.18);
            font-size: 13px;
        }
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .stat-card {
            position: relative;
            border-radius: 14px;
            padding: 15px 16px 15px 20px;
            display: flex;
            gap: 12px;
            align-items: center;
            overflow: hidden;
        }
        .stat-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .tone-sky::before { background: var(--sky); }
        .tone-emerald::before { background: var(--emerald); }
        .tone-amber::before { background: var(--amber); }
        .tone-indigo::before { background: var(--indigo); }
        .stat-icon {
            width: 42px; height: 42px; border-radius: 11px;
            display: inline-flex; align-items: center; justify-content: center;
            flex: 0 0 auto; font-size: 18px;
        }
        .tone-sky .stat-icon { background: var(--sky-soft); color: var(--sky); }
        .tone-emerald .stat-icon { background: var(--emerald-soft); color: var(--emerald); }
        .tone-amber .stat-icon { background: var(--amber-soft); color: var(--amber); }
        .tone-indigo .stat-icon { background: var(--indigo-soft); color: var(--indigo); }
        .stat-label { font-size: 10px; color: var(--text-3); font-weight: 700; text-transform: uppercase; letter-spacing: .5px; }
        .stat-value { font-size: 20px; font-weight: 700; line-height: 1.2; margin-top: 3px; }
        .stat-sub { font-size: 11px; color: var(--text-3); margin-top: 2px; }
        .insight-grid {
            display: grid;
            grid-template-columns: .82fr 1.18fr 1.18fr;
            gap: 14px;
            margin-bottom: 18px;
        }
        .insight-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(15,23,42,.045);
        }
        .insight-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 12px 14px;
            border-bottom: 1px solid #eef2f7;
        }
        .insight-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 12.5px;
            font-weight: 800;
        }
        .insight-title i { color: var(--indigo); }
        .insight-body { padding: 12px 14px; }
        .mini-flow { display: grid; gap: 9px; }
        .mini-row {
            display: grid;
            grid-template-columns: minmax(82px, .8fr) minmax(0, 1.2fr) auto;
            align-items: center;
            gap: 10px;
            font-size: 12px;
        }
        .mini-date { color: var(--text-3); font-weight: 600; }
        .mini-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 700; }
        .mini-val { font-weight: 800; white-space: nowrap; text-align: right; }
        .selected-strip {
            display: flex; align-items: center; justify-content: space-between; gap: 14px;
            margin-bottom: 14px;
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 13px 16px;
        }
        .selected-title { font-size: 15px; font-weight: 700; }
        .selected-meta { font-size: 12px; color: var(--text-3); margin-top: 3px; }
        .score-pill {
            display: inline-flex; align-items: center; gap: 6px;
            border-radius: 999px; padding: 7px 11px;
            font-size: 12px; font-weight: 700;
            border: 1px solid transparent;
        }
        .score-good { color: var(--emerald); background: var(--emerald-soft); border-color: rgba(5,150,105,.18); }
        .score-mid { color: var(--amber); background: var(--amber-soft); border-color: rgba(217,119,6,.2); }
        .score-bad { color: var(--red); background: var(--red-soft); border-color: rgba(111,16,34,.18); }
        .score-open { color: var(--text-2); background: #f1f5f9; border-color: #e2e8f0; }
        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(340px, .65fr);
            gap: 16px;
            align-items: start;
        }
        .panel, .leader-card {
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        .panel-head, .leader-head {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 15px 18px;
            border-bottom: 1px solid var(--border);
        }
        .panel-title, .leader-title {
            display: inline-flex; align-items: center; gap: 9px;
            font-size: 14px; font-weight: 700;
        }
        .panel-title i, .leader-title i { color: var(--indigo); }
        .panel-sub { font-size: 11px; color: var(--text-3); }
        .table-scroll { overflow-x: auto; }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .data-table th {
            text-align: left;
            padding: 10px 12px;
            font-size: 10px;
            color: var(--text-3);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .45px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
        }
        .data-table td {
            padding: 11px 12px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: middle;
        }
        .data-table tbody tr:hover { background: #f8fafc; }
        .data-table.compact td { padding: 9px 10px; }
        .col-right { text-align: right; }
        .main-text { font-weight: 700; color: var(--text-1); }
        .muted { color: var(--text-3); font-size: 11px; }
        .mono { font-family: Consolas, 'Courier New', monospace; }
        .strong { font-weight: 700; }
        .invoice-btn {
            border: 0;
            background: transparent;
            color: var(--indigo);
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-weight: 700;
            cursor: pointer;
            padding: 0;
        }
        .invoice-btn:hover { text-decoration: underline; }
        .empty-state {
            padding: 28px 18px;
            text-align: center;
            color: var(--text-3);
            font-size: 13px;
        }
        .empty-state i { display: block; font-size: 28px; margin-bottom: 8px; color: #cbd5e1; }
        .leader-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-top: 4px;
        }
        .leader-card { margin-bottom: 0; }
        .leader-list { padding: 8px 0; }
        .leader-row {
            display: grid;
            grid-template-columns: 32px minmax(0, 1fr) auto;
            gap: 9px;
            padding: 10px 14px;
            align-items: center;
            border-bottom: 1px solid #eef2f7;
            color: inherit;
            text-decoration: none;
        }
        .leader-row:last-child { border-bottom: 0; }
        .rank {
            width: 26px; height: 26px; border-radius: 8px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #f1f5f9; color: var(--text-2);
            font-size: 12px; font-weight: 800;
        }
        .rank.top { background: var(--amber-soft); color: var(--amber); }
        .leader-name { font-size: 12.5px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .leader-meta { font-size: 10.5px; color: var(--text-3); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .leader-value { text-align: right; font-size: 12px; font-weight: 800; white-space: nowrap; }
        .leader-value small { display: block; color: var(--text-3); font-size: 10px; font-weight: 600; margin-top: 2px; }
        .method-note {
            margin-top: 16px;
            padding: 12px 14px;
            border-radius: 12px;
            background: #fff;
            border: 1px solid var(--border);
            color: var(--text-2);
            font-size: 12px;
            line-height: 1.55;
        }
        .modal-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.42);
            z-index: 80;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .modal-backdrop.show { display: flex; }
        .modal {
            width: min(920px, 100%);
            max-height: 86vh;
            overflow: auto;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 70px rgba(15,23,42,.25);
        }
        .modal-head {
            position: sticky; top: 0; z-index: 1;
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 16px;
            background: #fff;
            border-bottom: 1px solid var(--border);
        }
        .modal-title { font-size: 14px; font-weight: 800; display: inline-flex; align-items: center; gap: 8px; }
        .modal-close {
            width: 34px; height: 34px; border-radius: 9px;
            border: 1px solid var(--border); background: #fff;
            cursor: pointer; color: var(--text-2);
        }
        .modal-body { padding: 16px; }
        .detail-head {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 12px;
            background: #f8fafc;
        }
        .detail-head span { display: block; color: var(--text-3); font-size: 11px; margin-top: 2px; }
        .detail-total { font-weight: 800; color: var(--indigo); white-space: nowrap; }
        .detail-empty { padding: 26px; text-align: center; color: var(--text-3); }
        @media (max-width: 980px) {
            .filter-form, .content-grid, .leader-grid, .stat-grid, .insight-grid { grid-template-columns: 1fr; }
            .header-chip { display: none; }
        }
        @media (max-width: 640px) {
            .top-header { height: 54px; }
            .header-inner { padding: 0 12px; gap: 10px; }
            .header-title { font-size: 15px; }
            main.page { padding: 16px 12px 40px; }
            .selected-strip { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
<header class="top-header">
    <div class="header-inner">
        <a class="header-back" href="dashboard.php" title="Geri"><i class="fa-solid fa-arrow-left"></i></a>
        <div class="header-divider"></div>
        <div class="header-title"><span class="material-icons">speed</span> Musteri Odeme Hizi</div>
        <div class="header-spacer"></div>
        <div class="header-chip"><?php echo (int) $year; ?> performansi</div>
    </div>
</header>

<main class="page">
    <?php if ($pageError !== ''): ?>
        <div class="notice"><?php echo htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="filter-card">
        <form class="filter-form" method="get">
            <div class="field">
                <label for="q">Musteri ara</label>
                <input class="control" id="q" name="q" value="<?php echo htmlspecialchars($rawQ, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Kod veya unvan">
            </div>
            <div class="field">
                <label for="cariid">Musteri sec</label>
                <select class="control" id="cariid" name="cariid">
                    <option value="0">Musteri seciniz</option>
                    <?php foreach ($customerChoices as $choice): ?>
                        <option value="<?php echo (int) $choice['CARIID']; ?>" <?php echo (int) $choice['CARIID'] === $selectedCariId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string) $choice['KODU'] . ' - ' . (string) $choice['UNVANI'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="year">Yil</label>
                <input class="control" id="year" name="year" type="number" min="2020" max="<?php echo $currentYear + 1; ?>" value="<?php echo (int) $year; ?>">
            </div>
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Getir</button>
        </form>
    </section>

    <?php if ($selectedCustomer): ?>
        <section class="selected-strip">
            <div>
                <div class="selected-title"><?php echo htmlspecialchars((string) $selectedCustomer['UNVANI'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="selected-meta">
                    <?php echo htmlspecialchars((string) $selectedCustomer['KODU'], ENT_QUOTES, 'UTF-8'); ?>
                    <?php if (!empty($selectedCustomer['SEHIR'])): ?> · <?php echo htmlspecialchars((string) $selectedCustomer['SEHIR'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                    <?php if (!empty($selectedCustomer['TEL'])): ?> · <?php echo htmlspecialchars((string) $selectedCustomer['TEL'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?>
                </div>
            </div>
            <span class="score-pill <?php echo $avgMeta['class']; ?>">
                <i class="fa-solid <?php echo $avgMeta['icon']; ?>"></i>
                <?php echo htmlspecialchars($avgMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($customerStats['avg_days'] !== null): ?>
                    · <?php echo number_format((float) $customerStats['avg_days'], 1, ',', '.'); ?> gun
                <?php endif; ?>
            </span>
        </section>

        <section class="stat-grid">
            <div class="stat-card tone-sky">
                <div class="stat-icon"><i class="fa-solid fa-file-invoice"></i></div>
                <div><div class="stat-label">Mal alimi</div><div class="stat-value"><?php echo moh_money($customerStats['sales_total']); ?></div><div class="stat-sub"><?php echo (int) $customerStats['invoice_count']; ?> fis</div></div>
            </div>
            <div class="stat-card tone-emerald">
                <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div><div class="stat-label">Kapanan hacim</div><div class="stat-value"><?php echo moh_money($customerStats['paid_total']); ?></div><div class="stat-sub">%<?php echo number_format($paidRate, 1, ',', '.'); ?> kapanan</div></div>
            </div>
            <div class="stat-card tone-amber">
                <div class="stat-icon"><i class="fa-solid fa-stopwatch"></i></div>
                <div><div class="stat-label">Ortalama hiz</div><div class="stat-value"><?php echo $customerStats['avg_days'] === null ? '-' : number_format((float) $customerStats['avg_days'], 1, ',', '.') . ' gun'; ?></div><div class="stat-sub">15 iyi · 45 orta · 45+ kotu</div></div>
            </div>
            <div class="stat-card tone-indigo">
                <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
                <div><div class="stat-label">Acik hacim</div><div class="stat-value"><?php echo moh_money($customerStats['open_total']); ?></div><div class="stat-sub"><?php echo (int) $customerStats['completed_count']; ?> kapanan fis</div></div>
            </div>
        </section>

        <section class="insight-grid">
            <div class="insight-card">
                <div class="insight-head">
                    <div class="insight-title"><i class="fa-solid fa-fingerprint"></i> Odeme aliskanligi</div>
                </div>
                <div class="insight-body">
                    <span class="score-pill <?php echo $habitMeta['class']; ?>">
                        <i class="fa-solid <?php echo $habitMeta['icon']; ?>"></i>
                        <?php echo htmlspecialchars($habitMeta['label'], ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <div class="muted" style="margin-top:8px;"><?php echo htmlspecialchars($habitMeta['desc'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
            <div class="insight-card">
                <div class="insight-head">
                    <div class="insight-title"><i class="fa-solid fa-basket-shopping"></i> Son 3 alis</div>
                </div>
                <div class="insight-body">
                    <?php if ($lastInvoices === []): ?>
                        <div class="muted">Fis yok.</div>
                    <?php else: ?>
                        <div class="mini-flow">
                            <?php foreach ($lastInvoices as $invoice): ?>
                                <div class="mini-row">
                                    <span class="mini-date"><?php echo moh_date($invoice['TARIH'] ?? null); ?></span>
                                    <span class="mini-name"><?php echo htmlspecialchars((string) ($invoice['FICHENO'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="mini-val"><?php echo moh_money($invoice['SATIS_TUTAR'] ?? 0); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="insight-card">
                <div class="insight-head">
                    <div class="insight-title"><i class="fa-solid fa-chart-line"></i> Son 3 odeme</div>
                    <span class="score-pill <?php echo $trendMeta['class']; ?>"><i class="fa-solid <?php echo $trendMeta['icon']; ?>"></i><?php echo htmlspecialchars($trendMeta['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="insight-body">
                    <?php if ($lastPayments === []): ?>
                        <div class="muted">Odeme yok.</div>
                    <?php else: ?>
                        <div class="mini-flow">
                            <?php foreach ($lastPayments as $payment): ?>
                                <div class="mini-row">
                                    <span class="mini-date"><?php echo moh_date($payment['TARIH'] ?? null); ?></span>
                                    <span class="mini-name"><?php echo htmlspecialchars(moh_trcode_name((int) $payment['TRCODE']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="mini-val"><?php echo moh_money($payment['TUTAR'] ?? 0); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="muted" style="margin-top:8px;"><?php echo htmlspecialchars($trendMeta['desc'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
            </div>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div>
                    <div class="panel-title"><i class="fa-solid fa-list-timeline"></i> Cari akisi</div>
                    <div class="panel-sub">Fisler ve tahsilatlar tek kronolojik listede</div>
                </div>
            </div>
            <?php if ($activityRows === []): ?>
                <div class="empty-state"><i class="fa-regular fa-folder-open"></i>Bu yil icin fis veya odeme bulunamadi.</div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Hareket</th>
                            <th class="col-right">Borc</th>
                            <th class="col-right">Alacak</th>
                            <th class="col-right">Odeme</th>
                            <th>Durum</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activityRows as $activity): ?>
                            <?php if ($activity['type'] === 'invoice'): ?>
                                <?php
                                    $invoice = $activity['data'];
                                    $days = $invoice['PAYMENT_DAYS'] !== null ? (float) $invoice['PAYMENT_DAYS'] : null;
                                    $meta = moh_score_meta($days);
                                ?>
                                <tr>
                                    <td><?php echo moh_date($invoice['TARIH'] ?? null); ?></td>
                                    <td>
                                        <button class="invoice-btn" type="button" data-ref="<?php echo (int) $invoice['INVOICE_REF']; ?>">
                                            <?php echo htmlspecialchars((string) $invoice['FICHENO'], ENT_QUOTES, 'UTF-8'); ?>
                                        </button>
                                        <div class="muted"><?php echo (int) ($invoice['SATIR_SAYISI'] ?? 0); ?> satir · Fis</div>
                                    </td>
                                    <td class="col-right strong"><?php echo moh_money($invoice['SATIS_TUTAR'] ?? 0); ?></td>
                                    <td class="col-right muted">-</td>
                                    <td class="col-right">
                                        <?php if ($days === null): ?>
                                            <span class="muted">Kapanmadi</span>
                                        <?php else: ?>
                                            <span class="strong"><?php echo number_format($days, 0, ',', '.'); ?> gun</span>
                                            <div class="muted"><?php echo moh_date($invoice['COMPLETE_DATE'] ?? null); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="score-pill <?php echo $meta['class']; ?>"><i class="fa-solid <?php echo $meta['icon']; ?>"></i><?php echo htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                </tr>
                            <?php else: ?>
                                <?php $payment = $activity['data']; ?>
                                <tr>
                                    <td><?php echo moh_date($payment['TARIH'] ?? null); ?></td>
                                    <td>
                                        <div class="main-text"><?php echo htmlspecialchars(moh_trcode_name((int) $payment['TRCODE']), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php if (!empty($payment['TRANNO'])): ?><div class="muted mono"><?php echo htmlspecialchars((string) $payment['TRANNO'], ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
                                    </td>
                                    <td class="col-right muted">-</td>
                                    <td class="col-right strong"><?php echo moh_money($payment['TUTAR'] ?? 0); ?></td>
                                    <td class="col-right muted">-</td>
                                    <td><span class="score-pill score-good"><i class="fa-solid fa-money-bill-transfer"></i>Tahsilat</span></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php else: ?>
        <div class="panel">
            <div class="empty-state"><i class="fa-solid fa-user-magnifying-glass"></i>Musteri arayip secerek son fisleri, odemeleri ve odeme hizini gorebilirsiniz.</div>
        </div>
    <?php endif; ?>

    <section class="leader-grid">
        <?php
        $leaderConfigs = [
            'fast' => ['title' => 'En hizli odeme', 'icon' => 'fa-bolt', 'metric' => 'AVG_DAYS', 'suffix' => 'gun'],
            'volume' => ['title' => 'En hacimli mal alan', 'icon' => 'fa-boxes-stacked', 'metric' => 'SALES_TOTAL', 'suffix' => ''],
            'combo' => ['title' => 'Hacim + hiz lideri', 'icon' => 'fa-ranking-star', 'metric' => 'COMBO_SCORE', 'suffix' => 'skor'],
        ];
        ?>
        <?php foreach ($leaderConfigs as $key => $cfg): ?>
            <div class="leader-card">
                <div class="leader-head">
                    <div class="leader-title"><i class="fa-solid <?php echo $cfg['icon']; ?>"></i><?php echo htmlspecialchars($cfg['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="leader-list">
                    <?php if (($leaderboard[$key] ?? []) === []): ?>
                        <div class="empty-state">Veri yok.</div>
                    <?php else: ?>
                        <?php foreach (($leaderboard[$key] ?? []) as $idx => $row): ?>
                            <?php
                            $metric = $cfg['metric'];
                            $value = $row[$metric] ?? null;
                            if ($metric === 'SALES_TOTAL') {
                                $display = moh_money($value);
                                $sub = (int) $row['INVOICE_COUNT'] . ' fis';
                            } elseif ($metric === 'AVG_DAYS') {
                                $display = $value === null ? '-' : number_format((float) $value, 1, ',', '.') . ' gun';
                                $sub = moh_money($row['PAID_VOLUME'] ?? 0);
                            } else {
                                $display = number_format((float) $value, 0, ',', '.');
                                $sub = number_format((float) ($row['AVG_DAYS'] ?? 0), 1, ',', '.') . ' gun · ' . moh_money($row['PAID_VOLUME'] ?? 0);
                            }
                            ?>
                            <a class="leader-row" href="?year=<?php echo (int) $year; ?>&cariid=<?php echo (int) $row['CARIID']; ?>">
                                <span class="rank <?php echo $idx < 3 ? 'top' : ''; ?>"><?php echo $idx + 1; ?></span>
                                <span>
                                    <span class="leader-name"><?php echo htmlspecialchars((string) $row['UNVANI'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="leader-meta"><?php echo htmlspecialchars((string) $row['KODU'], ENT_QUOTES, 'UTF-8'); ?><?php if (!empty($row['SEHIR'])): ?> · <?php echo htmlspecialchars((string) $row['SEHIR'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></span>
                                </span>
                                <span class="leader-value"><?php echo $display; ?><small><?php echo $sub; ?></small></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>

    <div class="method-note">
        Hesaplama FIFO mantigi ile yapilir: satis faturasi cari hesapta borc satiri olarak izlenir, CLFLINE uzerindeki alacak yonlu cari hareketler sirayla eski borclari kapatir. Sag taraftaki odeme listesi tahsilat tiplerini gosterir; kapatma hesabinda ise cari bakiyeyi etkileyen tum alacak hareketleri dikkate alinir. 15 gun ve alti cok iyi, 16-45 gun orta, 45 gun uzeri cok kotu olarak siniflanir.
    </div>
</main>

<div class="modal-backdrop" id="invoiceModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-head">
            <div class="modal-title"><i class="fa-solid fa-receipt"></i> Fis ayrintisi</div>
            <button class="modal-close" type="button" id="modalClose" title="Kapat"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="detail-empty">Yukleniyor...</div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('invoiceModal');
    var body = document.getElementById('modalBody');
    var closeBtn = document.getElementById('modalClose');

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
    }

    function openModal(ref) {
        modal.classList.add('show');
        modal.setAttribute('aria-hidden', 'false');
        body.innerHTML = '<div class="detail-empty">Yukleniyor...</div>';
        fetch('rapor_musteri_odeme_hizi.php?ajax=invoice_detail&ref=' + encodeURIComponent(ref), {
            credentials: 'same-origin'
        })
            .then(function (response) { return response.text(); })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () { body.innerHTML = '<div class="detail-empty">Detay alinamadi.</div>'; });
    }

    document.querySelectorAll('.invoice-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn.getAttribute('data-ref'));
        });
    });
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
}());
</script>
</body>
</html>

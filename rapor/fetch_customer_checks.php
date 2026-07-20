<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

header('Content-Type: text/html; charset=UTF-8');

if (m_p_yetki($terminalkullanici, 'M17') != 1) {
    http_response_code(403);
    echo '<p class="text-red-600 text-center py-4">Bu veriyi görüntüleme yetkiniz yok.</p>';
    exit;
}

$kimden = isset($_GET['kimen']) ? trim((string) $_GET['kimen']) : '';
if ($kimden === '') {
    echo '<p class="text-gray-600 text-center py-4">Müşteri bilgisi bulunamadı.</p>';
    exit;
}

function raporDateFormat(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $ts = strtotime((string) $value);
    return ($ts === false) ? '-' : date('d.m.Y', $ts);
}

try {
    $sql = "
        SELECT
            CAST(C.DUEDATE AS DATE) AS DUEDATE,
            C.TRNET AS TUTAR,
            C.PORTFOYNO,
            C.NEWSERINO,
            CAST(C.SETDATE AS DATE) AS SETDATE,
            ISNULL(CL.CODE, '') AS CARIHESAP
        FROM {$firmadonem}CSCARD C WITH(NOLOCK)
        LEFT JOIN (
            SELECT
                T.CSREF,
                T.CARDREF,
                ROW_NUMBER() OVER (PARTITION BY T.CSREF ORDER BY T.LOGICALREF DESC) AS RN
            FROM {$firmadonem}CSTRANS T WITH(NOLOCK)
        ) TX ON TX.CSREF = C.LOGICALREF AND TX.RN = 1
        LEFT JOIN {$firma}CLCARD CL WITH(NOLOCK) ON CL.LOGICALREF = TX.CARDREF
        WHERE C.CURRSTAT IN(1)
          AND C.STATUS IN(0,1)
          AND C.DOC = 1
          AND C.OWING = :kimden
        ORDER BY CAST(C.DUEDATE AS DATE) ASC, C.TRNET
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([':kimden' => $kimden]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("fetch_customer_checks.php sorgu hatasi: " . $e->getMessage());
    echo '<p class="text-red-600 text-center py-4">Veriler getirilirken bir hata oluştu.</p>';
    exit;
}

if (!$rows) {
    echo '<p class="text-gray-600 text-center py-4">Bu müşteri için çek kaydı bulunamadı.</p>';
    exit;
}

$total = 0.0;
foreach ($rows as $row) {
    $total += isset($row['TUTAR']) ? (float) $row['TUTAR'] : 0.0;
}
?>
<div class="space-y-3">
    <div class="bg-blue-50 border border-blue-100 rounded-lg px-3 py-2 flex flex-wrap items-center gap-3 text-sm">
        <span class="text-blue-700 font-semibold">Müşteri: <?php echo htmlspecialchars($kimden, ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="text-gray-600">Kayıt: <?php echo number_format(count($rows), 0, ',', '.'); ?></span>
        <span class="text-green-700 font-semibold">Toplam: <?php echo number_format($total, 2, ',', '.'); ?> ₺</span>
    </div>

    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200">
                    <th class="text-left py-2 px-2 font-semibold text-gray-700">Vade</th>
                    <th class="text-right py-2 px-2 font-semibold text-gray-700">Tutar</th>
                    <th class="text-left py-2 px-2 font-semibold text-gray-700">Portföy</th>
                    <th class="text-left py-2 px-2 font-semibold text-gray-700">Seri No</th>
                    <th class="text-left py-2 px-2 font-semibold text-gray-700">Alım</th>
                    <th class="text-left py-2 px-2 font-semibold text-gray-700">Cari Kodu</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($rows as $row): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="py-2 px-2 whitespace-nowrap"><?php echo htmlspecialchars(raporDateFormat($row['DUEDATE'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="py-2 px-2 whitespace-nowrap text-right font-semibold"><?php echo number_format((float) ($row['TUTAR'] ?? 0), 2, ',', '.'); ?> ₺</td>
                        <td class="py-2 px-2 whitespace-nowrap"><?php echo htmlspecialchars((string) ($row['PORTFOYNO'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="py-2 px-2 whitespace-nowrap"><?php echo htmlspecialchars((string) ($row['NEWSERINO'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="py-2 px-2 whitespace-nowrap"><?php echo htmlspecialchars(raporDateFormat($row['SETDATE'] ?? null), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="py-2 px-2 whitespace-nowrap"><?php echo htmlspecialchars((string) ($row['CARIHESAP'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

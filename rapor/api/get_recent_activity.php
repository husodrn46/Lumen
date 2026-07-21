<?php
declare(strict_types=1);

// api/get_recent_activity.php
include_once(__DIR__ . "/../../ayr.php");
require_once __DIR__ . '/../../kontrol.php';
header('Content-Type: application/json; charset=utf-8');

// Rapor yetkisi (M17) — cari unvani + fatura tutari doner.
if ((int) m_p_yetki($terminalkullanici, 'M17') !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$sql = "SELECT TOP 5 I.FICHENO, C.DEFINITION_ as UNVAN, I.NETTOTAL, I.DATE_
	        FROM {$firmadonem}INVOICE I JOIN {$firma}CLCARD C ON I.CLIENTREF = C.LOGICALREF
	        WHERE I.TRCODE = 8 AND I.CANCELLED = 0 ORDER BY I.DATE_ DESC, I.TIME_ DESC";

$stmt = $dbh->prepare($sql);
$stmt->execute();
$activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo json_encode($activities, JSON_UNESCAPED_UNICODE);
?>

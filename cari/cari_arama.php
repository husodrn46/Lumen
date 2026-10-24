<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once __DIR__ . '/../kontrol.php';

header('Content-Type: application/json; charset=utf-8');

$query = $_GET['q'] ?? '';

if (!empty($query)) {
    $searchTerm = '%' . lumen_arama_terimi($query) . '%';
    // Özel cari kısıtı: gizlenen cariler aramada da çıkmaz (eskiden uygulanmıyordu).
    $ozel = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'C.ID', 'cari_arama_gizli');
    $stmt = $dbh->prepare("
        SELECT TOP 10 C.ID AS LOGICALREF, C.KOD AS CODE, C.UNVAN AS DEFINITION_
        FROM " . lumen_tablo('cari') . " C
        WHERE (" . lumen_arama_sql('C.KOD') . " LIKE :query1 OR " . lumen_arama_sql('C.UNVAN') . " LIKE :query2) AND C.AKTIF = 1"
        . ($ozel['sql'] !== '' ? " AND {$ozel['sql']}" : '') . "
    ");
    $stmt->execute([':query1' => $searchTerm, ':query2' => $searchTerm] + $ozel['params']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} else {
    echo json_encode([]);
}
?>

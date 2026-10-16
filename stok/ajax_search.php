<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");

header('Content-Type: application/json');

$query = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

if (strlen($query) < 2) {
    echo json_encode(['customers' => [], 'products' => []]);
    exit;
}

$results = ['customers' => [], 'products' => []];

try {
    // Müşteri arama
    $stmt = $dbh->prepare("SELECT TOP 5 LOGICALREF, CODE, DEFINITION_
                          FROM {$firma}CLCARD
                          WHERE DEFINITION_ LIKE :query1 OR CODE LIKE :query2
                          ORDER BY DEFINITION_");
    $escaped = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $query);
    $searchTerm = '%' . $escaped . '%';
    $stmt->bindParam(':query1', $searchTerm, PDO::PARAM_STR);
    $stmt->bindParam(':query2', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $results['customers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ürün arama
    $stmt = $dbh->prepare("SELECT TOP 5 LOGICALREF, CODE, NAME
                          FROM {$firma}ITEMS
                          WHERE NAME LIKE :query1 OR CODE LIKE :query2
                          ORDER BY NAME");
    $stmt->bindParam(':query1', $searchTerm, PDO::PARAM_STR);
    $stmt->bindParam(':query2', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $results['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception) {
    // Hata durumunda boş sonuç
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
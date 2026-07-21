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
                          WHERE DEFINITION_ LIKE :query OR CODE LIKE :query
                          ORDER BY DEFINITION_");
    $escaped = str_replace(['[', ']', '%', '_'], ['[[]', '[]]', '[%]', '[_]'], $query);
    $searchTerm = '%' . $escaped . '%';
    $stmt->bindParam(':query', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $results['customers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ürün arama
    $stmt = $dbh->prepare("SELECT TOP 5 LOGICALREF, CODE, NAME
                          FROM {$firma}ITEMS
                          WHERE NAME LIKE :query OR CODE LIKE :query
                          ORDER BY NAME");
    $stmt->bindParam(':query', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $results['products'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception) {
    // Hata durumunda boş sonuç
}

echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
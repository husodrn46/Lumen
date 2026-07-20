<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';

header('Content-Type: application/json; charset=utf-8');

$query = $_GET['q'] ?? '';

if (!empty($query)) {
    $escaped = str_replace(['[', ']', '%', '_'], ['[[]', '[]]', '[%]', '[_]'], $query);
    $searchTerm = "%$escaped%";
    $stmt = $dbh->prepare("
        SELECT TOP 10 LOGICALREF, CODE, DEFINITION_
        FROM {$firma}CLCARD
        WHERE (CODE LIKE :query OR DEFINITION_ LIKE :query) AND ACTIVE=0
    ");
    $stmt->bindParam(':query', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
} else {
    echo json_encode([]);
}
?>

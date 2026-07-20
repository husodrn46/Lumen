<?php
include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';

echo "<h1>View Sorguları</h1>";

// M_CARI view tanımını getir
echo "<h2>M_CARI View Tanımı</h2>";
try {
    $stmt = $dbh->query("SELECT OBJECT_DEFINITION(OBJECT_ID('M_CARI')) AS TANIM");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['TANIM']) {
        echo "<pre style='background:#f5f5f5; padding:15px; overflow:auto; font-size:12px;'>" . htmlspecialchars($row['TANIM']) . "</pre>";
    } else {
        echo "<p style='color:red;'>M_CARI view bulunamadı</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Hata: " . $e->getMessage() . "</p>";
}

// M_FIS_HAREKETLERI view tanımını getir
echo "<h2>M_FIS_HAREKETLERI View Tanımı</h2>";
try {
    $stmt = $dbh->query("SELECT OBJECT_DEFINITION(OBJECT_ID('M_FIS_HAREKETLERI')) AS TANIM");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['TANIM']) {
        echo "<pre style='background:#f5f5f5; padding:15px; overflow:auto; font-size:12px;'>" . htmlspecialchars($row['TANIM']) . "</pre>";
    } else {
        echo "<p style='color:red;'>M_FIS_HAREKETLERI view bulunamadı</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Hata: " . $e->getMessage() . "</p>";
}

// Tüm M_ ile başlayan view'ları listele
echo "<h2>Tüm M_ View'ları</h2>";
try {
    $stmt = $dbh->query("SELECT name FROM sys.views WHERE name LIKE 'M_%' ORDER BY name");
    $views = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($views) {
        echo "<ul>";
        foreach ($views as $v) {
            echo "<li>" . htmlspecialchars($v['name']) . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>M_ ile başlayan view bulunamadı</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>Hata: " . $e->getMessage() . "</p>";
}
?>

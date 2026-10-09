<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/support/sqlserver-environment.php';
require __DIR__ . '/../api/_api.inc';
$pdo = lumen_test_sql_connection();
try {
    $tables = (int) $pdo->query('SELECT COUNT(*) FROM sys.tables WHERE is_ms_shipped = 0')->fetchColumn();
    echo 'İzole DB bağlantısı doğrulandı. Kullanıcı tablosu: ' . $tables . "\n";
    $stmt = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='dbo' AND TABLE_NAME='M_API_TOKEN'");
    $exists = $stmt->fetchColumn(); $stmt->closeCursor();
    if ($exists) {
        api_token_sema_kontrol($pdo);
        $pdo->query('SELECT TOP 0 TOKEN, PERSONEL, KULLANICI_ADI, IP, OLUSTURMA, SON_KULLANIM, AKTIF FROM dbo.M_API_TOKEN')->closeCursor();
        $pdo->query('SELECT TOP 0 KULLANICI_ID, KAPATMA_TS FROM dbo.M_OTURUM_KAPAT')->closeCursor();
        echo "Token alanı ve force-logout sütunları uygun. Veri/şema yazılmadı.\n";
    } else {
        echo "Token şeması yok. Boş fixture DB için beklenen durum; runtime hazır anlamına gelmez.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "ENGEL: Token/force-logout şeması hazır değil. Veri koruyan hazırlık gerekli; veri/şema yazılmadı.\n");
    exit(2);
}

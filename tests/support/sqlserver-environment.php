<?php
declare(strict_types=1);

/** Shared guard: no .env, production bootstrap, remote host or automatic DB creation. */
function lumen_test_sql_database(string $dsn): string
{
    if (!preg_match('/\Asqlsrv:Server=(?:localhost|127\.0\.0\.1)(?:,[0-9]+)?;Database=(LumenTest_[A-Za-z0-9_]+)(?:;(?:Encrypt|TrustServerCertificate|ConnectionPooling)=(?:0|1|yes|no|true|false))*;?\z/i', $dsn, $m)) {
        throw new InvalidArgumentException('Yalnız localhost ve LumenTest_* DSN kabul edilir.');
    }
    return $m[1];
}
function lumen_test_sql_connection(bool $write = false): PDO
{
    try { $database = lumen_test_sql_database((string) getenv('LUMEN_TEST_SQL_DSN')); }
    catch (InvalidArgumentException $e) { fwrite(STDERR, 'ENGEL: ' . $e->getMessage() . "\n"); exit(2); }
    if (PHP_VERSION_ID < 80200 || !extension_loaded('pdo_sqlsrv')) {
        fwrite(STDERR, "ENGEL: PHP 8.2+ ve pdo_sqlsrv gerekli; test çalıştırılmadı.\n"); exit(2);
    }
    if ($write && getenv('LUMEN_TEST_SQL_WRITE') !== 'sentetik-test-onayli') {
        fwrite(STDERR, "ENGEL: Sentetik test DB yazma seçimi gerekli.\n"); exit(2);
    }
    try {
        $pdo = new PDO((string) getenv('LUMEN_TEST_SQL_DSN'), (string) getenv('LUMEN_TEST_SQL_USER'),
            (string) getenv('LUMEN_TEST_SQL_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $pdo->query('SELECT DB_NAME()');
        $actual = $stmt->fetchColumn(); $stmt->closeCursor();
        if ($actual !== $database) { throw new RuntimeException('Test veritabanı adı uyuşmuyor.'); }
        return $pdo;
    } catch (Throwable $e) {
        // Never echo driver exception/DSN/user/password or a credential-bearing trace.
        fwrite(STDERR, "ENGEL: İzole test DB bağlantısı/DB adı doğrulanamadı.\n"); exit(2);
    }
}

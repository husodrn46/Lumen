<?php
declare(strict_types=1);

/** Separate, lazy SQL Server pilot connection. Never reads a caller-supplied DSN. */
final class LumenConnectionFactory
{
    public static function configuration(array $env): array
    {
        $server = $env['LUMEN_DB_SERVER'] ?? '';
        $database = $env['LUMEN_DB_NAME'] ?? '';
        $user = $env['LUMEN_DB_USER'] ?? '';
        $password = $env['LUMEN_DB_PASS'] ?? '';
        if (!is_string($server) || !preg_match('/\A[A-Za-z0-9._-]+(?:\\\\[A-Za-z0-9_-]+)?(?:,([0-9]{1,5}))?\z/', $server, $m)
            || (isset($m[1]) && ((int)$m[1] < 1 || (int)$m[1] > 65535))
            || !is_string($database) || !preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,127}\z/', $database)
            || !is_string($user) || trim($user) === '' || strtolower(trim($user)) === 'sa'
            || !is_string($password) || $password === '') {
            throw new RuntimeException('Lumen connection configuration unavailable.');
        }
        // Refuse accidental ERP reuse and shared runtime identity; no AKL credential fallback.
        if (strcasecmp($database, (string)($env['AKL_DB_NAME'] ?? 'LOGODB')) === 0
            || (strcasecmp(trim($user), trim((string)($env['AKL_DB_USER'] ?? ''))) === 0)) {
            throw new RuntimeException('Separate Lumen database/account required.');
        }
        return ['dsn'=>"sqlsrv:Server={$server};Database={$database};Encrypt=yes;TrustServerCertificate=no;LoginTimeout=5",
            'database'=>$database, 'user'=>$user, 'password'=>$password];
    }

    public static function connect(array $env, ?Closure $connector = null): PDO
    {
        $config = self::configuration($env);
        $connector ??= static fn(array $c): PDO => new PDO($c['dsn'], $c['user'], $c['password'],
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        try {
            $lumenDbh = $connector($config);
            $stmt = $lumenDbh->query("SELECT DB_NAME() AS DATABASE_NAME, IS_SRVROLEMEMBER('sysadmin') AS IS_ADMIN,
                HAS_PERMS_BY_NAME(DB_NAME(), 'DATABASE', 'CREATE TABLE') AS CAN_DDL");
            $row = $stmt->fetch(PDO::FETCH_ASSOC); $stmt->closeCursor();
            if (!$row || strcasecmp((string)$row['DATABASE_NAME'], $config['database']) !== 0
                || !isset($row['IS_ADMIN'], $row['CAN_DDL']) || (int)$row['IS_ADMIN'] !== 0 || (int)$row['CAN_DDL'] !== 0) {
                throw new RuntimeException('Lumen runtime database/account gate rejected.');
            }
            return $lumenDbh;
        } catch (Throwable $e) {
            // Keep driver trace and credentials out of application error logs and responses.
            throw new RuntimeException('Lumen preference service unavailable.');
        }
    }
}

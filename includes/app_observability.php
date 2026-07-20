<?php
declare(strict_types=1);

/**
 * Uygulama genel gozlemlenebilirlik yardimcilari:
 * - Standart hata loglama (referans kodlu)
 * - Yavas sorgu olcumu ve loglama
 */

if (!defined('APP_SLOW_QUERY_DEFAULT_MS')) {
    define('APP_SLOW_QUERY_DEFAULT_MS', 300.0);
}

if (!defined('APP_REQUEST_PERF_DEFAULT_MS')) {
    // Yalnizca 1 sn ve uzeri suren istekler loglanir (0 = her istek -> log sismesi).
    // Gerektiginde APP_REQUEST_PERF_MS ortam degiskeniyle override edilebilir.
    define('APP_REQUEST_PERF_DEFAULT_MS', 1000.0);
}

if (!function_exists('app_load_env_fallback')) {
    function app_load_env_fallback(?string $path = null): void
    {
        static $loaded = false;
        if ($loaded) {
            return;
        }
        $loaded = true;

        $envPath = $path ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');
        if (!is_file($envPath)) {
            return;
        }

        $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim(trim($value), "\"'");
            if ($key === '') {
                continue;
            }

            $existing = getenv($key);
            if ($existing !== false && $existing !== '') {
                continue;
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}

if (!function_exists('app_env_value')) {
    function app_env_value(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }

        app_load_env_fallback();

        $value = getenv($key);
        if ($value !== false && $value !== '') {
            return (string) $value;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return (string) $_SERVER[$key];
        }
        return $default;
    }
}

if (!function_exists('app_normalize_path')) {
    function app_normalize_path(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        if (preg_match('/^[A-Za-z]:[\\\\\\/]*$/', $normalized) === 1) {
            return strtoupper($normalized[0]) . ':' . DIRECTORY_SEPARATOR;
        }
        if ($normalized === DIRECTORY_SEPARATOR || preg_match('/^[\\\\\\/]+$/', $normalized) === 1) {
            return DIRECTORY_SEPARATOR;
        }
        return rtrim($normalized, '/\\');
    }
}

if (!function_exists('app_path_is_absolute')) {
    function app_path_is_absolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        return (bool) preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }
}

if (!function_exists('app_path_join')) {
    function app_path_join(string ...$parts): string
    {
        $joined = '';
        foreach ($parts as $idx => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $part = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $part);

            if ($joined === '') {
                $first = rtrim($part, '/\\');
                if (preg_match('/^[A-Za-z]:$/', $first) === 1) {
                    $joined = $first . DIRECTORY_SEPARATOR;
                } elseif ($first === '' && ($part === DIRECTORY_SEPARATOR || preg_match('/^[\\\\\\/]+$/', $part) === 1)) {
                    $joined = DIRECTORY_SEPARATOR;
                } else {
                    $joined = $first;
                }
            } else {
                $joined = rtrim($joined, '/\\') . DIRECTORY_SEPARATOR . trim($part, '/\\');
            }
        }

        return $joined;
    }
}

if (!function_exists('app_resolve_path')) {
    function app_resolve_path(string $path, string $baseDir): string
    {
        $path = trim($path);
        if ($path === '') {
            return app_normalize_path($baseDir);
        }

        if (app_path_is_absolute($path)) {
            return app_normalize_path($path);
        }
        return app_normalize_path(app_path_join($baseDir, $path));
    }
}

if (!function_exists('app_ensure_dir')) {
    function app_ensure_dir(string $dir): bool
    {
        if ($dir === '') {
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return false;
        }
        return is_dir($dir);
    }
}

if (!function_exists('app_log_root_dir')) {
    function app_log_root_dir(): string
    {
        static $resolved = null;
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        $projectRoot = dirname(__DIR__);
        $configuredRoot = (string) app_env_value('LOG_ROOT', '');
        $fallbackRoot = app_path_join($projectRoot, 'logs');
        $candidates = [];

        if ($configuredRoot !== '') {
            $candidates[] = app_resolve_path($configuredRoot, $projectRoot);
        }
        $candidates[] = app_normalize_path($fallbackRoot);

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && app_ensure_dir($candidate)) {
                $resolved = $candidate;
                return $resolved;
            }
        }

        $resolved = app_normalize_path($fallbackRoot);
        return $resolved;
    }
}

if (!function_exists('app_log_file')) {
    function app_log_file(string $channel): string
    {
        $channel = trim($channel);
        if ($channel === '') {
            $channel = 'app';
        }

        $map = [
            'app-error' => ['env' => 'APP_ERROR_LOG', 'relative' => ['app', 'app-error.log']],
            'query-error' => ['env' => 'QUERY_ERROR_LOG', 'relative' => ['app', 'query-error.log']],
            'slow-query' => ['env' => 'SLOW_QUERY_LOG', 'relative' => ['app', 'slow-query.log']],
            'access' => ['env' => 'ACCESS_LOG', 'relative' => ['app', 'access.log']],
            'loglama_hatalari' => ['env' => 'LOG_WRITE_ERROR_LOG', 'relative' => ['app', 'loglama_hatalari.log']],
        ];

        $defaultRelative = ['app', $channel . '.log'];
        $config = $map[$channel] ?? ['env' => '', 'relative' => $defaultRelative];

        $configuredFile = '';
        if ($config['env'] !== '') {
            $configuredFile = (string) app_env_value((string) $config['env'], '');
        }

        if ($configuredFile !== '') {
            $file = app_resolve_path($configuredFile, app_log_root_dir());
        } else {
            $file = app_path_join(app_log_root_dir(), ...(array) $config['relative']);
        }

        app_ensure_dir(dirname($file));

        $dirWritable = is_dir(dirname($file)) && is_writable(dirname($file));
        $fileWritable = !is_file($file) || is_writable($file);
        if ($dirWritable && $fileWritable) {
            return $file;
        }

        $fallbackRelative = (array) $config['relative'];
        $fallbackFile = app_path_join(dirname(__DIR__), 'logs', ...$fallbackRelative);
        app_ensure_dir(dirname($fallbackFile));
        return $fallbackFile;
    }
}

if (!function_exists('app_log_dir')) {
    function app_log_dir(): string
    {
        $dir = dirname(app_log_file('app-error'));
        app_ensure_dir($dir);
        return $dir;
    }
}

if (!function_exists('app_access_log_file')) {
    function app_access_log_file(): string
    {
        return app_log_file('access');
    }
}

if (!function_exists('app_php_error_log_file')) {
    function app_php_error_log_file(): string
    {
        $configured = (string) app_env_value('PHP_ERROR_LOG', '');
        $file = $configured !== ''
            ? app_resolve_path($configured, app_log_root_dir())
            : app_path_join(app_log_root_dir(), 'php', 'php_errors.log');

        app_ensure_dir(dirname($file));
        return $file;
    }
}

if (!function_exists('app_error_status_file')) {
    function app_error_status_file(): string
    {
        $configured = (string) app_env_value('APP_ERROR_STATUS_FILE', '');
        $file = $configured !== ''
            ? app_resolve_path($configured, app_log_root_dir())
            : app_path_join(app_log_root_dir(), 'app', 'app-error-status.json');

        app_ensure_dir(dirname($file));
        return $file;
    }
}

if (!defined('APP_LOG_DIR')) {
    define('APP_LOG_DIR', app_log_dir());
}

if (!function_exists('app_request_context')) {
    function app_request_context(): array
    {
        return [
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI'),
            'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            'script' => (string) ($_SERVER['SCRIPT_NAME'] ?? ''),
        ];
    }
}

if (!function_exists('app_log_write')) {
    function app_log_write(string $channel, array $payload): void
    {
        try {
            $line = json_encode(
                array_merge(['ts' => date('c')], $payload),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($line === false) {
                return;
            }

            $file = app_log_file($channel);
            $written = @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
            if ($written === false) {
                $fallbackFile = app_path_join(dirname(__DIR__), 'logs', 'app', $channel . '.log');
                app_ensure_dir(dirname($fallbackFile));
                $fallbackWritten = @file_put_contents($fallbackFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                if ($fallbackWritten === false) {
                    error_log('app_log_write yazma hatasi: channel=' . $channel . ' file=' . $file);
                } else {
                    error_log('app_log_write fallback kullanildi: channel=' . $channel . ' file=' . $fallbackFile);
                }
            }
        } catch (Throwable $e) {
            error_log('app_log_write hatasi: ' . $e->getMessage());
        }
    }
}

if (!function_exists('app_error_ref')) {
    function app_error_ref(): string
    {
        try {
            return 'ERR-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        } catch (Throwable) {
            return 'ERR-' . date('YmdHis') . '-' . strtoupper((string) mt_rand(100000, 999999));
        }
    }
}

if (!function_exists('app_log_exception')) {
    function app_log_exception(Throwable $e, string $scope, array $context = []): string
    {
        $ref = app_error_ref();
        $payload = [
            'ref' => $ref,
            'scope' => $scope,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'context' => $context,
            'request' => app_request_context(),
            'trace' => $e->getTraceAsString(),
        ];

        app_log_write('app-error', $payload);
        error_log("[{$ref}] {$scope}: " . $e->getMessage());

        return $ref;
    }
}

if (!function_exists('app_error_level_name')) {
    function app_error_level_name(int $severity): string
    {
        return match ($severity) {
            E_ERROR => 'E_ERROR',
            E_WARNING => 'E_WARNING',
            E_PARSE => 'E_PARSE',
            E_NOTICE => 'E_NOTICE',
            E_CORE_ERROR => 'E_CORE_ERROR',
            E_CORE_WARNING => 'E_CORE_WARNING',
            E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            E_COMPILE_WARNING => 'E_COMPILE_WARNING',
            E_USER_ERROR => 'E_USER_ERROR',
            E_USER_WARNING => 'E_USER_WARNING',
            E_USER_NOTICE => 'E_USER_NOTICE',
            E_STRICT => 'E_STRICT',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
            E_DEPRECATED => 'E_DEPRECATED',
            E_USER_DEPRECATED => 'E_USER_DEPRECATED',
            default => 'E_UNKNOWN(' . $severity . ')',
        };
    }
}

if (!function_exists('app_register_global_error_handlers')) {
    function app_register_global_error_handlers(): void
    {
        static $registered = false;
        if ($registered || PHP_SAPI === 'cli') {
            return;
        }
        $registered = true;

        $prevErrorHandler = null;
        $prevExceptionHandler = null;
        $seenKeys = [];

        $logOnce = static function (array $entry) use (&$seenKeys): void {
            $dedupe = implode('|', [
                (string) ($entry['scope'] ?? ''),
                (string) ($entry['code'] ?? ''),
                (string) ($entry['file'] ?? ''),
                (string) ($entry['line'] ?? ''),
                (string) ($entry['message'] ?? ''),
            ]);
            if ($dedupe !== '' && isset($seenKeys[$dedupe])) {
                return;
            }
            if ($dedupe !== '') {
                $seenKeys[$dedupe] = true;
            }
            app_log_write('app-error', $entry);
        };

        $prevErrorHandler = set_error_handler(
            static function (int $severity, string $message, string $file = '', int $line = 0) use (&$prevErrorHandler, $logOnce): bool {
                if (!(error_reporting() & $severity)) {
                    if (is_callable($prevErrorHandler)) {
                        return (bool) call_user_func($prevErrorHandler, $severity, $message, $file, $line);
                    }
                    return false;
                }

                $ref = app_error_ref();
                $logOnce([
                    'ref' => $ref,
                    'scope' => 'php-error',
                    'message' => $message,
                    'code' => $severity,
                    'file' => $file,
                    'line' => $line,
                    'context' => [
                        'kind' => 'php_error',
                        'severity' => $severity,
                        'severity_name' => app_error_level_name($severity),
                    ],
                    'request' => app_request_context(),
                    'trace' => '',
                ]);

                if (is_callable($prevErrorHandler)) {
                    return (bool) call_user_func($prevErrorHandler, $severity, $message, $file, $line);
                }

                return false;
            }
        );

        $prevExceptionHandler = set_exception_handler(
            static function (Throwable $e) use (&$prevExceptionHandler, $logOnce): void {
                $ref = app_error_ref();
                $logOnce([
                    'ref' => $ref,
                    'scope' => 'uncaught-exception',
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'context' => [
                        'kind' => 'uncaught_exception',
                        'class' => get_class($e),
                    ],
                    'request' => app_request_context(),
                    'trace' => $e->getTraceAsString(),
                ]);

                if (is_callable($prevExceptionHandler)) {
                    call_user_func($prevExceptionHandler, $e);
                    return;
                }

                if (!headers_sent()) {
                    http_response_code(500);
                }
            }
        );

        register_shutdown_function(
            static function () use ($logOnce): void {
                $last = error_get_last();
                if (!is_array($last)) {
                    return;
                }

                $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
                $severity = (int) ($last['type'] ?? 0);
                if (!in_array($severity, $fatalTypes, true)) {
                    return;
                }

                $ref = app_error_ref();
                $logOnce([
                    'ref' => $ref,
                    'scope' => 'php-fatal-shutdown',
                    'message' => (string) ($last['message'] ?? 'Fatal error'),
                    'code' => $severity,
                    'file' => (string) ($last['file'] ?? ''),
                    'line' => (int) ($last['line'] ?? 0),
                    'context' => [
                        'kind' => 'fatal_shutdown',
                        'severity' => $severity,
                        'severity_name' => app_error_level_name($severity),
                    ],
                    'request' => app_request_context(),
                    'trace' => '',
                ]);
            }
        );
    }
}

if (!function_exists('app_json_istegi')) {
    function app_json_istegi(): bool
    {
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        $xhr = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        return str_contains($accept, 'application/json') || $xhr === 'xmlhttprequest';
    }
}

if (!function_exists('app_kullanici_hatasi_yanitla')) {
    function app_kullanici_hatasi_yanitla(string $mesaj, string $ref, int $status = 500): never
    {
        http_response_code($status);
        $guvenliMesaj = htmlspecialchars($mesaj, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $guvenliRef = htmlspecialchars($ref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (app_json_istegi()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(
                ['basarili' => false, 'mesaj' => "{$mesaj} (Ref: {$ref})", 'ref' => $ref],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        echo '<div style="margin:16px;padding:12px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-family:Arial,sans-serif;">';
        echo $guvenliMesaj . ' <small>(Ref: ' . $guvenliRef . ')</small>';
        echo '</div>';
        exit;
    }
}

if (!function_exists('app_hata_yonet')) {
    function app_hata_yonet(
        Throwable $e,
        string $scope,
        string $kullaniciMesaji = 'Islem sirasinda bir hata olustu.',
        array $context = [],
        int $status = 500
    ): never {
        $ref = app_log_exception($e, $scope, $context);
        app_kullanici_hatasi_yanitla($kullaniciMesaji, $ref, $status);
    }
}

if (!function_exists('app_slow_query_threshold_ms')) {
    function app_slow_query_threshold_ms(): float
    {
        $raw = app_env_value('APP_SLOW_QUERY_MS', '');
        if ($raw === null || $raw === '') {
            return APP_SLOW_QUERY_DEFAULT_MS;
        }
        $val = (float) $raw;
        return $val > 0 ? $val : APP_SLOW_QUERY_DEFAULT_MS;
    }
}

if (!function_exists('app_request_perf_enabled')) {
    function app_request_perf_enabled(): bool
    {
        $raw = strtolower(trim((string) app_env_value('APP_REQUEST_PERF_ENABLED', '1')));
        return !in_array($raw, ['0', 'false', 'off', 'no'], true);
    }
}

if (!function_exists('app_request_perf_threshold_ms')) {
    function app_request_perf_threshold_ms(): float
    {
        $raw = app_env_value('APP_REQUEST_PERF_MS', '');
        if ($raw === null || $raw === '') {
            return APP_REQUEST_PERF_DEFAULT_MS;
        }
        $val = (float) $raw;
        return $val >= 0 ? $val : APP_REQUEST_PERF_DEFAULT_MS;
    }
}

if (!function_exists('app_register_request_perf_logger')) {
    function app_register_request_perf_logger(array $meta = []): void
    {
        static $registered = false;
        if ($registered || PHP_SAPI === 'cli' || !app_request_perf_enabled()) {
            return;
        }
        $registered = true;

        $requestStart = microtime(true);
        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $pageName = basename($scriptName);

        register_shutdown_function(static function () use ($requestStart, $meta, $pageName): void {
            $durationMs = (microtime(true) - $requestStart) * 1000;
            $threshold = app_request_perf_threshold_ms();
            if ($durationMs < $threshold) {
                return;
            }

            $method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $requestLabel = trim($method . ' ' . $uri);
            if ($requestLabel === '') {
                $requestLabel = $pageName !== '' ? $pageName : 'REQUEST';
            }

            $payloadMeta = array_merge([
                'type' => 'request',
                'page' => $pageName !== '' ? $pageName : $scriptName,
                'action' => 'request_timing',
                'status_code' => (int) http_response_code(),
                'memory_peak_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
            ], $meta);

            app_log_write('slow-query', [
                'duration_ms' => round($durationMs, 2),
                'threshold_ms' => $threshold,
                'sql' => '[REQUEST] ' . app_sql_ozet($requestLabel),
                'meta' => $payloadMeta,
                'request' => app_request_context(),
            ]);
        });
    }
}

if (!function_exists('app_sql_ozet')) {
    function app_sql_ozet(string $sql, int $maxLen = 320): string
    {
        $norm = preg_replace('/\s+/', ' ', trim($sql));
        if (!is_string($norm)) {
            return '';
        }
        if (strlen($norm) <= $maxLen) {
            return $norm;
        }
        return substr($norm, 0, $maxLen) . '...';
    }
}

if (!function_exists('app_query_perf_kayit')) {
    function app_query_perf_kayit(string $sql, float $durationMs, array $meta = []): void
    {
        $threshold = app_slow_query_threshold_ms();
        if ($durationMs < $threshold) {
            return;
        }

        app_log_write('slow-query', [
            'duration_ms' => round($durationMs, 2),
            'threshold_ms' => $threshold,
            'sql' => app_sql_ozet($sql),
            'meta' => $meta,
            'request' => app_request_context(),
        ]);
    }
}

if (!function_exists('app_db_prepare_execute')) {
    function app_db_prepare_execute(PDO $pdo, string $sql, array $params = [], array $meta = []): PDOStatement
    {
        $start = microtime(true);
        try {
            $stmt = $pdo->prepare($sql);
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    $bindKey = is_int($key) ? ($key >= 1 ? $key : ($key + 1)) : (string) $key;
                    if (is_int($value)) {
                        $stmt->bindValue($bindKey, $value, PDO::PARAM_INT);
                    } elseif (is_bool($value)) {
                        $stmt->bindValue($bindKey, $value, PDO::PARAM_BOOL);
                    } elseif ($value === null) {
                        $stmt->bindValue($bindKey, null, PDO::PARAM_NULL);
                    } else {
                        $stmt->bindValue($bindKey, (string) $value, PDO::PARAM_STR);
                    }
                }
                $stmt->execute();
            } else {
                $stmt->execute();
            }
            return $stmt;
        } catch (Throwable $e) {
            app_log_write('query-error', [
                'sql' => app_sql_ozet($sql),
                'params_count' => count($params),
                'meta' => $meta,
                'request' => app_request_context(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            app_query_perf_kayit($sql, $durationMs, array_merge($meta, ['params_count' => count($params)]));
        }
    }
}

if (!function_exists('app_db_query_execute')) {
    function app_db_query_execute(PDO $pdo, string $sql, array $meta = []): PDOStatement
    {
        $start = microtime(true);
        try {
            return $pdo->query($sql);
        } catch (Throwable $e) {
            app_log_write('query-error', [
                'sql' => app_sql_ozet($sql),
                'params_count' => 0,
                'meta' => $meta,
                'request' => app_request_context(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $durationMs = (microtime(true) - $start) * 1000;
            app_query_perf_kayit($sql, $durationMs, array_merge($meta, ['params_count' => 0]));
        }
    }
}

<?php
declare(strict_types=1);

if (!function_exists('akl_page_visit_normalize_path')) {
    function akl_page_visit_normalize_path(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $path = ltrim($path, '/');

        $root = trim((string) (defined('APP_ROOT_URL') ? APP_ROOT_URL : ''), '/');
        if ($root !== '' && str_starts_with($path, $root . '/')) {
            $path = substr($path, strlen($root) + 1);
        }

        if ($path === '' || str_ends_with($path, '/')) {
            $path .= 'index.php';
        }

        return strtolower($path);
    }
}

if (!function_exists('akl_page_visit_path_from_href')) {
    function akl_page_visit_path_from_href(string $href): string
    {
        $path = (string) (parse_url($href, PHP_URL_PATH) ?: $href);
        return akl_page_visit_normalize_path($path);
    }
}

if (!function_exists('akl_page_visit_current_path')) {
    function akl_page_visit_current_path(): string
    {
        $scriptFile = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $rootDir = realpath(dirname(__DIR__));

        if ($scriptFile !== false && $rootDir !== false) {
            $scriptFile = str_replace('\\', '/', $scriptFile);
            $rootDir = rtrim(str_replace('\\', '/', $rootDir), '/');

            if (str_starts_with($scriptFile, $rootDir . '/')) {
                return akl_page_visit_normalize_path(substr($scriptFile, strlen($rootDir) + 1));
            }
        }

        return akl_page_visit_normalize_path((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    }
}

if (!function_exists('akl_page_visit_is_trackable_request')) {
    function akl_page_visit_is_trackable_request(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return false;
        }

        $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($requestedWith === 'xmlhttprequest') {
            return false;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if (str_contains($accept, 'application/json')) {
            return false;
        }

        $queryAjax = strtolower((string) ($_GET['ajax'] ?? ''));
        if ($queryAjax === '1' || $queryAjax === 'true') {
            return false;
        }

        $script = akl_page_visit_current_path();
        $base = basename($script);

        if (!str_ends_with($base, '.php')) {
            return false;
        }

        if (in_array($base, ['index.php', 'giris.php', 'kontrol.php', 'ayr.php', 'api.php'], true)) {
            return false;
        }

        if (str_starts_with($base, 'ajax_') || str_contains($base, '_ajax')) {
            return false;
        }

        if (str_starts_with($base, 'api_') || str_contains($base, '_api')) {
            return false;
        }

        if (str_contains($script, '/api/') || str_contains($script, '/ajax/')) {
            return false;
        }

        return true;
    }
}

if (!function_exists('akl_page_visit_title_from_path')) {
    function akl_page_visit_title_from_path(string $path): string
    {
        $base = basename($path, '.php');
        $base = str_replace(['_', '-'], ' ', $base);
        $title = function_exists('mb_convert_case')
            ? trim(mb_convert_case($base, MB_CASE_TITLE, 'UTF-8'))
            : trim(ucwords($base));

        return $title !== '' ? $title : 'Sayfa';
    }
}

if (!function_exists('akl_page_visit_ensure_table')) {
    function akl_page_visit_ensure_table(PDO $dbh): bool
    {
        if (isset($_SESSION['akl_page_visit_table_ready'])) {
            return $_SESSION['akl_page_visit_table_ready'] === true;
        }

        try {
            $dbh->exec("
                IF OBJECT_ID('dbo.M_SAYFA_ZIYARET', 'U') IS NULL
                BEGIN
                    CREATE TABLE dbo.M_SAYFA_ZIYARET (
                        LOGICALREF INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                        PERSONEL_ID INT NOT NULL,
                        SAYFA NVARCHAR(260) NOT NULL,
                        SAYFA_BASLIK NVARCHAR(160) NOT NULL,
                        SAYFA_URL NVARCHAR(400) NOT NULL,
                        ZIYARET_SAYISI INT NOT NULL CONSTRAINT DF_M_SAYFA_ZIYARET_SAYI DEFAULT 1,
                        ILK_ZIYARET DATETIME NOT NULL CONSTRAINT DF_M_SAYFA_ZIYARET_ILK DEFAULT GETDATE(),
                        SON_ZIYARET DATETIME NOT NULL CONSTRAINT DF_M_SAYFA_ZIYARET_SON DEFAULT GETDATE()
                    )
                END

                IF NOT EXISTS (
                    SELECT 1
                    FROM sys.indexes
                    WHERE name = 'UX_M_SAYFA_ZIYARET_PERSONEL_SAYFA'
                      AND object_id = OBJECT_ID('dbo.M_SAYFA_ZIYARET')
                )
                BEGIN
                    CREATE UNIQUE INDEX UX_M_SAYFA_ZIYARET_PERSONEL_SAYFA
                    ON dbo.M_SAYFA_ZIYARET (PERSONEL_ID, SAYFA)
                END

                IF NOT EXISTS (
                    SELECT 1
                    FROM sys.indexes
                    WHERE name = 'IX_M_SAYFA_ZIYARET_PERSONEL_SON'
                      AND object_id = OBJECT_ID('dbo.M_SAYFA_ZIYARET')
                )
                BEGIN
                    CREATE INDEX IX_M_SAYFA_ZIYARET_PERSONEL_SON
                    ON dbo.M_SAYFA_ZIYARET (PERSONEL_ID, SON_ZIYARET DESC)
                END
            ");

            $_SESSION['akl_page_visit_table_ready'] = true;
            return true;
        } catch (Throwable $e) {
            error_log('M_SAYFA_ZIYARET tablo kontrol hatasi: ' . $e->getMessage());
            unset($_SESSION['akl_page_visit_table_ready']);
            return false;
        }
    }
}

if (!function_exists('akl_log_current_page_visit')) {
    function akl_log_current_page_visit(PDO $dbh, int|string $personelId): void
    {
        if (!akl_page_visit_is_trackable_request()) {
            return;
        }

        if (!akl_page_visit_ensure_table($dbh)) {
            return;
        }

        $page = akl_page_visit_current_path();
        $title = akl_page_visit_title_from_path($page);
        $url = $page;

        try {
            $stmt = $dbh->prepare("
                UPDATE dbo.M_SAYFA_ZIYARET
                SET ZIYARET_SAYISI = ZIYARET_SAYISI + 1,
                    SAYFA_BASLIK = :title,
                    SAYFA_URL = :url,
                    SON_ZIYARET = GETDATE()
                WHERE PERSONEL_ID = :personel
                  AND SAYFA = :page
            ");
            $stmt->execute([
                ':title' => $title,
                ':url' => $url,
                ':personel' => (int) $personelId,
                ':page' => $page,
            ]);

            if ($stmt->rowCount() > 0) {
                return;
            }

            $insert = $dbh->prepare("
                INSERT INTO dbo.M_SAYFA_ZIYARET
                    (PERSONEL_ID, SAYFA, SAYFA_BASLIK, SAYFA_URL, ZIYARET_SAYISI, ILK_ZIYARET, SON_ZIYARET)
                VALUES
                    (:personel, :page, :title, :url, 1, GETDATE(), GETDATE())
            ");
            $insert->execute([
                ':personel' => (int) $personelId,
                ':page' => $page,
                ':title' => $title,
                ':url' => $url,
            ]);
        } catch (Throwable $e) {
            error_log('Sayfa ziyaret log hatasi: ' . $e->getMessage());
        }
    }
}

if (!function_exists('akl_page_visit_shortcuts')) {
    function akl_page_visit_shortcuts(PDO $dbh, int|string $personelId, array $visibleItems, int $limit = 5): array
    {
        if ($limit <= 0 || $visibleItems === [] || !akl_page_visit_ensure_table($dbh)) {
            return [];
        }

        $itemMap = [];
        foreach ($visibleItems as $item) {
            $href = (string) ($item['href'] ?? '');
            if ($href === '') {
                continue;
            }

            $path = akl_page_visit_path_from_href($href);
            $itemMap[$path] = $item;
        }

        if ($itemMap === []) {
            return [];
        }

        try {
            $stmt = $dbh->prepare("
                SELECT TOP 30 SAYFA, ZIYARET_SAYISI, SON_ZIYARET
                FROM dbo.M_SAYFA_ZIYARET
                WHERE PERSONEL_ID = :personel
                  AND SON_ZIYARET >= DATEADD(DAY, -90, GETDATE())
                ORDER BY ZIYARET_SAYISI DESC, SON_ZIYARET DESC
            ");
            $stmt->execute([':personel' => (int) $personelId]);
        } catch (Throwable $e) {
            error_log('Sayfa kisayol sorgu hatasi: ' . $e->getMessage());
            return [];
        }

        $shortcuts = [];
        while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $path = akl_page_visit_normalize_path((string) ($row['SAYFA'] ?? ''));
            if (!isset($itemMap[$path])) {
                continue;
            }

            $item = $itemMap[$path];
            $item['count'] = (int) ($row['ZIYARET_SAYISI'] ?? 0);
            $shortcuts[] = $item;

            if (count($shortcuts) >= $limit) {
                break;
            }
        }

        return $shortcuts;
    }
}

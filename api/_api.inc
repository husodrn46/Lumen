<?php
declare(strict_types=1);

/**
 * ============================================================================
 * Masaüstü uygulaması REST API — ortak yardımcılar
 * ----------------------------------------------------------------------------
 * Token tabanlı kimlik doğrulama (Authorization: Bearer <token>).
 * Bu katman mevcut iş mantığını (LOGO yazma) yeniden kullanır; mantık kopyalanmaz.
 * ============================================================================
 */

/** JSON yanıt döndürüp çıkar. */
function api_json($payload, int $code = 200): void
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** İstek gövdesini JSON olarak oku (boşsa boş dizi). */
function api_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** İstekteki Bearer token'ı al (Apache + IIS uyumlu). */
function api_bearer_token(): string
{
    $hdr = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($hdr === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0) {
                $hdr = (string) $v;
                break;
            }
        }
    }
    if (preg_match('/Bearer\s+([a-f0-9]{64})/i', $hdr, $m)) {
        return strtolower($m[1]);
    }
    return '';
}

/** Token tablosunu (M_API_TOKEN) yoksa oluştur. İlk başarılı kontrolden sonra
 *  istek başına tekrar sorgulamamak için process-level cache kullanır. */
function api_ensure_token_table(PDO $dbh): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;
    try {
        $stmt = $dbh->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = :t");
        $stmt->execute([':t' => 'M_API_TOKEN']);
        if ($stmt->fetchColumn()) {
            return;
        }
        $dbh->exec("CREATE TABLE M_API_TOKEN (
            ID INT IDENTITY(1,1) PRIMARY KEY,
            TOKEN VARCHAR(128) NOT NULL,
            PERSONEL INT NOT NULL,
            KULLANICI_ADI VARCHAR(64) NULL,
            OLUSTURMA DATETIME NOT NULL DEFAULT GETDATE(),
            SON_KULLANIM DATETIME NULL,
            AKTIF SMALLINT NOT NULL DEFAULT 1,
            IP VARCHAR(64) NULL
        )");
        $dbh->exec("CREATE INDEX IX_M_API_TOKEN_TOKEN ON M_API_TOKEN (TOKEN)");
    } catch (Throwable $e) {
        error_log('M_API_TOKEN olusturma: ' . $e->getMessage());
    }
}

/** Yeni token üret, sakla ve döndür. */
function api_token_olustur(PDO $dbh, int $personel, string $kullaniciAdi): string
{
    api_ensure_token_table($dbh);
    $token = bin2hex(random_bytes(32));
    // OLUSTURMA/AKTIF acikca yazilir: tablo eski bir surumden gelip DEFAULT
    // tanimi tasimiyorsa da (NOT NULL) insert basarisiz olmasin.
    $stmt = $dbh->prepare("INSERT INTO M_API_TOKEN (TOKEN, PERSONEL, KULLANICI_ADI, IP, OLUSTURMA, AKTIF)
                           VALUES (:t, :p, :k, :ip, GETDATE(), 1)");
    $stmt->execute([
        ':t' => $token,
        ':p' => $personel,
        ':k' => $kullaniciAdi,
        ':ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
    return $token;
}

/**
 * İstekteki token'ı doğrula. Geçerliyse ['personel'=>int,'kullanici'=>string]
 * döner; değilse null. SON_KULLANIM güncellenir.
 */
function api_token_dogrula(PDO $dbh): ?array
{
    $token = api_bearer_token();
    if ($token === '') {
        return null;
    }
    api_ensure_token_table($dbh);
    // Token 30 günlük mutlak ömre sahip; süresi geçmişse geçersiz sayılır.
    // (DATEADD'in 2. argümanı sabit INT — sqlsrv nvarchar tuzağına düşmez.)
    $stmt = $dbh->prepare("SELECT TOP 1 PERSONEL, KULLANICI_ADI
                           FROM M_API_TOKEN
                           WHERE TOKEN = :t AND AKTIF = 1
                             AND OLUSTURMA > DATEADD(DAY, -30, GETDATE())");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $upd = $dbh->prepare("UPDATE M_API_TOKEN SET SON_KULLANIM = GETDATE() WHERE TOKEN = :t");
    $upd->execute([':t' => $token]);
    return ['personel' => (int) $row['PERSONEL'], 'kullanici' => (string) ($row['KULLANICI_ADI'] ?? '')];
}

/** Token zorunlu uçlar için: geçersizse 401 döndürüp çıkar, geçerliyse oturum dizisi döner. */
function api_oturum_gerekli(PDO $dbh): array
{
    $oturum = api_token_dogrula($dbh);
    if ($oturum === null) {
        api_json(['ok' => false, 'mesaj' => 'Oturum gecersiz veya suresi dolmus.'], 401);
    }
    return $oturum;
}

/* =====================  OKUMA UÇLARI ORTAK YARDIMCILARI  ===================== */

/**
 * Türkçe-duyarlı arama için alan normalizasyonu. Latin1_General_CI_AI
 * collation'ı harf büyüklüğü + aksan (ç/c, ş/s, ı/i, ö/o, ü/u, ğ/g) ayrımını
 * kaldırır; mevcut ai_beta_sql_norm ile birebir aynı kalıp.
 */
function api_sql_norm(string $field): string
{
    return "UPPER({$field} COLLATE Latin1_General_CI_AI)";
}

/** Arama terimini sadeleştir + büyük harfe çevir (SQL COLLATE aksanı halleder). */
function api_arama_norm(string $q): string
{
    $q = trim(preg_replace('/\s+/u', ' ', $q) ?? $q);
    return function_exists('mb_strtoupper') ? mb_strtoupper($q, 'UTF-8') : strtoupper($q);
}

/** Para biçimi: 1.234,56 TL */
function api_money(float $value): string
{
    return number_format($value, 2, ',', '.') . ' TL';
}

/** Tarih biçimi: d.m.Y (boş/geçersizse '-'). */
function api_tarih($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    $ts = strtotime((string) $value);
    return $ts ? date('d.m.Y', $ts) : (string) $value;
}

/** LOGO (latin5) metnini düzelt — trcevir varsa kullan. */
function api_metin($value): string
{
    return function_exists('trcevir') ? trcevir((string) $value) : (string) $value;
}

/** Personelin bir yetki sütunu var mı (m_p_yetki '1' döndürürse var). */
function api_yetki_var(int $personel, string $kod): bool
{
    // Fail-closed: yetki fonksiyonu yoksa erişim verilmez.
    if (!function_exists('m_p_yetki')) {
        return false;
    }
    return (string) m_p_yetki($personel, $kod) === '1';
}

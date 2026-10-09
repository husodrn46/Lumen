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
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false) {
        api_json(['ok' => false, 'mesaj' => 'İstek gövdesi okunamadı.'], 400);
    }
    if (strlen($raw) > 1048576) {
        api_json(['ok' => false, 'mesaj' => 'İstek gövdesi çok büyük.'], 413);
    }
    try {
        return api_json_govde_coz($raw);
    } catch (InvalidArgumentException $e) {
        api_json(['ok' => false, 'mesaj' => 'Geçerli bir JSON nesnesi gönderiniz.'], 400);
    }
}

/** JSON gövdesi nesne olmalı; malformed/scalar/list girdi sessizce yutulmaz. */
function api_json_govde_coz(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }
    try {
        $data = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('Geçersiz JSON.', 0, $e);
    }
    if (!$data instanceof stdClass) {
        throw new InvalidArgumentException('JSON nesnesi gerekli.');
    }
    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
}

function api_token_saklama_degeri(string $token): string
{
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        throw new InvalidArgumentException('Geçersiz token biçimi.');
    }
    // İşaretçi, DB hash'inin eski düz token gibi tekrar kullanılmasını engeller.
    return 'sha256:' . hash('sha256', $token);
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
    if (preg_match('/\ABearer[ \t]+([a-f0-9]{64})\z/i', trim($hdr), $m)) {
        return strtolower($m[1]);
    }
    return '';
}

/** Firma kimliği ile SQL tablo/view önekleri aynı kapsamı göstermeli. */
function api_firma_kapsami_dogrula(): int
{
    global $firmano, $firma, $firmadonem, $firmadonemx, $eskifirmadonem, $eskifirmadonemx;
    if ((!is_int($firmano) && !is_string($firmano))
        || !preg_match('/\A[0-9]{1,3}\z/', (string) $firmano)
        || (int) $firmano < 1 || (int) $firmano > 999) {
        throw new RuntimeException('API firma numarası geçersiz.');
    }
    $f = str_pad((string) (int) $firmano, 3, '0', STR_PAD_LEFT);
    if ($firma !== 'LG_' . $f . '_'
        || !is_string($firmadonem) || !preg_match('/\ALG_' . $f . '_([0-9]{2})_\z/', $firmadonem, $m)
        || (int) $m[1] < 1 || $firmadonemx !== 'LV_' . $f . '_' . $m[1] . '_') {
        throw new RuntimeException('API firma/dönem tablo önekleri uyuşmuyor.');
    }
    if (isset($eskifirmadonem) && $eskifirmadonem !== '') {
        if (!is_string($eskifirmadonem) || !preg_match('/\ALG_' . $f . '_([0-9]{2})_\z/', $eskifirmadonem, $old)
            || (int) $old[1] < 1 || (isset($eskifirmadonemx) && $eskifirmadonemx !== 'LV_' . $f . '_' . $old[1] . '_')) {
            throw new RuntimeException('API önceki dönem firma önekleri uyuşmuyor.');
        }
    } elseif (isset($eskifirmadonemx) && $eskifirmadonemx !== '') {
        throw new RuntimeException('API önceki dönem önekleri eksik.');
    }
    return (int) $firmano;
}

/** Hash alanı hazırlığı: yalnız metadata okur, otomatik ALTER yapmaz. */
function api_token_sema_kontrol(PDO $dbh): void
{
    $stmt = $dbh->query("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'M_API_TOKEN' AND COLUMN_NAME = 'TOKEN'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    $type = strtolower((string) ($column['DATA_TYPE'] ?? ''));
    $length = (int) ($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
    if (!in_array($type, ['varchar', 'nvarchar'], true) || ($length !== -1 && $length < 71)) {
        throw new RuntimeException('M_API_TOKEN.TOKEN için veri koruyan şema hazırlığı gerekli.');
    }
}

/** Token tablosunu (M_API_TOKEN) yoksa oluştur. İlk başarılı kontrolden sonra
 *  istek başına tekrar sorgulamamak için process-level cache kullanır. */
function api_ensure_token_table(PDO $dbh): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    try {
        $stmt = $dbh->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = :t");
        $stmt->execute([':t' => 'M_API_TOKEN']);
        $exists = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($exists) {
            api_token_sema_kontrol($dbh);
            $checked = true;
            return;
        }
        $dbh->exec("CREATE TABLE dbo.M_API_TOKEN (
            ID INT IDENTITY(1,1) PRIMARY KEY,
            TOKEN VARCHAR(128) NOT NULL,
            PERSONEL INT NOT NULL,
            KULLANICI_ADI VARCHAR(64) NULL,
            OLUSTURMA DATETIME NOT NULL DEFAULT GETDATE(),
            SON_KULLANIM DATETIME NULL,
            AKTIF SMALLINT NOT NULL DEFAULT 1,
            IP VARCHAR(64) NULL
        )");
        $dbh->exec("CREATE INDEX IX_M_API_TOKEN_TOKEN ON dbo.M_API_TOKEN (TOKEN)");
        $checked = true;
    } catch (Throwable $e) {
        error_log('M_API_TOKEN olusturma: ' . $e->getMessage());
        throw new RuntimeException('Token altyapısı hazır değil.', 0, $e);
    }
}

/** Yeni token üret, sakla ve döndür. */
function api_token_olustur(PDO $dbh, int $personel, string $kullaniciAdi): string
{
    api_firma_kapsami_dogrula();
    api_ensure_token_table($dbh);
    $token = bin2hex(random_bytes(32));
    // OLUSTURMA/AKTIF acikca yazilir: tablo eski bir surumden gelip DEFAULT
    // tanimi tasimiyorsa da (NOT NULL) insert basarisiz olmasin.
    $stmt = $dbh->prepare("INSERT INTO dbo.M_API_TOKEN (TOKEN, PERSONEL, KULLANICI_ADI, IP, OLUSTURMA, AKTIF)
                           VALUES (:t, :p, :k, :ip, GETDATE(), 1)");
    $stmt->execute([
        ':t' => api_token_saklama_degeri($token),
        ':p' => $personel,
        ':k' => $kullaniciAdi,
        ':ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
    $stmt->closeCursor();
    return $token;
}

/**
 * İstekteki token'ı doğrula. Geçerliyse ['personel'=>int,'kullanici'=>string]
 * döner; değilse null. SON_KULLANIM güncellenir.
 */
function api_token_dogrula(PDO $dbh): ?array
{
    $firmano = api_firma_kapsami_dogrula();
    $token = api_bearer_token();
    if ($token === '') {
        return null;
    }
    api_ensure_token_table($dbh);
    // Token 30 günlük mutlak ömre sahip; süresi geçmişse geçersiz sayılır.
    // (DATEADD'in 2. argümanı sabit INT — sqlsrv nvarchar tuzağına düşmez.)
    $stmt = $dbh->prepare("SELECT TOP 1 T.PERSONEL, T.KULLANICI_ADI, T.TOKEN AS SAKLANAN_TOKEN
                           FROM dbo.M_API_TOKEN T
                           INNER JOIN dbo.LG_SLSMAN L ON L.LOGICALREF = T.PERSONEL
                           INNER JOIN dbo.M_P_YETKI M ON M.PERSONEL = T.PERSONEL
                           LEFT JOIN dbo.M_OTURUM_KAPAT K ON K.KULLANICI_ID = T.PERSONEL
                           WHERE (T.TOKEN = :h OR T.TOKEN = :t) AND T.AKTIF = 1
                             AND L.ACTIVE = 0 AND L.FIRMNR = :firma AND M.YETKI IN (0, 1)
                             AND (K.KAPATMA_TS IS NULL OR T.OLUSTURMA > K.KAPATMA_TS)
                             AND T.OLUSTURMA > DATEADD(DAY, -30, GETDATE())");
    $stmt->bindValue(':t', $token);
    $stmt->bindValue(':h', api_token_saklama_degeri($token));
    $stmt->bindValue(':firma', (int) $firmano, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$row) {
        return null;
    }
    // Aynı legacy token eşzamanlı yükseltilmiş olabilir; hem eski hem yeni değer
    // kabul edilir. Çıkış/pasifleştirme araya girdiyse güncelleme 0 satır olur.
    $upd = $dbh->prepare("UPDATE T SET SON_KULLANIM = GETDATE(), TOKEN = :h FROM dbo.M_API_TOKEN T
                         INNER JOIN dbo.LG_SLSMAN L ON L.LOGICALREF = T.PERSONEL
                         INNER JOIN dbo.M_P_YETKI M ON M.PERSONEL = T.PERSONEL
                         LEFT JOIN dbo.M_OTURUM_KAPAT K ON K.KULLANICI_ID = T.PERSONEL
                         WHERE (T.TOKEN = :t OR T.TOKEN = :yeni) AND T.PERSONEL = :p AND T.AKTIF = 1
                           AND L.ACTIVE = 0 AND L.FIRMNR = :firma AND M.YETKI IN (0, 1)
                           AND (K.KAPATMA_TS IS NULL OR T.OLUSTURMA > K.KAPATMA_TS)
                           AND T.OLUSTURMA > DATEADD(DAY, -30, GETDATE())");
    $hash = api_token_saklama_degeri($token);
    $upd->execute([':t' => (string) $row['SAKLANAN_TOKEN'], ':h' => $hash, ':yeni' => $hash,
        ':p' => (int) $row['PERSONEL'], ':firma' => (int) $firmano]);
    $changed = $upd->rowCount();
    $upd->closeCursor();
    if ($changed !== 1) {
        return null;
    }
    return ['personel' => (int) $row['PERSONEL'], 'kullanici' => (string) ($row['KULLANICI_ADI'] ?? '')];
}

/** Çıkış aynı kullanıcıya ait mevcut tokenı iptal eder; başka cihazları etkilemez. */
function api_token_iptal(PDO $dbh, string $token, int $personel): void
{
    $stmt = $dbh->prepare('UPDATE dbo.M_API_TOKEN SET AKTIF = 0 WHERE PERSONEL = :p AND (TOKEN = :h OR TOKEN = :t)');
    $stmt->execute([':p' => $personel, ':h' => api_token_saklama_degeri($token), ':t' => $token]);
    $stmt->closeCursor();
}

/** Mevcut ekran/eylem izinlerini API'de sorgu öncesi uygular. */
function api_yetki_gerekli(int $personel, string $kod): void
{
    if (!api_yetki_var($personel, $kod)) {
        api_json(['ok' => false, 'mesaj' => 'Bu işlem için yetkiniz yok.'], 403);
    }
}

/** Token zorunlu uçlar için: geçersizse 401 döndürüp çıkar, geçerliyse oturum dizisi döner. */
function api_oturum_gerekli(PDO $dbh): array
{
    try {
        $oturum = api_token_dogrula($dbh);
    } catch (Throwable $e) {
        error_log('API oturum doğrulama: ' . $e->getMessage());
        api_json(['ok' => false, 'mesaj' => 'Oturum hizmeti geçici olarak kullanılamıyor.'], 503);
    }
    if ($oturum === null) {
        api_json(['ok' => false, 'mesaj' => 'Oturum gecersiz veya suresi dolmus.'], 401);
    }
    // API bearer isteği cookie-session'daki 5 dakikalık web yetki cache'ini devralmaz.
    if (function_exists('m_p_yetki_cache_temizle') && isset($_SESSION) && is_array($_SESSION)) {
        m_p_yetki_cache_temizle((int) $oturum['personel']);
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

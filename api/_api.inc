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

/** JSON gövdesi nesne olmalı; bozuk, liste veya skaler girdi sessizce boş sayılmaz. */
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

/** Veritabanında tekrar Bearer olarak kullanılamayan, türü işaretli token özeti. */
function api_token_saklama_degeri(string $token): string
{
    if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
        throw new InvalidArgumentException('Geçersiz token biçimi.');
    }
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
        if ($stmt->fetchColumn()) {
            $kolon = $dbh->query("SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS
                                  WHERE TABLE_SCHEMA = 'dbo' AND TABLE_NAME = 'M_API_TOKEN' AND COLUMN_NAME = 'TOKEN'")
                         ->fetch(PDO::FETCH_ASSOC);
            $uzunluk = (int) ($kolon['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
            if (!in_array(strtolower((string) ($kolon['DATA_TYPE'] ?? '')), ['varchar', 'nvarchar'], true)
                || ($uzunluk !== -1 && $uzunluk < 71)) {
                throw new RuntimeException('M_API_TOKEN.TOKEN için şema güncellemesi gerekli.');
            }
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
    api_ensure_token_table($dbh);
    // Hesap başına tek canlı belirteç: yeni giriş öncekileri pasifleştirir; süresi
    // dolmuş ve pasif belirteçler temizlenir (tablo sınırsız büyüyordu; unutulan
    // cihazlardaki belirteçler 30 gün geçerli kalıyordu).
    try {
        $dbh->prepare("UPDATE M_API_TOKEN SET AKTIF = 0 WHERE PERSONEL = :p AND AKTIF = 1")->execute([':p' => $personel]);
        $dbh->exec("DELETE FROM M_API_TOKEN WHERE OLUSTURMA < DATEADD(DAY, -30, GETDATE())");
    } catch (Throwable $e) {
        error_log('api_token_olustur temizlik: ' . $e->getMessage());
    }
    $token = bin2hex(random_bytes(32));
    // Veritabanında belirtecin kendisi değil SHA-256 özeti saklanır: tablo okunursa
    // (yedek, log, SQL erişimi) belirteçler doğrudan kullanılamaz.
    // OLUSTURMA/AKTIF acikca yazilir: tablo eski bir surumden gelip DEFAULT
    // tanimi tasimiyorsa da (NOT NULL) insert basarisiz olmasin.
    $stmt = $dbh->prepare("INSERT INTO M_API_TOKEN (TOKEN, PERSONEL, KULLANICI_ADI, IP, OLUSTURMA, AKTIF)
                           VALUES (:t, :p, :k, :ip, GETDATE(), 1)");
    $stmt->execute([
        ':t' => api_token_saklama_degeri($token),
        ':p' => $personel,
        ':k' => $kullaniciAdi,
        ':ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    ]);
    return $token;
}

/**
 * İstekteki token'ı doğrula. Geçerliyse ['personel'=>int,'kullanici'=>string]
 * döner; değilse null. SON_KULLANIM güncellenir.
 *
 * Belirteç her istekte kullanıcının GÜNCEL durumuna göre doğrulanır: pasif
 * yapılan, müşteri yetkisine düşürülen veya oturumları yönetici tarafından
 * kapatılan kullanıcının belirteci artık 30 gün boyunca çalışmaya devam etmez.
 * Veritabanında yalnız "sha256:" işaretli özet kabul edilir; özetin kendisi
 * Bearer olarak tekrar kullanılamaz.
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
    $stmt = $dbh->prepare("SELECT TOP 1 T.ID, T.PERSONEL, T.KULLANICI_ADI, T.OLUSTURMA, Y.YETKI
                           FROM M_API_TOKEN T
                           INNER JOIN {$GLOBALS['LUMEN_SLSMAN']} S ON S.LOGICALREF = T.PERSONEL AND S.ACTIVE = 0
                           INNER JOIN M_P_YETKI Y ON Y.PERSONEL = T.PERSONEL AND Y.YETKI IN (0, 1, 3)
                           WHERE T.TOKEN = :ozet AND T.AKTIF = 1
                             AND T.OLUSTURMA > DATEADD(DAY, -30, GETDATE())");
    $stmt->execute([':ozet' => api_token_saklama_degeri($token)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    try {
        $kapat = $dbh->prepare("SELECT 1 FROM M_OTURUM_KAPAT
                                WHERE KULLANICI_ID = :p AND KAPATMA_TS > (SELECT OLUSTURMA FROM M_API_TOKEN WHERE ID = :id)");
        $kapat->execute([':p' => (int) $row['PERSONEL'], ':id' => (int) $row['ID']]);
        if ($kapat->fetchColumn()) {
            return null;
        }
    } catch (Throwable $e) {
        // M_OTURUM_KAPAT yoksa zorla çıkış hiç uygulanmamış demektir.
    }
    $upd = $dbh->prepare("UPDATE M_API_TOKEN SET SON_KULLANIM = GETDATE() WHERE ID = :id");
    $upd->execute([':id' => (int) $row['ID']]);
    return ['personel' => (int) $row['PERSONEL'], 'kullanici' => (string) ($row['KULLANICI_ADI'] ?? ''),
            'salt_okunur' => (int) $row['YETKI'] === LUMEN_YETKI_ENTEGRASYON];
}

/** Geçerli tokenı yalnız kendi kullanıcısı kapsamında iptal eder. */
function api_token_iptal(PDO $dbh, string $token, int $personel): void
{
    $stmt = $dbh->prepare('UPDATE dbo.M_API_TOKEN SET AKTIF = 0 WHERE PERSONEL = :p AND TOKEN = :t');
    $stmt->execute([':p' => $personel, ':t' => api_token_saklama_degeri($token)]);
}

/**
 * Entegrasyon (salt okunur) hesabının çağırabildiği uçlar. Liste "izin verilen"
 * mantığıyla tutulur: buraya eklenmeyen her uç — sonradan eklenenler dahil —
 * bu hesaba kapalıdır (yazma uçları yanlışlıkla açılmaz).
 *
 * @return string[]
 */
function api_okuma_uclari(): array
{
    return [
        'logout.php',
        'bekleyen_siparis.php', 'cari_ara.php', 'cari_detay.php', 'cari_hareket.php', 'cari_hareket_detay.php',
        'cek_listesi.php', 'fatura_listesi.php', 'fiyat_listesi.php',
        'rapor_borc_alacak.php', 'rapor_cek_vade.php', 'rapor_karsilastirmali.php', 'rapor_kasa.php',
        'rapor_musteriler_top.php', 'rapor_nakit_akis.php', 'rapor_pazarlamaci.php', 'rapor_satis_aylik.php',
        'rapor_top_satis.php', 'rapor_yaslandirma.php',
        'siparis_detay_getir.php', 'siparis_listesi.php', 'siparis_onizle.php',
        'stok_ara.php', 'stok_birimleri.php',
    ];
}

/** Token zorunlu uçlar için: geçersizse 401 döndürüp çıkar, geçerliyse oturum dizisi döner. */
function api_oturum_gerekli(PDO $dbh): array
{
    $oturum = api_token_dogrula($dbh);
    if ($oturum === null) {
        api_json(['ok' => false, 'mesaj' => 'Oturum gecersiz veya suresi dolmus.'], 401);
    }
    if (!empty($oturum['salt_okunur'])
        && !in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), api_okuma_uclari(), true)) {
        api_json(['ok' => false, 'mesaj' => 'Entegrasyon hesabi yalniz okuma yapabilir.'], 403);
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

// Saldırı modu: web arayüzü yerel ağ dışına kapatılırken API açık kalıyordu
// (mevcut belirteçlerle yazma dahil). Tüm API uçları için aynı kural.
if (function_exists('saldiri_modu_erisim_engelli_mi') && saldiri_modu_erisim_engelli_mi()) {
    api_json(['ok' => false, 'mesaj' => 'Sistem şu anda yalnız yerel ağdan erişilebilir.'], 403);
}

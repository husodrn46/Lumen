<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/support/TestPdo.php';
require __DIR__ . '/../api/_siparis.inc';
require __DIR__ . '/../api/_api.inc';
require __DIR__ . '/../includes/kurulum_lib.php';
require __DIR__ . '/support/sqlserver-environment.php';

$assertions = 0;
function check(bool $ok, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$ok) { throw new RuntimeException($message); }
}
function throws(Closure $fn, string $class): void
{
    try { $fn(); } catch (Throwable $e) { check($e instanceof $class, 'Beklenmeyen hata: ' . get_class($e)); return; }
    check(false, 'Beklenen hata oluşmadı: ' . $class);
}
// Yetki sisteminin sonucu fixture'dır; gerçek yetki SQL'ini bu suite sınamaz.
$allowed = true;
$permissionCalls = [];
function m_p_cariid_goruntulebilir_mi(PDO $dbh, string $firma, int $personel, int $cari): bool
{
    global $allowed, $permissionCalls;
    $permissionCalls[] = [$firma, $personel, $cari];
    return $allowed;
}

check(siparis_sayi('1,25') === 1.25, 'Virgüllü sayı');
foreach (['12abc', '', 'NaN', 'INF', '1e999', [], true, null] as $value) {
    check(!is_finite(siparis_sayi($value)), 'Geçersiz sayı reddedilmeli');
}
$units = [['carpan' => 1.0], ['carpan' => 12.0], ['carpan' => 0.5]];
check(siparis_birim_carpani_dogrula($units, 12.0) === 12.0, 'Kayıtlı koli');
check(siparis_birim_carpani_dogrula($units, 0.5) === 0.5, 'Kesirli tanımlı birim');
foreach ([999.0, 0.0, -1.0, NAN, INF] as $factor) {
    check(siparis_birim_carpani_dogrula($units, $factor) === null, 'Uydurma birim');
}
check(siparis_birim_carpani_dogrula([], 1.0) === null, 'Tanımsız ana birim kabul edilmemeli');

$card = ['id' => 5, 'code' => 'DEMO-5', 'name' => 'Sentetik ürün', 'vat' => 20,
    'unitsetref' => 2, 'unit_code' => 'ADET', 'onhand' => 100, 'price' => 10, 'uomref' => 7];
$pdo = new TestPdo(static function (string $sql) use ($card): array {
    if (str_contains($sql, 'AS carpan')) { return ['rows' => [['kod' => 'ADET', 'carpan' => 1], ['kod' => 'KOLI', 'carpan' => 12]]]; }
    return ['rows' => [$card]];
});
$valid = siparis_kalemleri_hazirla($pdo, 'LG_002_', 'LV_002_03_', [['stok_id' => 5, 'miktar' => 2, 'birim_carpan' => 12]]);
check(count($valid['ready']) === 1 && $valid['ready'][0]['ana_miktar'] === 24.0, 'Sunucu birimi ana miktara dönmeli');
$bad = siparis_kalemleri_hazirla($pdo, 'LG_002_', 'LV_002_03_', [['stok_id' => 5, 'miktar' => 2, 'birim_carpan' => 999]]);
check($bad['ready'] === [] && count($bad['skipped']) === 1, 'Uydurma birim kalemi yazmaya hazır olmamalı');
foreach ([['miktar' => '12abc'], ['fiyat' => '1e999'], ['kdv' => 'yanlış'], ['kdv' => 101]] as $override) {
    $bad = siparis_kalemleri_hazirla($pdo, 'LG_002_', 'LV_002_03_', [array_merge(['stok_id' => 5, 'miktar' => 2], $override)]);
    check($bad['ready'] === [], 'Geçersiz kalem yazmaya hazır olmamalı');
}
$sum = siparis_toplam_hesapla($valid['ready'], 10.0, 5.0);
check(abs($sum['net_toplam'] - 205.2) < 0.00001 && abs($sum['genel_toplam'] - 246.24) < 0.00001, 'İki kademeli mevcut yuvarlama/KDV sözleşmesi korunmalı');

$pdo = new TestPdo(static fn() => ['sets' => [[], [['trigger_id' => 900]], [['LUMEN_INSERTED_ID' => 42]]]]);
throws(static fn() => siparis_baslik_insert_hazirla($pdo, 'INSERT INTO LG_002_03_ORFICHE(FICHENO) VALUES (:n)'), LogicException::class);
$pdo->beginTransaction();
$stmt = siparis_baslik_insert_hazirla($pdo, 'INSERT INTO LG_002_03_ORFICHE(FICHENO) VALUES (:n)');
$stmt->execute([':n' => 'SIP000042']);
check(siparis_baslik_eklenen_id($stmt) === 42 && $stmt->closed, 'Trigger sonucunu kimlik sanmamalı; cursor kapanmalı');
check(str_contains($pdo->events[0]['sql'], 'OUTPUT INSERTED.LOGICALREF INTO'), 'Trigger-safe OUTPUT INTO sözleşmesi');
foreach ([[], [['LUMEN_INSERTED_ID' => 0]], [['LUMEN_INSERTED_ID' => 2], ['LUMEN_INSERTED_ID' => 3]]] as $rows) {
    $p = new TestPdo(static fn() => ['rows' => $rows]);
    $p->beginTransaction(); $s = siparis_baslik_insert_hazirla($p, 'INSERT INTO X(A) VALUES (:a)'); $s->execute();
    throws(static fn() => siparis_baslik_eklenen_id($s), RuntimeException::class);
    check($s->closed, 'Hatalı kimlikte cursor kapanmalı');
}
throws(static fn() => siparis_baslik_tablosu('LG_002_03_; DROP TABLE X'), InvalidArgumentException::class);
$pdo = new TestPdo(static fn() => ['rows' => [['next' => 9]], 'affected' => 1]);
$pdo->beginTransaction();
check(siparis_baslik_numarasi_ayir($pdo, 'LG_002_03_', 'SIP') === 'SIP000009', 'Numara biçimi');
check(str_contains($pdo->events[0]['sql'], 'UPDLOCK, HOLDLOCK'), 'Numara için aralık kilidi');
check(siparis_baslik_numarasini_kesinlestir($pdo, 'LG_002_03_', 20, 'SIP') === 'SIP000020', 'Identity boşluğu sonrası gerçek numara');
check($pdo->events[1]['params'][':id'] === 20, 'Başka kaydı numaralandırmamalı');

$pdo = new TestPdo(static fn() => ['rows' => [['LOGICALREF' => 10, 'FICHENO' => 'SIP000010', 'CLIENTREF' => 60]]]);
$allowed = false;
check(siparis_fis_duzenlenebilir($pdo, 'LG_002_03_', 10, 'LG_002_', 7) === null, 'Kısıtlı cari düzenlenememeli');
check(end($permissionCalls) === ['LG_002_', 7, 60], 'Politika gerçek fiş carisi ve oturum kullanıcısına uygulanmalı');
$allowed = true;
check(siparis_fis_duzenlenebilir($pdo, 'LG_002_03_', 10, 'LG_002_', 8)['id'] === 10, 'Yetkili başka personel ortak siparişi düzenleyebilmeli');
throws(static fn() => siparis_fis_duzenlenebilir($pdo, 'LG_002_03_', 10, 'LG_001_', 7), InvalidArgumentException::class);

check(api_json_govde_coz('{"kalemler":[{"miktar":2}]}')['kalemler'][0]['miktar'] === 2, 'JSON nesnesi ve iç listeler');
foreach (['{', '[]', 'null', '12', 'true', '"metin"'] as $json) {
    throws(static fn() => api_json_govde_coz($json), InvalidArgumentException::class);
}
$token = str_repeat('a', 64);
$stored = api_token_saklama_degeri($token);
check($stored !== $token && strlen($stored) === 71, 'Token geri döndürülemez, işaretli 128 karakter alanına sığar');
check(api_token_saklama_degeri(substr($stored, 7)) !== $stored, 'DB token özeti bearer olarak replay edilememeli');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token . ' trailing';
check(api_bearer_token() === '', 'Token sonundaki ekstra veri reddedilmeli');
$firmano = 2; $firma = 'LG_002_'; $firmadonem = 'LG_002_03_'; $firmadonemx = 'LV_002_03_';
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('a', 64);
$active = false;
$pdo = new TestPdo(static function (string $sql, array $params) use (&$active): array {
    if (str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')) { return ['rows' => [['DATA_TYPE' => 'varchar', 'CHARACTER_MAXIMUM_LENGTH' => 128]]]; }
    if (str_contains($sql, 'INFORMATION_SCHEMA.TABLES')) { return ['rows' => [['exists' => 1]]]; }
    if (str_starts_with($sql, 'SELECT TOP 1 T.PERSONEL')) {
        check($params[':firma'] === 2 && str_contains($sql, 'L.ACTIVE = 0')
            && str_contains($sql, 'M.YETKI IN (0, 1)') && str_contains($sql, 'T.OLUSTURMA > K.KAPATMA_TS'), 'Token aktif personel ve kurulum firmasıyla sınırlı');
        return ['rows' => $active ? [['PERSONEL' => 7, 'KULLANICI_ADI' => 'DEMO', 'SAKLANAN_TOKEN' => str_repeat('a', 64)]] : []];
    }
    return ['affected' => 1];
});
check(api_token_dogrula($pdo) === null, 'Pasif/başka firma tokenı reddedilmeli');
check(count($pdo->events) === 3, 'Reddedilen tokenın son kullanımını yazmamalı');
$active = true;
check(api_token_dogrula($pdo)['personel'] === 7, 'Aktif doğru firma tokenı');
$event = end($pdo->events);
check($event['params'][':h'] === $stored && $event['params'][':t'] === $token, 'Legacy token doğrulamadan sonra özet olarak saklanmalı');
api_token_iptal($pdo, $token, 7);
$event = end($pdo->events);
check($event['params'][':p'] === 7 && str_contains($event['sql'], 'AKTIF = 0'), 'Çıkış yalnız o personelin tokenını iptal etmeli');

foreach ([[], true, 1.5, '1e2', '12x', '2147483648', -1, 0] as $value) {
    check(siparis_kimlik($value) === 0, 'Cast ile yanlış kimlik oluşmamalı');
}
check(siparis_kimlik('00042') === 42 && siparis_kimlik(42) === 42, 'Int/sayı metni istemci uyumluluğu');
foreach ([['varchar', 64], ['char', 128], ['varchar', 0]] as [$type, $size]) {
    $schema = new TestPdo(static fn() => ['rows' => [['DATA_TYPE' => $type, 'CHARACTER_MAXIMUM_LENGTH' => $size]]]);
    throws(static fn() => api_token_sema_kontrol($schema), RuntimeException::class);
    check(count($schema->events) === 1 && str_starts_with($schema->events[0]['sql'], 'SELECT'), 'Schema gate yalnız metadata okur');
}
$schema = new TestPdo(static fn() => ['rows' => [['DATA_TYPE' => 'nvarchar', 'CHARACTER_MAXIMUM_LENGTH' => -1]]]);
api_token_sema_kontrol($schema);
check(count($schema->events) === 1, 'NVARCHAR MAX hash saklayabilir');
$revoked = new TestPdo(static function (string $sql): array {
    if (str_starts_with($sql, 'SELECT TOP 1 T.PERSONEL')) { return ['rows' => [['PERSONEL' => 7, 'KULLANICI_ADI' => 'DEMO', 'SAKLANAN_TOKEN' => str_repeat('a', 64)]]]; }
    return ['affected' => 0];
});
check(api_token_dogrula($revoked) === null, 'Okuma ile güncelleme arasında iptal edilen token oturum vermemeli');
$event = end($revoked->events);
check(str_contains($event['sql'], 'T.AKTIF = 1') && str_contains($event['sql'], 'T.TOKEN = :yeni') && str_contains($event['sql'], 'L.FIRMNR = :firma'), 'Token güncelleme scope/lifecycle yeniden kontrol eder');

check(lumen_test_sql_database('sqlsrv:Server=localhost,1433;Database=LumenTest_001') === 'LumenTest_001', 'Local isolated DSN accepted');
foreach (['sqlsrv:Server=AKEL-SRV01;Database=LumenTest_001', 'sqlsrv:Server=localhost;Database=LOGO',
    'sqlsrv:Server=localhost;Database=LumenTest_001;Server=remote', 'sqlsrv:Server=localhost;Database=LumenTest_001;UID=other'] as $dsn) {
    throws(static fn() => lumen_test_sql_database($dsn), InvalidArgumentException::class);
}

check(api_firma_kapsami_dogrula() === 2, 'Complete consistent API scope');
$scopePdo = new TestPdo(static fn() => []);
$firma = 'LG_001_';
throws(static fn() => api_token_dogrula($scopePdo), RuntimeException::class);
check($scopePdo->events === [], 'Wrong company prefix rejected before any token/ERP SQL');
$firma = 'LG_002_'; $firmadonemx = 'LV_001_03_';
throws(static fn() => api_firma_kapsami_dogrula(), RuntimeException::class);
$firmadonemx = 'LV_002_03_'; $eskifirmadonem = 'LG_001_02_';
throws(static fn() => api_firma_kapsami_dogrula(), RuntimeException::class);
unset($eskifirmadonem); $firmano = '2.5';
throws(static fn() => api_firma_kapsami_dogrula(), RuntimeException::class);
$firmano = 2;
$emptyCari = new TestPdo(static fn() => ['rows' => [['LOGICALREF' => 10, 'FICHENO' => 'SIP000010', 'CLIENTREF' => 0]]]);
check(siparis_fis_duzenlenebilir($emptyCari, 'LG_002_03_', 10, 'LG_002_', 7) === null, 'Missing header cari reference fails closed');

$prefix = kurulum_onekler(2, 3);
check($prefix['FIRMA_DONEM'] === 'LG_002_03_' && $prefix['FIRMA_ESKI_DONEM'] === 'LG_002_02_', 'Firma/dönem türetme');
throws(static fn() => kurulum_onekler(1000, 1), InvalidArgumentException::class);
throws(static fn() => kurulum_onekler(1, 0), InvalidArgumentException::class);
$pdo = new TestPdo(static function (string $sql): array {
    if (str_contains($sql, '[M_GIRIS_LOG]')) { throw new RuntimeException('Fixture: eksik giriş tablosu'); }
    return [];
});
throws(static fn() => kurulum_sema_kontrol($pdo, $prefix), RuntimeException::class);
check(count(array_filter($pdo->events, static fn($e) => !str_starts_with($e['sql'], 'SELECT TOP 0'))) === 0, 'Önkontrol veri/şema yazmamalı');
$viewExists = false;
$executed = [];
$pdo = new TestPdo(static function (string $sql) use (&$viewExists, &$executed): array {
    if (str_starts_with($sql, 'SELECT TOP 0')) { return []; }
    if (str_starts_with($sql, 'SELECT OBJECT_ID')) { return ['rows' => [['id' => $viewExists ? 1 : null]]]; }
    $executed[] = $sql;
    if (str_contains($sql, 'CREATE VIEW')) { $viewExists = true; }
    return [];
});
$result = kurulum_sema_uygula($pdo, __DIR__ . '/../sql', $prefix);
check($result['hatalar'] === [] && $result['basarili'] === 14, 'Temiz kurulum kontrol akışı');
check(str_contains($executed[0], 'CREATE TABLE M_P_YETKI'), 'İzin tablosu bağımlı betiklerden önce');
$view = array_values(array_filter($executed, static fn($sql) => str_contains($sql, 'CREATE VIEW')))[0];
check(str_contains($view, 'dbo.LG_002_03_ORFLINE') && str_contains($view, 'dbo.LV_002_03_GNTOTCL'), 'View seçilen firma/dönemi kullanmalı');
check(!str_contains($view, 'FROM dbo.LG_001_'), 'Başka firmanın view tablosuna yönelmemeli');
$executed = [];
$result = kurulum_sema_uygula($pdo, __DIR__ . '/../sql', $prefix);
check($result['hatalar'] === [] && count(array_filter($executed, static fn($sql) => str_contains($sql, 'ALTER VIEW'))) === 1, 'İkinci kurulum var olan view için ALTER');
$pdo = new TestPdo(static function (string $sql): array {
    if (str_contains($sql, 'CREATE TABLE M_P_YETKI')) { throw new RuntimeException('Fixture: DDL yetkisi yok'); }
    return [];
});
$result = kurulum_sema_uygula($pdo, __DIR__ . '/../sql', $prefix);
check(count($result['hatalar']) === 1 && $result['basarili'] === 0, 'İlk şema hatasında durmalı');
check(count(array_filter($pdo->events, static fn($e) => !str_starts_with($e['sql'], 'SELECT TOP 0'))) === 1, 'Şema hatasından sonra sonraki betik çalışmamalı');

echo "TAMAM: {$assertions} assertion (PDO test double; gerçek SQL/HTTP testi değil).\n";

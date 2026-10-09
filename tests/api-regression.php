<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$checks = 0;
function verify(bool $ok, string $message): void {
    global $checks; $checks++;
    if (!$ok) { throw new RuntimeException($message); }
}
function endpoint(array $config): array {
    $pipes = [];
    $p = proc_open([PHP_BINARY, __DIR__ . '/support/endpoint.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { throw new RuntimeException('Fixture process açılamadı'); }
    fwrite($pipes[0], json_encode($config, JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($p);
    if ($status !== 0) { throw new RuntimeException('Fixture hatası: ' . $err . $out); }
    return json_decode($out, true, 64, JSON_THROW_ON_ERROR);
}
function writes(array $result): array {
    return array_values(array_filter($result['events'], static fn($e) => preg_match('/\b(INSERT INTO|UPDATE|DELETE)\b/i', $e['sql'])));
}
$body = ['cari_id' => 60, 'kalemler' => [['stok_id' => 5, 'miktar' => 2], ['stok_id' => 5, 'miktar' => 3]]];
$r = endpoint(['endpoint' => 'siparis_olustur', 'body' => $body, 'permissions' => ['M1']]);
verify($r['status'] === 200 && $r['payload']['fis']['id'] === 42, 'Oluşturma kendi INSERT kimliğini kullanmalı');
verify($r['payload']['fis']['fisno'] === 'SIP000042', 'Numara gerçek kimlikten türemeli');
$lines = array_values(array_filter($r['events'], static fn($e) => str_contains($e['sql'], 'INSERT INTO LG_002_03_ORFLINE')));
verify(count($lines) === 2 && $lines[0]['params'][':stokhareket'] === 42 && $lines[1]['params'][':stokhareket'] === 42, 'Her satır kendi başlığına bağlanmalı');
verify($r['transactions'] === ['begin', 'commit'], 'Başlık/satır/toplam tek transaction');
$r = endpoint(['endpoint' => 'siparis_olustur', 'body' => $body, 'permissions' => ['M1'], 'fail_line' => 2]);
verify($r['status'] === 500 && $r['transactions'] === ['begin', 'rollback'], 'Satır ortası hata rollback çağırmalı, başarı dönmemeli');
foreach (['siparis_olustur', 'siparis_onizle'] as $name) {
    $r = endpoint(['endpoint' => $name, 'body' => $body, 'permissions' => ['M1'], 'cari_allowed' => false]);
    verify($r['status'] === 404 && writes($r) === [], 'Kısıtlı cari önizleme/oluşturma: ' . $name);
    $r = endpoint(['endpoint' => $name, 'body' => $body, 'permissions' => []]);
    verify($r['status'] === 403 && $r['events'] === [], 'Eylem izni sorgudan önce: ' . $name);
}
foreach (['siparis_miktar_guncelle', 'siparis_satir_sil', 'siparis_satir_ekle', 'siparis_iptal'] as $name) {
    $r = endpoint(['endpoint' => $name, 'body' => ['satir_id' => 4, 'fis_id' => 10, 'order_id' => 10, 'stok_id' => 5, 'miktar' => 2],
        'permissions' => ['M1', 'M10'], 'cari_allowed' => false]);
    verify($r['status'] === 404 && writes($r) === [], 'Yasak cari satır ID ile aşılamamalı: ' . $name);
    $r = endpoint(['endpoint' => $name, 'body' => [], 'permissions' => []]);
    verify($r['status'] === 403 && $r['events'] === [], 'Eylem izni sorgudan önce: ' . $name);
}
foreach (['birim_carpan' => 999, 'miktar' => '12abc', 'fiyat' => '1e999', 'kdv' => 'NaN'] as $key => $value) {
    $b = ['cari_id' => 60, 'kalemler' => [array_merge(['stok_id' => 5, 'miktar' => 2], [$key => $value])]];
    $r = endpoint(['endpoint' => 'siparis_olustur', 'body' => $b, 'permissions' => ['M1']]);
    verify($r['status'] === 422 && writes($r) === [], 'Geçersiz kalem fiş açmamalı: ' . $key);
}
foreach (['miktar' => 'NaN', 'fiyat' => '12abc', 'kdv' => 101] as $key => $value) {
    $r = endpoint(['endpoint' => 'siparis_miktar_guncelle', 'body' => array_merge(['satir_id' => 4, 'miktar' => 2], [$key => $value]), 'permissions' => ['M1']]);
    verify($r['status'] === 422 && writes($r) === [], 'Geçersiz güncelleme yazmamalı: ' . $key);
}
foreach (['rapor_kasa', 'rapor_nakit_akis', 'rapor_satis_aylik', 'rapor_karsilastirmali', 'rapor_top_satis', 'fiyat_listesi', 'bekleyen_siparis'] as $name) {
    $r = endpoint(['endpoint' => $name, 'body' => [], 'permissions' => []]);
    verify($r['status'] === 403 && $r['events'] === [], 'Modül izni olmadan sorgu yok: ' . $name);
}
$r = endpoint(['endpoint' => 'siparis_olustur', 'method' => 'GET', 'permissions' => ['M1']]);
verify($r['status'] === 405 && $r['events'] === [], 'GET yazmamalı');
foreach ([[], true, 60.5, '60x'] as $invalid) {
    $r = endpoint(['endpoint' => 'siparis_olustur', 'body' => ['cari_id' => $invalid, 'kalemler' => $body['kalemler']], 'permissions' => ['M1']]);
    verify($r['status'] === 400 && $r['events'] === [], 'Malformed cari ID cannot target another record');
    $r = endpoint(['endpoint' => 'siparis_miktar_guncelle', 'body' => ['satir_id' => $invalid, 'miktar' => 2], 'permissions' => ['M1']]);
    verify($r['status'] === 400 && $r['events'] === [], 'Malformed line ID rejected before DB');
}
foreach ([[], true, 5.5, '5x'] as $invalid) {
    $r = endpoint(['endpoint' => 'siparis_olustur', 'body' => ['cari_id' => 60, 'kalemler' => [['stok_id' => $invalid, 'miktar' => 2]]], 'permissions' => ['M1']]);
    verify($r['status'] === 422 && writes($r) === [], 'Malformed stock ID cannot become stock 1/5');
}
foreach (['siparis_olustur', 'siparis_onizle', 'siparis_satir_ekle', 'siparis_satir_sil', 'siparis_miktar_guncelle', 'siparis_iptal'] as $name) {
    $r = endpoint(['endpoint' => $name, 'body' => ['cari_id' => 60, 'kalemler' => $body['kalemler'], 'fis_id' => 10, 'satir_id' => 4, 'order_id' => 10, 'stok_id' => 5, 'miktar' => 2],
        'permissions' => ['M1', 'M10'], 'fail_read' => $name === 'siparis_olustur' || $name === 'siparis_onizle' ? 'CLCARD' : 'ORFICHE']);
    verify($r['status'] === 500 && $r['payload']['ok'] === false && writes($r) === [], 'DB read failure returns JSON before write: ' . $name);
    verify(!str_contains($r['payload']['mesaj'], 'Synthetic'), 'Read error details private');
}
$r = endpoint(['endpoint' => 'siparis_olustur', 'body' => ['cari_id' => '60', 'kalemler' => [['stok_id' => '5', 'miktar' => 2]]], 'permissions' => ['M1']]);
verify($r['status'] === 200, 'Existing numeric-string IDs accepted');
// Two independent identical requests both attempt an INSERT: no idempotency claim.
$retry = endpoint(['endpoint' => 'siparis_olustur', 'body' => ['cari_id' => '60', 'kalemler' => [['stok_id' => '5', 'miktar' => 2]]], 'permissions' => ['M1']]);
verify(count(array_filter(writes($r), static fn($e) => str_contains($e['sql'], 'DECLARE @lumen_eklenen'))) === 1
    && count(array_filter(writes($retry), static fn($e) => str_contains($e['sql'], 'DECLARE @lumen_eklenen'))) === 1, 'Retry still attempts another header: explicitly uncovered idempotency requirement');
echo "TAMAM: {$checks} API assertion (CLI gövde harness; gerçek HTTP/SQL değil).\n";

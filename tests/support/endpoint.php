<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** CLI harness: gerçek endpoint gövdesi, sentetik oturum/body/PDO. HTTP testi değil. */
require __DIR__ . '/TestPdo.php';
require __DIR__ . '/../../api/_siparis.inc';
require __DIR__ . '/../../api/_idempotency.inc';
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$config = $input;
$files = ['siparis_olustur', 'siparis_miktar_guncelle', 'siparis_satir_sil', 'siparis_satir_ekle',
    'siparis_iptal', 'siparis_onizle', 'rapor_kasa', 'rapor_nakit_akis', 'rapor_satis_aylik',
    'rapor_karsilastirmali', 'rapor_top_satis', 'fiyat_listesi', 'bekleyen_siparis'];
final class ApiResponse extends RuntimeException
{
    public function __construct(public array $payload, public int $status) { parent::__construct(); }
}
function api_json($payload, int $code = 200): void { throw new ApiResponse($payload, $code); }
function api_body(): array { global $config; return $config['body'] ?? []; }
function api_oturum_gerekli(PDO $pdo): array { global $config; return ['personel' => $config['personel'] ?? 7, 'kullanici' => 'DEMO']; }
function api_yetki_var(int $personel, string $kod): bool { global $config; return in_array($kod, $config['permissions'] ?? [], true); }
function api_yetki_gerekli(int $personel, string $kod): void { if (!api_yetki_var($personel, $kod)) { api_json(['ok' => false], 403); } }
function m_p_cariid_goruntulebilir_mi(PDO $dbh, string $firma, int $personel, int $cari): bool { global $config; return $config['cari_allowed'] ?? true; }
function api_money(float $value): string { return number_format($value, 2, ',', '.') . ' TL'; }
function guid(): string { return '11111111-1111-4111-8111-111111111111'; }
function intcevir(mixed $value): int { return (int) $value; }
$lineWrites = 0; $idempotRecords = []; $headers = []; $lines = []; $nextId = 41; $snapshot = [];
$pdo = new TestPdo(static function (string $sql, array $params) use (&$config, &$lineWrites, &$idempotRecords, &$headers, &$lines, &$nextId): array {
    if (str_contains($sql, 'SELECT TOP 0') && str_contains($sql, 'M_API_IDEMPOTENCY')) {
        if ($config['missing_idempotency_schema'] ?? false) { throw new RuntimeException('Fixture missing schema'); } return [];
    }
    if (str_contains($sql, 'sys.key_constraints')) { return ['rows' => [[1]]]; }
    if (str_contains($sql, 'sp_getapplock')) { return ['rows' => [['LUMEN_LOCK_RESULT' => $config['lock_result'] ?? 0]]]; }
    if (str_contains($sql, 'FROM dbo.M_API_IDEMPOTENCY WHERE')) { return ['rows' => isset($idempotRecords[$params[':key']]) ? [$idempotRecords[$params[':key']]] : []]; }
    if (str_contains($sql, 'INSERT INTO dbo.M_API_IDEMPOTENCY')) {
        if ($config['fail_receipt'] ?? false) { throw new RuntimeException('Fixture receipt failure'); }
        if (isset($idempotRecords[$params[':key']])) { throw new RuntimeException('Fixture unique key collision'); }
        $idempotRecords[$params[':key']] = ['FIRMA' => $params[':firma'], 'DONEM' => $params[':donem'], 'PERSONEL' => $params[':personel'], 'KAPSAM' => $params[':scope'], 'BODYHASH' => $params[':hash'], 'YANIT' => $params[':response']];
        return ['affected' => 1];
    }
    if (($config['fail_read'] ?? '') !== '' && str_contains($sql, $config['fail_read'])) { throw new RuntimeException('Synthetic read failure'); }
    if (str_contains($sql, 'UPDLOCK, HOLDLOCK')) { return ['rows' => [['next' => 90]]]; }
    if (str_contains($sql, 'DECLARE @lumen_eklenen')) { $id = ++$nextId; $headers[] = $id; return ['sets' => [[], [['trigger_identity' => 999]], [['LUMEN_INSERTED_ID' => $id]]]]; }
    if (str_contains($sql, 'INSERT INTO') && str_contains($sql, 'ORFLINE')) {
        $lineWrites++;
        if (($config['fail_line'] ?? 0) === $lineWrites) { throw new RuntimeException('Fixture: satır yazma hatası'); }
        $lines[] = $params; return ['affected' => 1];
    }
    if (str_contains($sql, 'UPDATE') || str_contains($sql, 'DELETE')) { return ['affected' => 1]; }
    if (str_contains($sql, 'AS carpan')) { return ['rows' => [['kod' => 'ADET', 'carpan' => 1], ['kod' => 'KOLI', 'carpan' => 12]]]; }
    if (str_contains($sql, 'S.UNITSETREF')) {
        return ['rows' => [['id' => 5, 'code' => 'DEMO-5', 'name' => 'Sentetik ürün', 'vat' => 20,
            'unitsetref' => 2, 'unit_code' => 'ADET', 'onhand' => 100, 'price' => 10, 'uomref' => 7]]];
    }
    if (str_contains($sql, 'CLCARD')) { return ['rows' => [['LOGICALREF' => 60, 'CODE' => 'DEMO-C', 'DEFINITION_' => 'Sentetik cari']]]; }
    if (str_contains($sql, 'SELECT TOP 1 L.LOGICALREF')) {
        return ['rows' => [['LOGICALREF' => 4, 'ORDFICHEREF' => 10, 'LINETYPE' => 0, 'STOCKREF' => 5,
            'AMOUNT' => 2, 'PRICE' => 10, 'VAT' => 20, 'TOTAL' => 20, 'VATMATRAH' => 20,
            'SHIPPEDAMOUNT' => 0, 'LINEEXP' => '', 'CODE' => 'DEMO-5', 'NAME' => 'Sentetik ürün']]];
    }
    if (str_contains($sql, 'SELECT TOP 1 LOGICALREF, FICHENO, CLIENTREF')) {
        return ['rows' => [['LOGICALREF' => 10, 'FICHENO' => 'SIP000010', 'CLIENTREF' => 60]]];
    }
    if (str_contains($sql, 'SUM(VATAMNT)')) { return ['rows' => [['KDVTUTAR' => 4, 'TUTAR' => 20, 'ISK' => 0]]]; }
    if (str_contains($sql, 'SUM(CASE')) { return ['rows' => [['shipped' => 0]]]; }
    if (str_contains($sql, 'SELECT LOGICALREF FROM') || str_contains($sql, 'SELECT MAX(LINENO_)')) { return ['rows' => []]; }
    throw new RuntimeException('Fixture beklenmeyen SQL: ' . $sql);
}, static function (string $event) use (&$config, &$snapshot, &$idempotRecords, &$headers, &$lines): void {
    if ($event === 'begin') { $snapshot = [$idempotRecords, $headers, $lines]; }
    if ($event === 'rollback') { [$idempotRecords, $headers, $lines] = $snapshot; }
    if ($event === 'before_commit' && ($config['fail_commit_before'] ?? false)) { throw new RuntimeException('Fixture commit failed before persistence'); }
    if ($event === 'commit' && ($config['commit_ack_lost'] ?? false)) { throw new RuntimeException('Fixture commit acknowledgement lost'); }
});
$dbh = $pdo;
$responses = [];
foreach ($input['sequence'] ?? [$input] as $config) {
    if (!in_array($config['endpoint'] ?? '', $files, true)) { throw new RuntimeException('Geçersiz fixture endpoint'); }
    $eventStart = count($pdo->events); $txStart = count($pdo->transactions); $lineWrites = 0;
    $firmano = $config['firma'] ?? 2;
    $firma = 'LG_' . str_pad((string) $firmano, 3, '0', STR_PAD_LEFT) . '_';
    $firmadonem = $firma . ($config['donem'] ?? '03') . '_'; $firmadonemx = str_replace('LG_', 'LV_', $firmadonem);
    $sipno = 'SIP'; $depo = 0; $kayitsaat = $saat = $dakika = $saniye = 0; $reserve = '1';
    $_SERVER['REQUEST_METHOD'] = $config['method'] ?? 'POST';
    unset($_SERVER['HTTP_IDEMPOTENCY_KEY']);
    if (array_key_exists('key', $config)) { $_SERVER['HTTP_IDEMPOTENCY_KEY'] = $config['key']; }
    // Only bootstrap includes are removed. api_json exit bypasses test catch blocks.
    $code = file_get_contents(__DIR__ . '/../../api/' . $config['endpoint'] . '.php');
    $code = preg_replace('/^include_once\([^\n]+\);\s*$/m', '', $code);
    $code = preg_replace('/\A<\?php\s*declare\(strict_types=1\);/', '', $code);
    $code = str_replace('catch (Throwable $e) {', 'catch (Throwable $e) { if ($e instanceof ApiResponse) { throw $e; }', $code);
    try {
        eval($code);
        throw new RuntimeException('Endpoint yanıt üretmedi');
    } catch (ApiResponse $response) {
        $responses[] = ['status' => $response->status, 'payload' => $response->payload,
            'events' => array_slice($pdo->events, $eventStart), 'transactions' => array_slice($pdo->transactions, $txStart),
            'discarded_response' => $config['drop_response'] ?? false];
    }
}
$result = isset($input['sequence']) ? ['responses' => $responses, 'committed_headers' => $headers, 'committed_lines' => $lines, 'receipts' => count($idempotRecords)] : $responses[0];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

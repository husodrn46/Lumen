<?php
declare(strict_types=1);

final class SiparisIdempotencyCakisma extends RuntimeException {}
final class SiparisIdempotencyBekle extends RuntimeException {}
final class SiparisIdempotencyHazirDegil extends RuntimeException {}

/** Optional: old clients remain valid; only keyed requests gain replay protection. */
function siparis_idempotency_anahtari(): ?string
{
    $value = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    if ($value === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $header) {
            if (strcasecmp((string) $name, 'Idempotency-Key') === 0) { $value = $header; break; }
        }
    }
    if ($value === null) { return null; }
    if (!is_string($value) || !preg_match('/\A(?:[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})\z/i', $value)) {
        throw new InvalidArgumentException('Idempotency-Key UUIDv4 veya 64 hex olmalı.');
    }
    return strtolower($value);
}

/** Complete parsed payload; field order is immaterial, list order/types are not. */
function siparis_idempotency_parmakizi(array $body): string
{
    $canonical = static function (mixed $value) use (&$canonical): mixed {
        if (!is_array($value)) { return $value; }
        if (!array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $item) { $value[$key] = $canonical($item); }
        return $value;
    };
    return hash('sha256', 'siparis_olustur:body:v1:' . json_encode($canonical($body),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
}

function siparis_idempotency_sema_kontrol(PDO $dbh): void
{
    $dbh->query('SELECT TOP 0 KEYHASH, FIRMA, DONEM, PERSONEL, KAPSAM, BODYHASH, YANIT, OLUSTURMA FROM dbo.M_API_IDEMPOTENCY')->closeCursor();
    $stmt = $dbh->query("SELECT COUNT(*) FROM sys.key_constraints K
        INNER JOIN sys.index_columns I ON I.object_id=K.parent_object_id AND I.index_id=K.unique_index_id
        WHERE K.parent_object_id=OBJECT_ID('dbo.M_API_IDEMPOTENCY') AND K.type='PK'
          AND I.key_ordinal=1 AND COL_NAME(I.object_id,I.column_id)='KEYHASH'
          AND NOT EXISTS (SELECT 1 FROM sys.index_columns X WHERE X.object_id=I.object_id AND X.index_id=I.index_id AND X.key_ordinal>1)");
    $valid = (int) $stmt->fetchColumn() === 1;
    $stmt->closeCursor();
    if (!$valid) { throw new RuntimeException('Idempotency şemasında tek anahtarlı PK gerekli.'); }
}

/** Returns previous committed response, or null while holding the key until tx end. */
function siparis_idempotency_baslat(PDO $dbh, string $key, int $firma, string $donem, int $personel, string $hash, string $scope = 'siparis_olustur:v1'): ?array
{
    if (!$dbh->inTransaction()) { throw new LogicException('Idempotency transaction gerektirir.'); }
    if ($firma < 1 || $firma > 999 || $personel <= 0 || !preg_match('/\ALG_' . str_pad((string) $firma, 3, '0', STR_PAD_LEFT) . '_[0-9]{2}_\z/', $donem)) {
        throw new InvalidArgumentException('Idempotency sahibi/kapsamı geçersiz.');
    }
    try { siparis_idempotency_sema_kontrol($dbh); }
    catch (Throwable $e) { throw new SiparisIdempotencyHazirDegil('Idempotency şeması hazır değil.', 0, $e); }
    $keyhash = hash('sha256', $key);
    // All users/companies share this key lock: key theft cannot create a second scope.
    $lock = $dbh->prepare("DECLARE @result INT; EXEC @result=sys.sp_getapplock
        @Resource=:resource, @LockMode='Exclusive', @LockOwner='Transaction', @LockTimeout=5000;
        SELECT @result AS LUMEN_LOCK_RESULT;");
    $lock->execute([':resource' => 'Lumen:siparis-idempotency:' . $keyhash]);
    $code = null;
    try {
        do {
            if ($lock->columnCount() > 0) {
                while ($row = $lock->fetch(PDO::FETCH_ASSOC)) {
                    if (array_key_exists('LUMEN_LOCK_RESULT', $row)) { $code = (int) $row['LUMEN_LOCK_RESULT']; }
                }
            }
        } while ($lock->nextRowset());
    } finally { $lock->closeCursor(); }
    if ($code === null || $code === -999) { throw new SiparisIdempotencyHazirDegil('İşlem kilidi sonucu/altyapısı doğrulanamadı.'); }
    if ($code < 0) { throw new SiparisIdempotencyBekle('İşlem anahtarı kilidi alınamadı.'); }
    $stmt = $dbh->prepare('SELECT FIRMA, DONEM, PERSONEL, KAPSAM, BODYHASH, YANIT FROM dbo.M_API_IDEMPOTENCY WHERE KEYHASH=:key');
    $stmt->execute([':key' => $keyhash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC); $stmt->closeCursor();
    if (!$row) { return null; }
    if ((int) $row['FIRMA'] !== $firma || $row['DONEM'] !== $donem || (int) $row['PERSONEL'] !== $personel
        || $row['KAPSAM'] !== $scope || !hash_equals((string) $row['BODYHASH'], $hash)) {
        throw new SiparisIdempotencyCakisma('Anahtar sahipliği/içeriği uyuşmuyor.');
    }
    $response = json_decode((string) $row['YANIT'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($response) || ($response['ok'] ?? false) !== true || !isset($response['fis']['id'])) {
        throw new RuntimeException('Saklanan işlem sonucu geçersiz.');
    }
    return $response;
}

/** Must run with order writes in the same transaction, before the only COMMIT. */
function siparis_idempotency_tamamla(PDO $dbh, string $key, int $firma, string $donem, int $personel, string $hash, array $response, string $scope = 'siparis_olustur:v1'): void
{
    if (!$dbh->inTransaction()) { throw new LogicException('Idempotency sonucu transaction içinde yazılmalı.'); }
    $stmt = $dbh->prepare('INSERT INTO dbo.M_API_IDEMPOTENCY (KEYHASH,FIRMA,DONEM,PERSONEL,KAPSAM,BODYHASH,YANIT,OLUSTURMA)
        VALUES (:key,:firma,:donem,:personel,:scope,:hash,:response,GETDATE())');
    $stmt->execute([':key' => hash('sha256', $key), ':firma' => $firma, ':donem' => $donem, ':personel' => $personel,
        ':scope' => $scope, ':hash' => $hash,
        ':response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]);
    $changed = $stmt->rowCount(); $stmt->closeCursor();
    if ($changed !== 1) { throw new RuntimeException('Idempotency sonucu saklanamadı.'); }
}

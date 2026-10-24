<?php

declare(strict_types=1);

final class SiparisIdempotencyCakisma extends RuntimeException {}
final class SiparisIdempotencyBekle extends RuntimeException {}
final class SiparisIdempotencyHazirDegil extends RuntimeException {}

/** Eski istemciler anahtarsız çalışmaya devam eder; koruma yalnız anahtarlı istekte devrededir. */
function siparis_idempotency_anahtari(): ?string
{
    $deger = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null;
    if ($deger === null && function_exists('getallheaders')) {
        foreach (getallheaders() as $ad => $baslik) {
            if (strcasecmp((string) $ad, 'Idempotency-Key') === 0) {
                $deger = $baslik;
                break;
            }
        }
    }
    if ($deger === null) {
        return null;
    }
    if (!is_string($deger)
        || preg_match('/\A(?:[a-f0-9]{64}|[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12})\z/i', $deger) !== 1) {
        throw new InvalidArgumentException('Idempotency-Key UUIDv4 veya 64 hex olmalı.');
    }
    return strtolower($deger);
}

/** Nesne alan sırası önemsiz; liste sırası ve değer türleri parmak izinin parçasıdır. */
function siparis_idempotency_parmakizi(array $body): string
{
    $sirala = static function (mixed $deger) use (&$sirala): mixed {
        if (!is_array($deger)) {
            return $deger;
        }
        if (!array_is_list($deger)) {
            ksort($deger, SORT_STRING);
        }
        foreach ($deger as $anahtar => $oge) {
            $deger[$anahtar] = $sirala($oge);
        }
        return $deger;
    };

    return hash('sha256', 'siparis_olustur:body:v1:' . json_encode(
        $sirala($body),
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
    ));
}

/** Eksik veya uyumsuz şemada korumasız yazmaya düşmek yerine kapalı kalır. */
function siparis_idempotency_sema_kontrol(PDO $dbh): void
{
    $dbh->query(
        'SELECT TOP 0 KEYHASH, FIRMA, DONEM, PERSONEL, KAPSAM, BODYHASH, YANIT, OLUSTURMA '
        . 'FROM dbo.M_API_IDEMPOTENCY'
    )->closeCursor();
    $stmt = $dbh->query("SELECT COUNT(*) FROM sys.key_constraints K
        INNER JOIN sys.index_columns I ON I.object_id = K.parent_object_id AND I.index_id = K.unique_index_id
        WHERE K.parent_object_id = OBJECT_ID('dbo.M_API_IDEMPOTENCY') AND K.type = 'PK'
          AND I.key_ordinal = 1 AND COL_NAME(I.object_id, I.column_id) = 'KEYHASH'
          AND NOT EXISTS (
              SELECT 1 FROM sys.index_columns X
              WHERE X.object_id = I.object_id AND X.index_id = I.index_id AND X.key_ordinal > 1
          )");
    $gecerli = (int) $stmt->fetchColumn() === 1;
    $stmt->closeCursor();
    if (!$gecerli) {
        throw new RuntimeException('Idempotency şemasında tek anahtarlı PK gerekli.');
    }
}

/**
 * Anahtarı transaction sonuna kadar kilitler. Daha önce tamamlandıysa saklanan yanıtı,
 * ilk istekse null döndürür.
 */
function siparis_idempotency_baslat(
    PDO $dbh,
    string $anahtar,
    int $firma,
    string $donem,
    int $personel,
    string $parmakIzi,
    string $kapsam = 'siparis_olustur:v1'
): ?array {
    if (!$dbh->inTransaction()) {
        throw new LogicException('Idempotency transaction gerektirir.');
    }
    $firmaKodu = str_pad((string) $firma, 3, '0', STR_PAD_LEFT);
    $logoDonemi = preg_match('/\ALG_' . $firmaKodu . '_[0-9]{2}_\z/', $donem) === 1;
    if ($firma < 1 || $firma > 999 || $personel <= 0 || (!$logoDonemi && $donem !== 'LUMEN')) {
        throw new InvalidArgumentException('Idempotency sahibi veya dönemi geçersiz.');
    }
    try {
        siparis_idempotency_sema_kontrol($dbh);
    } catch (Throwable $e) {
        throw new SiparisIdempotencyHazirDegil('Idempotency şeması hazır değil.', 0, $e);
    }

    $anahtarOzeti = hash('sha256', $anahtar);
    // Anahtar bütün firma/kullanıcılarda tek kilittir; çalınmış anahtar farklı kapsamda
    // ikinci işlem üretmez ve önceki yanıtı da ifşa etmez.
    $kilit = $dbh->prepare("DECLARE @sonuc INT;
        EXEC @sonuc = sys.sp_getapplock
            @Resource = :kaynak, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 5000;
        SELECT @sonuc AS LUMEN_LOCK_RESULT;");
    $kilit->execute([':kaynak' => 'Lumen:siparis-idempotency:' . $anahtarOzeti]);
    $kilitSonucu = null;
    try {
        do {
            if ($kilit->columnCount() > 0) {
                while ($satir = $kilit->fetch(PDO::FETCH_ASSOC)) {
                    if (array_key_exists('LUMEN_LOCK_RESULT', $satir)) {
                        $kilitSonucu = (int) $satir['LUMEN_LOCK_RESULT'];
                    }
                }
            }
        } while ($kilit->nextRowset());
    } finally {
        $kilit->closeCursor();
    }
    if ($kilitSonucu === null || $kilitSonucu === -999) {
        throw new SiparisIdempotencyHazirDegil('İşlem kilidi sonucu doğrulanamadı.');
    }
    if ($kilitSonucu < 0) {
        throw new SiparisIdempotencyBekle('İşlem anahtarı kilidi alınamadı.');
    }

    $stmt = $dbh->prepare(
        'SELECT FIRMA, DONEM, PERSONEL, KAPSAM, BODYHASH, YANIT '
        . 'FROM dbo.M_API_IDEMPOTENCY WHERE KEYHASH = :anahtar'
    );
    $stmt->execute([':anahtar' => $anahtarOzeti]);
    $kayit = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    if (!$kayit) {
        return null;
    }
    if ((int) $kayit['FIRMA'] !== $firma
        || (string) $kayit['DONEM'] !== $donem
        || (int) $kayit['PERSONEL'] !== $personel
        || (string) $kayit['KAPSAM'] !== $kapsam
        || !hash_equals((string) $kayit['BODYHASH'], $parmakIzi)) {
        throw new SiparisIdempotencyCakisma('Anahtar sahipliği veya içeriği uyuşmuyor.');
    }

    $yanit = json_decode((string) $kayit['YANIT'], true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($yanit) || ($yanit['ok'] ?? false) !== true || !isset($yanit['fis']['id'])) {
        throw new RuntimeException('Saklanan işlem sonucu geçersiz.');
    }
    return $yanit;
}

/** Sipariş ve tekrar oynatılacak yanıt, aynı transaction içinde birlikte yazılır. */
function siparis_idempotency_tamamla(
    PDO $dbh,
    string $anahtar,
    int $firma,
    string $donem,
    int $personel,
    string $parmakIzi,
    array $yanit,
    string $kapsam = 'siparis_olustur:v1'
): void {
    if (!$dbh->inTransaction()) {
        throw new LogicException('Idempotency sonucu transaction içinde yazılmalı.');
    }
    $stmt = $dbh->prepare(
        'INSERT INTO dbo.M_API_IDEMPOTENCY '
        . '(KEYHASH, FIRMA, DONEM, PERSONEL, KAPSAM, BODYHASH, YANIT, OLUSTURMA) '
        . 'VALUES (:anahtar, :firma, :donem, :personel, :kapsam, :parmak_izi, :yanit, GETDATE())'
    );
    $stmt->execute([
        ':anahtar' => hash('sha256', $anahtar),
        ':firma' => $firma,
        ':donem' => $donem,
        ':personel' => $personel,
        ':kapsam' => $kapsam,
        ':parmak_izi' => $parmakIzi,
        ':yanit' => json_encode(
            $yanit,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ),
    ]);
    $degisen = $stmt->rowCount();
    $stmt->closeCursor();
    if ($degisen !== 1) {
        throw new RuntimeException('Idempotency sonucu saklanamadı.');
    }
}

<?php
declare(strict_types=1);

/** TL, döviz ve API başlıkları için ortak SQL Server kayıt sözleşmesi. */
function siparis_baslik_tablosu(string $firmadonem): string
{
    if (!preg_match('/\ALG_[0-9]{3}_[0-9]{2}_\z/', $firmadonem)) {
        throw new InvalidArgumentException('Geçersiz firma/dönem öneki.');
    }
    return $firmadonem . 'ORFICHE';
}

function siparis_fis_numarasi(string $onek, int $id): string
{
    if ($id <= 0) {
        throw new InvalidArgumentException('Geçersiz sipariş kimliği.');
    }
    return $onek . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

/**
 * MAX yalnız geçici numara içindir, kayıt kimliği değildir. Transaction sonuna
 * kadar aralık kilidi aynı numaranın eşzamanlı Lumen INSERT'lerinde kullanımını
 * önler. Silinmiş IDENTITY değerleri varsa gerçek numara INSERT sonrası düzelir.
 */
function siparis_baslik_numarasi_ayir(PDO $dbh, string $firmadonem, string $onek): string
{
    if (!$dbh->inTransaction()) {
        throw new LogicException('Sipariş numarası transaction içinde ayrılmalıdır.');
    }
    $tablo = siparis_baslik_tablosu($firmadonem);
    $stmt = $dbh->prepare("SELECT ISNULL(MAX(LOGICALREF), 0) + 1 FROM {$tablo} WITH (UPDLOCK, HOLDLOCK)");
    $stmt->execute();
    $id = (int) $stmt->fetchColumn();
    $stmt->closeCursor();
    return siparis_fis_numarasi($onek, $id);
}

/**
 * OUTPUT INTO, LOGO'nun INSERT trigger'ları olan tablolarında da kullanılabilir.
 * Bu fonksiyona yalnız kodda sabit tek satırlık INSERT ... VALUES SQL'i verilir.
 */
function siparis_baslik_insert_hazirla(PDO $dbh, string $sql): PDOStatement
{
    if (!$dbh->inTransaction()) {
        throw new LogicException('Sipariş başlığı transaction içinde yazılmalıdır.');
    }
    $sql = preg_replace('/\)\s*VALUES\s*\(/i',
        ') OUTPUT INSERTED.LOGICALREF INTO @lumen_eklenen VALUES (', $sql, 1, $adet);
    if ($sql === null || $adet !== 1) {
        throw new InvalidArgumentException('Tek satırlık INSERT VALUES sözleşmesi gerekli.');
    }
    return $dbh->prepare('DECLARE @lumen_eklenen TABLE (id INT); ' . $sql
        . '; SELECT id AS LUMEN_INSERTED_ID FROM @lumen_eklenen;');
}

/** SQL Server'ın DML/trigger result-set'lerini atla; yalnız bu INSERT'in kimliği. */
function siparis_baslik_eklenen_id(PDOStatement $stmt): int
{
    $kimlikler = [];
    try {
        do {
            if ($stmt->columnCount() > 0) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    if (array_key_exists('LUMEN_INSERTED_ID', $row)) {
                        $kimlikler[] = (int) $row['LUMEN_INSERTED_ID'];
                    }
                }
            }
        } while ($stmt->nextRowset());
    } finally {
        $stmt->closeCursor();
    }
    if (count($kimlikler) !== 1 || $kimlikler[0] <= 0) {
        throw new RuntimeException('Yeni siparişin kesin kimliği alınamadı.');
    }
    return $kimlikler[0];
}

/** Web başlığı için numarayı gerçek IDENTITY değerine bağlar; caller commit eder. */
function siparis_baslik_numarasini_kesinlestir(PDO $dbh, string $firmadonem, int $id, string $onek): string
{
    if (!$dbh->inTransaction()) {
        throw new LogicException('Sipariş numarası transaction içinde kesinleşmelidir.');
    }
    $tablo = siparis_baslik_tablosu($firmadonem);
    $numara = siparis_fis_numarasi($onek, $id);
    $stmt = $dbh->prepare("UPDATE {$tablo} SET FICHENO = :n WHERE LOGICALREF = :id");
    $stmt->execute([':n' => $numara, ':id' => $id]);
    $changed = $stmt->rowCount();
    $stmt->closeCursor();
    if ($changed !== 1) {
        throw new RuntimeException('Sipariş numarası kesinleştirilemedi.');
    }
    return $numara;
}

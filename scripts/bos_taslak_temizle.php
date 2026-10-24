<?php

declare(strict_types=1);

/**
 * Boş taslak sipariş temizliği (zamanlanmış görev).
 *
 * Yeni sipariş ekranı fişi hemen açar; kullanıcı vazgeçerse satırsız bir fiş
 * LOGO'da kalır ve sipariş listelerini kirletir (siparis/fisekle.php notu).
 * Bu betik YALNIZ şu fişleri siler:
 *   - Lumen tarafından açılmış (M_FIS_LOG'da OLUSTURMA kaydı var) — LOGO'nun
 *     veya başka bir programın açtığı fişlere dokunulmaz,
 *   - satış siparişi (TRCODE=1), iptal edilmemiş, hiç yazdırılmamış,
 *   - hiç satırı yok ve toplamı sıfır,
 *   - en az --gun gündür (varsayılan 3) dokunulmamış.
 *
 * Varsayılan olarak yalnız LİSTELER; silmek için --uygula verin:
 *   php scripts/bos_taslak_temizle.php            # ne silineceğini göster
 *   php scripts/bos_taslak_temizle.php --uygula   # sil
 *   php scripts/bos_taslak_temizle.php --gun=7 --uygula
 *
 * Windows Görev Zamanlayıcı örneği (her gece 03:00):
 *   php.exe C:\inetpub\lumen\scripts\bos_taslak_temizle.php --uygula
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$secenek = getopt('', ['uygula', 'gun:']);
$uygula = isset($secenek['uygula']);
$gun = max(1, (int) ($secenek['gun'] ?? 3));

$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? 'bos_taslak_temizle.php';
require_once dirname(__DIR__) . '/ayr.php';

$sql = "
    SELECT F.LOGICALREF, F.FICHENO, F.CLIENTREF, F.CAPIBLOCK_CREADEDDATE
    FROM {$firmadonem}ORFICHE F
    WHERE F.TRCODE = 1
      AND ISNULL(F.CANCELLED, 0) = 0
      AND ISNULL(F.PRINTCNT, 0) = 0
      AND ISNULL(F.GROSSTOTAL, 0) = 0 AND ISNULL(F.NETTOTAL, 0) = 0
      AND NOT EXISTS (SELECT 1 FROM {$firmadonem}ORFLINE L WHERE L.ORDFICHEREF = F.LOGICALREF)
      AND EXISTS (SELECT 1 FROM M_FIS_LOG G WHERE G.FIS_REF = F.LOGICALREF AND G.ISLEM_TIPI = 'OLUSTURMA')
      AND ISNULL(F.CAPIBLOCK_MODIFIEDDATE, F.CAPIBLOCK_CREADEDDATE) < DATEADD(DAY, -{$gun}, GETDATE())
    ORDER BY F.LOGICALREF";
$adaylar = $dbh->query($sql)->fetchAll(PDO::FETCH_ASSOC);

printf("%d boş taslak (%d+ gün, Lumen'in açtığı, satırsız)%s\n", count($adaylar), $gun, $uygula ? '' : ' — yalnız listeleniyor (--uygula ile silinir)');
foreach ($adaylar as $a) {
    printf("  %-14s id=%-8d cari=%-6d %s\n", (string) $a['FICHENO'], (int) $a['LOGICALREF'], (int) $a['CLIENTREF'], substr((string) $a['CAPIBLOCK_CREADEDDATE'], 0, 10));
}
if (!$uygula || $adaylar === []) {
    exit(0);
}

$silinen = 0;
$sil = $dbh->prepare("
    DELETE F FROM {$firmadonem}ORFICHE F
    WHERE F.LOGICALREF = :id AND F.TRCODE = 1
      AND NOT EXISTS (SELECT 1 FROM {$firmadonem}ORFLINE L WITH (UPDLOCK, HOLDLOCK) WHERE L.ORDFICHEREF = :id2)");
foreach ($adaylar as $a) {
    $id = (int) $a['LOGICALREF'];
    try {
        $dbh->beginTransaction();
        $sil->execute([':id' => $id, ':id2' => $id]);   // bu arada satır eklendiyse silinmez
        if ($sil->rowCount() === 1) {
            $silinen++;
            $dbh->prepare("INSERT INTO M_FIS_LOG (FIS_REF, FICHENO, ISLEM_TIPI, CARI_REF, KULLANICI, KULLANICI_ID, TARIH, ACIKLAMA)
                           VALUES (:f, :n, 'SILME', :c, 0, 0, GETDATE(), :a)")
                ->execute([':f' => $id, ':n' => (string) $a['FICHENO'], ':c' => (int) $a['CLIENTREF'],
                           ':a' => "Boş taslak otomatik silindi ({$gun}+ gün satırsız)"]);
        }
        $dbh->commit();
    } catch (Throwable $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        fwrite(STDERR, "HATA id={$id}: " . $e->getMessage() . "\n");
    }
}
printf("%d taslak silindi.\n", $silinen);

<?php
declare(strict_types=1);

require_once __DIR__ . '/../siparis/olustur.php';

function et_json(mixed $v): string
{
    return json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function et_tek(PDO $db, string $sql, array $p = []): ?array
{
    $s = $db->prepare($sql);
    $s->execute($p);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    $s->closeCursor();
    return $r ?: null;
}

function et_kanal(PDO $db, int $id): array
{
    $r = et_tek($db, 'SELECT *, CONVERT(VARCHAR(18),SURUM,1) AS SURUM_KOD FROM dbo.M_ET_KANAL WHERE ID=:id AND FIRMA=:f',
        [':id' => $id, ':f' => (int) $GLOBALS['firmano']]);
    if (!$r) {
        throw new DomainException('Kanal bulunamadı.');
    }
    return $r;
}

function et_kanallar(PDO $db): array
{
    $s = $db->prepare('SELECT * FROM dbo.M_ET_KANAL WHERE FIRMA=:f ORDER BY AD,ID');
    $s->execute([':f' => (int) $GLOBALS['firmano']]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function et_olay(PDO $db, int $kanal, ?int $siparis, int $kullanici, string $tur, string $mesaj, mixed $veri = null): void
{
    $s = $db->prepare('INSERT INTO dbo.M_ET_OLAY (KANAL_ID,SIPARIS_ID,KULLANICI,TUR,MESAJ,VERI) VALUES (:k,:s,:u,:t,:m,:v)');
    $s->execute([':k' => $kanal, ':s' => $siparis, ':u' => $kullanici, ':t' => $tur,
        ':m' => mb_substr($mesaj, 0, 500), ':v' => $veri === null ? null : et_json($veri)]);
}

/** Ana veri referansları her iki modun mevcut okuma katmanından doğrulanır. Depo kimliği ambar numarasıdır. */
function et_hedef_dogrula(PDO $db, int $cari, int $depo, int $personel, int $kullanici): array
{
    if (!m_p_cariid_goruntulebilir_mi($db, $GLOBALS['firma'], $kullanici, $cari)
        || !($c = siparis_cari_getir($db, $GLOBALS['firma'], $cari))) {
        throw new DomainException('Geçerli ve erişilebilir bir cari seçin.');
    }
    $t = lumen_tablo('depo');
    if (!et_tek($db, "SELECT NR FROM {$t} WHERE FIRMNR=:f AND NR=:n", [':f' => (int) $GLOBALS['firmano'], ':n' => $depo])) {
        throw new DomainException('Depo bulunamadı.');
    }
    $p = lumen_personel_getir($db, $personel);
    if (!$p) {
        throw new DomainException('Aktif personel seçin.');
    }
    return $c;
}

function et_kanal_kaydet(PDO $db, array $v, int $kullanici): int
{
    if ((int) m_p_yetki($kullanici, 'M16') !== 1) {
        throw new DomainException('Kanal yönetimi için M16 yetkisi gerekiyor.');
    }
    $ad = trim((string) ($v['ad'] ?? ''));
    $tur = (string) ($v['tur'] ?? '');
    if ($ad === '' || mb_strlen($ad) > 100 || !in_array($tur, ['trendyol','hepsiburada','woocommerce','shopify','site','diger'], true)) {
        throw new DomainException('Geçerli kanal adı ve türü gerekiyor.');
    }
    $c = (int) ($v['cari'] ?? 0); $d = (int) ($v['depo'] ?? -1); $p = (int) ($v['personel'] ?? 0);
    if ($c > 0 || $d >= 0 || $p > 0) {
        et_hedef_dogrula($db, $c, $d, $p, $kullanici);
    }
    $id = (int) ($v['id'] ?? 0);
    $param = [':f' => (int) $GLOBALS['firmano'], ':ad' => $ad, ':t' => $tur, ':a' => isset($v['aktif']) ? 1 : 0,
        ':c' => $c ?: null, ':d' => $d >= 0 ? $d : null, ':p' => $p ?: null, ':u' => $kullanici];
    $db->beginTransaction();
    try {
        if ($id > 0) {
            et_kanal($db, $id);
            $s = $db->prepare('UPDATE dbo.M_ET_KANAL SET AD=:ad,TUR=:t,AKTIF=:a,CARI_ID=:c,DEPO_NO=:d,PERSONEL_ID=:p,
                GUNCELLEYEN=:u,GUNCELLEME=SYSDATETIME() WHERE ID=:id AND FIRMA=:f AND CONVERT(VARCHAR(18),SURUM,1)=:surum');
            $s->execute($param + [':id' => $id, ':surum' => (string) ($v['surum'] ?? '')]);
            if ($s->rowCount() !== 1) {
                throw new DomainException('Kanal değişmiş; sayfayı yenileyin.');
            }
            $s->closeCursor();
        } else {
            $id = lumen_insert_kimlik($db, 'INSERT INTO dbo.M_ET_KANAL (FIRMA,AD,TUR,AKTIF,CARI_ID,DEPO_NO,PERSONEL_ID,OLUSTURAN,GUNCELLEYEN)
                VALUES (:f,:ad,:t,:a,:c,:d,:p,:u,:g)', $param + [':g' => $kullanici], 'ID');
        }
        $s=$db->prepare("UPDATE dbo.M_ET_SIPARIS SET DURUM='esleme',ONAY_GOVDE=NULL,ONAY_OZET=NULL WHERE KANAL_ID=:k AND DURUM='hazir'");
        $s->execute([':k'=>$id]); $s->closeCursor();
        et_olay($db, $id, null, $kullanici, 'kanal', 'Kanal tanımı kaydedildi.');
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        throw $e;
    }
}

/** Kaynak birimi ana birime çevirir. MVP birden küçük çarpanları kabul etmez. */
function et_birimler(PDO $db, string $firma, int $stok): array
{
    $s=$db->prepare("SELECT U.CODE AS kod,IA.CONVFACT1,IA.CONVFACT2 FROM {$firma}ITMUNITA IA
        JOIN {$firma}UNITSETL U ON U.LOGICALREF=IA.UNITLINEREF WHERE IA.ITEMREF=:s ORDER BY IA.LINENR,IA.LOGICALREF");
    $s->execute([':s'=>$stok]);$out=[];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c=(float) $r['CONVFACT1']>0 ? (float) $r['CONVFACT2']/(float) $r['CONVFACT1'] : 0;
        if ($c>=1 && $c<=100000) { $out[]=['kod'=>trim((string) $r['kod']),'carpan'=>round($c,6)]; }
    }
    return $out;
}

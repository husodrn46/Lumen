<?php
declare(strict_types=1);
require_once __DIR__ . '/depo.php';
require_once __DIR__ . '/okuyucu.php';
require_once __DIR__ . '/../../api/_idempotency.inc';

function et_ice_aktar(PDO $db, int $kanal, string $ham, string $tur, string $ad, int $kullanici, string $bicim = 'lumen'): array
{
    $kayitlar = et_dosya_oku($ham,$tur,$bicim);
    $sonuc = ['yeni'=>0,'mukerrer'=>0,'cakisma'=>0,'hatali'=>0,'eski'=>0];
    $kendiIslemi=!$db->inTransaction();
    if ($kendiIslemi) { $db->beginTransaction(); }
    try {
        $k = et_kanal($db,$kanal);
        // Aynı kanaldaki dosya alımları kilitlenir; benzersiz anahtarlar son savunmadır.
        et_tek($db,'SELECT ID FROM dbo.M_ET_KANAL WITH (UPDLOCK,HOLDLOCK) WHERE ID=:i',[':i'=>$kanal]);
        $k = et_kanal($db,$kanal);
        $beklenen=et_bicimler()[$bicim]['kanal'];
        if ($beklenen!==null && $k['TUR']!==$beklenen) { throw new DomainException('Dosya biçimi kanal türüyle uyuşmuyor.'); }
        if (!$k['AKTIF']) { throw new DomainException('Pasif kanala sipariş alınamaz.'); }
        $batch = lumen_insert_kimlik($db,'INSERT INTO dbo.M_ET_ICE_AKTARIM (KANAL_ID,DOSYA_ADI,TUR,OZET,HAM,KULLANICI,BICIM)
            VALUES (:k,:a,:t,:o,:h,:u,:bicim)', [':k'=>$kanal,':a'=>mb_substr(basename($ad),0,200),':t'=>$tur,':o'=>hash('sha256',$ham),':h'=>$ham,':u'=>$kullanici,':bicim'=>$bicim],'ID');
        foreach ($kayitlar as $r) {
            $raw = $r['ham'];
            $no=$r['numara']; $id=$r['kimlik']; $paket=$r['paket_kimligi'];
            if ($id === '' || $no === '') { $sonuc['hatali']++; continue; }
            // Paketli/paketsiz aynı sipariş birlikte ikinci satış siparişine dönüşemez.
            $onceki = et_tek($db, "SELECT ID,HAM,HARICI_ID,HARICI_NO,PAKET_KIMLIGI,KAYNAK,KAYNAK_DURUMU,KAYNAK_ENGELI,CONVERT(VARCHAR(23),KAYNAK_GUNCELLEME,121) AS KAYNAK_GUNCELLEME FROM dbo.M_ET_SIPARIS
                WHERE KANAL_ID=:k AND (HARICI_ID=:i OR (HARICI_NO=:n AND (PAKET_KIMLIGI=:p OR PAKET_KIMLIGI=N'' OR :bos=N'')))",
                [':k'=>$kanal,':i'=>$id,':n'=>$no,':p'=>$paket,':bos'=>$paket]);
            if ($onceki) {
                $ayni = $onceki['HARICI_ID'] === $id && $onceki['HARICI_NO'] === $no && $onceki['PAKET_KIMLIGI'] === $paket && $onceki['KAYNAK'] === $r['kaynak']
                    && siparis_idempotency_parmakizi(json_decode($onceki['HAM'],true,64,JSON_THROW_ON_ERROR)) === siparis_idempotency_parmakizi($raw);
                $sonuc[$ayni ? 'mukerrer' : 'cakisma']++;
                if ($onceki['KAYNAK'] === $r['kaynak'] && et_kaynak_guncelle($db,$onceki,$r)) {
                    $sonuc['eski']++; $sonuc[$ayni ? 'mukerrer' : 'cakisma']--;
                    et_olay($db,$kanal,(int) $onceki['ID'],$kullanici,'eski_veri','Daha eski kaynak yanıtı saklandı; güncel kayıt değiştirilmedi.', ['aktarim_id'=>$batch,'ham'=>$raw]);
                    continue;
                }
                if (!$ayni) {
                    $s=$db->prepare("UPDATE dbo.M_ET_SIPARIS SET CAKISMA=1,DURUM='hatali',ONAY_GOVDE=NULL,ONAY_OZET=NULL,
                        SON_HATA=N'Kaynak içerik değişmiş; orijinal kayıtla devam kararı gerekiyor.'
                        WHERE ID=:kayit AND DURUM NOT IN ('aktarildi','iptal')");
                    $s->execute([':kayit'=>$onceki['ID']]); $s->closeCursor();
                }

                et_olay($db,$kanal,(int) $onceki['ID'],$kullanici,$ayni ? 'tekrar' : 'cakisma',
                    $ayni ? 'Aynı sipariş yeniden alındı.' : 'Değişmiş içerik bekletildi; mevcut kayıt değiştirilmedi.', ['aktarim_id'=>$batch,'ham'=>$raw]);
                continue;
            }
            $sid = lumen_insert_kimlik($db,'INSERT INTO dbo.M_ET_SIPARIS
                (KANAL_ID,AKTARIM_ID,HARICI_ID,HARICI_NO,PAKET_KIMLIGI,KAYNAK,KAYNAK_DURUMU,KAYNAK_ENGELI,KAYNAK_GUNCELLEME,DURUM,HAM,NORMAL,CARI_ID,DEPO_NO,PERSONEL_ID,ANAHTAR,SON_HATA,OLUSTURAN)
                VALUES (:k,:b,:i,:n,:paket,:kaynak,:kd,:ke,:kg,:d,:h,:v,:c,:depo,:p,:a,:e,:u)',
                [':k'=>$kanal,':b'=>$batch,':i'=>$id,':n'=>$no,':paket'=>$paket,':kaynak'=>$r['kaynak'],':kd'=>$r['kaynak_durumu'],':ke'=>$r['kaynak_engeli'],':kg'=>$r['kaynak_guncelleme'],':d'=>($r['hata'] || $r['kaynak_engeli']) ? 'hatali' : 'yeni',':h'=>et_json($raw),
                 ':v'=>et_json($r['normal']),':c'=>$k['CARI_ID'],':depo'=>$k['DEPO_NO'],':p'=>$k['PERSONEL_ID'],
                 ':a'=>bin2hex(random_bytes(32)),':e'=>$r['hata'] ?? $r['kaynak_engeli'],':u'=>$kullanici],'ID');
            foreach (($r['normal']['kalemler'] ?? []) as $i=>$satir) {
                $s = $db->prepare('INSERT INTO dbo.M_ET_SIPARIS_SATIR (SIPARIS_ID,SIRA,SKU,BARKOD,AD,BIRIM,MIKTAR,FIYAT,KDV,KDV_DAHIL)
                    VALUES (:s,:i,:sku,:b,:ad,:birim,:m,:f,:k,:d)');
                $s->execute([':s'=>$sid,':i'=>$i+1,':sku'=>$satir['sku'],':b'=>$satir['barkod'],':ad'=>$satir['ad'],':birim'=>$satir['birim'],
                    ':m'=>$satir['miktar'],':f'=>$satir['fiyat'],':k'=>$satir['kdv'],':d'=>$satir['kdv_dahil'] ? 1 : 0]);
            }
            et_olay($db,$kanal,$sid,$kullanici,'alindi',$r['hata'] ?? 'Sipariş kutuya alındı.');
            $sonuc[$r['hata'] ? 'hatali' : 'yeni']++;
        }
        $s=$db->prepare('UPDATE dbo.M_ET_ICE_AKTARIM SET SONUC=:s WHERE ID=:i');
        $s->execute([':s'=>et_json($sonuc),':i'=>$batch]);
        if ($kendiIslemi) { $db->commit(); }return $sonuc+['aktarim_id'=>$batch];
    } catch (Throwable $e) { if ($kendiIslemi && $db->inTransaction()) { $db->rollBack(); } throw $e; }
}

/** true: daha eski yanıt. İptal/ödeme engeli yalnız daha yeni, doğrulanmış zamanla kalkar. */
function et_kaynak_guncelle(PDO $db, array $onceki, array $yeni): bool
{
    $eskiTarih=$onceki['KAYNAK_GUNCELLEME']; $yeniTarih=$yeni['kaynak_guncelleme'];
    if ($eskiTarih && $yeniTarih && $yeniTarih<$eskiTarih) { return true; }
    $engel=$yeni['kaynak_engeli']; $durum=$yeni['kaynak_durumu'];
    if ($onceki['KAYNAK_ENGELI'] && !$engel && (!$yeniTarih || !$eskiTarih || $yeniTarih<=$eskiTarih)) {
        $engel=$onceki['KAYNAK_ENGELI']; $durum=$onceki['KAYNAK_DURUMU'];
    }
    $tarih=$yeniTarih ?: $eskiTarih;
    if ($durum===$onceki['KAYNAK_DURUMU'] && $engel===$onceki['KAYNAK_ENGELI'] && $tarih===$eskiTarih) { return false; }
    $s=$db->prepare('UPDATE dbo.M_ET_SIPARIS SET KAYNAK_DURUMU=:d,KAYNAK_ENGELI=:e,KAYNAK_GUNCELLEME=:t WHERE ID=:i');
    $s->execute([':d'=>$durum,':e'=>$engel,':t'=>$tarih,':i'=>$onceki['ID']]); $s->closeCursor();
    return false;
}

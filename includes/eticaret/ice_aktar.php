<?php
declare(strict_types=1);
require_once __DIR__ . '/depo.php';
require_once __DIR__ . '/okuyucu.php';
require_once __DIR__ . '/../../api/_idempotency.inc';

function et_ice_aktar(PDO $db, int $kanal, string $ham, string $tur, string $ad, int $kullanici): array
{
    $kayitlar = et_dosya_oku($ham,$tur);
    $sonuc = ['yeni'=>0,'mukerrer'=>0,'cakisma'=>0,'hatali'=>0];
    $db->beginTransaction();
    try {
        $k = et_kanal($db,$kanal);
        // Aynı kanaldaki dosya alımları kilitlenir; benzersiz anahtarlar son savunmadır.
        et_tek($db,'SELECT ID FROM dbo.M_ET_KANAL WITH (UPDLOCK,HOLDLOCK) WHERE ID=:i',[':i'=>$kanal]);
        $k = et_kanal($db,$kanal);
        if (!$k['AKTIF']) { throw new DomainException('Pasif kanala sipariş alınamaz.'); }
        $batch = lumen_insert_kimlik($db,'INSERT INTO dbo.M_ET_ICE_AKTARIM (KANAL_ID,DOSYA_ADI,TUR,OZET,HAM,KULLANICI)
            VALUES (:k,:a,:t,:o,:h,:u)', [':k'=>$kanal,':a'=>mb_substr(basename($ad),0,200),':t'=>$tur,':o'=>hash('sha256',$ham),':h'=>$ham,':u'=>$kullanici],'ID');
        foreach ($kayitlar as $r) {
            $raw = $r['ham'];
            $no=$r['numara']; $id=$r['kimlik']; $paket=$r['paket_kimligi'];
            if ($id === '' || $no === '') { $sonuc['hatali']++; continue; }
            // Paketli/paketsiz aynı sipariş birlikte ikinci satış siparişine dönüşemez.
            $onceki = et_tek($db, "SELECT ID,HAM,HARICI_ID,HARICI_NO,PAKET_KIMLIGI,KAYNAK FROM dbo.M_ET_SIPARIS
                WHERE KANAL_ID=:k AND (HARICI_ID=:i OR (HARICI_NO=:n AND (PAKET_KIMLIGI=:p OR PAKET_KIMLIGI=N'' OR :bos=N'')))",
                [':k'=>$kanal,':i'=>$id,':n'=>$no,':p'=>$paket,':bos'=>$paket]);
            if ($onceki) {
                $ayni = $onceki['HARICI_ID'] === $id && $onceki['HARICI_NO'] === $no && $onceki['PAKET_KIMLIGI'] === $paket && $onceki['KAYNAK'] === $r['kaynak']
                    && siparis_idempotency_parmakizi(json_decode($onceki['HAM'],true,64,JSON_THROW_ON_ERROR)) === siparis_idempotency_parmakizi($raw);
                $sonuc[$ayni ? 'mukerrer' : 'cakisma']++;
                if ($onceki['KAYNAK'] === $r['kaynak']) {
                    $s=$db->prepare("UPDATE dbo.M_ET_SIPARIS SET KAYNAK_DURUMU=:d,KAYNAK_ENGELI=:e WHERE ID=:i
                        AND (ISNULL(KAYNAK_DURUMU,N'')<>ISNULL(:durum,N'') OR ISNULL(KAYNAK_ENGELI,N'')<>ISNULL(:engel,N''))");
                    $s->execute([':d'=>$r['kaynak_durumu'],':e'=>$r['kaynak_engeli'],':i'=>$onceki['ID'],
                        ':durum'=>$r['kaynak_durumu'],':engel'=>$r['kaynak_engeli']]); $s->closeCursor();
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
                (KANAL_ID,AKTARIM_ID,HARICI_ID,HARICI_NO,PAKET_KIMLIGI,KAYNAK,KAYNAK_DURUMU,KAYNAK_ENGELI,DURUM,HAM,NORMAL,CARI_ID,DEPO_NO,PERSONEL_ID,ANAHTAR,SON_HATA,OLUSTURAN)
                VALUES (:k,:b,:i,:n,:paket,:kaynak,:kd,:ke,:d,:h,:v,:c,:depo,:p,:a,:e,:u)',
                [':k'=>$kanal,':b'=>$batch,':i'=>$id,':n'=>$no,':paket'=>$paket,':kaynak'=>$r['kaynak'],':kd'=>$r['kaynak_durumu'],':ke'=>$r['kaynak_engeli'],':d'=>$r['hata'] ? 'hatali' : 'yeni',':h'=>et_json($raw),
                 ':v'=>et_json($r['normal']),':c'=>$k['CARI_ID'],':depo'=>$k['DEPO_NO'],':p'=>$k['PERSONEL_ID'],
                 ':a'=>bin2hex(random_bytes(32)),':e'=>$r['hata'],':u'=>$kullanici],'ID');
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
        $db->commit(); return $sonuc;
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
}

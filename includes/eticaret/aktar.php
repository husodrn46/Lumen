<?php
declare(strict_types=1);
require_once __DIR__ . '/kutu.php';

function et_aktar(PDO $db, int $id, int $kullanici, string $surum): array
{
    if ((int) m_p_yetki($kullanici,'M33') !== 1 || (int) m_p_yetki($kullanici,'M1') !== 1) {
        throw new DomainException('Aktarım için M33 ve M1 yetkileri gerekiyor.');
    }
    $r=et_siparis($db,$id,$kullanici);
    $db->beginTransaction();
    try {
        et_kanal_kilitle($db,(int) $r['KANAL_ID']);
        $r=et_siparis($db,$id,$kullanici,true);
        if ($r['DURUM']==='aktarildi') {
            $db->commit(); return ['id'=>(int) $r['FIS_ID'],'fisno'=>$r['FIS_NO']];
        }
        if ($r['DURUM']!=='hazir' || $r['SURUM_KOD']!==$surum) { throw new DomainException('Kayıt değişmiş veya hazır değil; kontrolleri yenileyin.'); }
        $v=et_kontrol($db,$r,$kullanici);
        if ($v['hatalar']) { throw new DomainException(implode(' ',$v['hatalar'])); }
        $hash=siparis_idempotency_parmakizi($v['govde']);
        if (!$r['ONAY_OZET'] || !hash_equals($r['ONAY_OZET'],$hash)) { throw new DomainException('Ürün, fiyat, stok veya hedef değişmiş; yeniden kontrol edip onaylayın.'); }
        $g=$v['govde'];
        $onceki=siparis_idempotency_baslat($db,$r['ANAHTAR'],(int) $GLOBALS['firmano'],$g['donem'],$kullanici,$hash,'eticaret_aktar:v1');
        $c=et_hedef_dogrula($db,$g['cari_id'],$g['depo'],$g['personel'],$kullanici);
        $yanit=$onceki ?? siparis_servis_yaz($db,$g['hazir'],$c,[
            'firmadonem'=>$GLOBALS['firmadonem'],'sipno'=>$GLOBALS['sipno'],'personel'=>$g['personel'],
            'kayitsaat'=>$GLOBALS['kayitsaat'],'saat'=>$GLOBALS['saat'],'dakika'=>$GLOBALS['dakika'],'saniye'=>$GLOBALS['saniye'],
            'depo'=>$g['depo'],'reserve'=>(string) $GLOBALS['reserve']==='1',
        ]);
        $fis=$yanit['fis'];
        if (abs((float) $yanit['genel_toplam']-(float) $g['toplam'])>0.005 || (int) $yanit['satir_sayisi']!==count($g['kalemler']) || $yanit['atlanan']) {
            throw new RuntimeException('Oluşan sipariş onaylanan içerikle uyuşmuyor.');
        }
        if (!$onceki) {
            // Sadece yeni siparişe kaynak açıklaması yazılır; harici numaranın tamamı kutuda kalır.
            $s=$db->prepare("UPDATE {$GLOBALS['firmadonem']}ORFICHE SET DOCODE=:d,GENEXP1=:a WHERE LOGICALREF=:i");
            $s->execute([':d'=>'ETICARET',':a'=>mb_substr('E-ticaret '.$r['KANAL_AD'].' / '.$r['HARICI_NO'],0,200),':i'=>$fis['id']]); $s->closeCursor();
            siparis_idempotency_tamamla($db,$r['ANAHTAR'],(int) $GLOBALS['firmano'],$g['donem'],$kullanici,$hash,$yanit,'eticaret_aktar:v1');
        }
        $s=$db->prepare("UPDATE dbo.M_ET_SIPARIS SET DURUM='aktarildi',FIS_ID=:f,FIS_NO=:n,HEDEF_MOD=:m,HEDEF_DONEM=:d,
            AKTARAN=:u,AKTARILMA=SYSDATETIME(),DENEME=DENEME+1,SON_HATA=NULL WHERE ID=:i");
        $s->execute([':f'=>$fis['id'],':n'=>$fis['fisno'],':m'=>$g['mod'],':d'=>$g['donem'],':u'=>$kullanici,':i'=>$id]); $s->closeCursor();
        foreach ($g['kalemler'] as $i=>$k) {
            $s=$db->prepare('UPDATE dbo.M_ET_SIPARIS_SATIR SET STOK_ID=:s WHERE SIPARIS_ID=:i AND SIRA=:n');
            $s->execute([':s'=>$k['stok_id'],':i'=>$id,':n'=>$i+1]); $s->closeCursor();
        }
        et_olay($db,(int) $r['KANAL_ID'],$id,$kullanici,'aktarildi','Satış siparişi oluşturuldu.',$fis);
        if (!$db->commit()) { throw new RuntimeException('Commit sonucu doğrulanamadı.'); }
        return $fis;
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        // Hata izi ayrı transaction'dadır. Başka bir isteğin başarılı sonucunu geri düşürmez.
        try {
            $db->beginTransaction(); et_kanal_kilitle($db,(int) $r['KANAL_ID']);
            $son=et_siparis($db,$id,$kullanici,true);
            if (!in_array($son['DURUM'],['aktarildi','iptal'],true)) {
                $mesaj=$e instanceof DomainException ? $e->getMessage() : 'Aktarım tamamlanamadı; yeniden kontrol ederek tekrar deneyin.';
                $s=$db->prepare("UPDATE dbo.M_ET_SIPARIS SET DURUM='hatali',DENEME=DENEME+1,SON_HATA=:e,ONAY_GOVDE=NULL,ONAY_OZET=NULL WHERE ID=:i");
                $s->execute([':e'=>mb_substr($mesaj,0,500),':i'=>$id]); $s->closeCursor();
                et_olay($db,(int) $r['KANAL_ID'],$id,$kullanici,'aktarim_hatasi',$mesaj);
            }
            $db->commit();
        } catch (Throwable $logHatasi) {
            if ($db->inTransaction()) { $db->rollBack(); }
            error_log('eticaret aktarım hata izi: '.$logHatasi->getMessage());
        }
        throw $e;
    }
}
function et_iptal(PDO $db, int $id, int $kullanici, string $surum): void
{
    $r=et_siparis($db,$id,$kullanici); $db->beginTransaction();
    try {
        et_kanal_kilitle($db,(int) $r['KANAL_ID']); $r=et_siparis($db,$id,$kullanici,true);
        if ($r['DURUM']==='aktarildi' || $r['SURUM_KOD']!==$surum) { throw new DomainException('Aktarılmış veya değişmiş kayıt iptal edilemez.'); }
        $s=$db->prepare("UPDATE dbo.M_ET_SIPARIS SET DURUM='iptal',ONAY_GOVDE=NULL,ONAY_OZET=NULL WHERE ID=:i");
        $s->execute([':i'=>$id]); $s->closeCursor();
        et_olay($db,(int) $r['KANAL_ID'],$id,$kullanici,'iptal','Kutu kaydı kullanıcı tarafından iptal edildi.');
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
}

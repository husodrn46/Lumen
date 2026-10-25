<?php
declare(strict_types=1);
require_once __DIR__ . '/depo.php';
require_once __DIR__ . '/okuyucu.php';
require_once __DIR__ . '/../../api/_idempotency.inc';

function et_durumlar(): array
{
    return ['yeni'=>'Yeni','esleme'=>'Eşleme bekliyor','hazir'=>'Hazır','aktarildi'=>'Aktarıldı','iptal'=>'İptal','hatali'=>'Hatalı'];
}
function et_siparis(PDO $db, int $id, int $kullanici, bool $kilit = false): array
{
    $hint=$kilit ? 'WITH (UPDLOCK,HOLDLOCK)' : '';
    $r=et_tek($db,"SELECT S.*,K.AD AS KANAL_AD,CONVERT(VARCHAR(18),S.SURUM,1) AS SURUM_KOD FROM dbo.M_ET_SIPARIS S {$hint}
        JOIN dbo.M_ET_KANAL K ON K.ID=S.KANAL_ID WHERE S.ID=:i AND K.FIRMA=:f",[':i'=>$id,':f'=>(int) $GLOBALS['firmano']]);
    if (!$r || ((int) $r['CARI_ID'] > 0 && !m_p_cariid_goruntulebilir_mi($db,$GLOBALS['firma'],$kullanici,(int) $r['CARI_ID']))) {
        throw new DomainException('Sipariş bulunamadı.');
    }
    return $r;
}
function et_kanal_kilitle(PDO $db, int $kanal): void
{
    et_kanal($db,$kanal);
    et_tek($db,'SELECT ID FROM dbo.M_ET_KANAL WITH (UPDLOCK,HOLDLOCK) WHERE ID=:i',[':i'=>$kanal]);
}
function et_esleme_kaydet(PDO $db, array $v, int $kullanici): void
{
    $kanal=(int) ($v['kanal'] ?? 0); $tur=(string) ($v['tur'] ?? ''); $deger=et_metin($v['deger'] ?? '');
    if (!in_array($tur,['sku','barkod'],true) || $deger === '') { throw new DomainException('SKU veya barkod girin.'); }
    $kod=et_metin($v['stok_kod'] ?? ''); $birim=et_metin($v['birim'] ?? '',30); $firma=$GLOBALS['firma'];
    $stok=et_tek($db,"SELECT LOGICALREF FROM {$firma}ITEMS WHERE CODE=:k AND ACTIVE=0",[':k'=>$kod]);
    $id=(int) ($stok['LOGICALREF'] ?? 0); $carpan=null;
    foreach (siparis_stok_birimleri($db,$firma,$id) as $b) {
        if (mb_strtoupper($b['kod']) === mb_strtoupper($birim)) { $carpan=$b['carpan']; $birim=$b['kod']; break; }
    }
    if ($id <= 0 || $carpan === null) { throw new DomainException('Geçerli stok kodu ve bu stoğa ait birim kodunu seçin.'); }
    $db->beginTransaction();
    try {
        et_kanal_kilitle($db,$kanal);
        if (!et_kanal($db,$kanal)['AKTIF']) { throw new DomainException('Kanal pasif.'); }
        $once=et_tek($db,'SELECT ID FROM dbo.M_ET_URUN_ESLEME WHERE KANAL_ID=:k AND TUR=:t AND DEGER=:d', [':k'=>$kanal,':t'=>$tur,':d'=>$deger]);
        $p=[':s'=>$id,':b'=>$birim,':c'=>$carpan,':u'=>$kullanici];
        if ($once) {
            $s=$db->prepare('UPDATE dbo.M_ET_URUN_ESLEME SET STOK_ID=:s,BIRIM=:b,CARPAN=:c,KULLANICI=:u,GUNCELLEME=SYSDATETIME() WHERE ID=:i');
            $s->execute($p+[':i'=>$once['ID']]);
        } else {
            $s=$db->prepare('INSERT INTO dbo.M_ET_URUN_ESLEME (KANAL_ID,TUR,DEGER,STOK_ID,BIRIM,CARPAN,KULLANICI) VALUES (:k,:t,:d,:s,:b,:c,:u)');
            $s->execute($p+[':k'=>$kanal,':t'=>$tur,':d'=>$deger]);
        }
        $s->closeCursor();
        $s=$db->prepare("UPDATE dbo.M_ET_SIPARIS SET DURUM='esleme',ONAY_GOVDE=NULL,ONAY_OZET=NULL WHERE KANAL_ID=:k AND DURUM IN ('yeni','esleme','hazir')");
        $s->execute([':k'=>$kanal]); $s->closeCursor();
        et_olay($db,$kanal,null,$kullanici,'esleme','Ürün eşlemesi kaydedildi; bekleyen onaylar yenilenecek.', ['tur'=>$tur,'deger'=>$deger,'stok_id'=>$id,'birim'=>$birim]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
}

/** Hazır olma hesaplanır; yazmaz. Aktarım öncesinde aynı kontroller yeniden çalışır. */
function et_kontrol(PDO $db, array $r, int $kullanici): array
{
    $hatalar=[]; $gorunum=[]; $kalemler=[]; $eslemeEksik=false;
    $normal=json_decode($r['NORMAL'],true,64,JSON_THROW_ON_ERROR);
    if (!$normal) { return ['hatalar'=>[$r['SON_HATA'] ?: 'Kaynak veri geçersiz.'],'satirlar'=>[],'govde'=>null,'durum'=>'hatali']; }
    $kanal=et_kanal($db,(int) $r['KANAL_ID']);
    if (!$kanal['AKTIF']) { $hatalar[]='Kanal pasif.'; }
    try { et_hedef_dogrula($db,(int) $r['CARI_ID'],(int) ($r['DEPO_NO'] ?? -1),(int) $r['PERSONEL_ID'],$kullanici); }
    catch (DomainException $e) { $hatalar[]=$e->getMessage(); $eslemeEksik=true; }
    $i=0;
    foreach ($normal['kalemler'] as $k) {
        $i++; $m=[];
        foreach (['sku','barkod'] as $tur) {
            if ($k[$tur] !== '') {
                $x=et_tek($db,'SELECT * FROM dbo.M_ET_URUN_ESLEME WHERE KANAL_ID=:k AND TUR=:t AND DEGER=:d',
                    [':k'=>$r['KANAL_ID'],':t'=>$tur,':d'=>$k[$tur]]);
                if ($x) { $m[]=$x; }
            }
        }
        $e=$m[0] ?? null;
        $g=$k+['stok'=>null,'carpan'=>null,'eldeki'=>null,'hedef_fiyat'=>null,'liste_fiyat'=>null,'stok_kdv'=>null,'engel'=>''];
        if (!$e || (count($m) === 2 && ((int) $m[0]['STOK_ID'] !== (int) $m[1]['STOK_ID'] || $m[0]['BIRIM'] !== $m[1]['BIRIM']))) {
            $g['engel']='Ürün eşlemesi eksik veya SKU/barkod eşlemeleri çelişiyor.'; $eslemeEksik=true;
        } elseif (mb_strtoupper($e['BIRIM']) !== mb_strtoupper($k['birim'])) {
            $g['engel']='Kaynak birimi ile eşleme birimi farklı.'; $eslemeEksik=true;
        } else {
            $stok=siparis_stok_getir($db,$GLOBALS['firma'],$GLOBALS['firmadonemx'],(int) $e['STOK_ID']);
            $birimler=siparis_stok_birimleri($db,$GLOBALS['firma'],(int) $e['STOK_ID']);
            $uygun=array_filter($birimler,static fn($b)=>$b['kod']===$e['BIRIM'] && abs($b['carpan']-(float) $e['CARPAN'])<0.000001);
            if (!$stok || !$uygun) { $g['engel']='Stok/birim değişmiş veya pasif.'; $eslemeEksik=true; }
            else {
                $fiyat=round($k['fiyat']/($k['kdv_dahil'] ? 1+$k['kdv']/100 : 1)/(float) $e['CARPAN'],6);
                $depo=et_tek($db,"SELECT ISNULL(SUM(ONHAND),0) AS M FROM {$GLOBALS['firmadonemx']}STINVTOT WHERE STOCKREF=:s AND INVENNO=:d",
                    [':s'=>$e['STOK_ID'],':d'=>$r['DEPO_NO']]);
                $g=array_replace($g,['stok'=>$stok,'carpan'=>(float) $e['CARPAN'],'eldeki'=>(float) $depo['M'],
                    'hedef_fiyat'=>$fiyat,'liste_fiyat'=>$stok['price'],'stok_kdv'=>$stok['vat']]);
                if ((int) m_p_yetki($kullanici,'SP1') !== 1 && (abs($stok['price']-$fiyat)>0.000001 || abs($stok['vat']-$k['kdv'])>0.000001)) {
                    $g['engel']='Kanal fiyatı/KDV farklı; SP1 yetkisi gerekiyor.';
                }
                $kalemler[]=['stok_id'=>(int) $e['STOK_ID'],'miktar'=>$k['miktar'],'birim_carpan'=>(float) $e['CARPAN'],'fiyat'=>$fiyat,'kdv'=>$k['kdv']];
            }
        }
        if ($g['engel']) { $hatalar[]=$i.'. satır: '.$g['engel']; }
        $gorunum[]=$g;
    }
    $h=siparis_kalemleri_hazirla($db,$GLOBALS['firma'],$GLOBALS['firmadonemx'],$kalemler,true);
    foreach ($h['skipped'] as $x) { $hatalar[]=$x['etiket'].': '.$x['sebep']; }
    // Aynı stok birden fazla satırdaysa talep toplanır.
    $talep=[];
    foreach ($h['ready'] as $x) { $talep[$x['stok_id']]=($talep[$x['stok_id']] ?? 0)+$x['ana_miktar']; }
    foreach ($gorunum as $g) {
        if ($g['stok'] && ($talep[$g['stok']['id']] ?? 0)>$g['eldeki']+0.000001) { $hatalar[]=$g['stok']['code'].': seçilen depoda stok yetersiz.'; }
    }
    $toplam=siparis_toplam_hesapla($h['ready'],0,0);
    if (count($h['ready']) !== count($normal['kalemler'])) { $hatalar[]='Bütün kalemler doğrulanmalı; kısmi aktarım yapılamaz.'; }
    if (abs($toplam['genel_toplam']-$normal['genel_toplam'])>0.005) { $hatalar[]='Kaynak toplamı ile Lumen toplamı uyuşmuyor.'; }
    $govde=['cari_id'=>(int) $r['CARI_ID'],'depo'=>(int) ($r['DEPO_NO'] ?? -1),'personel'=>(int) $r['PERSONEL_ID'],
        'kalemler'=>$kalemler,'hazir'=>$h['ready'],'toplam'=>$toplam['genel_toplam'],
        'mod'=>lumen_bagimsiz_mi() ? 'lumen' : 'logo','donem'=>lumen_bagimsiz_mi() ? 'LUMEN' : $GLOBALS['firmadonem']];
    return ['hatalar'=>array_values(array_unique($hatalar)),'satirlar'=>$gorunum,'govde'=>$govde,
        'durum'=>$hatalar ? ($eslemeEksik ? 'esleme' : 'hatali') : 'hazir','toplam'=>$toplam];
}
function et_hazirla(PDO $db, int $id, int $kullanici, string $surum, array $hedef): array
{
    $r=et_siparis($db,$id,$kullanici);
    $db->beginTransaction();
    try {
        et_kanal_kilitle($db,(int) $r['KANAL_ID']);
        $r=et_siparis($db,$id,$kullanici,true);
        if (in_array($r['DURUM'],['aktarildi','iptal'],true) || $r['SURUM_KOD'] !== $surum) { throw new DomainException('Kayıt değişmiş veya kapalı; sayfayı yenileyin.'); }
        $r['CARI_ID']=(int) ($hedef['cari'] ?? 0); $r['DEPO_NO']=(int) ($hedef['depo'] ?? -1); $r['PERSONEL_ID']=(int) ($hedef['personel'] ?? 0);
        et_hedef_dogrula($db,$r['CARI_ID'],$r['DEPO_NO'],$r['PERSONEL_ID'],$kullanici);
        $v=et_kontrol($db,$r,$kullanici); $hazir=!$v['hatalar'];
        $s=$db->prepare('UPDATE dbo.M_ET_SIPARIS SET DURUM=:d,CARI_ID=:c,DEPO_NO=:depo,PERSONEL_ID=:p,ONAY_GOVDE=:g,ONAY_OZET=:o,SON_HATA=:e WHERE ID=:i');
        $s->execute([':d'=>$v['durum'],':c'=>$r['CARI_ID'],':depo'=>$r['DEPO_NO'],':p'=>$r['PERSONEL_ID'],
            ':g'=>$hazir ? et_json($v['govde']) : null,':o'=>$hazir ? siparis_idempotency_parmakizi($v['govde']) : null,
            ':e'=>$v['hatalar'] ? mb_substr(implode(' ',$v['hatalar']),0,500) : null,':i'=>$id]); $s->closeCursor();
        et_olay($db,(int) $r['KANAL_ID'],$id,$kullanici,'kontrol',$hazir ? 'Kontroller tamamlandı; aktarım onayı bekleniyor.' : implode(' ',$v['hatalar']));
        $db->commit(); return $v;
    } catch (Throwable $e) { if ($db->inTransaction()) { $db->rollBack(); } throw $e; }
}

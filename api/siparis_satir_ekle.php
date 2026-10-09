<?php
declare(strict_types=1);

/**
 * POST /api/siparis_satir_ekle.php
 * Gövde: { "fis_id":123, "stok_id":1, "miktar":5, "birim_carpan"?:1, "fiyat"?:.., "kdv"?:.. }
 * Yanıt: { ok, satir:{...}, toplam:{...}, mesaj }
 *
 * Mevcut bir siparişe TEK satır ekler. ORFLINE INSERT sözleşmesi siparis_olustur.php
 * (../siparis/hareketeklecoklu.php) ile birebirdir; ekleme sonrası LINENO_ yeniden sıralanır ve
 * ORFICHE toplamı satırlardan yeniden hesaplanır (siparis_fis_toplam_yenile).
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');
include_once(__DIR__ . '/_siparis.inc');

global $dbh, $firma, $firmadonem, $firmadonemx, $depo, $kayitsaat, $reserve, $terminalkullanici;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
$terminalkullanici = $personel;

if (!api_yetki_var($personel, 'M1')) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş düzenleme yetkiniz yok.'], 403);
}

$body   = api_body();
$fisId  = siparis_kimlik($body['fis_id'] ?? 0);
if ($fisId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Geçerli bir kayıt kimliği gerekli.'], 400);
}
$stokId = siparis_kimlik($body['stok_id'] ?? 0);

try {
    $fis = siparis_fis_duzenlenebilir($dbh, $firmadonem, $fisId, $firma, $personel);
    if ($fis === null) {
        api_json(['ok' => false, 'mesaj' => 'Sipariş bulunamadı veya iptal edilmiş.'], 404);
    }

    // Kalemi siparis_olustur ile aynı kurallarla hazırla (F3: fiyatsız kalem reddedilir).
    $h = siparis_kalemleri_hazirla($dbh, $firma, $firmadonemx, [[
        'stok_id'      => $stokId,
        'miktar'       => $body['miktar'] ?? 0,
        'birim_carpan' => $body['birim_carpan'] ?? 1,
        'fiyat'        => $body['fiyat'] ?? null,
        'kdv'          => $body['kdv'] ?? null,
    ]]);
    if (!$h['ready']) {
        $sebep = $h['skipped'][0]['sebep'] ?? 'kalem geçersiz';
        api_json(['ok' => false, 'mesaj' => 'Satır eklenemedi: ' . $sebep], 422);
    }
} catch (Throwable $e) {
    error_log('API sipariş önkontrol: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Sipariş bilgileri okunamadı.'], 500);
}

$r = $h['ready'][0];

$kayitsaatI = (int) $kayitsaat;
$depoI      = (int) $depo;
$reserveOn  = ((string) $reserve === '1');
$cariId     = (int) $fis['cari_id'];

try {
    $dbh->beginTransaction();

    // Yeni satır numarası (ürün satırları arasında).
    $stMax = $dbh->prepare("SELECT MAX(LINENO_) AS SATIR FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :f AND LINETYPE = 0");
    $stMax->execute([':f' => $fisId]);
    $sonsirano = (int) (($stMax->fetch(PDO::FETCH_ASSOC)['SATIR'] ?? 0)) + 1;

    $anaMiktar = (float) $r['ana_miktar'];
    $fiyat     = (float) $r['fiyat'];
    $toplam    = (float) $r['toplam'];
    $kdv       = (float) $r['kdv'];
    $kdvTut    = (float) $r['kdv_tutar'];
    $satirGuid = guid();
    if ($reserveOn) {
        $rx = 1; $rm = $anaMiktar; $rt = date('Y-m-d');
    } else {
        $rx = 0; $rm = 0.0; $rt = '';
    }

    // ORFLINE INSERT — siparis_olustur.php satır sözleşmesiyle birebir.
    $stmtLine = $dbh->prepare("INSERT INTO {$firmadonem}ORFLINE (
STOCKREF,ORDFICHEREF,CLIENTREF,LINETYPE,PREVLINEREF,PREVLINENO,DETLINE,LINENO_,TRCODE,DATE_,TIME_,GLOBTRANS,CALCTYPE,CENTERREF,ACCOUNTREF,VATACCREF,VATCENTERREF,PRACCREF,PRCENTERREF,PRVATACCREF,PRVATCENREF,PROMREF,SPECODE,DELVRYCODE,AMOUNT,PRICE,TOTAL,SHIPPEDAMOUNT,DISCPER,DISTCOST,DISTDISC,DISTEXP,DISTPROM,VAT,VATAMNT,VATMATRAH,LINEEXP,UOMREF,USREF,UINFO1,UINFO2,UINFO3,UINFO4,UINFO5,UINFO6,UINFO7,UINFO8,VATINC,CLOSED,INUSE,DUEDATE,PRCURR,PRPRICE,REPORTRATE,BILLEDITEM,PAYDEFREF,EXTENREF,CPSTFLAG,SOURCEINDEX,SOURCECOSTGRP,BRANCH,DEPARTMENT,LINENET,SALESMANREF,STATUS,DREF,TRGFLAG,SITEID,RECSTATUS,ORGLOGICREF,FACTORYNR,WFSTATUS,NETDISCFLAG,NETDISCPERC,NETDISCAMNT,CONDITIONREF,DISTRESERVED,ONVEHICLE,CAMPAIGNREFS1,CAMPAIGNREFS2,CAMPAIGNREFS3,CAMPAIGNREFS4,CAMPAIGNREFS5,POINTCAMPREF,CAMPPOINT,PROMCLASITEMREF,REASONFORNOTSHP,CMPGLINEREF,PRRATE,GROSSUINFO1,GROSSUINFO2,CANCELLED,DEMPEGGEDAMNT,TEXTINC,OFFERREF,ORDERPARAM,ITEMASGREF,EXIMAMOUNT,OFFTRANSREF,ORDEREDAMOUNT,ORGLOGOID,TRCURR,TRRATE,WITHPAYTRANS,PROJECTREF,POINTCAMPREFS1,POINTCAMPREFS2,POINTCAMPREFS3,POINTCAMPREFS4,CAMPPOINTS1,CAMPPOINTS2,CAMPPOINTS3,CAMPPOINTS4,CMPGLINEREFS1,CMPGLINEREFS2,CMPGLINEREFS3,CMPGLINEREFS4,PRCLISTREF,AFFECTCOLLATRL,FCTYP,PURCHOFFNR,DEMFICHEREF,DEMTRANSREF,ALTPROMFLAG,VARIANTREF,REFLVATACCREF,REFLVATOTHACCREF,PRIORITY,AFFECTRISK,BOMREF,BOMREVREF,ROUTINGREF,OPERATIONREF,WSREF,ADDTAXRATE,ADDTAXCONVFACT,ADDTAXAMOUNT,ADDTAXACCREF,ADDTAXCENTERREF,ADDTAXAMNTISUPD,ADDTAXDISCAMOUNT,EXADDTAXRATE,EXADDTAXCONVF,EXADDTAXAMNT,EUVATSTATUS,ADDTAXVATMATRAH,CAMPPAYDEFREF,RPRICE,ORGDUEDATE,ORGAMOUNT,ORGPRICE,SPECODE2,CANDEDUCT,UNDERDEDUCTLIMIT,GLOBALID,DEDUCTIONPART1,DEDUCTIONPART2,PARENTLNREF,GUID,DORESERVE,RESERVEDATE,RESERVEAMOUNT
) VALUES (
:stokid,:stokhareket,:cariid,0,0,0,0,:sonsirano,:siparisdurum,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,0,0,0,0,0,0,0,0,0,0,0,'','',:stokmiktar,:stokfiyat,:stoktoplam,0,0,0,0,0,0,:stokkdv,:stoktoplamkdv,:stoktoplam_vatmatrah,:stokaciklama,:uomref,:stokbirim,1,1,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),0,0,1,0,0,0,0,:depo,0,0,0,:stoktoplam_linenet,:terminalkullanici,4,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,'',0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),:stokmiktar_org,:stokfiyat_org,'',0,0,'',0,0,0,:guid,:reservex,:reservetarih,:reservemiktar
)");
    $stmtLine->execute([
        ':stokid'               => (int) $r['stok_id'],
        ':stokhareket'          => $fisId,
        ':cariid'               => $cariId,
        ':sonsirano'            => $sonsirano,
        ':siparisdurum'         => 1,
        ':kayitsaat'            => $kayitsaatI,
        ':stokmiktar'           => $anaMiktar,
        ':stokfiyat'            => $fiyat,
        ':stoktoplam'           => $toplam,
        ':stokkdv'              => $kdv,
        ':stoktoplamkdv'        => $kdvTut,
        ':stoktoplam_vatmatrah' => $toplam,
        ':stokaciklama'         => '',
        ':uomref'               => (int) $r['uomref'],
        ':stokbirim'            => (int) $r['unitsetref'],
        ':depo'                 => $depoI,
        ':stoktoplam_linenet'   => $toplam,
        ':terminalkullanici'    => $personel,
        ':stokmiktar_org'       => $anaMiktar,
        ':stokfiyat_org'        => $fiyat,
        ':guid'                 => (string) $satirGuid,
        ':reservex'             => $rx,
        ':reservetarih'         => $rt,
        ':reservemiktar'        => $rm,
    ]);

    // Satır numaralarını düzgünleştir (iskonto satırı sona) ve fiş toplamını yenile.
    siparis_lineno_yenile($dbh, $firmadonem, $fisId);
    $top = siparis_fis_toplam_yenile($dbh, $firmadonem, $fisId);

    $dbh->commit();
} catch (Throwable $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    error_log('api/siparis_satir_ekle: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Satır eklenirken hata oluştu, kayıt yapılmadı.'], 500);
}

// Denetim izi.
if (function_exists('logSatirEkleme')) {
    try {
        logSatirEkleme($fisId, 0, $fis['fisno'], (string) $r['kod'], (string) $r['ad'],
            $anaMiktar, $fiyat, $toplam, $personel, 'Masaüstü API ile satır eklendi');
    } catch (Throwable $e) {
        error_log('siparis_satir_ekle log: ' . $e->getMessage());
    }
}

api_json([
    'ok'    => true,
    'fis'   => ['id' => $fisId, 'fisno' => $fis['fisno']],
    'satir' => ['stok_id' => (int) $r['stok_id'], 'kod' => $r['kod'], 'ad' => $r['ad'],
                'miktar' => $anaMiktar, 'fiyat' => $fiyat, 'kdv' => $kdv],
    'toplam' => [
        'brut'   => $top['brut'],   'brut_metin'   => api_money($top['brut']),
        'iskonto'=> $top['iskonto'],'iskonto_metin'=> api_money($top['iskonto']),
        'kdv'    => $top['kdv'],    'kdv_metin'    => api_money($top['kdv']),
        'net'    => $top['net'],    'net_metin'    => api_money($top['net']),
    ],
    'mesaj' => $r['kod'] . ' eklendi.',
]);

<?php
declare(strict_types=1);

/**
 * POST /api/siparis_olustur.php
 * Gövde: { "cari_id": 123, "kalemler": [ { "stok_id":1, "miktar":5, "fiyat"?:.. }, ... ] }
 * Yanıt: { ok, fis:{id,fisno}, cari, satir_sayisi, ara_toplam, genel_toplam, atlanan, mesaj }
 *
 * LOGO'ya YAZAR (tek transaction): ORFICHE başlık + ORFLINE satırlar + ORFICHE toplam.
 * Yazma sözleşmesi mevcut akıştan birebir alınmıştır (ai_beta_order_header,
 * ../siparis/hareketeklecoklu.php, ../siparis/lg_fis.php toplam güncelleme). Mevcut akışla aynı şekilde
 * satırlar KDV=0, iskontosuz açılır. Herhangi bir adım hata verirse tüm işlem geri alınır.
 */

include_once(__DIR__ . '/../ayr.php');
include_once(__DIR__ . '/_api.inc');
include_once(__DIR__ . '/_siparis.inc');

global $dbh, $firma, $firmadonem, $firmadonemx, $sipno, $depo, $kayitsaat, $saat, $dakika, $saniye, $reserve, $terminalkullanici;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_json(['ok' => false, 'mesaj' => 'Yalnızca POST desteklenir.'], 405);
}
$oturum   = api_oturum_gerekli($dbh);
$personel = (int) $oturum['personel'];
$terminalkullanici = $personel; // SALESMANREF / CAPIBLOCK alanları için

if (!api_yetki_var($personel, 'M1')) {
    api_json(['ok' => false, 'mesaj' => 'Sipariş oluşturma yetkiniz yok.'], 403);
}

$body     = api_body();
$cariId   = (int) ($body['cari_id'] ?? 0);
$kalemler = is_array($body['kalemler'] ?? null) ? $body['kalemler'] : [];
$iskonto1 = max(0.0, min(100.0, siparis_sayi($body['iskonto1'] ?? 0)));
$iskonto2 = max(0.0, min(100.0, siparis_sayi($body['iskonto2'] ?? 0)));
if ($cariId <= 0) {
    api_json(['ok' => false, 'mesaj' => 'Cari seçimi gerekiyor.'], 400);
}

// Özel Cari kısıtı: kısıtlı cariye sipariş açılamaz
// (web ../siparis/fisekle.php ile aynı kural).
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $personel, $cariId)) {
    api_json(['ok' => false, 'mesaj' => 'Cari bulunamadi.'], 404);
}

if (!$kalemler) {
    api_json(['ok' => false, 'mesaj' => 'En az bir kalem gerekiyor.'], 400);
}
if (count($kalemler) > 200) {
    api_json(['ok' => false, 'mesaj' => 'Tek seferde en fazla 200 kalem gönderilebilir.'], 400);
}

$cari = siparis_cari_getir($dbh, $firma, $cariId);
if ($cari === null) {
    api_json(['ok' => false, 'mesaj' => 'Cari bulunamadı.'], 404);
}

// Fiş AÇMADAN önce tüm kalemleri doğrula (F3 kuralı) — fiyatsız kalem orphan fiş bırakmaz.
$h     = siparis_kalemleri_hazirla($dbh, $firma, $firmadonemx, $kalemler);
$ready = $h['ready'];
if (!$ready) {
    api_json(['ok' => false, 'mesaj' => 'Geçerli kalem bulunamadı, sipariş açılmadı.', 'atlanan' => $h['skipped']], 422);
}

$kayitsaatI = (int) $kayitsaat;
$saatI      = (int) $saat;
$dakikaI    = (int) $dakika;
$saniyeI    = (int) $saniye;
$depoI      = (int) $depo;
$reserveOn  = ((string) $reserve === '1');

$brut = 0.0;
$netKdv = 0.0;
$fisId = 0;
$fisno = '';

try {
    $dbh->beginTransaction();

    // ---- ORFICHE başlık (ai_beta_order_header sözleşmesi) ----
    $stmtMax = $dbh->prepare("SELECT MAX(LOGICALREF) AS SONID FROM {$firmadonem}ORFICHE");
    $stmtMax->execute();
    $maxRow = $stmtMax->fetch(PDO::FETCH_ASSOC);
    $yeniId = intcevir($maxRow['SONID'] ?? 0) + 1;
    // INSERT için tahmini fiş no. LOGICALREF IDENTITY olduğundan gerçek id
    // INSERT sonrası alınır ve FICHENO toplam UPDATE'inde gerçek id'ye göre
    // kesinleştirilir (silme/boşluk veya eşzamanlı kayıtta tutarsızlığı önler).
    $fisno  = (string) $sipno . str_pad((string) $yeniId, 6, '0', STR_PAD_LEFT);
    $ficheGuid = guid();

    $stmtFiche = $dbh->prepare("INSERT INTO {$firmadonem}ORFICHE (
TRCODE,FICHENO,DATE_,TIME_,DOCODE,SPECODE,CYPHCODE,CLIENTREF,RECVREF,ACCOUNTREF,CENTERREF,SOURCEINDEX,SOURCECOSTGRP,UPDCURR,ADDDISCOUNTS,TOTALDISCOUNTS,TOTALDISCOUNTED,ADDEXPENSES,TOTALEXPENSES,TOTALPROMOTIONS,TOTALVAT,GROSSTOTAL,NETTOTAL,REPORTRATE,REPORTNET,GENEXP1,GENEXP2,GENEXP3,GENEXP4,EXTENREF,PAYDEFREF,PRINTCNT,BRANCH,DEPARTMENT,STATUS,CAPIBLOCK_CREATEDBY,CAPIBLOCK_CREADEDDATE,CAPIBLOCK_CREATEDHOUR,CAPIBLOCK_CREATEDMIN,CAPIBLOCK_CREATEDSEC,CAPIBLOCK_MODIFIEDBY,CAPIBLOCK_MODIFIEDDATE,CAPIBLOCK_MODIFIEDHOUR,CAPIBLOCK_MODIFIEDMIN,CAPIBLOCK_MODIFIEDSEC,SALESMANREF,SHPTYPCOD,SHPAGNCOD,GENEXCTYP,LINEEXCTYP,TRADINGGRP,TEXTINC,SITEID,RECSTATUS,ORGLOGICREF,FACTORYNR,WFSTATUS,SHIPINFOREF,CUSTORDNO,SENDCNT,DLVCLIENT,DOCTRACKINGNR,CANCELLED,ORGLOGOID,OFFERREF,OFFALTREF,TYP,ALTNR,ADVANCEPAYM,TRCURR,TRRATE,TRNET,PAYMENTTYPE,ONLYONEPAYLINE,OPSTAT,WITHPAYTRANS,PROJECTREF,WFLOWCRDREF,UPDTRCURR,AFFECTCOLLATRL,POFFERBEGDT,POFFERENDDT,REVISNR,LASTREVISION,CHECKAMOUNT,SLSOPPRREF,SLSACTREF,SLSCUSTREF,AFFECTRISK,TOTALADDTAX,TOTALEXADDTAX,APPROVE,APPROVEDATE,CHECKPRICE,GUID,EINVOICE
) VALUES (
:siparisdurum,:fisno,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,'MASA-API','','',:cariid,0,0,0,:depo,0,0,0,0,0,0,0,0,0,0,0,1,0,'Masaustu siparis','','','',0,0,0,0,0,:status,1,DATEADD(day,0,datediff(day,0,GETDATE())),:saat,:dakika,:saniye,0,NULL,0,0,0,:terminalkullanici,'','',2,0,'',0,0,1,0,0,0,0,'',0,0,'',0,'',0,0,0,0,0,:doviz,:dovizkuru,0,0,0,0,0,0,0,0,0,NULL,NULL,'',0,0,0,0,0,1,0,0,0,NULL,0,:guid,0
)");
    $stmtFiche->execute([
        ':siparisdurum'      => 1,
        ':fisno'             => $fisno,
        ':kayitsaat'         => $kayitsaatI,
        ':cariid'            => $cariId,
        ':depo'              => $depoI,
        ':status'            => 4,
        ':saat'              => $saatI,
        ':dakika'            => $dakikaI,
        ':saniye'            => $saniyeI,
        ':terminalkullanici' => $personel,
        ':doviz'             => 0,
        ':dovizkuru'         => 0.0,
        ':guid'              => (string) $ficheGuid,
    ]);

    // Yeni fişin gerçek LOGICALREF'i
    $stmtMax->execute();
    $maxRow2 = $stmtMax->fetch(PDO::FETCH_ASSOC);
    $fisId = intcevir($maxRow2['SONID'] ?? 0);
    if ($fisId <= 0) {
        throw new RuntimeException('Fiş kimliği alınamadı.');
    }
    // FICHENO'yu gerçek LOGICALREF'e göre kesinleştir (aşağıdaki toplam UPDATE'inde yazılır).
    $fisno = (string) $sipno . str_pad((string) $fisId, 6, '0', STR_PAD_LEFT);

    // ---- ORFLINE satırlar (../siparis/hareketeklecoklu.php sözleşmesi) ----
    $stmtLine = $dbh->prepare("INSERT INTO {$firmadonem}ORFLINE (
STOCKREF,ORDFICHEREF,CLIENTREF,LINETYPE,PREVLINEREF,PREVLINENO,DETLINE,LINENO_,TRCODE,DATE_,TIME_,GLOBTRANS,CALCTYPE,CENTERREF,ACCOUNTREF,VATACCREF,VATCENTERREF,PRACCREF,PRCENTERREF,PRVATACCREF,PRVATCENREF,PROMREF,SPECODE,DELVRYCODE,AMOUNT,PRICE,TOTAL,SHIPPEDAMOUNT,DISCPER,DISTCOST,DISTDISC,DISTEXP,DISTPROM,VAT,VATAMNT,VATMATRAH,LINEEXP,UOMREF,USREF,UINFO1,UINFO2,UINFO3,UINFO4,UINFO5,UINFO6,UINFO7,UINFO8,VATINC,CLOSED,INUSE,DUEDATE,PRCURR,PRPRICE,REPORTRATE,BILLEDITEM,PAYDEFREF,EXTENREF,CPSTFLAG,SOURCEINDEX,SOURCECOSTGRP,BRANCH,DEPARTMENT,LINENET,SALESMANREF,STATUS,DREF,TRGFLAG,SITEID,RECSTATUS,ORGLOGICREF,FACTORYNR,WFSTATUS,NETDISCFLAG,NETDISCPERC,NETDISCAMNT,CONDITIONREF,DISTRESERVED,ONVEHICLE,CAMPAIGNREFS1,CAMPAIGNREFS2,CAMPAIGNREFS3,CAMPAIGNREFS4,CAMPAIGNREFS5,POINTCAMPREF,CAMPPOINT,PROMCLASITEMREF,REASONFORNOTSHP,CMPGLINEREF,PRRATE,GROSSUINFO1,GROSSUINFO2,CANCELLED,DEMPEGGEDAMNT,TEXTINC,OFFERREF,ORDERPARAM,ITEMASGREF,EXIMAMOUNT,OFFTRANSREF,ORDEREDAMOUNT,ORGLOGOID,TRCURR,TRRATE,WITHPAYTRANS,PROJECTREF,POINTCAMPREFS1,POINTCAMPREFS2,POINTCAMPREFS3,POINTCAMPREFS4,CAMPPOINTS1,CAMPPOINTS2,CAMPPOINTS3,CAMPPOINTS4,CMPGLINEREFS1,CMPGLINEREFS2,CMPGLINEREFS3,CMPGLINEREFS4,PRCLISTREF,AFFECTCOLLATRL,FCTYP,PURCHOFFNR,DEMFICHEREF,DEMTRANSREF,ALTPROMFLAG,VARIANTREF,REFLVATACCREF,REFLVATOTHACCREF,PRIORITY,AFFECTRISK,BOMREF,BOMREVREF,ROUTINGREF,OPERATIONREF,WSREF,ADDTAXRATE,ADDTAXCONVFACT,ADDTAXAMOUNT,ADDTAXACCREF,ADDTAXCENTERREF,ADDTAXAMNTISUPD,ADDTAXDISCAMOUNT,EXADDTAXRATE,EXADDTAXCONVF,EXADDTAXAMNT,EUVATSTATUS,ADDTAXVATMATRAH,CAMPPAYDEFREF,RPRICE,ORGDUEDATE,ORGAMOUNT,ORGPRICE,SPECODE2,CANDEDUCT,UNDERDEDUCTLIMIT,GLOBALID,DEDUCTIONPART1,DEDUCTIONPART2,PARENTLNREF,GUID,DORESERVE,RESERVEDATE,RESERVEAMOUNT
) VALUES (
:stokid,:stokhareket,:cariid,0,0,0,0,:sonsirano,:siparisdurum,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,0,0,0,0,0,0,0,0,0,0,0,'','',:stokmiktar,:stokfiyat,:stoktoplam,0,0,0,0,0,0,:stokkdv,:stoktoplamkdv,:stoktoplam_vatmatrah,:stokaciklama,:uomref,:stokbirim,1,1,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),0,0,1,0,0,0,0,:depo,0,0,0,:stoktoplam_linenet,:terminalkullanici,4,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,'',0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),:stokmiktar_org,:stokfiyat_org,'',0,0,'',0,0,0,:guid,:reservex,:reservetarih,:reservemiktar
)");

    $sira = 0;
    foreach ($ready as $r) {
        $sira++;
        $anaMiktar = (float) $r['ana_miktar'];   // miktar × birim çarpanı (AMOUNT)
        $fiyat  = (float) $r['fiyat'];
        $toplam = (float) $r['toplam'];          // ana_miktar × fiyat (brüt)
        $kdv    = (float) $r['kdv'];
        $kdvTut = (float) $r['kdv_tutar'];
        $satirGuid = guid();
        if ($reserveOn) {
            $rx = 1;
            $rm = $anaMiktar;
            $rt = date('Y-m-d');
        } else {
            $rx = 0;
            $rm = 0.0;
            $rt = '';
        }

        $stmtLine->execute([
            ':stokid'               => (int) $r['stok_id'],
            ':stokhareket'          => $fisId,
            ':cariid'               => $cariId,
            ':sonsirano'            => $sira,
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
    }

    // ---- Genel iskonto (varsa) — ../siparis/iskonto.php sözleşmesi: LINETYPE=2 satır + dağıtım ----
    // İki kademe sıralı uygulanır (önce iskonto1, kalan üzerine iskonto2).
    if ($iskonto1 > 0.0) {
        siparis_iskonto_uygula($dbh, $firmadonem, $fisId, $cariId, $iskonto1, $kayitsaatI);
    }
    if ($iskonto2 > 0.0) {
        siparis_iskonto_uygula($dbh, $firmadonem, $fisId, $cariId, $iskonto2, $kayitsaatI);
    }

    // ---- ORFICHE toplamları (../siparis/lg_fis.php satır sonrası güncelleme sözleşmesi) ----
    $stmtSum = $dbh->prepare("
        SELECT SUM(VATAMNT) AS KDVTUTAR, SUM(TOTAL) AS TUTAR, SUM(DISTDISC) AS ISK
        FROM {$firmadonem}ORFLINE WHERE ORDFICHEREF = :ref AND LINETYPE = 0
    ");
    $stmtSum->execute([':ref' => $fisId]);
    $t    = $stmtSum->fetch(PDO::FETCH_ASSOC) ?: [];
    $kdvT = round((float) ($t['KDVTUTAR'] ?? 0), 2);
    $brut = round((float) ($t['TUTAR'] ?? 0), 2);
    $isk  = round((float) ($t['ISK'] ?? 0), 2);
    $net  = round($brut - $isk, 2);
    $netKdv = round($net + $kdvT, 2);

    $stmtUpd = $dbh->prepare("
        UPDATE {$firmadonem}ORFICHE
        SET ADDDISCOUNTS=:a, TOTALDISCOUNTS=:td, TOTALDISCOUNTED=:tded,
            TOTALVAT=:tv, GROSSTOTAL=:gt, NETTOTAL=:nt, REPORTNET=:rn, TRNET=:tn,
            FICHENO=:fisno
        WHERE LOGICALREF=:ref
    ");
    $stmtUpd->execute([
        ':a' => $isk, ':td' => $isk, ':tded' => $brut, ':tv' => $kdvT,
        ':gt' => $brut, ':nt' => $netKdv, ':rn' => $netKdv, ':tn' => $netKdv,
        ':fisno' => $fisno, ':ref' => $fisId,
    ]);

    $dbh->commit();
} catch (Throwable $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    error_log('api/siparis_olustur: ' . $e->getMessage());
    api_json(['ok' => false, 'mesaj' => 'Sipariş kaydedilirken hata oluştu, hiçbir kayıt yapılmadı.'], 500);
}

// Denetim izi (mevcut web akışıyla aynı) — log hatası siparişi etkilemez.
if (function_exists('logFisOlusturma')) {
    try {
        logFisOlusturma($fisId, $fisno, $cariId, $personel, [
            'doviz'    => 'TL',
            'depo'     => $depoI,
            'durum'    => 4,
            'aciklama' => 'Masaüstü API ile fiş oluşturuldu',
        ]);
    } catch (Throwable $e) {
        error_log('siparis_olustur log: ' . $e->getMessage());
    }
}

api_json([
    'ok'  => true,
    'fis' => ['id' => $fisId, 'fisno' => $fisno],
    'cari' => $cari,
    'satir_sayisi'         => count($ready),
    'ara_toplam'           => $brut,
    'ara_toplam_metin'     => api_money($brut),
    'iskonto_toplam'       => $isk,
    'iskonto_toplam_metin' => api_money($isk),
    'kdv_toplam'           => $kdvT,
    'kdv_toplam_metin'     => api_money($kdvT),
    'genel_toplam'         => $netKdv,
    'genel_toplam_metin'   => api_money($netKdv),
    'atlanan'              => $h['skipped'],
    'mesaj'                => $fisno . ' numaralı sipariş oluşturuldu.',
]);

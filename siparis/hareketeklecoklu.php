<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../log_ip.php");
include_once(__DIR__ . "/iskonto_lib.php"); // yeni urunde mevcut iskontoyu otomatik uygulamak icin
if (isset($_POST['cstok_miktari'])) {

  // CSRF koruması (POST istekleri için)
  if (!csrf_verify()) {
    http_response_code(403);
    die('Geçersiz güvenlik doğrulaması.');
  }

  $stokhareket = isset($stokhareket) ? (int) $stokhareket : (isset($_POST['stokhareket']) ? (int) $_POST['stokhareket'] : 0);
  $cstok_miktari = isset($_POST['cstok_miktari']) ? (array) $_POST['cstok_miktari'] : [];

  $stmtCari = $dbh->prepare("SELECT CLIENTREF FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF = :stokhareket");
  $stmtCari->execute([':stokhareket' => $stokhareket]);
  $listcari = $stmtCari->fetch(PDO::FETCH_ASSOC);
  $cariid = $listcari ? intcevir($listcari['CLIENTREF']) : 0;

  $stmtSipvar = $dbh->prepare("SELECT TOP 1 O.LOGICALREF, S.NAME
    FROM " . $firmadonem . "ORFLINE O
    LEFT JOIN " . $firma . "ITEMS S ON O.STOCKREF = S.LOGICALREF
    WHERE O.STOCKREF = :stokid
    AND O.ORDFICHEREF = :stokhareket");

  $stmtSirano = $dbh->prepare("SELECT MAX(LINENO_) AS SATIR
    FROM " . $firmadonem . "ORFLINE
    WHERE ORDFICHEREF = :stokhareket
    AND LINETYPE = 0");

  $stmtBirim = $dbh->prepare("SELECT UNITLINEREF
    FROM " . $firma . "ITMUNITA
    WHERE ITEMREF = :stokid");

  $stmtSonAlis = $dbh->prepare("SELECT TOP 1 S.PRICE, I.NAME
    FROM " . $firmadonem . "STLINE S
    LEFT JOIN " . $firma . "ITEMS I ON S.STOCKREF=I.LOGICALREF
    WHERE S.STOCKREF = :stokid
    AND S.LINETYPE = 0
    AND S.CANCELLED = 0
    AND S.TRCODE IN (1,14)
    ORDER BY S.DATE_ DESC");

  $stmtInsert = $dbh->prepare("INSERT INTO " . $firmadonem . "ORFLINE  (

      [STOCKREF]
      ,[ORDFICHEREF]
      ,[CLIENTREF]
      ,[LINETYPE]
      ,[PREVLINEREF]
      ,[PREVLINENO]
      ,[DETLINE]
      ,[LINENO_]
      ,[TRCODE]
      ,[DATE_]
      ,[TIME_]
      ,[GLOBTRANS]
      ,[CALCTYPE]
      ,[CENTERREF]
      ,[ACCOUNTREF]
      ,[VATACCREF]
      ,[VATCENTERREF]
      ,[PRACCREF]
      ,[PRCENTERREF]
      ,[PRVATACCREF]
      ,[PRVATCENREF]
      ,[PROMREF]
      ,[SPECODE]
      ,[DELVRYCODE]
      ,[AMOUNT]
      ,[PRICE]
      ,[TOTAL]
      ,[SHIPPEDAMOUNT]
      ,[DISCPER]
      ,[DISTCOST]
      ,[DISTDISC]
      ,[DISTEXP]
      ,[DISTPROM]
      ,[VAT]
      ,[VATAMNT]
      ,[VATMATRAH]
      ,[LINEEXP]
      ,[UOMREF]
      ,[USREF]
      ,[UINFO1]
      ,[UINFO2]
      ,[UINFO3]
      ,[UINFO4]
      ,[UINFO5]
      ,[UINFO6]
      ,[UINFO7]
      ,[UINFO8]
      ,[VATINC]
      ,[CLOSED]

      ,[INUSE]
      ,[DUEDATE]
      ,[PRCURR]
      ,[PRPRICE]
      ,[REPORTRATE]
      ,[BILLEDITEM]
      ,[PAYDEFREF]
      ,[EXTENREF]
      ,[CPSTFLAG]
      ,[SOURCEINDEX]
      ,[SOURCECOSTGRP]
      ,[BRANCH]
      ,[DEPARTMENT]
      ,[LINENET]
      ,[SALESMANREF]
      ,[STATUS]
      ,[DREF]
      ,[TRGFLAG]
      ,[SITEID]
      ,[RECSTATUS]
      ,[ORGLOGICREF]
      ,[FACTORYNR]
      ,[WFSTATUS]
      ,[NETDISCFLAG]
      ,[NETDISCPERC]
      ,[NETDISCAMNT]
      ,[CONDITIONREF]
      ,[DISTRESERVED]
      ,[ONVEHICLE]
      ,[CAMPAIGNREFS1]
      ,[CAMPAIGNREFS2]
      ,[CAMPAIGNREFS3]
      ,[CAMPAIGNREFS4]
      ,[CAMPAIGNREFS5]
      ,[POINTCAMPREF]
      ,[CAMPPOINT]
      ,[PROMCLASITEMREF]
      ,[REASONFORNOTSHP]
      ,[CMPGLINEREF]
      ,[PRRATE]
      ,[GROSSUINFO1]
      ,[GROSSUINFO2]
      ,[CANCELLED]
      ,[DEMPEGGEDAMNT]
      ,[TEXTINC]
      ,[OFFERREF]
      ,[ORDERPARAM]
      ,[ITEMASGREF]
      ,[EXIMAMOUNT]
      ,[OFFTRANSREF]
      ,[ORDEREDAMOUNT]
      ,[ORGLOGOID]
      ,[TRCURR]
      ,[TRRATE]
      ,[WITHPAYTRANS]
      ,[PROJECTREF]
      ,[POINTCAMPREFS1]
      ,[POINTCAMPREFS2]
      ,[POINTCAMPREFS3]
      ,[POINTCAMPREFS4]
      ,[CAMPPOINTS1]
      ,[CAMPPOINTS2]
      ,[CAMPPOINTS3]
      ,[CAMPPOINTS4]
      ,[CMPGLINEREFS1]
      ,[CMPGLINEREFS2]
      ,[CMPGLINEREFS3]
      ,[CMPGLINEREFS4]
      ,[PRCLISTREF]
      ,[AFFECTCOLLATRL]
      ,[FCTYP]
      ,[PURCHOFFNR]
      ,[DEMFICHEREF]
      ,[DEMTRANSREF]
      ,[ALTPROMFLAG]
      ,[VARIANTREF]
      ,[REFLVATACCREF]
      ,[REFLVATOTHACCREF]
      ,[PRIORITY]
      ,[AFFECTRISK]
      ,[BOMREF]
      ,[BOMREVREF]
      ,[ROUTINGREF]
      ,[OPERATIONREF]
      ,[WSREF]
      ,[ADDTAXRATE]
      ,[ADDTAXCONVFACT]
      ,[ADDTAXAMOUNT]
      ,[ADDTAXACCREF]
      ,[ADDTAXCENTERREF]
      ,[ADDTAXAMNTISUPD]
      ,[ADDTAXDISCAMOUNT]
      ,[EXADDTAXRATE]
      ,[EXADDTAXCONVF]
      ,[EXADDTAXAMNT]
      ,[EUVATSTATUS]
      ,[ADDTAXVATMATRAH]
      ,[CAMPPAYDEFREF]
      ,[RPRICE]
      ,[ORGDUEDATE]
      ,[ORGAMOUNT]
      ,[ORGPRICE]
      ,[SPECODE2]

      ,[CANDEDUCT]
      ,[UNDERDEDUCTLIMIT]
      ,[GLOBALID]
      ,[DEDUCTIONPART1]
      ,[DEDUCTIONPART2]
      ,[PARENTLNREF]
	  ,[GUID]
	  ,[DORESERVE]
	  ,[RESERVEDATE]
	  ,[RESERVEAMOUNT]
)VALUES (

:stokid,:stokhareket,:cariid,0,0,0,0,:sonsirano,:siparisdurum,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,0,0,0,0,0,0,0,0,0,0,0,'','',:stokmiktar,:stokfiyat,:stoktoplam,0,0,0,0,0,0,:stokkdv,:stoktoplamkdv,:stoktoplam_vatmatrah,:stokaciklama,:uomref,:stokbirim,1,1,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),0,0,1,0,0,0,0,:depo,0,0,0,:stoktoplam_linenet,:terminalkullanici,4,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,'',0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,1,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),:stokmiktar_org,:stokfiyat_org,'',0,0,'',0,0,0,:guid,:reservex,:reservetarih,:reservemiktar

)");

  $stmtYeniSatir = $dbh->prepare("SELECT TOP 1 L.LOGICALREF, F.FICHENO, I.CODE, I.NAME
    FROM " . $firmadonem . "ORFLINE L
    LEFT JOIN " . $firma . "ITEMS I ON I.LOGICALREF = L.STOCKREF
    LEFT JOIN " . $firmadonem . "ORFICHE F ON F.LOGICALREF = L.ORDFICHEREF
    WHERE L.ORDFICHEREF = :stokhareket
    AND L.LINETYPE = 0
    ORDER BY L.LOGICALREF DESC");

  $stmtSiranoIskontoSelect = $dbh->prepare("SELECT LOGICALREF
    FROM " . $firmadonem . "ORFLINE
    WHERE ORDFICHEREF = :stokhareket
    AND LINETYPE = 2
    ORDER BY LINENO_ ASC");

  $stmtSiranoIskontoUpdate = $dbh->prepare("UPDATE " . $firmadonem . "ORFLINE
    SET LINENO_ = :lineno_
    WHERE LOGICALREF = :logicalref");

  $toplamsatismiktari = count($cstok_miktari);
  for ($i = 0; $i < $toplamsatismiktari; $i++) {
    $cstok_mik = str_replace(',', '.', $_POST['cstok_miktari'][$i] ?? '0');
    $cstok_fiy = str_replace(',', '.', $_POST['cstok_fiyati'][$i] ?? '0');
    $cstok_id = $_POST['cstok_id'][$i] ?? '';
    $cstok_birim = $_POST['cstok_birim'][$i] ?? '';
    $kayit = true;
    
    /************maliyet kontrol****************//*
    $son_alis = "";
    if ($cstok_mik > 0 && isset($maliyetkontrol) && $maliyetkontrol == 1) {
      $stmtSonAlis->execute(array(':stokid' => (int) $cstok_id));
      $stokalisX = $stmtSonAlis->fetch(PDO::FETCH_ASSOC);

      $son_alis = isset($stokalisX['PRICE']) ? $stokalisX['PRICE'] : "";



      if ($son_alis > $cstok_fiy) {
        echo '<script type="text/javascript"> ';

        echo '  if (alert("' . $stokalisX["NAME"] . ' alis fiyat satış fiyatından fazla lütfen kontrol ediniz")) {';
        $kayit = false;

        echo '}';

        echo '</script>';

      }
    }

    /******************maliyet kontol bitiş*****************************/

    /******************aynı ürün kontrol*****************************/

    if ($cstok_mik > 0 && isset($cokluekledeayniurun) && $cokluekledeayniurun == 1) {
      $stmtSipvar->execute([':stokid' => intcevir($cstok_id), ':stokhareket' => $stokhareket]);
      $sipvarmi = $stmtSipvar->fetch(PDO::FETCH_ASSOC);
      if ($sipvarmi) {
        echo '<script type="text/javascript"> ';

        echo '  if (window.toast) { toast("' . $sipvarmi["NAME"] . ' daha önceden eklenmiş ürün", "warning"); } else { alert("' . $sipvarmi["NAME"] . ' daha önceden eklenmiş ürün"); }';
        $kayit = false;

        echo '</script>';

      }
    }
    /******************aynı ürün kontrol bitiş*****************************/

    /******************fiyat kontrol*****************************/
    if ($cstok_mik > 0 && ($cstok_fiy === '' || $cstok_fiy === null || (float)$cstok_fiy <= 0)) {
      // Stok adını bul
      $stmtStokAd = $dbh->prepare("SELECT NAME FROM " . $firma . "ITEMS WHERE LOGICALREF = :stokid");
      $stmtStokAd->execute([':stokid' => intcevir($cstok_id)]);
      $stokAdBilgi = $stmtStokAd->fetch(PDO::FETCH_ASSOC);
      $stokAdi = $stokAdBilgi ? $stokAdBilgi['NAME'] : 'Bilinmeyen ürün';

      echo '<script type="text/javascript">';
      echo 'if (window.toast) { toast("' . addslashes($stokAdi) . ' için fiyat girilmemiş!", "error"); } else { alert("' . addslashes($stokAdi) . ' için fiyat girilmemiş!"); }';
      echo '</script>';
      $kayit = false;
    }
    /******************fiyat kontrol bitiş*****************************/

    if ($cstok_mik > 0 && $kayit == true) {
      echo $cstok_id . '<br>';

	      $siparisdurum = 1;
	      $stokid = intcevir($cstok_id);
	      $stokmiktar = $cstok_mik;
	      $stokfiyat = $cstok_fiy;
	      $stokkdv = defined('RESMI_FORCE_KDV') ? (float) RESMI_FORCE_KDV : 0;
	      $stokaciklama = "";
	      $stokbirim = intcevir($cstok_birim);
      $stoktoplam = round($stokmiktar * $stokfiyat, 2);
      $stoktoplamkdv = round((($stoktoplam / 100) * $stokkdv), 2);
      $tarih = date("Y-m-d");
      $guid = guid();
      //HAREKET ÜRÜN SAYI NOSU
      $stmtSirano->execute([':stokhareket' => $stokhareket]);
      $sirano = $stmtSirano->fetch(PDO::FETCH_ASSOC) ?: [];
      $sonsirano = intcevir($sirano['SATIR'] ?? 0) + 1;



      $stmtBirim->execute([':stokid' => $stokid]);
      $birim = $stmtBirim->fetch(PDO::FETCH_ASSOC) ?: [];
      $uomref = intcevir($birim['UNITLINEREF'] ?? 0);

      if ($reserve == '0') {
        $reservex = 0;
        $reservemiktar = 0;
        $reservetarih = "";

      } else {

        $reservex = 1;
        $reservemiktar = $stokmiktar;
        //$reservetarih=NULL;
        $reservetarih = $tarih;
      }

      $stmtInsert->execute([':stokid' => $stokid, ':stokhareket' => $stokhareket, ':cariid' => $cariid, ':sonsirano' => $sonsirano, ':siparisdurum' => $siparisdurum, ':kayitsaat' => (int) $kayitsaat, ':stokmiktar' => (float) $stokmiktar, ':stokfiyat' => (float) $stokfiyat, ':stoktoplam' => $stoktoplam, ':stokkdv' => (float) $stokkdv, ':stoktoplamkdv' => $stoktoplamkdv, ':stoktoplam_vatmatrah' => $stoktoplam, ':stokaciklama' => $stokaciklama, ':uomref' => $uomref, ':stokbirim' => $stokbirim, ':depo' => (int) $depo, ':stoktoplam_linenet' => $stoktoplam, ':terminalkullanici' => (int) $terminalkullanici, ':stokmiktar_org' => (float) $stokmiktar, ':stokfiyat_org' => (float) $stokfiyat, ':guid' => (string) $guid, ':reservex' => (int) $reservex, ':reservetarih' => $reservetarih, ':reservemiktar' => (float) $reservemiktar]);


      // ========================================
      // Eklenen satırın LOGICALREF'ini al ve logla
      // ========================================
      $stmtYeniSatir->execute([':stokhareket' => $stokhareket]);
      $yeniSatir = $stmtYeniSatir->fetch(PDO::FETCH_ASSOC);

      if ($yeniSatir) {
        // Log kaydı oluştur
        $logBasarili = logSatirEkleme(
          $stokhareket,                           // FIS_REF
          intcevir($yeniSatir['LOGICALREF']),     // SATIR_REF
          $yeniSatir['FICHENO'],                  // FICHENO
          $yeniSatir['CODE'],                     // STOK_KODU
          $yeniSatir['NAME'],                     // STOK_ADI
          $stokmiktar,                            // MIKTAR
          $stokfiyat,                             // FIYAT
          $stoktoplam,                            // TOTAL
          $terminalkullanici,                     // KULLANICI_ID
          ''                                      // ACIKLAMA (çoklu eklemede açıklama yok)
        );

        // Log kaydedilemezse uyarı ver (sessizce devam et)
        if (!$logBasarili) {
          error_log("UYARI: Satır ekleme logu kaydedilemedi! Fiş: {$yeniSatir['FICHENO']}, Stok: {$yeniSatir['CODE']}");
        }
      } else {
        // Satır bilgisi alınamadıysa da logla
        error_log("HATA: Eklenen satır bilgisi alınamadı! Fiş REF: {$stokhareket}");
      }
      // ========================================


      $stmtSiranoIskontoSelect->execute([':stokhareket' => $stokhareket]);
      while ($siranoisk2 = $stmtSiranoIskontoSelect->fetch(PDO::FETCH_ASSOC)) {
        $siraid1 = intcevir($siranoisk2['LOGICALREF']);
        $siranoiskX = $sonsirano + 1;
        $stmtSiranoIskontoUpdate->execute([':lineno_' => $siranoiskX, ':logicalref' => $siraid1]);
        $sonsirano = $siranoiskX;
      }
    }

  }

  // Tum urunler eklendi: fiste iskonto satiri (LINETYPE=2) varsa yeni urun(ler)
  // dahil hepsine mevcut iskontoyu otomatik uygula (hareketekle.php ile ayni mantik).
  // Iskonto yoksa fonksiyon hicbir sey yapmaz; hata olursa urunler eklenmis kalir.
  try {
    fis_iskonto_oranlari_uygula($dbh, $firmadonem, (int) $stokhareket);
  } catch (Throwable $e) {
    error_log('hareketeklecoklu.php otomatik iskonto hatasi: ' . $e->getMessage());
  }

  echo '<script>window.location="?stokhareket=' . $stokhareket . '";</script>';
}

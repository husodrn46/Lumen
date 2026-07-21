<?php
declare(strict_types=1);

	require_once __DIR__ . '/../kontrol.php';
	include_once(__DIR__ . "/../log_ip.php");

	// AJAX istegi olup olmadigi (lg_fis.php sayfa yenilemeden iskonto uygulamak icin)
	$isIskontoAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

if (!function_exists('hesaplaIskontoluSatir')) {
function hesaplaIskontoluSatir(float $brutToplam, float $miktar, float $oran): array
{
   $oran = max(0.0, min(100.0, $oran));

   if ($brutToplam <= 0) {
      return ['net' => 0.0, 'iskonto' => 0.0];
   }

   // Oran %0 ise hesap yapma, dogrudan dondur.
   // Aksi halde per-unit rounding biriktirerek -0,68 TL gibi sapma olusturuyor.
   if (abs($oran) < 0.00001) {
      return ['net' => round($brutToplam, 2), 'iskonto' => 0.0];
   }

   if ($miktar > 0) {
      // Birim bazında iskonto uygulayıp 2 hane yuvarla, sonra satır toplamına dön.
      $birimBrut = $brutToplam / $miktar;
      $birimNet = round($birimBrut * (1 - ($oran / 100)), 2);
      $net = round($birimNet * $miktar, 2);
   } else {
      $net = round($brutToplam * (1 - ($oran / 100)), 2);
   }

   $iskonto = round($brutToplam - $net, 2);
   if ($iskonto < 0) {
      $iskonto = 0.0;
   }

   return ['net' => $net, 'iskonto' => $iskonto];
}
} // if (!function_exists('hesaplaIskontoluSatir')) — iskonto_lib.php ile cakismayi onler

		if (isset($_POST['isk1'])) {
      // CSRF koruması
      if (!csrf_verify()) {
        http_response_code(403);
        die("Geçersiz güvenlik doğrulaması.");
      }

      $isk1 = isset($_POST['isk1']) ? (float) virgul($_POST['isk1']) : 0.0;
      $stokhareket = isset($_POST['stokhareket']) ? (int) $_POST['stokhareket'] : 0;
      $cari = isset($_POST['cari']) ? (int) $_POST['cari'] : 0;
      $stmtSirano = $dbh->prepare("SELECT MAX(LINENO_) AS SATIR FROM " . $firmadonem . "ORFLINE WHERE ORDFICHEREF = :stokhareket");
      $stmtSirano->execute([':stokhareket' => $stokhareket]);
      $sirano = $stmtSirano->fetch(PDO::FETCH_ASSOC);
      $sonsirano = (int) ($sirano['SATIR'] ?? 0) + 1;
      $stmtIskEkle1 = $dbh->prepare("INSERT INTO ".$firmadonem."ORFLINE  (    
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
      ,[DORESERVE]
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
	)VALUES (
	0,:stokhareket,:cari,2,0,0,0,:sonsirano,1,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,1,0,0,0,0,0,0,0,0,0,0,'','',0,0,0,0,:isk,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),0,0,1,0,0,0,0,0,0,0,0,0,0,1,0,0,0,1
	,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0
	)");
      $stmtIskEkle1->execute([':stokhareket' => $stokhareket, ':cari' => $cari, ':sonsirano' => $sonsirano, ':kayitsaat' => $kayitsaat, ':isk' => $isk1]);
      $topiskx=0;
      $stmtIskGuncelle=$dbh->prepare("SELECT LOGICALREF,TOTAL,VAT,DISTDISC,AMOUNT FROM ".$firmadonem."ORFLINE  WHERE ORDFICHEREF = :stokhareket AND LINETYPE=0");
      $stmtIskGuncelle->execute([':stokhareket' => $stokhareket]);
      $stmtIskSatirGuncelle = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET VATAMNT = :vatamnt, DISTCOST = :distcost, DISTDISC = :distdisc, VATMATRAH = :vatmatrah, LINENET = :linenet WHERE LOGICALREF = :logicalref");
      WHILE($iskgncl=$stmtIskGuncelle->fetch(PDO::FETCH_ASSOC))
   	 { 
   	$idnohi=$iskgncl['LOGICALREF'];
   	$iskontotutarx=(float)$iskgncl['TOTAL']-(float)$iskgncl['DISTDISC'];
      $miktar = (float)($iskgncl['AMOUNT'] ?? 0);
      $hesap = hesaplaIskontoluSatir($iskontotutarx, $miktar, $isk1);
   	  $iskontotutar=$hesap['iskonto'];
   	 $iskontolu=$hesap['net'];
   	 $yenikdv=$iskgncl['VAT'];
   	 $stoktoplamkdvlp=round((($iskontolu/100)*$yenikdv), 2);
      if ($stoktoplamkdvlp < 0) { $stoktoplamkdvlp = 0; }
   	 $topisk=round($iskontotutar+(float)$iskgncl['DISTDISC'], 2);
   	 
   	 $topiskx+=$topisk;
   	$stmtIskSatirGuncelle->execute([':vatamnt' => $stoktoplamkdvlp, ':distcost' => $topisk, ':distdisc' => $topisk, ':vatmatrah' => $iskontolu, ':linenet' => $iskontolu, ':logicalref' => (int) $idnohi]);
   
   	 }
      $stmtIskontoSatirTutar = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET TOTAL = :total WHERE ORDFICHEREF = :stokhareket AND LINETYPE=2 AND LINENO_ = :lineno");
      $stmtIskontoSatirTutar->execute([':total' => $topiskx, ':stokhareket' => $stokhareket, ':lineno' => $sonsirano]);
      // İskonto logla (1. İskonto)
      $stmtFisInfo = $dbh->prepare("SELECT FICHENO FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket");
      $stmtFisInfo->execute([':stokhareket' => $stokhareket]);
      $fisInfo = $stmtFisInfo->fetch(PDO::FETCH_ASSOC);
      logIskontoDegisiklik(
    			$stokhareket,                      // FIS_REF
    			$fisInfo['FICHENO'],               // FICHENO
    		1,                                 // ISKONTO_SEVIYE (1. iskonto)
    		0,                                 // ESKI_ISKONTO
    		$isk1,                             // YENI_ISKONTO
    		0,                                 // ESKI_TUTAR
    		$topiskx,                          // YENI_TUTAR
    		$terminalkullanici,                // KULLANICI_ID
    		0,                                 // ONAYLAYAN_ID
    		'1. İskonto uygulandı: %' . $isk1  // ACIKLAMA
    	);
      if ($isIskontoAjax) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => true, 'msg' => '1. Iskonto uygulandi']);
          exit;
      }
      echo  '<script>window.location="?stokhareket='.$stokhareket.'";</script>' ;
  } elseif (isset($_POST['isk2'])) {
      // CSRF koruması
      if (!csrf_verify()) {
        http_response_code(403);
        die("Geçersiz güvenlik doğrulaması.");
      }
      $isk2 = isset($_POST['isk2']) ? (float) virgul($_POST['isk2']) : 0.0;
      $stokhareket = isset($_POST['stokhareket']) ? (int) $_POST['stokhareket'] : 0;
      $cari = isset($_POST['cari']) ? (int) $_POST['cari'] : 0;
      $stmtSirano = $dbh->prepare("SELECT MAX(LINENO_) AS SATIR FROM " . $firmadonem . "ORFLINE WHERE ORDFICHEREF = :stokhareket");
      $stmtSirano->execute([':stokhareket' => $stokhareket]);
      $sirano = $stmtSirano->fetch(PDO::FETCH_ASSOC);
      $sonsirano = (int) ($sirano['SATIR'] ?? 0) + 1;
      $stmtIskEkle2=$dbh->prepare("INSERT INTO ".$firmadonem."ORFLINE  (    
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
      ,[DORESERVE]
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
	)VALUES (
	0,:stokhareket,:cari,2,0,0,0,:sonsirano,1,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,1,0,0,0,0,0,0,0,0,0,0,'','',0,0,0,0,:isk,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,DATEADD(day,0,datediff(day,0,GETDATE())),0,0,1,0,0,0,0,0,0,0,0,0,0,1,0,0,0,1
	,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0,0
	)");
      $stmtIskEkle2->execute([':stokhareket' => $stokhareket, ':cari' => $cari, ':sonsirano' => $sonsirano, ':kayitsaat' => $kayitsaat, ':isk' => $isk2]);
      $topisk2x=0;
      $stmtIskGuncelle2=$dbh->prepare("SELECT LOGICALREF,TOTAL,VAT,DISTDISC,AMOUNT FROM ".$firmadonem."ORFLINE  WHERE ORDFICHEREF = :stokhareket AND LINETYPE=0");
      $stmtIskGuncelle2->execute([':stokhareket' => $stokhareket]);
      $stmtIskSatirGuncelle2 = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET VATAMNT = :vatamnt, DISTCOST = :distcost, DISTDISC = :distdisc, VATMATRAH = :vatmatrah, LINENET = :linenet WHERE LOGICALREF = :logicalref");
      WHILE($iskgncl2=$stmtIskGuncelle2->fetch(PDO::FETCH_ASSOC))
   	 { 
   	$idnohi2=$iskgncl2['LOGICALREF'];
   	$iskontotutar2x=(float)$iskgncl2['TOTAL']-(float)$iskgncl2['DISTDISC'];
      $miktar = (float)($iskgncl2['AMOUNT'] ?? 0);
      $hesap = hesaplaIskontoluSatir($iskontotutar2x, $miktar, $isk2);
   	  $iskontotutar2=$hesap['iskonto'];
    $iskontolu2=$hesap['net'];
    $yenikdv2=$iskgncl2['VAT'];
     $topisk2=round($iskontotutar2+(float)$iskgncl2['DISTDISC'], 2);
   	 $stoktoplamkdvlp2=round((($iskontolu2/100)*$yenikdv2), 2);
      if ($stoktoplamkdvlp2 < 0) { $stoktoplamkdvlp2 = 0; }
   	 $topisk2x+=$iskontotutar2;
   	$stmtIskSatirGuncelle2->execute([':vatamnt' => $stoktoplamkdvlp2, ':distcost' => $topisk2, ':distdisc' => $topisk2, ':vatmatrah' => $iskontolu2, ':linenet' => $iskontolu2, ':logicalref' => (int) $idnohi2]);
   
   
   	 }
      $stmtIskontoSatirTutar = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET TOTAL = :total WHERE ORDFICHEREF = :stokhareket AND LINETYPE=2 AND LINENO_ = :lineno");
      $stmtIskontoSatirTutar->execute([':total' => $topisk2x, ':stokhareket' => $stokhareket, ':lineno' => $sonsirano]);
      // İskonto logla (2. İskonto)
      $stmtFisInfo = $dbh->prepare("SELECT FICHENO FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket");
      $stmtFisInfo->execute([':stokhareket' => $stokhareket]);
      $fisInfo = $stmtFisInfo->fetch(PDO::FETCH_ASSOC);
      logIskontoDegisiklik(
    			$stokhareket,                      // FIS_REF
    			$fisInfo['FICHENO'],               // FICHENO
    		2,                                 // ISKONTO_SEVIYE (2. iskonto)
    		0,                                 // ESKI_ISKONTO
    		$isk2,                             // YENI_ISKONTO
    		0,                                 // ESKI_TUTAR
    		$topisk2x,                         // YENI_TUTAR
    		$terminalkullanici,                // KULLANICI_ID
    		0,                                 // ONAYLAYAN_ID
    		'2. İskonto uygulandı: %' . $isk2  // ACIKLAMA
    	);
      if ($isIskontoAjax) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => true, 'msg' => '2. Iskonto uygulandi']);
          exit;
      }
      echo  '<script>window.location="?stokhareket='.$stokhareket.'";</script>' ;
  } elseif (isset($_POST['isk1b'])) {
      //EĞER 1. İSKONTO VARSA ÇALIŞACAK
      // CSRF koruması
      if (!csrf_verify()) {
        http_response_code(403);
        die("Geçersiz güvenlik doğrulaması.");
      }
      $isk1b = isset($_POST['isk1b']) ? (float) virgul($_POST['isk1b']) : 0.0;
      $isk2bx = isset($_POST['isk2bx']) ? (float) virgul($_POST['isk2bx']) : 0.0;
      $iskidno = isset($_POST['iskidno']) ? (int) $_POST['iskidno'] : 0;
      $stokhareket = isset($_POST['stokhareket']) ? (int) $_POST['stokhareket'] : 0;
      $cari = isset($_POST['cari']) ? (int) $_POST['cari'] : 0;
      // Eski iskonto değerini al
      $stmtEskiIskonto = $dbh->prepare("SELECT DISCPER, TOTAL FROM ".$firmadonem."ORFLINE WHERE LOGICALREF = :ref");
      $stmtEskiIskonto->execute([':ref' => $iskidno]);
      $eskiIskonto = $stmtEskiIskonto->fetch(PDO::FETCH_ASSOC);
      $stmtIskGuncelle=$dbh->prepare("SELECT LOGICALREF,TOTAL,VAT,AMOUNT FROM ".$firmadonem."ORFLINE  WHERE ORDFICHEREF = :stokhareket AND LINETYPE=0");
      $stmtIskGuncelle->execute([':stokhareket' => $stokhareket]);
      $stmtIskSatirGuncelle = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET VATAMNT = :vatamnt, DISTCOST = :distcost, DISTDISC = :distdisc, VATMATRAH = :vatmatrah, LINENET = :linenet WHERE LOGICALREF = :logicalref");
      $iskonbir = 0.0;
      WHILE($iskgncl=$stmtIskGuncelle->fetch(PDO::FETCH_ASSOC))
     { 
    	$idnohi=$iskgncl['LOGICALREF'];
    
    //1.iskonr-tı
    $iskontotutarx=(float)$iskgncl['TOTAL'];
      $miktar = (float)($iskgncl['AMOUNT'] ?? 0);
      $hesap1 = hesaplaIskontoluSatir($iskontotutarx, $miktar, $isk1b);
      $iskontotutar1=$hesap1['iskonto'];
     $iskontolu1=$hesap1['net'];
     $iskontolu1 = max(0.0, $iskontolu1);
     //2.iskonto
      $hesap2 = hesaplaIskontoluSatir($iskontolu1, $miktar, $isk2bx);
      $iskontotutar2=$hesap2['iskonto'];
      $iskontolu2=$hesap2['net'];
    
    	 $yenikdv=$iskgncl['VAT'];
    	 $stoktoplamkdvlp=round((($iskontolu2/100)*$yenikdv), 2);
       if ($stoktoplamkdvlp < 0) { $stoktoplamkdvlp = 0; }
    	 $topisk=round($iskontotutar1+$iskontotutar2, 2);
       $iskonbir += $iskontotutar1;
    	$stmtIskSatirGuncelle->execute([':vatamnt' => $stoktoplamkdvlp, ':distcost' => $topisk, ':distdisc' => $topisk, ':vatmatrah' => $iskontolu2, ':linenet' => $iskontolu2, ':logicalref' => (int) $idnohi]);
    	 }
      $iskonbir = round($iskonbir, 2);
      $stmtIskontoGuncelle = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET DISCPER = :discper, TOTAL = :total WHERE LOGICALREF = :ref");
      $stmtIskontoGuncelle->execute([':discper' => $isk1b, ':total' => $iskonbir, ':ref' => $iskidno]);
      // İskonto logla (1. İskonto güncelleme)
      $stmtFisInfo = $dbh->prepare("SELECT FICHENO FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket");
      $stmtFisInfo->execute([':stokhareket' => $stokhareket]);
      $fisInfo = $stmtFisInfo->fetch(PDO::FETCH_ASSOC);
      if ($eskiIskonto && ($eskiIskonto['DISCPER'] != $isk1b)) {
     		logIskontoDegisiklik(
     			$stokhareket,                                                                  // FIS_REF
     			$fisInfo['FICHENO'],                                                           // FICHENO
     			1,                                                                             // ISKONTO_SEVIYE
     			$eskiIskonto['DISCPER'],                                                       // ESKI_ISKONTO
     			$isk1b,                                                                        // YENI_ISKONTO
     			$eskiIskonto['TOTAL'],                                                         // ESKI_TUTAR
     			$iskonbir,                                                                     // YENI_TUTAR
     			$terminalkullanici,                                                            // KULLANICI_ID
     			0,                                                                             // ONAYLAYAN_ID
     			'1. İskonto güncellendi: %' . $eskiIskonto['DISCPER'] . ' -> %' . $isk1b     // ACIKLAMA
     		);
     	}
      if ($isIskontoAjax) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => true, 'msg' => '1. Iskonto guncellendi']);
          exit;
      }
  } elseif (ISSET($_POST['isk2b'])) {
      //EĞER 2. İSKONTO VARSA ÇALIŞACAK
      // CSRF koruması
      if (!csrf_verify()) {
        http_response_code(403);
        die("Geçersiz güvenlik doğrulaması.");
      }
      $isk2b = isset($_POST['isk2b']) ? (float) virgul($_POST['isk2b']) : 0.0;
      $isk1b = isset($_POST['isk1bx']) ? (float) virgul($_POST['isk1bx']) : 0.0;
      $iskidno2 = isset($_POST['iskidno2']) ? (int) $_POST['iskidno2'] : 0;
      $stokhareket = isset($_POST['stokhareket']) ? (int) $_POST['stokhareket'] : 0;
      $cari = isset($_POST['cari']) ? (int) $_POST['cari'] : 0;
      // Eski iskonto değerini al
      $stmtEskiIskonto2 = $dbh->prepare("SELECT DISCPER, TOTAL FROM ".$firmadonem."ORFLINE WHERE LOGICALREF = :ref");
      $stmtEskiIskonto2->execute([':ref' => $iskidno2]);
      $eskiIskonto2 = $stmtEskiIskonto2->fetch(PDO::FETCH_ASSOC);
      $stmtIskGuncelle=$dbh->prepare("SELECT LOGICALREF,TOTAL,VAT,AMOUNT FROM ".$firmadonem."ORFLINE  WHERE ORDFICHEREF = :stokhareket AND LINETYPE=0");
      $stmtIskGuncelle->execute([':stokhareket' => $stokhareket]);
      $stmtIskSatirGuncelle = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET VATAMNT = :vatamnt, DISTCOST = :distcost, DISTDISC = :distdisc, VATMATRAH = :vatmatrah, LINENET = :linenet WHERE LOGICALREF = :logicalref");
      $iskoniki = 0.0;
      WHILE($iskgncl=$stmtIskGuncelle->fetch(PDO::FETCH_ASSOC))
     { 
    	$idnohi=$iskgncl['LOGICALREF'];
    
    //1.iskonr-tı
    $iskontotutarx=(float)$iskgncl['TOTAL'];
      $miktar = (float)($iskgncl['AMOUNT'] ?? 0);
      $hesap1 = hesaplaIskontoluSatir($iskontotutarx, $miktar, $isk1b);
      $iskontotutar1=$hesap1['iskonto'];
      $iskontolu1=$hesap1['net'];
      $iskontolu1 = max(0.0, $iskontolu1);
     //2.iskonto
      $hesap2 = hesaplaIskontoluSatir($iskontolu1, $miktar, $isk2b);
      $iskontotutar2=$hesap2['iskonto'];
      $iskontolu2=$hesap2['net'];
    
    	 $yenikdv=$iskgncl['VAT'];
    	 $stoktoplamkdvlp=round((($iskontolu2/100)*$yenikdv), 2);
       if ($stoktoplamkdvlp < 0) { $stoktoplamkdvlp = 0; }
    	 $topisk=round($iskontotutar1+$iskontotutar2, 2);
       $iskoniki += $iskontotutar2;
    	$stmtIskSatirGuncelle->execute([':vatamnt' => $stoktoplamkdvlp, ':distcost' => $topisk, ':distdisc' => $topisk, ':vatmatrah' => $iskontolu2, ':linenet' => $iskontolu2, ':logicalref' => (int) $idnohi]);
    	 }
      $iskoniki = round($iskoniki, 2);
      $stmtIskontoGuncelle = $dbh->prepare("UPDATE ".$firmadonem."ORFLINE SET DISCPER = :discper, TOTAL = :total WHERE LOGICALREF = :ref");
      $stmtIskontoGuncelle->execute([':discper' => $isk2b, ':total' => $iskoniki, ':ref' => $iskidno2]);
      // İskonto logla (2. İskonto güncelleme)
      $stmtFisInfo = $dbh->prepare("SELECT FICHENO FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket");
      $stmtFisInfo->execute([':stokhareket' => $stokhareket]);
      $fisInfo = $stmtFisInfo->fetch(PDO::FETCH_ASSOC);
      if ($eskiIskonto2 && ($eskiIskonto2['DISCPER'] != $isk2b)) {
     		logIskontoDegisiklik(
     			$stokhareket,                                                                  // FIS_REF
     			$fisInfo['FICHENO'],                                                           // FICHENO
     			2,                                                                             // ISKONTO_SEVIYE
     			$eskiIskonto2['DISCPER'],                                                      // ESKI_ISKONTO
     			$isk2b,                                                                        // YENI_ISKONTO
     			$eskiIskonto2['TOTAL'],                                                        // ESKI_TUTAR
     			$iskoniki,                                                                     // YENI_TUTAR
     			$terminalkullanici,                                                            // KULLANICI_ID
     			0,                                                                             // ONAYLAYAN_ID
     			'2. İskonto güncellendi: %' . $eskiIskonto2['DISCPER'] . ' -> %' . $isk2b    // ACIKLAMA
     		);
     	}
      if ($isIskontoAjax) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => true, 'msg' => '2. Iskonto guncellendi']);
          exit;
      }
  }

<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");  // DB bağlantısı ve loglama fonksiyonları için GEREKLİ!
require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/iskonto_lib.php"); // yeni urunde mevcut iskontoyu otomatik uygulamak icin

if (!function_exists('hareketekle_trace_log')) {
  function hareketekle_trace_log(string $event, array $context = []): void
  {
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
      @mkdir($logDir, 0775, true);
    }

    $payload = [
      'ts' => date('Y-m-d H:i:s'),
      'event' => $event,
      'session_id' => session_id(),
      'request_time' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
      'method' => $_SERVER['REQUEST_METHOD'] ?? '',
      'uri' => $_SERVER['REQUEST_URI'] ?? '',
      'context' => $context,
    ];

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
      $encoded = json_encode([
        'ts' => date('Y-m-d H:i:s'),
        'event' => $event,
        'json_error' => json_last_error_msg(),
      ]);
    }

    @file_put_contents($logDir . '/hareketekle_trace.log', (string)$encoded . PHP_EOL, FILE_APPEND | LOCK_EX);
  }
}

hareketekle_trace_log('REQUEST_START', [
  'post' => $_POST,
  'get' => $_GET,
]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  hareketekle_trace_log('METHOD_REJECTED', ['method' => $_SERVER['REQUEST_METHOD'] ?? '']);
  http_response_code(405);
  header('Allow: POST');
  exit;
}

include_once(__DIR__ . "/../log_ip.php");
if (isset($_POST['stkid'])) {
  // CSRF koruması (POST istekleri için)
  $csrfOk = csrf_verify();
  hareketekle_trace_log('CSRF_VERIFY', [
    'ok' => $csrfOk,
    'posted_token_length' => strlen((string)($_POST['csrf_token'] ?? '')),
  ]);
  if (!$csrfOk) {
    http_response_code(403);
    die("Geçersiz güvenlik doğrulaması.");
  }

  $kayit = true;
  $siparisdurum = 1;



  $stokmiktar = 1;
  $stokfiyat = 0;
  $stokkdv = 0;

  $stokbirimmiktar = 1;
  $stokaciklama = "";

  if (isset($_POST['stkid'])) {
      $stokid = intcevir($_POST['stkid']);
      $stokmiktar = str_replace(",", ".", $_POST['stkadt']);
      $stokfiyat = max(0.0, (float)str_replace(",", ".", $_POST['stkfyt']));
      $stokkdv = max(0.0, min(100.0, (float)$_POST['stkkdv']));
      //   $cariid = $_POST['cariid'];
      $kontrol = $_POST['kontrol'];
      $stokbirimmiktar = $_POST['stbrm'] ?? 1;
      $stokaciklama = isset($_POST['aciklama']) ? (string)$_POST['aciklama'] : '';
      // Tekli stok ekleme formu fis bilgisini action query string ile tasiyor.
      $stokhareketKaynak = 'yok';
      if (isset($_POST['stokhareket'])) {
        $stokhareket = (int)$_POST['stokhareket'];
        $stokhareketKaynak = 'post.stokhareket';
      } elseif (isset($_POST['fisid'])) {
        $stokhareket = (int)$_POST['fisid'];
        $stokhareketKaynak = 'post.fisid';
      } elseif (isset($_GET['stokhareket'])) {
        $stokhareket = (int)$_GET['stokhareket'];
        $stokhareketKaynak = 'get.stokhareket';
      } elseif (isset($_GET['fisid'])) {
        $stokhareket = (int)$_GET['fisid'];
        $stokhareketKaynak = 'get.fisid';
      } else {
        $stokhareket = 0;
      }
  }

  if ($stokhareket <= 0) {
    hareketekle_trace_log('VALIDATION_FAILED', [
      'reason' => 'stokhareket <= 0',
      'stokhareket' => $stokhareket,
      'stokhareket_kaynak' => $stokhareketKaynak ?? 'yok',
      'post_keys' => array_keys($_POST),
      'get' => $_GET,
    ]);
    http_response_code(400);
    exit('Gecersiz fis bilgisi.');
  }

  if (!m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
    hareketekle_trace_log('AUTH_REJECTED', [
      'stokhareket' => $stokhareket,
      'terminalkullanici' => $terminalkullanici ?? null,
    ]);
    header('Location: 403.html');
    exit;
  }

  hareketekle_trace_log('POST_PARSED', [
    'stokid' => $stokid ?? null,
    'stokhareket' => $stokhareket ?? null,
    'stokhareket_kaynak' => $stokhareketKaynak ?? null,
    'stokmiktar_raw' => $stokmiktar,
    'stokfiyat' => $stokfiyat,
    'stokkdv' => $stokkdv,
    'stokbirimmiktar' => $stokbirimmiktar,
    'stokaciklama' => $stokaciklama,
  ]);

  // Resmi modulde KDV zorunlu ve sabit (%20).
  if (defined('RESMI_FORCE_KDV')) {
    $stokkdv = (float) RESMI_FORCE_KDV;
  }

  /************maliyet kontrol****************//*
  $son_alis = "";
  if (isset($maliyetkontrol) && $maliyetkontrol == 1) {
    $stmt = $dbh->prepare("SELECT TOP 1 S.PRICE, I.NAME
                           FROM " . $firmadonem . "STLINE S
                           LEFT JOIN " . $firma . "ITEMS I ON S.STOCKREF = I.LOGICALREF
                           WHERE S.STOCKREF = :stokid
                           AND S.LINETYPE = 0
                           AND S.CANCELLED = 0
                           AND S.TRCODE IN (1,14)
                           ORDER BY S.DATE_ DESC");
    $stmt->bindParam(':stokid', $stokid, PDO::PARAM_INT);
    $stmt->execute();
    $stokalisX = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $son_alis = $stokalisX['PRICE'] ?? 0;



    if ($son_alis > $stokfiyat) {
      echo '<script type="text/javascript"> ';

      echo '  if (alert("' . $stokalisX["NAME"] . ' alis fiyat satış fiyatından fazla lütfen kontrol ediniz")) {';
      $kayit = false;

      echo '}';

      echo '</script>';

    }
  }*/

  /******************maliyet kontol bitiş*****************************/

  $stokmiktar *= $stokbirimmiktar;
  $stoktoplam = round($stokmiktar * $stokfiyat, 2);
  $stoktoplamkdv = round((($stoktoplam / 100) * $stokkdv), 2);
  $tarih = date("Y-m-d");
  $guid = guid();

  // ORFICHE'den müşteri ve döviz bilgilerini al
  $stmt = $dbh->prepare("SELECT CLIENTREF, TRCURR, TRRATE FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF = :stokhareket");
  $stmt->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
  $stmt->execute();
  $listcari = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
  $cariid = intcevir($listcari['CLIENTREF'] ?? 0);

  // Döviz bilgilerini al (ORFLINE'a yazılacak)
  $trcurr = isset($listcari['TRCURR']) ? (int)$listcari['TRCURR'] : 0;
  $trrate = isset($listcari['TRRATE']) && is_numeric($listcari['TRRATE']) ? (float)$listcari['TRRATE'] : 0;
  //if($sorgu=$dbh -> query ("SELECT LOGICALREF FROM ".$firmadonem."ORFLINE WHERE  DATE_=GETDATE() AND LOGICALREF='$stokhareket' ")->fetch(PDO::FETCH_ASSOC)) {$kayit=false;}
  //HAREKET ÜRÜN SAYI NOSU
  $stmt = $dbh->prepare("SELECT MAX(LINENO_) AS SATIR FROM " . $firmadonem . "ORFLINE WHERE ORDFICHEREF = :stokhareket AND LINETYPE = 0");
  $stmt->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
  $stmt->execute();
  $sirano = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

	  $sonsirano = intcevir($sirano['SATIR'] ?? 0) + 1;
   $birimler = birim_bul($stokid);
   $uomref = $birimler[0];
   $stokbirim = $birimler[1];
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

   // DÜZELTME: Miktar 0 veya negatif olamaz (LOGO trigger sıfıra bölme hatası verir)
   if ($stokmiktar <= 0) {
       hareketekle_trace_log('VALIDATION_FAILED', [
         'reason' => 'stokmiktar <= 0',
         'stokmiktar' => $stokmiktar,
         'stokhareket' => $stokhareket,
         'stokid' => $stokid,
       ]);
       echo '<script>if (window.toast) { toast("Miktar 0 veya negatif olamaz!", "error"); } else { alert("Miktar 0 veya negatif olamaz!"); } window.history.back();</script>';
       exit;
   }

   // Fiyat boş veya 0 olamaz
   if ($stokfiyat === '' || $stokfiyat === null || (float)$stokfiyat <= 0) {
       hareketekle_trace_log('VALIDATION_FAILED', [
         'reason' => 'stokfiyat <= 0',
         'stokfiyat' => $stokfiyat,
         'stokhareket' => $stokhareket,
         'stokid' => $stokid,
       ]);
       echo '<script>if (window.toast) { toast("Lütfen fiyat giriniz!", "error"); } else { alert("Lütfen fiyat giriniz!"); } window.history.back();</script>';
       exit;
   }

   $stmt = $dbh->prepare("INSERT INTO " . $firmadonem . "ORFLINE  (

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

:stokid, :stokhareket, :cariid, 0, 0, 0, 0, :sonsirano, :siparisdurum, DATEADD(day,0,datediff(day,0,GETDATE())), :kayitsaat, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, '', '', :stokmiktar, :stokfiyat, :stoktoplam, 0, 0, 0, 0, 0, 0, :stokkdv, :stoktoplamkdv, :stoktoplam2, :stokaciklama, :uomref, :stokbirim, 1, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, DATEADD(day,0,datediff(day,0,GETDATE())), 0, 0, 1, 0, 0, 0, 0, :depo, 0, 0, 0, :stoktoplam3, :terminalkullanici, 4, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, '', 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, :trcurr, :trrate, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, DATEADD(day,0,datediff(day,0,GETDATE())), :stokmiktar2, :stokfiyat2, '', 0, 0, '', 0, 0, 0, :guid, :reservex, :reservetarih, :reservemiktar

)");
   $stmt->bindParam(':stokid', $stokid, PDO::PARAM_INT);
   $stmt->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
   $stmt->bindParam(':cariid', $cariid, PDO::PARAM_INT);
   $stmt->bindParam(':sonsirano', $sonsirano, PDO::PARAM_INT);
   $stmt->bindParam(':siparisdurum', $siparisdurum, PDO::PARAM_INT);
   $stmt->bindParam(':kayitsaat', $kayitsaat, PDO::PARAM_INT);
   $stmt->bindParam(':stokmiktar', $stokmiktar, PDO::PARAM_STR);
   $stmt->bindParam(':stokfiyat', $stokfiyat, PDO::PARAM_STR);
   $stmt->bindParam(':stoktoplam', $stoktoplam, PDO::PARAM_STR);
   $stmt->bindParam(':stokkdv', $stokkdv, PDO::PARAM_STR);
   $stmt->bindParam(':stoktoplamkdv', $stoktoplamkdv, PDO::PARAM_STR);
   $stmt->bindParam(':stoktoplam2', $stoktoplam, PDO::PARAM_STR);
   $stmt->bindParam(':stokaciklama', $stokaciklama, PDO::PARAM_STR);
   $stmt->bindParam(':uomref', $uomref, PDO::PARAM_INT);
   $stmt->bindParam(':stokbirim', $stokbirim, PDO::PARAM_INT);
   $stmt->bindParam(':depo', $depo, PDO::PARAM_INT);
   $stmt->bindParam(':stoktoplam3', $stoktoplam, PDO::PARAM_STR);
   $stmt->bindParam(':terminalkullanici', $terminalkullanici, PDO::PARAM_INT);
   $stmt->bindParam(':stokmiktar2', $stokmiktar, PDO::PARAM_STR);
   $stmt->bindParam(':stokfiyat2', $stokfiyat, PDO::PARAM_STR);
   $stmt->bindParam(':guid', $guid, PDO::PARAM_STR);
   $stmt->bindParam(':reservex', $reservex, PDO::PARAM_INT);
   $stmt->bindParam(':reservetarih', $reservetarih, PDO::PARAM_STR);
   $stmt->bindParam(':reservemiktar', $reservemiktar, PDO::PARAM_STR);
   $stmt->bindParam(':trcurr', $trcurr, PDO::PARAM_INT);
   $stmt->bindParam(':trrate', $trrate, PDO::PARAM_STR);
   $insertTraceBinds = [
     'stokid' => $stokid,
     'stokhareket' => $stokhareket,
     'cariid' => $cariid,
     'sonsirano' => $sonsirano,
     'siparisdurum' => $siparisdurum,
     'kayitsaat' => $kayitsaat ?? null,
     'stokmiktar' => $stokmiktar,
     'stokfiyat' => $stokfiyat,
     'stoktoplam' => $stoktoplam,
     'stokkdv' => $stokkdv,
     'stoktoplamkdv' => $stoktoplamkdv,
     'stokaciklama' => $stokaciklama,
     'uomref' => $uomref,
     'stokbirim' => $stokbirim,
     'depo' => $depo ?? null,
     'terminalkullanici' => $terminalkullanici ?? null,
     'guid' => $guid,
     'reservex' => $reservex,
     'reservetarih' => $reservetarih,
     'reservemiktar' => $reservemiktar,
     'trcurr' => $trcurr,
     'trrate' => $trrate,
   ];
   hareketekle_trace_log('INSERT_BEFORE_EXECUTE', $insertTraceBinds);
   try {
     $shekle = $stmt->execute();
     $lastInsertId = null;
     try {
       $lastInsertId = $dbh->lastInsertId();
     } catch (Throwable $lastInsertError) {
       $lastInsertId = 'lastInsertId okunamadi: ' . $lastInsertError->getMessage();
     }
     hareketekle_trace_log('INSERT_AFTER_EXECUTE', [
       'execute_result' => $shekle,
       'row_count' => $stmt->rowCount(),
       'last_insert_id' => $lastInsertId,
       'binds' => $insertTraceBinds,
     ]);
   } catch (Throwable $e) {
     // INSERT hatasini detayli logla — silent rollback yerine sebebi gor
     $errInfo = method_exists($stmt, 'errorInfo') ? $stmt->errorInfo() : [];
     hareketekle_trace_log('INSERT_EXCEPTION', [
       'message' => $e->getMessage(),
       'class' => get_class($e),
       'file' => $e->getFile(),
       'line' => $e->getLine(),
       'trace' => $e->getTraceAsString(),
       'error_info' => $errInfo,
       'binds' => $insertTraceBinds,
     ]);
     error_log('hareketekle.php INSERT hatasi: ' . $e->getMessage()
       . ' | errorInfo=' . json_encode($errInfo)
       . ' | binds: stokid=' . $stokid . ', stokhareket=' . $stokhareket
       . ', cariid=' . $cariid . ', sonsirano=' . $sonsirano
       . ', stokmiktar=' . $stokmiktar . ', stokfiyat=' . $stokfiyat
       . ', uomref=' . $uomref . ', stokbirim=' . $stokbirim
       . ', trcurr=' . $trcurr . ', trrate=' . $trrate);
     if (function_exists('app_log_exception')) {
       try { app_log_exception($e, 'hareketekle.insert', ['error_info' => $errInfo, 'binds' => $insertTraceBinds]); } catch (Throwable $x) {}
     }
     echo '<script>if (window.toast) { toast("Satir eklenemedi: ' . addslashes($e->getMessage()) . '", "error"); } else { alert("Satir eklenemedi: ' . addslashes($e->getMessage()) . '"); } window.history.back();</script>';
     exit;
   }
   // ========================================
   // Eklenen satırın LOGICALREF'ini al ve logla
   // ========================================
   $stmt = $dbh->prepare("SELECT TOP 1 L.LOGICALREF, F.FICHENO, I.CODE, I.NAME
                           FROM " . $firmadonem . "ORFLINE L
                           LEFT JOIN " . $firma . "ITEMS I ON I.LOGICALREF = L.STOCKREF
                           LEFT JOIN " . $firmadonem . "ORFICHE F ON F.LOGICALREF = L.ORDFICHEREF
                           WHERE L.ORDFICHEREF = :stokhareket
                           AND L.LINETYPE = 0
                           ORDER BY L.LOGICALREF DESC");
   $stmt->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
   $stmt->execute();
   $yeniSatir = $stmt->fetch(PDO::FETCH_ASSOC);
   hareketekle_trace_log('SELECT_NEW_LINE_RESULT', [
     'stokhareket' => $stokhareket,
     'result' => $yeniSatir ?: null,
   ]);
   if ($yeniSatir) {
     // ORFICHE/ITEMS LEFT JOIN'leri NULL donebilir; string parametreleri guvene al
     $ficheNo  = (string) ($yeniSatir['FICHENO'] ?? '');
     $stokKodu = (string) ($yeniSatir['CODE']    ?? '');
     $stokAdi  = (string) ($yeniSatir['NAME']    ?? '');

     // Log kaydı oluştur — log fail olsa da akisi durdurma
     try {
       $logBasarili = logSatirEkleme(
         $stokhareket,
         intcevir($yeniSatir['LOGICALREF']),
         $ficheNo, $stokKodu, $stokAdi,
         $stokmiktar, $stokfiyat, $stoktoplam,
         $terminalkullanici, $stokaciklama
       );
       if (!$logBasarili) {
         error_log("UYARI: Satir ekleme logu kaydedilemedi! Fis: {$ficheNo}, Stok: {$stokKodu}");
       }
     } catch (Throwable $e) {
       error_log('hareketekle.php logSatirEkleme hatasi: ' . $e->getMessage());
     }
   } else {
     error_log("HATA: Eklenen satir bilgisi alinamadi! Fis REF: {$stokhareket}");
   }
   // ========================================
   // ISKONTO satirlari LINENO_ kaydirma
   // SELECT cursor acikken UPDATE etmek SQL Server PDO MARS kapaliysa
   // "Connection is busy" patlatabilir — once fetchAll, sonra update.
   try {
     $stmtSelect = $dbh->prepare("SELECT LOGICALREF FROM " . $firmadonem . "ORFLINE WHERE ORDFICHEREF = :stokhareket AND LINETYPE = 2 ORDER BY LINENO_ ASC");
     $stmtSelect->bindParam(':stokhareket', $stokhareket, PDO::PARAM_INT);
     $stmtSelect->execute();
     $iskontoSatirlari = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);
     $stmtSelect->closeCursor();

     if (!empty($iskontoSatirlari)) {
       $stmtUpdate = $dbh->prepare("UPDATE " . $firmadonem . "ORFLINE SET LINENO_ = :siranoiskX WHERE LOGICALREF = :siraid1");
       foreach ($iskontoSatirlari as $row) {
         $siraid1 = intcevir($row['LOGICALREF']);
         $siranoiskX = $sonsirano + 1;
         $stmtUpdate->execute([':siranoiskX' => $siranoiskX, ':siraid1' => $siraid1]);
         $sonsirano = $siranoiskX;
       }
       // Yeni urun dahil tum satirlara mevcut iskontoyu (1. ve 2.) otomatik uygula.
       // iskonto.php ile ayni hesap; mevcut satirlarin sonucu degismez, yeni urun de kapsanir.
       // Hata olursa urun eklenmis kalir, kullanici iskontoyu elle uygulayabilir.
       try {
         fis_iskonto_oranlari_uygula($dbh, $firmadonem, (int) $stokhareket);
       } catch (Throwable $e) {
         error_log('hareketekle.php otomatik iskonto hatasi: ' . $e->getMessage());
         hareketekle_trace_log('OTO_ISKONTO_EXCEPTION', [
           'message' => $e->getMessage(),
           'stokhareket' => (int) $stokhareket,
         ]);
       }
     }
   } catch (Throwable $e) {
     error_log('hareketekle.php iskonto LINENO_ update hatasi: ' . $e->getMessage());
     hareketekle_trace_log('ISKONTO_LINENO_EXCEPTION', [
       'message' => $e->getMessage(),
       'trace' => $e->getTraceAsString(),
       'stokhareket' => $stokhareket,
     ]);
     // Iskonto satir kaydirmasi opsiyonel — INSERT'i geri alma, devam et
   }
   hareketekle_trace_log('REDIRECT', [
     'message' => 'REDIRECT to lg_fis with stokhareket=' . (int)$stokhareket,
     'location' => 'lg_fis.php?stokhareket=' . (int)$stokhareket,
   ]);
   header('Location: lg_fis.php?stokhareket=' . (int)$stokhareket);
   exit;
}

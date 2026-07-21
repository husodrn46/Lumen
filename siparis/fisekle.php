<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// YETKI: fisekle hem Yeni Sipariş (M1) hem Mağaza Satış (M3) akışının ortak
// yazma kapısı — menüde buton gizlemek yetki değildir; endpoint'te doğrula.
// (2026-07-13: eskiden yalnız cari-görünürlük kontrolü vardı, menü yetkisi yoktu.)
if (
    (int) ($yetkidurum ?? 1) !== 0
    && m_p_yetki($terminalkullanici, 'M1') != 1
    && m_p_yetki($terminalkullanici, 'M3') != 1
) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Yeni sipariş açılırken session'daki eski stokhareket değerini temizle
// Eğer URL'de stokhareket yoksa veya 0 ise bu yeni bir sipariştir
$urlStokhareket = isset($_GET['stokhareket']) ? (int)$_GET['stokhareket'] : 0;
if ($urlStokhareket <= 0) {
    unset($_SESSION['page_params']['fisekle']['stokhareket']);
    unset($_SESSION['page_params']['lg_fis']['stokhareket']);
    unset($_SESSION['lg_fis_stokhareket']);
}

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['cariid', 'fiyat', 'stokhareket']);

// Bu sayfa sadece TL siparişleri oluşturur
// Dövizli siparişler için: doviz/fisekle.php kullanılır
$doviz = 0;      // TL
$dovizkuru = 0;  // Kur yok
$status = 4;     // 1 öneri 2SEVKEDİLEMEZ 4 SEVKEDİLEBİLİR

// Validasyon: cariid zorunlu (session bazlı)
$cariid = getPageParamInt('cariid');
if ($cariid <= 0) {
	die('<script>if (window.toast) { toast("Hata: Müşteri seçilmedi!", "error"); } else { alert("Hata: Müşteri seçilmedi!"); } window.location="index.php";</script>');
}
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $cariid)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Session'ı temizle (TL modu)
$_SESSION['doviz'] = 0;
$fiyatParam = getPageParamInt('fiyat');
if ($fiyatgruplu == 1 && $fiyatParam > 0) {
	$_SESSION['fiyatgrup'] = $fiyatParam;
}

// Mevcut fiş güncelleme - sadece yönlendirme yap
$stokhareket = getPageParamInt('stokhareket');
if ($stokhareket > 0) {
    if (!m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
        header('Location: ' . APP_ROOT_URL . '/403.html');
        exit;
    }
	// lg_fis.php'ye yönlendir (TL/Döviz kontrolü orada yapılacak)
	echo '<script>window.location="lg_fis.php?stokhareket=' . $stokhareket . '";</script>';
	exit();
}

// ── M3 (Mağaza Satış) YENİ SİPARİŞ cari kısıtı — Issue #3 (CWE-863) ──
// M3 yalnızca mağaza satış carisi (LOGICALREF=2, CODE=SATIS) için yeni sipariş
// açabilir; M1 (Yeni Sipariş) ve admin herhangi GÖRÜNÜR cari için açabilir.
// Buraya konur çünkü: (a) stokhareket>0 düzenleme-yönlendirmesinden SONRA — yalnız
// YENİ başlık oluşturma kısıtlanır, mevcut sipariş düzenleme etkilenmez; (b) aşağıdaki
// ORFICHE INSERT'inden ÖNCE — ret, kayıt yazılmadan gerçekleşir. $cariid hem URL hem
// session değerini tek kaynaktan (getPageParamInt) okur; URL-direkt ve session'da kalmış
// cariid vektörlerinin ikisini de kapatır. KOŞUL üstteki M1/M3/admin kapısını birebir
// yansıtır: admin (yetkidurum===0) ve M1 sahipleri MUAFTIR — aksi halde canlıdaki tüm
// kullanıcılar (hepsi M3=1) gerçek-müşteri siparişlerinde kırılır.
$magazaSatisCari = 2; // CODE=SATIS / DEFINITION_=MAGAZA SATIS (index.php M3 menüsüyle aynı sabit)
if (
    (int) ($yetkidurum ?? 1) !== 0
    && m_p_yetki($terminalkullanici, 'M1') != 1
    && m_p_yetki($terminalkullanici, 'M3') == 1
    && $cariid !== $magazaSatisCari
) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// NOT: Bos fis YENIDEN KULLANIMI (reuse) KALDIRILDI (kullanici tercihi, 2026-06).
// Onceden, acik kalmis bos bir taslak fis varsa yeni fis acmak yerine o eski slot
// yeniden kullaniliyordu; bu, fisin ESKI numarayi (LOGICALREF/FICHENO) almasina ve
// LOGO listesinde geride gorunmesine yol aciyordu. Artik her yeni fis asagidaki
// MAX(LOGICALREF)+1 ile GUNCEL/SIRALI numara alir. Acilip vazgecilen bos fisler
// sistemde kalabilir; gerekirse ayri bir temizlik islemiyle silinebilir.

//SAKLI SİPARİŞ İD AL
	$stmtSonId = $dbh->prepare("SELECT MAX(LOGICALREF) AS SONID FROM " . $firmadonem . "ORFICHE");
	$stmtSonId->execute();
	$sip = $stmtSonId->fetch(PDO::FETCH_ASSOC);
	// 1 EKLE id oluşssun
	$sonsiparisid = intcevir($sip['SONID']) + 1;
	$siparisdurum = 1;//satış
// $kullanici=2;
	$kdvtoplam = 0;
	$toplam = 0;
	$gtoplam = 0;
	$fisaciklama = '';
	$fisnox = str_pad((string)$sonsiparisid, 6, '0', STR_PAD_LEFT);//rakamı 6 ahaneli ve kalanı sıfır y
	$fisno = $sipno . $fisnox;
	$guid = guid();

	$stmtEkle = $dbh->prepare("INSERT INTO " . $firmadonem . "ORFICHE  ( 
TRCODE,FICHENO,DATE_,TIME_,DOCODE,SPECODE,CYPHCODE,CLIENTREF,RECVREF,ACCOUNTREF,CENTERREF,SOURCEINDEX,SOURCECOSTGRP,UPDCURR,ADDDISCOUNTS,TOTALDISCOUNTS,TOTALDISCOUNTED,ADDEXPENSES,TOTALEXPENSES,TOTALPROMOTIONS,TOTALVAT,GROSSTOTAL,NETTOTAL,REPORTRATE,REPORTNET,GENEXP1,GENEXP2,GENEXP3,GENEXP4,EXTENREF,PAYDEFREF,PRINTCNT,BRANCH,DEPARTMENT,STATUS,CAPIBLOCK_CREATEDBY,CAPIBLOCK_CREADEDDATE,CAPIBLOCK_CREATEDHOUR,CAPIBLOCK_CREATEDMIN,CAPIBLOCK_CREATEDSEC,CAPIBLOCK_MODIFIEDBY,CAPIBLOCK_MODIFIEDDATE,CAPIBLOCK_MODIFIEDHOUR,CAPIBLOCK_MODIFIEDMIN,CAPIBLOCK_MODIFIEDSEC,SALESMANREF,SHPTYPCOD,SHPAGNCOD,GENEXCTYP,LINEEXCTYP,TRADINGGRP,TEXTINC,SITEID,RECSTATUS,ORGLOGICREF,FACTORYNR,WFSTATUS,SHIPINFOREF,CUSTORDNO,SENDCNT,DLVCLIENT,DOCTRACKINGNR,CANCELLED,ORGLOGOID,OFFERREF,OFFALTREF,TYP,ALTNR,ADVANCEPAYM,TRCURR,TRRATE,TRNET,PAYMENTTYPE,ONLYONEPAYLINE,OPSTAT,WITHPAYTRANS,PROJECTREF,WFLOWCRDREF,UPDTRCURR,AFFECTCOLLATRL,POFFERBEGDT,POFFERENDDT,REVISNR,LASTREVISION,CHECKAMOUNT,SLSOPPRREF,SLSACTREF,SLSCUSTREF,AFFECTRISK,TOTALADDTAX,TOTALEXADDTAX,APPROVE,APPROVEDATE,CHECKPRICE,GUID,EINVOICE 
)VALUES (
:siparisdurum,:fisno,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,'','','',:cariid,0,0,0,:depo,0,0,0,0,0,0,0,0,0,0,0,1,0,'','','','',0,0,0,0,0,:status,1,DATEADD(day,0,datediff(day,0,GETDATE())),:saat,:dakika,:saniye,0,NULL,0,0,0,:terminalkullanici,'','',2,0,'',0,0,1,0,0,0,0,'',0,0,'',0,'',0,0,0,0,0,:doviz,:dovizkuru,0,0,0,0,0,0,0,0,0,NULL,NULL,'',0,0,0,0,0,1,0,0,0,NULL,0,:guid,0
)");
	$ekle = $stmtEkle->execute([':siparisdurum' => $siparisdurum, ':fisno' => $fisno, ':kayitsaat' => (int) $kayitsaat, ':cariid' => $cariid, ':depo' => (int) $depo, ':status' => $status, ':saat' => (int) $saat, ':dakika' => (int) $dakika, ':saniye' => (int) $saniye, ':terminalkullanici' => (int) $terminalkullanici, ':doviz' => (int) $doviz, ':dovizkuru' => (float) $dovizkuru, ':guid' => (string) $guid]);
	if ($ekle) {//echo "fiş açma işlem başarılı";
		$stmtSonId->execute();
		$sip = $stmtSonId->fetch(PDO::FETCH_ASSOC);
		$sonsiparisid = intcevir($sip['SONID']);

		// ========================================
		// Fiş oluşturma işlemini logla
		// ========================================
		$logOptions = ['doviz' => ($doviz === '0' || $doviz === '') ? 'TL' : $doviz, 'dovizKuru' => $dovizkuru, 'depo' => $depo, 'durum' => $status, 'aciklama' => "Yeni fiş oluşturuldu (Fiş No: {$fisno})"];

		$logBasarili = logFisOlusturma(
			$sonsiparisid,    // FIS_REF
			$fisno,           // FICHENO
			$cariid,          // CARI_REF
			$terminalkullanici,  // KULLANICI_ID
			$logOptions       // OPTIONS
		);

		// Log başarısız olduysa uyarı ver (geliştirme ortamı için)
		if (!$logBasarili) {
			error_log("UYARI: Fiş #{$fisno} oluşturma logu kaydedilemedi!");
		}
		// ========================================

		echo '<script>window.location="lg_fis.php?stokhareket=' . $sonsiparisid . '";</script>';

} else {
	error_log("Fiş açma hatası (sipariş no: $fisno): " . implode(", ", $dbh->errorInfo()));
	echo "Fiş açma işlemi başarısız. Lütfen tekrar deneyin veya sistem yöneticisine başvurun.";
	exit;
}

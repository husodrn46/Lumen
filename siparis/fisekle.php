<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");
require_once __DIR__ . "/kayit_lib.php";
require_once __DIR__ . "/web_intent.php";
header('Cache-Control: no-store');
web_siparis_kapsam_dogrula($firmano, $firma, $firmadonem);
if (function_exists('m_p_yetki_cache_temizle')) { m_p_yetki_cache_temizle((int)$terminalkullanici); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!is_string($_POST['csrf_token'] ?? null) || !csrf_verify())) { http_response_code(403); exit('Geçersiz güvenlik doğrulaması.'); }

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
$cariid = $_SERVER['REQUEST_METHOD'] === 'POST' ? web_siparis_tamsayi($_POST['web_cariid'] ?? null) : getPageParamInt('cariid');
if ($cariid <= 0) {
	die('<script>if (window.toast) { toast("Hata: Müşteri seçilmedi!", "error"); } else { alert("Hata: Müşteri seçilmedi!"); } window.location="../index.php";</script>');
}
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $cariid)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Session'ı temizle (TL modu)
$_SESSION['doviz'] = 0;
$fiyatParam = $_SERVER['REQUEST_METHOD'] === 'POST' ? web_siparis_tamsayi($_POST['web_fiyat'] ?? null) : getPageParamInt('fiyat');
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $fiyatgruplu == 1 && $fiyatParam > 0) {
	$_SESSION['fiyatgrup'] = $fiyatParam;
}

// Mevcut fiş güncelleme - sadece yönlendirme yap
$stokhareket = $_SERVER['REQUEST_METHOD'] === 'POST' ? 0 : getPageParamInt('stokhareket');
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

$scope = 'web_tl_baslik:v1';
$context = ['personel'=>(int)$terminalkullanici, 'firma'=>(int)$firmano, 'donem'=>$firmadonem, 'cari'=>$cariid, 'depo'=>(int)$depo];
try {
    $key = web_siparis_anahtari($_SESSION, $scope, $context,
        $_SERVER['REQUEST_METHOD'] === 'POST' ? web_siparis_post_anahtari($_POST) : null);
} catch (Throwable $e) { http_response_code(409); exit('Sipariş formu doğrulanamadı. Siparişleri kontrol edin.'); }
$fields = ['web_cariid'=>$cariid, 'web_fiyat'=>$fiyatParam];
$pending = $_SESSION['web_siparis_intents'][$key]['payload'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $pending !== null) { $fields = $pending['fields']; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { web_siparis_form($key, $fields); exit; }
// Boş fiş yeniden kullanılmaz. Başlık ve kesin numara tek transaction'dır.
try {
    $hash = web_siparis_dondur($_SESSION, $key, ['context'=>$context, 'fields'=>$fields]);
    if ($fiyatgruplu == 1 && $fiyatParam > 0) { $_SESSION['fiyatgrup'] = $fiyatParam; }
    $dbh->beginTransaction();
    $replay = siparis_idempotency_baslat($dbh, $key, (int)$firmano, $firmadonem, (int)$terminalkullanici, $hash, $scope);
    if ($replay !== null) {
        if (!$dbh->commit()) { throw new RuntimeException('Commit doğrulanamadı.'); }
        web_siparis_tamam($_SESSION, $key);
        header('Location: lg_fis.php?stokhareket=' . (int)$replay['fis']['id'], true, 303);
        exit;
    }
    $fisno = siparis_baslik_numarasi_ayir($dbh, $firmadonem, (string) $sipno);
	$siparisdurum = 1;//satış
// $kullanici=2;
	$kdvtoplam = 0;
	$toplam = 0;
	$gtoplam = 0;
	$fisaciklama = '';
	$guid = guid();

	$stmtEkle = siparis_baslik_insert_hazirla($dbh, "INSERT INTO " . $firmadonem . "ORFICHE  (
TRCODE,FICHENO,DATE_,TIME_,DOCODE,SPECODE,CYPHCODE,CLIENTREF,RECVREF,ACCOUNTREF,CENTERREF,SOURCEINDEX,SOURCECOSTGRP,UPDCURR,ADDDISCOUNTS,TOTALDISCOUNTS,TOTALDISCOUNTED,ADDEXPENSES,TOTALEXPENSES,TOTALPROMOTIONS,TOTALVAT,GROSSTOTAL,NETTOTAL,REPORTRATE,REPORTNET,GENEXP1,GENEXP2,GENEXP3,GENEXP4,EXTENREF,PAYDEFREF,PRINTCNT,BRANCH,DEPARTMENT,STATUS,CAPIBLOCK_CREATEDBY,CAPIBLOCK_CREADEDDATE,CAPIBLOCK_CREATEDHOUR,CAPIBLOCK_CREATEDMIN,CAPIBLOCK_CREATEDSEC,CAPIBLOCK_MODIFIEDBY,CAPIBLOCK_MODIFIEDDATE,CAPIBLOCK_MODIFIEDHOUR,CAPIBLOCK_MODIFIEDMIN,CAPIBLOCK_MODIFIEDSEC,SALESMANREF,SHPTYPCOD,SHPAGNCOD,GENEXCTYP,LINEEXCTYP,TRADINGGRP,TEXTINC,SITEID,RECSTATUS,ORGLOGICREF,FACTORYNR,WFSTATUS,SHIPINFOREF,CUSTORDNO,SENDCNT,DLVCLIENT,DOCTRACKINGNR,CANCELLED,ORGLOGOID,OFFERREF,OFFALTREF,TYP,ALTNR,ADVANCEPAYM,TRCURR,TRRATE,TRNET,PAYMENTTYPE,ONLYONEPAYLINE,OPSTAT,WITHPAYTRANS,PROJECTREF,WFLOWCRDREF,UPDTRCURR,AFFECTCOLLATRL,POFFERBEGDT,POFFERENDDT,REVISNR,LASTREVISION,CHECKAMOUNT,SLSOPPRREF,SLSACTREF,SLSCUSTREF,AFFECTRISK,TOTALADDTAX,TOTALEXADDTAX,APPROVE,APPROVEDATE,CHECKPRICE,GUID,EINVOICE
)VALUES (
:siparisdurum,:fisno,DATEADD(day,0,datediff(day,0,GETDATE())),:kayitsaat,'','','',:cariid,0,0,0,:depo,0,0,0,0,0,0,0,0,0,0,0,1,0,'','','','',0,0,0,0,0,:status,1,DATEADD(day,0,datediff(day,0,GETDATE())),:saat,:dakika,:saniye,0,NULL,0,0,0,:terminalkullanici,'','',2,0,'',0,0,1,0,0,0,0,'',0,0,'',0,'',0,0,0,0,0,:doviz,:dovizkuru,0,0,0,0,0,0,0,0,0,NULL,NULL,'',0,0,0,0,0,1,0,0,0,NULL,0,:guid,0
)");
	$ekle = $stmtEkle->execute([':siparisdurum' => $siparisdurum, ':fisno' => $fisno, ':kayitsaat' => (int) $kayitsaat, ':cariid' => $cariid, ':depo' => (int) $depo, ':status' => $status, ':saat' => (int) $saat, ':dakika' => (int) $dakika, ':saniye' => (int) $saniye, ':terminalkullanici' => (int) $terminalkullanici, ':doviz' => (int) $doviz, ':dovizkuru' => (float) $dovizkuru, ':guid' => (string) $guid]);
    if (!$ekle) {
        throw new RuntimeException('Sipariş başlığı yazılamadı.');
    }
    $sonsiparisid = siparis_baslik_eklenen_id($stmtEkle);
    $fisno = siparis_baslik_numarasini_kesinlestir($dbh, $firmadonem, $sonsiparisid, (string) $sipno);
    siparis_idempotency_tamamla($dbh, $key, (int)$firmano, $firmadonem, (int)$terminalkullanici, $hash,
        ['ok'=>true, 'fis'=>['id'=>$sonsiparisid]], $scope);
    if (!$dbh->commit()) { throw new RuntimeException('Commit doğrulanamadı.'); }
    web_siparis_tamam($_SESSION, $key);
} catch (Throwable $e) {
    try { if ($dbh->inTransaction()) { $dbh->rollBack(); } } catch (Throwable $ignored) {}
    error_log('Fiş açma hatası: ' . $e->getMessage());
    $fields = $_SESSION['web_siparis_intents'][$key]['payload']['fields'] ?? $fields;
    web_siparis_form($key, $fields, web_siparis_hata($e));
    exit;
}

		// ========================================
		// Fiş oluşturma işlemini logla
		// ========================================
		$logOptions = ['doviz' => ($doviz === '0' || $doviz === '') ? 'TL' : $doviz, 'dovizKuru' => $dovizkuru, 'depo' => $depo, 'durum' => $status, 'aciklama' => "Yeni fiş oluşturuldu (Fiş No: {$fisno})"];

		try { $logBasarili = logFisOlusturma(
			$sonsiparisid,    // FIS_REF
			$fisno,           // FICHENO
			$cariid,          // CARI_REF
			$terminalkullanici,  // KULLANICI_ID
			$logOptions       // OPTIONS
		); } catch (Throwable $ignored) { $logBasarili = false; }

		// Log başarısız olduysa uyarı ver (geliştirme ortamı için)
		if (!$logBasarili) {
			error_log("UYARI: Fiş #{$fisno} oluşturma logu kaydedilemedi!");
		}
		// ========================================

header('Location: lg_fis.php?stokhareket=' . (int)$sonsiparisid, true, 303);
exit;

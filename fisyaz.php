<?php
declare(strict_types=1);

$doviz="";
include_once(__DIR__ . "/ayr.php");
require_once __DIR__ . '/kontrol.php';

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['stokhareket']);

// Session bazlı parametre sistemi kullanılıyor
$stokhareket = getPageParamInt('stokhareket');
if ($stokhareket <= 0) {
	echo '<div class="notification msgerror">Lütfen bir fiş numarası belirtin.</div>';
	exit;
}
if (!m_p_siparis_goruntulebilir_mi($dbh, $firmadonem, $firma, $terminalkullanici, $stokhareket)) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

		// Yazdırma işlemini logla
	$stmtFisNo = $dbh->prepare("SELECT FICHENO FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket");
	$stmtFisNo->execute([':stokhareket' => $stokhareket]);
	$fisNoLog = $stmtFisNo->fetch(PDO::FETCH_ASSOC);
	if (function_exists('logYazdir') && $fisNoLog) {
	    logYazdir($stokhareket, $fisNoLog['FICHENO'], 'FIYATLI', $terminalkullanici, 'Fiyatlı fiş yazdırıldı');
	}

	$stmtListfis = $dbh->prepare ("SELECT
	 CLIENTREF,
	 GROSSTOTAL,
	  GENEXP1,
	  DATE_,
	FICHENO
	 FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket ");
	$stmtListfis->execute([':stokhareket' => $stokhareket]);
	$listfis = $stmtListfis->fetch(PDO::FETCH_ASSOC);
	$cariid=intcevir($listfis['CLIENTREF']);
	 $stmtCari = $dbh->prepare("SELECT CLCARD.CODE AS KODU,CLCARD.LOGICALREF AS CARIID, CLCARD.DEFINITION_ AS UNVANI, SUM((1 - CLFLINE.SIGN) * CLFLINE.AMOUNT) - SUM(CLFLINE.SIGN * CLFLINE.AMOUNT) AS BAKIYE  FROM  ".$firma."CLCARD CLCARD LEFT JOIN ".$firmadonem."CLFLINE CLFLINE ON CLCARD.LOGICALREF=CLFLINE.CLIENTREF   AND CLFLINE.CANCELLED = 0 GROUP BY CLCARD.CODE, CLCARD.DEFINITION_, CLCARD.ACTIVE,CLCARD.LOGICALREF HAVING CLCARD.LOGICALREF = :cariid  AND CLCARD.ACTIVE = 0");
	 $stmtCari->execute([':cariid' => $cariid]);
	 $sqlx = $stmtCari->fetch(PDO::FETCH_ASSOC);

	 $stmtSatirsay = $dbh->prepare("SELECT COUNT(*) AS SAYI FROM ".$firmadonem."ORFLINE  WHERE ".$firmadonem."ORFLINE.ORDFICHEREF = :stokhareket");
	 $stmtSatirsay->execute([':stokhareket' => $stokhareket]);
	 $satirsay = $stmtSatirsay->fetch(PDO::FETCH_ASSOC);
	 $satirtoplam= $satirsay["SAYI"];


 ?>



<?php
$satirmiktari=40;
$yenisatirmiktari=$satirmiktari;
$sayfano=1;
$satiryukseklik=130;
$baslikyuksekligi=630;
$stokfont=120;
$stokfontx=40;
//$yaziciadi="Microsoft Print to PDF";
$tarih=date("d/m/Y H:i:s");
//$handle = printer_open("\\\\tnb_mikro\\HP1132MFP");
if (!function_exists('printer_open')) {
    // php_printer (Windows RAW yazici) eklentisi yuklu degil; fatal yerine duzgun cikis.
    http_response_code(503);
    exit('Bu yazdirma yontemi sunucuda desteklenmiyor (php_printer eklentisi yuklu degil).');
}
$handle = printer_open($fisyazici);





	printer_start_doc($handle, "mshop");
printer_start_page($handle);
printer_set_option($handle,PRINTER_MODE,"RAW");

//printer_set_option($handle, PRINTER_TEXT_ALIGN, PRINTER_TA_LEFT);
$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
printer_select_pen($handle, $pen);
$font = printer_create_font("Tahoma", 280, 55, 100, false, false, false, 1);
printer_select_font($handle, $font);
//printer_draw_bmp($handle, "c:\\c.bmp", 50, 50);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","TEKLİF FİŞİ"), 2000, 100);
$font = printer_create_font("Tahoma", $stokfont, $stokfontx, 00, false, false, false, 1);
printer_select_font($handle, $font);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","Sayın:"), 100, 400);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",(string) $sqlx['UNVANI']), 400, 400);
printer_draw_text($handle,tarihcevir($listfis['DATE_']), 4200, 200);
printer_draw_text($handle,"sayfa : ".$sayfano, 4400, 400);

//tablo üst


//rinter_draw_roundrect($handle, 1, 1, 500, 500, 200, 200);
printer_draw_roundrect($handle, 10, 580, 4800, 720, 0, 0);//left/top/withh/height/0/0



$font = printer_create_font("Tahoma", 98, 33, 00, false, false, false, 1);
printer_select_font($handle, $font);
	printer_draw_text($handle, "KODU", 350, 590);
printer_draw_text($handle, "ACIKLAMA", 800, 590);

//printer_draw_text($handle, "KOLI", 2400, 590);
//printer_draw_text($handle, "BR", 2600, 590);
//printer_draw_text($handle, "K.ICI", 2800, 590);
//printer_draw_text($handle, "K.FIYAT", 3100, 590);
printer_draw_text($handle, "MIKTAR", 3500, 590);

printer_draw_text($handle, "FIYAT", 4100, 590);

printer_draw_text($handle, "TOPLAM", 4400, 590);
/*printer_draw_line($handle, 30, 590, 4800,590);*/

$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
printer_select_pen($handle, $pen);
$font = printer_create_font("Tahoma", 98, 33, 00, false, false, false, 1);
printer_select_font($handle, $font);
$i = 0;
$a=$baslikyuksekligi;
$b=$satiryukseklik;


$hareketFisToplami = 0;
$toplam='';
$gtoplam='';
$gisk='';
$kolifiyat=0;
	$kolitoplam=0;
	$adetmiktar=0;
	$koli=0;
	$silmes="seçtiniz ürün silinecek";
	$stmtList = $dbh->prepare("SELECT
	  ".$firmadonem."ORFLINE.PRICE,
	 ".$firmadonem."ORFLINE.AMOUNT,
	  ".$firmadonem."ORFLINE.TOTAL,
	  ".$firmadonem."ORFLINE.VATMATRAH,
	 ".$firmadonem."ORFLINE.STOCKREF,
	  ".$firmadonem."ORFLINE.LOGICALREF,
	    ".$firmadonem."ORFLINE.ORDFICHEREF,
		".$firmadonem."ORFLINE.LINEEXP,
	  ".$firma."ITEMS.CODE [SKODU],
	 ".$firma."ITEMS.NAME [SADI],
	 ".$firma."UNITSETL.CODE [BIRIM] 
	 FROM ".$firmadonem."ORFLINE LEFT JOIN ".$firma."ITEMS ON ".$firmadonem."ORFLINE.STOCKREF=".$firma."ITEMS.LOGICALREF LEFT JOIN ".$firma."UNITSETL ON ".$firmadonem."ORFLINE.UOMREF=".$firma."UNITSETL.LOGICALREF WHERE ".$firmadonem."ORFLINE.ORDFICHEREF = :stokhareket");
	$stmtList->execute([':stokhareket' => $stokhareket]);
	$list = $stmtList;
	 while($liste=$list->fetch(PDO::FETCH_ASSOC))
 { 
$toplam=$liste['AMOUNT']*$liste['PRICE'];
$gtoplam+=$toplam;
$gisk+=$liste['VATMATRAH'];
$stokhareketid=intcevir($liste['LOGICALREF']);
	$stokhid=intcevir($liste['STOCKREF']);
	$kodu=trcevir($liste['SKODU']);
	$adi=trcevir($liste['SADI']);
	$aciklama=trcevir($liste['LINEEXP']);
	$adetmiktar+=$liste['AMOUNT'];


//$d=$a+10;
//echo $satirmiktari;
////////çoklu sayfa döngüsü/////////////
if($yenisatirmiktari == $i)
{
	$sayfano+=1;
	$yenisatirmiktari+=$satirmiktari;

printer_delete_font($font);
printer_delete_pen($pen);
printer_end_page($handle);
printer_end_doc($handle);
printer_close($handle);

	$handle = printer_open($fisyazici);
	printer_start_doc($handle, "My Document");
printer_start_page($handle);
//printer_set_option($handle, PRINTER_FORMAT_LETTER);
printer_set_option($handle,PRINTER_MODE,"RAW");

//printer_set_option($handle, PRINTER_TEXT_ALIGN, PRINTER_TA_LEFT,PRINTER_FORMAT_LETTER);
$font = printer_create_font("Tahoma", 100, 35, 00, false, false, false, 1);
printer_select_font($handle, $font);
$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
printer_select_pen($handle, $pen);
printer_draw_text($handle,"sayfa : ".$sayfano, 4400, 400);
printer_draw_text($handle, "KODU", 350, 590);
printer_draw_text($handle, "ACIKLAMA", 800, 590);
//printer_draw_text($handle, "KOLI", 2400, 590);
//printer_draw_text($handle, "BR", 2600, 590);
//printer_draw_text($handle, "K.ICI", 2800, 590);
//printer_draw_text($handle, "K.FIYAT", 3100, 590);
printer_draw_text($handle, "MIKTAR", 3500, 590);

printer_draw_text($handle, "FIYAT", 4100, 590);

printer_draw_text($handle, "TOPLAM", 4400, 590);
printer_draw_line($handle, 30, 590, 4800,590);
$font = printer_create_font("Tahoma", 95, 25, 00, false, false, false, 1);
printer_select_font($handle, $font);
$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
printer_select_pen($handle, $pen);

$a=$baslikyuksekligi;
$b=$satiryukseklik;
}
////////çoklu sayfa döngüsü bitiş/////////////
$i++;
$a += $b;
printer_draw_roundrect($handle, 5, $a+5, 225, $a+120, 0, 0);
//printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","[     ]"), 10, $a);
printer_draw_text($handle, $i, 250, $a);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",(string) $kodu), 400, $a);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",$adi.'  '.$aciklama), 850, $a);
//printer_draw_text($handle, kusuratadet($koli), 2400, $a);
//printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",$kolibirim), 2650, $a);
//printer_draw_text($handle, kusuratsifir($koliici['CARPAN']), 2900, $a);
//printer_draw_text($handle, kusuratpara($kolifiyat), 3100, $a);
printer_draw_text($handle, kusuratsifir($liste['AMOUNT']), 3550, $a);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",(string) $liste['BIRIM']), 3750, $a);
printer_draw_text($handle, kusuratpara($liste['PRICE']), 4150, $a);

printer_draw_text($handle, kusuratpara($liste['TOTAL']), 4400, $a);
printer_draw_line($handle, 30, $a, 4800, $a);	

	}
	printer_draw_line($handle, 30, $a+130, 4800, $a+130);
$durum=$sqlx['BAKIYE']+$listfis['GROSSTOTAL'];


	$a=$a+$b+10;
$c=$a+$b+10;

printer_draw_roundrect($handle, 20, $a+5, 4800, $a+600, 0, 0);
//$fis="fi� toplam�:";
//$fis=iconv("UTF-8", "ISO-8859-9",$fis);
	printer_draw_text($handle,"bu fis:", 3500, $a+100);
printer_draw_text($handle, kusuratpara($listfis['GROSSTOTAL']), 4300, $a+100);
	printer_draw_text($handle, "eski  bakiye:", 3500, $c+100);
printer_draw_text($handle, kusuratpara($sqlx['BAKIYE']), 4300, $c+100);//cari bakiye olacak
printer_draw_text($handle, "son  bakiye:", 3500, $c+220);
printer_draw_text($handle, kusuratpara($durum), 4300, $c+220);//cari bakiye olacak
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","*teklif fişidir resmi belge yerine geçmez"), 100, $a+100);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","*KIRIK VE EKSİK ÜRÜNLERİ 7 GÜN İÇİNDE BİLDİRİNİZ AKSİ TAKTİRDE FİRMAMIZ MESUL DEĞİLDİR "), 100, $a+450);
printer_draw_text($handle, trcevir($listfis['GENEXP1']), 2000, $a+170);

$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
printer_select_pen($handle, $pen);



//printer_draw_text($handle,  iconv("UTF-8", "ISO-8859-9",kusuratpara($kolitoplam)." KOLİ"), 2400, $a+205);
//printer_draw_text($handle,  iconv("UTF-8", "ISO-8859-9",kusuratsifir($adetmiktar)."ADET"), 1550, $a+205);
printer_delete_font($font);
printer_delete_pen($pen);
printer_end_page($handle);
printer_end_doc($handle);
printer_close($handle);
// echo  '<script>window.location="index.php";</script>' ;
?>

<?php
declare(strict_types=1);
 include_once(__DIR__ . "/ayr.php"); ?>
<?php
require_once __DIR__ . '/kontrol.php';
if(!isset($_GET['stokhareket'])){echo '<div class="notification msgerror">Lütfen bir fiş numarası belirtin.</div>'; exit;}
 $stokhareket=(int)$_GET['stokhareket'];

$stmtFis = $dbh->prepare ("SELECT
 CLIENTREF,
 GROSSTOTAL,
  GENEXP1,
  DATE_,
FICHENO
 FROM ".$firmadonem."ORFICHE WHERE LOGICALREF=:stokhareket ");
$stmtFis->execute([':stokhareket' => $stokhareket]);
$listfis = $stmtFis->fetch(PDO::FETCH_ASSOC);
if(!$listfis){echo '<div class="notification msgerror">Fiş bulunamadı.</div>'; exit;}
$cariid=intcevir($listfis['CLIENTREF']);
 $stmtCari = $dbh->prepare("SELECT CLCARD.CODE AS KODU,CLCARD.LOGICALREF AS CARIID, CLCARD.DEFINITION_ AS UNVANI, SUM((1 - CLFLINE.SIGN) * CLFLINE.AMOUNT) - SUM(CLFLINE.SIGN * CLFLINE.AMOUNT) AS BAKIYE  FROM  ".$firma."CLCARD CLCARD LEFT JOIN ".$firmadonem."CLFLINE CLFLINE ON CLCARD.LOGICALREF=CLFLINE.CLIENTREF   AND CLFLINE.CANCELLED = 0 GROUP BY CLCARD.CODE, CLCARD.DEFINITION_, CLCARD.ACTIVE,CLCARD.LOGICALREF HAVING CLCARD.LOGICALREF = :cariid  AND CLCARD.ACTIVE = 0  ");
 $stmtCari->execute([':cariid' => $cariid]);
 $sqlx = $stmtCari->fetch(PDO::FETCH_ASSOC);

 $stmtSatirSay = $dbh->prepare("SELECT COUNT(*) AS SAYI FROM ".$firmadonem."ORFLINE  WHERE ORDFICHEREF=:stokhareket ");
 $stmtSatirSay->execute([':stokhareket' => $stokhareket]);
 $satirsay = $stmtSatirSay->fetch(PDO::FETCH_ASSOC);
 $satirtoplam= $satirsay["SAYI"];
 
  	$dovizfiyat=dovizkuru_bul($stokhareket);
 ?>



<?php
$satirmiktari=40;
$sayfano=1;
$satiryukseklik=130;
$baslikyuksekligi=630;
$stokfont=120;
$stokfontx=40;
//$yaziciadi="Microsoft Print to PDF";
$tarih=date("d/m/Y H:i:s");
//$handle = printer_open("\\\\tnb_mikro\\HP1132MFP");
$handle = printer_open($fisyazici);
	printer_start_doc($handle, "mshop");
printer_start_page($handle);
printer_set_option($handle,PRINTER_MODE,"RAW");
printer_set_option($handle, PRINTER_TEXT_ALIGN, PRINTER_TA_LEFT);
$font = printer_create_font("Tahoma", 280, 55, 100, false, false, false, 1);
printer_select_font($handle, $font);
//printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","TUĞRA CAM"), 200, 100);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","TEKLİF FİŞİ"), 2000, 320);
$font = printer_create_font("Tahoma", $stokfont, $stokfontx, 00, false, false, false, 1);
printer_select_font($handle, $font);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","Sayın:"), 100, 400);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",(string) $sqlx['UNVANI']), 400, 400);
printer_draw_text($handle,tarihcevir($listfis['DATE_']), 4400, 200);
printer_draw_text($handle,"sayfa : ".$sayfano, 4400, 400);
$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
printer_select_pen($handle, $pen);
//tablo üst
	
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
 FROM ".$firmadonem."ORFLINE LEFT JOIN ".$firma."ITEMS ON ".$firmadonem."ORFLINE.STOCKREF=".$firma."ITEMS.LOGICALREF LEFT JOIN ".$firma."UNITSETL ON ".$firmadonem."ORFLINE.UOMREF=".$firma."UNITSETL.LOGICALREF WHERE ".$firmadonem."ORFLINE.ORDFICHEREF=:stokhareket ");
 $stmtList->execute([':stokhareket' => $stokhareket]);
 while($liste=$stmtList->fetch(PDO::FETCH_ASSOC))
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
if($satirmiktari == $i)
{
	$sayfano+=1;
	 $satirmiktari+=$satirmiktari;
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

printer_set_option($handle, PRINTER_TEXT_ALIGN, PRINTER_TA_LEFT);
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
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","[      ]"), 10, $a);
printer_draw_text($handle, $i, 180, $a);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",(string) $kodu), 350, $a);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",$adi.'  '.$aciklama), 800, $a);
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
	
$durum=$sqlx['BAKIYE']+$listfis['GROSSTOTAL'];


	$a=$a+$b+10;
$c=$a+$b+10;
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
//printer_draw_text($handle,  iconv("UTF-8", "ISO-8859-9",kusuratpara($kolitoplam)." KOLİ"), 2400, $a+205);
//printer_draw_text($handle,  iconv("UTF-8", "ISO-8859-9",kusuratsifir($adetmiktar)."ADET"), 1550, $a+205);
printer_delete_font($font);
printer_delete_pen($pen);
printer_end_page($handle);
printer_end_doc($handle);
printer_close($handle);
 echo  '<script>window.location="index.php";</script>' ;
?>

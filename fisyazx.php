<?php
declare(strict_types=1);
 include_once(__DIR__ . "/ayr.php"); ?>
<?php
require_once __DIR__ . '/kontrol.php';
if(!isset($_GET['stokhareket'])){echo '<div class="notification msgerror">Lütfen bir fiş numarası belirtin.</div>'; exit;}
 $stokhareket=(int) $_GET['stokhareket'];
if ($stokhareket <= 0) { echo '<div class="notification msgerror">Geçersiz fiş numarası.</div>'; exit; }

$stmtFis = $dbh->prepare("SELECT
 CLIENTREF,
 DATE_,
 GENEXP1,
 TOTALVAT,
 NETTOTAL,
 GROSSTOTAL,
FICHENO
 FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket ");
$stmtFis->execute([':stokhareket' => $stokhareket]);
$listfis = $stmtFis->fetch(PDO::FETCH_ASSOC);
$cariid=intcevir($listfis['CLIENTREF']);
 $stmtBakiye = $dbh->prepare("SELECT CLCARD.CODE AS KODU,CLCARD.LOGICALREF AS CARIID, CLCARD.DEFINITION_ AS UNVANI, SUM((1 - CLFLINE.SIGN) * CLFLINE.AMOUNT) - SUM(CLFLINE.SIGN * CLFLINE.AMOUNT) AS BAKIYE  FROM  ".$firma."CLCARD CLCARD LEFT JOIN ".$firmadonem."CLFLINE CLFLINE ON CLCARD.LOGICALREF=CLFLINE.CLIENTREF   AND CLFLINE.CANCELLED = 0 GROUP BY CLCARD.CODE, CLCARD.DEFINITION_, CLCARD.ACTIVE,CLCARD.LOGICALREF HAVING CLCARD.LOGICALREF = :cariid  AND CLCARD.ACTIVE = 0  ");
 $stmtBakiye->execute([':cariid' => $cariid]);
 $sqlx = $stmtBakiye->fetch(PDO::FETCH_ASSOC);
?>



<?php
$tarih=date("d/m/Y H:i:s");
//$handle = printer_open("\\\\tnb_mikro\\HP1132MFP");
$handle = printer_open("Microsoft Print to PDF");
	printer_start_doc($handle, "My Document");
printer_start_page($handle);
printer_set_option($handle,PRINTER_MODE,"RAW");
printer_set_option($handle, PRINTER_TEXT_ALIGN, PRINTER_TA_LEFT);
$font = printer_create_font("Tahoma", 90, 25, 00, false, false, false, 1);
printer_select_font($handle, $font);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","Sayın:"), 500, 200);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",(string) $sqlx['UNVANI']), 800, 200);
printer_draw_text($handle,$tarih.'>>'. $stokhareket, 3500, 200);
$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "666666");
printer_select_pen($handle, $pen);
//tablo üst
	
	printer_draw_text($handle, "kodu", 300, 400);
printer_draw_text($handle, "isim", 1000, 400);
printer_draw_text($handle, "miktar", 3300, 400);

printer_draw_text($handle, "fiyat", 3800, 400);
printer_draw_text($handle, "toplam", 4300, 400);
printer_draw_line($handle, 30, 400, 4800,400);

$i = 0;
$a=405;
$b=120;

$hareketFisToplami = 0;
$toplam='';
$gtoplam='';
$gisk='';
$silmes="seçtiniz ürün silinecek";
$stmtList = $dbh->prepare("SELECT
  ".$firmadonem."ORFLINE.PRICE,
 ".$firmadonem."ORFLINE.AMOUNT,
  ".$firmadonem."ORFLINE.TOTAL,
  ".$firmadonem."ORFLINE.VATMATRAH,
 ".$firmadonem."ORFLINE.STOCKREF,
  ".$firmadonem."ORFLINE.LOGICALREF,
    ".$firmadonem."ORFLINE.ORDFICHEREF,
 ".$firma."ITEMS.CODE [SKODU],
 ".$firma."ITEMS.NAME [SADI],
 ".$firma."UNITSETL.CODE [BIRIM] 
 FROM ".$firmadonem."ORFLINE LEFT JOIN ".$firma."ITEMS ON ".$firmadonem."ORFLINE.STOCKREF=".$firma."ITEMS.LOGICALREF LEFT JOIN ".$firma."UNITSETL ON ".$firmadonem."ORFLINE.UOMREF=".$firma."UNITSETL.LOGICALREF WHERE ".$firmadonem."ORFLINE.ORDFICHEREF = :stokhareket ");
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

	$i++;
$a += $b;
//$d=$a+10;
printer_draw_text($handle, $i, 100, $a);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",(string) $kodu), 300, $a);
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9",(string) $adi), 1000, $a);
printer_draw_text($handle, kusuratsifir($liste['AMOUNT']), 3300, $a);
printer_draw_text($handle, $liste['BIRIM'], 3500, $a);
printer_draw_text($handle, kusuratpara($liste['PRICE']), 3800, $a);
printer_draw_text($handle, kusuratpara($liste['TOTAL']), 4300, $a);
printer_draw_line($handle, 18, $a, 4800, $a);	
	
	}
	$a += $b;
$c=$a+$b;
//$fis="fi� toplam�:";
//$fis=iconv("UTF-8", "ISO-8859-9",$fis);
	printer_draw_text($handle,"toplam:", 3500, $a);
printer_draw_text($handle, kusuratpara($listfis['GROSSTOTAL']), 4100, $a);
printer_draw_text($handle,"kdv:", 3500, $c);
printer_draw_text($handle, kusuratpara($listfis['TOTALVAT']), 4100, $c);
	printer_draw_text($handle, "genel toplam:", 3500, $c+110);
printer_draw_text($handle, kusuratpara($listfis['NETTOTAL']), 4100, $c+110);//cari bakiye olacak
printer_draw_text($handle, "son  bakiye:", 3500, $c+250);
printer_draw_text($handle, $durum=kusuratpara($sqlx['BAKIYE']+kusuratpara($listfis['NETTOTAL'])), 4100, $c+250);//cari bakiye olacak
printer_draw_text($handle, iconv("UTF-8", "ISO-8859-9","*teklif fişidir resmi belge yerine geçmez"), 100, $a);
printer_draw_text($handle, trcevir($listfis['GENEXP1']), 2000, $a);

printer_delete_font($font);
printer_delete_pen($pen);
printer_end_page($handle);
printer_end_doc($handle);
printer_close($handle);
echo  '<script>window.location="index.php";</script>' ;
?>

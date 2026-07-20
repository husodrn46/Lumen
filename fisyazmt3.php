<?php
declare(strict_types=1);
 include_once(__DIR__ . "/ayr.php"); ?>
<?php
require_once __DIR__ . '/kontrol.php';
if (!isset($_GET['stokhareket'])) {
	echo '<div class="notification msgerror">Lütfen bir fiş numarası belirtin.</div>';
	exit;
}
$stokhareket = (int)$_GET['stokhareket'];
$yazdir = $_GET['yazdir'];
$stmtFis = $dbh->prepare("SELECT
 CLIENTREF,
 DATE_,
 GENEXP1,
 TOTALVAT,
 NETTOTAL,
 GROSSTOTAL,
FICHENO,
TOTALDISCOUNTS
 FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF=:stokhareket ");
$stmtFis->execute([':stokhareket' => $stokhareket]);
$listfis = $stmtFis->fetch(PDO::FETCH_ASSOC);
$cariid = intcevir($listfis['CLIENTREF']);
$stmtCari = $dbh->prepare("SELECT CLCARD.CODE AS KODU,CLCARD.LOGICALREF AS CARIID, CLCARD.DEFINITION_ AS UNVANI, SUM((1 - CLFLINE.SIGN) * CLFLINE.AMOUNT) - SUM(CLFLINE.SIGN * CLFLINE.AMOUNT) AS BAKIYE  FROM  " . $firma . "CLCARD CLCARD LEFT JOIN " . $firmadonem . "CLFLINE CLFLINE ON CLCARD.LOGICALREF=CLFLINE.CLIENTREF   AND CLFLINE.CANCELLED = 0 GROUP BY CLCARD.CODE, CLCARD.DEFINITION_, CLCARD.ACTIVE,CLCARD.LOGICALREF HAVING CLCARD.LOGICALREF = :cariid  AND CLCARD.ACTIVE = 0  ");
$stmtCari->execute([':cariid' => $cariid]);
$sqlx = $stmtCari->fetch(PDO::FETCH_ASSOC);
?>
<div align="center" class="meydan">

	<table class="" width="100%" border="0" style="border-collapse: collapse;font-size:12px;">
		<thead>
			<tr>
				<th width="200">TARİH</th>
				<th width="100">FİŞ NO</th>

				<th>CARİ ADI</th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td style="text-align:center;">
					<?php echo tarihcevir($listfis['DATE_']); ?>
				</td>
				<td style="text-align:center;">
					<?php echo $listfis['FICHENO']; ?>
				</td>
				<td style="text-align:center;">
					<?php echo trcevir($sqlx['UNVANI']); ?>
				</td>

			</tr>
		</tbody>
	</table>

	<br />
	<table class="" width="100%" border="0" style="border-collapse: collapse;font-size:10px;">
		<thead>
			<tr>
				<th><a href="index.php">STOK KODU</a></th>

				<th>STOK ADI</th>
				<th>MİKTAR</th>

				<th>İÇ BİRİM</th>
				<th>KOLİ</th>
				<th>KG</th>
				<th>TOP-KG</th>
				<th>M3</th>
				<th>TOP-M3</th>


			</tr>
		</thead>
		<tbody>
			<?php
			$hareketFisToplami = 0;
			$toplam = '';
			$gtoplam = '';
			$gisk = '';
			$i = 0;

			$kolitoplam = 0;
			$kilotoplam = 0;
			$metrekuptoplam = 0;
			$metrekup = 0;
			$kilo = 0;
			$genelmetrekup = 0;
			$genelkilo = 0;
			$genelkoli = 0;
			$silmes = "seçtiniz ürün silinecek";
			$stmtList = $dbh->prepare("SELECT
  " . $firmadonem . "ORFLINE.PRICE,
    " . $firmadonem . "ORFLINE.TOTAL,
	" . $firmadonem . "ORFLINE.VAT,
	" . $firmadonem . "ORFLINE.VATAMNT,
	" . $firmadonem . "ORFLINE.LINENO_,
 " . $firmadonem . "ORFLINE.AMOUNT,
  " . $firmadonem . "ORFLINE.VATMATRAH,
 " . $firmadonem . "ORFLINE.STOCKREF,
 " . $firmadonem . "ORFLINE.LINEEXP,
  " . $firmadonem . "ORFLINE.LOGICALREF,
    " . $firmadonem . "ORFLINE.ORDFICHEREF,
  " . $firma . "ITEMS.CODE [SKODU],
 " . $firma . "ITEMS.NAME [SADI],
 " . $firma . "ITEMS.[KEYWORD1],
 " . $firma . "ITEMS.[KEYWORD2],

 " . $firma . "UNITSETL.CODE [BIRIM] 
 FROM " . $firmadonem . "ORFLINE LEFT JOIN " . $firma . "ITEMS ON " . $firmadonem . "ORFLINE.STOCKREF=" . $firma . "ITEMS.LOGICALREF LEFT JOIN " . $firma . "UNITSETL ON " . $firmadonem . "ORFLINE.UOMREF=" . $firma . "UNITSETL.LOGICALREF WHERE " . $firmadonem . "ORFLINE.ORDFICHEREF=:stokhareket AND LINETYPE=0 ");
			$stmtList->execute([':stokhareket' => $stokhareket]);

			$stmtKoliici = $dbh->prepare("SELECT ICBIRIM.CONVFACT2 AS CARPAN, BR.CODE
			  FROM  " . $firma . "ITMUNITA ICBIRIM
			  LEFT JOIN " . $firma . "UNITSETL BR ON ICBIRIM.UNITLINEREF = BR.LOGICALREF
			  LEFT JOIN " . $firma . "ITEMS STK ON ICBIRIM.ITEMREF = STK.LOGICALREF
			  WHERE ICBIRIM.ITEMREF = :stokhid
			  AND ICBIRIM.LINENR = 2
			  AND BR.UNITSETREF = STK.UNITSETREF");

			while ($liste = $stmtList->fetch(PDO::FETCH_ASSOC)) {
				$stokhid = $liste['STOCKREF'];
				$metrekup = $liste['KEYWORD1'];
				$kilo = $liste['KEYWORD2'];
				$stmtKoliici->execute([':stokhid' => (int)$stokhid]);
				$koliici = $stmtKoliici->fetch(PDO::FETCH_ASSOC);


				$koli = $liste['AMOUNT'] / $koliici['CARPAN'];
				//$koli=kusuratadet($koli);
				$kolifiyat = $koliici['CARPAN'] * $liste['PRICE'];
				$kolibirim = $koliici['CODE'];
				$kilotoplam = $koli * $kilo;
				$metrekuptoplam = $koli * $metrekup;

				$kolitoplam += $liste['AMOUNT'] / $koliici['CARPAN'];


				$genelmetrekup += $metrekuptoplam;
				$genelkilo += $kilotoplam;

				$genelkoli += $koli;

				$toplam = $liste['TOTAL'] + $liste['VATAMNT'];
				//$gtoplam+=$toplam;
//$gisk+=$liste['VATMATRAH'];
//$stokhareketid=intcevir($liste['LOGICALREF']);
//$stokhid=intcevir($liste['STOCKREF']);
//$kodu=trcevir($liste['SKODU']);
//$adi=trcevir($liste['SADI']);
			
				$i++;

				echo '
	<tr style="border-top: 1px solid #000;">
		
		<td>' . $liste['SKODU'] . '</td>
		<td>' . $liste['SADI'] . '</td>
		<td style="text-align:right;">' . kusuratadet($liste['AMOUNT']) . ' ' . $liste['BIRIM'] . '</td>
	
		<td style="text-align:center;">' . kusuratpara($koliici['CARPAN']) . '</td>
		<td style="text-align:center;">' . kusuratpara($koli) . '</td>
		<td style="text-align:center;">' . kusuratpara($kilo) . '</td>
		<td style="text-align:center;">' . kusuratpara($kilotoplam) . '</td>
		<td style="text-align:center;">' . kusuratpara($metrekup) . '</td>
		<td style="text-align:center;">' . kusuratpara($metrekuptoplam) . '</td>
		
	</tr>
	';
			}

			?>
			<tr style="border-top: 1px solid #000;">
				<td></td>
				<td></td>
				<td></td>
				<td>
				<td></td>
				</td>
				<td></td>
				<td></td>
				<td></td>
				<td></td>
				<td></td>
			</tr>
		</tbody>
	</table>
</div>

<div align="right">
	<table border="0" style=";border-collapse: collapse;width:autopx; font-weight: bold;">
		<tr style="border-bottom: 1px solid #000;">
			<td style="text-align:right;">toplam koli:</td>
			<td style="text-align:right;">
				<?php echo kusuratpara($genelkoli); ?>
			</td>
		</tr>
		<tr style="border-bottom: 1px solid #000;">
			<td style="text-align:right;">toplam m3:</td>
			<td style="text-align:right;">
				<?php echo kusuratpara($genelmetrekup); ?>
			</td>
		</tr>
		<tr style="border-bottom: 1px solid #000;">
			<td style="text-align:right;">toplam kg:</td>
			<td style="text-align:right;">
				<?php echo kusuratpara($genelkilo); ?>
			</td>
		</tr>





	</table>
</div>



<?php
if (isset($_GET['yazdir'])) {
	?>

	<body onLoad="window.print(); ">
	<?php
}
?>

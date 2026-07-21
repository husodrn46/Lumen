<?php declare(strict_types=1); ?>
<?php
require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../log_ip.php");

// Dönem kontrolü - 2025 dönemi için eski tabloları kullan
$donemParam = isset($_GET['donem']) ? $_GET['donem'] : '';
if ($donemParam === '2025' && isset($eskifirmadonem)) {
    $firmadonem = $eskifirmadonem;
}

$mdoviz="TL";
if(isset($_GET['cari_id']))
{
 $CARIID = (int) $_GET['cari_id'];
 $trcode = (int) $_GET['trcode'];
$REF = (int) $_GET['REF'];
} else {exit;}
echo '<a href="lg_fatura_yazdir.php?cari_id='.$CARIID.'&trcode='.$trcode.'&REF='.$REF.'" target="_blank"><img src="tm/s/yaz2.png"></a>';
?> 


<table class="" width="100%" border="0" style="border-collapse: collapse;font-size:10px;">
<thead>
<tr><th data-toggle="tooltip" title="İŞLEM TARİHİ"><a href="lg_hareket.php?cariid=<?php echo $CARIID;?>">TARİH</a></th>

<th data-toggle="tooltip" title="İŞLEM DURUMU" >KODU</th>
<th data-toggle="tooltip" title="İŞLEM DURUMU" >AÇIKLAMA</th>
<th data-toggle="tooltip" title="">MİKTAR</th>
<th data-toggle="tooltip" title="">FİYAT</th>
<th data-toggle="tooltip" title="">NET</th>
<th data-toggle="tooltip" title="">TOPLAM</th>
</tr></thead>
<tbody>
<?php 
$toplam1=0;
$toplam2=0;
$toplam3=0;
$nakita=0;
$nakitb=0;
$ceka=0;
$cekb=0;
$seneta=0;
$senetb=0;
$alisa=0;
$alisb=0;
$satisa=0;
$satisb=0;

$stmt = $dbh->prepare("SELECT SH.DATE_,SH.AMOUNT,SH.PRICE,SH.TOTAL,SH.DISTDISC, S.CODE,S.NAME
FROM         ".$firmadonem."INVOICE F LEFT JOIN
                     ".$firmadonem."STLINE  SH ON F.LOGICALREF = SH.INVOICEREF LEFT JOIN ".$firma."ITEMS S ON S.LOGICALREF=SH.STOCKREF
WHERE F.CLIENTREF = :cariid AND F.TRCODE = :trcode AND F.LOGICALREF = :ref AND SH.LINETYPE=0");
$stmt->execute([':cariid' => $CARIID, ':trcode' => $trcode, ':ref' => $REF]);
    while($rowx=$stmt->fetch(PDO::FETCH_ASSOC))
 {


echo  '<tr style="border-top: 1px solid #000;">
<td>'.tarihcevir($rowx['DATE_']).'</td>
<td>'.$rowx['CODE'].'</td>
<td>'.$rowx['NAME'].'</td>
<td style="text-align:right">'.kusuratadet($rowx['AMOUNT']).'</td>
<td style="text-align:right">'.kusuratpara($rowx['PRICE']).'</td>
<td style="text-align:right">'.kusuratpara(($rowx['TOTAL']-$rowx['DISTDISC'])/$rowx['AMOUNT']).'</td>
<td style="text-align:right">'.kusuratpara($rowx['TOTAL']).'</td>

</tr>' ;
} 


?>
</tbody></table></div> </div></div>
		


	
<!--<body onLoad="window.print(); ">-->
		

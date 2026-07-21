<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");
// Yetki kontrolü: Fiyat değişikliği yetkisi (admin VEYA ST2).
// KRİTİK DÜZELTME (2026-07-13): $terminalyetki tanımsızdı (daima null), bu yüzden
// "$terminalyetki != 0" false kalıp AND kısa devre yapıyor ve ST2 kontrolü hiç
// uygulanmıyordu — yetkisiz kullanıcı fiyat güncelleyebiliyordu. Doğru değişken
// $yetkidurum. Fail-closed.
if ((int) ($yetkidurum ?? 1) !== 0 && m_p_yetki($terminalkullanici, 'ST2') != 1) {
  header('Location: ' . APP_ROOT_URL . '/index.php');
  exit;
}

//FİYAT GÜNCELLME FORMDAN GELEN
if(isset($_POST['yeni_fiyat'])){
  // CSRF koruması
  if (!csrf_verify()) {
    http_response_code(403);
    die("Geçersiz güvenlik doğrulaması.");
  }

  $fiyat = isset($_POST['stkfyt']) ? (string)virgul($_POST['stkfyt']) : '0';
  $fiyat_id = isset($_POST['yeni_fiyat']) ? (int)$_POST['yeni_fiyat'] : 0;
  $grup_id = isset($_POST['fgrup']) ? (int)$_POST['fgrup'] : 0;

  $stmt = $dbh->prepare("UPDATE ".$firma."PRCLIST SET PRICE = :fiyat, PAYPLANREF = :grup_id WHERE LOGICALREF = :fiyat_id AND PTYPE = 2");
  $stmt->execute([':fiyat' => $fiyat, ':grup_id' => $grup_id, ':fiyat_id' => $fiyat_id]);

  echo '<script>window.location="../stok/stok_tara.php?link=fiyat_guncelle.php";</script>';
  exit;

}
//***************FİYAT GÜNCELLME FORMDAN GELEN********************


//FİYAT ekleme FORMDAN GELEN
if(isset($_POST['yeni_fiyat_ekle'])){
  // CSRF koruması
  if (!csrf_verify()) {
    http_response_code(403);
    die("Geçersiz güvenlik doğrulaması.");
  }
  $fiyat = isset($_POST['stkfyt']) ? (string)virgul($_POST['stkfyt']) : '0';
  $stokid = isset($_POST['yeni_fiyat_ekle']) ? (int)$_POST['yeni_fiyat_ekle'] : 0;
$grup_id = isset($_POST['fgrup']) ? (int)$_POST['fgrup'] : 0;

 $birimlerX=birim_bul($stokid);
$birimno= isset($birimlerX[0]) ? (int)$birimlerX[0] : 0;
 $stmtSon = $dbh->prepare("SELECT TOP 1 LOGICALREF FROM ".$firma."PRCLIST ORDER BY LOGICALREF DESC");
 $stmtSon->execute();
 $sipf = $stmtSon->fetch(PDO::FETCH_ASSOC);
 // 1 EKLE id oluşssun
 $sonfiyatid = $sipf ? (int)$sipf['LOGICALREF'] : 0;

$stmtFiyatEkle = $dbh->prepare("INSERT INTO ".$firma."PRCLIST( 
[CARDREF]
      ,[CLIENTCODE]
      ,[CLSPECODE]
      ,[PAYPLANREF]
      ,[PRICE]
      ,[UOMREF]
      ,[INCVAT]
      ,[CURRENCY]
      ,[PRIORITY]
      ,[PTYPE]
      ,[MTRLTYPE]
      ,[LEADTIME]
      ,[BEGDATE]
      ,[ENDDATE]
      ,[CONDITION]
      ,[SHIPTYP]
      ,[SPECIALIZED]
      ,[CAPIBLOCK_CREATEDBY]
      ,[CAPIBLOCK_CREADEDDATE]
      ,[CAPIBLOCK_CREATEDHOUR]
      ,[CAPIBLOCK_CREATEDMIN]
      ,[CAPIBLOCK_CREATEDSEC]
      ,[CAPIBLOCK_MODIFIEDBY]

      ,[CAPIBLOCK_MODIFIEDHOUR]
      ,[CAPIBLOCK_MODIFIEDMIN]
      ,[CAPIBLOCK_MODIFIEDSEC]
	   ,[SITEID]
      ,[RECSTATUS]
      ,[ORGLOGICREF]
      ,[WFSTATUS]
      ,[UNITCONVERT]
      ,[EXTACCESSFLAGS]
      ,[CYPHCODE]
      ,[ORGLOGOID]
      ,[TRADINGGRP]

	  ,[BEGTIME]
      ,[ENDTIME]
      ,[DEFINITION_]
      ,[CODE]
      ,[GRPCODE]
      ,[ORDERNR]
      ,[GENIUSPAYTYPE]
      ,[GENIUSSHPNR]
      ,[PRCALTERTYP1]
      ,[PRCALTERLMT1]
      ,[PRCALTERTYP2]
      ,[PRCALTERLMT2]
      ,[PRCALTERTYP3]
      ,[PRCALTERLMT3]
      ,[ACTIVE]
      ,[PURCHCONTREF]
      ,[BRANCH]
      ,[COSTVAL]
      ,[CLTRADINGGRP]
      ,[CLCYPHCODE]
      ,[CLSPECODE2]
      ,[CLSPECODE3]
      ,[CLSPECODE4]
      ,[CLSPECODE5]


	         )
VALUES (
:stokid,
'',
'',
:grup_id,
:fiyat,
:birimno,
0,
160,
0,
2,
0,
0,
DATEADD(yy, DATEDIFF(yy,0,getdate()), 0),
DATEADD(dd,-1,DATEADD(yy,0,DATEADD(yy,DATEDIFF(yy,0,getdate())+1,0))),
'',
'',
0,
1,
GETDATE(),
:saat,
:dakika,
:saniye,
0,
0,
0,
0,
0,
1,
0,
0,
0,
0,
'',
'',
'',
0,
:kayitsaat,
'',
:sonfiyatid,
'',
0,
'',
0,0,0,0,0,0,0,0,0,
'-1',
0,
'',
'',
'',
'',
'',
''

)");
$stmtFiyatEkle->execute([':stokid' => $stokid, ':grup_id' => $grup_id, ':fiyat' => $fiyat, ':birimno' => $birimno, ':saat' => $saat, ':dakika' => $dakika, ':saniye' => $saniye, ':kayitsaat' => $kayitsaat, ':sonfiyatid' => $sonfiyatid]);

//echo '<script>window.location="../stok/stok_tara.php?link=fiyat_guncelle.php";</script>';

}
//***************FİYAT ekleme FORMDAN GELEN********************
?>
<body >
 <div align="center" class="meydan">
<a class="mavi" href="../stok/stok_tara.php?link=fiyat_guncelle.php"> İPTAL ET </a>
<?PHP
//********************FİYATLARI FORMA AKTARMA***********************
if(isset($_GET['fiyatid'])){
$fiyat= isset($_GET['fiyat']) ? (string)$_GET['fiyat'] : '';
  $fiyat_id = isset($_GET['fiyatid']) ? (int)$_GET['fiyatid'] : 0;
$grup_id = isset($_GET['grup']) ? (int)$_GET['grup'] : 0;

?>
<table ><thead>
<tr>
<form name="frm" id="frm" method="POST" action=""  onSubmit="return bak()">
<?php echo csrf_field(); ?>
<input type="hidden" name="yeni_fiyat" value="<?php echo $fiyat_id;?>">
 <th>
GRUP:<br>
 <select value=""  name="fgrup" id="fgrup" >
 <option value="<?php echo $grup_id; ?>" selected><?php echo $grup_id;?></option>
<?PHP 
$grup = $dbh->prepare("SELECT LOGICALREF,CODE FROM ".$firma."PAYPLANS");
$grup->execute();
while($grupliste=$grup->fetch(PDO::FETCH_ASSOC))
{


?>
<option value="<?php echo $grupliste['LOGICALREF']; ?>" ><?php echo $grupliste['CODE'];?></option>
<?php }
?>
 </th>
<th>
FİYATI:<br><input type="text" name="stkfyt" id="stkfyt" style="width:50px;" value="<?php echo  $fiyat;?>"><button class="blue" type="submit">düzenle</button></th></tr>


</th><tr></thead></Table>
</form>
<?php
}
//********************FİYATLARI FORMA AKTARMA***********************
 ?>



 <?PHP
//********************YENİ FİYAT EKLE***********************
if(isset($_GET['yenifiyat'])){
$stok= isset($_GET['stok']) ? (int)$_GET['stok'] : 0;


?>
<table ><thead>
<tr>
<form name="frm" id="frm" method="POST" action=""  onSubmit="return bak()">
<?php echo csrf_field(); ?>
<input type="hidden" name="yeni_fiyat_ekle" value="<?php echo $stok;?>">
 <th>
GRUP:<br>
 <select value=""  name="fgrup" id="fgrup" >
 <option value="" selected></option>
<?PHP 
$grup = $dbh->prepare("SELECT LOGICALREF,CODE FROM ".$firma."PAYPLANS");
$grup->execute();
while($grupliste=$grup->fetch(PDO::FETCH_ASSOC))
{


?>
<option value="<?php echo $grupliste['LOGICALREF']; ?>" ><?php echo $grupliste['CODE'];?></option>
<?php }
?>
 </th>
<th>
FİYATI:<br><input type="text" name="stkfyt" id="stkfyt" style="width:50px;" value=""><button class="blue" type="submit">fiyat ekle</button></th></tr>


</th><tr></thead></Table>
</form>
<?php
}
//********************YENİ FİYAT EKLE***********************
 ?>

	<table ><thead>
<?php if(isset($_GET['stok'])){
$stokid = (int)$_GET['stok'];

}else{exit;}
		$stokarax = $dbh->prepare("SELECT F.LOGICALREF,F.PRICE,G.CODE,G.LOGICALREF AS COKLUFIYATID FROM ".$firma."PRCLIST F LEFT JOIN ".$firma."PAYPLANS G ON F.PAYPLANREF=G.LOGICALREF WHERE F.CARDREF = :stokid AND  F.PTYPE=2");
		$stokarax->execute([':stokid' => $stokid]);
		 while($stokara=$stokarax->fetch(PDO::FETCH_ASSOC))
		 {
		 ?>
	<tr>
 <td ><?php echo $stokara['CODE'];?></td>
<td ><a href="fiyat_guncelle.php?fiyat=<?php echo kusuratpara($stokara['PRICE']);?>&grup=<?php echo $stokara['COKLUFIYATID'];?>&fiyatid=<?php echo $stokara['LOGICALREF'];?>"><?php echo kusuratpara($stokara['PRICE']);?></a></td></tr>


	<?php	 
	 }




?>
</thead></Table>


<a class="mavi" href="fiyat_guncelle.php?yenifiyat=evet&stok=<?php echo $stokid;?>"> YENİ FİYAT EKLE </a>

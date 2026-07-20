<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
header("Content-Type: text/html; charset=utf-8");
date_default_timezone_set('Europe/Istanbul');
include_once(__DIR__ . "/_baglanti_.inc");
include_once(__DIR__ . "/_bilgi_.inc");
try {


  $SET = 'UTF-8';
  $dbh = new PDO("sqlsrv:server=$anamakina;database=$veritabani;", $kullanici, $sifre);
} catch (PDOException $e) {
  echo "büyük hata veri güvenliği için aynı işlemi tekrarlamayın: " . $e->getMessage();
  die();
}
/*try{

$dbh = new PDO("odbc:logodbc", 'logo', 'logo');

}catch (PDOException $e) {
echo "büyük hata veri güvenliği için aynı işlemi tekrarlamayın: " . $e->getMessage();
die();
}*/


$dbh->exec("SET NAMES latin5");
$dbh->exec('SET CHARACTER SET TURKISH_CI_AS');
ini_set('mssql.charset', 'TURKISH_CI_AS');


$tarih = date("Y.m.d");
$saat = date("H");
$dakika = date("i");
$saniye = date("s");

$xsaat = date('H');
$xdakika = date('i');
$xsaniye = date('s');
$kayitsaat = ($xsaniye * 256) + ($xdakika * 65536) + ($xsaat * 16777216);

function tlgoster(float|int|string|null $kusurat): string
{
  global $parakusurat;
  if ($kusurat == "") {
    $kusurat = 0;
  }
  return number_format($kusurat, 2, ',', '.');
}

function kusuratadet(float|int|string|null $kusurata): string
{
  global $adetkusurat;
  if ($kusurata == "") {
    $kusurata = 0;
  }
  return str_replace(',', '', number_format($kusurata, $adetkusurat));
}
function kusuratsifir(float|int|string|null $kusurata): string
{
  //return number_format($kusurata,0);
  return str_replace(',', '', number_format($kusurata, 0));
}
function intcevir(mixed $intsayi): int
{
  return intval($intsayi);
}
function flcevir(mixed $intsayi): float
{
  return floatval($intsayi);
}
function virgul(string|int|float|null $virgul): string
{

  return str_replace(",", ".", $virgul);
}

function trcevir(mixed $bilgi): mixed
{
  //return iconv('ISO-8859-9', 'UTF-8', $bilgi);
  return $bilgi;
}
function tarihcevir(string|int|float|null $tarih): string
{
  return date("d.m.Y", strtotime((string) $tarih));
}
function cari_mail_bul(int|string $stokhareket): string
{
  global $dbh;
  global $firma;
  global $firmadonem;
  $stmt = $dbh->prepare("SELECT C.EMAILADDR
    FROM " . $firmadonem . "ORFICHE F
    LEFT JOIN " . $firma . "CLCARD C ON F.CLIENTREF=C.LOGICALREF
    WHERE F.LOGICALREF = :stokhareket");
  $stmt->execute([':stokhareket' => (int) $stokhareket]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);


  return $bul['EMAILADDR'] ?? '';

}
function cari_bul(int|string $cariid): string
{
  global $dbh;
  global $firma;
  $sql = "
    SELECT CODE, DEFINITION_
    FROM {$firma}CLCARD
    WHERE LOGICALREF = :ref
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':ref' => $cariid]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return $bul['DEFINITION_'] ?? '';
}
function odemegrup_bul(string $kodu): int
{
  global $dbh;
  global $firma;
  $sql = "
    SELECT LOGICALREF
    FROM {$firma}PAYPLANS
    WHERE CODE = :code AND ACTIVE = 0
    ORDER BY LOGICALREF DESC
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':code' => $kodu]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return $bul['LOGICALREF'] ?? 0;
}
function doviz_bul(string|int|float|null $doviz): float
{
  global $dbh;
  $sql = "
    SELECT RATES1
    FROM L_DAILYEXCHANGES
    WHERE CRTYPE = :type
    ORDER BY LREF DESC
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':type' => $doviz]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return $bul['RATES1'] ?? 0;
}
function dovizkuru_bul(int|string $stokhareket): float
{
  global $dbh;
  global $firmadonem;
  $sql = "
    SELECT TRRATE
    FROM {$firmadonem}ORFICHE
    WHERE LOGICALREF = :ref
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':ref' => $stokhareket]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return $bul['TRRATE'] ?? 0;
}
function dovizsembol_bul(string|int|float|null $doviz): string
{
  global $dbh;
  $sql = "
    SELECT CURSYMBOL
    FROM L_CURRENCYLIST
    WHERE CURTYPE = :type
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':type' => $doviz]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return $bul['CURSYMBOL'] ?? '';
}
function guid(): string|false
{
  if (function_exists('com_create_guid')) {
    return com_create_guid();
  }
  mt_srand((double) microtime() * 10000);
  //optional for php 4.2.0 and up.
  $charid = strtolower(md5(uniqid(random_int(0, mt_getrandmax()), true)));
  $hyphen = chr(45);
  // "-"
  $uuid =
    substr($charid, 0, 8) . $hyphen
    . substr($charid, 8, 4) . $hyphen
    . substr($charid, 12, 4) . $hyphen
    . substr($charid, 16, 4) . $hyphen
    . substr($charid, 20, 12);
  return $uuid;
}

function turkce(string|int|float|null $text): string
{

  $search = ['Ç', 'ç', 'Ğ', 'ğ', 'ı', 'İ', 'Ö', 'ö', 'Ş', 'ş', 'Ü', 'ü'];
  $replace = ['c', 'c', 'g', 'g', 'i', 'i', 'o', 'o', 's', 's', 'u', 'u'];
  return str_replace($search, $replace, $text);
}
function turkcearama(string|int|float|null $text): string
{

  $search = ['i', 'c', 'u', 's', 'g', 'o', 'İ', 'C', 'U', 'S', 'G', 'O'];
  $replace = ['[ıi]', '[cç]', '[uü]', '[sş]', '[gğ]', '[oö]', '[Iİ]', '[CÇ]', '[UÜ]', '[SŞ]', '[GĞ]', '[OÖ]'];
  return str_replace($search, $replace, $text);
}

function bekleyen_siparis(int|string $kodu): float
{
  global $dbh;
  global $firmadonem;
  $sql = "
    SELECT SUM(FS.AMOUNT) AS TOPLAM
    FROM {$firmadonem}ORFLINE FS
    WHERE FS.STOCKREF = :code
      AND FS.TRCODE = 1
      AND FS.STATUS <> 2
      AND CASE FS.CLOSED
            WHEN 0 THEN (FS.AMOUNT - FS.SHIPPEDAMOUNT)
            WHEN 1 THEN (FS.AMOUNT - FS.AMOUNT)
          END > 0
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':code' => $kodu]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return $bul['TOPLAM'] ?? 0;
}

function birim_bul(int|string $stok_id): array
{
  global $dbh;
  global $firma;
  $sql = "
    SELECT B.LOGICALREF, B.UNITSETREF
    FROM {$firma}ITEMS S
    LEFT JOIN {$firma}UNITSETL B
      ON S.UNITSETREF = B.UNITSETREF AND B.LINENR = 1
    WHERE S.LOGICALREF = :ref
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':ref' => $stok_id]);
  $birim_bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return [$birim_bul['LOGICALREF'], $birim_bul['UNITSETREF']];
}
function tanimlialantoplam(int|string $stok_id): array
{
  global $dbh;
  global $firma;


  $sql = "
    SELECT TEXTFLDS1, TEXTFLDS2, TEXTFLDS3
    FROM {$firma}DEFNFLDSCARDV
    WHERE PARENTREF = :ref AND MODULENR = 6
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':ref' => $stok_id]);
  $tanim = $stmt->fetch(PDO::FETCH_ASSOC);



  //$alan=array($tanim['TEXTFLDS1'],$tanim['TEXTFLDS2'],$tanim['TEXTFLDS3']);
  $alan = [str_replace(",", ".", $tanim['TEXTFLDS1']), str_replace(",", ".", $tanim['TEXTFLDS2']), str_replace(",", ".", $tanim['TEXTFLDS2'])];

  return $alan;


}
function bosluksil(mixed $veri): string
{

  $veri = str_replace(" ", "", $veri);
  return trim($veri);
}
;
function iskontooran(float|int|string|null $fiyati, float|int|string|null $iskontolu, string $durum): float
{
  if ($durum == "+") {
      return ($fiyati + (($fiyati * $iskontolu) / 100));
  }
  if ($durum == "-") {
    return ($fiyati - (($fiyati * $iskontolu) / 100));
    ;
  }
  return null;

}

function stok_miktar_bul(int|string $id): float
{
  global $dbh;
  global $firmadonemx;


  $sql = "
    SELECT SUM(ONHAND) AS MIKTAR
    FROM {$firmadonemx}STINVTOT
    WHERE STOCKREF = :ref AND INVENNO = -1
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':ref' => $id]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return $bul['MIKTAR'] ?? 0;

}


function m_p_yetki(int|string $id, string $sutun): int|string|null
{
  global $dbh;

  // SQL injection önlemek için sütun whitelist
  $allowed_columns = ['YETKI', 'SIFRE', 'PERSONEL', 'M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10', 'M11', 'M12', 'M13', 'M15', 'M16', 'M17', 'M20', 'M21', 'M22', 'M23', 'ST1', 'ST2', 'ST3', 'CR1', 'CR2', 'CR3', 'CR4', 'SP1', 'SP2', 'SP3', 'SP4'];

  if (!in_array($sutun, $allowed_columns)) {
    return 0;
  }

  $sql = "
    SELECT {$sutun}
    FROM M_P_YETKI
    WHERE PERSONEL = :id
  ";
  $stmt = $dbh->prepare($sql);
  $stmt->execute([':id' => (int) $id]);
  $bul = $stmt->fetch(PDO::FETCH_ASSOC);
  return $bul[$sutun] ?? null;

}

function cokludepomiktar(int|string $stokid): void
{
  global $dbh;
  global $firma;
  global $firmano;
  global $firmadonemx;
  $durum = [];

  $renk = "light";

  $stmt = $dbh->prepare("SELECT SUM(DM.ONHAND) AS MIKTAR, D.NAME AS DEPO, L.CODE AS BIRIM, BR.CONVFACT2 AS ICBIRIM
	FROM " . $firmadonemx . "STINVTOT AS DM
	LEFT OUTER JOIN " . $firma . "ITEMS S ON DM.STOCKREF = S.LOGICALREF
	LEFT JOIN " . $firma . "ITMUNITA BR  ON S.LOGICALREF = BR.ITEMREF AND BR.LINENR = 2
	LEFT JOIN " . $firma . "UNITSETL L  ON S.UNITSETREF = L.UNITSETREF AND L.LINENR = 1
	LEFT JOIN L_CAPIWHOUSE D ON DM.INVENNO = D.NR AND D.FIRMNR = :firmano
	WHERE DM.STOCKREF = :stokid AND DM.INVENNO > -1
	GROUP BY S.LOGICALREF, S.CODE, S.NAME, DM.INVENNO, D.NAME, L.CODE, BR.CONVFACT2");
  $stmt->execute([':firmano' => (int) $firmano, ':stokid' => (int) $stokid]);



  while ($tanim = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $koli = 0;
    if ($tanim['ICBIRIM']) {
      $koli = $tanim['MIKTAR'] / $tanim['ICBIRIM'];
    }
    $renk = $tanim['MIKTAR'] < 1 ? "warning" : "secondary";

    //echo $durum[] = '<div class="col-4 btn btn-warning border-dark shadow">'.$tanim['DEPO'].' <br> '.kusuratadet($tanim['MIKTAR']) .'</div> ';
//echo $durum[] = $tanim['KODU'].' = '.kusuratadet($tanim['MIKTAR']) .' , ';

    echo $durum[] = '<div class="lg-12"><div class=" badge badge-' . $renk . ' "><i class="fa fa-cubes"></i> ' . $tanim['DEPO'] . ' ' . kusuratsifir($tanim['MIKTAR']) . ' <span class="badge badge-light "> ' . kusuratadet($koli) . '</span></div></div>';

    /*
     $durum[] = $tanim['DEPO'].'='.kusuratadet($tanim['MIKTAR']);
     $durum[] = kusuratadet($tanim['MIKTAR']);
     $durum[] = $tanim['BIRIM'];
     $durum[] = $tanim['ICBIRIM'];
     */
  }

}
?>
<html>

<head>
  <title>Lumen</title>
  <meta charset="utf-8">
  <meta name="viewport" content="initial-scale=1.0">
  <script src="assets/js/jquery.js"></script>
  <script src="assets/js/bootstrap.bundle.min.js"></script>
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
  <META http-equiv=content-type content=text/html;charset=iso-8859-9>
  <META http-equiv=content-type content=text/html;charset=windows-1254>


  <!-- Chrome, Firefox OS ve Opera -->
  <meta name="theme-color" content="#CA352B">
  <!-- Windows Phone -->
  <meta name="msapplication-navbutton-color" content="#CA352B">
  <!-- iOS Safari -->
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="CA352B">
</head>

<script type="text/javascript">
  function gizle() {
    document.getElementById("klavye").style.display = 'none';
  }
  function goster() {
    if (document.getElementById("klavye").style.display == 'none') {
      document.getElementById("klavye").style.display = '';
    }
    else if (document.getElementById("klavye").style.display == '') {
      document.getElementById("klavye").style.display = 'none';
    }
  }
  function bak() {
    if (document.getElementById("stkadt").value == "") {
      alert("miktar kısmı boş olamaz!!!");
      return false;
    }
  }
</script>
<script type="text/javascript">

function yaz(a) {
    document.ara.arabul.value = document.ara.arabul.value + a;
    document.getElementById("arabul").focus()

  }

  function silem() {

    document.ara.arabul.value = document.ara.arabul.value.substring(0, document.ara.arabul.value.length - 1);
    document.getElementById("arabul").focus()
  }
</script>

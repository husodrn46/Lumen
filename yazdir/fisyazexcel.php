<?php
declare(strict_types=1);

header('Content-type: text/html; charset=ISO-8859-9');
include_once(__DIR__ . "/../ayr.php");

require_once __DIR__ . '/../kontrol.php';

if (!isset($_GET['stokhareket'])) {
    echo '<div class="notification msgerror">Lütfen bir fiş numarası belirtin.</div>';
    exit;
}

$stokhareket = (int)$_GET['stokhareket'];
$tarih       = date('Y-m-d');  // Bugünün tarihini aldık

// Yazdırma işlemini logla
$stmtFisNoLog = $dbh->prepare("SELECT FICHENO FROM ".$firmadonem."ORFICHE WHERE LOGICALREF = :stokhareket");
$stmtFisNoLog->execute([':stokhareket' => $stokhareket]);
$fisNoLog = $stmtFisNoLog->fetch(PDO::FETCH_ASSOC);
if (function_exists('logYazdir') && $fisNoLog) {
    logYazdir($stokhareket, $fisNoLog['FICHENO'], 'EXCEL', $terminalkullanici, 'Excel olarak dışa aktarıldı');
}

// Excel çıktısı için header
header("Content-Type: application/vnd.ms-excel");
header("Content-disposition: attachment; filename=siparis-$tarih.xls");
?>

<script src="/tm/js/jquery-3.7.1.min.js"></script>
<script>
$(function () {
    $("#btnExport").click(function (e) {
        window.open('data:application/vnd.ms-excel,' + $('#dvData').html());
        e.preventDefault();
    });
});
</script>

<input type="button" id="btnExport" value=" Export Table data into Excel " />
<br/>
<br/>

<div id="dvData">
    <table>
        <tr style="background:blue;color:white;">
            <th>BARKOD</th>
            <th>STOK ADI</th>
            <th>MİKTAR</th>
            <th>BİRİM</th>
            <th>FİYAT</th>
            <th>TUTAR</th>
        </tr>

        <?php
        $hareketFisToplami = 0;
        $toplam             = 0;
        $gtoplam            = 0;
        $gisk               = 0;
        $i                  = 0;
        $silmes             = "seçtiniz ürün silinecek";

        $sql = "
            SELECT
                {$firmadonem}ORFLINE.PRICE,
                {$firmadonem}ORFLINE.TOTAL,
                {$firmadonem}ORFLINE.VAT,
                {$firmadonem}ORFLINE.VATAMNT,
                {$firmadonem}ORFLINE.LINENO_,
                {$firmadonem}ORFLINE.AMOUNT,
                {$firmadonem}ORFLINE.VATMATRAH,
                {$firmadonem}ORFLINE.STOCKREF,
                {$firmadonem}ORFLINE.LINEEXP,
                {$firmadonem}ORFLINE.LOGICALREF,
                {$firmadonem}ORFLINE.ORDFICHEREF,
                {$firma}ITEMS.CODE        AS SKODU,
                {$firma}ITEMS.NAME        AS SADI,
                {$firma}UNITSETL.CODE     AS BIRIM,
                {$firma}UNITBARCODE.BARCODE AS BARKOD
            FROM {$firmadonem}ORFLINE
            LEFT JOIN {$firma}ITEMS
                ON {$firmadonem}ORFLINE.STOCKREF = {$firma}ITEMS.LOGICALREF
            LEFT JOIN {$firma}UNITSETL
                ON {$firmadonem}ORFLINE.UOMREF = {$firma}UNITSETL.LOGICALREF
            LEFT JOIN {$firma}UNITBARCODE
                ON {$firmadonem}ORFLINE.STOCKREF = {$firma}UNITBARCODE.ITEMREF
            WHERE {$firmadonem}ORFLINE.ORDFICHEREF = :stokhareket
              AND LINETYPE = 0
        ";

        $list = $dbh->prepare($sql);
        $list->execute([':stokhareket' => $stokhareket]);

        while ($liste = $list->fetch(PDO::FETCH_ASSOC)) {
            // Satır toplamı (net + KDV)
            $toplam = $liste['TOTAL'] + $liste['VATAMNT'];

            echo '<tr style="background:#ccc;">';
            echo '<td>' . $liste['BARKOD'] . '</td>';
            echo '<td>' . $liste['SKODU'] . '</td>';
            echo '<td>' . trcevir($liste['SADI']) . '</td>';
            echo '<td>' . kusuratadet($liste['AMOUNT']) . '</td>';
            echo '<td>' . $liste['BIRIM'] . '</td>';
            echo '<td>' . number_format((float)$liste['PRICE'], 2, ',', '.') . '</td>';
            echo '<td>' . kusuratpara($toplam) . '</td>';
            echo '</tr>';
        }
        ?>
    </table>
</div>

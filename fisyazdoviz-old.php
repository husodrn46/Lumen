<?php
declare(strict_types=1);

include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");
$doviz = $_SESSION['doviz'];

if (!isset($_GET['stokhareket'])) {
    echo '<div class="alert alert-danger text-center">Please specify an order number.</div>';
    exit;
}

$stokhareket = (int) $_GET['stokhareket'];
if ($stokhareket <= 0) {
    echo '<div class="alert alert-danger text-center">Invalid order number.</div>';
    exit;
}

$stmtFis = $dbh->prepare("
    SELECT CLIENTREF, DATE_, GENEXP1, TOTALVAT, NETTOTAL, GROSSTOTAL, FICHENO, TOTALDISCOUNTS
    FROM {$firmadonem}ORFICHE
    WHERE LOGICALREF = :stokhareket
");
$stmtFis->execute([':stokhareket' => $stokhareket]);
$listfis = $stmtFis->fetch(PDO::FETCH_ASSOC);

$cariid = intcevir($listfis['CLIENTREF']);
$stmtCari = $dbh->prepare("
    SELECT CLCARD.DEFINITION_ AS UNVANI
    FROM {$firma}CLCARD CLCARD
    WHERE CLCARD.LOGICALREF = :cariid
");
$stmtCari->execute([':cariid' => $cariid]);
$sqlx = $stmtCari->fetch(PDO::FETCH_ASSOC);

$dovizfiyat  = dovizkuru_bul($stokhareket);
$dovizsembol = dovizsembol_bul($doviz);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Foreign Currency Sales Order</title>
  <!-- Google Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700&family=Roboto:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --primary: #007bff;
      --secondary: #6c757d;
      --bg: #f8f9fa;
      --text: #212529;
    }
    * { box-sizing: border-box; margin:0; padding:0 }
    body {
      font-family: 'Roboto', sans-serif;
      background: var(--bg);
      color: var(--text);
      line-height: 1.5;
    }
    .container {
      max-width: 1000px;
      margin: 30px auto;
      padding: 0 15px;
    }
    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #fff;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }
    .header img { max-height: 60px; }
    .header .title {
      font-family: 'Montserrat', sans-serif;
      font-size: 1.75rem;
      color: var(--primary);
      flex-grow: 1;
      text-align: center;
    }
    .actions button {
      font-size: 0.9rem;
      font-weight: 500;
      padding: 8px 16px;
      margin-left: 8px;
      border: 2px solid var(--primary);
      background: transparent;
      color: var(--primary);
      border-radius: 4px;
      cursor: pointer;
      transition: all .2s;
    }
    .actions button:hover {
      background: var(--primary);
      color: #fff;
    }
    .table-wrapper {
      margin-top: 30px;
      overflow-x: auto;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      background: #fff;
      border-radius: 8px;
      overflow: hidden;
      box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    }
    th, td {
      padding: 12px 15px;
      font-size: 0.9rem;
      border-bottom: 1px solid #ececec;
    }
    th {
      background: var(--primary);
      color: #fff;
      text-align: left;
    }
    tr:nth-child(even) td { background: #f1f5f9; }
    tr:hover td { background: #e2e8f0; }
    .numeric-right { text-align: right; }
    .numeric-center { text-align: center; }
    .summary-card {
      margin-top: 30px;
      float: right;
      width: 320px;
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      padding: 20px;
    }
    .summary-card .row {
      display: flex;
      justify-content: space-between;
      margin-bottom: 12px;
      font-size: 0.95rem;
    }
    .summary-card .row.total {
      font-family: 'Montserrat', sans-serif;
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--primary);
    }
    .note {
      clear: both;
      margin-top: 40px;
      background: #fff;
      padding: 15px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.05);
      font-size: 0.9rem;
    }
    @media print {
      .actions, .header, .summary-card, .note { page-break-inside: avoid; }
      .actions { display: none; }
    }
  </style>
</head>
<body>
  <div class="container">

    <div class="header">
      <img src="logo.png" alt="Lumen">
      <div class="title">Foreign Currency Sales Order</div>
      <div class="actions">
        <button onclick="window.print()">Print</button>
        <button id="saveImg">Save as Image</button>
      </div>
    </div>

    <!-- Order Info -->
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Order No</th>
            <th>Customer Name</th>
            <th>Exchange Rate</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><?php echo tarihcevir($listfis['DATE_']); ?></td>
            <td><?php echo htmlspecialchars((string) $listfis['FICHENO']); ?></td>
            <td><?php echo htmlspecialchars((string) $sqlx['UNVANI']); ?></td>
            <td><?php echo $dovizsembol . ' ' . kusuratpara($dovizfiyat); ?></td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Details -->
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Item Code</th>
            <th>Item Name</th>
            <th class="numeric-right">Quantity</th>
            <th class="numeric-center">Cases</th>
            <th>Unit</th>
            <th class="numeric-right">Price (Curr.)</th>
            <th class="numeric-right">Net Price</th>
            <th class="numeric-right">Amount (Curr.)</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $stmtLines = $dbh->prepare("
            SELECT
              O.LINENO_,
              O.AMOUNT,
              O.PRICE,
              CASE WHEN O.AMOUNT<>0 THEN O.VATMATRAH/O.AMOUNT ELSE 0 END AS NETFIYAT,
              O.TOTAL,
              O.VATAMNT,
              I.CODE AS SKODU,
              I.NAME AS SADI,
              U.CODE AS BIRIM,
              ui.koli_ici
            FROM {$firmadonem}ORFLINE O
            LEFT JOIN {$firma}ITEMS    I  ON O.STOCKREF = I.LOGICALREF
            LEFT JOIN {$firma}UNITSETL U  ON O.UOMREF   = U.LOGICALREF
            LEFT JOIN (
              SELECT ITEMREF, MAX(CONVFACT2) AS koli_ici
              FROM LG_001_ITMUNITA
              GROUP BY ITEMREF
            ) ui ON ui.ITEMREF = I.LOGICALREF
            WHERE O.ORDFICHEREF = :stokhareket AND O.LINETYPE=0
          ");
          $stmtLines->execute([':stokhareket' => $stokhareket]);
          while ($row = $stmtLines->fetch(PDO::FETCH_ASSOC)) {
            $toplam = $row['TOTAL'] + $row['VATAMNT'];
            $cases  = ceil($row['AMOUNT'] / ($row['koli_ici'] ?: 1));
            echo '<tr>';
              echo '<td>' . htmlspecialchars((string) $row['LINENO_']) . '</td>';
              echo '<td>' . htmlspecialchars((string) $row['SKODU'])    . '</td>';
              echo '<td>' . htmlspecialchars((string) $row['SADI'])     . '</td>';
              echo '<td class="numeric-right">' . kusuratadet($row['AMOUNT']) . '</td>';
              echo '<td class="numeric-center">' . $cases . '</td>';
              echo '<td>' . htmlspecialchars((string) $row['BIRIM'])    . '</td>';
              echo '<td class="numeric-right">'
                     . $dovizsembol . ' ' . kusuratadet($row['PRICE'] / $dovizfiyat)
                   . '</td>';
              echo '<td class="numeric-right">'
                     . $dovizsembol . ' ' . kusuratadet($row['NETFIYAT'] / $dovizfiyat)
                   . '</td>';
              echo '<td class="numeric-right">'
                     . $dovizsembol . ' ' . kusuratadet($toplam / $dovizfiyat)
                   . '</td>';
            echo '</tr>';
          }
          ?>
        </tbody>
      </table>
    </div>

    <!-- Summary -->
    <div class="summary-card">
      <div class="row">Total (Curr.):</div>
      <div class="row total">
        <?php echo $dovizsembol . ' ' . kusuratpara($listfis['NETTOTAL'] / $dovizfiyat); ?>
      </div>
    </div>

    <!-- Note -->
    <div class="note">
      <strong>Note:</strong> <?php echo htmlspecialchars((string) $listfis['GENEXP1']); ?>
    </div>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script>
    document.getElementById('saveImg').addEventListener('click', () => {
      html2canvas(document.querySelector('.container'), { scale:2 }).then(canvas => {
        const link = document.createElement('a');
        link.download = 'order.png';
        link.href = canvas.toDataURL();
        link.click();
      });
    });
  </script>
</body>
</html>

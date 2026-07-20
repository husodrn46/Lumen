<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/log_ip.php");
include_once(__DIR__ . "/ayr.php");

// 2025 dönemi için eski tabloları kullan
$firmadonem = $eskifirmadonem;

// Parametreler
if (isset($_GET['cari_id']) && (isset($_GET['CSREF']) || isset($_GET['REF']))) {
    $CARIID = (int) $_GET['cari_id'];
    $CSREF  = isset($_GET['CSREF']) ? (int) $_GET['CSREF'] : (int) $_GET['REF'];
} else {
    exit('Parametre eksik.');
}

function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat;
    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0;
    }
    return number_format((float)$kusurat, $parakusurat, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="tr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Çek / Senet Detayı (2025)</title>
    <style>
        :root {
            --bg: #f3f4f6;
            --card: #ffffff;
            --border: #e5e7eb;
            --text: #0f172a;
            --muted: #6b7280;
            --primary: #0f766e;
            --primary-hover: #115e57;
            --danger: #ef4444;
            --amber: #f97316;
            --success: #16a34a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f3f4f6;
            color: #0f172a;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 50;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            padding: .6rem 1.2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
        }
        .topbar-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: .9rem;
        }
        .donem-badge {
            background-color: #fef3c7;
            color: #92400e;
            padding: 3px 10px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            background: #0f766e;
            color: #fff;
            border: none;
            border-radius: .5rem;
            padding: .4rem .75rem;
            font-size: .75rem;
            cursor: pointer;
            text-decoration: none;
        }
        .btn:hover { background: #115e57; color: #fff; }
        .btn.back { background: #e2e8f0; color: #0f172a; }
        .btn.back:hover { background: #cbd5f5; }
        .page-container {
            max-width: 1100px;
            margin: 1.4rem auto 2.6rem;
            padding: 0 1rem;
            display: flex;
            flex-direction: column;
            gap: 1.2rem;
        }
        .header { display: flex; gap: 1rem; align-items: center; }
        .header img { height: 48px; }
        .header .title { font-size: 1.1rem; font-weight: 600; }
        .kpi {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: .75rem;
        }
        .kpi .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: .75rem;
            padding: .6rem .75rem;
        }
        .kpi .k {
            font-size: .6rem;
            text-transform: uppercase;
            color: #6b7280;
            letter-spacing: .035em;
            margin-bottom: .25rem;
        }
        .kpi .v { font-size: .85rem; font-weight: 600; }
        .badge {
            display: inline-block;
            padding: .15rem .45rem;
            border-radius: 9999px;
            font-size: .6rem;
            margin-left: .35rem;
        }
        .bad-red { background: rgba(239,68,68,.1); color: #b91c1c; }
        .bad-amber { background: rgba(249,115,22,.13); color: #c05621; }
        .bad-green { background: rgba(22,163,74,.13); color: #166534; }
        .table-responsive {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: .75rem;
            overflow: hidden;
        }
        .info-table, .details-table { width: 100%; border-collapse: collapse; }
        .info-table td,
        .details-table td,
        .details-table th {
            padding: .45rem .6rem;
            font-size: .7rem;
            border-bottom: 1px solid #edf2f7;
        }
        .info-table tr:nth-child(even),
        .details-table tbody tr:nth-child(even) { background: #f9fafb; }
        .info-table td:first-child { font-weight: 500; color: #6b7280; width: 110px; }
        .details-table thead { background: #f8fafc; }
        .details-table th { text-align: left; font-weight: 600; font-size: .7rem; color: #0f172a; }
        .details-table td.center,
        .details-table th.center { text-align: center; }
        .info-table .numeric { text-align: right; }
        @media print {
            .topbar, .btn { display: none !important; }
            body { background: #fff; }
            .page-container { margin: 0; max-width: 100%; }
            .table-responsive { border: 1px solid #000; }
        }
    </style>
</head>

<body>

    <div class="topbar">
        <div class="topbar-title">
            <span>Çek / Senet Detayı</span>
            <span class="donem-badge">2025 Dönemi</span>
        </div>
        <div class="topbar-actions">
            <a href="lg_hareket.php?cariid=<?php echo (int)$CARIID; ?>" class="btn back">← Geri Dön</a>
            <button class="btn" onclick="window.print()">PDF Olarak Kaydet</button>
        </div>
    </div>

    <div class="page-container">
        <div class="header">
            <img src="logo.png" alt="Lumen">
            <div class="title">Çek / Senet Detayı</div>
        </div>

        <?php
        $mdoviz = "₺";

        $stmtHeader = $dbh->prepare("
    WITH StatusMap(CURRSTAT, StatusText) AS (
        SELECT * FROM (VALUES
          (1,'Portföyde'),(2,'Ciro Edildi'),(3,'Teminata Verildi'),
          (4,'Tahsile Verildi'),(5,'Protestolu Tahsile Verildi'),
          (6,'İade Edildi'),(7,'Protesto Edildi'),(8,'Tahsil Edildi'),
          (9,'Kendi Çekimiz'),(10,'Borç Senedimiz'),(11,'Karşılığı Yok'),
          (12,'Tahsil Edilemiyor')
        ) v(CURRSTAT, StatusText)
    )
    SELECT
      c.LOGICALREF     AS CSREF,
      c.DOC            AS DOC,
      c.NEWSERINO      AS SERINO,
      c.BANKNAME       AS BANKA,
      SUBSTRING(c.BNBRANCHNO,6,12) AS SUBE,
      c.AMOUNT         AS TUTAR,
      c.DUEDATE        AS VADE,
      CONVERT(char(8), c.DUEDATE, 112) AS VADE_YMD,
      c.CURRSTAT,
      sm.StatusText    AS DURUMU,
      c.OWING          AS CIRO_EDEN,
      (SELECT TOP 1 cl.CODE
         FROM {$firmadonem}CSTRANS t
         JOIN {$firma}CLCARD cl ON cl.LOGICALREF = t.CARDREF
        WHERE t.CSREF = c.LOGICALREF
        ORDER BY t.LOGICALREF ASC) AS KIMDEN_CODE,
      (SELECT TOP 1 cl.DEFINITION_
         FROM {$firmadonem}CSTRANS t
         JOIN {$firma}CLCARD cl ON cl.LOGICALREF = t.CARDREF
        WHERE t.CSREF = c.LOGICALREF
        ORDER BY t.LOGICALREF ASC) AS KIMDEN_AD,
      lm.LastDate, lm.LastTrText, lm.RollNo, lm.RollTrCode, lm.BankaKodu
    FROM {$firmadonem}CSCARD c
    LEFT JOIN StatusMap sm ON sm.CURRSTAT = c.CURRSTAT
    OUTER APPLY (
        SELECT TOP (1)
            t.DATE_ AS LastDate,
            CASE t.TRCODE
              WHEN 1 THEN 'Giriş' WHEN 2 THEN 'Ciro' WHEN 3 THEN 'Ciro Edilen'
              WHEN 4 THEN 'Teminat' WHEN 5 THEN 'Tahsile Verildi' WHEN 6 THEN 'Tahsil'
              WHEN 7 THEN 'Protestolu Tahsil' WHEN 8 THEN 'İade' WHEN 9 THEN 'Protesto'
              WHEN 10 THEN 'Kendi Çekimiz' WHEN 11 THEN 'Borç Senedimiz' ELSE 'Diğer'
            END AS LastTrText,
            r.ROLLNO AS RollNo,
            r.TRCODE AS RollTrCode,
            ba.CODE  AS BankaKodu
        FROM {$firmadonem}CSTRANS t
        LEFT JOIN {$firmadonem}CSROLL r  ON r.LOGICALREF = t.ROLLREF
        LEFT JOIN {$firma}BANKACC  ba ON ba.LOGICALREF = t.CARDREF
        WHERE t.CSREF = c.LOGICALREF
        ORDER BY t.LOGICALREF DESC
    ) lm
    WHERE c.LOGICALREF = :csref
");
        $stmtHeader->execute([':csref' => $CSREF]);
        $sqlHeader = $stmtHeader->fetch(PDO::FETCH_ASSOC);

        if (!$sqlHeader) {
            echo "<p>Çek/Senet bulunamadı.</p>";
            exit;
        }

        $stmtCari = $dbh->prepare("
    SELECT DEFINITION_, CITY, TELNRS1
    FROM {$firma}CLCARD
    WHERE LOGICALREF = :cariid
");
        $stmtCari->execute([':cariid' => $CARIID]);
        $cari = $stmtCari->fetch(PDO::FETCH_ASSOC);

        // Bakiye (2025 dönemi)
        $stmtBakiye = $dbh->prepare("
    SELECT SUM((1 - L.SIGN) * L.AMOUNT) - SUM(L.SIGN * L.AMOUNT) AS BAKIYE
    FROM {$firma}CLCARD C
    LEFT JOIN {$firmadonem}CLFLINE L
      ON C.LOGICALREF = L.CLIENTREF AND L.CANCELLED = 0
    WHERE C.LOGICALREF = :cariid
");
        $stmtBakiye->execute([':cariid' => $CARIID]);
        $sqlbakiye = $stmtBakiye->fetch(PDO::FETCH_ASSOC);

        $bugun = new DateTimeImmutable(date('Y-m-d'));
        $vade  = new DateTimeImmutable(substr((string) $sqlHeader['VADE_YMD'], 0, 4) . '-' . substr((string) $sqlHeader['VADE_YMD'], 4, 2) . '-' . substr((string) $sqlHeader['VADE_YMD'], 6, 2));
        $diff  = (int) $vade->diff($bugun)->format('%r%a');
        $gecikmis = ($bugun > $vade && !in_array((int) $sqlHeader['CURRSTAT'], [8, 12]));
        ?>

        <div class="kpi">
            <div class="card">
                <div class="k">Tür</div>
                <div class="v"><?php echo ($sqlHeader['DOC'] == 1 ? 'Çek' : 'Senet'); ?></div>
            </div>
            <div class="card">
                <div class="k">Seri No</div>
                <div class="v"><?php echo htmlspecialchars((string) $sqlHeader['SERINO']); ?></div>
            </div>
            <div class="card">
                <div class="k">Vade</div>
                <div class="v">
                    <?php echo tarihcevir($sqlHeader['VADE']); ?>
                    <?php if ($gecikmis): ?>
                        <span class="badge bad-red">Gecikme: +<?php echo $diff; ?>g</span>
                    <?php elseif ($diff < 0): ?>
                        <span class="badge bad-amber"><?php echo abs($diff); ?>g kaldı</span>
                    <?php else: ?>
                        <span class="badge bad-green">Vadesinde</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card">
                <div class="k">Tutar</div>
                <div class="v"><?php echo paraformat($sqlHeader['TUTAR']) . ' ' . $mdoviz; ?></div>
            </div>
            <div class="card">
                <div class="k">Durum</div>
                <div class="v"><?php echo htmlspecialchars((string) $sqlHeader['DURUMU']); ?></div>
            </div>
            <div class="card">
                <div class="k">Son İşlem</div>
                <div class="v">
                    <?php
                    echo htmlspecialchars($sqlHeader['LastTrText'] ?? '-');
                    if (!empty($sqlHeader['LastDate'])) {
                        echo ' — ' . tarihcevir($sqlHeader['LastDate']);
                    }
                    ?>
                </div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="info-table">
                <tr>
                    <td>Firma :</td>
                    <td><?php echo htmlspecialchars($cari['DEFINITION_'] ?? ''); ?></td>
                    <td>Tür :</td>
                    <td><?php echo ($sqlHeader['DOC'] == 1 ? 'Çek' : 'Senet'); ?></td>
                </tr>
                <tr>
                    <td>Banka / Şube :</td>
                    <td>
                        <?php
                        $banka = $sqlHeader['BANKA'] ?? '';
                        $sube  = $sqlHeader['SUBE'] ?? '';
                        echo htmlspecialchars($banka . ' / ' . $sube);
                        ?>
                    </td>
                    <td>Seri No :</td>
                    <td><?php echo htmlspecialchars($sqlHeader['SERINO'] ?? ''); ?></td>
                </tr>
                <tr>
                    <td>Kimden :</td>
                    <td><?php
                        $kcode = $sqlHeader['KIMDEN_CODE'] ?? '';
                        $kad   = $sqlHeader['KIMDEN_AD'] ?? '';
                        echo htmlspecialchars($kcode . ' — ' . $kad);
                    ?></td>
                    <td>Son Roll / Banka :</td>
                    <td><?php
                        $rollno   = $sqlHeader['RollNo'] ?? '-';
                        $bankakod = $sqlHeader['BankaKodu'] ?? '-';
                        echo htmlspecialchars($rollno . ' / ' . $bankakod);
                    ?></td>
                </tr>
                <?php if (!empty($cari['CITY']) || !empty($cari['TELNRS1'])): ?>
                    <tr>
                        <td>Şehir / Tel :</td>
                        <td colspan="3">
                            <?php
                            $sehir = $cari['CITY'] ?? '';
                            $tel   = $cari['TELNRS1'] ?? '';
                            echo htmlspecialchars($sehir . ' / ' . $tel);
                            ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="table-responsive">
            <table class="details-table">
                <thead>
                    <tr>
                        <th class="center">Tarih</th>
                        <th class="center">İşlem</th>
                        <th class="center">Roll No</th>
                        <th class="center">Roll Tür</th>
                        <th>Banka Kodu</th>
                        <th>Karşı Cari Kodu</th>
                        <th>Karşı Cari Adı</th>
                        <th class="center">TR LOGREF</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $stmtTr = $dbh->prepare("
                SELECT
                  t.DATE_ AS Tarih,
                  t.TRCODE,
                  CASE t.TRCODE
                    WHEN 1 THEN 'Giriş' WHEN 2 THEN 'Ciro' WHEN 3 THEN 'Ciro Edilen'
                    WHEN 4 THEN 'Teminat' WHEN 5 THEN 'Tahsile Verildi' WHEN 6 THEN 'Tahsil'
                    WHEN 7 THEN 'Protestolu Tahsil' WHEN 8 THEN 'İade' WHEN 9 THEN 'Protesto'
                    WHEN 10 THEN 'Kendi Çekimiz' WHEN 11 THEN 'Borç Senedimiz'
                    ELSE 'Diğer'
                  END AS Islem,
                  r.ROLLNO,
                  r.TRCODE AS RollTrCode,
                  ba.CODE  AS BankaKodu,
                  cp.CODE  AS KarsiCariKodu,
                  cp.DEFINITION_ AS KarsiCariAdi,
                  t.LOGICALREF AS TR_LOGREF
                FROM {$firmadonem}CSTRANS t
                LEFT JOIN {$firmadonem}CSROLL r ON r.LOGICALREF = t.ROLLREF
                LEFT JOIN {$firma}BANKACC ba ON ba.LOGICALREF = t.CARDREF
                LEFT JOIN {$firma}CLCARD  cp ON cp.LOGICALREF = t.CARDREF
                WHERE t.CSREF = :csref
                ORDER BY t.DATE_, t.LOGICALREF
            ");
                    $stmtTr->execute([':csref' => $CSREF]);
                    while ($row = $stmtTr->fetch(PDO::FETCH_ASSOC)) {
                        echo '<tr>
                <td class="center">' . tarihcevir($row['Tarih']) . '</td>
                <td class="center">' . htmlspecialchars((string) $row['Islem']) . '</td>
                <td class="center">' . htmlspecialchars($row['ROLLNO'] ?? '') . '</td>
                <td class="center">' . htmlspecialchars($row['RollTrCode'] ?? '') . '</td>
                <td>' . htmlspecialchars($row['BankaKodu'] ?? '') . '</td>
                <td>' . htmlspecialchars($row['KarsiCariKodu'] ?? '') . '</td>
                <td>' . htmlspecialchars($row['KarsiCariAdi'] ?? '') . '</td>
                <td class="center">' . htmlspecialchars((string) $row['TR_LOGREF']) . '</td>
            </tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <div class="table-responsive" style="max-width: 420px;">
            <table class="info-table">
                <tr>
                    <td>Son Bakiye (2025):</td>
                    <td class="numeric" style="font-weight:bold;color:#0f766e;">
                        <?php
                        $bakiyeSon = $sqlbakiye['BAKIYE'] ?? 0;
                        echo paraformat($bakiyeSon) . ' ' . $mdoviz;
                        ?>
                    </td>
                </tr>
            </table>
        </div>

    </div>
</body>

</html>

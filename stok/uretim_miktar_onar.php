<?php
declare(strict_types=1);

include_once(__DIR__ . '/../ayr.php');
include(__DIR__ . '/../kontrol.php');

if (m_p_yetki($terminalkullanici, 'M15') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}

try {
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable) {
}

function umo_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function umo_dec(mixed $value, int $scale = 2): string
{
    return number_format((float) $value, $scale, '.', '');
}

function umo_candidate_sql(string $firmadonem, string $firma, string $extraWhere = ''): string
{
    return "
        SELECT
            H.LOGICALREF AS LINE_ID,
            F.LOGICALREF AS FIS_ID,
            F.FICHENO,
            F.DATE_,
            F.GENEXP1,
            H.STFICHELNNO,
            H.LINEEXP,
            S.CODE AS STOCK_CODE,
            S.NAME AS STOCK_NAME,
            CAST(H.AMOUNT AS FLOAT) AS AMOUNT,
            CAST(ISNULL(H.UINFO1, 1) AS FLOAT) AS UINFO1,
            CAST(ISNULL(H.UINFO2, 1) AS FLOAT) AS UINFO2,
            CAST(ISNULL(L1.CONVFACT1, 1) AS FLOAT) AS BASE_CF1,
            CAST(ISNULL(L1.CONVFACT2, 1) AS FLOAT) AS BASE_CF2,
            CAST(ISNULL(PK.CONVFACT2, 1) AS FLOAT) AS KOLI_CF2
        FROM {$firmadonem}STLINE H
        JOIN {$firmadonem}STFICHE F ON F.LOGICALREF = H.STFICHEREF AND F.TRCODE = 13
        JOIN {$firma}ITEMS S ON S.LOGICALREF = H.STOCKREF
        LEFT JOIN {$firma}UNITSETL L1 ON L1.UNITSETREF = S.UNITSETREF AND L1.LINENR = 1
        OUTER APPLY (
            SELECT TOP 1 IA.CONVFACT2
            FROM {$firma}ITMUNITA IA
            WHERE IA.ITEMREF = S.LOGICALREF AND IA.CONVFACT2 IS NOT NULL AND IA.CONVFACT2 > 1
            ORDER BY IA.LINENR DESC
        ) AS PK
        WHERE H.TRCODE = 13
          AND H.IOCODE = 1
          AND H.LINETYPE = 0
          AND H.CANCELLED = 0
          AND ISNULL(H.UINFO2, 1) > 1
          AND ISNULL(PK.CONVFACT2, 1) > 1
          AND ABS(CAST(ISNULL(H.UINFO2, 1) AS FLOAT) - CAST(ISNULL(PK.CONVFACT2, 1) AS FLOAT)) < 0.0001
          {$extraWhere}
    ";
}

$today = date('Y-m-d');
$startDate = isset($_GET['baslangic']) ? trim((string) $_GET['baslangic']) : date('Y-m-01');
$endDate = isset($_GET['bitis']) ? trim((string) $_GET['bitis']) : $today;
$stockCode = isset($_GET['stok']) ? strtoupper(trim((string) $_GET['stok'])) : '';
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['repair'])) {
    $selectedIds = is_array($_POST['line_id'] ?? null) ? array_map('intval', $_POST['line_id']) : [];
    $selectedIds = array_values(array_filter(array_unique($selectedIds), static fn(int $id): bool => $id > 0));

    if ($selectedIds === []) {
        $err = 'Onarilacak satir secilmedi.';
    } else {
        $selectCandidate = $dbh->prepare(umo_candidate_sql($firmadonem, $firma, 'AND H.LOGICALREF = ?'));
        $updateLine = $dbh->prepare("UPDATE {$firmadonem}STLINE SET UINFO1 = ?, UINFO2 = ? WHERE LOGICALREF = ?");
        $fixedCount = 0;

        $dbh->beginTransaction();
        try {
            foreach ($selectedIds as $lineId) {
                $selectCandidate->execute([$lineId]);
                $row = $selectCandidate->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    continue;
                }

                $baseCf1 = max((float) $row['BASE_CF1'], 1.0);
                $baseCf2 = max((float) $row['BASE_CF2'], 1.0);
                $updateLine->execute([
                    number_format($baseCf1, 6, '.', ''),
                    number_format($baseCf2, 6, '.', ''),
                    (int) $row['LINE_ID'],
                ]);
                $fixedCount++;
            }
            $dbh->commit();
            $msg = $fixedCount . ' satir onarildi. Stok toplaminda eski sislik hala gorunurse Logo stok toplamlarini yeniden hesaplatmak gerekir.';
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            $err = $e->getMessage();
        }
    }
}

$where = '';
$params = [];
if ($startDate !== '') {
    $where .= ' AND F.DATE_ >= ?';
    $params[] = $startDate . ' 00:00:00';
}
if ($endDate !== '') {
    $where .= ' AND F.DATE_ < DATEADD(day, 1, ?)';
    $params[] = $endDate . ' 00:00:00';
}
if ($stockCode !== '') {
    $where .= ' AND S.CODE LIKE ?';
    $params[] = '%' . $stockCode . '%';
}

$stmt = $dbh->prepare('SELECT TOP 300 * FROM (' . umo_candidate_sql($firmadonem, $firma, $where) . ') X ORDER BY X.DATE_ DESC, X.LINE_ID DESC');
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Uretim Miktar Onarimi</title>
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        body{font-family:Arial,sans-serif;background:#f6f7f9;margin:0;color:#172033}
        .top{position:sticky;top:0;background:#fff;border-bottom:1px solid #e5e7eb;padding:14px 22px;display:flex;gap:12px;align-items:center;z-index:3}
        .back{width:36px;height:36px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;color:#334155;text-decoration:none;border:1px solid #e5e7eb}
        .title{font-size:18px;font-weight:800;flex:1}
        main{max-width:1280px;margin:0 auto;padding:20px}
        .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 8px 24px rgba(15,23,42,.04);overflow:hidden;margin-bottom:16px}
        .body{padding:16px}.muted{color:#64748b;font-size:12px}
        .filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
        label{font-size:12px;font-weight:700;color:#475569;display:grid;gap:5px}
        input{border:1px solid #d1d5db;border-radius:8px;padding:9px 10px}
        .btn{border:0;border-radius:10px;background:var(--red,#6F1022);color:#fff;padding:10px 14px;font-weight:800;cursor:pointer;text-decoration:none;display:inline-flex;gap:8px;align-items:center}
        .btn.secondary{background:#475569}.btn.gray{background:#64748b}
        table{width:100%;border-collapse:collapse}
        th,td{padding:9px;border-bottom:1px solid #eef2f7;font-size:12px;text-align:left;vertical-align:top}
        th{font-size:11px;text-transform:uppercase;color:#64748b;background:#f8fafc}
        .msg{padding:10px 12px;border-radius:10px;margin-bottom:12px}.err{background:#fee2e2;color:#991b1b}.ok{background:#dcfce7;color:#166534}
        .warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;border-radius:10px;padding:10px 12px;margin-bottom:12px}
        .num{text-align:right;font-variant-numeric:tabular-nums}
        .danger{color:#b91c1c;font-weight:800}
        @media(max-width:900px){main{padding:12px}.top{padding:12px}table{min-width:980px}.scroll{overflow:auto}}
    </style>
</head>
<body>
<header class="top">
    <a class="back" href="index.php" title="Geri"><i class="fa fa-arrow-left"></i></a>
    <div class="title">Uretim Miktar Onarimi</div>
</header>
<main>
    <?php if ($err): ?><div class="msg err"><?= umo_h($err); ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="msg ok"><?= umo_h($msg); ?></div><?php endif; ?>
    <div class="warn">
        Bu ekran sadece supheli uretim satirlarini listeler: satirda <strong>UINFO2</strong> koli ici adet gibi yazilmissa, onarim UINFO1/UINFO2 alanlarini ana birime ceker.
        Stok toplaminda eski sislik devam ederse Logo tarafinda stok toplamlarini yeniden hesaplatmak gerekir.
    </div>

    <section class="card">
        <div class="body">
            <form class="filters" method="get">
                <label>Baslangic <input type="date" name="baslangic" value="<?= umo_h($startDate); ?>"></label>
                <label>Bitis <input type="date" name="bitis" value="<?= umo_h($endDate); ?>"></label>
                <label>Stok kodu <input type="text" name="stok" value="<?= umo_h($stockCode); ?>" placeholder="AKL365"></label>
                <button class="btn gray" type="submit"><i class="fa fa-search"></i> Listele</button>
            </form>
        </div>
    </section>

    <form method="post" class="card">
        <div class="scroll">
            <table>
                <thead>
                <tr>
                    <th>Sec</th><th>Tarih</th><th>Fis</th><th>Stok</th><th>Aciklama</th>
                    <th class="num">AMOUNT</th><th class="num">UINFO2</th><th class="num">Koli Ici</th>
                    <th class="num">Tahmini Etki</th><th class="num">Fazla</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <?php
                    $amount = (float) $row['AMOUNT'];
                    $uinfo1 = max((float) $row['UINFO1'], 1.0);
                    $uinfo2 = max((float) $row['UINFO2'], 1.0);
                    $estimated = $amount * $uinfo2 / $uinfo1;
                    $excess = max(0.0, $estimated - $amount);
                    ?>
                    <tr>
                        <td><input type="checkbox" name="line_id[]" value="<?= (int) $row['LINE_ID']; ?>"></td>
                        <td><?= umo_h(date('d.m.Y', strtotime((string) $row['DATE_']))); ?></td>
                        <td>
                            <a href="uretim_giris.php?fis=<?= (int) $row['FIS_ID']; ?>"><?= umo_h($row['FICHENO']); ?></a><br>
                            <span class="muted">Satir #<?= (int) $row['STFICHELNNO']; ?></span>
                        </td>
                        <td><strong><?= umo_h($row['STOCK_CODE']); ?></strong><br><span class="muted"><?= umo_h(trcevir($row['STOCK_NAME'])); ?></span></td>
                        <td><span class="muted"><?= umo_h($row['GENEXP1']); ?><br><?= umo_h($row['LINEEXP']); ?></span></td>
                        <td class="num"><?= umo_dec($amount); ?></td>
                        <td class="num danger"><?= umo_dec($uinfo2); ?></td>
                        <td class="num"><?= umo_dec($row['KOLI_CF2']); ?></td>
                        <td class="num danger"><?= umo_dec($estimated); ?></td>
                        <td class="num danger"><?= umo_dec($excess); ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?><tr><td colspan="10">Supheli satir bulunamadi.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="body" style="display:flex;justify-content:space-between;gap:12px;align-items:center;border-top:1px solid #e5e7eb">
            <span class="muted"><?= count($rows); ?> supheli satir listelendi. Once tek urunle deneyip stok sonucunu kontrol et.</span>
            <button class="btn" type="submit" name="repair" value="1" onclick="return confirm('Secili satirlarin UINFO1/UINFO2 alanlari ana birime cekilecek. Devam edilsin mi?');">
                <i class="fa fa-wrench"></i> Secilenleri Onar
            </button>
        </div>
    </form>
</main>
</body>
</html>

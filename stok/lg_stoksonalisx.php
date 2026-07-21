<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// Input validation ve güvenlik
if (!isset($_POST['userid'])) {
    die(json_encode(['error' => 'Missing userid parameter']));
}

$idx = $_POST['userid'];
$id = explode(",", (string) $idx);

if (count($id) !== 3) {
    die(json_encode(['error' => 'Invalid userid format']));
}

// Input sanitization
$fisid = (int)$id[0];
$stokid = (int)$id[1];
$birim = htmlspecialchars(trim($id[2]), ENT_QUOTES, 'UTF-8');

if ($fisid <= 0 || $stokid <= 0) {
    die(json_encode(['error' => 'Invalid ID values']));
}

// Yetkilendirme kontrolü - Kullanıcı bu fişe erişebilir mi?
$stmt_auth = $dbh->prepare("SELECT CLIENTREF FROM " . $firmadonem . "ORFICHE WHERE LOGICALREF = :fisid");
$stmt_auth->execute([':fisid' => $fisid]);
$order = $stmt_auth->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die(json_encode(['error' => 'Order not found']));
}

$cariid = $order['CLIENTREF'];

// Veri toplama
$satisList = [];
$alisList  = [];

if (m_p_yetki($terminalkullanici, 'SP3') == 1 || $yetkidurum == 0) {
    $stmt_satis = $dbh->prepare("SELECT TOP 5
        S.PRICE, S.AMOUNT, S.DATE_, CR.DEFINITION_
    FROM " . $firma . "ITEMS I
    JOIN " . $firmadonem . "STLINE S ON I.LOGICALREF = S.STOCKREF
    RIGHT JOIN " . $firma . "CLCARD CR ON S.CLIENTREF = CR.LOGICALREF
    WHERE S.STOCKREF = :stokid
        AND S.LINETYPE = 0
        AND S.CANCELLED = 0
        AND S.CLIENTREF = :cariid
        AND S.TRCODE IN(7,8)
    ORDER BY S.DATE_ DESC");
    $stmt_satis->execute([':stokid' => $stokid, ':cariid' => $cariid]);
    while ($row = $stmt_satis->fetch(PDO::FETCH_ASSOC)) {
        $satisList[] = $row;
    }
}

if (m_p_yetki($terminalkullanici, 'SP2') == 1 || $yetkidurum == 0) {
    $stmt_alis = $dbh->prepare("SELECT TOP 5
        S.PRICE, S.AMOUNT, S.DATE_
    FROM " . $firma . "ITEMS I
    JOIN " . $firmadonem . "STLINE S ON I.LOGICALREF = S.STOCKREF
    WHERE S.STOCKREF = :stokid
        AND S.LINETYPE = 0
        AND S.CANCELLED = 0
        AND S.TRCODE IN(1,14)
    ORDER BY S.DATE_ DESC");
    $stmt_alis->execute([':stokid' => $stokid]);
    while ($row = $stmt_alis->fetch(PDO::FETCH_ASSOC)) {
        $alisList[] = $row;
    }
}

// Ortalama hesapla
function ssx_avg(array $rows, string $key): float {
    if (empty($rows)) return 0.0;
    $sum = 0.0;
    foreach ($rows as $r) { $sum += (float)($r[$key] ?? 0); }
    return $sum / count($rows);
}
$satisAvgFiyat = ssx_avg($satisList, 'PRICE');
$alisAvgFiyat  = ssx_avg($alisList,  'PRICE');

// HTML render (inline CSS — modal icin bagimsiz)
ob_start();
?>
<style>
    .ssx-wrap { display: flex; flex-direction: column; gap: 16px; }
    .ssx-section { border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background: #fff; }
    .ssx-head {
        padding: 12px 16px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 1px solid #e5e7eb;
    }
    .ssx-head .ico {
        width: 32px; height: 32px;
        border-radius: 9px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 13px;
        flex-shrink: 0;
    }
    .ssx-head .title {
        font-size: 13px;
        font-weight: 700;
        color: #1f2937;
    }
    .ssx-head .meta {
        margin-left: auto;
        font-size: 11px;
        color: #6b7280;
        font-weight: 500;
    }
    .ssx-head .meta strong { color: #1f2937; font-weight: 700; }

    .ssx-section.satis .ssx-head { background: linear-gradient(90deg, #ecfdf5, #fff); }
    .ssx-section.satis .ssx-head .ico { background: #ecfdf5; color: #059669; }

    .ssx-section.alis .ssx-head { background: linear-gradient(90deg, #eff6ff, #fff); }
    .ssx-section.alis .ssx-head .ico { background: #eff6ff; color: #0284c7; }

    .ssx-body { padding: 0; }
    .ssx-row {
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 12px;
        align-items: center;
        padding: 10px 16px;
        font-size: 12px;
        border-bottom: 1px dashed #f3f4f6;
        transition: background 0.15s ease;
    }
    .ssx-row:last-child { border-bottom: none; }
    .ssx-row:hover { background: #fafafa; }
    .ssx-row .date {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 9px;
        background: #f3f4f6;
        border-radius: 100px;
        font-size: 10.5px;
        font-weight: 600;
        color: #374151;
        white-space: nowrap;
    }
    .ssx-row .date i { font-size: 9px; color: #9ca3af; }
    .ssx-row .qty {
        font-size: 12.5px;
        font-weight: 600;
        color: #1f2937;
    }
    .ssx-row .qty .unit {
        font-size: 10px;
        color: #9ca3af;
        margin-left: 3px;
        text-transform: uppercase;
    }
    .ssx-row .price {
        font-size: 13px;
        font-weight: 700;
        text-align: right;
        white-space: nowrap;
    }
    .ssx-section.satis .ssx-row .price { color: #059669; }
    .ssx-section.alis .ssx-row .price  { color: #0284c7; }

    .ssx-empty {
        padding: 24px 16px;
        text-align: center;
        color: #9ca3af;
        font-size: 12px;
    }
    .ssx-empty i { display: block; font-size: 22px; margin-bottom: 6px; color: #d1d5db; }

    @media (max-width: 480px) {
        .ssx-row { grid-template-columns: auto 1fr; gap: 6px 10px; padding: 10px 14px; }
        .ssx-row .price { grid-column: 2; text-align: left; font-size: 12.5px; }
        .ssx-head { padding: 10px 14px; }
        .ssx-head .meta { display: none; }
    }
</style>

<div class="ssx-wrap">
    <!-- Satis gecmisi -->
    <?php if (m_p_yetki($terminalkullanici, 'SP3') == 1 || $yetkidurum == 0): ?>
    <div class="ssx-section satis">
        <div class="ssx-head">
            <span class="ico"><i class="fa-solid fa-arrow-trend-up"></i></span>
            <div>
                <div class="title">Satis Gecmisi</div>
            </div>
            <?php if (!empty($satisList)): ?>
                <div class="meta">ort <strong><?php echo number_format($satisAvgFiyat, 2, ',', '.'); ?> &#8378;</strong> &middot; <?php echo count($satisList); ?> kayit</div>
            <?php endif; ?>
        </div>
        <div class="ssx-body">
            <?php if (empty($satisList)): ?>
                <div class="ssx-empty"><i class="fa-solid fa-receipt"></i>Satis kaydi yok</div>
            <?php else: foreach ($satisList as $s):
                $tarih = htmlspecialchars(tarihcevir($s['DATE_']), ENT_QUOTES, 'UTF-8');
                $miktar = htmlspecialchars(kusuratadet($s['AMOUNT']), ENT_QUOTES, 'UTF-8');
                $fiyat = htmlspecialchars(number_format((float)$s['PRICE'], 2, ',', '.'), ENT_QUOTES, 'UTF-8');
            ?>
                <div class="ssx-row">
                    <span class="date"><i class="fa-regular fa-calendar"></i><?php echo $tarih; ?></span>
                    <span class="qty"><?php echo $miktar; ?><span class="unit"><?php echo $birim; ?></span></span>
                    <span class="price"><?php echo $fiyat; ?> &#8378;</span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Alis gecmisi -->
    <?php if (m_p_yetki($terminalkullanici, 'SP2') == 1 || $yetkidurum == 0): ?>
    <div class="ssx-section alis">
        <div class="ssx-head">
            <span class="ico"><i class="fa-solid fa-arrow-trend-down"></i></span>
            <div>
                <div class="title">Alis Gecmisi</div>
            </div>
            <?php if (!empty($alisList)): ?>
                <div class="meta">ort <strong><?php echo number_format($alisAvgFiyat, 2, ',', '.'); ?> &#8378;</strong> &middot; <?php echo count($alisList); ?> kayit</div>
            <?php endif; ?>
        </div>
        <div class="ssx-body">
            <?php if (empty($alisList)): ?>
                <div class="ssx-empty"><i class="fa-solid fa-truck-ramp-box"></i>Alis kaydi yok</div>
            <?php else: foreach ($alisList as $a):
                $tarih = htmlspecialchars(tarihcevir($a['DATE_']), ENT_QUOTES, 'UTF-8');
                $miktar = htmlspecialchars(kusuratadet($a['AMOUNT']), ENT_QUOTES, 'UTF-8');
                $fiyat = htmlspecialchars(number_format((float)$a['PRICE'], 2, ',', '.'), ENT_QUOTES, 'UTF-8');
            ?>
                <div class="ssx-row">
                    <span class="date"><i class="fa-regular fa-calendar"></i><?php echo $tarih; ?></span>
                    <span class="qty"><?php echo $miktar; ?><span class="unit"><?php echo $birim; ?></span></span>
                    <span class="price"><?php echo $fiyat; ?> &#8378;</span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
echo ob_get_clean();
exit;

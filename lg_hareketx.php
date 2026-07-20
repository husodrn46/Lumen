<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/log_ip.php");
$mdoviz = "TL";
if (isset($_GET['cariid'])) {
    $CARIID = intcevir($_GET['cariid']);
} else {
    exit;
}
$cari_unvan = cari_bul($CARIID);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hesap Hareketleri - <?php echo htmlspecialchars((string) $cari_unvan); ?></title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once __DIR__ . '/pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f9fafb;
            --text-1: #1f2937;
            --text-2: #6b7280;
            --text-3: #9ca3af;
            --border: #e5e7eb;
            --red: #6F1022;
            --red-soft: #fef2f2;
            --emerald: #059669;
            --emerald-soft: #ecfdf5;
            --indigo: #4f46e5;
            --indigo-soft: #eef2ff;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
        }
        * { box-sizing: border-box; margin: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background: var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        .top-header {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border-bottom: 1px solid rgba(248, 113, 113, 0.18);
            box-shadow: 0 2px 8px rgba(111, 16, 34, 0.04);
        }
        .header-inner {
            max-width: 1200px; margin: 0 auto;
            padding: 14px 24px;
            display: flex; align-items: center; gap: 14px;
            flex-wrap: wrap;
        }
        .header-back {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 10px;
            color: var(--text-2); text-decoration: none; transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .header-back:hover { background: rgba(0,0,0,0.04); color: var(--red); }
        .header-divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
        .header-title { min-width: 0; flex: 1 1 auto; }
        .header-title h1 {
            font-size: 16px; font-weight: 700; color: var(--text-1);
            display: flex; align-items: center; gap: 8px; margin: 0;
        }
        .header-title h1 i { color: var(--red,#ef4444); font-size: 14px; }
        .header-title p {
            margin: 2px 0 0; font-size: 11.5px; color: var(--text-2); font-weight: 500;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn-print {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; background: var(--red,#ef4444); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        .btn-print:hover { background: var(--red); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(239, 68, 68, 0.3); }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .stat-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid var(--border);
            border-radius: 14px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            padding: 16px 18px;
            display: flex; align-items: center; gap: 14px;
            position: relative; overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
        }
        .stat-card:nth-child(2) { animation-delay: 60ms; }
        .stat-card:nth-child(3) { animation-delay: 120ms; }
        .stat-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; bottom: 0;
            width: 4px;
        }
        .stat-card.emerald::before { background: var(--emerald); }
        .stat-card.red::before { background: var(--red); }
        .stat-card.indigo::before { background: var(--indigo); }
        .stat-card .icon-box {
            width: 48px; height: 48px;
            flex-shrink: 0;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .stat-card.emerald .icon-box { background: var(--emerald-soft); color: var(--emerald); }
        .stat-card.red .icon-box     { background: var(--red-soft); color: var(--red); }
        .stat-card.indigo .icon-box  { background: var(--indigo-soft); color: var(--indigo); }
        .stat-card .stat-body { flex: 1 1 auto; min-width: 0; }
        .stat-card .stat-label {
            font-size: 10.5px; color: var(--text-2); font-weight: 600;
            text-transform: uppercase; letter-spacing: 0.4px;
            display: block;
        }
        .stat-card .stat-value {
            display: block; margin-top: 3px;
            font-size: 18px; font-weight: 700; color: var(--text-1);
            line-height: 1.2;
        }
        .stat-card.emerald .stat-value { color: var(--emerald); }
        .stat-card.red .stat-value     { color: var(--red); }

        .table-card {
            background: rgba(255,255,255,0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.18);
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            overflow: hidden;
            animation: cardIn 0.45s cubic-bezier(0.22, 1, 0.36, 1) 0.18s both;
            will-change: transform, opacity;
        }
        .hareket-table { width: 100%; border-collapse: collapse; }
        .hareket-table thead { background: linear-gradient(180deg, #fff, #fffafa); }
        .hareket-table thead th {
            padding: 12px 16px;
            font-size: 10px; font-weight: 700; color: var(--text-2);
            text-transform: uppercase; letter-spacing: 0.5px;
            text-align: left; border-bottom: 1px solid var(--border);
        }
        .hareket-table thead th.right { text-align: right; }
        .hareket-table tbody td {
            padding: 12px 16px;
            font-size: 12.5px; color: var(--text-1);
            border-bottom: 1px solid #f3f4f6;
            vertical-align: middle;
        }
        .hareket-table tbody tr { transition: background 0.15s ease; }
        .hareket-table tbody tr:hover { background: #fafafa; }
        .hareket-table tbody tr:last-child td { border-bottom: none; }
        .hareket-table .date {
            color: var(--text-2); font-weight: 500;
            white-space: nowrap; font-size: 12px;
        }
        .hareket-table .tr-type { font-weight: 500; color: var(--text-1); }
        .hareket-table .tr-type i { color: var(--text-3); margin-right: 6px; font-size: 11px; }
        .hareket-table .right {
            text-align: right; font-weight: 600; white-space: nowrap;
        }
        .hareket-table .right.giris  { color: var(--emerald); }
        .hareket-table .right.cikis  { color: var(--red); }
        .hareket-table .right.bakiye { font-weight: 700; }
        .hareket-table .right.bakiye.pozitif { color: var(--emerald); }
        .hareket-table .right.bakiye.negatif { color: var(--red); }
        .hareket-table .right .muted { color: var(--text-3); font-weight: 400; }
        .hareket-table .devir-row { background: #fafafa; }
        .hareket-table .devir-row td { font-style: italic; color: var(--text-2); }
        .hareket-table .devir-row i { color: var(--text-3); margin-right: 6px; }
        .hareket-table .empty-row td {
            text-align: center; padding: 48px 20px;
            color: var(--text-3); font-size: 13px;
        }
        .hareket-table .empty-row i {
            display: block; font-size: 32px; margin-bottom: 10px; color: var(--text-3);
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 12px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }

        @media (max-width: 767px) {
            input, select, textarea { font-size: 16px !important; }

            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title h1 { font-size: 14px; }
            .header-title p { font-size: 10.5px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            .header-actions { width: 100%; justify-content: flex-start; }
            .btn-print { padding: 8px 14px; font-size: 12px; }

            main { padding: 14px 12px 40px !important; }

            .stat-grid { grid-template-columns: 1fr; gap: 10px; margin-bottom: 14px; }
            .stat-card { padding: 14px 16px; }
            .stat-card .icon-box { width: 42px; height: 42px; font-size: 16px; }
            .stat-card .stat-value { font-size: 16px; }

            .hareket-table thead { display: none; }
            .hareket-table, .hareket-table tbody, .hareket-table tr, .hareket-table td {
                display: block; width: 100%;
            }
            .hareket-table tbody tr {
                padding: 14px 16px;
                border-bottom: 1px solid #f3f4f6;
                display: grid;
                grid-template-columns: 1fr auto;
                gap: 4px 10px;
            }
            .hareket-table tbody tr:last-child { border-bottom: none; }
            .hareket-table tbody td { padding: 0; border-bottom: none; }
            .hareket-table td.date    { grid-column: 1; font-size: 10.5px; }
            .hareket-table td.tr-type { grid-column: 1; font-size: 13px; }
            .hareket-table td.giris, .hareket-table td.cikis, .hareket-table td.bakiye {
                grid-column: 2; text-align: right;
            }
            .hareket-table td.giris::before  { content: 'Giris '; color: var(--text-3); font-weight: 400; font-size: 10px; }
            .hareket-table td.cikis::before  { content: 'Cikis '; color: var(--text-3); font-weight: 400; font-size: 10px; }
            .hareket-table td.bakiye::before { content: 'Bakiye '; color: var(--text-3); font-weight: 400; font-size: 10px; }
        }

        @media print {
            body { background: #fff; }
            .top-header { position: static; box-shadow: none; border-bottom: 2px solid #000; }
            .header-actions, .no-print { display: none !important; }
            .stat-card, .table-card { box-shadow: none; border: 1px solid #ccc; animation: none; }
            .hareket-table tbody tr:hover { background: transparent; }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <div class="header-inner">
            <a href="javascript:history.back()" class="header-back" title="Geri">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <div class="header-title">
                <h1><i class="fa-solid fa-right-left"></i>Hesap Hareketleri</h1>
                <p><?php echo htmlspecialchars((string) $cari_unvan); ?></p>
            </div>
            <div class="header-actions">
                <button onclick="window.print()" class="btn-print" type="button">
                    <i class="fa-solid fa-print"></i> Yazdir
                </button>
            </div>
        </div>
    </header>

    <main style="max-width:1200px;margin:0 auto;padding:22px 24px 60px;">
        <?php
        $toplam1 = 0;
        $toplam2 = 0;
        $DURUM = "(A)";

        // RESMİ BAKİYE: GNTOTCL view'ından al (lg_bakiye.php ile aynı kaynak)
        $stmtResmi = $dbh->prepare("SELECT (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE
            FROM {$firmadonemx}GNTOTCL G WITH(NOLOCK)
            WHERE G.CARDREF = :cariid AND G.TOTTYP = 1");
        $stmtResmi->execute([':cariid' => $CARIID]);
        $resmi_bakiye = (float)$stmtResmi->fetchColumn();

        // İki dönemi birleştir (2025 + 2026) - UNION ALL
        $trcodeCaseExpr = "CASE HAREKET.TRCODE WHEN 1 THEN 'Nakit Tahsilat' WHEN 2 THEN 'Nakit Ödeme' WHEN 3 THEN 'Borç Dekontu' WHEN 4 THEN 'Alacak Dekontu' WHEN 5 THEN 'Virman Fişi'
                       WHEN 6 THEN 'Kur Farkı Fişi' WHEN 12 THEN 'Özel Fiş' WHEN 14 THEN 'Açılış Fişi' WHEN 20 THEN 'Gelen Havale' WHEN 21 THEN 'Gönderilen Havale' WHEN 24 THEN
                       'Döviz Alış Belgesi' WHEN 25 THEN 'Döviz Satış belgesi' WHEN 28 THEN 'Alınan Hizmet Faturası' WHEN 29 THEN 'Verilen Hizmet Faturası' WHEN 31 THEN 'Satın Alma Faturası'
                       WHEN 32 THEN 'Perakende Satış İade Faturası' WHEN 33 THEN 'Toptan Satış İade Faturası' WHEN 34 THEN 'Alınan Hizmet Faturası' WHEN 35 THEN 'Alınan Proforma Fatura'
                       WHEN 36 THEN 'Satın Alma İade Faturası' WHEN 37 THEN 'Perakende Satış Faturası' WHEN 38 THEN 'Toptan Satış Faturası' WHEN 39 THEN 'Verilen Hizmet Faturası'
                       WHEN 40 THEN 'Verilen proforma fatura' WHEN 41 THEN 'Verilen Vade Farkı Faturası' WHEN 42 THEN 'Alınan Vade Farkı Faturası' WHEN 43 THEN 'Satın Alma Fiyat Farkı Faturası'
                       WHEN 44 THEN 'Satış Fiyat Farkı Faturası' WHEN 45 THEN 'Verilen Serbest Meslek Makbuzu' WHEN 46 THEN 'Alınan Serbest Meslek Makbuzu' WHEN 56 THEN 'Müstahsil Makbuzu'
                       WHEN 61 THEN 'Çek Girişi' WHEN 62 THEN 'Senet Girişi' WHEN 63 THEN 'Çek Çıkışı (Cari Hesaba)' WHEN 64 THEN 'Senet Çıkışı (Cari Hesaba)' WHEN 70 THEN 'Kredi Kartı Fişi'
                       WHEN 71 THEN 'Kredi Kartı İade Fişi' WHEN 72 THEN 'Firma Kredi Kartı Fişi' WHEN 73 THEN 'Firma Kredi Kartı İade Fişi' WHEN 81 THEN 'Satınalma Siparişi' WHEN 82
                       THEN 'Satış Siparişi' END";

        $cariIdSafe = (int)$CARIID;
        // İki dönem birleştirilirken TÜM açılış fişlerini (TRCODE=14) hariç tut
        // Çünkü lg_bakiye.php sadece aktif dönemin GNTOTCL view'ını kullanıyor
        // Bu view açılış fişlerini içsel olarak hesaba katıyor, biz sadece gerçek işlemleri göstermeliyiz
        $stmtHareket = $dbh->prepare("SELECT * FROM (
            SELECT
                HAREKET.LOGICALREF, HAREKET.DATE_, HAREKET.TRANNO AS DOCODE,
                {$trcodeCaseExpr} AS TRCODE,
                ISNULL((1 - HAREKET.SIGN) * HAREKET.AMOUNT, 0) AS BORC,
                ISNULL(HAREKET.SIGN * HAREKET.AMOUNT, 0) AS ALACAK
            FROM {$firmadonem}CLFLINE AS HAREKET
            INNER JOIN {$firma}CLCARD AS KART ON HAREKET.CLIENTREF = KART.LOGICALREF
            WHERE HAREKET.CANCELLED = 0 AND KART.LOGICALREF = {$cariIdSafe} AND HAREKET.TRCODE <> 14
            UNION ALL
            SELECT
                HAREKET.LOGICALREF, HAREKET.DATE_, HAREKET.TRANNO AS DOCODE,
                {$trcodeCaseExpr} AS TRCODE,
                ISNULL((1 - HAREKET.SIGN) * HAREKET.AMOUNT, 0) AS BORC,
                ISNULL(HAREKET.SIGN * HAREKET.AMOUNT, 0) AS ALACAK
            FROM {$eskifirmadonem}CLFLINE AS HAREKET
            INNER JOIN {$firma}CLCARD AS KART ON HAREKET.CLIENTREF = KART.LOGICALREF
            WHERE HAREKET.CANCELLED = 0 AND KART.LOGICALREF = {$cariIdSafe} AND HAREKET.TRCODE <> 14
        ) AS BIRLESIK ORDER BY DATE_ ASC, LOGICALREF ASC");
        $stmtHareket->execute();
        $hareketler = $stmtHareket->fetchAll(PDO::FETCH_ASSOC);

        // Önce toplamları hesapla
        foreach ($hareketler as $rowx) {
            $toplam1 += $rowx['BORC'];
            $toplam2 += $rowx['ALACAK'];
        }

        // Başlangıç bakiyesi hesapla (son bakiye = resmi bakiye olacak şekilde)
        $islem_net_etki = $toplam1 - $toplam2;
        $baslangic_bakiye = $resmi_bakiye - $islem_net_etki;
        $bakiye = $baslangic_bakiye;

        $bakiye_pozitif = $resmi_bakiye > 0;
        $bakiyeStatClass = $bakiye_pozitif ? 'red' : 'emerald';
        $bakiyeLabel = $bakiye_pozitif ? 'Borclu' : 'Alacakli';
        if (abs($resmi_bakiye) < 0.01) {
            $bakiyeStatClass = 'indigo';
            $bakiyeLabel = '';
        }
        ?>

        <div class="stat-grid">
            <div class="stat-card emerald">
                <span class="icon-box"><i class="fa-solid fa-arrow-down"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Toplam Giris</span>
                    <span class="stat-value"><?php echo kusuratpara($toplam2); ?></span>
                </div>
            </div>
            <div class="stat-card red">
                <span class="icon-box"><i class="fa-solid fa-arrow-up"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Toplam Cikis</span>
                    <span class="stat-value"><?php echo kusuratpara($toplam1); ?></span>
                </div>
            </div>
            <div class="stat-card <?php echo $bakiyeStatClass; ?>">
                <span class="icon-box"><i class="fa-solid fa-scale-balanced"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Bakiye<?php echo $bakiyeLabel !== '' ? ' ('.$bakiyeLabel.')' : ''; ?></span>
                    <span class="stat-value"><?php echo kusuratpara(abs($resmi_bakiye)); ?></span>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div style="overflow-x:auto;">
                <table class="hareket-table">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Durum</th>
                            <th class="right">Giris</th>
                            <th class="right">Cikis</th>
                            <th class="right">Bakiye</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($hareketler) && abs($baslangic_bakiye) < 0.01) {
                            echo '<tr class="empty-row"><td colspan="5"><i class="fa-solid fa-inbox"></i>Bu cari icin goruntulenecek hareket bulunamadi.</td></tr>';
                        } else {
                            // Başlangıç bakiyesi varsa ilk satır olarak göster
                            if (abs($baslangic_bakiye) > 0.01) {
                                $DURUM = ($baslangic_bakiye > 0) ? "(B)" : "(A)";
                                $basClass = $baslangic_bakiye >= 0 ? 'pozitif' : 'negatif';
                                echo '<tr class="devir-row">';
                                echo '<td class="date">-</td>';
                                echo '<td class="tr-type"><i class="fa-solid fa-clock-rotate-left"></i>Onceki Donem Bakiyesi</td>';
                                echo '<td class="right giris"><span class="muted">-</span></td>';
                                echo '<td class="right cikis"><span class="muted">-</span></td>';
                                echo '<td class="right bakiye ' . $basClass . '">' . kusuratpara($baslangic_bakiye) . ' ' . $DURUM . '</td>';
                                echo '</tr>';
                            }

                            // Hareketleri listele
                            foreach ($hareketler as $rowx) {
                                // bakiye güncelle (GNTOTCL formatı: BORC arttırır, ALACAK azaltır)
                                if ($rowx['BORC'] > 0) {
                                    $bakiye += $rowx['BORC'];
                                }
                                if ($rowx['ALACAK'] > 0) {
                                    $bakiye -= $rowx['ALACAK'];
                                }

                                $DURUM = ($bakiye > 0) ? "(B)" : "(A)";
                                $bakiyeRowClass = $bakiye >= 0 ? 'pozitif' : 'negatif';

                                $icon = match ($rowx['TRCODE']) {
                                    'Nakit Tahsilat'             => '<i class="fa-solid fa-money-bill-wave"></i>',
                                    'Nakit Ödeme'                => '<i class="fa-solid fa-hand-holding-dollar"></i>',
                                    'Çek Girişi'                 => '<i class="fa-solid fa-receipt"></i>',
                                    'Çek Çıkışı (Cari Hesaba)'   => '<i class="fa-solid fa-file-invoice-dollar"></i>',
                                    'Senet Girişi'               => '<i class="fa-solid fa-file-lines"></i>',
                                    'Senet Çıkışı (Cari Hesaba)' => '<i class="fa-solid fa-file-invoice"></i>',
                                    'Satın Alma Faturası'        => '<i class="fa-solid fa-cart-arrow-down"></i>',
                                    'Toptan Satış Faturası'      => '<i class="fa-solid fa-cart-shopping"></i>',
                                    default                      => '<i class="fa-solid fa-file"></i>',
                                };

                                echo '<tr>';
                                echo '<td class="date">' . tarihcevir($rowx['DATE_']) . '</td>';
                                echo '<td class="tr-type">' . $icon . htmlspecialchars((string) $rowx['TRCODE']) . '</td>';
                                echo '<td class="right giris">' . ($rowx['ALACAK'] > 0 ? kusuratpara($rowx['ALACAK']) : '<span class="muted">-</span>') . '</td>';
                                echo '<td class="right cikis">' . ($rowx['BORC'] > 0 ? kusuratpara($rowx['BORC']) : '<span class="muted">-</span>') . '</td>';
                                echo '<td class="right bakiye ' . $bakiyeRowClass . '">' . kusuratpara($bakiye) . ' ' . $DURUM . '</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

</body>
</html>

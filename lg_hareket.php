<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/log_ip.php");

// URL parametrelerini session'a aktar ve temiz URL'ye yönlendir
migrateUrlToSession(['cariid']);

$mdoviz = "TL";
// Session bazlı parametre sistemi kullanılıyor
$CARIID = getPageParamInt('cariid');
if ($CARIID <= 0) {
    exit;
}

// Dönem seçimi (varsayılan: tüm dönemler)
$donemSecim = isset($_GET['donem']) ? $_GET['donem'] : 'tumu';
// Geçerli seçenekler: aktif (2026), onceki (2025), tumu (her iki dönem)

// CARİ BİLGİLERİNİ ÇEKME (BAŞLIKTA KULLANMAK İÇİN)
$sql_cari_info = $dbh->prepare("SELECT CODE, DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF = :cariid");
$sql_cari_info->execute([':cariid' => $CARIID]);
$cari_info = $sql_cari_info->fetch(PDO::FETCH_ASSOC);
$cari_unvan = $cari_info ? $cari_info['DEFINITION_'] : 'Bilinmeyen Cari';
$cari_kod = $cari_info ? $cari_info['CODE'] : '';
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, $terminalkullanici, $CARIID, 'M4')) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}


function paraformat(float|int|string|null $kusurat): string
{
    global $parakusurat;
    if ($kusurat === "" || is_null($kusurat)) {
        $kusurat = 0.0;
    }
    return number_format((float)$kusurat, $parakusurat, ',', '.');
}

?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cari Hareket Dokumu - <?php echo htmlspecialchars((string) $cari_unvan); ?></title>
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
            --purple: #7c3aed;
            --purple-soft: #f5f3ff;
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
        .header-title p .kod {
            display: inline-block; padding: 1px 7px;
            background: var(--red-soft); color: var(--red);
            border-radius: 100px; font-size: 10px; font-weight: 700; margin-left: 4px;
        }
        .header-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .donem-select {
            padding: 9px 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 12.5px; font-weight: 600; color: var(--text-1);
            background: #fff; border: 1px solid var(--border); border-radius: 10px;
            outline: none; cursor: pointer; transition: all 0.2s ease;
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
            background-position: right 0.55rem center;
            background-repeat: no-repeat;
            background-size: 1.4em 1.4em;
            padding-right: 2.4rem;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        .donem-select:focus { border-color: rgba(239, 68, 68, 0.5); box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1); }
        .btn-print {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; background: var(--red,#ef4444); color: #fff;
            border: none; border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        .btn-print:hover { background: var(--red); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(239, 68, 68, 0.3); }
        .btn-ekstre {
            display: inline-flex; align-items: center; gap: 7px;
            padding: 9px 16px; background: #fff; color: var(--text-1);
            border: 1px solid var(--border); border-radius: 10px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif; font-size: 12.5px; font-weight: 700;
            cursor: pointer; transition: all 0.2s ease;
        }
        .btn-ekstre:hover { background: var(--indigo-soft); color: var(--indigo); border-color: rgba(79, 70, 229, 0.3); transform: translateY(-1px); }
        .btn-ekstre i { color: var(--indigo); }

        .ekstre-modal-overlay {
            position: fixed; inset: 0;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            display: none;
            align-items: center; justify-content: center;
            z-index: 100;
            padding: 20px;
            animation: overlayIn 0.2s ease;
        }
        .ekstre-modal-overlay.active { display: flex; }
        .ekstre-modal {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.25);
            max-width: 460px; width: 100%;
            overflow: hidden;
            animation: modalIn 0.25s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .ekstre-modal-head {
            padding: 20px 22px 14px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
        }
        .ekstre-modal-head h3 {
            font-size: 15px; font-weight: 700; color: var(--text-1);
            margin: 0; display: flex; align-items: center; gap: 8px;
        }
        .ekstre-modal-head h3 i { color: var(--indigo); font-size: 14px; }
        .ekstre-modal-head .close-btn {
            width: 30px; height: 30px;
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; cursor: pointer; color: var(--text-2);
            transition: all 0.15s ease; background: transparent; border: none;
        }
        .ekstre-modal-head .close-btn:hover { background: rgba(0,0,0,0.05); color: var(--text-1); }
        .ekstre-modal-body {
            padding: 18px 22px 22px;
        }
        .ekstre-modal-body p {
            margin: 0 0 14px; font-size: 12.5px; color: var(--text-2);
        }
        .ekstre-format-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 12px;
        }
        .ekstre-format-btn {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 8px;
            padding: 22px 16px;
            border-radius: 14px;
            border: 1.5px solid var(--border);
            background: #fff;
            cursor: pointer;
            transition: all 0.2s ease;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            color: var(--text-1);
            text-decoration: none;
        }
        .ekstre-format-btn .icon {
            width: 44px; height: 44px;
            border-radius: 12px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 18px;
        }
        .ekstre-format-btn .label { font-size: 13px; font-weight: 700; }
        .ekstre-format-btn .desc { font-size: 10.5px; color: var(--text-3); font-weight: 500; }
        .ekstre-format-btn.pdf .icon { background: var(--red-soft); color: var(--red); }
        .ekstre-format-btn.excel .icon { background: var(--emerald-soft); color: var(--emerald); }
        .ekstre-format-btn.pdf:hover { border-color: rgba(111, 16, 34, 0.4); background: var(--red-soft); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(111, 16, 34, 0.12); }
        .ekstre-format-btn.excel:hover { border-color: rgba(5, 150, 105, 0.4); background: var(--emerald-soft); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(5, 150, 105, 0.12); }

        @keyframes overlayIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes modalIn {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

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
        .hareket-table .tr-type a {
            text-decoration: none; font-weight: 500;
            transition: opacity 0.15s ease;
        }
        .hareket-table .tr-type a:hover { text-decoration: underline; }
        .hareket-table .tr-type a.tr-nakit    { color: var(--emerald); }
        .hareket-table .tr-type a.tr-hizmet   { color: var(--purple); }
        .hareket-table .tr-type a.tr-fatura   { color: var(--sky); }
        .hareket-table .tr-type a.tr-ceksen   { color: var(--indigo); }
        .hareket-table .tr-type i {
            color: var(--text-3); margin-right: 6px; font-size: 11px;
        }
        .hareket-table .aciklama {
            color: var(--text-2); font-size: 12px;
            max-width: 280px;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
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
            .header-inner { padding: 12px 14px; gap: 10px; }
            .header-title h1 { font-size: 14px; }
            .header-title p { font-size: 10.5px; }
            .header-back { width: 30px; height: 30px; border-radius: 8px; }
            .header-actions { width: 100%; justify-content: flex-start; }
            .donem-select { font-size: 12px; padding: 8px 32px 8px 10px; }
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
            .hareket-table td.aciklama { grid-column: 1 / -1; font-size: 11px; max-width: none; white-space: normal; }
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
            <a href="<?php echo trim((string) $cari_kod) !== '' ? 'lg_bakiye.php?q=' . rawurlencode(trim((string) $cari_kod)) : 'lg_bakiye.php'; ?>" class="header-back" title="Bakiyeye Geri Dön">
                <i class="fa fa-arrow-left"></i>
            </a>
            <div class="header-divider"></div>
            <div class="header-title">
                <h1><i class="fa-solid fa-right-left"></i>Cari Hareket Dokumu</h1>
                <p>
                    <?php echo htmlspecialchars((string) $cari_unvan); ?>
                    <?php if ($cari_kod !== ''): ?>
                        <span class="kod"><?php echo htmlspecialchars((string) $cari_kod); ?></span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="header-actions">
                <select id="donemSecim" onchange="donemDegistir(this.value)" class="donem-select" aria-label="Donem secimi">
                    <option value="aktif" <?php echo $donemSecim === 'aktif' ? 'selected' : ''; ?>>2026 (Aktif)</option>
                    <option value="onceki" <?php echo $donemSecim === 'onceki' ? 'selected' : ''; ?>>2025 (Onceki)</option>
                    <option value="tumu" <?php echo $donemSecim === 'tumu' ? 'selected' : ''; ?>>Tum Donemler</option>
                </select>
                <button onclick="ekstreModalAc()" class="btn-ekstre" type="button">
                    <i class="fa-solid fa-file-export"></i> Ekstre
                </button>
                <button onclick="window.print()" class="btn-print" type="button">
                    <i class="fa-solid fa-print"></i> Yazdir
                </button>
            </div>
        </div>
    </header>

    <div class="ekstre-modal-overlay" id="ekstreModal" onclick="ekstreOverlayTikla(event)">
        <div class="ekstre-modal" role="dialog" aria-modal="true" aria-labelledby="ekstreModalTitle">
            <div class="ekstre-modal-head">
                <h3 id="ekstreModalTitle"><i class="fa-solid fa-file-export"></i>Ekstre Formati Sec</h3>
                <button type="button" class="close-btn" onclick="ekstreModalKapat()" aria-label="Kapat">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            <div class="ekstre-modal-body">
                <p>Cari hareket ekstresini hangi formatta indirmek istiyorsunuz?</p>
                <div class="ekstre-format-grid">
                    <a href="#" class="ekstre-format-btn pdf" id="ekstrePdfBtn">
                        <span class="icon"><i class="fa-solid fa-file-pdf"></i></span>
                        <span class="label">PDF</span>
                        <span class="desc">Yazdirma / Paylasim</span>
                    </a>
                    <a href="#" class="ekstre-format-btn excel" id="ekstreExcelBtn">
                        <span class="icon"><i class="fa-solid fa-file-excel"></i></span>
                        <span class="label">Excel</span>
                        <span class="desc">Duzenlenebilir tablo</span>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function donemDegistir(donem) {
            const url = new URL(window.location.href);
            url.searchParams.set('donem', donem);
            window.location.href = url.toString();
        }

        const EKSTRE_PARAMS = {
            cariid: <?php echo (int)$CARIID; ?>,
            donem: <?php echo json_encode((string)$donemSecim); ?>
        };

        function ekstreModalAc() {
            const modal = document.getElementById('ekstreModal');
            const qs = '?cariid=' + EKSTRE_PARAMS.cariid + '&donem=' + encodeURIComponent(EKSTRE_PARAMS.donem);
            document.getElementById('ekstrePdfBtn').href = 'lg_hareket_ekstre_pdf.php' + qs;
            document.getElementById('ekstreExcelBtn').href = 'lg_hareket_ekstre_excel.php' + qs;
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function ekstreModalKapat() {
            document.getElementById('ekstreModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function ekstreOverlayTikla(e) {
            if (e.target.id === 'ekstreModal') {
                ekstreModalKapat();
            }
        }

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                ekstreModalKapat();
            }
        });

        document.getElementById('ekstrePdfBtn').addEventListener('click', ekstreModalKapat);
        document.getElementById('ekstreExcelBtn').addEventListener('click', ekstreModalKapat);
    </script>

    <main style="max-width:1200px;margin:0 auto;padding:22px 24px 60px;">
        <?php
        // PHP HESAPLAMA KODLARI BURADA BAŞLIYOR
        $toplam_borc = 0;
        $toplam_alacak = 0;
        $bakiye = 0;

        // RESMİ BAKİYE: GNTOTCL view'ından al (lg_bakiye.php ile aynı kaynak)
        // Bu her zaman güncel ve doğru bakiyeyi verir
        $stmtResmi = $dbh->prepare("SELECT (ISNULL(G.DEBIT, 0) - ISNULL(G.CREDIT, 0)) AS BAKIYE
            FROM {$firmadonemx}GNTOTCL G WITH(NOLOCK)
            WHERE G.CARDREF = :cariid AND G.TOTTYP = 1");
        $stmtResmi->execute([':cariid' => $CARIID]);
        $resmi_bakiye = (float)$stmtResmi->fetchColumn();

        // Dönem seçimine göre kullanılacak tablo prefixlerini belirle
        $donemPrefixler = match($donemSecim) {
            'onceki' => [$eskifirmadonem],
            'tumu' => [$firmadonem, $eskifirmadonem],
            default => [$firmadonem], // aktif
        };

        // TRCODE CASE ifadesi (tekrar kullanım için)
        $trcodeCaseExpr = "CASE HAREKET.TRCODE
            WHEN 1 THEN 'Nakit Tahsilat' WHEN 2 THEN 'Nakit Ödeme' WHEN 3 THEN 'Borç Dekontu' WHEN 4 THEN 'Alacak Dekontu' WHEN 5 THEN 'Virman Fişi'
            WHEN 6 THEN 'Kur Farkı Fişi' WHEN 12 THEN 'Özel Fiş' WHEN 14 THEN 'Açılış Fişi' WHEN 20 THEN 'Gelen Havale' WHEN 21 THEN 'Gönderilen Havale'
            WHEN 24 THEN 'Döviz Alış Belgesi' WHEN 25 THEN 'Döviz Satış belgesi' WHEN 28 THEN 'Alınan Hizmet Faturası' WHEN 29 THEN 'Verilen Hizmet Faturası'
            WHEN 31 THEN 'Satın Alma Faturası' WHEN 32 THEN 'Perakende Satış İade Faturası' WHEN 33 THEN 'Toptan Satış İade Faturası'
            WHEN 34 THEN 'Alınan Hizmet Faturası' WHEN 35 THEN 'Alınan Proforma Fatura' WHEN 36 THEN 'Satın Alma İade Faturası'
            WHEN 37 THEN 'Perakende Satış Faturası' WHEN 38 THEN 'Toptan Satış Faturası' WHEN 39 THEN 'Verilen Hizmet Faturası'
            WHEN 40 THEN 'Verilen proforma fatura' WHEN 41 THEN 'Verilen Vade Farkı Faturası' WHEN 42 THEN 'Alınan Vade Farkı Faturası'
            WHEN 43 THEN 'Satın Alma Fiyat Farkı Faturası' WHEN 44 THEN 'Satış Fiyat Farkı Faturası' WHEN 45 THEN 'Verilen Serbest Meslek Makbuzu'
            WHEN 46 THEN 'Alınan Serbest Meslek Makbuzu' WHEN 56 THEN 'Müstahsil Makbuzu'
            WHEN 61 THEN 'Çek Girişi' WHEN 62 THEN 'Senet Girişi'
            WHEN 63 THEN 'Çek Çıkışı (Cari Hesaba)' WHEN 64 THEN 'Senet Çıkışı (Cari Hesaba)'
            WHEN 70 THEN 'Kredi Kartı Fişi' WHEN 71 THEN 'Kredi Kartı İade Fişi' WHEN 72 THEN 'Firma Kredi Kartı Fişi' WHEN 73 THEN 'Firma Kredi Kartı İade Fişi'
            WHEN 81 THEN 'Satınalma Siparişi' WHEN 82 THEN 'Satış Siparişi'
          END";

        // Her dönem için sorgu parçası oluştur (cariid değeri doğrudan SQL'e yerleştirilecek)
        $cariIdSafe = (int)$CARIID; // SQL injection koruması
        $queryParts = [];
        foreach ($donemPrefixler as $prefix) {
            // "Tüm Dönemler" seçildiğinde, TÜM dönemlerin açılış fişlerini (TRCODE=14) hariç tut
            // Çünkü lg_bakiye.php sadece aktif dönemin GNTOTCL view'ını kullanıyor
            // Bu view açılış fişlerini içsel olarak hesaba katıyor, biz sadece gerçek işlemleri göstermeliyiz
            // Böylece lg_bakiye.php ve lg_hareket.php aynı bakiyeyi gösterir
            $acilisFiltresi = '';
            if ($donemSecim === 'tumu') {
                $acilisFiltresi = ' AND HAREKET.TRCODE <> 14'; // 14 = Açılış Fişi (tüm dönemlerden hariç tut)
            }

            $queryParts[] = "
            SELECT
              HAREKET.LOGICALREF,
              HAREKET.DATE_,
              HAREKET.TRANNO AS DOCODE,
              HAREKET.SOURCEFREF,
              HAREKET.TRCODE AS TRCODE_NO,
              {$trcodeCaseExpr} AS TRCODE,
              KART.LOGICALREF AS KART_REF,
              KART.CODE,
              KART.DEFINITION_,
              HAREKET.LINEEXP AS ACIKLAMA,
              ISNULL((1 - HAREKET.SIGN) * HAREKET.AMOUNT, 0) AS BORC,
              ISNULL(HAREKET.SIGN * HAREKET.AMOUNT, 0) AS ALACAK,
              -- ONEMLI: cek/senet girisinde SOURCEFREF bir CSROLL (bordro) referansidir.
              -- Ayni LOGICALREF sayisi CSTRANS'ta da bulunabilir (ID-uzayi cakismasi) ve
              -- alakasiz bir cek-i tutturup yanlis CSREF uretir. Bu yuzden once BORDRO
              -- yolunu (J) deneriz; yalnizca o boşsa dogrudan CSTRANS eslesmesine (CS) duseriz.
              COALESCE(J.CSREF_FROM_ROLL, CS.CSREF) AS CSREF,
              COALESCE(INV1.LOGICALREF, STF.INVOICEREF) AS INVOICE_REF,
              '{$prefix}' AS DONEM_PREFIX
            FROM {$prefix}CLFLINE AS HAREKET
            JOIN {$firma}CLCARD AS KART ON HAREKET.CLIENTREF = KART.LOGICALREF
            LEFT JOIN {$prefix}CSTRANS AS CS ON CS.LOGICALREF = HAREKET.SOURCEFREF
            OUTER APPLY (
              SELECT TOP (1) t.CSREF AS CSREF_FROM_ROLL
              FROM {$prefix}CSROLL r
              JOIN {$prefix}CSTRANS t ON t.ROLLREF = r.LOGICALREF
              WHERE r.LOGICALREF = HAREKET.SOURCEFREF
              ORDER BY t.LOGICALREF DESC
            ) AS J
            LEFT JOIN {$prefix}STFICHE AS STF ON STF.LOGICALREF = HAREKET.SOURCEFREF
            LEFT JOIN {$prefix}INVOICE AS INV1 ON INV1.LOGICALREF = HAREKET.SOURCEFREF
            WHERE HAREKET.CANCELLED = 0 AND KART.LOGICALREF = {$cariIdSafe}{$acilisFiltresi}";
        }

        // Sorguları UNION ALL ile birleştir ve subquery olarak sırala
        $sql_sorgu_str = "SELECT * FROM (" . implode(" UNION ALL ", $queryParts) . ") AS BIRLESIK ORDER BY DATE_ ASC, LOGICALREF ASC";

        $stmt = $dbh->prepare($sql_sorgu_str);
        $stmt->execute();
        $hareketler = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($hareketler as $hareket) {
            $toplam_borc += $hareket['BORC'];
            $toplam_alacak += $hareket['ALACAK'];
        }

        // Gösterilen işlemlerin net etkisi (GNTOTCL formatında: BORC - ALACAK)
        $islem_net_etki = $toplam_borc - $toplam_alacak;

        // Başlangıç bakiyesi = Resmi bakiye - Gösterilen işlemlerin etkisi
        // Böylece son satırda resmi bakiyeye ulaşırız
        $baslangic_bakiye = $resmi_bakiye - $islem_net_etki;

        // Tabloda kullanılacak bakiye değişkeni (başlangıç bakiyesinden başlar)
        $bakiye = $baslangic_bakiye;
        ?>

        <div class="stat-grid">
            <div class="stat-card emerald">
                <span class="icon-box"><i class="fa-solid fa-arrow-down"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Toplam Giris (Alacak)</span>
                    <span class="stat-value"><?php echo paraformat($toplam_alacak); ?> &#8378;</span>
                </div>
            </div>
            <div class="stat-card red">
                <span class="icon-box"><i class="fa-solid fa-arrow-up"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Toplam Cikis (Borc)</span>
                    <span class="stat-value"><?php echo paraformat($toplam_borc); ?> &#8378;</span>
                </div>
            </div>
            <?php
            $bakiye_pozitif = $resmi_bakiye > 0;
            $bakiyeClass = $bakiye_pozitif ? 'emerald' : 'red';
            $bakiyeLabel = $bakiye_pozitif ? 'Borclu' : 'Alacakli';
            if (abs($resmi_bakiye) < 0.01) {
                $bakiyeClass = 'indigo';
                $bakiyeLabel = '';
            }
            ?>
            <div class="stat-card <?php echo $bakiyeClass; ?>">
                <span class="icon-box"><i class="fa-solid fa-scale-balanced"></i></span>
                <div class="stat-body">
                    <span class="stat-label">Genel Bakiye<?php echo $bakiyeLabel !== '' ? ' ('.$bakiyeLabel.')' : ''; ?></span>
                    <span class="stat-value"><?php echo paraformat(abs($resmi_bakiye)); ?> &#8378;</span>
                </div>
            </div>
        </div>

        <div class="table-card">
            <div style="overflow-x:auto;">
                <table class="hareket-table">
                    <thead>
                        <tr>
                            <th>Tarih</th>
                            <th>Islem Turu</th>
                            <th>Aciklama</th>
                            <th class="right">Giris</th>
                            <th class="right">Cikis</th>
                            <th class="right">Bakiye</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($hareketler)) {
                            echo '<tr class="empty-row"><td colspan="6"><i class="fa-solid fa-inbox"></i>Bu cari icin goruntulenecek hareket bulunamadi.</td></tr>';
                        } else {
                            // Baslangic bakiyesi varsa ilk satir olarak goster
                            if (abs($baslangic_bakiye) > 0.01) {
                                $basClass = $baslangic_bakiye >= 0 ? 'pozitif' : 'negatif';
                                echo '<tr class="devir-row">';
                                echo '<td class="date">-</td>';
                                echo '<td class="tr-type"><i class="fa-solid fa-clock-rotate-left"></i>Onceki Donem Bakiyesi</td>';
                                echo '<td class="aciklama">Devir</td>';
                                echo '<td class="right giris"><span class="muted">-</span></td>';
                                echo '<td class="right cikis"><span class="muted">-</span></td>';
                                echo '<td class="right bakiye ' . $basClass . '">' . paraformat($baslangic_bakiye) . ' &#8378;</td>';
                                echo '</tr>';
                            }

                            foreach ($hareketler as $rowx) {
                                $icon = match ($rowx['TRCODE']) {
                                    'Nakit Tahsilat'           => '<i class="fa-solid fa-money-bill-wave"></i>',
                                    'Nakit Ödeme'              => '<i class="fa-solid fa-hand-holding-dollar"></i>',
                                    'Çek Girişi'               => '<i class="fa-solid fa-receipt"></i>',
                                    'Çek Çıkışı (Cari Hesaba)' => '<i class="fa-solid fa-file-invoice-dollar"></i>',
                                    'Senet Girişi'             => '<i class="fa-solid fa-file-lines"></i>',
                                    'Senet Çıkışı (Cari Hesaba)' => '<i class="fa-solid fa-file-invoice"></i>',
                                    'Satın Alma Faturası'      => '<i class="fa-solid fa-cart-arrow-down"></i>',
                                    'Toptan Satış Faturası'    => '<i class="fa-solid fa-cart-shopping"></i>',
                                    default                    => '<i class="fa-solid fa-file"></i>',
                                };

                                if ($rowx['BORC'] > 0)   { $bakiye += $rowx['BORC']; }
                                if ($rowx['ALACAK'] > 0) { $bakiye -= $rowx['ALACAK']; }
                                $bakiyeRowClass = $bakiye >= 0 ? 'pozitif' : 'negatif';

                                $isHizmetFaturasi = isset($rowx['TRCODE_NO']) && in_array((int)$rowx['TRCODE_NO'], [28, 29, 34, 39]);
                                $isNakit = in_array($rowx['TRCODE'], ['Nakit Tahsilat', 'Nakit Ödeme']);
                                $isInvoice = isset($rowx['TRCODE_NO']) && in_array((int)$rowx['TRCODE_NO'], [31, 32, 33, 36, 37, 38]);
                                $isCekSenet = isset($rowx['TRCODE_NO'])
                                    ? in_array((int)$rowx['TRCODE_NO'], [61, 62, 63, 64])
                                    : in_array($rowx['TRCODE'], ['Çek Girişi', 'Senet Girişi', 'Çek Çıkışı (Cari Hesaba)', 'Senet Çıkışı (Cari Hesaba)']);
                                $eskiDonem = isset($rowx['DONEM_PREFIX']) && strpos($rowx['DONEM_PREFIX'], '_01_') !== false;

                                echo '<tr>';
                                echo '<td class="date">' . tarihcevir($rowx['DATE_']) . '</td>';
                                echo '<td class="tr-type">';

                                if ($isNakit && !empty($rowx['SOURCEFREF'])) {
                                    $nakitSayfa = $eskiDonem ? 'lg_nakit_2025.php' : 'lg_nakit.php';
                                    echo '<a href="' . $nakitSayfa . '?cari_id=' . $CARIID . '&REF=' . (int)$rowx['SOURCEFREF'] .
                                        '" target="_blank" data-fis-link="1" class="tr-nakit">' .
                                        $icon . htmlspecialchars((string) $rowx['TRCODE']) . '</a>';
                                } elseif ($isHizmetFaturasi && !empty($rowx['SOURCEFREF'])) {
                                    $hizmetSayfa = $eskiDonem ? 'lg_alinan-hizmet_2025.php' : 'lg_alinan-hizmet.php';
                                    echo '<a href="' . $hizmetSayfa . '?cari_id=' . $CARIID . '&REF=' . (int)$rowx['SOURCEFREF'] .
                                        '" target="_blank" data-fis-link="1" class="tr-hizmet">' .
                                        $icon . htmlspecialchars((string) $rowx['TRCODE']) . '</a>';
                                } elseif ($isInvoice && !empty($rowx['INVOICE_REF'])) {
                                    $trcodex = in_array((int)$rowx['TRCODE_NO'], [31, 36]) ? 1 : 8;
                                    $islem = in_array((int)$rowx['TRCODE_NO'], [32, 33, 36]) ? 2 : 1;
                                    $faturaSayfa = $eskiDonem ? 'lg_fatura_yazdir_2025.php' : 'lg_fatura_yazdir.php';
                                    echo '<a href="' . $faturaSayfa . '?cari_id=' . $CARIID . '&trcode=' . $trcodex .
                                        '&REF=' . (int)$rowx['INVOICE_REF'] . '&islem=' . $islem .
                                        '" target="_blank" data-fis-link="1" class="tr-fatura">' .
                                        $icon . htmlspecialchars((string) $rowx['TRCODE']) . '</a>';
                                } elseif ($isCekSenet) {
                                    $cekSayfa = $eskiDonem ? 'cek_2025.php' : 'cek.php';
                                    $trno = (int) ($rowx['TRCODE_NO'] ?? 0);
                                    $cekUrl = null;
                                    if (in_array($trno, [63, 64], true) && !empty($rowx['SOURCEFREF'])) {
                                        // Cek/senet cikis (cari hesaba ciro) bir bordrodur: SOURCEFREF -> CSROLL.
                                        // Bordrodaki tum cekleri gostermek icin ROLLREF ile ac (ID cakismasi onlenir).
                                        $cekUrl = $cekSayfa . '?cari_id=' . $CARIID . '&ROLLREF=' . (int) $rowx['SOURCEFREF'];
                                    } elseif (!empty($rowx['CSREF'])) {
                                        // Cek/senet giris = tek cek detayi
                                        $cekUrl = $cekSayfa . '?cari_id=' . $CARIID . '&CSREF=' . (int) $rowx['CSREF'];
                                    }
                                    if ($cekUrl !== null) {
                                        echo '<a href="' . $cekUrl . '" target="_blank" data-fis-link="1" class="tr-ceksen">' .
                                            $icon . htmlspecialchars((string) $rowx['TRCODE']) . '</a>';
                                    } else {
                                        echo $icon . htmlspecialchars((string) $rowx['TRCODE']);
                                    }
                                } else {
                                    echo $icon . htmlspecialchars((string) $rowx['TRCODE']);
                                }

                                echo '</td>';
                                echo '<td class="aciklama">' . htmlspecialchars((string) $rowx['ACIKLAMA']) . '</td>';
                                echo '<td class="right giris">' . ($rowx['ALACAK'] > 0 ? paraformat($rowx['ALACAK']) . ' &#8378;' : '<span class="muted">-</span>') . '</td>';
                                echo '<td class="right cikis">' . ($rowx['BORC'] > 0 ? paraformat($rowx['BORC']) . ' &#8378;' : '<span class="muted">-</span>') . '</td>';
                                echo '<td class="right bakiye ' . $bakiyeRowClass . '">' . paraformat($bakiye) . ' &#8378;</td>';
                                echo '</tr>';
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <script>
    (function() {
        const scrollKey = 'lg_hareket_scroll_' + <?php echo json_encode((string) $CARIID); ?> + '_' + <?php echo json_encode((string) $donemSecim); ?>;

        function saveScrollPosition() {
            try {
                sessionStorage.setItem(scrollKey, String(window.scrollY || window.pageYOffset || 0));
            } catch (e) {
                // sessionStorage engelliyse sessizce devam et
            }
        }

        function restoreScrollPosition() {
            try {
                const saved = sessionStorage.getItem(scrollKey);
                if (saved === null) {
                    return;
                }

                const y = parseInt(saved, 10);
                if (!Number.isNaN(y) && y > 0) {
                    window.scrollTo(0, y);
                }
            } catch (e) {
                // sessionStorage engelliyse sessizce devam et
            }
        }

        const fisLinks = document.querySelectorAll('a[data-fis-link="1"]');
        const isTouchDevice = window.matchMedia('(hover: none), (pointer: coarse)').matches;

        fisLinks.forEach((link) => {
            if (isTouchDevice) {
                link.removeAttribute('target');
            }

            link.addEventListener('click', saveScrollPosition, {
                passive: true
            });
        });

        window.addEventListener('pagehide', saveScrollPosition);
        window.addEventListener('beforeunload', saveScrollPosition);
        window.addEventListener('pageshow', restoreScrollPosition);

        restoreScrollPosition();
    })();
    </script>

</body>

</html>

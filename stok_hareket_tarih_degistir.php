<?php
declare(strict_types=1);

require_once __DIR__ . '/kontrol.php';
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/log_ip.php");

$stokhareket = isset($_GET['stokhareket']) ? (int) $_GET['stokhareket'] : 0;
if ($stokhareket <= 0) {
    echo "<script>if (window.toast) { toast('Geçersiz stok hareketi!', 'error'); } else { alert('Geçersiz stok hareketi!'); } window.location='index.php';</script>";
    exit;
}

if (isset($_POST['yeni_tarih'])) {
    // CSRF koruması (POST istekleri için)
    if (!csrf_verify()) {
        http_response_code(403);
        die('Geçersiz güvenlik doğrulaması.');
    }

    $yeni_tarih = $_POST['yeni_tarih'];

    // Stok hareketinin tarihini güncelle
    $stmt = $dbh->prepare("UPDATE {$firmadonem}ORFICHE SET DATE_ = :tarih WHERE LOGICALREF = :stokhareket");
    $gncl = $stmt->execute([':tarih' => $yeni_tarih, ':stokhareket' => $stokhareket]);

    if ($gncl) {
        echo "<script>if (window.toast) { toast('Stok hareketinin tarihi güncellendi!', 'success'); } else { alert('Stok hareketinin tarihi güncellendi!'); } window.location='index.php';</script>";
    } else {
        echo "<script>if (window.toast) { toast('Hata oluştu!', 'error'); } else { alert('Hata oluştu!'); }</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lumen - Tarih Degistir</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/pwa-header.php')) { include_once(__DIR__ . '/pwa-header.php'); } ?>
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
            --sky: #0284c7;
            --sky-soft: #eff6ff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background:
                radial-gradient(1200px 600px at 8% -10%, rgba(56, 189, 248, .14), transparent 60%),
                radial-gradient(900px 500px at 92% 110%, rgba(99, 102, 241, .08), transparent 55%),
                var(--bg);
            color: var(--text-1);
            min-height: 100vh;
        }

        /* TOP HEADER */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 50;
            background: rgba(255, 255, 255, 0.92);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            padding: 12px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .top-header .back-btn {
            width: 38px;
            height: 38px;
            border-radius: 11px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text-2);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .top-header .back-btn:hover {
            color: var(--sky);
            border-color: rgba(2, 132, 199, 0.35);
            background: var(--sky-soft);
        }
        .top-header .divider {
            width: 1px;
            height: 26px;
            background: var(--border);
            flex-shrink: 0;
        }
        .top-header .header-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14.5px;
            font-weight: 600;
            color: var(--text-1);
            letter-spacing: -0.1px;
            min-width: 0;
        }
        .top-header .header-title .title-icon {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            background: var(--sky-soft);
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
        }
        .top-header .header-title span {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* MAIN */
        .main-wrap {
            max-width: 480px;
            margin: 0 auto;
            padding: 28px 20px 40px;
            animation: pageRise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(56, 189, 248, 0.22);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            padding: 36px 28px 30px;
            animation: cardIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* HERO */
        .hero {
            text-align: center;
            margin-bottom: 26px;
        }
        .hero .icon-box {
            width: 68px;
            height: 68px;
            border-radius: 18px;
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            color: var(--sky);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 14px;
            box-shadow: 0 6px 18px rgba(56, 189, 248, 0.18);
        }
        .hero h1 {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-1);
            letter-spacing: -0.2px;
        }
        .hero .subtitle {
            margin-top: 6px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--text-3);
        }

        /* FIELDS */
        .field {
            margin-bottom: 20px;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.15s both;
        }
        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .input-wrap { position: relative; }
        .input-wrap .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-3);
            font-size: 14px;
            transition: color 0.25s ease;
            pointer-events: none;
        }
        .field-control {
            width: 100%;
            padding: 13px 14px 13px 42px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-1);
            background: #fff;
            outline: none;
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
        }
        .field-control:focus {
            border-color: rgba(2, 132, 199, 0.5);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.12);
        }
        .field-control:focus ~ .input-icon { color: var(--sky); }

        /* SUBMIT */
        .btn-submit {
            width: 100%;
            margin-top: 6px;
            padding: 14px 22px;
            border: none;
            border-radius: 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 700;
            color: #fff;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--red,#ef4444);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.28);
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.28s both;
        }
        .btn-submit:hover {
            background: var(--red);
            transform: translateY(-1px);
            box-shadow: 0 10px 26px rgba(239, 68, 68, 0.38);
        }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit i { font-size: 13px; }

        /* ANIMATIONS */
        @keyframes pageRise {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translate3d(0, 14px, 0) scale(0.985); }
            to { opacity: 1; transform: translate3d(0, 0, 0) scale(1); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* RESPONSIVE */
        @media (max-width: 480px) {
            .top-header { padding: 10px 14px; }
            .top-header .header-title { font-size: 13.5px; }
            .main-wrap { padding: 22px 14px 32px; }
            .glass-card { padding: 28px 20px 24px; border-radius: 16px; }
            .hero .icon-box { width: 58px; height: 58px; font-size: 20px; }
            .hero h1 { font-size: 18px; }
            .field-control {
                font-size: 16px; /* iOS zoom engeli */
                padding: 13px 14px 13px 40px;
            }
            .btn-submit { font-size: 14px; padding: 13px 20px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .main-wrap, .glass-card, .hero, .field, .btn-submit {
                animation: none !important;
                transition: none !important;
                transform: none !important;
                opacity: 1 !important;
            }
        }
    </style>
</head>
<body>

    <header class="top-header">
        <a href="javascript:history.back()" class="back-btn" aria-label="Geri">
            <i class="fa-solid fa-arrow-left"></i>
        </a>
        <span class="divider"></span>
        <div class="header-title">
            <span class="title-icon"><i class="fa-solid fa-calendar-day"></i></span>
            <span>Tarih Degistir</span>
        </div>
    </header>

    <main class="main-wrap">
        <div class="glass-card">

            <div class="hero">
                <span class="icon-box"><i class="fa-solid fa-calendar-day"></i></span>
                <h1>Tarih Degistir</h1>
                <div class="subtitle">Stok hareket tarihini guncelleyin</div>
            </div>

            <form method="POST" action="">
                <?php echo csrf_field(); ?>
                <div class="field">
                    <label for="yeni_tarih" class="field-label">Yeni Tarih</label>
                    <div class="input-wrap">
                        <input type="date" id="yeni_tarih" name="yeni_tarih" required
                               class="field-control">
                        <i class="fa-solid fa-calendar input-icon"></i>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-check"></i>
                    <span>Tarihi Degistir</span>
                </button>
            </form>
        </div>
    </main>

    <?php include_once(__DIR__ . '/ux_katman.php'); ?>
</body>
</html>

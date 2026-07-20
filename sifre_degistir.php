<?php
declare(strict_types=1);

include_once(__DIR__ . "/log_ip.php");
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/kontrol.php");

$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF koruması
    if (!csrf_verify()) {
        $error = "Geçersiz güvenlik doğrulaması. Lütfen sayfayı yenileyip tekrar deneyin.";
    } else {
        $eski = isset($_POST['eski_sifre']) ? trim((string) $_POST['eski_sifre']) : '';
        $yeni = isset($_POST['yeni_sifre']) ? trim((string) $_POST['yeni_sifre']) : '';
        $yeni2 = isset($_POST['yeni_sifre2']) ? trim((string) $_POST['yeni_sifre2']) : '';

        if ($eski === '' || $eski === '0' || ($yeni === '' || $yeni === '0') || ($yeni2 === '' || $yeni2 === '0')) {
            $error = "Lütfen tüm alanları doldurunuz.";
        } elseif (strlen($yeni) < 6) {
            $error = "Yeni şifre en az 6 karakter olmalıdır.";
        } elseif ($yeni !== $yeni2) {
            $error = "Yeni şifreler eşleşmiyor.";
        } else {
            $uid = $_SESSION['plasiyer_id'];
            $stmt = $dbh->prepare("SELECT SIFRE FROM M_P_YETKI WHERE PERSONEL = :uid");
            $stmt->bindParam(':uid', $uid);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $error = "Kullanıcı bilgisi bulunamadı.";
            } elseif (!sifre_dogrula($eski, $row['SIFRE'])) {
                $error = "Eski şifre hatalı.";
            } else {
                $hashedPassword = sifre_hashle($yeni);
                $update = $dbh->prepare("UPDATE M_P_YETKI SET SIFRE = :yeni WHERE PERSONEL = :uid");
                $update->bindParam(':yeni', $hashedPassword);
                $update->bindParam(':uid', $uid);
                if ($update->execute()) {
                    // Şifre değiştiğinde yetki cache'ini temizle
                    m_p_yetki_cache_temizle($uid);
                    $success = "Şifreniz başarıyla değiştirildi.";
                } else {
                    $error = "Şifre güncellenirken hata oluştu.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lumen - Sifre Degistir</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (is_file(__DIR__ . '/pwa-header.php')) { include_once(__DIR__ . '/pwa-header.php'); } ?>
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
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background:
                radial-gradient(1200px 600px at 8% -10%, rgba(248, 113, 113, .14), transparent 60%),
                radial-gradient(900px 500px at 92% 110%, rgba(99, 102, 241, .08), transparent 55%),
                var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .page-wrapper {
            width: 100%;
            max-width: 440px;
            animation: pageRise 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        .glass-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(248, 113, 113, 0.22);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            padding: 38px 30px 32px;
            animation: cardIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        /* HERO */
        .hero {
            text-align: center;
            margin-bottom: 28px;
        }
        .hero .lock-box {
            width: 68px;
            height: 68px;
            border-radius: 18px;
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            color: var(--red);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            margin-bottom: 14px;
            box-shadow: 0 6px 18px rgba(239, 68, 68, 0.12);
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

        /* ALERTS */
        .alert-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 22px;
            animation: shake 0.42s ease;
        }
        .alert-bar i { font-size: 15px; flex-shrink: 0; }
        .alert-bar span { font-size: 12.5px; font-weight: 500; line-height: 1.4; }
        .alert-error {
            border: 1px solid rgba(239, 68, 68, 0.22);
            background: var(--red-soft);
        }
        .alert-error i { color: var(--red); }
        .alert-error span { color: #991b1b; }
        .alert-success {
            border: 1px solid rgba(5, 150, 105, 0.22);
            background: var(--emerald-soft);
            animation: fadeUp 0.42s ease;
        }
        .alert-success i { color: var(--emerald); }
        .alert-success span { color: #065f46; }

        /* FIELDS */
        .field {
            margin-bottom: 18px;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .field:nth-of-type(1) { animation-delay: 0.15s; }
        .field:nth-of-type(2) { animation-delay: 0.22s; }
        .field:nth-of-type(3) { animation-delay: 0.29s; }
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
        .field-control::placeholder { color: var(--text-3); font-weight: 400; }
        .field-control:focus {
            border-color: rgba(239, 68, 68, 0.5);
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.1);
        }
        .field-control:focus ~ .input-icon { color: var(--red); }

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
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.36s both;
        }
        .btn-submit:hover {
            background: var(--red);
            transform: translateY(-1px);
            box-shadow: 0 10px 26px rgba(239, 68, 68, 0.38);
        }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit i { font-size: 13px; }

        /* BACK LINK */
        .back-row {
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px dashed var(--border);
            text-align: center;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.42s both;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-2);
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        .back-link:hover {
            color: var(--red);
            background: var(--red-soft);
        }
        .back-link i { font-size: 12px; }

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
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20% { transform: translateX(-5px); }
            40% { transform: translateX(5px); }
            60% { transform: translateX(-3px); }
            80% { transform: translateX(3px); }
        }

        /* RESPONSIVE */
        @media (max-width: 480px) {
            body { padding: 14px; }
            .glass-card { padding: 30px 20px 24px; border-radius: 16px; }
            .hero .lock-box { width: 58px; height: 58px; font-size: 20px; }
            .hero h1 { font-size: 18px; }
            .field-control {
                font-size: 16px; /* iOS zoom engeli */
                padding: 13px 14px 13px 40px;
            }
            .btn-submit { font-size: 14px; padding: 13px 20px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .page-wrapper, .glass-card, .hero, .field,
            .btn-submit, .back-row, .alert-bar {
                animation: none !important;
                transition: none !important;
                transform: none !important;
                opacity: 1 !important;
            }
        }
    </style>
</head>
<body>

    <div class="page-wrapper">
        <div class="glass-card">

            <div class="hero">
                <span class="lock-box"><i class="fa-solid fa-lock"></i></span>
                <h1>Sifre Degistir</h1>
                <div class="subtitle">Guvenli yeni sifrenizi belirleyin</div>
            </div>

            <?php if ($error !== '' && $error !== '0'): ?>
                <div class="alert-bar alert-error" role="alert">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success !== '' && $success !== '0'): ?>
                <div class="alert-bar alert-success" role="status">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <?php echo csrf_field(); ?>

                <div class="field">
                    <label for="eski_sifre" class="field-label">Eski Sifre</label>
                    <div class="input-wrap">
                        <input type="password" id="eski_sifre" name="eski_sifre" required
                               class="field-control" placeholder="Mevcut sifreniz"
                               autocomplete="current-password">
                        <i class="fa-solid fa-key input-icon"></i>
                    </div>
                </div>

                <div class="field">
                    <label for="yeni_sifre" class="field-label">Yeni Sifre</label>
                    <div class="input-wrap">
                        <input type="password" id="yeni_sifre" name="yeni_sifre" required
                               class="field-control" placeholder="En az 6 karakter"
                               autocomplete="new-password" minlength="6">
                        <i class="fa-solid fa-lock input-icon"></i>
                    </div>
                </div>

                <div class="field">
                    <label for="yeni_sifre2" class="field-label">Yeni Sifre (Tekrar)</label>
                    <div class="input-wrap">
                        <input type="password" id="yeni_sifre2" name="yeni_sifre2" required
                               class="field-control" placeholder="Yeni sifreyi tekrar girin"
                               autocomplete="new-password" minlength="6">
                        <i class="fa-solid fa-lock input-icon"></i>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Sifremi Degistir</span>
                </button>
            </form>

            <div class="back-row">
                <a href="index.php" class="back-link">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Ana Sayfaya Don</span>
                </a>
            </div>
        </div>
    </div>

</body>
</html>

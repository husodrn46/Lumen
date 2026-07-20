<?php
declare(strict_types=1);

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);

$error_message = '';
$success_message = '';

if (isset($_POST['kodu'])) {
    ayar_require_csrf();

    $kodu = trim((string) $_POST['kodu']);
    $adi = trim((string) $_POST['adi']);
    $sifre = trim((string) $_POST['sifre']);
    $firma_no = $_POST['firma'];
    $tipi = $_POST['tipi'];

    $basari_durumu = true;

    if ($kodu === '' || $kodu === '0' || strlen($kodu) > 30) {
        $error_message = 'Personel kodu bos veya 30 karakterden buyuk olamaz.';
        $basari_durumu = false;
    }
    if ($adi === '' || $adi === '0' || strlen($adi) > 50) {
        $error_message = 'Personel adi bos veya 50 karakterden buyuk olamaz.';
        $basari_durumu = false;
    }
    if ($sifre === '' || $sifre === '0' || strlen($sifre) < 4) {
        $error_message = 'Sifre bos olamaz ve en az 4 karakter olmalidir.';
        $basari_durumu = false;
    }

    if ($basari_durumu) {
        $stmt_sorgu = $dbh->prepare("SELECT CODE FROM LG_SLSMAN WHERE CODE = :kodu AND FIRMNR = :firma_no");
        $stmt_sorgu->bindParam(':kodu', $kodu);
        $stmt_sorgu->bindParam(':firma_no', $firma_no);
        $stmt_sorgu->execute();

        if ($stmt_sorgu->fetch()) {
            $error_message = 'Dikkat! "' . htmlspecialchars($kodu) . '" personel kodu bu firmada zaten kullanilmis.';
            $basari_durumu = false;
        }
    }

    if ($basari_durumu == true) {
        $stmt_kayit = $dbh->prepare("INSERT INTO LG_SLSMAN (CODE, DEFINITION_, CARDTYPE, CAPIBLOCK_CREADEDDATE, USERID, FIRMNR, TYP, ACTIVE) VALUES (:kodu, :adi, 0, GETDATE(), 1, :firma_no, 0, 0)");
        $stmt_kayit->bindParam(':kodu', $kodu);
        $stmt_kayit->bindParam(':adi', $adi);
        $stmt_kayit->bindParam(':firma_no', $firma_no);

        if ($stmt_kayit->execute()) {
            $sonid = $dbh->lastInsertId();
            // Bos parolayla kullanici olusturma engellendi (validation yukarida) - guvenlik
            $sifreKayit = sifre_hashle($sifre);

            $stmt_yetki = $dbh->prepare("INSERT INTO M_P_YETKI (PERSONEL, SIFRE, YETKI) VALUES (:personel_id, :sifre, :yetki)");
            $stmt_yetki->bindParam(':personel_id', $sonid, PDO::PARAM_INT);
            $stmt_yetki->bindParam(':sifre', $sifreKayit);
            $stmt_yetki->bindParam(':yetki', $tipi);
            $stmt_yetki->execute();

            header('Location: mobilyetki.php');
            exit;
        }
        $error_message = 'Hata! Kullanici kaydedilemedi.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lumen - Yeni Kullanici Ekle</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
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
            --amber: #d97706;
            --amber-soft: #fffbeb;
            --sky: #0284c7;
            --sky-soft: #eff6ff;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            background:
                radial-gradient(1200px 600px at 8% -10%, rgba(99, 102, 241, .14), transparent 60%),
                radial-gradient(900px 500px at 92% 110%, rgba(2, 132, 199, .08), transparent 55%),
                var(--bg);
            color: var(--text-1);
            min-height: 100vh;
            padding-bottom: 40px;
        }

        /* STICKY HEADER */
        .top-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: rgba(255, 255, 255, 0.88);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border);
            padding: 12px 18px;
            margin-bottom: 18px;
        }
        .top-header-inner {
            max-width: 640px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .back-btn {
            width: 38px;
            height: 38px;
            min-width: 38px;
            border-radius: 10px;
            background: var(--indigo-soft);
            color: var(--indigo);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s ease;
            border: 1px solid rgba(79, 70, 229, 0.12);
        }
        .back-btn:hover {
            background: #e0e7ff;
            transform: translateX(-2px);
        }
        .top-header-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--indigo-soft), #e0e7ff);
            color: var(--indigo);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            box-shadow: 0 4px 12px rgba(79, 70, 229, 0.14);
        }
        .top-header-text { flex: 1; min-width: 0; }
        .top-header-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-1);
            letter-spacing: -0.2px;
            line-height: 1.2;
        }
        .top-header-sub {
            font-size: 11.5px;
            font-weight: 500;
            color: var(--text-3);
            margin-top: 2px;
        }

        /* PAGE WRAPPER */
        .page-wrapper {
            width: 100%;
            max-width: 560px;
            margin: 0 auto;
            padding: 0 18px;
        }

        /* GLASS CARD */
        .glass-card {
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            border: 1px solid rgba(79, 70, 229, 0.18);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(15, 23, 42, 0.08);
            padding: 30px 26px 26px;
            animation: cardIn 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
            will-change: transform, opacity;
            margin-bottom: 18px;
        }
        .glass-card:nth-of-type(2) { animation-delay: 0.08s; }

        /* HERO */
        .hero {
            background: linear-gradient(135deg, var(--indigo-soft) 0%, #e0e7ff 100%);
            border: 1px solid rgba(79, 70, 229, 0.18);
        }
        .hero-inner {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .hero .icon-box {
            width: 58px;
            height: 58px;
            min-width: 58px;
            border-radius: 16px;
            background: linear-gradient(135deg, #ffffff, #eef2ff);
            color: var(--indigo);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 6px 18px rgba(79, 70, 229, 0.16);
        }
        .hero h1 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-1);
            letter-spacing: -0.2px;
        }
        .hero .subtitle {
            margin-top: 4px;
            font-size: 12.5px;
            font-weight: 500;
            color: var(--text-2);
            line-height: 1.45;
        }

        /* ALERTS */
        .alert-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 12px;
            margin-bottom: 18px;
            animation: fadeUp 0.42s ease;
        }
        .alert-bar i { font-size: 15px; flex-shrink: 0; }
        .alert-bar span { font-size: 12.5px; font-weight: 500; line-height: 1.4; }
        .alert-error {
            border: 1px solid rgba(111, 16, 34, 0.22);
            background: var(--red-soft);
            animation: shake 0.42s ease;
        }
        .alert-error i { color: var(--red); }
        .alert-error span { color: #991b1b; }
        .alert-success {
            border: 1px solid rgba(5, 150, 105, 0.22);
            background: var(--emerald-soft);
        }
        .alert-success i { color: var(--emerald); }
        .alert-success span { color: #065f46; }

        /* FIELDS */
        .field {
            margin-bottom: 16px;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) both;
        }
        .field:nth-of-type(1) { animation-delay: 0.10s; }
        .field:nth-of-type(2) { animation-delay: 0.14s; }
        .field:nth-of-type(3) { animation-delay: 0.18s; }
        .field:nth-of-type(4) { animation-delay: 0.22s; }
        .field:nth-of-type(5) { animation-delay: 0.26s; }
        .field-label {
            display: block;
            font-size: 11px;
            font-weight: 600;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 6px;
        }
        .field-label .required { color: var(--red); margin-left: 2px; }
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
            min-height: 46px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-family: 'Avenir Next', 'Montserrat', sans-serif;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-1);
            background: #fff;
            outline: none;
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
            appearance: none;
            -webkit-appearance: none;
        }
        select.field-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%239ca3af' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 14px center;
            padding-right: 36px;
        }
        .field-control::placeholder { color: var(--text-3); font-weight: 400; }
        .field-control:focus {
            border-color: rgba(79, 70, 229, 0.5);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12);
        }
        .field-control:focus ~ .input-icon { color: var(--indigo); }
        .field-hint {
            margin-top: 6px;
            font-size: 11.5px;
            color: var(--text-3);
            display: flex;
            align-items: center;
            gap: 6px;
            line-height: 1.4;
        }

        .grid-two {
            display: grid;
            grid-template-columns: 1fr;
            gap: 0;
        }
        @media (min-width: 520px) {
            .grid-two {
                grid-template-columns: 1fr 1fr;
                gap: 14px;
            }
            .grid-two .field { margin-bottom: 16px; }
        }

        /* SUBMIT */
        .btn-submit {
            width: 100%;
            margin-top: 8px;
            padding: 14px 22px;
            min-height: 48px;
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
            background: var(--indigo);
            box-shadow: 0 6px 20px rgba(79, 70, 229, 0.28);
            transition: all 0.25s cubic-bezier(0.22, 1, 0.36, 1);
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.30s both;
        }
        .btn-submit:hover {
            background: #4338ca;
            transform: translateY(-1px);
            box-shadow: 0 10px 26px rgba(79, 70, 229, 0.38);
        }
        .btn-submit:active { transform: translateY(0); }
        .btn-submit i { font-size: 13px; }

        /* BACK ROW */
        .back-row {
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px dashed var(--border);
            text-align: center;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.34s both;
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
            min-height: 36px;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        .back-link:hover {
            color: var(--indigo);
            background: var(--indigo-soft);
        }
        .back-link i { font-size: 12px; }

        /* TIPS PANEL */
        .tips-panel {
            background: linear-gradient(135deg, var(--amber-soft) 0%, #fef3c7 100%);
            border: 1px solid rgba(217, 119, 6, 0.18);
            border-radius: 16px;
            padding: 14px 16px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            animation: fadeUp 0.5s cubic-bezier(0.22, 1, 0.36, 1) 0.40s both;
        }
        .tips-panel .tip-icon {
            color: var(--amber);
            font-size: 16px;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .tips-panel .tip-body { flex: 1; min-width: 0; }
        .tips-panel .tip-title {
            font-size: 12px;
            font-weight: 700;
            color: #92400e;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .tips-panel ul {
            list-style: none;
            margin: 0;
            padding: 0;
            font-size: 12px;
            color: #78350f;
            line-height: 1.6;
        }
        .tips-panel li {
            position: relative;
            padding-left: 14px;
        }
        .tips-panel li::before {
            content: "";
            position: absolute;
            left: 0;
            top: 9px;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--amber);
        }

        /* ANIMATIONS */
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
        @media (max-width: 767px) {
            .top-header { padding: 10px 14px; margin-bottom: 14px; }
            .top-header-title { font-size: 14px; }
            .top-header-sub { font-size: 11px; }
            .top-header-icon { width: 36px; height: 36px; font-size: 14px; }
            .page-wrapper { padding: 0 14px; }
            .glass-card { padding: 24px 20px 22px; border-radius: 16px; }
            .hero .icon-box { width: 52px; height: 52px; min-width: 52px; font-size: 20px; }
            .hero h1 { font-size: 16px; }
            .hero .subtitle { font-size: 12px; }
            .field-control {
                font-size: 16px; /* iOS zoom engeli */
                padding: 13px 14px 13px 40px;
                min-height: 48px;
            }
            .btn-submit { font-size: 14px; padding: 13px 20px; min-height: 48px; }
            .back-btn { width: 44px; height: 44px; min-width: 44px; }
        }

        @media (prefers-reduced-motion: reduce) {
            .glass-card, .field, .btn-submit, .back-row, .alert-bar, .tips-panel {
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
        <div class="top-header-inner">
            <a href="index.php" class="back-btn" aria-label="Geri">
                <i class="fa-solid fa-arrow-left"></i>
            </a>
            <span class="top-header-icon"><i class="fa-solid fa-user-plus"></i></span>
            <div class="top-header-text">
                <div class="top-header-title">Yeni Kullanici Ekle</div>
                <div class="top-header-sub">Personel / Musteri kaydi olustur</div>
            </div>
        </div>
    </header>

    <div class="page-wrapper">

        <!-- HERO -->
        <div class="glass-card hero">
            <div class="hero-inner">
                <span class="icon-box"><i class="fa-solid fa-user-shield"></i></span>
                <div>
                    <h1>Yeni Kullanici</h1>
                    <div class="subtitle">Yonetici, personel veya musteri hesabi olusturarak sisteme erisim saglayin.</div>
                </div>
            </div>
        </div>

        <!-- ALERTS -->
        <?php if ($error_message !== '' && $error_message !== '0'): ?>
            <div class="alert-bar alert-error" role="alert">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success_message !== '' && $success_message !== '0'): ?>
            <div class="alert-bar alert-success" role="status">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- FORM -->
        <div class="glass-card">
            <form method="POST" action="">
                <?php echo csrf_field(); ?>

                <div class="field">
                    <label for="kodu" class="field-label">Personel Kodu <span class="required">*</span></label>
                    <div class="input-wrap">
                        <input type="text" id="kodu" name="kodu" maxlength="30" required autofocus
                               class="field-control" placeholder="Orn: KOD-1"
                               autocomplete="off">
                        <i class="fa-solid fa-hashtag input-icon"></i>
                    </div>
                    <div class="field-hint">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Benzersiz olmali. Harf-sayi kombinasyonu, tire ile ayirin.</span>
                    </div>
                </div>

                <div class="field">
                    <label for="adi" class="field-label">Personel Adi <span class="required">*</span></label>
                    <div class="input-wrap">
                        <input type="text" id="adi" name="adi" maxlength="50" required
                               class="field-control" placeholder="Ad Soyad"
                               autocomplete="off">
                        <i class="fa-solid fa-id-badge input-icon"></i>
                    </div>
                </div>

                <div class="field">
                    <label for="sifre" class="field-label">Sifre</label>
                    <div class="input-wrap">
                        <input type="password" id="sifre" name="sifre"
                               class="field-control" placeholder="Kullanici sifresi (istege bagli)"
                               autocomplete="new-password">
                        <i class="fa-solid fa-lock input-icon"></i>
                    </div>
                    <div class="field-hint">
                        <i class="fa-solid fa-shield-halved"></i>
                        <span>Bos birakilirsa kullanici sifresiz eklenir.</span>
                    </div>
                </div>

                <div class="grid-two">
                    <div class="field">
                        <label for="firma" class="field-label">Firma <span class="required">*</span></label>
                        <div class="input-wrap">
                            <select id="firma" name="firma" required class="field-control">
                                <?php
                                $sql = $dbh->prepare("SELECT NR, NAME FROM L_CAPIFIRM ORDER BY NAME ASC");
                                $sql->execute();
                                while ($satir = $sql->fetch(PDO::FETCH_ASSOC)) {
                                    echo '<option value="' . htmlspecialchars((string) $satir['NR']) . '">' . htmlspecialchars((string) $satir['NAME']) . '</option>';
                                }
                                ?>
                            </select>
                            <i class="fa-solid fa-building input-icon"></i>
                        </div>
                    </div>

                    <div class="field">
                        <label for="tipi" class="field-label">Yetki Tipi <span class="required">*</span></label>
                        <div class="input-wrap">
                            <select id="tipi" name="tipi" required class="field-control">
                                <option value="0">Yonetici</option>
                                <option value="1" selected>Personel</option>
                                <option value="2">Musteri</option>
                            </select>
                            <i class="fa-solid fa-user-tag input-icon"></i>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-user-plus"></i>
                    <span>Kullaniciyi Kaydet</span>
                </button>

                <div class="back-row">
                    <a href="mobilyetki.php" class="back-link">
                        <i class="fa-solid fa-xmark"></i>
                        <span>Vazgec ve Listeye Don</span>
                    </a>
                </div>
            </form>
        </div>

        <!-- TIPS -->
        <div class="tips-panel">
            <i class="fa-solid fa-lightbulb tip-icon"></i>
            <div class="tip-body">
                <div class="tip-title">Kullanici Ekleme Ipuclari</div>
                <ul>
                    <li>Kullanici kodu firma bazinda benzersiz olmalidir.</li>
                    <li>Yonetici yetkisi tum modullere tam erisim saglar.</li>
                    <li>Personel yetkisi izin atanan modullerle sinirlidir.</li>
                    <li>Musteri yetkisi portal uzerinden kisitli erisim saglar.</li>
                </ul>
            </div>
        </div>

    </div>

    <script>
        // RAF reflow fix: kart animasyonu GPU katmaninda tetiklensin
        requestAnimationFrame(function () {
            document.querySelectorAll('.glass-card').forEach(function (el) {
                el.style.willChange = 'auto';
            });
        });
    </script>

</body>
</html>

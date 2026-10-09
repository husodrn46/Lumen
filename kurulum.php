<?php

declare(strict_types=1);

/**
 * kurulum.php — Lumen kurulum sihirbazı.
 *
 * İlk kurulumda tek seferlik çalışır:
 *   Adım 1: Veritabanı bağlantısı + firma/dönem/başlık/mağaza carisi → .env yazılır,
 *           _baglanti_.inc oluşturulur, bağlantı test edilir ve Lumen'e özel
 *           tablolar (sql/*.sql — M_P_YETKI, M_CEK_*, M_GOREV vb.) otomatik kurulur.
 *   Adım 2: Yönetici kullanıcı (mevcut LOGO satış temsilcisi kodu + parola) → M_P_YETKI
 *           satırı (YETKI=0) yazılır.
 * Tamamlanınca `.installed` kilit dosyası oluşur; sihirbaz bir daha açılmaz.
 * (Yeniden çalıştırmak için `.installed` dosyasını silin.)
 *
 * Bu dosya BİLİNÇLİ olarak ayr.php'yi dahil ETMEZ — kurulumdan önce DB yapılandırması
 * henüz yoktur. Kendi PDO test bağlantısını kurar.
 */

session_start();
$KOK       = __DIR__;
$LOCK      = $KOK . '/.installed';
$ENV       = $KOK . '/.env';
$ORNEK_BAG = $KOK . '/_baglanti_.inc.example';
$HEDEF_BAG = $KOK . '/_baglanti_.inc';

// Zaten kuruluysa sihirbazı kapat.
if (is_file($LOCK)) {
    header('Location: giris.php');
    exit;
}

if (empty($_SESSION['kurulum_csrf'])) {
    $_SESSION['kurulum_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['kurulum_csrf'];

$adim   = 1;
$hata   = '';
$notlar = [];
$gd_dsn = static fn(string $srv, string $db): string => "sqlsrv:server={$srv};database={$db};TrustServerCertificate=1;LoginTimeout=8";

require_once __DIR__ . '/includes/kurulum_lib.php';

/** .env dosyasını güvenli yaz. */
function kurulum_env_yaz(string $yol, array $kv): bool
{
    $satirlar = ["# Lumen — kurulum sihirbazı tarafından oluşturuldu", ''];
    foreach ($kv as $k => $v) {
        $v = str_replace(["\r", "\n"], '', (string) $v); // satır sonu enjeksiyonu engelle
        $satirlar[] = $k . '=' . $v;
    }
    return @file_put_contents($yol, implode("\n", $satirlar) . "\n") !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $hata = 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.';
    } elseif (!extension_loaded('pdo_sqlsrv')) {
        $hata = 'PHP pdo_sqlsrv eklentisi yüklü değil. SQL Server sürücüsünü kurun.';
    } elseif (($_POST['adim'] ?? '') === '1') {
        unset($_SESSION['kurulum_db']);
        // ---- ADIM 1: DB + firma ----
        $srv   = trim((string) ($_POST['db_server'] ?? ''));
        $db    = trim((string) ($_POST['db_name'] ?? ''));
        $usr   = trim((string) ($_POST['db_user'] ?? ''));
        $pss   = (string) ($_POST['db_pass'] ?? '');
        $fno   = (int) ($_POST['firma_no'] ?? 1);
        $dno   = (int) ($_POST['donem_no'] ?? 2);
        $bas   = trim((string) ($_POST['firma_baslik'] ?? '')) ?: 'Lumen';
        $mcari = max(0, (int) ($_POST['magaza_cari'] ?? 0));

        if ($srv === '' || $db === '' || $usr === '') {
            $hata = 'Sunucu, veritabanı ve kullanıcı adı zorunludur.';
        } else {
            try {
                $test = new PDO($gd_dsn($srv, $db), $usr, $pss, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $onek = kurulum_onekler($fno, $dno);
                // Lumen'e özel tabloları (M_*) burada kur — kullanıcı elle SQL çalıştırmasın.
                $sema = kurulum_sema_uygula($test, $KOK . '/sql', $onek);
                if ($sema['hatalar'] !== []) {
                    throw new RuntimeException('Şema doğrulanamadı: ' . implode(', ', array_keys($sema['hatalar'])));
                }

                $env = [
                    'AKL_DB_SERVER' => $srv,
                    'AKL_DB_NAME'   => $db,
                    'AKL_DB_USER'   => $usr,
                    'AKL_DB_PASS'   => $pss,
                    'FIRMA_NO'      => (string) $fno,
                ] + $onek + [
                    'FIRMA_BASLIK'      => $bas,
                    'MAGAZA_CARI'       => (string) $mcari,
                    'LOG_ROOT'          => '',
                    'APP_SLOW_QUERY_MS' => '300',
                    'DEBUG_MODE'        => 'false',
                ];

                if (!kurulum_env_yaz($ENV, $env)) {
                    $hata = '.env dosyası yazılamadı — klasör yazma izinlerini kontrol edin.';
                } else {
                    if (!is_file($HEDEF_BAG) && !copy($ORNEK_BAG, $HEDEF_BAG)) {
                        throw new RuntimeException('Bağlantı dosyası oluşturulamadı.');
                    }
                    $_SESSION['kurulum_db'] = ['srv' => $srv, 'db' => $db, 'usr' => $usr, 'pss' => $pss, 'onek' => $onek, 'firma' => $fno];
                    $adim = 2;
                    $notlar[] = 'Bağlantı ve şema doğrulandı; yapılandırma kaydedildi.';
                }
            } catch (Throwable $e) {
                unset($_SESSION['kurulum_db']);
                error_log('Kurulum bağlantı/şema hatası: ' . $e->getMessage());
                $hata = 'Bağlantı veya şema doğrulanamadı. Kurulum tamamlanmadı; sunucu kaydını inceleyin.';
            }
        }
    } elseif (($_POST['adim'] ?? '') === '2' && !empty($_SESSION['kurulum_db'])) {
        // ---- ADIM 2: yönetici ----
        $cfg  = $_SESSION['kurulum_db'];
        $kod  = strtoupper(trim((string) ($_POST['admin_kod'] ?? '')));
        $p1   = (string) ($_POST['admin_pass'] ?? '');
        $p2   = (string) ($_POST['admin_pass2'] ?? '');
        $adim = 2;

        if ($kod === '' || $p1 === '') {
            $hata = 'Kullanıcı kodu ve parola zorunludur.';
        } elseif ($p1 !== $p2) {
            $hata = 'Parolalar eşleşmiyor.';
        } elseif (strlen($p1) < 6) {
            $hata = 'Parola en az 6 karakter olmalı.';
        } else {
            try {
                $pdo = new PDO($gd_dsn($cfg['srv'], $cfg['db']), $cfg['usr'], $cfg['pss'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                kurulum_sema_kontrol($pdo, $cfg['onek']);
                // Satış temsilcisi var mı?
                $q = $pdo->prepare("SELECT LOGICALREF FROM LG_SLSMAN WHERE CODE = :k AND ACTIVE = 0 AND FIRMNR = :firma");
                $q->bindValue(':k', $kod);
                $q->bindValue(':firma', (int) $cfg['firma'], PDO::PARAM_INT);
                $q->execute();
                $ref = $q->fetchColumn();
                if ($ref === false) {
                    $hata = "'{$kod}' kodlu aktif satış temsilcisi bulunamadı. Önce LOGO'da tanımlayın.";
                } else {
                    $ref = (int) $ref;
                    $hash = password_hash($p1, PASSWORD_DEFAULT);
                    $var = (int) $pdo->query("SELECT COUNT(*) FROM M_P_YETKI WHERE PERSONEL = {$ref}")->fetchColumn();
                    if ($var > 0) {
                        $u = $pdo->prepare("UPDATE M_P_YETKI SET SIFRE = :s, YETKI = 0 WHERE PERSONEL = :p");
                        $u->execute([':s' => $hash, ':p' => $ref]);
                    } else {
                        // Mevcut kurulumlarda M_P_YETKI ek NOT NULL izin kolonlarına (M1..Mn) sahip
                        // olabilir; bunları 0 ile doldur (yönetici YETKI=0 zaten tüm izinleri açar).
                        $extra = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_NAME = 'M_P_YETKI' AND IS_NULLABLE = 'NO'
                              AND COLUMN_NAME NOT IN ('PERSONEL','SIFRE','YETKI')
                              AND COLUMNPROPERTY(OBJECT_ID('dbo.M_P_YETKI'), COLUMN_NAME, 'IsIdentity') = 0")
                            ->fetchAll(PDO::FETCH_COLUMN);
                        $cols = ['[PERSONEL]', '[SIFRE]', '[YETKI]'];
                        $vals = [':p', ':s', '0'];
                        foreach ($extra as $c) { $cols[] = '[' . $c . ']'; $vals[] = '0'; }
                        $sql = 'INSERT INTO M_P_YETKI (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
                        $i = $pdo->prepare($sql);
                        $i->execute([':p' => $ref, ':s' => $hash]);
                    }
                    if (file_put_contents($LOCK, "kurulum tamamlandi\n") === false) {
                        throw new RuntimeException('Kurulum kilidi yazılamadı.');
                    }
                    unset($_SESSION['kurulum_db']);
                    $adim = 3;
                }
            } catch (Throwable $e) {
                error_log('Kurulum yönetici hatası: ' . $e->getMessage());
                $hata = 'Yönetici veya kurulum kilidi oluşturulamadı; sunucu kaydını inceleyin.';
            }
        }
    }
}

// Adım 1'e düşülüyorsa önceki değerleri koru (yeniden doldurma)
$val = static fn(string $k, string $d = ''): string => htmlspecialchars((string) ($_POST[$k] ?? $d), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Lumen — Kurulum</title>
    <link rel="icon" type="image/png" href="icon.png">
    <link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --card:#fff; --t1:#1f2937; --t2:#6b7280; --t3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; background:
            radial-gradient(1000px 500px at 10% -10%, rgba(111,16,34,.06), transparent 60%), var(--bg);
            color:var(--t1); min-height:100vh; display:flex; align-items:flex-start; justify-content:center; padding:40px 16px; }
        .wrap { width:100%; max-width:560px; }
        .marka { text-align:center; margin-bottom:22px; }
        .marka img { height:44px; }
        .adimlar { display:flex; justify-content:center; gap:8px; margin:14px 0 24px; }
        .adimlar .a { display:flex; align-items:center; gap:7px; font-size:12px; font-weight:600; color:var(--t3); }
        .adimlar .a .n { width:22px; height:22px; border-radius:50%; background:#eee; color:var(--t3); display:inline-flex; align-items:center; justify-content:center; font-size:11px; }
        .adimlar .a.akt .n { background:var(--red); color:#fff; }
        .adimlar .a.akt { color:var(--red); }
        .adimlar .a.tmm .n { background:var(--emerald); color:#fff; }
        .kart { background:var(--card); border:1px solid var(--border); border-radius:16px; box-shadow:0 10px 30px rgba(15,23,42,.06); padding:28px 26px; }
        .kart h1 { font-size:18px; font-weight:700; margin-bottom:4px; }
        .kart .alt { font-size:13px; color:var(--t2); margin-bottom:20px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .alan { margin-bottom:14px; }
        .alan label { display:block; font-size:12px; font-weight:600; color:var(--t2); margin-bottom:5px; }
        .alan input { width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; outline:none; }
        .alan input:focus { border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
        .alan .ip { font-size:11px; color:var(--t3); margin-top:4px; }
        .btn { width:100%; background:var(--red); color:#fff; border:none; padding:13px; border-radius:11px; font-weight:700; font-size:14px; cursor:pointer; font-family:inherit; display:flex; align-items:center; justify-content:center; gap:8px; margin-top:6px; }
        .btn:hover { filter:brightness(1.08); }
        .mesaj { padding:12px 14px; border-radius:10px; margin-bottom:16px; font-size:13px; display:flex; align-items:flex-start; gap:9px; line-height:1.45; }
        .mesaj.err { background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }
        .mesaj.ok { background:var(--emerald-soft); color:#065f46; border:1px solid #a7f3d0; }
        .bitti { text-align:center; padding:10px 0; }
        .bitti .ico { width:64px; height:64px; border-radius:50%; background:var(--emerald-soft); color:var(--emerald); display:inline-flex; align-items:center; justify-content:center; font-size:28px; margin-bottom:14px; }
        .bitti a { display:inline-flex; align-items:center; gap:8px; margin-top:18px; background:var(--red); color:#fff; text-decoration:none; padding:12px 24px; border-radius:11px; font-weight:700; }
        @media (max-width:520px){ .grid2 { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="marka"><img src="logo.png" alt="Lumen" onerror="this.style.display='none'"></div>

        <div class="adimlar">
            <span class="a <?php echo $adim === 1 ? 'akt' : 'tmm'; ?>"><span class="n"><?php echo $adim > 1 ? '<i class="fa-solid fa-check"></i>' : '1'; ?></span> Veritabanı</span>
            <span class="a <?php echo $adim === 2 ? 'akt' : ($adim > 2 ? 'tmm' : ''); ?>"><span class="n"><?php echo $adim > 2 ? '<i class="fa-solid fa-check"></i>' : '2'; ?></span> Yönetici</span>
            <span class="a <?php echo $adim === 3 ? 'akt' : ''; ?>"><span class="n">3</span> Bitti</span>
        </div>

        <div class="kart">
            <?php if ($hata !== ''): ?>
                <div class="mesaj err"><i class="fa-solid fa-circle-exclamation"></i><span><?php echo htmlspecialchars($hata, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <?php endif; ?>
            <?php foreach ($notlar as $n): ?>
                <div class="mesaj ok"><i class="fa-solid fa-circle-check"></i><span><?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?></span></div>
            <?php endforeach; ?>

            <?php if ($adim === 1): ?>
                <h1>Veritabanı Bağlantısı</h1>
                <p class="alt">LOGO Tiger SQL Server veritabanınıza bağlanın.</p>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="adim" value="1">
                    <div class="grid2">
                        <div class="alan"><label>SQL Server</label><input name="db_server" value="<?php echo $val('db_server', 'localhost'); ?>" required></div>
                        <div class="alan"><label>Veritabanı adı</label><input name="db_name" value="<?php echo $val('db_name'); ?>" placeholder="LOGODB" required></div>
                    </div>
                    <div class="grid2">
                        <div class="alan"><label>Kullanıcı</label><input name="db_user" value="<?php echo $val('db_user', 'sa'); ?>" required></div>
                        <div class="alan"><label>Parola</label><input type="password" name="db_pass" value=""></div>
                    </div>
                    <div class="grid2">
                        <div class="alan"><label>Firma No</label><input type="number" name="firma_no" value="<?php echo $val('firma_no', '1'); ?>" min="1" max="999" required><div class="ip">LG_<b>001</b>_...</div></div>
                        <div class="alan"><label>Dönem No</label><input type="number" name="donem_no" value="<?php echo $val('donem_no', '2'); ?>" min="1" max="99" required><div class="ip">LG_001_<b>02</b>_...</div></div>
                    </div>
                    <div class="grid2">
                        <div class="alan"><label>Firma başlığı</label><input name="firma_baslik" value="<?php echo $val('firma_baslik'); ?>" placeholder="Firma adınız"></div>
                        <div class="alan"><label>Mağaza carisi (ops.)</label><input type="number" name="magaza_cari" value="<?php echo $val('magaza_cari', '0'); ?>" min="0"><div class="ip">0 = kapalı</div></div>
                    </div>
                    <button class="btn" type="submit"><i class="fa-solid fa-plug"></i> Bağlan ve Devam Et</button>
                </form>

            <?php elseif ($adim === 2): ?>
                <h1>Yönetici Hesabı</h1>
                <p class="alt">Yönetici olacak <b>mevcut LOGO satış temsilcisinin</b> kodunu ve bir parola belirleyin.</p>
                <form method="POST">
                    <input type="hidden" name="csrf" value="<?php echo $csrf; ?>">
                    <input type="hidden" name="adim" value="2">
                    <div class="alan"><label>Satış temsilcisi kodu (kullanıcı adı)</label><input name="admin_kod" value="<?php echo $val('admin_kod'); ?>" required autofocus><div class="ip">LOGO'daki satış temsilcisi kodu (büyük harfe çevrilir)</div></div>
                    <div class="grid2">
                        <div class="alan"><label>Parola</label><input type="password" name="admin_pass" required></div>
                        <div class="alan"><label>Parola (tekrar)</label><input type="password" name="admin_pass2" required></div>
                    </div>
                    <button class="btn" type="submit"><i class="fa-solid fa-user-shield"></i> Yöneticiyi Oluştur</button>
                </form>

            <?php else: ?>
                <div class="bitti">
                    <div class="ico"><i class="fa-solid fa-check"></i></div>
                    <h1>Kurulum Tamamlandı</h1>
                    <p class="alt">Lumen kullanıma hazır. Giriş yaptığınızda ana ekranda kalan <b>başlangıç adımlarını</b> (firma adı, logo, yazıcı) görüp tamamlayabilirsiniz.</p>
                    <a href="giris.php"><i class="fa-solid fa-arrow-right-to-bracket"></i> Girişe Git</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

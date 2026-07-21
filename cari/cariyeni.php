<?php
declare(strict_types=1);

include_once(__DIR__ . "/../ayr.php");
require_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/../log_ip.php");

// YETKI: M12 (Yeni Cari) yetkisi olan personel cari acabilir
if (m_p_yetki($terminalkullanici, 'M12') != 1) {
    header('Location: ' . APP_ROOT_URL . '/403.html');
    exit;
}

// Ozellik kapali mi? (_bilgi_.inc $yenicariac)
$yeniCariAcik = !isset($yenicariac) || (string) $yenicariac === '1';

$onek = isset($carikoduontaki) && (string) $carikoduontaki !== '' ? (string) $carikoduontaki : 'ANDL';
// Kullanici ANDL onekiyle gidiyor; _bilgi_.inc 'c-' olsa bile cari kodlari ANDL
$onek = 'ANDL';

/**
 * Siradaki ANDL kodu uret: ANDL0001 formatinda, mevcut en buyukten +1.
 */
function cariyeni_sonraki_kod(PDO $dbh, string $firma, string $onek): string
{
    $like = $onek . str_repeat('[0-9]', 4);
    $max = $dbh->query("SELECT MAX(CODE) FROM {$firma}CLCARD WHERE CODE LIKE '{$like}'")->fetchColumn();
    $n = 1;
    if ($max && preg_match('/^' . preg_quote($onek, '/') . '(\d+)$/', (string) $max, $m)) {
        $n = (int) $m[1] + 1;
    }
    return $onek . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

$mesaj = '';
$mesajTip = '';
$olusanCariId = 0;
$olusanCariKod = '';
$olusanCariAd = '';

if ($yeniCariAcik && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cari_ekle'])) {
    if (!csrf_verify()) {
        http_response_code(403);
        die('Gecersiz guvenlik dogrulamasi. Sayfayi yenileyip tekrar deneyin.');
    }

    $unvan = trim((string) ($_POST['unvan'] ?? ''));
    $telefon = trim((string) ($_POST['telefon'] ?? ''));
    $sehir = trim((string) ($_POST['sehir'] ?? ''));
    $ilce = trim((string) ($_POST['ilce'] ?? ''));

    // Uzunluk sinirlari (LOGO CLCARD alan boyutlari)
    $unvan = mb_substr($unvan, 0, 200);
    $telefon = mb_substr($telefon, 0, 30);
    $sehir = mb_substr($sehir, 0, 30);
    $ilce = mb_substr($ilce, 0, 30);

    if ($unvan === '') {
        $mesaj = 'Cari unvani zorunludur.';
        $mesajTip = 'error';
    } else {
        // Yaris durumuna karsi: cakisirsa kodu yenile ve tekrar dene
        $denendi = 0;
        $basarili = false;
        while (!$basarili && $denendi < 3) {
            $denendi++;
            $yeniKod = cariyeni_sonraki_kod($dbh, $firma, $onek);
            try {
                // Sablon: calisan en son ANDL cari (LOGO butunlugu icin tum alanlar)
                $ref = $dbh->query("SELECT TOP 1 * FROM {$firma}CLCARD WHERE CODE LIKE 'ANDL%' AND ACTIVE=0 ORDER BY LOGICALREF DESC")->fetch(PDO::FETCH_ASSOC);
                if (!$ref) {
                    // Sablon yoksa herhangi bir aktif cari
                    $ref = $dbh->query("SELECT TOP 1 * FROM {$firma}CLCARD WHERE ACTIVE=0 ORDER BY LOGICALREF DESC")->fetch(PDO::FETCH_ASSOC);
                }
                if (!$ref) {
                    throw new RuntimeException('Sablon cari bulunamadi.');
                }
                unset($ref['LOGICALREF']); // IDENTITY

                // Cari-spesifik alanlari override et (gerisi sablondan = LOGO butunlugu korunur)
                $ref['CODE'] = $yeniKod;
                $ref['DEFINITION_'] = $unvan;
                $ref['DEFINITION2'] = '';
                $ref['TELNRS1'] = $telefon;
                $ref['TELNRS2'] = '';
                $ref['CITY'] = $sehir;
                $ref['TOWN'] = $ilce;
                $ref['DISTRICT'] = '';
                $ref['ADDR1'] = '';
                $ref['ADDR2'] = '';
                $ref['EMAILADDR'] = '';
                $ref['TAXNR'] = '';
                $ref['TAXOFFICE'] = '';
                if (array_key_exists('TCKNO', $ref)) { $ref['TCKNO'] = ''; }
                $ref['SPECODE'] = '';
                $ref['CYPHCODE'] = '';
                $ref['GUID'] = strtoupper(guid());
                $ref['CAPIBLOCK_CREATEDBY'] = (int) $terminalkullanici;
                $ref['CAPIBLOCK_CREADEDDATE'] = date('Y-m-d H:i:s');
                $ref['CAPIBLOCK_CREATEDHOUR'] = (int) date('H');
                $ref['CAPIBLOCK_CREATEDMIN'] = (int) date('i');
                $ref['CAPIBLOCK_CREATEDSEC'] = (int) date('s');

                $kolonlar = array_keys($ref);
                $kolStr = '[' . implode('],[', $kolonlar) . ']';
                $phStr = ':' . implode(', :', $kolonlar);
                $stmt = $dbh->prepare("INSERT INTO {$firma}CLCARD ({$kolStr}) VALUES ({$phStr})");
                foreach ($ref as $k => $v) {
                    $stmt->bindValue(':' . $k, $v);
                }
                $stmt->execute();
                $olusanCariId = (int) $dbh->lastInsertId();
                $olusanCariKod = $yeniKod;
                $olusanCariAd = $unvan;
                $basarili = true;

                if (function_exists('logGenel')) {
                    // varsa genel log; yoksa sessiz gec
                    @logGenel('cari_olustur', "Yeni cari: [{$yeniKod}] {$unvan}", $terminalkullanici);
                }
            } catch (PDOException $e) {
                $kod = (int) ($e->errorInfo[1] ?? 0);
                if (in_array($kod, [2601, 2627], true) && $denendi < 3) {
                    continue; // kod cakismasi -> yeni kod ile tekrar
                }
                error_log('cariyeni INSERT hatasi: ' . $e->getMessage());
                $mesaj = 'Cari olusturulamadi. Lutfen tekrar deneyin.';
                $mesajTip = 'error';
                break;
            } catch (Throwable $e) {
                error_log('cariyeni hatasi: ' . $e->getMessage());
                $mesaj = 'Cari olusturulamadi.';
                $mesajTip = 'error';
                break;
            }
        }

        if ($basarili) {
            $mesaj = 'Cari basariyla olusturuldu.';
            $mesajTip = 'success';
        }
    }
}

// Form icin siradaki kod (onizleme)
$sonrakiKod = $yeniCariAcik ? cariyeni_sonraki_kod($dbh, $firma, $onek) : '';
$csrf = function_exists('csrf_field') ? csrf_field() : '';
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yeni Cari</title>
    <link rel="icon" type="image/png" href="icon.png">
    <?php if (file_exists(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <script src="/tm/css/tailwind.js" onerror="(function(){var s=document.createElement('script');s.src='https://cdn.tailwindcss.com';document.head.appendChild(s);}())"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --bg:#f9fafb; --text-1:#1f2937; --text-2:#6b7280; --text-3:#9ca3af; --border:#e5e7eb; --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; }
        * { box-sizing:border-box; margin:0; }
        body { font-family:'Avenir Next','Montserrat',sans-serif; background:var(--bg); color:var(--text-1); min-height:100vh; }
        .top-header { position:sticky; top:0; z-index:40; height:64px; background:rgba(255,255,255,0.9); backdrop-filter:blur(6px); border-bottom:1px solid rgba(248,113,113,0.18); }
        .header-inner { max-width:680px; margin:0 auto; height:100%; display:flex; align-items:center; gap:14px; padding:0 20px; }
        .header-back { display:inline-flex; align-items:center; justify-content:center; width:40px; height:40px; border-radius:10px; color:var(--text-2); text-decoration:none; }
        .header-back:hover { background:rgba(0,0,0,0.04); color:var(--red); }
        .header-divider { width:1px; height:24px; background:var(--border); }
        .header-title { font-size:18px; font-weight:700; display:inline-flex; align-items:center; gap:8px; }
        .header-title i { color:var(--red,#ef4444); font-size:16px; }
        main { max-width:680px; margin:0 auto; padding:22px 20px 60px; }
        .card { background:#fff; border:1px solid rgba(248,113,113,0.18); border-radius:16px; box-shadow:0 4px 16px rgba(0,0,0,0.04); padding:22px; animation:cardIn .4s ease both; }
        @keyframes cardIn { from{opacity:0;transform:translateY(10px);} to{opacity:1;transform:none;} }
        .form-label { display:block; font-size:13px; font-weight:600; color:var(--text-2); margin-bottom:6px; }
        .form-label .req { color:var(--red); }
        .form-input { width:100%; padding:12px 14px; font-family:'Avenir Next','Montserrat',sans-serif; font-size:16px; color:var(--text-1); background:#fff; border:1px solid var(--border); border-radius:10px; outline:none; transition:all .2s ease; }
        .form-input:focus { border-color:rgba(239,68,68,0.5); box-shadow:0 0 0 3px rgba(239,68,68,0.1); }
        .form-input[readonly] { background:#f9fafb; color:var(--text-2); font-weight:600; }
        .form-row { margin-bottom:16px; }
        .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        .kod-hint { font-size:12px; color:var(--text-3); margin-top:5px; }
        .btn-primary { width:100%; min-height:48px; display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:13px 18px; background:var(--red,#ef4444); color:#fff; border:none; border-radius:10px; font-family:'Avenir Next','Montserrat',sans-serif; font-size:15px; font-weight:700; cursor:pointer; transition:all .2s ease; }
        .btn-primary:hover { background:var(--red); transform:translateY(-1px); box-shadow:0 6px 16px rgba(239,68,68,0.25); }
        .alert { display:flex; align-items:flex-start; gap:10px; padding:14px 16px; border-radius:12px; font-size:14px; margin-bottom:18px; }
        .alert.error { background:var(--red-soft); color:var(--red); border:1px solid #fecaca; }
        .alert.success { background:var(--emerald-soft); color:var(--emerald); border:1px solid #a7f3d0; }
        .success-box { text-align:center; padding:8px 0 4px; }
        .success-box .ico { width:64px; height:64px; margin:0 auto 14px; border-radius:50%; background:var(--emerald-soft); color:var(--emerald); display:flex; align-items:center; justify-content:center; font-size:30px; }
        .success-box .t1 { font-size:17px; font-weight:700; }
        .success-box .t2 { font-size:14px; color:var(--text-2); margin-top:4px; }
        .success-box .kod { display:inline-block; margin-top:10px; padding:4px 12px; border-radius:100px; background:var(--red-soft); color:var(--red); font-weight:700; font-size:13px; }
        .success-actions { display:flex; flex-direction:column; gap:10px; margin-top:22px; }
        .act { display:inline-flex; align-items:center; justify-content:center; gap:8px; min-height:48px; padding:13px 18px; border-radius:10px; font-size:14px; font-weight:600; text-decoration:none; transition:all .2s ease; cursor:pointer; border:1px solid var(--border); }
        .act-red { background:var(--red,#ef4444); color:#fff; border-color:var(--red,#ef4444); }
        .act-red:hover { background:var(--red); }
        .act-light { background:#fff; color:var(--text-1); }
        .act-light:hover { background:#f9fafb; }
        @media (max-width:560px){ .form-grid { grid-template-columns:1fr; } main { padding:16px 14px 50px; } .card { padding:18px; } }
    </style>
</head>
<body>
    <header class="top-header">
        <div class="header-inner">
            <a href="cari.php" class="header-back" title="Geri"><i class="fa fa-arrow-left"></i></a>
            <div class="header-divider"></div>
            <span class="header-title"><i class="fa-solid fa-user-plus"></i> Yeni Cari</span>
        </div>
    </header>

    <main>
        <?php if (!$yeniCariAcik): ?>
            <div class="card">
                <div class="alert error"><i class="fa-solid fa-ban"></i> Yeni cari ekleme ozelligi su an kapali.</div>
            </div>
        <?php elseif ($mesajTip === 'success'): ?>
            <div class="card">
                <div class="success-box">
                    <div class="ico"><i class="fa-solid fa-check"></i></div>
                    <div class="t1"><?php echo htmlspecialchars($olusanCariAd, ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="t2">cari basariyla olusturuldu</div>
                    <div class="kod"><?php echo htmlspecialchars($olusanCariKod, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
                <div class="success-actions">
                    <a class="act act-red" href="../siparis/fisekle.php?cariid=<?php echo (int) $olusanCariId; ?>&stokhareket=0"><i class="fa-solid fa-cart-plus"></i> Bu cariye siparis ver</a>
                    <a class="act act-light" href="cariyeni.php"><i class="fa-solid fa-user-plus"></i> Yeni cari ekle</a>
                    <a class="act act-light" href="cari.php?q=<?php echo rawurlencode($olusanCariKod); ?>"><i class="fa-solid fa-list"></i> Cari listesinde gor</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <?php if ($mesajTip === 'error'): ?>
                    <div class="alert error"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo htmlspecialchars($mesaj, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <form method="POST" autocomplete="off">
                    <?php echo $csrf; ?>
                    <input type="hidden" name="cari_ekle" value="1">

                    <div class="form-row">
                        <label class="form-label" for="unvan">Cari Unvani <span class="req">*</span></label>
                        <input type="text" name="unvan" id="unvan" class="form-input" required maxlength="200"
                               value="<?php echo htmlspecialchars((string) ($_POST['unvan'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="Firma / kisi adi" autofocus>
                    </div>

                    <div class="form-row">
                        <label class="form-label" for="telefon">Telefon</label>
                        <input type="tel" name="telefon" id="telefon" class="form-input" maxlength="30" inputmode="tel"
                               value="<?php echo htmlspecialchars((string) ($_POST['telefon'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="05xx xxx xx xx">
                    </div>

                    <div class="form-row form-grid">
                        <div>
                            <label class="form-label" for="sehir">Sehir</label>
                            <input type="text" name="sehir" id="sehir" class="form-input" maxlength="30"
                                   value="<?php echo htmlspecialchars((string) ($_POST['sehir'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Sehir">
                        </div>
                        <div>
                            <label class="form-label" for="ilce">Ilce</label>
                            <input type="text" name="ilce" id="ilce" class="form-input" maxlength="30"
                                   value="<?php echo htmlspecialchars((string) ($_POST['ilce'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                   placeholder="Ilce">
                        </div>
                    </div>

                    <div class="form-row">
                        <label class="form-label">Cari Kodu (otomatik)</label>
                        <input type="text" class="form-input" value="<?php echo htmlspecialchars($sonrakiKod, ENT_QUOTES, 'UTF-8'); ?>" readonly>
                        <div class="kod-hint">Kod otomatik atanir. Diger bilgileri (adres, vergi vb.) sonradan LOGO'dan tamamlayabilirsiniz.</div>
                    </div>

                    <button type="submit" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Cariyi Olustur</button>
                </form>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>

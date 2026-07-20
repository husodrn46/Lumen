<?php
declare(strict_types=1);

/**
 * kullanici_yetki.php — Birleşik "Kullanıcı & Yetki" ekranı (Kullanıcılar sekmesi).
 *
 * Eski üç sayfayı tek yerde toplar:
 *   - mobilyetki.php      → kullanıcı listesi + aktif/pasif + silme
 *   - mobilkullanici.php  → yeni kullanıcı ekleme
 *   - mobilkullanicix.php → kullanıcı düzenleme
 * İzin Matrisi ve Denetim, ortak sekme şeridiyle (yetki_sekmeler.php) erişilir.
 *
 * Yetki: yalnız yönetici (M16). Tüm yazma işlemleri CSRF korumalı + PRG (POST→Redirect→GET).
 */

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';

ayar_require_m16($terminalkullanici);

/**
 * M_P_YETKI'ye satır ekle. Bazı kurulumlarda tablo ek NOT NULL izin kolonlarına
 * (M1..Mn) sahip olabilir; bunları 0 ile doldurur (YETKI=0 yönetici zaten tüm
 * izinleri açar, personel için ilgili izinler matristen verilir).
 */
function ky_yetki_insert(PDO $dbh, int $personel, string $sifreHash, int $yetki): void
{
    $extra = $dbh->query(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = 'M_P_YETKI' AND IS_NULLABLE = 'NO'
           AND COLUMN_NAME NOT IN ('PERSONEL','SIFRE','YETKI')
           AND COLUMNPROPERTY(OBJECT_ID('dbo.M_P_YETKI'), COLUMN_NAME, 'IsIdentity') = 0"
    )->fetchAll(PDO::FETCH_COLUMN);

    $cols = ['[PERSONEL]', '[SIFRE]', '[YETKI]'];
    $vals = [':p', ':s', ':y'];
    foreach ($extra as $c) {
        $cols[] = '[' . $c . ']';
        $vals[] = '0';
    }
    $sql = 'INSERT INTO M_P_YETKI (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')';
    $st = $dbh->prepare($sql);
    $st->execute([':p' => $personel, ':s' => $sifreHash, ':y' => $yetki]);
}

$firmaId = isset($_GET['firma']) ? (int) $_GET['firma'] : (int) $firmano;
$search  = isset($_GET['ara'])   ? trim((string) $_GET['ara']) : '';

$hataMsj = '';   // form validasyon hatası → formu tekrar aç
$formEski = [];  // hata durumunda girilen değerleri geri doldur

// ─────────────────────────── POST: yazma işlemleri ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ayar_require_csrf();
    $action = (string) ($_POST['action'] ?? '');
    $geri = 'kullanici_yetki.php?firma=' . $firmaId . '&ara=' . rawurlencode($search);

    // ---- Aktif/Pasif değiştir ----
    if ($action === 'toggle_status') {
        $perid = (int) ($_POST['perid'] ?? 0);
        $durum = (string) ($_POST['durum'] ?? '');
        if ($perid > 0 && in_array($durum, ['aktif', 'pasif'], true)) {
            $dr = ($durum === 'aktif') ? 0 : 1; // LG_SLSMAN.ACTIVE: 0=aktif, 1=pasif
            $dbh->prepare("UPDATE LG_SLSMAN SET ACTIVE = :dr WHERE LOGICALREF = :id")
                ->execute([':dr' => $dr, ':id' => $perid]);
            if (function_exists('m_p_yetki_cache_temizle')) {
                m_p_yetki_cache_temizle($perid);
            }
            header('Location: ' . $geri . '&ok=durum', true, 303);
            exit;
        }
        header('Location: ' . $geri . '&hata=gecersiz', true, 303);
        exit;
    }

    // ---- Sil (yalnız pasif kullanıcı) ----
    if ($action === 'delete_user') {
        $perid = (int) ($_POST['perid'] ?? 0);
        if ($perid > 0) {
            $chk = $dbh->prepare("SELECT ACTIVE FROM LG_SLSMAN WHERE LOGICALREF = :id");
            $chk->execute([':id' => $perid]);
            $u = $chk->fetch(PDO::FETCH_ASSOC);
            if ($u && (int) $u['ACTIVE'] === 1) {
                $dbh->prepare("DELETE FROM M_P_YETKI WHERE PERSONEL = :id")->execute([':id' => $perid]);
                $dbh->prepare("DELETE FROM LG_SLSMAN WHERE LOGICALREF = :id AND ACTIVE = 1")->execute([':id' => $perid]);
                if (function_exists('m_p_yetki_cache_temizle')) {
                    m_p_yetki_cache_temizle($perid);
                }
                header('Location: ' . $geri . '&ok=silindi', true, 303);
                exit;
            }
            header('Location: ' . $geri . '&hata=aktif_silinemez', true, 303);
            exit;
        }
        header('Location: ' . $geri . '&hata=gecersiz', true, 303);
        exit;
    }

    // ---- Kaydet (id=0 ekle / id>0 düzenle) ----
    if ($action === 'save') {
        $id    = (int) ($_POST['id'] ?? 0);
        $kodu  = trim((string) ($_POST['kodu'] ?? ''));
        $adi   = trim((string) ($_POST['adi'] ?? ''));
        $sifre = trim((string) ($_POST['sifre'] ?? ''));
        $tip   = (int) ($_POST['tipi'] ?? 1);
        $frm   = (int) ($_POST['firma'] ?? $firmaId);
        $formEski = ['id' => $id, 'kodu' => $kodu, 'adi' => $adi, 'tipi' => $tip];

        // Ortak validasyon
        if ($kodu === '' || mb_strlen($kodu) > 30) {
            $hataMsj = 'Personel kodu boş olamaz ve en fazla 30 karakter olmalıdır.';
        } elseif ($adi === '' || mb_strlen($adi) > 50) {
            $hataMsj = 'Personel adı boş olamaz ve en fazla 50 karakter olmalıdır.';
        } elseif (!in_array($tip, [0, 1, 2], true)) {
            $hataMsj = 'Geçersiz yetki tipi.';
        } elseif ($sifre !== '' && mb_strlen($sifre) < 4) {
            $hataMsj = 'Parola en az 4 karakter olmalıdır.';
        }

        if ($hataMsj === '' && $id > 0) {
            // ---------- DÜZENLE ----------
            try {
                $dbh->beginTransaction();
                $dbh->prepare("UPDATE LG_SLSMAN SET CODE = :c, DEFINITION_ = :d, FIRMNR = :f WHERE LOGICALREF = :id")
                    ->execute([':c' => $kodu, ':d' => $adi, ':f' => $frm, ':id' => $id]);

                $var = (int) $dbh->query("SELECT COUNT(*) FROM M_P_YETKI WHERE PERSONEL = " . $id)->fetchColumn();
                if ($var > 0) {
                    $sql = "UPDATE M_P_YETKI SET YETKI = :y";
                    $p = [':y' => $tip, ':id' => $id];
                    if ($sifre !== '') { // boş = parolayı değiştirme
                        $sql .= ", SIFRE = :s";
                        $p[':s'] = sifre_hashle($sifre);
                    }
                    $sql .= " WHERE PERSONEL = :id";
                    $dbh->prepare($sql)->execute($p);
                } else {
                    // Yetki satırı yok → parola zorunlu (auth bypass koruması)
                    if (mb_strlen($sifre) < 4) {
                        throw new RuntimeException('sifre_zorunlu');
                    }
                    ky_yetki_insert($dbh, $id, sifre_hashle($sifre), $tip);
                }

                $dbh->commit();
                if (function_exists('m_p_yetki_cache_temizle')) {
                    m_p_yetki_cache_temizle($id);
                }
                header('Location: ' . $geri . '&ok=guncellendi', true, 303);
                exit;
            } catch (Throwable $e) {
                if ($dbh->inTransaction()) {
                    $dbh->rollBack();
                }
                $hataMsj = ($e->getMessage() === 'sifre_zorunlu')
                    ? 'Bu kullanıcı için parola belirlemelisiniz (en az 4 karakter).'
                    : 'Kayıt sırasında hata oluştu. Lütfen tekrar deneyin.';
            }
        } elseif ($hataMsj === '' && $id === 0) {
            // ---------- EKLE ----------
            if (mb_strlen($sifre) < 4) {
                $hataMsj = 'Yeni kullanıcı için parola zorunludur (en az 4 karakter).';
            } else {
                $dup = $dbh->prepare("SELECT 1 FROM LG_SLSMAN WHERE CODE = :c AND FIRMNR = :f");
                $dup->execute([':c' => $kodu, ':f' => $frm]);
                if ($dup->fetchColumn()) {
                    $hataMsj = '"' . htmlspecialchars($kodu, ENT_QUOTES, 'UTF-8') . '" personel kodu bu firmada zaten kullanılmış.';
                } else {
                    try {
                        $dbh->beginTransaction();
                        $dbh->prepare(
                            "INSERT INTO LG_SLSMAN (CODE, DEFINITION_, CARDTYPE, CAPIBLOCK_CREADEDDATE, USERID, FIRMNR, TYP, ACTIVE)
                             VALUES (:c, :d, 0, GETDATE(), 1, :f, 0, 0)"
                        )->execute([':c' => $kodu, ':d' => $adi, ':f' => $frm]);
                        $newId = (int) $dbh->lastInsertId();
                        ky_yetki_insert($dbh, $newId, sifre_hashle($sifre), $tip);
                        $dbh->commit();
                        header('Location: ' . $geri . '&ok=eklendi', true, 303);
                        exit;
                    } catch (Throwable $e) {
                        if ($dbh->inTransaction()) {
                            $dbh->rollBack();
                        }
                        $hataMsj = 'Kullanıcı kaydedilemedi. Lütfen tekrar deneyin.';
                    }
                }
            }
        }
        // hata varsa aşağıda $formEski ile form tekrar açılır
    }
}

// ─────────────────────────────── Liste ───────────────────────────────
$like = '%' . $search . '%';
$stmt = $dbh->prepare(
    "SELECT L.LOGICALREF, L.CODE, L.DEFINITION_, L.ACTIVE, M.YETKI
     FROM LG_SLSMAN L
     LEFT JOIN M_P_YETKI M ON L.LOGICALREF = M.PERSONEL
     WHERE L.FIRMNR = :firm AND (L.CODE LIKE :p1 OR L.DEFINITION_ LIKE :p2)
     ORDER BY L.CODE ASC"
);
$stmt->execute([':firm' => $firmaId, ':p1' => $like, ':p2' => $like]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$aktifSayisi = count(array_filter($users, static fn($u) => (int) $u['ACTIVE'] === 0));
$pasifSayisi = count($users) - $aktifSayisi;

$allFirms = $dbh->query("SELECT NR, NAME FROM L_CAPIFIRM ORDER BY NAME ASC")->fetchAll(PDO::FETCH_ASSOC);

// Derin bağlantı: ?duzenle=<ref> → o kullanıcının düzenleme modalını otomatik aç
// (ör. Denetim > Detay ekranından "Düzenle" ile gelinir).
$duzenleUser = null;
if (isset($_GET['duzenle']) && (int) $_GET['duzenle'] > 0) {
    $ds = $dbh->prepare(
        "SELECT L.LOGICALREF, L.CODE, L.DEFINITION_, M.YETKI
         FROM LG_SLSMAN L LEFT JOIN M_P_YETKI M ON L.LOGICALREF = M.PERSONEL
         WHERE L.LOGICALREF = :id"
    );
    $ds->execute([':id' => (int) $_GET['duzenle']]);
    $duzenleUser = $ds->fetch(PDO::FETCH_ASSOC) ?: null;
}

$yetkiAd = [0 => 'Yönetici', 1 => 'Personel', 2 => 'Müşteri'];
$formAcik = ($hataMsj !== '');
$aktifSekme = 'kullanicilar';
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

// Durum bildirimleri (?ok= / ?hata=)
$bildirim = ''; $bildirimTip = 'ok';
if ($hataMsj !== '') {
    $bildirim = $hataMsj; $bildirimTip = 'error';
} elseif (isset($_GET['ok'])) {
    $bildirim = [
        'eklendi'     => 'Kullanıcı eklendi.',
        'guncellendi' => 'Kullanıcı güncellendi.',
        'durum'       => 'Kullanıcı durumu güncellendi.',
        'silindi'     => 'Kullanıcı silindi.',
    ][$_GET['ok']] ?? '';
} elseif (isset($_GET['hata'])) {
    $bildirim = [
        'aktif_silinemez' => 'Aktif kullanıcı silinemez. Önce pasife alın.',
        'gecersiz'        => 'Geçersiz işlem.',
    ][$_GET['hata']] ?? 'İşlem tamamlanamadı.';
    $bildirimTip = 'error';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Kullanıcı &amp; Yetki - Lumen</title>
    <link rel="icon" type="image/png" href="../icon.png">
    <?php if (is_file(__DIR__ . '/../pwa-header.php')) { include_once __DIR__ . '/../pwa-header.php'; } ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{ --bg:#f9fafb; --card:#fff; --text-1:#1f2937; --text-2:#6b7280; --text-3:#9ca3af; --border:#e5e7eb;
               --red:#6F1022; --red-soft:#fef2f2; --emerald:#059669; --emerald-soft:#ecfdf5; --amber:#b45309; --amber-soft:#fffbeb; }
        *{ box-sizing:border-box; margin:0; }
        body{ font-family:'Avenir Next','Montserrat',sans-serif; letter-spacing:-0.02em; background:var(--bg); color:var(--text-1); font-size:14px; }
        main{ max-width:1080px; margin:0 auto; padding:22px 20px 80px; }

        .arac{ display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-bottom:16px; }
        .arac form.filtre{ display:flex; gap:8px; align-items:center; flex:1; min-width:220px; }
        .arac .ara-kutu{ position:relative; flex:1; max-width:340px; }
        .arac .ara-kutu i{ position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:13px; }
        .arac input[type=text], .arac select{ width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:10px; font-size:13.5px; font-family:inherit; outline:none; background:#fff; }
        .arac .ara-kutu input{ padding-left:34px; }
        .arac input:focus, .arac select:focus{ border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
        .arac select.firma{ max-width:200px; }
        .btn{ display:inline-flex; align-items:center; gap:7px; padding:10px 16px; border-radius:10px; font-weight:600; font-size:13.5px; cursor:pointer; border:1px solid transparent; font-family:inherit; text-decoration:none; white-space:nowrap; }
        .btn-red{ background:var(--red); color:#fff; } .btn-red:hover{ filter:brightness(1.08); }
        .btn-hat{ background:#fff; color:var(--text-2); border:1px solid var(--border); } .btn-hat:hover{ border-color:var(--red); color:var(--red); }
        .btn-yeni{ margin-left:auto; }

        .istatistik{ display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
        .chip{ display:inline-flex; align-items:center; gap:7px; padding:7px 13px; border-radius:9px; font-size:12.5px; font-weight:600; border:1px solid var(--border); background:#fff; color:var(--text-2); }
        .chip .n{ font-weight:700; color:var(--text-1); }
        .chip.em{ background:var(--emerald-soft); border-color:#a7f3d0; color:#065f46; }
        .chip.em .n{ color:#065f46; }

        .mesaj{ padding:12px 14px; border-radius:10px; margin-bottom:16px; font-size:13px; display:flex; gap:9px; align-items:center; }
        .mesaj.ok{ background:var(--emerald-soft); color:#065f46; border:1px solid #a7f3d0; }
        .mesaj.error{ background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }

        .liste{ background:var(--card); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
        .satir{ display:flex; align-items:center; gap:14px; padding:14px 18px; border-bottom:1px solid var(--border); }
        .satir:last-child{ border-bottom:none; }
        .satir:hover{ background:#fafafa; }
        .avatar{ width:40px; height:40px; border-radius:11px; background:var(--red-soft); color:var(--red); display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; flex-shrink:0; }
        .satir.pasif .avatar{ background:#f3f4f6; color:var(--text-3); }
        .kimlik{ flex:1; min-width:0; }
        .kimlik .ad{ font-weight:600; font-size:14px; color:var(--text-1); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .kimlik .kod{ font-size:12px; color:var(--text-3); margin-top:1px; }
        .rozet{ display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:20px; font-size:11.5px; font-weight:600; }
        .rz-yonetici{ background:var(--amber-soft); color:var(--amber); border:1px solid #fde68a; }
        .rz-personel{ background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }
        .rz-musteri{ background:#f5f3ff; color:#6d28d9; border:1px solid #ddd6fe; }
        .rz-eksik{ background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }
        .durum{ display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; }
        .durum.on{ color:var(--emerald); } .durum.off{ color:var(--text-3); }
        .durum .dot{ width:7px; height:7px; border-radius:50%; background:currentColor; }
        .aksiyon{ display:flex; gap:6px; align-items:center; flex-shrink:0; }
        .ibtn{ width:34px; height:34px; border-radius:9px; border:1px solid var(--border); background:#fff; color:var(--text-2); cursor:pointer; display:inline-flex; align-items:center; justify-content:center; font-size:13px; transition:.15s; }
        .ibtn:hover{ border-color:var(--red); color:var(--red); background:var(--red-soft); }
        .ibtn.sil:hover{ border-color:#dc2626; color:#dc2626; background:#fef2f2; }
        .bos{ text-align:center; padding:48px 20px; color:var(--text-3); }
        .bos i{ font-size:34px; margin-bottom:12px; display:block; opacity:.6; }

        /* orta/sag sutunlar mobilde gizlensin */
        .col-tip, .col-durum{ display:flex; }
        @media (max-width:640px){ .col-tip{ display:none; } .satir{ gap:10px; padding:12px 14px; } }

        /* Modal */
        .modal-ort{ position:fixed; inset:0; background:rgba(17,24,39,.5); display:none; align-items:center; justify-content:center; z-index:100; padding:16px; }
        .modal-ort.acik{ display:flex; }
        .modal{ background:#fff; border-radius:16px; width:100%; max-width:440px; box-shadow:0 24px 60px rgba(0,0,0,.28); overflow:hidden; }
        .modal-bas{ padding:18px 22px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; }
        .modal-bas i{ color:var(--red); }
        .modal-bas h3{ font-size:16px; font-weight:700; flex:1; }
        .modal-bas .kapat{ width:32px; height:32px; border:none; background:#f3f4f6; border-radius:8px; color:var(--text-2); cursor:pointer; }
        .modal-govde{ padding:20px 22px; }
        .alan{ margin-bottom:15px; }
        .alan label{ display:block; font-size:12.5px; font-weight:600; color:var(--text-2); margin-bottom:6px; }
        .alan input, .alan select{ width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; outline:none; }
        .alan input:focus, .alan select:focus{ border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
        .alan .ipucu{ font-size:11.5px; color:var(--text-3); margin-top:5px; }
        .modal-alt{ padding:16px 22px; border-top:1px solid var(--border); display:flex; gap:10px; }
        .modal-alt .btn-red{ margin-left:auto; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/yetki_sekmeler.php'; ?>

    <main>
        <?php if ($bildirim !== ''): ?>
            <div class="mesaj <?= $bildirimTip === 'ok' ? 'ok' : 'error' ?>">
                <i class="fa-solid <?= $bildirimTip === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                <span><?= $h($bildirim) ?></span>
            </div>
        <?php endif; ?>

        <!-- Araç çubuğu: firma + arama + yeni -->
        <div class="arac">
            <form method="GET" class="filtre">
                <?php if (count($allFirms) > 1): ?>
                    <select name="firma" class="firma" onchange="this.form.submit()">
                        <?php foreach ($allFirms as $f): ?>
                            <option value="<?= (int) $f['NR'] ?>" <?= (int) $f['NR'] === $firmaId ? 'selected' : '' ?>>
                                <?= $h($f['NAME']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input type="hidden" name="firma" value="<?= $firmaId ?>">
                <?php endif; ?>
                <div class="ara-kutu">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="ara" value="<?= $h($search) ?>" placeholder="Kod veya isim ara…">
                </div>
                <button type="submit" class="btn btn-hat"><i class="fa-solid fa-arrow-right"></i></button>
            </form>
            <button type="button" class="btn btn-red btn-yeni" onclick="kyYeni()"><i class="fa-solid fa-plus"></i> Yeni Kullanıcı</button>
        </div>

        <div class="istatistik">
            <span class="chip em"><i class="fa-solid fa-circle-check"></i> Aktif <span class="n"><?= $aktifSayisi ?></span></span>
            <span class="chip"><i class="fa-solid fa-circle-pause"></i> Pasif <span class="n"><?= $pasifSayisi ?></span></span>
            <span class="chip"><i class="fa-solid fa-users"></i> Toplam <span class="n"><?= count($users) ?></span></span>
        </div>

        <div class="liste">
            <?php if (!$users): ?>
                <div class="bos">
                    <i class="fa-solid fa-user-slash"></i>
                    <?= $search !== '' ? 'Aramanıza uygun kullanıcı bulunamadı.' : 'Henüz kullanıcı yok. “Yeni Kullanıcı” ile ekleyin.' ?>
                </div>
            <?php else: foreach ($users as $u):
                $ref    = (int) $u['LOGICALREF'];
                $aktif  = ((int) $u['ACTIVE'] === 0);
                $yok    = is_null($u['YETKI']);
                $tipi   = $yok ? 1 : (int) $u['YETKI'];
                $bas    = mb_strtoupper(mb_substr((string) $u['CODE'], 0, 1)); ?>
                <div class="satir <?= $aktif ? '' : 'pasif' ?>">
                    <div class="avatar"><?= $h($bas) ?></div>
                    <div class="kimlik">
                        <div class="ad"><?= $h($u['DEFINITION_'] ?: $u['CODE']) ?></div>
                        <div class="kod"><i class="fa-solid fa-hashtag" style="font-size:9px;"></i> <?= $h($u['CODE']) ?></div>
                    </div>
                    <div class="col-tip">
                        <?php if ($yok): ?>
                            <span class="rozet rz-eksik" title="M_P_YETKI kaydı yok — düzenleyip parola belirleyin"><i class="fa-solid fa-triangle-exclamation"></i> Yetki yok</span>
                        <?php else:
                            $rc = [0 => 'rz-yonetici', 1 => 'rz-personel', 2 => 'rz-musteri'][$tipi] ?? 'rz-personel'; ?>
                            <span class="rozet <?= $rc ?>"><?= $h($yetkiAd[$tipi] ?? 'Personel') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="col-durum">
                        <span class="durum <?= $aktif ? 'on' : 'off' ?>"><span class="dot"></span><?= $aktif ? 'Aktif' : 'Pasif' ?></span>
                    </div>
                    <div class="aksiyon">
                        <button type="button" class="ibtn" title="Düzenle"
                            onclick='kyDuzenle(<?= $ref ?>, <?= json_encode($u['CODE'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= json_encode($u['DEFINITION_'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, <?= $tipi ?>, <?= $yok ? 1 : 0 ?>)'>
                            <i class="fa-solid fa-pen"></i>
                        </button>
                        <?php if ($aktif): ?>
                            <button type="button" class="ibtn" title="Pasife al" onclick="kyDurum(<?= $ref ?>,'pasif')"><i class="fa-solid fa-toggle-on"></i></button>
                        <?php else: ?>
                            <button type="button" class="ibtn" title="Aktife al" onclick="kyDurum(<?= $ref ?>,'aktif')"><i class="fa-solid fa-toggle-off"></i></button>
                            <button type="button" class="ibtn sil" title="Sil" onclick='kySil(<?= $ref ?>, <?= json_encode($u['CODE'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'><i class="fa-solid fa-trash"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </main>

    <!-- Ekle/Düzenle modal -->
    <div class="modal-ort" id="kyModal">
        <div class="modal">
            <form method="POST" id="kyForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="ky_id" value="0">
                <input type="hidden" name="firma" value="<?= $firmaId ?>">
                <div class="modal-bas">
                    <i class="fa-solid fa-user-gear"></i>
                    <h3 id="ky_baslik">Yeni Kullanıcı</h3>
                    <button type="button" class="kapat" onclick="kyKapat()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-govde">
                    <div class="alan">
                        <label for="ky_kodu">Personel Kodu</label>
                        <input type="text" name="kodu" id="ky_kodu" maxlength="30" autocomplete="off" required placeholder="Örn: AHMET">
                    </div>
                    <div class="alan">
                        <label for="ky_adi">Ad Soyad</label>
                        <input type="text" name="adi" id="ky_adi" maxlength="50" autocomplete="off" required placeholder="Örn: Ahmet Yılmaz">
                    </div>
                    <div class="alan">
                        <label for="ky_tipi">Yetki Tipi</label>
                        <select name="tipi" id="ky_tipi">
                            <option value="0">Yönetici — tüm yetkiler</option>
                            <option value="1" selected>Personel — kısıtlı (matristen ayarlanır)</option>
                            <option value="2">Müşteri — salt okunur</option>
                        </select>
                    </div>
                    <div class="alan">
                        <label for="ky_sifre">Parola</label>
                        <input type="password" name="sifre" id="ky_sifre" autocomplete="new-password" placeholder="En az 4 karakter">
                        <div class="ipucu" id="ky_sifre_ipucu">Düzenlemede boş bırakırsanız mevcut parola değişmez.</div>
                    </div>
                </div>
                <div class="modal-alt">
                    <button type="button" class="btn btn-hat" onclick="kyKapat()">Vazgeç</button>
                    <button type="submit" class="btn btn-red"><i class="fa-solid fa-floppy-disk"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Durum/sil için gizli POST formu -->
    <form method="POST" id="kyAksiyon" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="kya_action" value="">
        <input type="hidden" name="perid" id="kya_perid" value="">
        <input type="hidden" name="durum" id="kya_durum" value="">
    </form>

    <script>
        var kyModal = document.getElementById('kyModal');
        function kyYeni(){
            document.getElementById('ky_id').value = '0';
            document.getElementById('ky_baslik').textContent = 'Yeni Kullanıcı';
            document.getElementById('ky_kodu').value = '';
            document.getElementById('ky_adi').value = '';
            document.getElementById('ky_tipi').value = '1';
            document.getElementById('ky_sifre').value = '';
            document.getElementById('ky_sifre').setAttribute('placeholder','En az 4 karakter (zorunlu)');
            document.getElementById('ky_sifre_ipucu').textContent = 'Yeni kullanıcı için parola zorunludur (en az 4 karakter).';
            kyModal.classList.add('acik');
            setTimeout(function(){ document.getElementById('ky_kodu').focus(); }, 50);
        }
        function kyDuzenle(id, kodu, adi, tipi, yetkiYok){
            document.getElementById('ky_id').value = id;
            document.getElementById('ky_baslik').textContent = 'Kullanıcı Düzenle';
            document.getElementById('ky_kodu').value = kodu || '';
            document.getElementById('ky_adi').value = adi || '';
            document.getElementById('ky_tipi').value = String(tipi);
            document.getElementById('ky_sifre').value = '';
            if (yetkiYok){
                document.getElementById('ky_sifre').setAttribute('placeholder','En az 4 karakter (zorunlu)');
                document.getElementById('ky_sifre_ipucu').textContent = 'Bu kullanıcının parolası yok — belirlemeniz gerekir.';
            } else {
                document.getElementById('ky_sifre').setAttribute('placeholder','Değiştirmek için yeni parola');
                document.getElementById('ky_sifre_ipucu').textContent = 'Boş bırakırsanız mevcut parola değişmez.';
            }
            kyModal.classList.add('acik');
            setTimeout(function(){ document.getElementById('ky_adi').focus(); }, 50);
        }
        function kyKapat(){ kyModal.classList.remove('acik'); }
        kyModal.addEventListener('click', function(e){ if (e.target === kyModal) kyKapat(); });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') kyKapat(); });

        function kyDurum(id, durum){
            document.getElementById('kya_action').value = 'toggle_status';
            document.getElementById('kya_perid').value = id;
            document.getElementById('kya_durum').value = durum;
            document.getElementById('kyAksiyon').submit();
        }
        function kySil(id, kodu){
            if (!confirm('"' + kodu + '" kullanıcısı kalıcı olarak silinsin mi? Bu işlem geri alınamaz.')) return;
            document.getElementById('kya_action').value = 'delete_user';
            document.getElementById('kya_perid').value = id;
            document.getElementById('kya_durum').value = '';
            document.getElementById('kyAksiyon').submit();
        }

        <?php if (!$formAcik && $duzenleUser):
            $duYok = is_null($duzenleUser['YETKI']); ?>
        // Derin bağlantı (?duzenle=) → düzenleme modalını aç
        kyDuzenle(<?= (int) $duzenleUser['LOGICALREF'] ?>, <?= json_encode($duzenleUser['CODE'], JSON_UNESCAPED_UNICODE) ?>, <?= json_encode($duzenleUser['DEFINITION_'], JSON_UNESCAPED_UNICODE) ?>, <?= $duYok ? 1 : (int) $duzenleUser['YETKI'] ?>, <?= $duYok ? 1 : 0 ?>);
        <?php endif; ?>

        <?php if ($formAcik): ?>
        // Kayıt hatası → formu geri doldurup aç
        (function(){
            <?php $fe = $formEski; ?>
            document.getElementById('ky_id').value = '<?= (int) ($fe['id'] ?? 0) ?>';
            document.getElementById('ky_baslik').textContent = <?= (int) ($fe['id'] ?? 0) > 0 ? "'Kullanıcı Düzenle'" : "'Yeni Kullanıcı'" ?>;
            document.getElementById('ky_kodu').value = <?= json_encode($fe['kodu'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
            document.getElementById('ky_adi').value = <?= json_encode($fe['adi'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
            document.getElementById('ky_tipi').value = '<?= (int) ($fe['tipi'] ?? 1) ?>';
            kyModal.classList.add('acik');
        })();
        <?php endif; ?>
    </script>
</body>
</html>

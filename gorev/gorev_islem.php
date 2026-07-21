<?php

declare(strict_types=1);

/**
 * gorev_islem.php — Gorev modulu AJAX islem yonlendiricisi
 * ----------------------------------------------------------------------------
 *   GET  ?islem=detay&id=..   -> gorev detay HTML parcasi (modal icine)
 *   POST islem=olustur        -> yeni gorev (M29 gerekli, ATAYAN = ben)
 *   POST islem=durum          -> durum degistir (yalnizca ATANAN)
 *   POST islem=reddet         -> reddet + sebep (yalnizca ATANAN)
 *   POST islem=iptal          -> iptal (yalnizca ATAYAN)
 *   POST islem=yorum          -> yorum/not ekle (ATAYAN veya ATANAN)
 * Tum POST islemleri csrf_verify() ister; her islem erisim yetkisi dogrular.
 */

ob_start();
include_once(__DIR__ . "/../ayr.php");
include_once(__DIR__ . "/../kontrol.php");
include_once(__DIR__ . "/gorev_lib.php");
ob_end_clean();

$benimId = (int) $terminalkullanici;

if (!gorev_erisim_var_mi($benimId)) {
    http_response_code(403);
    echo 'Yetkisiz erişim.';
    exit;
}

$islem = (string) ($_GET['islem'] ?? $_POST['islem'] ?? '');

/* ---- Yardimcilar (gorev_getir_yetkili, gorev_hareket_ekle gorev_lib.php'de) */

/** JSON cevap dondurup cikar. */
function jcik(bool $ok, string $mesaj = '', array $ekstra = []): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $ok, 'mesaj' => $mesaj], $ekstra), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ==========================================================================
   DETAY (GET, HTML parca)
   ========================================================================== */
if ($islem === 'detay') {
    header('Content-Type: text/html; charset=utf-8');
    $gid = (int) ($_GET['id'] ?? 0);
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        echo '<div class="detay-yukleniyor">Görev bulunamadı veya erişim yetkiniz yok.</div>';
        exit;
    }

    // Atanan kisi ilk kez goruyorsa otomatik "Görüldü"
    if ((int) $g['ATANAN_ID'] === $benimId && (int) $g['DURUM'] === GOREV_DURUM_YENI) {
        try {
            $dbh->beginTransaction();
            $up = $dbh->prepare("UPDATE M_GOREV SET DURUM = 1, GORULME_TARIHI = GETDATE(), GUNCELLEME_TARIHI = GETDATE() WHERE ID = :id");
            $up->bindValue(':id', $gid, PDO::PARAM_INT);
            $up->execute();
            gorev_hareket_ekle($dbh, $gid, $benimId, GOREV_HAR_DURUM, GOREV_DURUM_YENI, GOREV_DURUM_GORULDU, null);
            $dbh->commit();
            $g['DURUM'] = GOREV_DURUM_GORULDU;
        } catch (PDOException $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('gorev detay otomatik goruldu: ' . $e->getMessage());
        }
    }

    echo gorev_detay_html($dbh, $g, $benimId);
    exit;
}

/* ==========================================================================
   Buradan sonrasi POST + CSRF
   ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jcik(false, 'Geçersiz istek.');
}
if (!csrf_verify()) {
    jcik(false, 'Oturum doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.');
}

/* ---- Yeni gorev olustur -------------------------------------------------- */
if ($islem === 'olustur') {
    // M29 (atama yetkisi) olan baskasina atayabilir; olmayan yalnizca kendine ekler.
    if (gorev_atama_yetkisi_var_mi($benimId)) {
        $atananId = (int) ($_POST['atanan_id'] ?? 0);
    } else {
        $atananId = $benimId;
    }
    $baslik   = trim((string) ($_POST['baslik'] ?? ''));
    $aciklama = trim((string) ($_POST['aciklama'] ?? ''));
    $oncelik  = (int) ($_POST['oncelik'] ?? GOREV_ONCELIK_NORMAL);
    $kategori = (int) ($_POST['kategori'] ?? GOREV_KATEGORI_GENEL);
    $vadeRaw  = trim((string) ($_POST['vade'] ?? ''));

    if ($atananId <= 0) {
        jcik(false, 'Görevi atayacağınız kişiyi seçin.');
    }
    if ($baslik === '') {
        jcik(false, 'Başlık boş olamaz.');
    }
    if (mb_strlen($baslik) > 200) {
        $baslik = mb_substr($baslik, 0, 200);
    }
    if (mb_strlen($aciklama) > 4000) {
        $aciklama = mb_substr($aciklama, 0, 4000);
    }
    if (!in_array($oncelik, [GOREV_ONCELIK_DUSUK, GOREV_ONCELIK_NORMAL, GOREV_ONCELIK_YUKSEK], true)) {
        $oncelik = GOREV_ONCELIK_NORMAL;
    }
    if (!array_key_exists($kategori, gorev_kategori_listesi())) {
        $kategori = GOREV_KATEGORI_GENEL;
    }
    $vadeVal = null;
    if ($vadeRaw !== '') {
        $ts = strtotime($vadeRaw);
        if ($ts !== false) {
            $vadeVal = date('Y-m-d 00:00:00', $ts);
        }
    }

    // Atanan kisi gecerli/aktif personel mi?
    try {
        $chk = $dbh->prepare("SELECT COUNT(*) FROM LG_SLSMAN WITH(NOLOCK) WHERE LOGICALREF = :id AND ACTIVE = 0");
        $chk->bindValue(':id', $atananId, PDO::PARAM_INT);
        $chk->execute();
        if ((int) $chk->fetchColumn() === 0) {
            jcik(false, 'Seçilen kişi geçerli bir personel değil.');
        }
    } catch (PDOException $e) {
        error_log('gorev olustur personel kontrol: ' . $e->getMessage());
    }

    try {
        $dbh->beginTransaction();
        $ins = $dbh->prepare(
            "INSERT INTO M_GOREV (BASLIK, ACIKLAMA, ATAYAN_ID, ATANAN_ID, DURUM, ONCELIK, KATEGORI, VADE_TARIHI, OLUSTURMA_TARIHI)
             OUTPUT INSERTED.ID
             VALUES (:baslik, :aciklama, :atayan, :atanan, 0, :oncelik, :kategori, :vade, GETDATE())"
        );
        $ins->bindValue(':baslik', $baslik, PDO::PARAM_STR);
        $ins->bindValue(':aciklama', $aciklama !== '' ? $aciklama : null, $aciklama !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $ins->bindValue(':atayan', $benimId, PDO::PARAM_INT);
        $ins->bindValue(':atanan', $atananId, PDO::PARAM_INT);
        $ins->bindValue(':oncelik', $oncelik, PDO::PARAM_INT);
        $ins->bindValue(':kategori', $kategori, PDO::PARAM_INT);
        $ins->bindValue(':vade', $vadeVal, $vadeVal === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $ins->execute();
        $yeniId = (int) $ins->fetchColumn();

        gorev_hareket_ekle($dbh, $yeniId, $benimId, GOREV_HAR_OLUSTUR, null, GOREV_DURUM_YENI, null);
        $dbh->commit();
        jcik(true, 'Görev oluşturuldu.', ['id' => $yeniId]);
    } catch (PDOException $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log('gorev olustur: ' . $e->getMessage());
        jcik(false, 'Görev kaydedilemedi.');
    }
}

/* ---- Durum degistir (yalnizca ATANAN) ------------------------------------ */
if ($islem === 'durum') {
    $gid  = (int) ($_POST['id'] ?? 0);
    $yeni = (int) ($_POST['durum'] ?? -1);

    if (!in_array($yeni, [GOREV_DURUM_GORULDU, GOREV_DURUM_YAPILIYOR, GOREV_DURUM_BITTI], true)) {
        jcik(false, 'Geçersiz durum.');
    }
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Görev bulunamadı.');
    }
    if ((int) $g['ATANAN_ID'] !== $benimId) {
        jcik(false, 'Görevin durumunu yalnızca atanan kişi değiştirebilir.');
    }
    $eski = (int) $g['DURUM'];
    if (gorev_durum_kesin_kapali_mi($eski)) {
        jcik(false, 'Kapatılmış görev güncellenemez.');
    }
    if ($eski === $yeni) {
        jcik(true, 'Değişiklik yok.');
    }

    $setTarih = '';
    if ($yeni === GOREV_DURUM_GORULDU && empty($g['GORULME_TARIHI'])) {
        $setTarih = ', GORULME_TARIHI = GETDATE()';
    } elseif ($yeni === GOREV_DURUM_YAPILIYOR && empty($g['BASLAMA_TARIHI'])) {
        $setTarih = ', BASLAMA_TARIHI = GETDATE()';
    } elseif ($yeni === GOREV_DURUM_BITTI) {
        $setTarih = ', TAMAMLANMA_TARIHI = GETDATE()';
    }

    try {
        $dbh->beginTransaction();
        $up = $dbh->prepare("UPDATE M_GOREV SET DURUM = :d, GUNCELLEME_TARIHI = GETDATE()" . $setTarih . " WHERE ID = :id");
        $up->bindValue(':d', $yeni, PDO::PARAM_INT);
        $up->bindValue(':id', $gid, PDO::PARAM_INT);
        $up->execute();
        gorev_hareket_ekle($dbh, $gid, $benimId, GOREV_HAR_DURUM, $eski, $yeni, null);
        $dbh->commit();
        jcik(true, 'Durum güncellendi.');
    } catch (PDOException $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log('gorev durum: ' . $e->getMessage());
        jcik(false, 'Durum güncellenemedi.');
    }
}

/* ---- Reddet (yalnizca ATANAN) -------------------------------------------- */
if ($islem === 'reddet') {
    $gid   = (int) ($_POST['id'] ?? 0);
    $sebep = trim((string) ($_POST['sebep'] ?? ''));
    if ($sebep === '') {
        jcik(false, 'Reddetme sebebi girmelisiniz.');
    }
    if (mb_strlen($sebep) > 500) {
        $sebep = mb_substr($sebep, 0, 500);
    }
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Görev bulunamadı.');
    }
    if ((int) $g['ATANAN_ID'] !== $benimId) {
        jcik(false, 'Görevi yalnızca atanan kişi reddedebilir.');
    }
    if (gorev_durum_kapali_mi((int) $g['DURUM'])) {
        jcik(false, 'Kapatılmış görev reddedilemez.');
    }
    try {
        $dbh->beginTransaction();
        $up = $dbh->prepare("UPDATE M_GOREV SET DURUM = 4, RET_SEBEBI = :s, GUNCELLEME_TARIHI = GETDATE() WHERE ID = :id");
        $up->bindValue(':s', $sebep, PDO::PARAM_STR);
        $up->bindValue(':id', $gid, PDO::PARAM_INT);
        $up->execute();
        gorev_hareket_ekle($dbh, $gid, $benimId, GOREV_HAR_DURUM, (int) $g['DURUM'], GOREV_DURUM_REDDEDILDI, $sebep);
        $dbh->commit();
        jcik(true, 'Görev reddedildi.');
    } catch (PDOException $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log('gorev reddet: ' . $e->getMessage());
        jcik(false, 'İşlem başarısız.');
    }
}

/* ---- Iptal (yalnizca ATAYAN) --------------------------------------------- */
if ($islem === 'iptal') {
    $gid = (int) ($_POST['id'] ?? 0);
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Görev bulunamadı.');
    }
    if ((int) $g['ATAYAN_ID'] !== $benimId) {
        jcik(false, 'Görevi yalnızca veren kişi iptal edebilir.');
    }
    if (gorev_durum_kapali_mi((int) $g['DURUM'])) {
        jcik(false, 'Bu görev iptal edilemez.');
    }
    try {
        $dbh->beginTransaction();
        $up = $dbh->prepare("UPDATE M_GOREV SET DURUM = 9, GUNCELLEME_TARIHI = GETDATE() WHERE ID = :id");
        $up->bindValue(':id', $gid, PDO::PARAM_INT);
        $up->execute();
        gorev_hareket_ekle($dbh, $gid, $benimId, GOREV_HAR_DURUM, (int) $g['DURUM'], GOREV_DURUM_IPTAL, null);
        $dbh->commit();
        jcik(true, 'Görev iptal edildi.');
    } catch (PDOException $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log('gorev iptal: ' . $e->getMessage());
        jcik(false, 'İşlem başarısız.');
    }
}

/* ---- Yorum / not ekle (ATAYAN veya ATANAN) ------------------------------- */
if ($islem === 'yorum') {
    $gid   = (int) ($_POST['id'] ?? 0);
    $mesaj = trim((string) ($_POST['mesaj'] ?? ''));
    if ($mesaj === '') {
        jcik(false, 'Mesaj boş olamaz.');
    }
    if (mb_strlen($mesaj) > 4000) {
        $mesaj = mb_substr($mesaj, 0, 4000);
    }
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Görev bulunamadı.');
    }
    try {
        gorev_hareket_ekle($dbh, $gid, $benimId, GOREV_HAR_YORUM, null, null, $mesaj);
        jcik(true, 'Yorum eklendi.');
    } catch (PDOException $e) {
        error_log('gorev yorum: ' . $e->getMessage());
        jcik(false, 'Yorum eklenemedi.');
    }
}

/* ---- Onayla (yalnizca ATAYAN, Bitti -> Onaylandi) ------------------------ */
if ($islem === 'onay') {
    $gid = (int) ($_POST['id'] ?? 0);
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Görev bulunamadı.');
    }
    if ((int) $g['ATAYAN_ID'] !== $benimId) {
        jcik(false, 'Görevi yalnızca veren onaylayabilir.');
    }
    if ((int) $g['DURUM'] !== GOREV_DURUM_BITTI) {
        jcik(false, 'Yalnızca "Bitti" durumundaki görev onaylanabilir.');
    }
    try {
        $dbh->beginTransaction();
        $up = $dbh->prepare("UPDATE M_GOREV SET DURUM = 5, ONAY_TARIHI = GETDATE(), GUNCELLEME_TARIHI = GETDATE() WHERE ID = :id");
        $up->bindValue(':id', $gid, PDO::PARAM_INT);
        $up->execute();
        gorev_hareket_ekle($dbh, $gid, $benimId, GOREV_HAR_DURUM, GOREV_DURUM_BITTI, GOREV_DURUM_ONAYLANDI, null);
        $dbh->commit();
        jcik(true, 'Görev onaylandı.');
    } catch (PDOException $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log('gorev onay: ' . $e->getMessage());
        jcik(false, 'İşlem başarısız.');
    }
}

/* ---- Geri ac (yalnizca ATAYAN, Bitti/Onaylandi -> Yapiliyor) ------------- */
if ($islem === 'geri_ac') {
    $gid = (int) ($_POST['id'] ?? 0);
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Görev bulunamadı.');
    }
    if ((int) $g['ATAYAN_ID'] !== $benimId) {
        jcik(false, 'Görevi yalnızca veren geri açabilir.');
    }
    if (!in_array((int) $g['DURUM'], [GOREV_DURUM_BITTI, GOREV_DURUM_ONAYLANDI], true)) {
        jcik(false, 'Bu görev geri açılamaz.');
    }
    try {
        $dbh->beginTransaction();
        $up = $dbh->prepare("UPDATE M_GOREV SET DURUM = 2, TAMAMLANMA_TARIHI = NULL, ONAY_TARIHI = NULL, GUNCELLEME_TARIHI = GETDATE() WHERE ID = :id");
        $up->bindValue(':id', $gid, PDO::PARAM_INT);
        $up->execute();
        gorev_hareket_ekle($dbh, $gid, $benimId, GOREV_HAR_DURUM, (int) $g['DURUM'], GOREV_DURUM_YAPILIYOR, 'Görev geri açıldı');
        $dbh->commit();
        jcik(true, 'Görev geri açıldı.');
    } catch (PDOException $e) {
        if ($dbh->inTransaction()) {
            $dbh->rollBack();
        }
        error_log('gorev geri_ac: ' . $e->getMessage());
        jcik(false, 'İşlem başarısız.');
    }
}

/* ---- Checklist: madde ekle (ATAYAN veya ATANAN) -------------------------- */
if ($islem === 'altgorev_ekle') {
    $gid = (int) ($_POST['id'] ?? 0);
    $metin = trim((string) ($_POST['metin'] ?? ''));
    if ($metin === '') {
        jcik(false, 'Madde boş olamaz.');
    }
    if (mb_strlen($metin) > 300) {
        $metin = mb_substr($metin, 0, 300);
    }
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Görev bulunamadı.');
    }
    if (gorev_durum_kapali_mi((int) $g['DURUM'])) {
        jcik(false, 'Kapatılmış göreve madde eklenemez.');
    }
    try {
        $ins = $dbh->prepare(
            "INSERT INTO M_GOREV_ALTGOREV (GOREV_ID, METIN, OLUSTURAN_ID, SIRA)
             OUTPUT INSERTED.ID
             VALUES (:g, :m, :o, (SELECT ISNULL(MAX(SIRA), 0) + 1 FROM M_GOREV_ALTGOREV WHERE GOREV_ID = :g2))"
        );
        $ins->bindValue(':g', $gid, PDO::PARAM_INT);
        $ins->bindValue(':g2', $gid, PDO::PARAM_INT);
        $ins->bindValue(':m', $metin, PDO::PARAM_STR);
        $ins->bindValue(':o', $benimId, PDO::PARAM_INT);
        $ins->execute();
        jcik(true, 'Madde eklendi.', ['id' => (int) $ins->fetchColumn()]);
    } catch (PDOException $e) {
        error_log('altgorev ekle: ' . $e->getMessage());
        jcik(false, 'Madde eklenemedi.');
    }
}

/* ---- Checklist: madde tikle/kaldir --------------------------------------- */
if ($islem === 'altgorev_tikle') {
    $altId = (int) ($_POST['alt_id'] ?? 0);
    $tamam = ((int) ($_POST['tamam'] ?? 0) === 1) ? 1 : 0;
    if ($altId <= 0) {
        jcik(false, 'Geçersiz madde.');
    }
    try {
        $st = $dbh->prepare("SELECT GOREV_ID FROM M_GOREV_ALTGOREV WITH(NOLOCK) WHERE ID = :id");
        $st->bindValue(':id', $altId, PDO::PARAM_INT);
        $st->execute();
        $gid = (int) ($st->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        $gid = 0;
    }
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Yetkisiz işlem.');
    }
    try {
        $up = $dbh->prepare("UPDATE M_GOREV_ALTGOREV SET TAMAM = :t WHERE ID = :id");
        $up->bindValue(':t', $tamam, PDO::PARAM_INT);
        $up->bindValue(':id', $altId, PDO::PARAM_INT);
        $up->execute();
        jcik(true, '', gorev_altgorev_ilerleme($dbh, $gid));
    } catch (PDOException $e) {
        error_log('altgorev tikle: ' . $e->getMessage());
        jcik(false, 'İşlem başarısız.');
    }
}

/* ---- Checklist: madde sil ------------------------------------------------ */
if ($islem === 'altgorev_sil') {
    $altId = (int) ($_POST['alt_id'] ?? 0);
    if ($altId <= 0) {
        jcik(false, 'Geçersiz madde.');
    }
    try {
        $st = $dbh->prepare("SELECT GOREV_ID FROM M_GOREV_ALTGOREV WITH(NOLOCK) WHERE ID = :id");
        $st->bindValue(':id', $altId, PDO::PARAM_INT);
        $st->execute();
        $gid = (int) ($st->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        $gid = 0;
    }
    $g = gorev_getir_yetkili($dbh, $gid, $benimId);
    if (!$g) {
        jcik(false, 'Yetkisiz işlem.');
    }
    try {
        $del = $dbh->prepare("DELETE FROM M_GOREV_ALTGOREV WHERE ID = :id");
        $del->bindValue(':id', $altId, PDO::PARAM_INT);
        $del->execute();
        jcik(true, 'Madde silindi.', gorev_altgorev_ilerleme($dbh, $gid));
    } catch (PDOException $e) {
        error_log('altgorev sil: ' . $e->getMessage());
        jcik(false, 'İşlem başarısız.');
    }
}

jcik(false, 'Bilinmeyen işlem.');


/* ==========================================================================
   Gorev detay HTML uretici
   ========================================================================== */
function gorev_detay_html(PDO $dbh, array $g, int $benimId): string
{
    $h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $gid = (int) $g['ID'];
    $d = (int) $g['DURUM'];
    $db = gorev_durum_bilgi($d);
    $ob = gorev_oncelik_bilgi((int) $g['ONCELIK']);
    $benAtanan = (int) $g['ATANAN_ID'] === $benimId;
    $benAtayan = (int) $g['ATAYAN_ID'] === $benimId;
    $atayanAd = gorev_kullanici_adi($dbh, (int) $g['ATAYAN_ID']);
    $atananAd = gorev_kullanici_adi($dbh, (int) $g['ATANAN_ID']);
    $kb = gorev_kategori_bilgi(isset($g['KATEGORI']) ? (int) $g['KATEGORI'] : null);
    $kapali = gorev_durum_kapali_mi($d);

    ob_start();
    ?>
    <div class="detay-ust">
        <span class="durum-badge" style="background:<?php echo $db['bg']; ?>;color:<?php echo $db['renk']; ?>;">
            <i class="fa-solid <?php echo $db['ikon']; ?>"></i><?php echo $h($db['etiket']); ?>
        </span>
        <span class="durum-badge" style="background:<?php echo $kb['bg']; ?>;color:<?php echo $kb['renk']; ?>;">
            <i class="fa-solid <?php echo $kb['ikon']; ?>"></i><?php echo $h($kb['etiket']); ?>
        </span>
        <span class="oncelik" style="color:<?php echo $ob['renk']; ?>;"><i class="fa-solid fa-flag"></i><?php echo $h($ob['etiket']); ?> öncelik</span>
    </div>

    <h3 class="detay-baslik"><?php echo $h($g['BASLIK']); ?></h3>
    <?php if (!empty($g['ACIKLAMA'])): ?>
        <p class="detay-aciklama"><?php echo nl2br($h($g['ACIKLAMA'])); ?></p>
    <?php endif; ?>

    <?php if ($d === GOREV_DURUM_REDDEDILDI && !empty($g['RET_SEBEBI'])): ?>
        <div class="ret-kutu"><i class="fa-solid fa-circle-xmark"></i> <strong>Reddedildi:</strong> <?php echo $h($g['RET_SEBEBI']); ?></div>
    <?php endif; ?>

    <div class="detay-meta">
        <div><span><i class="fa-solid fa-user-tie"></i> Veren</span><b><?php echo $h($atayanAd); ?></b></div>
        <div><span><i class="fa-solid fa-user"></i> Alan</span><b><?php echo $h($atananAd); ?></b></div>
        <div><span><i class="fa-regular fa-calendar-plus"></i> Oluşturma</span><b><?php echo $h(gorev_tarih_format($g['OLUSTURMA_TARIHI'])); ?></b></div>
        <?php if (!empty($g['VADE_TARIHI'])): ?>
            <div><span><i class="fa-regular fa-clock"></i> Vade</span><b><?php echo $h(gorev_tarih_format($g['VADE_TARIHI'], false)); ?></b></div>
        <?php endif; ?>
        <?php if (!empty($g['TAMAMLANMA_TARIHI'])): ?>
            <div><span><i class="fa-solid fa-flag-checkered"></i> Tamamlanma</span><b><?php echo $h(gorev_tarih_format($g['TAMAMLANMA_TARIHI'])); ?></b></div>
        <?php endif; ?>
    </div>

    <?php
    $atananAksiyon = $benAtanan && !$kapali;
    $atayanAksiyon = $benAtayan && ($d === GOREV_DURUM_BITTI || $d === GOREV_DURUM_ONAYLANDI || !$kapali);
    ?>
    <?php if ($atananAksiyon || $atayanAksiyon): ?>
        <div class="detay-aksiyon">
            <?php if ($atananAksiyon): ?>
                <?php if ($d === GOREV_DURUM_YENI || $d === GOREV_DURUM_GORULDU): ?>
                    <button type="button" class="durum-btn ileri" data-durum-btn="<?php echo GOREV_DURUM_YAPILIYOR; ?>" data-id="<?php echo $gid; ?>"><i class="fa-solid fa-play"></i>Başla</button>
                <?php elseif ($d === GOREV_DURUM_YAPILIYOR): ?>
                    <button type="button" class="durum-btn bitir" data-durum-btn="<?php echo GOREV_DURUM_BITTI; ?>" data-id="<?php echo $gid; ?>"><i class="fa-solid fa-check"></i>Bitti</button>
                <?php endif; ?>
                <button type="button" class="durum-btn" data-reddet="<?php echo $gid; ?>"><i class="fa-solid fa-ban"></i>Reddet</button>
            <?php endif; ?>
            <?php if ($benAtayan): ?>
                <?php if ($d === GOREV_DURUM_BITTI): ?>
                    <button type="button" class="durum-btn bitir" data-onay="<?php echo $gid; ?>"><i class="fa-solid fa-check-double"></i>Onayla</button>
                    <button type="button" class="durum-btn" data-geriac="<?php echo $gid; ?>"><i class="fa-solid fa-rotate-left"></i>Geri aç</button>
                <?php elseif ($d === GOREV_DURUM_ONAYLANDI): ?>
                    <button type="button" class="durum-btn" data-geriac="<?php echo $gid; ?>"><i class="fa-solid fa-rotate-left"></i>Geri aç</button>
                <?php elseif (!$kapali): ?>
                    <button type="button" class="durum-btn" data-iptal="<?php echo $gid; ?>"><i class="fa-solid fa-trash-can"></i>İptal et</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php
    // Alt gorevler (checklist)
    $altlar = [];
    try {
        $st = $dbh->prepare("SELECT ID, METIN, TAMAM FROM M_GOREV_ALTGOREV WITH(NOLOCK) WHERE GOREV_ID = :id ORDER BY SIRA, ID");
        $st->bindValue(':id', $gid, PDO::PARAM_INT);
        $st->execute();
        $altlar = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('gorev detay altgorev: ' . $e->getMessage());
    }
    $altToplam = count($altlar);
    $altTamam = 0;
    foreach ($altlar as $a) {
        if ((int) $a['TAMAM'] === 1) {
            $altTamam++;
        }
    }
    $altYuzde = $altToplam > 0 ? (int) round($altTamam / $altToplam * 100) : 0;
    ?>
    <div class="detay-bolum-baslik">
        <i class="fa-solid fa-list-check"></i> Alt görevler
        <?php if ($altToplam > 0): ?><span class="alt-sayac"><?php echo $altTamam; ?>/<?php echo $altToplam; ?></span><?php endif; ?>
    </div>
    <?php if ($altToplam > 0): ?>
        <div class="alt-bar"><div class="alt-bar-dolu" style="width:<?php echo $altYuzde; ?>%"></div></div>
        <div class="alt-liste">
            <?php foreach ($altlar as $a): ?>
                <div class="alt-item <?php echo (int) $a['TAMAM'] === 1 ? 'tamam' : ''; ?>">
                    <label class="alt-check">
                        <input type="checkbox" data-alt-tik="<?php echo (int) $a['ID']; ?>" <?php echo (int) $a['TAMAM'] === 1 ? 'checked' : ''; ?> <?php echo $kapali ? 'disabled' : ''; ?>>
                        <span><?php echo $h($a['METIN']); ?></span>
                    </label>
                    <?php if (!$kapali): ?><button type="button" class="alt-sil" data-alt-sil="<?php echo (int) $a['ID']; ?>" title="Sil"><i class="fa fa-xmark"></i></button><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (!$kapali): ?>
        <form class="alt-ekle-form" data-alt-form="<?php echo $gid; ?>">
            <input type="text" name="metin" class="form-ctrl" placeholder="Yeni madde ekle..." maxlength="300" autocomplete="off">
            <button type="submit" class="btn-yorum" title="Ekle"><i class="fa-solid fa-plus"></i></button>
        </form>
    <?php endif; ?>

    <?php
    // Ekler
    $ekler = [];
    try {
        $st = $dbh->prepare("SELECT ID, DOSYA_ADI FROM M_GOREV_EK WITH(NOLOCK) WHERE GOREV_ID = :id ORDER BY ID ASC");
        $st->bindValue(':id', $gid, PDO::PARAM_INT);
        $st->execute();
        $ekler = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('gorev detay ekler: ' . $e->getMessage());
    }
    ?>
    <div class="detay-bolum-baslik"><i class="fa-solid fa-paperclip"></i> Ekler</div>
    <?php if ($ekler): ?>
        <div class="ek-liste">
            <?php foreach ($ekler as $e): ?>
                <a class="ek-item" href="gorev_ek_indir.php?id=<?php echo (int) $e['ID']; ?>" target="_blank" rel="noopener">
                    <i class="fa-solid fa-file-arrow-down"></i><span class="ek-ad"><?php echo $h($e['DOSYA_ADI']); ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="ek-bos">Henüz ek eklenmemiş.</div>
    <?php endif; ?>
    <?php if (!$kapali): ?>
        <label class="ek-yukle-alan">
            <i class="fa-solid fa-upload"></i> Fotoğraf / dosya ekle
            <input type="file" data-ek-input="<?php echo $gid; ?>" hidden>
        </label>
    <?php endif; ?>

    <?php
    // Hareket gecmisi
    $hareketler = [];
    try {
        $st = $dbh->prepare("SELECT TIP, ESKI_DURUM, YENI_DURUM, MESAJ, PERSONEL_ID, TARIH FROM M_GOREV_HAREKET WITH(NOLOCK) WHERE GOREV_ID = :id ORDER BY TARIH ASC, ID ASC");
        $st->bindValue(':id', $gid, PDO::PARAM_INT);
        $st->execute();
        $hareketler = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('gorev detay hareket: ' . $e->getMessage());
    }
    ?>
    <div class="detay-bolum-baslik"><i class="fa-solid fa-clock-rotate-left"></i> Geçmiş ve yorumlar</div>
    <div class="timeline">
        <?php foreach ($hareketler as $hk):
            $kim = gorev_kullanici_adi($dbh, (int) $hk['PERSONEL_ID']);
            $tip = (int) $hk['TIP'];
            $zaman = gorev_tarih_format($hk['TARIH']);
            $ikon = 'fa-circle';
            if ($tip === GOREV_HAR_OLUSTUR) {
                $ikon = 'fa-plus';
                $metin = '<b>' . $h($kim) . '</b> görevi oluşturdu';
            } elseif ($tip === GOREV_HAR_YORUM) {
                $ikon = 'fa-comment';
                $metin = '<b>' . $h($kim) . ':</b> ' . nl2br($h($hk['MESAJ']));
            } elseif ($tip === GOREV_HAR_EK) {
                $ikon = 'fa-paperclip';
                $metin = '<b>' . $h($kim) . '</b> dosya ekledi' . (!empty($hk['MESAJ']) ? ': ' . $h($hk['MESAJ']) : '');
            } else {
                $yd = gorev_durum_bilgi((int) $hk['YENI_DURUM']);
                $ikon = $yd['ikon'];
                $metin = '<b>' . $h($kim) . '</b> durumu <span style="color:' . $yd['renk'] . ';font-weight:600">' . $h($yd['etiket']) . '</span> yaptı';
                if ((int) $hk['YENI_DURUM'] === GOREV_DURUM_REDDEDILDI && !empty($hk['MESAJ'])) {
                    $metin .= ' — ' . $h($hk['MESAJ']);
                }
            }
        ?>
            <div class="tl-item">
                <div class="tl-ikon"><i class="fa-solid <?php echo $ikon; ?>"></i></div>
                <div class="tl-icerik"><?php echo $metin; ?><div class="tl-zaman"><?php echo $h($zaman); ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <form class="yorum-form" data-yorum-form="<?php echo $gid; ?>">
        <input type="text" name="mesaj" class="form-ctrl" placeholder="Yorum yaz..." maxlength="4000" autocomplete="off">
        <button type="submit" class="btn-yorum" title="Gönder"><i class="fa-solid fa-paper-plane"></i></button>
    </form>
    <?php
    return (string) ob_get_clean();
}

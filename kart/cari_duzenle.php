<?php

declare(strict_types=1);

/**
 * Cari kartı düzenleme (yalnız bağımsız mod): unvan, tür, vergi bilgileri, adres, iletişim,
 * vade günü, risk limiti, özel kod, pazarlamacı, aktiflik. Kod değişmez (belgelerde kullanılır).
 * Erişim: M12 (Yeni Cari) ya da yönetici; özel cari kısıtı uygulanır.
 */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/../includes/belge/sayfa.php';

if (!lumen_bagimsiz_mi()) {
    lumen_hata_sayfasi('Modül kullanılamıyor', 'Bu ekran yalnız bağımsız modda (Lumen veritabanı) kullanılır; LOGO modunda cari LOGO\'da düzenlenir.', 501, 'fa-person-digging');
}
if ((int) $yetkidurum !== 0 && (int) m_p_yetki($terminalkullanici, 'M12') !== 1) {
    lumen_hata_sayfasi('Yetkiniz yok', 'Cari kartı düzenlemek için M12 (Yeni Cari) yetkisi gerekir.', 403, 'fa-lock');
}
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!m_p_cariid_goruntulebilir_mi($dbh, $firma, (int) $terminalkullanici, $id, 'M4')) {
    lumen_hata_sayfasi('Cari bulunamadı', 'Bu cari kartı yok ya da görme yetkiniz yok.', 404);
}
$st = $dbh->prepare('SELECT * FROM dbo.LM_CARI WHERE ID = :id');
$st->execute([':id' => $id]);
$c = $st->fetch(PDO::FETCH_ASSOC);
if (!$c) {
    lumen_hata_sayfasi('Cari bulunamadı', 'Bu cari kartı yok.', 404);
}
$personeller = $dbh->query('SELECT ID, AD FROM dbo.LM_PERSONEL WHERE AKTIF = 1 ORDER BY AD')->fetchAll(PDO::FETCH_KEY_PAIR);

$hata = null;
$mukerrerUyari = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        lumen_hata_sayfasi('Geçersiz istek', 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.', 403);
    }
    $v = static fn(string $k, int $uz): ?string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $uz) ?: null;
    $unvan = $v('unvan', 200);
    $tur = (int) ($_POST['tur'] ?? 3);
    $vno = lumen_vergi_no_normalize((string) ($_POST['vergi_no'] ?? ''));
    $eposta = $v('eposta', 160);
    $vade = (int) ($_POST['vade_gun'] ?? 0);
    $riskHam = trim((string) ($_POST['risk_limiti'] ?? ''));
    $risk = $riskHam === '' ? null : lumen_tutar_coz($riskHam);
    $paz = (int) ($_POST['pazarlamaci_id'] ?? 0);
    $eslesen = $vno !== '' && lumen_vergi_no_gecerli($vno)
        ? lumen_cari_vergi_eslesmesi($dbh, $vno, $id)
        : null;
    if ($unvan === null) {
        $hata = 'Unvan boş olamaz.';
    } elseif (!in_array($tur, [1, 2, 3], true)) {
        $hata = 'Geçersiz cari türü.';
    } elseif ($vno !== '' && !lumen_vergi_no_gecerli($vno)) {
        $hata = 'Vergi no (10 hane) / TCKN (11 hane) geçersiz; numarayı kontrol edin.';
    } elseif ($eslesen !== null && !isset($_POST['mukerrer_onay'])) {
        $hata = "Bu numara [{$eslesen['KOD']}] {$eslesen['UNVAN']} carisinde kullanılıyor. Bilinçli bir istisnaysa aşağıdan onaylayın.";
        $mukerrerUyari = true;
    } elseif ($eposta !== null && filter_var($eposta, FILTER_VALIDATE_EMAIL) === false) {
        $hata = 'E-posta adresi geçersiz.';
    } elseif ($vade < 0 || $vade > 365) {
        $hata = 'Vade günü 0 ile 365 arasında olmalı.';
    } elseif ($risk !== null && $risk < 0) {
        $hata = 'Risk limiti negatif olamaz.';
    } elseif ($paz > 0 && !isset($personeller[$paz])) {
        $hata = 'Pazarlamacı bulunamadı.';
    } else {
        $dbh->prepare('UPDATE dbo.LM_CARI SET UNVAN = :u, TUR = :t, VERGI_DAIRESI = :vd, VERGI_NO = :vno, TC_NO = :tc, IL = :il, ILCE = :ilce,
                ADRES = :adres, TELEFON = :tel, EPOSTA = :ep, VADE_GUN = :vade, RISK_LIMITI = :risk, OZEL_KOD = :ok, PAZARLAMACI_ID = :paz,
                AKTIF = :aktif, GUNCELLEME = SYSDATETIME() WHERE ID = :id')
            ->execute([':u' => $unvan, ':t' => $tur, ':vd' => $v('vergi_dairesi', 80), ':vno' => strlen($vno) === 10 ? $vno : null,
                ':tc' => strlen($vno) === 11 ? $vno : null, ':il' => $v('il', 60), ':ilce' => $v('ilce', 60), ':adres' => $v('adres', 400),
                ':tel' => $v('telefon', 40), ':ep' => $eposta, ':vade' => $vade, ':risk' => $risk, ':ok' => $v('ozel_kod', 40),
                ':paz' => $paz ?: null, ':aktif' => isset($_POST['aktif']) ? 1 : 0, ':id' => $id]);
        if (function_exists('app_log_write')) {
            app_log_write('cari-kart', ['id' => $id, 'personel' => (int) $terminalkullanici]);
        }
        header('Location: cari.php?' . http_build_query(['id' => $id, 'ok' => 'Cari kartı güncellendi.']), true, 303);
        exit;
    }
}
// Hatalı gönderimde girilenler korunur
$d = static function (string $alan, string $kolon) use ($c, $hata): string {
    return (string) ($hata !== null ? ($_POST[$alan] ?? '') : ($c[$kolon] ?? ''));
};

belge_sayfa_basla('Düzenle: ' . $c['KOD'], 'fa-address-card', 'cari.php?id=' . $id);
if ($hata !== null) {
    echo '<div class="bildirim hata" role="alert"><i class="fa-solid fa-circle-exclamation"></i>' . bh($hata) . '</div>';
}
$turSec = (int) ($hata !== null ? ($_POST['tur'] ?? 3) : $c['TUR']);
$pazSec = (int) ($hata !== null ? ($_POST['pazarlamaci_id'] ?? 0) : ($c['PAZARLAMACI_ID'] ?? 0));
$aktif = $hata !== null ? isset($_POST['aktif']) : (int) $c['AKTIF'] === 1;
$vnoDeger = $hata !== null ? (string) ($_POST['vergi_no'] ?? '') : (string) ($c['VERGI_NO'] ?: $c['TC_NO'] ?: '');
$risk = $c['RISK_LIMITI'] !== null ? rtrim(rtrim(number_format((float) $c['RISK_LIMITI'], 2, ',', ''), '0'), ',') : '';
?>
<style>
    label.alan.kutu { flex-direction: row; align-items: center; gap: 8px; font-size: 13px; }
    label.alan.kutu input { width: auto; flex: 0 0 auto; }
</style>
<form method="post" action="cari_duzenle.php" class="kart kart-ic">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <div class="izgara">
        <label class="alan">Cari kodu <input value="<?= bh($c['KOD']) ?>" disabled></label>
        <label class="alan" style="grid-column:span 2">Unvan <input name="unvan" required maxlength="200" value="<?= bh($d('unvan', 'UNVAN')) ?>"></label>
        <label class="alan">Tür
            <select name="tur"><?php foreach ([1 => 'Alıcı (müşteri)', 2 => 'Satıcı (tedarikçi)', 3 => 'Alıcı + satıcı'] as $k => $a): ?>
                <option value="<?= $k ?>" <?= $k === $turSec ? 'selected' : '' ?>><?= bh($a) ?></option><?php endforeach; ?></select></label>
        <label class="alan">Vergi dairesi <input name="vergi_dairesi" maxlength="80" value="<?= bh($d('vergi_dairesi', 'VERGI_DAIRESI')) ?>"></label>
        <label class="alan">Vergi no / TCKN <input name="vergi_no" inputmode="numeric" maxlength="20" value="<?= bh($vnoDeger) ?>"></label>
        <?php if ($mukerrerUyari): ?><label class="alan kutu" style="grid-column:1/-1"><input type="checkbox" name="mukerrer_onay" value="1"> Eşleşen cariyi gördüm; bu istisnayı bilerek yine de kaydet.</label><?php endif; ?>
        <label class="alan">İl <input name="il" maxlength="60" value="<?= bh($d('il', 'IL')) ?>"></label>
        <label class="alan">İlçe <input name="ilce" maxlength="60" value="<?= bh($d('ilce', 'ILCE')) ?>"></label>
        <label class="alan" style="grid-column:1/-1">Adres <input name="adres" maxlength="400" value="<?= bh($d('adres', 'ADRES')) ?>"></label>
        <label class="alan">Telefon <input name="telefon" maxlength="40" inputmode="tel" value="<?= bh($d('telefon', 'TELEFON')) ?>"></label>
        <label class="alan">E-posta <input name="eposta" type="email" maxlength="160" value="<?= bh($d('eposta', 'EPOSTA')) ?>"></label>
        <label class="alan">Vade (gün) <input name="vade_gun" type="number" min="0" max="365" value="<?= bh($d('vade_gun', 'VADE_GUN')) ?>"></label>
        <label class="alan">Risk limiti (₺) <input name="risk_limiti" inputmode="decimal" value="<?= bh($hata !== null ? (string) ($_POST['risk_limiti'] ?? '') : $risk) ?>" placeholder="boş: sınırsız"></label>
        <label class="alan">Özel kod <input name="ozel_kod" maxlength="40" value="<?= bh($d('ozel_kod', 'OZEL_KOD')) ?>"></label>
        <label class="alan">Pazarlamacı
            <select name="pazarlamaci_id"><option value="0">—</option>
                <?php foreach ($personeller as $pid => $pad): ?><option value="<?= (int) $pid ?>" <?= (int) $pid === $pazSec ? 'selected' : '' ?>><?= bh($pad) ?></option><?php endforeach; ?></select></label>
        <label class="alan kutu"><input type="checkbox" name="aktif" value="1" <?= $aktif ? 'checked' : '' ?>> Aktif (pasif cari belgelerde ve aramada çıkmaz)</label>
    </div>
    <p class="soluk" style="font-size:12.5px;margin-top:10px">Vade günü, vadesi girilmeyen faturaların vadesini ve vade takibini belirler.</p>
    <div style="margin-top:14px;display:flex;gap:10px;justify-content:flex-end">
        <a class="btn btn-ikincil" href="cari.php?id=<?= $id ?>">Vazgeç</a>
        <button class="btn btn-birincil" type="submit"><i class="fa-solid fa-check"></i> Kaydet</button>
    </div>
</form>
<?php belge_sayfa_bitir();

<?php

declare(strict_types=1);

/**
 * roller.php — Hazır rol şablonlarını yönet (Kullanıcı & Yetki > Roller sekmesi).
 *
 * Built-in roller (Yönetici, Tümünü Sıfırla) salt-okunur gösterilir.
 * Özel roller ayar/rol_helper.php üzerinden JSON'da saklanır; burada
 * eklenir/düzenlenir/silinir. İzin Matrisi bu rolleri "Hazır Rol" seçicisinde kullanır.
 *
 * Yetki: yalnız yönetici (M16). Tüm yazma işlemleri CSRF + PRG.
 */

require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';
require_once __DIR__ . '/rol_helper.php';

ayar_require_m16($terminalkullanici);

/** Rol adından güvenli anahtar üret (küçük harf, TR→ASCII, boşluk→_). */
function rol_slug(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u', 'â' => 'a', 'î' => 'i', 'û' => 'u']);
    $s = preg_replace('/[^a-z0-9]+/', '_', $s) ?? '';
    $s = trim($s, '_');
    return $s !== '' ? mb_substr($s, 0, 36) : 'rol';
}

$yetkiGruplari = yetki_gruplari();
$hataMsj = '';
$formEski = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ayar_require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    // ---- Sil ----
    if ($action === 'delete') {
        $key = trim((string) ($_POST['key'] ?? ''));
        $mevcut = rol_ozel_yukle();
        if ($key !== '' && isset($mevcut[$key])) {
            unset($mevcut[$key]);
            rol_ozel_kaydet($mevcut);
            header('Location: roller.php?ok=silindi', true, 303);
            exit;
        }
        header('Location: roller.php?hata=yok', true, 303);
        exit;
    }

    // ---- Kaydet (yeni / düzenle) ----
    if ($action === 'save') {
        $key      = trim((string) ($_POST['key'] ?? '')); // boş = yeni
        $name     = trim((string) ($_POST['name'] ?? ''));
        $aciklama = trim((string) ($_POST['aciklama'] ?? ''));
        $tipi     = (int) ($_POST['tipi'] ?? 1);
        $secili   = array_values(array_filter((array) ($_POST['yetkiler'] ?? []), 'is_string'));
        $yeni     = ($key === '');
        $formEski = ['key' => $key, 'name' => $name, 'aciklama' => $aciklama, 'tipi' => $tipi, 'yetkiler' => $secili];

        if ($name === '') {
            $hataMsj = 'Rol adı boş olamaz.';
        } elseif (!in_array($tipi, [1, 2], true)) {
            $hataMsj = 'Geçersiz yetki tipi.';
        } elseif (!$secili) {
            $hataMsj = 'En az bir yetki seçmelisiniz.';
        }

        if ($hataMsj === '') {
            $mevcut = rol_ozel_yukle();
            if ($yeni) {
                $base = rol_slug($name);
                $key = $base;
                $i = 2;
                while (isset($mevcut[$key]) || in_array($key, ['yonetici', 'sifirla'], true)) {
                    $key = $base . '_' . $i;
                    $i++;
                }
            } elseif (!isset($mevcut[$key])) {
                $hataMsj = 'Düzenlenecek rol bulunamadı.';
            }

            if ($hataMsj === '') {
                $eski = $mevcut[$key] ?? [];
                $mevcut[$key] = [
                    'name'       => $name,
                    'icon'       => $eski['icon'] ?? 'fa-user-tag',
                    'color'      => $eski['color'] ?? 'blue',
                    'aciklama'   => $aciklama,
                    'yetki_turu' => $tipi,
                    'yetkiler'   => $secili,
                ];
                if (rol_ozel_kaydet($mevcut)) {
                    header('Location: roller.php?ok=' . ($yeni ? 'eklendi' : 'guncellendi'), true, 303);
                    exit;
                }
                $hataMsj = 'Kaydedilemedi — ayar/ klasörünün yazma iznini kontrol edin.';
            }
        }
    }
}

$ozelRoller = rol_ozel_yukle();
$aktifSekme = 'roller';
$formAcik = ($hataMsj !== '');
$yetkiTuruAd = [1 => 'Personel', 2 => 'Müşteri'];
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$bildirim = ''; $bildirimTip = 'ok';
if ($hataMsj !== '') {
    $bildirim = $hataMsj; $bildirimTip = 'error';
} elseif (isset($_GET['ok'])) {
    $bildirim = ['eklendi' => 'Rol eklendi.', 'guncellendi' => 'Rol güncellendi.', 'silindi' => 'Rol silindi.'][$_GET['ok']] ?? '';
} elseif (isset($_GET['hata'])) {
    $bildirim = 'İşlem tamamlanamadı.'; $bildirimTip = 'error';
}

// Built-in roller (salt-okunur gösterim)
$builtinRoller = [
    ['name' => 'Yönetici', 'icon' => 'fa-crown', 'aciklama' => 'Tüm yetkiler açık (YETKI türü = Yönetici). Kullanıcıya tam erişim verir.'],
    ['name' => 'Tümünü Sıfırla', 'icon' => 'fa-ban', 'aciklama' => 'Tüm yetkileri kaldırır (YETKI türü değişmez).'],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Roller - Lumen</title>
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
        .arac{ display:flex; align-items:center; gap:12px; margin-bottom:18px; }
        .arac .baslik{ font-size:15px; font-weight:700; display:flex; align-items:center; gap:8px; }
        .arac .baslik i{ color:var(--red); }
        .arac .yeni{ margin-left:auto; }
        .btn{ display:inline-flex; align-items:center; gap:7px; padding:10px 16px; border-radius:10px; font-weight:600; font-size:13.5px; cursor:pointer; border:1px solid transparent; font-family:inherit; text-decoration:none; }
        .btn-red{ background:var(--red); color:#fff; } .btn-red:hover{ filter:brightness(1.08); }
        .btn-hat{ background:#fff; color:var(--text-2); border:1px solid var(--border); } .btn-hat:hover{ border-color:var(--red); color:var(--red); }

        .mesaj{ padding:12px 14px; border-radius:10px; margin-bottom:16px; font-size:13px; display:flex; gap:9px; align-items:center; }
        .mesaj.ok{ background:var(--emerald-soft); color:#065f46; border:1px solid #a7f3d0; }
        .mesaj.error{ background:var(--red-soft); color:#991b1b; border:1px solid #fecaca; }

        .bolum-bas{ font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--text-3); margin:22px 2px 10px; }
        .bolum-bas:first-of-type{ margin-top:4px; }
        .grid{ display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:12px; }
        .rk{ background:var(--card); border:1px solid var(--border); border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:10px; }
        .rk-ust{ display:flex; align-items:flex-start; gap:11px; }
        .rk-ikon{ width:40px; height:40px; border-radius:11px; background:var(--red-soft); color:var(--red); display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
        .rk.kilit .rk-ikon{ background:#f3f4f6; color:var(--text-2); }
        .rk-ad{ font-weight:700; font-size:14.5px; flex:1; }
        .rk-tip{ font-size:11px; font-weight:600; padding:3px 8px; border-radius:20px; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; white-space:nowrap; }
        .rk-tip.musteri{ background:#f5f3ff; color:#6d28d9; border-color:#ddd6fe; }
        .rk-tip.kilit{ background:#f3f4f6; color:var(--text-2); border-color:var(--border); }
        .rk-acik{ font-size:12.5px; color:var(--text-2); line-height:1.5; min-height:19px; }
        .rk-sayi{ font-size:12px; color:var(--text-3); font-weight:600; }
        .rk-alt{ display:flex; gap:8px; margin-top:2px; padding-top:11px; border-top:1px solid var(--border); }
        .rk-alt .btn-mini{ flex:1; justify-content:center; padding:8px 10px; border-radius:9px; font-size:12.5px; font-weight:600; border:1px solid var(--border); background:#fff; color:var(--text-2); cursor:pointer; font-family:inherit; display:inline-flex; align-items:center; gap:6px; }
        .rk-alt .btn-mini:hover{ border-color:var(--red); color:var(--red); }
        .rk-alt .btn-mini.sil:hover{ border-color:#dc2626; color:#dc2626; background:#fef2f2; }
        .rk-kilit-not{ font-size:11.5px; color:var(--text-3); display:flex; align-items:center; gap:6px; margin-top:2px; padding-top:11px; border-top:1px dashed var(--border); }

        /* Modal */
        .modal-ort{ position:fixed; inset:0; background:rgba(17,24,39,.5); display:none; align-items:center; justify-content:center; z-index:100; padding:16px; }
        .modal-ort.acik{ display:flex; }
        .modal{ background:#fff; border-radius:16px; width:100%; max-width:560px; max-height:90vh; box-shadow:0 24px 60px rgba(0,0,0,.28); display:flex; flex-direction:column; overflow:hidden; }
        .modal-bas{ padding:17px 22px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .modal-bas i{ color:var(--red); }
        .modal-bas h3{ font-size:16px; font-weight:700; flex:1; }
        .modal-bas .kapat{ width:32px; height:32px; border:none; background:#f3f4f6; border-radius:8px; color:var(--text-2); cursor:pointer; }
        .modal-govde{ padding:18px 22px; overflow-y:auto; }
        .alan{ margin-bottom:15px; }
        .alan label{ display:block; font-size:12.5px; font-weight:600; color:var(--text-2); margin-bottom:6px; }
        .alan input[type=text], .alan select{ width:100%; padding:11px 12px; border:1px solid var(--border); border-radius:10px; font-size:14px; font-family:inherit; outline:none; }
        .alan input:focus, .alan select:focus{ border-color:var(--red); box-shadow:0 0 0 3px var(--red-soft); }
        .grup{ margin-top:6px; }
        .grup-bas{ display:flex; align-items:center; gap:8px; font-size:12.5px; font-weight:700; color:var(--text-1); margin:14px 0 8px; }
        .grup-bas i{ color:var(--red); font-size:12px; }
        .grup-bas .tumu{ margin-left:auto; font-size:11px; font-weight:600; color:var(--red); background:none; border:none; cursor:pointer; font-family:inherit; }
        .yk{ display:flex; align-items:flex-start; gap:10px; padding:9px 11px; border:1px solid var(--border); border-radius:10px; margin-bottom:7px; cursor:pointer; transition:.12s; }
        .yk:hover{ border-color:#d1d5db; background:#fafafa; }
        .yk input{ margin-top:2px; width:16px; height:16px; accent-color:var(--red); cursor:pointer; flex-shrink:0; }
        .yk .yk-ad{ font-size:13px; font-weight:600; color:var(--text-1); }
        .yk .yk-desc{ font-size:11.5px; color:var(--text-3); margin-top:1px; line-height:1.4; }
        .modal-alt{ padding:15px 22px; border-top:1px solid var(--border); display:flex; gap:10px; flex-shrink:0; }
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

        <div class="arac">
            <span class="baslik"><i class="fa-solid fa-user-shield"></i> Hazır Roller</span>
            <button type="button" class="btn btn-red yeni" onclick="rolYeni()"><i class="fa-solid fa-plus"></i> Yeni Rol</button>
        </div>

        <div class="bolum-bas">Özel Roller (düzenlenebilir)</div>
        <div class="grid">
            <?php if (!$ozelRoller): ?>
                <div class="rk"><div class="rk-acik">Henüz özel rol yok. “Yeni Rol” ile ekleyin.</div></div>
            <?php else: foreach ($ozelRoller as $key => $rol):
                $tip = (int) ($rol['yetki_turu'] ?? 1); ?>
                <div class="rk">
                    <div class="rk-ust">
                        <div class="rk-ikon"><i class="fa-solid <?= $h($rol['icon'] ?? 'fa-user-tag') ?>"></i></div>
                        <div class="rk-ad"><?= $h($rol['name']) ?></div>
                        <span class="rk-tip <?= $tip === 2 ? 'musteri' : '' ?>"><?= $h($yetkiTuruAd[$tip] ?? 'Personel') ?></span>
                    </div>
                    <div class="rk-acik"><?= $h($rol['aciklama'] ?? '') ?></div>
                    <div class="rk-sayi"><i class="fa-solid fa-key" style="font-size:10px;"></i> <?= count($rol['yetkiler'] ?? []) ?> yetki</div>
                    <div class="rk-alt">
                        <button type="button" class="btn-mini" onclick="rolDuzenle('<?= $h($key) ?>')"><i class="fa-solid fa-pen"></i> Düzenle</button>
                        <button type="button" class="btn-mini sil" onclick="rolSil('<?= $h($key) ?>', <?= json_encode($rol['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)"><i class="fa-solid fa-trash"></i></button>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="bolum-bas">Sabit Roller (built-in)</div>
        <div class="grid">
            <?php foreach ($builtinRoller as $b): ?>
                <div class="rk kilit">
                    <div class="rk-ust">
                        <div class="rk-ikon"><i class="fa-solid <?= $h($b['icon']) ?>"></i></div>
                        <div class="rk-ad"><?= $h($b['name']) ?></div>
                        <span class="rk-tip kilit"><i class="fa-solid fa-lock" style="font-size:9px;"></i></span>
                    </div>
                    <div class="rk-acik"><?= $h($b['aciklama']) ?></div>
                    <div class="rk-kilit-not"><i class="fa-solid fa-circle-info"></i> Sistem rolü — değiştirilemez</div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>

    <!-- Ekle/Düzenle modal -->
    <div class="modal-ort" id="rolModal">
        <div class="modal">
            <form method="POST" id="rolForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="key" id="rol_key" value="">
                <div class="modal-bas">
                    <i class="fa-solid fa-user-shield"></i>
                    <h3 id="rol_baslik">Yeni Rol</h3>
                    <button type="button" class="kapat" onclick="rolKapat()"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <div class="modal-govde">
                    <div class="alan">
                        <label for="rol_name">Rol Adı</label>
                        <input type="text" name="name" id="rol_name" maxlength="40" autocomplete="off" required placeholder="Örn: Sevkiyat Sorumlusu">
                    </div>
                    <div class="alan">
                        <label for="rol_aciklama">Açıklama <span style="font-weight:400;color:var(--text-3);">(isteğe bağlı)</span></label>
                        <input type="text" name="aciklama" id="rol_aciklama" maxlength="120" autocomplete="off" placeholder="Kısa açıklama">
                    </div>
                    <div class="alan">
                        <label for="rol_tipi">Yetki Tipi</label>
                        <select name="tipi" id="rol_tipi">
                            <option value="1" selected>Personel — kısıtlı</option>
                            <option value="2">Müşteri — salt okunur</option>
                        </select>
                    </div>

                    <label style="display:block; font-size:12.5px; font-weight:600; color:var(--text-2); margin:16px 0 2px;">Yetkiler</label>
                    <?php foreach ($yetkiGruplari as $grupKey => $grup):
                        $aktifKodlar = array_filter($grup['kodlar'], static fn($t) => !empty($t['aktif']));
                        if (!$aktifKodlar) { continue; } ?>
                        <div class="grup">
                            <div class="grup-bas">
                                <i class="fa-solid <?= $h($grup['ikon']) ?>"></i> <?= $h($grup['ad']) ?>
                                <button type="button" class="tumu" onclick="rolGrupTumu(this)">Tümü</button>
                            </div>
                            <?php foreach ($aktifKodlar as $kod => $t): ?>
                                <label class="yk">
                                    <input type="checkbox" name="yetkiler[]" value="<?= $h($kod) ?>" data-grup="<?= $h($grupKey) ?>">
                                    <span>
                                        <span class="yk-ad"><?= $h($t['name']) ?></span>
                                        <span class="yk-desc"><?= $h($t['desc'] ?? '') ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="modal-alt">
                    <button type="button" class="btn btn-hat" onclick="rolKapat()">Vazgeç</button>
                    <button type="submit" class="btn btn-red"><i class="fa-solid fa-floppy-disk"></i> Kaydet</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Silme için gizli form -->
    <form method="POST" id="rolSilForm" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="key" id="rolsil_key" value="">
    </form>

    <script>
        var rolVerileri = <?= json_encode($ozelRoller, JSON_UNESCAPED_UNICODE) ?>;
        var rolModal = document.getElementById('rolModal');

        function rolKutulariTemizle(){
            document.querySelectorAll('#rolForm input[name="yetkiler[]"]').forEach(function(c){ c.checked = false; });
        }
        function rolKutulariAyarla(kodlar){
            rolKutulariTemizle();
            (kodlar || []).forEach(function(k){
                var el = document.querySelector('#rolForm input[name="yetkiler[]"][value="' + k + '"]');
                if (el) el.checked = true;
            });
        }
        function rolYeni(){
            document.getElementById('rol_key').value = '';
            document.getElementById('rol_baslik').textContent = 'Yeni Rol';
            document.getElementById('rol_name').value = '';
            document.getElementById('rol_aciklama').value = '';
            document.getElementById('rol_tipi').value = '1';
            rolKutulariTemizle();
            rolModal.classList.add('acik');
            setTimeout(function(){ document.getElementById('rol_name').focus(); }, 50);
        }
        function rolDuzenle(key){
            var r = rolVerileri[key];
            if (!r) return;
            document.getElementById('rol_key').value = key;
            document.getElementById('rol_baslik').textContent = 'Rol Düzenle';
            document.getElementById('rol_name').value = r.name || '';
            document.getElementById('rol_aciklama').value = r.aciklama || '';
            document.getElementById('rol_tipi').value = String(r.yetki_turu || 1);
            rolKutulariAyarla(r.yetkiler);
            rolModal.classList.add('acik');
            setTimeout(function(){ document.getElementById('rol_name').focus(); }, 50);
        }
        function rolKapat(){ rolModal.classList.remove('acik'); }
        rolModal.addEventListener('click', function(e){ if (e.target === rolModal) rolKapat(); });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') rolKapat(); });

        function rolGrupTumu(btn){
            var grup = btn.closest('.grup');
            var kutular = grup.querySelectorAll('input[type="checkbox"]');
            var hepsi = Array.prototype.every.call(kutular, function(c){ return c.checked; });
            kutular.forEach(function(c){ c.checked = !hepsi; });
        }
        function rolSil(key, ad){
            if (!confirm('"' + ad + '" rolü silinsin mi? (Uygulanmış kullanıcı yetkileri değişmez.)')) return;
            document.getElementById('rolsil_key').value = key;
            document.getElementById('rolSilForm').submit();
        }

        document.getElementById('rolForm').addEventListener('submit', function(e){
            var secili = document.querySelectorAll('#rolForm input[name="yetkiler[]"]:checked').length;
            if (secili === 0){ e.preventDefault(); alert('En az bir yetki seçmelisiniz.'); }
        });

        <?php if ($formAcik): ?>
        (function(){
            rolModal.classList.add('acik');
            document.getElementById('rol_key').value = <?= json_encode($formEski['key'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
            document.getElementById('rol_baslik').textContent = <?= ($formEski['key'] ?? '') !== '' ? "'Rol Düzenle'" : "'Yeni Rol'" ?>;
            document.getElementById('rol_name').value = <?= json_encode($formEski['name'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
            document.getElementById('rol_aciklama').value = <?= json_encode($formEski['aciklama'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
            document.getElementById('rol_tipi').value = '<?= (int) ($formEski['tipi'] ?? 1) ?>';
            rolKutulariAyarla(<?= json_encode($formEski['yetkiler'] ?? [], JSON_UNESCAPED_UNICODE) ?>);
        })();
        <?php endif; ?>
    </script>
</body>
</html>

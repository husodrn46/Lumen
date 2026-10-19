<?php

declare(strict_types=1);

/**
 * Lumen akış testleri — uçtan uca iş akışları (demo veritabanında).
 *
 * Duman testi (tests/duman) sayfaların açıldığını sınar; bu dosya gerçek işlem
 * yapar ve sonucu veritabanından doğrular: sipariş, iskonto, satır silme, yazdırma
 * düğmeleri, çek girişi/geri alma, dövizli sipariş, ayarlar, yetki, sekme
 * güvenliği. Bu daldaki düzeltmelerin geri gelmesini (regresyon) yakalar.
 *
 *   docker compose -f demo/docker-compose.yml exec app php tests/akis/akislar.php
 *
 * Yalnız LUMEN_ ile başlayan veritabanında çalışır (demo verisini değiştirir).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$URL = rtrim(getenv('LUMEN_TEST_URL') ?: 'http://web', '/');
$DB  = getenv('AKL_DB_NAME') ?: 'LUMEN_DEMO';
if (!preg_match('/^LUMEN_[A-Z0-9_]+$/', $DB)) {
    fwrite(STDERR, "REDDEDİLDİ: akış testleri yalnız demo/test veritabanında çalışır ({$DB}).\n");
    exit(3);
}
$pdo = new PDO(
    sprintf('sqlsrv:server=%s;database=%s;TrustServerCertificate=1', getenv('AKL_DB_SERVER') ?: 'db', $DB),
    getenv('AKL_DB_USER') ?: 'sa', getenv('AKL_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ---------------------------------------------------------------- yardımcılar
final class Tarayici
{
    private string $cerez;

    public function __construct(private string $url)
    {
        $this->cerez = (string) tempnam(sys_get_temp_dir(), 'akis_');
    }

    /** @return array{kod:int, govde:string, yer:string} */
    public function istek(string $yol, ?array $post = null, array $basliklar = []): array
    {
        $ch = curl_init(str_starts_with($yol, 'http') ? $yol : $this->url . '/' . ltrim($yol, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_COOKIEFILE => $this->cerez, CURLOPT_COOKIEJAR => $this->cerez,
            CURLOPT_HTTPHEADER => $basliklar,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $yanit = (string) curl_exec($ch);
        $bas = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $sonuc = ['kod' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'govde' => substr($yanit, $bas),
                  'yer' => (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '')];
        curl_close($ch);
        return $sonuc;
    }

    public function giris(string $kullanici): self
    {
        $this->istek('giris.php');
        $r = $this->istek('giris.php', ['kullaniciadi' => $kullanici, 'kullanicisifre' => 'demo1234']);
        if ($r['kod'] !== 302) {
            throw new RuntimeException("giriş başarısız: {$kullanici}");
        }
        return $this;
    }

    /** Sayfadaki CSRF belirteci (form alanı veya JS değişkeni). */
    public function belirtec(string $yol): string
    {
        $g = $this->istek($yol)['govde'];
        if (preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $g, $m) || preg_match('/csrf\w*\s*=\s*"([a-f0-9]{40,})"/i', $g, $m)) {
            return $m[1];
        }
        throw new RuntimeException("CSRF belirteci bulunamadı: {$yol}");
    }
}

function tek(PDO $pdo, string $sql, array $p = []): mixed
{
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $v = $st->fetchColumn();
    $st->closeCursor();
    return $v;
}

$sonuclar = [];
function akis(string $ad, callable $fn): void
{
    global $sonuclar;
    try {
        $fn();
        $sonuclar[] = [true, $ad, ''];
        echo "  ✓ {$ad}\n";
    } catch (Throwable $e) {
        $sonuclar[] = [false, $ad, $e->getMessage()];
        echo "  ✗ {$ad}\n      " . $e->getMessage() . "\n";
    }
}

function bekle(bool $kosul, string $mesaj): void
{
    if (!$kosul) {
        throw new RuntimeException($mesaj);
    }
}

function yakin(float $beklenen, float $gercek, string $ne): void
{
    bekle(abs($beklenen - $gercek) < 0.011, "{$ne}: beklenen {$beklenen}, gelen {$gercek}");
}

$yonetici = (new Tarayici($URL))->giris('YONETICI');

// ---------------------------------------------------------------- akışlar
echo "Akış testleri ({$DB})\n";

$fis = 0;
akis('sipariş aç: kimlik ve fiş no tutarlı (MAX(LOGICALREF) değil)', function () use ($yonetici, $pdo, &$fis): void {
    $once = (int) tek($pdo, "SELECT ISNULL(MAX(LOGICALREF),0) FROM LG_001_02_ORFICHE");
    $yonetici->istek('siparis/fisekle.php?cariid=5');
    $fis = (int) tek($pdo, "SELECT MAX(LOGICALREF) FROM LG_001_02_ORFICHE");
    bekle($fis > $once, 'yeni fiş oluşmadı');
    $no = (string) tek($pdo, "SELECT FICHENO FROM LG_001_02_ORFICHE WHERE LOGICALREF = :f", [':f' => $fis]);
    bekle(str_ends_with($no, str_pad((string) $fis, 6, '0', STR_PAD_LEFT)), "FICHENO ({$no}) kimlikle ({$fis}) uyuşmuyor");
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM M_FIS_LOG WHERE FIS_REF = :f", [':f' => $fis]) === 1, 'fiş logu yazılmadı');
});

akis('satır ekle (web) → fiş toplamları', function () use ($yonetici, $pdo, &$fis): void {
    foreach ([[1, 12, 42.5], [7, 48, 18.25]] as [$stok, $adet, $fiyat]) {
        $t = $yonetici->belirtec("stok/lg_stok_ekle.php?stok={$stok}&fisid={$fis}");
        $yonetici->istek("siparis/lg_fis.php?stokhareket={$fis}", ['csrf_token' => $t, 'stkid' => $stok, 'stokhareket' => $fis,
            'kontrol' => uniqid(), 'stkbirim' => 1, 'stkadt' => $adet, 'stbrm' => 1, 'stkfyt' => $fiyat, 'stkkdv' => 20, 'aciklama' => '']);
    }
    $n = (int) tek($pdo, "SELECT COUNT(*) FROM LG_001_02_ORFLINE WHERE ORDFICHEREF = :f AND LINETYPE = 0", [':f' => $fis]);
    bekle($n === 2, "2 satır beklendi, {$n} var");
    $yonetici->istek("siparis/lg_fis.php?stokhareket={$fis}"); // toplamlar görüntülemede yenilenir
    yakin(1386.0, (float) tek($pdo, "SELECT GROSSTOTAL FROM LG_001_02_ORFICHE WHERE LOGICALREF = :f", [':f' => $fis]), 'brüt');
});

akis('iskonto %10 + %5, sonra satır sil → iskonto yeniden dağıtılır, sıra no 1..N', function () use ($yonetici, $pdo, &$fis): void {
    $t = $yonetici->belirtec("siparis/lg_fis.php?stokhareket={$fis}");
    $yonetici->istek('siparis/iskonto.php', ['csrf_token' => $t, 'stokhareket' => $fis, 'isk1' => 10, 'ajax' => 1]);
    $yonetici->istek('siparis/iskonto.php', ['csrf_token' => $t, 'stokhareket' => $fis, 'isk2' => 5, 'ajax' => 1]);
    $isk = (float) tek($pdo, "SELECT SUM(TOTAL) FROM LG_001_02_ORFLINE WHERE ORDFICHEREF = :f AND LINETYPE = 2", [':f' => $fis]);
    // 1386 × %10 = 138,60; kalan 1247,40 × %5 = 62,37 → ~201 (satır bazlı yuvarlama farkı olabilir)
    bekle(abs($isk - 200.97) < 1.0, "iskonto toplamı beklenmedik: {$isk}");
    $sil = (int) tek($pdo, "SELECT TOP 1 LOGICALREF FROM LG_001_02_ORFLINE WHERE ORDFICHEREF = :f AND LINETYPE = 0 ORDER BY LOGICALREF DESC", [':f' => $fis]);
    $yonetici->istek("siparis/lg_fis.php?stokhareket={$fis}", ['csrf_token' => $t, 'hareketsil' => $sil, 'stokhareket' => $fis]);
    $isk2 = (float) tek($pdo, "SELECT SUM(TOTAL) FROM LG_001_02_ORFLINE WHERE ORDFICHEREF = :f AND LINETYPE = 2", [':f' => $fis]);
    bekle($isk2 < 100, "silinen satır iskontoda kaldı: {$isk2}");
    $sira = $pdo->query("SELECT LINENO_ FROM LG_001_02_ORFLINE WHERE ORDFICHEREF = {$fis} ORDER BY LINENO_")->fetchAll(PDO::FETCH_COLUMN);
    bekle(array_map('intval', $sira) === range(1, count($sira)), 'sıra numaraları 1..N değil: ' . implode(',', $sira));
});

akis('sipariş ekranı yazdırma düğmeleri doğru fişi açar', function () use ($yonetici, &$fis): void {
    $t = $yonetici->belirtec("siparis/lg_siparis.php?stokhareket={$fis}");
    foreach (['print_html', 'export_excel', 'print_dovizli'] as $a) {
        $r = $yonetici->istek("siparis/lg_siparis.php?stokhareket={$fis}", ['csrf_token' => $t, 'action' => $a, 'aciklama1' => 'a', 'aciklama2' => 'b']);
        bekle(str_contains($r['yer'], "stokhareket={$fis}"), "{$a}: yönlendirme fiş kimliği taşımıyor ({$r['yer']})");
        $g = $yonetici->istek($r['yer']);
        bekle($g['kod'] === 200 && !str_contains($g['govde'], 'fiş numarası belirtin'), "{$a}: sayfa fişi açmadı (HTTP {$g['kod']})");
    }
});

akis('yazdırılan genel toplam KDV\'yi iki kez eklemez', function () use ($yonetici, $pdo, &$fis): void {
    $net = (float) tek($pdo, "SELECT NETTOTAL FROM LG_001_02_ORFICHE WHERE LOGICALREF = :f", [':f' => $fis]);
    $g = $yonetici->istek("stok/stok_hareket_detay.php?stokhareket={$fis}")['govde'];
    bekle((bool) preg_match('/GENEL TOPLAM \(KDV Dahil\):<\/td><td[^>]*>([\d.,]+)/', $g, $m), 'genel toplam bulunamadı');
    yakin($net, (float) str_replace(['.', ','], ['', '.'], $m[1]), 'genel toplam');
});

akis('sekme güvenliği: başka sekmede açılan fiş ilk sekmeyi etkilemez', function () use ($yonetici, &$fis): void {
    $yonetici->istek("stok/lg_stok_ekle.php?stok=3&fisid={$fis}");
    $yonetici->istek('stok/lg_stok_ekle.php?stok=3&fisid=1');
    $g = $yonetici->istek("stok/lg_stok_ekle.php?stok=3&fisid={$fis}")['govde'];
    bekle(str_contains($g, "stokhareket={$fis}"), 'ilk sekme diğer sekmenin fişine yazacak');
});

akis('arama Türkçe harf ve büyük/küçük harf duyarsız (cari + ürün)', function () use ($yonetici): void {
    foreach (['İç Anadolu', 'ic anadolu', 'IC ANADOLU'] as $q) {
        bekle(str_contains($yonetici->istek('cari/cari.php?cari=' . rawurlencode($q))['govde'], '120.DM.009'), "cari bulunamadı: {$q}");
    }
    foreach (['Tabağı', 'tabagi', 'TABAGI', 'TABAĞI'] as $q) {
        bekle(str_contains($yonetici->istek('fiyat/fiyat_listesi.php?q=' . rawurlencode($q))['govde'], 'DM-0001'), "ürün bulunamadı: {$q}");
    }
});

akis('çek girişi kuruşlu tutarı aynen kaydeder; mükerrer gönderim ve geri alma', function () use ($yonetici, $pdo): void {
    $t = $yonetici->belirtec('cek/cek_panel.php?cari=4');
    $istek = bin2hex(random_bytes(16));
    $post = ['csrf_token' => $t, 'cari_ref' => 4, 'aciklama' => 'akis', 'istek_id' => $istek,
             'kalemler' => json_encode([['tutar' => 1234.56, 'cekno' => 'AKIS', 'banka' => 'Demo', 'vade' => '2026-12-15', 'sahibi' => '']])];
    $j = json_decode($yonetici->istek('cek/cek_giris.php', $post)['govde'], true);
    bekle(($j['ok'] ?? false) === true, 'çek girişi başarısız: ' . ($j['mesaj'] ?? '?'));
    yakin(1234.56, (float) tek($pdo, "SELECT AMOUNT FROM LG_001_02_CSCARD WHERE LOGICALREF = :r", [':r' => $j['cscard_ref']]), 'CSCARD tutarı');
    yakin(1234.56, (float) tek($pdo, "SELECT AMOUNT FROM LG_001_02_CLFLINE WHERE LOGICALREF = :r", [':r' => $j['clfline_ref']]), 'CLFLINE tutarı');
    // Aynı istek kimliğiyle TAZE belirteçli tekrar (ör. ikinci sekme / yeniden gönderim)
    $post['csrf_token'] = $yonetici->belirtec('cek/cek_panel.php?cari=4');
    $j2 = json_decode($yonetici->istek('cek/cek_giris.php', $post)['govde'], true);
    bekle(($j2['kod'] ?? 0) === 409, 'aynı istek kimliği ikinci kez kaydedildi');
    $t2 = $yonetici->belirtec('cek/cek_panel.php?cari=4');
    $g = json_decode($yonetici->istek('cek/cek_geri_al.php', ['csrf_token' => $t2, 'log_id' => $j['log_id']])['govde'], true);
    bekle(($g['ok'] ?? false) === true, 'geri alma başarısız: ' . ($g['mesaj'] ?? '?'));
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LG_001_02_CSCARD WHERE LOGICALREF = :r", [':r' => $j['cscard_ref']]) === 0, 'çek kartı silinmedi');
});

akis('dövizli sipariş: döviz kodu TRCURR\'a yazılır, TRNET = NETTOTAL / kur', function () use ($yonetici, $pdo): void {
    $t = $yonetici->belirtec('doviz/fisekle.php?cariid=3&doviz=1');
    $r = $yonetici->istek('doviz/fisekle.php?cariid=3&doviz=1', ['csrf_token' => $t, 'kur' => '40', 'genexp1' => 'akis']);
    bekle((bool) preg_match('/id=(\d+)/', $r['yer'], $m), 'dövizli fiş açılmadı');
    $df = (int) $m[1];
    $g = $yonetici->istek("doviz/stok_bul.php?fisid={$df}&q=Tabak")['govde'];
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $g, $tm);
    $re = $yonetici->istek("doviz/stok_bul.php?fisid={$df}", ['csrf_token' => $tm[1], 'stokid' => 1, 'miktar' => 10, 'fiyat' => 2, 'kdv' => 20, 'aciklama' => ''])['govde'];
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $re, $tm2);
    $yonetici->istek("doviz/fis.php?id={$df}", ['csrf_token' => $tm2[1], 'stokid' => 1, 'miktar' => 10, 'fiyat' => 2, 'kdv' => 20, 'aciklama' => '']);
    $t3 = $yonetici->belirtec("doviz/doviz_degistir.php?stokhareket={$df}");
    $yonetici->istek('doviz/doviz_degistir.php', ['csrf_token' => $t3, 'stokhareket' => $df, 'trcurr' => 20, 'trrate' => 48]);
    $st = $pdo->query("SELECT TRCODE, TRCURR, TRRATE, NETTOTAL, TRNET FROM LG_001_02_ORFICHE WHERE LOGICALREF = {$df}")->fetch(PDO::FETCH_ASSOC);
    bekle((int) $st['TRCODE'] === 1, 'sipariş türü (TRCODE) bozuldu');
    bekle((int) $st['TRCURR'] === 20, 'döviz TRCURR\'a yazılmadı');
    bekle((float) $st['NETTOTAL'] > 0, 'satır eklenmedi');
    yakin((float) $st['NETTOTAL'] / 48, (float) $st['TRNET'], 'TRNET');
});

akis('ayarlar JSON\'a yazılır; _bilgi_.inc değişmez; UNC yazıcı adı korunur', function () use ($yonetici): void {
    $kok = dirname(__DIR__, 2);
    $once = md5_file($kok . '/_bilgi_.inc');
    $t = $yonetici->belirtec('ayar/sistem_ayarlari.php');
    $yonetici->istek('ayar/sistem_ayarlari.php', ['csrf_token' => $t, 'guncelle' => 1, 'fisyazici' => '\\\\SRV\\HP',
        'sipno' => '{${system(1)}}', 'dovizlicalis' => 1]);
    bekle(md5_file($kok . '/_bilgi_.inc') === $once, '_bilgi_.inc değişti');
    $a = json_decode((string) file_get_contents($kok . '/database/ayarlar.json'), true)['ayarlar'] ?? [];
    bekle(($a['fisyazici'] ?? '') === '\\\\SRV\\HP', 'UNC yazıcı adı bozuldu: ' . ($a['fisyazici'] ?? '-'));
    bekle(!str_contains((string) ($a['sipno'] ?? ''), '$'), 'enjeksiyon süzülmedi');
    @unlink($kok . '/database/ayarlar.json');
});

akis('yetki: stok personeli sipariş satırı ekleyemez, M16 personeli yönetici olamaz', function () use ($URL, $pdo, &$fis): void {
    $depo = (new Tarayici($URL))->giris('DEPO1');
    $once = (int) tek($pdo, "SELECT COUNT(*) FROM LG_001_02_ORFLINE WHERE ORDFICHEREF = :f", [':f' => $fis]);
    $t = $depo->belirtec('stok/index.php');
    $depo->istek("siparis/lg_fis.php?stokhareket={$fis}", ['csrf_token' => $t, 'stkid' => 2, 'stokhareket' => $fis,
        'kontrol' => uniqid(), 'stkbirim' => 1, 'stkadt' => 1, 'stbrm' => 1, 'stkfyt' => 1, 'stkkdv' => 20]);
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LG_001_02_ORFLINE WHERE ORDFICHEREF = :f", [':f' => $fis]) === $once, 'DEPO1 satır ekleyebildi');

    $pdo->exec("UPDATE M_P_YETKI SET M16 = 1 WHERE PERSONEL = 2");
    try {
        $satis = (new Tarayici($URL))->giris('SATIS1');
        $t2 = $satis->belirtec('ayar/yetki_matrisi.php');
        $satis->istek('ayar/yetki_guncelle_ajax.php', ['csrf_token' => $t2, 'personel_id' => 2, 'yetki_kodu' => 'YETKI', 'yeni_deger' => 0]);
        bekle((int) tek($pdo, "SELECT YETKI FROM M_P_YETKI WHERE PERSONEL = 2") === 1, 'M16 personeli kendini yönetici yaptı');
    } finally {
        $pdo->exec("UPDATE M_P_YETKI SET M16 = 0, YETKI = 1 WHERE PERSONEL = 2");
    }
});

akis('API: fiyat override SP1 ister; pasif kullanıcının belirteci geçersiz', function () use ($URL, $pdo): void {
    $tok = static function (string $k) use ($URL): string {
        $ch = curl_init("{$URL}/api/login.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['kullanici' => $k, 'sifre' => 'demo1234'])]);
        $j = json_decode((string) curl_exec($ch), true);
        curl_close($ch);
        return (string) ($j['token'] ?? '');
    };
    $api = static function (string $tok, string $uc, ?array $govde = null) use ($URL): array {
        $ch = curl_init("{$URL}/api/{$uc}");
        $h = ["Authorization: Bearer {$tok}", 'Content-Type: application/json'];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h]);
        if ($govde !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($govde)]);
        }
        $j = json_decode((string) curl_exec($ch), true) ?: [];
        $j['_kod'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $j;
    };
    $s = $tok('SATIS1');
    $o = $api($s, 'siparis_onizle.php', ['cari_id' => 4, 'kalemler' => [['stok_id' => 2, 'miktar' => 12, 'fiyat' => 0.01, 'kdv' => 0]]]);
    yakin(528.0, (float) ($o['ara_toplam'] ?? 0), 'SP1 olmadan liste fiyatı');
    $d = $tok('DEPO1');
    bekle($api($d, 'rapor_kasa.php')['_kod'] === 403, 'DEPO1 kasa raporunu okudu');
    $pdo->exec("UPDATE LG_SLSMAN SET ACTIVE = 1 WHERE LOGICALREF = 3");
    try {
        bekle($api($d, 'stok_ara.php')['_kod'] === 401, 'pasif kullanıcının belirteci çalıştı');
    } finally {
        $pdo->exec("UPDATE LG_SLSMAN SET ACTIVE = 0 WHERE LOGICALREF = 3");
    }
});

$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d akış, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz === 0 ? 0 : 1);

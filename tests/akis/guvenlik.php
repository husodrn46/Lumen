<?php

declare(strict_types=1);

/**
 * Güvenlik regresyon akışları (bağımsız mod) — v1.3 öncesi güvenlik denetiminde bulunan
 * açıkların her biri yeniden denenir ve engellendiği doğrulanır. Bir düzeltme ileride
 * bozulursa burada yakalanır.
 *
 *   bash demo/kur.sh --bagimsiz
 *   docker compose -f demo/docker-compose.yml exec -T app php tests/akis/guvenlik.php
 */

require __DIR__ . '/_ortak.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (strtolower((string) getenv('LUMEN_VERI_KAYNAGI')) !== 'lumen') {
    fwrite(STDERR, "Bu akışlar bağımsız mod içindir (bash demo/kur.sh --bagimsiz).\n");
    exit(3);
}
echo "Güvenlik regresyonları ({$DB})\n";
$pdo->exec('DELETE FROM M_GIRIS_LOG');
$y = (new Tarayici($URL))->giris('YONETICI');
$bugun = date('Y-m-d');

/** POST belge/islem.php kaydet; yönlendirmedeki id/hata. */
$belge = static function (Tarayici $t, array $alan): array {
    $r = $t->istek('belge/islem.php', $alan + ['csrf_token' => $t->belirtec('belge/yeni.php'), 'islem' => 'kaydet', 'tarih' => date('Y-m-d')]);
    parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
    return ['kod' => $r['kod'], 'id' => (int) ($q['id'] ?? 0), 'hata' => (string) ($q['hata'] ?? '')];
};
$satir1 = static fn(int $stok, string $miktar, string $fiyat = '10', array $ek = []): array
    => ['satir' => ['stok_id' => [$stok], 'birim_id' => [0], 'miktar' => [$miktar], 'fiyat' => [$fiyat], 'iskonto' => ['0'], 'kdv' => ['20']] + $ek];
$iptal = static function (Tarayici $t, string $modul, int $id): void {
    $t->istek("{$modul}/islem.php", ['csrf_token' => $t->belirtec("{$modul}/yeni.php"), 'islem' => 'iptal', 'id' => $id]);
};
$kisitla = static function (int $cari, bool $ac) use ($pdo): void {
    $pdo->exec("DELETE FROM M_OZEL_CARI_KISIT WHERE CARIREF = {$cari}");
    if ($ac) {
        $pdo->exec("INSERT INTO M_OZEL_CARI_KISIT (CARIREF, OLUSTURAN, OLUSTURMA_TARIHI, YETKI_KODU, PERSONEL_ID)
                    VALUES ({$cari}, 1, GETDATE(), 'M19', 2), ({$cari}, 1, GETDATE(), 'M4', 2)");
    }
};

akis('özel cari: gizli carinin faturası ve kasa fişi iptal edilemez; stok kartında adı, API çek listesinde çekleri görünmez', function () use ($y, $pdo, $URL, $belge, $satir1, $iptal, $kisitla): void {
    $f = $belge($y, ['tur' => 'satis_fatura', 'cari_id' => 8] + $satir1(9, '1'));
    $r = $y->istek('finans/islem.php', ['csrf_token' => $y->belirtec('finans/yeni.php'), 'islem' => 'kaydet', 'tur' => 1, 'cari_id' => 8, 'tutar' => '10',
        'hesap_id' => (int) tek($pdo, "SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'KASA.01'"), 'istek_id' => bin2hex(random_bytes(16))]);
    parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
    $fis = (int) ($q['id'] ?? 0);
    bekle($f['id'] > 0 && $fis > 0, 'test kayıtları oluşmadı');
    $kisitla(8, true);
    try {
        $s = (new Tarayici($URL))->giris('SATIS1');
        $iptal($s, 'belge', $f['id']);
        bekle((int) tek($pdo, "SELECT DURUM FROM LM_BELGE WHERE ID = {$f['id']}") === 1, 'gizli carinin faturası iptal edildi');
        $iptal($s, 'finans', $fis);
        bekle((int) tek($pdo, "SELECT DURUM FROM LM_FINANS_FIS WHERE ID = {$fis}") === 1, 'gizli carinin kasa fişi iptal edildi');
        $unvan = (string) tek($pdo, 'SELECT UNVAN FROM LM_CARI WHERE ID = 8');
        $k = $s->istek('kart/stok.php?id=9')['govde'];
        bekle(!str_contains($k, $unvan) && str_contains($k, '(gizli cari)'), 'stok kartında gizli carinin adı görünüyor');
        $j = json_decode($s->istek('cek/cek_giris.php', ['csrf_token' => $s->belirtec('cek/cek_panel.php'), 'cari_ref' => 8, 'aciklama' => 'x',
            'istek_id' => bin2hex(random_bytes(16)), 'kalemler' => json_encode([['tutar' => 1, 'cekno' => 'G', 'banka' => 'B', 'vade' => date('Y-m-d', strtotime('+9 days')), 'sahibi' => '']])])['govde'], true);
        bekle(($j['ok'] ?? null) === false, 'gizli cariye çek girildi');
        // API çek listesi
        $ch = curl_init("{$URL}/api/login.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['kullanici' => 'SATIS1', 'sifre' => 'demo1234'])]);
        $tok = (string) (json_decode((string) curl_exec($ch), true)['token'] ?? '');
        $ch = curl_init("{$URL}/api/cek_listesi.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}"]]);
        $liste = (string) curl_exec($ch);
        bekle($liste !== '' && !str_contains($liste, (string) tek($pdo, 'SELECT KOD FROM LM_CARI WHERE ID = 8')), 'API çek listesinde gizli carinin çeki var');
        // Yazdırma görünümü (sipariş) gizli cari için kapalı
        $sip = (int) tek($pdo, 'SELECT TOP 1 ID FROM LM_BELGE WHERE TUR = 1 AND CARI_ID = 8');
        if ($sip > 0) {
            bekle($s->istek("yazdir/fis_goruntule.php?tip=siparis&fis={$sip}")['kod'] === 404, 'gizli carinin siparişi yazdırma görünümünde açıldı');
        }
    } finally {
        $kisitla(8, false);
        $iptal($y, 'belge', $f['id']);
        $iptal($y, 'finans', $fis);
    }
});

akis('sipariş bağlantısı: depo personeli siparişi bozamaz; aynı satır tekrarlanamaz; ilgisiz siparişe bağlanılamaz', function () use ($y, $pdo, $URL, $belge, $satir1, $iptal): void {
    $sat = $pdo->query('SELECT TOP 1 S.ID, S.STOK_ID, S.BIRIM_ID, S.MIKTAR, S.TESLIM_MIKTAR, B.ID AS SIP, B.CARI_ID FROM LM_BELGE_SATIR S
                        JOIN LM_BELGE B ON B.ID = S.BELGE_ID WHERE B.TUR = 1 AND B.YON = 1 AND B.DURUM = 1 AND S.KAPALI = 0
                        AND S.MIKTAR - S.TESLIM_MIKTAR >= 2 ORDER BY S.ID')->fetch(PDO::FETCH_ASSOC);
    bekle($sat !== false, 'uygun sipariş satırı yok');
    $teslim = (float) $sat['TESLIM_MIKTAR'];
    $depo = (new Tarayici($URL))->giris('DEPO1');
    $belge($depo, ['tur' => 'sayim_eksik'] + $satir1((int) $sat['STOK_ID'], '1', '0', ['kaynak_satir_id' => [(int) $sat['ID']]]));
    yakin($teslim, (float) tek($pdo, "SELECT TESLIM_MIKTAR FROM LM_BELGE_SATIR WHERE ID = {$sat['ID']}"), 'depo personeli sipariş teslim miktarını değiştirdi');
    $kalan = (float) $sat['MIKTAR'] - $teslim;
    $iki = $belge($y, ['tur' => 'satis_fatura', 'cari_id' => (int) $sat['CARI_ID'], 'kaynak_belge_id' => (int) $sat['SIP'], 'satir' => [
        'stok_id' => [(int) $sat['STOK_ID'], (int) $sat['STOK_ID']], 'birim_id' => [(int) $sat['BIRIM_ID'], (int) $sat['BIRIM_ID']],
        'kaynak_satir_id' => [(int) $sat['ID'], (int) $sat['ID']], 'miktar' => [(string) $kalan, (string) $kalan],
        'fiyat' => ['1', '1'], 'iskonto' => ['0', '0'], 'kdv' => ['20', '20']]]);
    bekle($iki['id'] === 0 && str_contains($iki['hata'], 'kalan'), 'aynı sipariş satırı iki kez faturalandı');
    // İlgisiz belgeyi "kaynak" göstererek iptalini engelleme
    $hedef = $belge($y, ['tur' => 'satis_fatura', 'cari_id' => 3] + $satir1(5, '1'));
    $saldiri = $belge($y, ['tur' => 'alis_irsaliye', 'cari_id' => 9, 'kaynak_belge_id' => $hedef['id']] + $satir1(4, '1'));
    bekle($saldiri['id'] === 0, 'ilgisiz belge kaynak olarak kabul edildi');
    $iptal($y, 'belge', $hedef['id']);
    bekle((int) tek($pdo, "SELECT DURUM FROM LM_BELGE WHERE ID = {$hedef['id']}") === 9, 'fatura iptal edilemedi');
    // Kesilmiş faturaya sipariş ekranından satır eklenemez (LOGO uyumlu yol)
    $once = (int) tek($pdo, "SELECT COUNT(*) FROM LM_BELGE_SATIR WHERE BELGE_ID = {$hedef['id']}");
    $y->istek("siparis/lg_fis.php?stokhareket={$hedef['id']}", ['csrf_token' => $y->belirtec('belge/yeni.php'), 'stkid' => 1, 'stokhareket' => $hedef['id'],
        'kontrol' => uniqid(), 'stkbirim' => 1, 'stkadt' => 1, 'stbrm' => 1, 'stkfyt' => 1, 'stkkdv' => 20, 'aciklama' => '']);
    $pdo->exec("INSERT INTO LMV_ORFLINE (ORDFICHEREF, STOCKREF, AMOUNT, PRICE, LINETYPE) VALUES ({$hedef['id']}, 1, 1, 1, 0)");
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_BELGE_SATIR WHERE BELGE_ID = {$hedef['id']}") === $once, 'faturaya sipariş satırı eklendi');
});

akis('teslim edilmiş sipariş satırı, teslim edilenin altına düşürülemez', function () use ($y, $pdo, $belge): void {
    $sat = $pdo->query('SELECT TOP 1 S.ID, S.STOK_ID, S.BIRIM_ID, S.MIKTAR, B.ID AS SIP, B.CARI_ID FROM LM_BELGE_SATIR S JOIN LM_BELGE B ON B.ID = S.BELGE_ID
                        WHERE B.TUR = 1 AND B.YON = 1 AND B.DURUM = 1 AND B.KUR = 1 AND S.KAPALI = 0 AND S.TESLIM_MIKTAR = 0 AND S.MIKTAR >= 4 ORDER BY S.ID DESC')->fetch(PDO::FETCH_ASSOC);
    bekle($sat !== false, 'uygun sipariş satırı yok');
    $f = $belge($y, ['tur' => 'satis_fatura', 'cari_id' => (int) $sat['CARI_ID'], 'kaynak_belge_id' => (int) $sat['SIP'], 'satir' => [
        'stok_id' => [(int) $sat['STOK_ID']], 'birim_id' => [(int) $sat['BIRIM_ID']], 'kaynak_satir_id' => [(int) $sat['ID']],
        'miktar' => [(string) ((float) $sat['MIKTAR'] / 2)], 'fiyat' => ['1'], 'iskonto' => ['0'], 'kdv' => ['20']]]);
    bekle($f['id'] > 0, 'kısmi fatura kesilmedi: ' . $f['hata']);
    try {
        $g = $y->istek("siparis/lg_fis.php?stokhareket={$sat['SIP']}", ['csrf_token' => $y->belirtec('belge/yeni.php'),
            'stkduzenle' => (int) $sat['ID'], 'stokhareket' => (int) $sat['SIP'], 'stkadt' => '1', 'stkfyt' => '1', 'stkkdv' => '20', 'aciklama' => '']);
        yakin((float) $sat['MIKTAR'], (float) tek($pdo, "SELECT MIKTAR FROM LM_BELGE_SATIR WHERE ID = {$sat['ID']}"), 'sipariş miktarı teslim edilenin altına indi');
        bekle(str_contains($g['govde'], 'teslim edilmiş'), 'kullanıcıya açıklama verilmedi');
        $pdo->exec("UPDATE LMV_ORFLINE SET AMOUNT = 0 WHERE LOGICALREF = {$sat['ID']}");
        bekle(false, 'görünüm üzerinden teslim edilenin altına indirildi');
    } catch (PDOException $e) {
        bekle(str_contains($e->getMessage(), 'teslim edilen'), 'beklenmeyen hata: ' . $e->getMessage());
    } finally {
        $y->istek('belge/islem.php', ['csrf_token' => $y->belirtec('belge/yeni.php'), 'islem' => 'iptal', 'id' => $f['id']]);
    }
});

akis('kur, tarih ve dönem kilidi: tanımlı kurdan sapma, makul olmayan tarih ve kapanmış döneme kayıt reddedilir', function () use ($y, $pdo, $URL, $belge, $satir1, $bugun): void {
    $pdo->exec("DELETE FROM LM_KUR WHERE TARIH = '{$bugun}' AND PARA_BIRIMI = 'USD'");
    $pdo->exec("INSERT INTO LM_KUR (TARIH, PARA_BIRIMI, ALIS, SATIS) VALUES ('{$bugun}', 'USD', 40, 40.2)");
    $s = (new Tarayici($URL))->giris('SATIS1');   // M31, yönetici değil
    $r = $belge($s, ['tur' => 'satis_fatura', 'cari_id' => 3, 'para_birimi' => 'USD', 'kur' => '0,0001'] + $satir1(5, '1'));
    bekle($r['id'] === 0 && str_contains($r['hata'], '%10'), 'tanımlı kurdan çok farklı kur kabul edildi');
    $r = $belge($s, ['tur' => 'satis_fatura', 'cari_id' => 3, 'para_birimi' => 'USD', 'kur' => '41'] + $satir1(5, '1'));
    bekle($r['id'] > 0, 'makul kur reddedildi: ' . $r['hata']);
    $s->istek('belge/islem.php', ['csrf_token' => $s->belirtec('belge/yeni.php'), 'islem' => 'iptal', 'id' => $r['id']]);
    $r = $belge($y, ['tur' => 'satis_fatura', 'cari_id' => 3, 'tarih' => '1990-01-01'] + $satir1(5, '1'));
    bekle($r['id'] === 0, '1990 tarihli fatura kabul edildi');
    $pdo->exec("DELETE FROM LM_AYAR WHERE ANAHTAR = 'donem_kilit_tarihi'");
    $pdo->exec("INSERT INTO LM_AYAR (ANAHTAR, DEGER) VALUES ('donem_kilit_tarihi', '" . date('Y-m-d', strtotime('-1 day')) . "')");
    try {
        $r = $belge($y, ['tur' => 'satis_fatura', 'cari_id' => 3, 'tarih' => date('Y-m-d', strtotime('-2 days'))] + $satir1(5, '1'));
        bekle($r['id'] === 0 && str_contains($r['hata'], 'kilitli'), 'kapanmış döneme fatura kesildi');
        $eski = (int) tek($pdo, "SELECT TOP 1 ID FROM LM_BELGE WHERE TUR = 6 AND DURUM = 1 AND TARIH < '{$bugun}' ORDER BY ID");
        $y->istek('belge/islem.php', ['csrf_token' => $y->belirtec('belge/yeni.php'), 'islem' => 'iptal', 'id' => $eski]);
        bekle((int) tek($pdo, "SELECT DURUM FROM LM_BELGE WHERE ID = {$eski}") === 1, 'kapanmış dönemdeki belge iptal edildi');
        $bugunku = $belge($y, ['tur' => 'satis_fatura', 'cari_id' => 3] + $satir1(5, '1'));
        bekle($bugunku['id'] > 0, 'kilit sonrası tarihe fatura kesilemedi');
        $y->istek('belge/islem.php', ['csrf_token' => $y->belirtec('belge/yeni.php'), 'islem' => 'iptal', 'id' => $bugunku['id']]);
    } finally {
        $pdo->exec("DELETE FROM LM_AYAR WHERE ANAHTAR = 'donem_kilit_tarihi'");
    }
});

akis('depo personeli üretimde fiyat ve cari giremez, fiyat görmez; miktar görme yetkisi olmayan miktar görmez', function () use ($pdo, $URL, $belge, $satir1): void {
    $d = (new Tarayici($URL))->giris('DEPO1');
    $u = $belge($d, ['tur' => 'uretim', 'cari_id' => 5] + $satir1(4, '2', '999999'));
    bekle($u['id'] > 0, 'üretim girilemedi: ' . $u['hata']);
    bekle(tek($pdo, "SELECT CARI_ID FROM LM_BELGE WHERE ID = {$u['id']}") === null, 'depo personeli üretime cari bağladı');
    bekle((float) tek($pdo, "SELECT ISNULL(MAX(BIRIM_MALIYET), 0) FROM LM_STOK_HAREKET WHERE BELGE_ID = {$u['id']}") < 1, 'depo personeli maliyet yazdı');
    $g = $d->istek("belge/goster.php?id={$u['id']}")['govde'];
    bekle(!str_contains($g, 'Tutar (KDV hariç)') && !str_contains($d->istek("belge/yazdir.php?id={$u['id']}")['govde'], 'Birim fiyat'), 'depo personeli fiyat sütunlarını görüyor');
    $d->istek('belge/islem.php', ['csrf_token' => $d->belirtec('belge/yeni.php'), 'islem' => 'iptal', 'id' => $u['id']]);
    $pdo->exec('UPDATE M_P_YETKI SET ST1 = 0 WHERE PERSONEL = 2');
    try {
        $s = (new Tarayici($URL))->giris('SATIS1');
        $j = json_decode($s->istek('belge/stok_ara.php?yon=1&q=DM-0004')['govde'], true);
        bekle(array_key_exists('eldeki', $j[0] ?? []) && $j[0]['eldeki'] === null, 'miktar görme yetkisi olmayan eldeki miktarı gördü');
    } finally {
        $pdo->exec('UPDATE M_P_YETKI SET ST1 = 1 WHERE PERSONEL = 2');
    }
});

akis('Excel: sağda unutulmuş hücreli dosya belleği tüketmez; kütüphaneler ve yedekler tarayıcıdan açılmaz', function () use ($y, $URL): void {
    $x = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sayfa = $x->getActiveSheet();
    $sayfa->fromArray([['Kod', 'Unvan']], null, 'A1');
    for ($i = 2; $i <= 40; $i++) {
        $sayfa->setCellValue("A{$i}", "XFD{$i}")->setCellValue("B{$i}", "Uzak hücre testi {$i}");
    }
    $sayfa->setCellValue('XFD1', 'z');
    $dosya = tempnam(sys_get_temp_dir(), 'xfd') . '.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($dosya);
    $r = $y->istekDosya('tanimlar/ice_aktar.php', ['csrf_token' => $y->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'yukle', 'tur' => 'cari', 'tarih' => date('Y-m-d')],
        'dosya', $dosya, 'uzak.xlsx');
    @unlink($dosya);
    bekle($r['kod'] === 200 && str_contains($r['govde'], 'Önizleme'), "uzak hücreli dosya önizlenemedi (HTTP {$r['kod']})");
    if (preg_match('/name="anahtar" value="([a-f0-9]+)"/', $r['govde'], $m)) {
        $y->istek('tanimlar/ice_aktar.php', ['csrf_token' => $y->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'vazgec', 'anahtar' => $m[1]]);
    }
    foreach (['includes/yedek/yedek_lib.php', 'includes/aktarim/logo_aktar.php', 'database/yedekler/x.lumen.gz', 'yedek.lumen.gz', 'web.config'] as $yol) {
        $ch = curl_init("{$URL}/{$yol}");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => true]);
        curl_exec($ch);
        $kod = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        bekle(in_array($kod, [403, 404], true), "/{$yol} tarayıcıdan açılıyor (HTTP {$kod})");
    }
});

akis('LOGO uyumlu yazma: stokta yalnız KDV güncellemesi uygulanır', function () use ($pdo): void {
    $once = (float) tek($pdo, 'SELECT KDV_ORANI FROM LM_STOK WHERE ID = 2');
    $pdo->exec('UPDATE LMV_ITEMS SET VAT = 1 WHERE LOGICALREF = 2');
    yakin(1.0, (float) tek($pdo, 'SELECT KDV_ORANI FROM LM_STOK WHERE ID = 2'), 'yalnız VAT güncellemesi');
    $pdo->exec("UPDATE LM_STOK SET KDV_ORANI = {$once} WHERE ID = 2");
});

akis('kasa/banka açılış fişi: yönetici keser, diğer kullanıcı kesemez', function () use ($y, $pdo, $URL): void {
    $hesap = (int) tek($pdo, "SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'BNK.01.TL'");
    $alan = static fn(Tarayici $t): array => ['csrf_token' => $t->belirtec('finans/yeni.php'), 'islem' => 'kaydet', 'tur' => 5,
        'hesap_id' => $hesap, 'tutar' => '1234,50', 'istek_id' => bin2hex(random_bytes(16))];
    $once = (int) tek($pdo, 'SELECT COUNT(*) FROM LM_FINANS_FIS WHERE TUR = 5');
    $s = (new Tarayici($URL))->giris('SATIS1');
    bekle(!str_contains($s->istek('finans/yeni.php')['govde'], '>Açılış<'), 'açılış türü yönetici olmayana görünüyor');
    $s->istek('finans/islem.php', $alan($s));
    bekle((int) tek($pdo, 'SELECT COUNT(*) FROM LM_FINANS_FIS WHERE TUR = 5') === $once, 'yönetici olmayan açılış fişi kesti');
    $r = $y->istek('finans/islem.php', $alan($y));
    parse_str((string) parse_url((string) $r['yer'], PHP_URL_QUERY), $q);
    $fis = (int) ($q['id'] ?? 0);
    bekle($fis > 0 && (int) tek($pdo, "SELECT TUR FROM LM_FINANS_FIS WHERE ID = {$fis}") === 5, 'yönetici açılış fişi kesemedi');
    yakin(1234.5, (float) tek($pdo, "SELECT TUTAR FROM LM_FINANS_FIS WHERE ID = {$fis}"), 'açılış tutarı');
    $y->istek('finans/islem.php', ['csrf_token' => $y->belirtec("finans/goster.php?id={$fis}"), 'islem' => 'iptal', 'id' => $fis]);
});

akis('cari finans bilgisi: bakiye/ekstre yetkisi olmayan kullanıcı cari kartı, ekstre ve PDF/Excel açamaz; sipariş özet tutarları fiyat yetkisiz gizli', function () use ($pdo, $URL): void {
    $d = (new Tarayici($URL))->giris('DEPO1');   // M15 + ST1; M4/CR yok
    foreach (['kart/cari.php?id=2', 'cari/lg_hareket.php?cariid=2', 'cari/lg_hareket_ekstre_pdf.php?cariid=2', 'cari/lg_hareket_ekstre_excel.php?cariid=2'] as $yol) {
        bekle($d->istek($yol)['kod'] === 403, "yetkisiz kullanıcı {$yol} açtı");
    }
    $s = (new Tarayici($URL))->giris('SATIS1');  // M4 + CR1/CR2
    bekle($s->istek('cari/lg_hareket.php?cariid=2')['kod'] === 200, 'bakiye yetkili kullanıcı ekstreyi açamadı');
    $sip = (int) tek($pdo, 'SELECT TOP 1 ID FROM LM_BELGE WHERE TUR = 1 AND YON = 1 AND DURUM = 1 AND NET > 0 ORDER BY ID');
    $net = number_format((float) tek($pdo, "SELECT NET FROM LM_BELGE WHERE ID = {$sip}"), 2, ',', '.');
    $g = $d->istek("siparis/lg_fis.php?stokhareket={$sip}");
    bekle($g['kod'] !== 200 || !str_contains($g['govde'], $net), 'fiyat yetkisiz kullanıcı sipariş genel toplamını görüyor');
});

$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d akış, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz === 0 ? 0 : 1);

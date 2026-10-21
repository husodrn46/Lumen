<?php

declare(strict_types=1);

/**
 * Kabul testi — "bir iş günü" (bağımsız mod). Bir firmanın gününü ekranlar üzerinden
 * baştan sona oynar ve aynı rakamın (cari bakiye, stok, kasa) bütün ekranlarda
 * tutarlı olduğunu denetler; sonunda yedekten geri dönüşü dener.
 *
 *   bash demo/kur.sh --bagimsiz
 *   docker compose -f demo/docker-compose.yml exec -T app php tests/kabul/is_gunu.php
 *
 * Tekrar çalıştırılabilir (her çalıştırmada yeni kodlarla cari/ürün açar).
 */

require dirname(__DIR__) . '/akis/_ortak.php';
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (strtolower((string) getenv('LUMEN_VERI_KAYNAGI')) !== 'lumen') {
    fwrite(STDERR, "İş günü senaryosu bağımsız mod içindir (bash demo/kur.sh --bagimsiz).\n");
    exit(3);
}
echo "Kabul testi — iş günü ({$DB})\n";
$pdo->exec('DELETE FROM M_GIRIS_LOG');
$y = (new Tarayici($URL))->giris('YONETICI');
$ek = (string) random_int(10000, 99999);
$bugun = date('Y-m-d');
$para = static fn(float $v): string => number_format($v, 2, ',', '.');
$sorgu = static fn(string $q, array $p = []): string => (string) tek($pdo, $q, $p);
$yonlendirme = static function (array $r): array {
    parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
    return $q;
};
$d = [];   // adımlar arası paylaşılan değerler

akis('sabah: firma bilgileri ve bugünün kuru tanımlanır', function () use ($y, $bugun, $yonlendirme): void {
    $q = $yonlendirme($y->istek('tanimlar/islem.php', ['csrf_token' => $y->belirtec('tanimlar/index.php'), 'islem' => 'firma_kaydet',
        'unvan' => 'Kabul Testi Ticaret A.Ş.', 'vergi_dairesi' => 'Kadıköy', 'vergi_no' => '1234567890',
        'adres' => 'Test Mah. No: 1 İstanbul', 'telefon' => '0 216 000 00 00', 'eposta' => 'kabul@ornek.test']));
    bekle(isset($q['ok']), 'firma bilgileri kaydedilmedi: ' . ($q['hata'] ?? '?'));
    $q = $yonlendirme($y->istek('tanimlar/islem.php', ['csrf_token' => $y->belirtec('tanimlar/index.php'), 'islem' => 'kur_kaydet',
        'tarih' => $bugun, 'pb' => 'USD', 'alis' => '41,0000', 'satis' => '41,2000']));
    bekle(isset($q['ok']), 'kur kaydedilmedi');
});

akis('Excel\'den yeni müşteri, tedarikçi ve ürünler (açılış bakiyesi ve stokla)', function () use ($y, $ek, $bugun, $sorgu, &$d): void {
    $x = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $x->getActiveSheet()->fromArray([
        ['Kod', 'Unvan', 'Tür', 'Vergi dairesi', 'Vergi no / TCKN', 'İl', 'İlçe', 'Adres', 'Telefon', 'E-posta', 'Vade (gün)', 'Açılış bakiyesi'],
        ["KB{$ek}M", 'Kabul Müşteri Ltd.', 'Alıcı', 'Ümraniye', '1111111111', 'İstanbul', '', '', '', '', 30, 1000],
        ["KB{$ek}T", 'Kabul Tedarikçi A.Ş.', 'Satıcı', '', '', 'Bursa', '', '', '', '', '', -2000],
    ], null, 'A1', true);
    $f = tempnam(sys_get_temp_dir(), 'kb') . '.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($f);
    foreach ([['cari', $f, 'cariler.xlsx', '2 yeni cari'], ['stok', null, 'urunler.csv', '2 yeni ürün']] as [$tur, $dosya, $ad, $beklenen]) {
        if ($dosya === null) {
            $dosya = tempnam(sys_get_temp_dir(), 'kb') . '.csv';
            file_put_contents($dosya, "Kod;Ad;Birim;KDV %;Satış fiyatı;Alış fiyatı;Koli içi adet;Açılış miktarı\n"
                . "KU{$ek}A;Kabul Ürün A;ADET;20;50;30;12;100\nKU{$ek}B;Kabul Ürün B;ADET;10;8,5;5;;40\n");
        }
        $g = $y->istekDosya('tanimlar/ice_aktar.php', ['csrf_token' => $y->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'yukle', 'tur' => $tur, 'tarih' => $bugun],
            'dosya', $dosya, $ad)['govde'];
        bekle(preg_match('/name="anahtar" value="([a-f0-9]+)"/', $g, $m) === 1, "{$tur}: önizleme açılmadı");
        $r = $y->istek('tanimlar/ice_aktar.php', ['csrf_token' => $y->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'onayla', 'anahtar' => $m[1]])['govde'];
        bekle(str_contains($r, $beklenen), "{$tur}: aktarılmadı");
        @unlink($dosya);
    }
    $d['m'] = (int) $sorgu("SELECT ID FROM LM_CARI WHERE KOD = 'KB{$ek}M'");
    $d['t'] = (int) $sorgu("SELECT ID FROM LM_CARI WHERE KOD = 'KB{$ek}T'");
    $d['a'] = (int) $sorgu("SELECT ID FROM LM_STOK WHERE KOD = 'KU{$ek}A'");
    $d['b'] = (int) $sorgu("SELECT ID FROM LM_STOK WHERE KOD = 'KU{$ek}B'");
    bekle($d['m'] > 0 && $d['t'] > 0 && $d['a'] > 0 && $d['b'] > 0, 'kartlar oluşmadı');
});

akis('müşteri siparişi: 20 adet A × 50 TL; siparişten 15 adet kısmi fatura', function () use ($y, $sorgu, $bugun, &$d): void {
    $once = (int) $sorgu('SELECT ISNULL(MAX(ID), 0) FROM LM_BELGE WHERE TUR = 1');
    $y->istek("siparis/fisekle.php?cariid={$d['m']}");
    $d['sip'] = (int) $sorgu('SELECT MAX(ID) FROM LM_BELGE WHERE TUR = 1');
    bekle($d['sip'] > $once, 'sipariş açılmadı');
    $t = $y->belirtec("stok/lg_stok_ekle.php?stok={$d['a']}&fisid={$d['sip']}");
    $y->istek("siparis/lg_fis.php?stokhareket={$d['sip']}", ['csrf_token' => $t, 'stkid' => $d['a'], 'stokhareket' => $d['sip'], 'kontrol' => uniqid(),
        'stkbirim' => 1, 'stkadt' => 20, 'stbrm' => 1, 'stkfyt' => 50, 'stkkdv' => 20, 'aciklama' => '']);
    $satir = (int) $sorgu("SELECT ID FROM LM_BELGE_SATIR WHERE BELGE_ID = {$d['sip']} AND STOK_ID = {$d['a']}");
    bekle($satir > 0, 'sipariş satırı eklenmedi');
    $birim = (int) $sorgu("SELECT BIRIM_ID FROM LM_BELGE_SATIR WHERE ID = {$satir}");
    $r = $y->istek('belge/islem.php', ['csrf_token' => $y->belirtec("belge/siparisten.php?siparis={$d['sip']}"), 'islem' => 'kaydet', 'tur' => 'satis_fatura',
        'kaynak_belge_id' => $d['sip'], 'cari_id' => $d['m'], 'tarih' => $bugun, 'satir' => ['stok_id' => [$d['a']], 'birim_id' => [$birim],
        'kaynak_satir_id' => [$satir], 'miktar' => ['15'], 'fiyat' => ['50'], 'iskonto' => ['0'], 'kdv' => ['20']]]);
    parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
    $d['fatura'] = (int) ($q['id'] ?? 0);
    bekle($d['fatura'] > 0, 'siparişten fatura kesilmedi: ' . ($q['hata'] ?? '?'));
    // 15 × 50 = 750 + %20 = 900
    yakin(900.0, (float) $sorgu("SELECT NET FROM LM_BELGE WHERE ID = {$d['fatura']}"), 'fatura tutarı');
    yakin(15.0, (float) $sorgu("SELECT TESLIM_MIKTAR FROM LM_BELGE_SATIR WHERE ID = {$satir}"), 'siparişte teslim edilen');
});

akis('dövizli satış (10 adet B × 2 USD) ve tedarikçiden alış (50 adet A × 30 TL)', function () use ($y, $sorgu, $bugun, &$d): void {
    $kes = static function (array $alan) use ($y): array {
        $r = $y->istek('belge/islem.php', ['csrf_token' => $y->belirtec('belge/yeni.php'), 'islem' => 'kaydet', 'tarih' => date('Y-m-d')] + $alan);
        parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
        return $q;
    };
    $q = $kes(['tur' => 'satis_fatura', 'cari_id' => $d['m'], 'para_birimi' => 'USD', 'kur' => '41',
        'satir' => ['stok_id' => [$d['b']], 'birim_id' => [0], 'miktar' => ['10'], 'fiyat' => ['2'], 'iskonto' => ['0'], 'kdv' => ['10']]]);
    $d['dovizli'] = (int) ($q['id'] ?? 0);
    bekle($d['dovizli'] > 0, 'dövizli fatura kesilmedi: ' . ($q['hata'] ?? '?'));
    // 10 × 82 TL = 820 + %10 = 902 TL; döviz 22 USD
    yakin(902.0, (float) $sorgu("SELECT NET FROM LM_BELGE WHERE ID = {$d['dovizli']}"), 'dövizli fatura TL');
    yakin(22.0, (float) $sorgu("SELECT DOVIZ_NET FROM LM_BELGE WHERE ID = {$d['dovizli']}"), 'dövizli fatura USD');
    $q = $kes(['tur' => 'alis_fatura', 'cari_id' => $d['t'],
        'satir' => ['stok_id' => [$d['a']], 'birim_id' => [0], 'miktar' => ['50'], 'fiyat' => ['30'], 'iskonto' => ['0'], 'kdv' => ['20']]]);
    $d['alis'] = (int) ($q['id'] ?? 0);
    bekle($d['alis'] > 0, 'alış faturası kesilmedi');
    yakin(1800.0, (float) $sorgu("SELECT NET FROM LM_BELGE WHERE ID = {$d['alis']}"), 'alış faturası');   // 1500 + 300
});

akis('kasa: müşteriden 500 TL tahsilat, tedarikçiye 300 TL ödeme, bankaya 200 TL virman; müşteriden 750 TL çek', function () use ($y, $sorgu, &$d): void {
    $d['kasa'] = (int) $sorgu("SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'KASA.01'");
    $d['banka'] = (int) $sorgu("SELECT TOP 1 ID FROM LM_FINANS_HESAP WHERE TUR = 2 AND AKTIF = 1 ORDER BY ID");
    $d['kasa_once'] = (float) $sorgu("SELECT BAKIYE FROM LMV_FINANS_BAKIYE WHERE HESAP_ID = {$d['kasa']}");
    foreach ([[1, 'cari_id', $d['m'], '500'], [2, 'cari_id', $d['t'], '300'], [3, 'karsi_hesap_id', $d['banka'], '200']] as [$tur, $alan, $deger, $tutar]) {
        $r = $y->istek('finans/islem.php', ['csrf_token' => $y->belirtec('finans/yeni.php'), 'islem' => 'kaydet', 'tur' => $tur, 'hesap_id' => $d['kasa'],
            $alan => $deger, 'tutar' => $tutar, 'tarih' => date('Y-m-d'), 'istek_id' => bin2hex(random_bytes(16))]);
        parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
        bekle(isset($q['ok']), "kasa fişi (tür {$tur}) kesilmedi: " . ($q['hata'] ?? '?'));
    }
    $j = json_decode($y->istek('cek/cek_giris.php', ['csrf_token' => $y->belirtec("cek/cek_panel.php?cari={$d['m']}"), 'cari_ref' => $d['m'],
        'aciklama' => 'kabul', 'istek_id' => bin2hex(random_bytes(16)),
        'kalemler' => json_encode([['tutar' => 750, 'cekno' => 'KB' . random_int(1000, 9999), 'banka' => 'Test', 'vade' => date('Y-m-d', strtotime('+45 days')), 'sahibi' => '']])])['govde'], true);
    bekle(($j['ok'] ?? false) === true, 'çek girişi yapılamadı: ' . ($j['mesaj'] ?? '?'));
});

akis('akşam kontrolü: müşteri bakiyesi bütün ekranlarda aynı (veritabanı, bakiye listesi, cari kartı, ekstre, PDF)', function () use ($y, $sorgu, $para, &$d): void {
    // 1000 açılış + 900 fatura + 902 dövizli − 500 tahsilat − 750 çek = 1.552
    $beklenen = 1552.0;
    yakin($beklenen, (float) $sorgu("SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = {$d['m']}"), 'veritabanı bakiyesi');
    $kod = $sorgu("SELECT KOD FROM LM_CARI WHERE ID = {$d['m']}");
    bekle(str_contains($y->istek('cari/lg_bakiye.php?q=' . rawurlencode($kod))['govde'], $para($beklenen)), 'bakiye listesinde farklı');
    bekle(str_contains($y->istek("kart/cari.php?id={$d['m']}")['govde'], $para($beklenen)), 'cari kartında farklı');
    bekle(str_contains($y->istek("cari/lg_hareket.php?cariid={$d['m']}")['govde'], $para($beklenen)), 'ekstrede farklı');
    $pdf = $y->istek("cari/lg_hareket_ekstre_pdf.php?cariid={$d['m']}");
    bekle($pdf['kod'] === 200 && str_starts_with($pdf['govde'], '%PDF'), 'ekstre PDF üretilmedi');
    // Tedarikçi: −2000 açılış − 1800 alış + 300 ödeme = −3.500 (biz borçluyuz)
    yakin(-3500.0, (float) $sorgu("SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = {$d['t']}"), 'tedarikçi bakiyesi');
});

akis('akşam kontrolü: stok ve kasa her ekranda aynı', function () use ($y, $sorgu, $para, &$d): void {
    // A: 100 açılış − 15 satış + 50 alış = 135; B: 40 − 10 = 30
    yakin(135.0, (float) $sorgu("SELECT SUM(MIKTAR) FROM LMV_STOK_MIKTAR WHERE STOK_ID = {$d['a']}"), 'A stoku');
    yakin(30.0, (float) $sorgu("SELECT SUM(MIKTAR) FROM LMV_STOK_MIKTAR WHERE STOK_ID = {$d['b']}"), 'B stoku');
    bekle(preg_match('/id="eldeki"[^>]*>\s*135 /', $y->istek("kart/stok.php?id={$d['a']}")['govde']) === 1, 'stok kartında A 135 değil');
    $ara = json_decode($y->istek('stok/urun_arama_ajax.php', ['barkod' => $sorgu("SELECT KOD FROM LM_STOK WHERE ID = {$d['a']}")])['govde'], true);
    bekle(str_contains((string) ($ara['html'] ?? ''), '135'), 'stok aramasında A 135 değil');
    // Kasa: + 500 − 300 − 200 = değişmez
    $kasa = (float) $sorgu("SELECT BAKIYE FROM LMV_FINANS_BAKIYE WHERE HESAP_ID = {$d['kasa']}");
    yakin($d['kasa_once'], $kasa, 'kasa bakiyesi');
    bekle(str_contains($y->istek('finans/index.php')['govde'], $para($kasa)), 'kasa ekranında farklı');
    bekle(str_contains($y->istek('rapor/rapor_kasa.php')['govde'], $para($kasa)), 'kasa raporunda farklı');
});

akis('günlük rapor bugünün satışlarını ve tahsilatı içerir; fatura çıktısı ve PDF', function () use ($y, $sorgu, $para, &$d): void {
    $toplam = (float) $sorgu("SELECT SUM(NET) FROM LM_BELGE WHERE TUR = 3 AND YON = 1 AND DURUM <> 9 AND TARIH = CAST(GETDATE() AS DATE)");
    $g = $y->istek('rapor/gunluk_islemler.php?tarih=' . date('Y-m-d'))['govde'];
    bekle(str_contains($g, $para($toplam)), "günlük raporda satış toplamı ({$para($toplam)}) yok");
    bekle(str_contains($g, 'Kabul Müşteri'), 'günlük raporda müşteri yok');
    $c = $y->istek("belge/yazdir.php?id={$d['fatura']}")['govde'];
    bekle(str_contains($c, 'Kabul Testi Ticaret') && str_contains($c, 'Dokuzyüz Türk Lirası'), 'fatura çıktısında firma/yazıyla tutar yok');
    $p = $y->istek("belge/yazdir.php?id={$d['dovizli']}&pdf=1");
    bekle($p['kod'] === 200 && str_starts_with($p['govde'], '%PDF'), 'dövizli fatura PDF üretilmedi');
});

akis('yetkiler: satış personeli tanımlara ve yedeklere, depo personeli faturaya ve kasaya giremez', function () use ($URL, &$d): void {
    $satis = (new Tarayici($URL))->giris('SATIS1');
    bekle($satis->istek('tanimlar/index.php')['kod'] === 403, 'satış personeli tanımları açtı');
    bekle(!str_contains($satis->istek('ayar/sistem_yedek.php')['govde'], 'Şimdi yedek al'), 'satış personeli yedek ekranını açtı');
    $depo = (new Tarayici($URL))->giris('DEPO1');
    bekle($depo->istek("belge/goster.php?id={$d['fatura']}")['kod'] === 404, 'depo personeli faturayı gördü');
    bekle($depo->istek('finans/index.php')['kod'] === 403, 'depo personeli kasayı açtı');
});

akis('yedek: al → yanlışlıkla fatura iptal → yedekten geri dön → bakiye ve stok geri gelir', function () use ($y, $sorgu, &$d): void {
    $r = $y->istek('ayar/sistem_yedek.php', ['csrf_token' => $y->belirtec('ayar/sistem_yedek.php'), 'islem' => 'yedek_al'])['govde'];
    bekle(preg_match('/\?indir=(lumen_[0-9_]+_elle_[a-f0-9]{6}\.lumen\.gz)/', $r, $m) === 1, 'yedek alınamadı');
    $bakiye = (float) $sorgu("SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = {$d['m']}");
    $y->istek('belge/islem.php', ['csrf_token' => $y->belirtec("belge/goster.php?id={$d['dovizli']}"), 'islem' => 'iptal', 'id' => $d['dovizli']]);
    yakin($bakiye - 902, (float) $sorgu("SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = {$d['m']}"), 'iptal sonrası bakiye');
    $g = $y->istek('ayar/sistem_yedek.php', ['csrf_token' => $y->belirtec('ayar/sistem_yedek.php'), 'islem' => 'geri_yukle', 'ad' => $m[1], 'onay' => 'GERİ YÜKLE'])['govde'];
    bekle(str_contains($g, 'Yedek geri yüklendi'), 'geri yüklenemedi');
    // Geri yükleme oturumları da eski haline getirir: yeniden giriş
    $y2 = (new Tarayici($GLOBALS['URL']))->giris('YONETICI');
    yakin($bakiye, (float) $sorgu("SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = {$d['m']}"), 'geri yükleme sonrası bakiye');
    yakin(30.0, (float) $sorgu("SELECT SUM(MIKTAR) FROM LMV_STOK_MIKTAR WHERE STOK_ID = {$d['b']}"), 'geri yükleme sonrası B stoku');
    bekle((int) $sorgu("SELECT DURUM FROM LM_BELGE WHERE ID = {$d['dovizli']}") === 1, 'iptal edilen fatura geri gelmedi');
    bekle($y2->istek('index.php')['kod'] === 200, 'geri yüklemeden sonra uygulama açılmıyor');
});

$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d adım, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz === 0 ? 0 : 1);

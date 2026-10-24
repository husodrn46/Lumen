<?php

declare(strict_types=1);

/**
 * Bağımsız mod (LUMEN_VERI_KAYNAGI=lumen) akış testleri — Lumen'in kendi
 * veritabanında (LM_ tabloları) uçtan uca işlemler. Faz faz büyür
 * (docs/mimari/bagimsiz-lumen.md).
 *
 *   bash demo/kur.sh --bagimsiz
 *   docker compose -f demo/docker-compose.yml exec app php tests/akis/bagimsiz.php
 */

require __DIR__ . '/_ortak.php';
require_once dirname(__DIR__, 2) . '/includes/belge/belge_lib.php';

if (strtolower((string) getenv('LUMEN_VERI_KAYNAGI')) !== 'lumen') {
    fwrite(STDERR, "Bu akışlar bağımsız mod içindir (bash demo/kur.sh --bagimsiz).\n");
    exit(3);
}

echo "Bağımsız mod akış testleri ({$DB})\n";
// Akışlar bilerek hatalı giriş dener; önceki çalıştırmaların denemeleri kaba kuvvet
// korumasını (IP/hesap kilidi) tetiklemesin. Yalnız LUMEN_ test veritabanında.
$pdo->exec("DELETE FROM M_GIRIS_LOG");
$yonetici = (new Tarayici($URL))->giris('YONETICI');

akis('giriş: etkin kullanıcı girer; pasif kullanıcı ve yanlış parola giremez', function () use ($URL): void {
    (new Tarayici($URL))->giris('SATIS1');
    foreach ([['ESKI1', 'demo1234'], ['SATIS1', 'yanlis-parola']] as [$k, $p]) {
        try {
            (new Tarayici($URL))->giris($k, $p);
            bekle(false, "{$k} / {$p} ile giriş yapılabildi");
        } catch (RuntimeException $e) {
            bekle(str_starts_with($e->getMessage(), 'giriş başarısız'), $e->getMessage());
        }
    }
});

akis('beni hatırla: oturum düşünce çerezle geri gelir (LM_PERSONEL üzerinden)', function () use ($URL): void {
    $t = new Tarayici($URL);
    $t->istek('giris.php');
    $r = $t->istek('giris.php', ['kullaniciadi' => 'SATIS1', 'kullanicisifre' => 'demo1234', 'hatirla' => 'on']);
    bekle($r['kod'] === 302, 'giriş başarısız');
    $t->cerezleriSuz('/^remember_/');
    $g = $t->istek('index.php');
    bekle($g['kod'] === 200 && !str_contains($g['yer'], 'giris.php'), "oturum kurtarılmadı (HTTP {$g['kod']} {$g['yer']})");
});

akis('kullanıcı yönetimi: ekle → giriş yapar → pasifleştir → giremez → sil', function () use ($yonetici, $URL, $pdo): void {
    $kod = 'TEST' . strtoupper(bin2hex(random_bytes(2)));
    $t = $yonetici->belirtec('ayar/kullanici_yetki.php');
    $yonetici->istek('ayar/kullanici_yetki.php', ['csrf_token' => $t, 'action' => 'save', 'id' => 0, 'kodu' => $kod,
        'adi' => 'Akış Testi', 'sifre' => 'parola-1234', 'tipi' => 1, 'firma' => 1]);
    $id = (int) tek($pdo, "SELECT ID FROM LM_PERSONEL WHERE KOD = :k", [':k' => $kod]);
    bekle($id > 0, 'kullanıcı LM_PERSONEL\'e yazılmadı');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM M_P_YETKI WHERE PERSONEL = :p", [':p' => $id]) === 1, 'yetki satırı yok');
    (new Tarayici($URL))->giris($kod, 'parola-1234');

    $t = $yonetici->belirtec('ayar/kullanici_yetki.php');
    $yonetici->istek('ayar/kullanici_yetki.php', ['csrf_token' => $t, 'action' => 'toggle_status', 'perid' => $id, 'durum' => 'pasif']);
    bekle((int) tek($pdo, "SELECT CAST(AKTIF AS INT) FROM LM_PERSONEL WHERE ID = :i", [':i' => $id]) === 0, 'pasifleştirilmedi');
    try {
        (new Tarayici($URL))->giris($kod, 'parola-1234');
        bekle(false, 'pasif kullanıcı giriş yaptı');
    } catch (RuntimeException) {
    }
    $t = $yonetici->belirtec('ayar/kullanici_yetki.php');
    $yonetici->istek('ayar/kullanici_yetki.php', ['csrf_token' => $t, 'action' => 'delete_user', 'perid' => $id]);
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_PERSONEL WHERE ID = :i", [':i' => $id]) === 0, 'silinmedi');
});

akis('görev ve bildirim: kullanıcı adları LM_PERSONEL\'den gelir', function () use ($yonetici, $URL, $pdo): void {
    $t = $yonetici->belirtec('gorev/gorevler.php');
    $baslik = 'Bağımsız görev ' . bin2hex(random_bytes(3));
    $j = json_decode($yonetici->istek('gorev/gorev_islem.php', ['csrf_token' => $t, 'islem' => 'olustur', 'atanan_id' => 2,
        'baslik' => $baslik, 'oncelik' => 1, 'kategori' => 1])['govde'], true);
    bekle(($j['ok'] ?? $j['success'] ?? false) == true, 'görev oluşturulamadı: ' . json_encode($j, JSON_UNESCAPED_UNICODE));
    // Atayanın "Atadıklarım" listesi: görev + atanan kişinin adı (LMV_SLSMAN üzerinden)
    $g = $yonetici->istek('gorev/gorevler.php?sekme=atadiklarim&f=hepsi')['govde'];
    bekle(str_contains($g, $baslik), 'görev atayanın listesinde yok');
    bekle(str_contains($g, 'Ayşe Demir (demo)'), 'atanan kişinin adı görünmüyor');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM M_BILDIRIM WHERE PERSONEL_ID = 2 AND METIN LIKE :b", [':b' => $baslik . '%']) === 1,
        'atanana bildirim yazılmadı');
    $satis = (new Tarayici($URL))->giris('SATIS1');
    bekle(str_contains($satis->istek('index.php')['govde'], 'h-btn-badge'), 'bildirim rozeti yok');
    // Sayfa ziyaretleri sekmesi kullanıcı kodunu LMV_SLSMAN JOIN'iyle gösterir
    $l = $yonetici->istek('loglar.php?grup=personel&tab=ziyaret')['govde'];
    bekle(str_contains($l, 'YONETICI') && !str_contains($l, 'Sorgu hatası'), 'loglarda kullanıcı kodu yok');
});

akis('geçiş koruması: taşınmamış modül 501 kartı / JSON verir, menüde görünmez', function () use ($yonetici): void {
    $r = $yonetici->istek('stok/index.php');
    bekle($r['kod'] === 501 && str_contains($r['govde'], 'henüz hazır değil'), "üretim modülü korunmuyor (HTTP {$r['kod']})");
    $j = $yonetici->istek('stok/index.php', null, ['X-Requested-With: XMLHttpRequest']);
    bekle($j['kod'] === 501 && (json_decode($j['govde'], true)['ok'] ?? null) === false, 'AJAX isteğine JSON dönmedi');
    bekle(!str_contains($yonetici->istek('index.php')['govde'], 'href="stok/index.php'), 'menüde taşınmamış modül var');
});

// ---------------------------------------------------------------- cari (Faz 1)

akis('cari arama: LM_CARI\'den, Türkçe duyarsız; bakiye listesi ve ekstre aynı bakiyeyi verir', function () use ($yonetici): void {
    $g = $yonetici->istek('cari/cari.php?cari=' . rawurlencode('IC ANADOLU'))['govde'];
    bekle(str_contains($g, 'İç Anadolu Züccaciye'), '"IC ANADOLU" araması "İç Anadolu Züccaciye"yi bulmadı');
    // Anadolu Ev Eşyası (id 5): borç 28.050, alacak 6.500 → bakiye 21.550
    $b = $yonetici->istek('cari/lg_bakiye.php?q=' . rawurlencode('anadolu ev'))['govde'];
    bekle(str_contains($b, 'Anadolu Ev Eşyası') && str_contains($b, '21.550,00'), 'bakiye listesinde beklenen bakiye (21.550,00) yok');
    $e = $yonetici->istek('cari/lg_hareket.php?cariid=5')['govde'];
    bekle(str_contains($e, '21.550,00'), 'ekstrede genel bakiye yok');
    // Üç açılış borcu + bir kasa tahsilatı (gerçek kasa fişi, fişe bağlı)
    bekle(substr_count($e, 'Açılış Fişi') === 3, 'ekstrede 3 açılış hareketi bekleniyordu');
    bekle(substr_count($e, 'finans/goster.php?id=') === 1, 'ekstrede kasa fişine bağlı bir tahsilat bekleniyordu');
    $d = $yonetici->istek('cari/lg_bakiyex.php', ['userid' => 5]);
    bekle($d['kod'] === 200 && str_contains($d['govde'], 'Finansal'), "bakiye detayı açılmadı (HTTP {$d['kod']})");
});

akis('ekstre PDF ve Excel bağımsız modda üretilir', function () use ($yonetici): void {
    foreach (['cari/lg_hareket_ekstre_pdf.php?cariid=5' => '%PDF', 'cari/lg_hareket_ekstre_excel.php?cariid=5' => "PK\x03\x04"] as $yol => $imza) {
        $r = $yonetici->istek($yol);
        bekle($r['kod'] === 200 && str_starts_with($r['govde'], $imza), "{$yol}: dosya üretilmedi (HTTP {$r['kod']}, " . substr($r['govde'], 0, 60) . ')');
    }
});

akis('yeni cari: LM_CARI\'ye önekli kodla yazılır, mükerrer vergi no uyarılır', function () use ($yonetici, $pdo): void {
    $unvan = 'Akış Testi Cari ' . strtoupper(bin2hex(random_bytes(2)));
    $t = $yonetici->belirtec('cari/cariyeni.php');
    $yonetici->istek('cari/cariyeni.php', ['csrf_token' => $t, 'cari_ekle' => 1, 'unvan' => $unvan, 'telefon' => '0 555 000 00 00',
        'sehir' => 'Eskişehir', 'ilce' => 'Odunpazarı', 'vergi_no' => '2000000002']);
    $r = $pdo->prepare("SELECT ID, KOD FROM LM_CARI WHERE UNVAN = :u");
    $r->execute([':u' => $unvan]);
    $c = $r->fetch(PDO::FETCH_ASSOC);
    bekle($c !== false, 'cari LM_CARI\'ye yazılmadı');
    bekle(preg_match('/^[A-Za-z0-9._-]+\d{4}$/', (string) $c['KOD']) === 1, 'kod önek + 4 hane değil: ' . $c['KOD']);
    bekle(str_contains($yonetici->istek('cari/cari.php?cari=' . rawurlencode($unvan))['govde'], $unvan), 'yeni cari aramada yok');
    $ikinci = 'Mükerrer Cari ' . strtoupper(bin2hex(random_bytes(2)));
    $r2 = $yonetici->istek('cari/cariyeni.php', ['csrf_token' => $yonetici->belirtec('cari/cariyeni.php'), 'cari_ekle' => 1,
        'unvan' => $ikinci, 'vergi_no' => '200.000.000-2']);
    bekle(str_contains($r2['govde'], (string) $c['KOD']) && str_contains($r2['govde'], $unvan) && str_contains($r2['govde'], 'mukerrer_onay'),
        'mükerrer vergi no eşleşmesi cari kodu/unvanıyla gösterilmedi');
    bekle((int) tek($pdo, 'SELECT COUNT(*) FROM LM_CARI WHERE UNVAN = N\'' . str_replace("'", "''", $ikinci) . '\'') === 0, 'mükerrer cari onaysız yazıldı');
    $yonetici->istek('cari/cariyeni.php', ['csrf_token' => $yonetici->belirtec('cari/cariyeni.php'), 'cari_ekle' => 1,
        'unvan' => $ikinci, 'vergi_no' => '2000000002', 'mukerrer_onay' => 1]);
    bekle((int) tek($pdo, 'SELECT COUNT(*) FROM LM_CARI WHERE UNVAN = N\'' . str_replace("'", "''", $ikinci) . '\'') === 1, 'bilinçli mükerrer istisnası yazılmadı');
});

akis('özel cari kısıtı: gizlenen cari liste, otomatik tamamlama ve ekstrede görünmez', function () use ($URL, $pdo): void {
    $pdo->exec("DELETE FROM M_OZEL_CARI_KISIT WHERE CARIREF = 5");
    $pdo->exec("INSERT INTO M_OZEL_CARI_KISIT (CARIREF, OLUSTURAN, OLUSTURMA_TARIHI, YETKI_KODU, PERSONEL_ID)
                VALUES (5, 1, GETDATE(), 'M19', 2), (5, 1, GETDATE(), 'M4', 2)");
    try {
        $s = (new Tarayici($URL))->giris('SATIS1');
        bekle(!str_contains($s->istek('cari/cari.php?cari=anadolu')['govde'], 'Anadolu Ev Eşyası'), 'gizli cari listede');
        bekle(str_contains($s->istek('cari/cari.php?cari=anadolu')['govde'], 'İç Anadolu'), 'kısıtsız cari de kayboldu');
        bekle(!str_contains($s->istek('cari/ajax_cari_search.php?term=anadolu')['govde'], 'Anadolu Ev'), 'gizli cari otomatik tamamlamada');
        bekle(!str_contains($s->istek('cari/cari_arama.php?q=anadolu')['govde'], 'Anadolu Ev'), 'gizli cari hızlı aramada');
        $e = $s->istek('cari/lg_hareket.php?cariid=5');
        bekle($e['kod'] !== 200 || !str_contains($e['govde'], '21.550,00'), 'gizli carinin ekstresi açıldı');
        bekle($s->istek('kart/cari.php?id=5')['kod'] === 404, 'gizli carinin kartı açıldı');
        bekle(!str_contains($s->istek('finans/yeni.php?tur=1&cari=5')['govde'], 'Anadolu Ev'), 'gizli cari formda önceden seçildi');
    } finally {
        $pdo->exec("DELETE FROM M_OZEL_CARI_KISIT WHERE CARIREF = 5");
    }
});

// ---------------------------------------------------------------- stok (Faz 2)

akis('stok arama ve miktar: LOGO uyumlu görünümlerden (LMV_ITEMS, LMV_STINVTOT)', function () use ($yonetici): void {
    $j = json_decode($yonetici->istek('stok/ajax_search.php?q=' . rawurlencode('YEMEK TABAĞI 26 CM BEYAZ'))['govde'], true);
    $kodlar = array_column($j['products'] ?? [], 'CODE');
    bekle(in_array('DM-0001', $kodlar, true), 'ürün aramasında DM-0001 yok: ' . json_encode($kodlar));
    $f = $yonetici->istek('fiyat/fiyat_listesi.php?ara=DM-0001')['govde'];
    bekle(str_contains($f, '42,50'), 'fiyat listesinde 42,50 yok');
});

akis('barkod: ekle → LM_BARKOD; sil → kalkar (INSTEAD OF tetikleyicileri)', function () use ($yonetici, $pdo): void {
    $barkod = '8690' . random_int(100000000, 999999999);
    $t = $yonetici->belirtec('barkod/barkod_ekle.php?stok=3');
    $g = $yonetici->istek('barkod/barkod_ekle.php', ['csrf_token' => $t, 'stok' => 3, 'barkod' => $barkod])['govde'];
    bekle(str_contains($g, 'Barkod eklendi'), 'ekleme mesajı yok');
    $id = (int) tek($pdo, "SELECT ID FROM LM_BARKOD WHERE BARKOD = :b AND STOK_ID = 3", [':b' => $barkod]);
    bekle($id > 0, 'barkod LM_BARKOD\'a yazılmadı');
    $t = $yonetici->belirtec('barkod/barkod_ekle.php?stok=3');
    $yonetici->istek('barkod/barkod_ekle.php', ['csrf_token' => $t, 'stok' => 3, 'hareketsil' => $id]);
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_BARKOD WHERE ID = :i", [':i' => $id]) === 0, 'barkod silinmedi');
});

akis('koli ölçüsü ve ürün adı: LM_STOK / LM_STOK_BIRIM\'e yazılır', function () use ($yonetici, $pdo): void {
    $eski = (string) tek($pdo, "SELECT AD FROM LM_STOK WHERE ID = 4");
    $t = $yonetici->belirtec('stok/koli_olcu_toplu.php');
    $j = json_decode($yonetici->istekJson('stok/koli_olcu_kaydet.php', ['csrf_token' => $t, 'id' => 4, 'name' => $eski . ' X',
        'width' => 120, 'length' => 300, 'height' => 90, 'weight' => 2.5])['govde'], true);
    try {
        bekle(($j['success'] ?? false) === true, 'kaydedilmedi: ' . json_encode($j, JSON_UNESCAPED_UNICODE));
        $sb = $pdo->query("SELECT SB.GENISLIK_MM, SB.UZUNLUK_MM, SB.YUKSEKLIK_MM, SB.AGIRLIK_KG FROM LM_STOK_BIRIM SB
                           JOIN LM_STOK S ON S.ID = SB.STOK_ID AND S.ANA_BIRIM_ID = SB.BIRIM_ID WHERE SB.STOK_ID = 4")->fetch(PDO::FETCH_ASSOC);
        bekle((float) $sb['GENISLIK_MM'] == 120.0 && (float) $sb['UZUNLUK_MM'] == 300.0 && (float) $sb['AGIRLIK_KG'] == 2.5,
            'ölçüler yazılmadı: ' . json_encode($sb));
        bekle(tek($pdo, "SELECT AD FROM LM_STOK WHERE ID = 4") === $eski . ' X', 'ürün adı güncellenmedi');
    } finally {
        $pdo->prepare("UPDATE LM_STOK SET AD = :a WHERE ID = 4")->execute([':a' => $eski]);
    }
});

akis('fiyat güncelle: LM_FIYAT (satış) güncellenir', function () use ($yonetici, $pdo): void {
    $id = (int) tek($pdo, "SELECT ID FROM LM_FIYAT WHERE STOK_ID = 2 AND TUR = 1");
    $eski = (float) tek($pdo, "SELECT FIYAT FROM LM_FIYAT WHERE ID = :i", [':i' => $id]);
    $t = $yonetici->belirtec('fiyat/fiyat_guncelle.php?fiyatid=' . $id . '&fiyat=1');
    $yonetici->istek('fiyat/fiyat_guncelle.php', ['csrf_token' => $t, 'yeni_fiyat' => $id, 'stkfyt' => '45,75', 'fgrup' => 0]);
    try {
        yakin(45.75, (float) tek($pdo, "SELECT FIYAT FROM LM_FIYAT WHERE ID = :i", [':i' => $id]), 'yeni fiyat');
    } finally {
        $pdo->prepare("UPDATE LM_FIYAT SET FIYAT = :f WHERE ID = :i")->execute([':f' => $eski, ':i' => $id]);
    }
});

// ---------------------------------------------------------------- belge / fatura (Faz 4)

/** Belge formunu gönderir; sonuçtaki belge kimliğini (yönlendirmeden) döndürür. */
function belge_kes(Tarayici $t, string $kaynakSayfa, array $alanlar): array
{
    $alanlar = ['csrf_token' => $t->belirtec($kaynakSayfa), 'islem' => 'kaydet'] + $alanlar;
    $r = $t->istek('belge/islem.php', $alanlar);
    parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
    return ['id' => (int) ($q['id'] ?? 0), 'ok' => $q['ok'] ?? null, 'hata' => $q['hata'] ?? null];
}

akis('siparişten kısmi satış faturası: stok çıkar, cari borçlanır, sipariş teslim miktarı ve "faturalı" görünümü', function () use ($yonetici, $pdo): void {
    $sip = (int) tek($pdo, "SELECT TOP 1 B.ID FROM LM_BELGE B WHERE B.TUR = 1 AND B.DURUM = 1
        AND EXISTS (SELECT 1 FROM LM_BELGE_SATIR S WHERE S.BELGE_ID = B.ID AND S.TESLIM_MIKTAR = 0) ORDER BY B.ID");
    $satir = $pdo->query("SELECT ID, STOK_ID, BIRIM_ID, MIKTAR, FIYAT, KDV_ORANI, CARPAN FROM LM_BELGE_SATIR WHERE BELGE_ID = {$sip} ORDER BY SIRA")->fetch(PDO::FETCH_ASSOC);
    $cari = (int) tek($pdo, "SELECT CARI_ID FROM LM_BELGE WHERE ID = {$sip}");
    $bakiyeOnce = (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = {$cari}");
    $stokOnce = (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = :s", [':s' => $satir['STOK_ID']]);
    $yarim = (float) $satir['MIKTAR'] / 2;
    // Fazlası reddedilir
    $fazla = belge_kes($yonetici, "belge/siparisten.php?siparis={$sip}", ['tur' => 'satis_fatura', 'kaynak_belge_id' => $sip, 'cari_id' => $cari,
        'tarih' => date('Y-m-d'), 'satir' => ['stok_id' => [$satir['STOK_ID']], 'birim_id' => [$satir['BIRIM_ID']], 'kaynak_satir_id' => [$satir['ID']],
        'miktar' => [(float) $satir['MIKTAR'] + 1], 'fiyat' => [$satir['FIYAT']], 'iskonto' => [0], 'kdv' => [$satir['KDV_ORANI']]]]);
    bekle($fazla['id'] === 0 && str_contains((string) $fazla['hata'], 'kalan'), 'siparişten fazlası faturalandı');

    $r = belge_kes($yonetici, "belge/siparisten.php?siparis={$sip}", ['tur' => 'satis_fatura', 'kaynak_belge_id' => $sip, 'cari_id' => $cari,
        'tarih' => date('Y-m-d'), 'satir' => ['stok_id' => [$satir['STOK_ID']], 'birim_id' => [$satir['BIRIM_ID']], 'kaynak_satir_id' => [$satir['ID']],
        'miktar' => [$yarim], 'fiyat' => [$satir['FIYAT']], 'iskonto' => [0], 'kdv' => [$satir['KDV_ORANI']]]]);
    bekle($r['id'] > 0, 'fatura kesilmedi: ' . ($r['hata'] ?? '?'));
    $h = lumen_belge_satir_hesapla($yarim, (float) $satir['FIYAT'], 0, (float) $satir['KDV_ORANI']);
    $genel = round($h['net'] + $h['kdv'], 2);
    yakin($genel, (float) tek($pdo, "SELECT NET FROM LM_BELGE WHERE ID = :i", [':i' => $r['id']]), 'fatura genel toplam');
    bekle(str_starts_with((string) tek($pdo, "SELECT NO FROM LM_BELGE WHERE ID = :i", [':i' => $r['id']]), 'FTR'), 'fatura numarası seriden değil');
    yakin($bakiyeOnce + $genel, (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = {$cari}"), 'cari bakiye');
    yakin($stokOnce - $yarim * (float) $satir['CARPAN'], (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = :s", [':s' => $satir['STOK_ID']]), 'stok');
    yakin($yarim, (float) tek($pdo, "SELECT TESLIM_MIKTAR FROM LM_BELGE_SATIR WHERE ID = :i", [':i' => $satir['ID']]), 'teslim miktarı');
    // LOGO uyumlu görünüm: sipariş ekranlarının "faturalandı mı" kontrolü
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LMV_STLINE WHERE ORDFICHEREF = :s AND INVOICEREF = :f", [':s' => $sip, ':f' => $r['id']]) === 1, 'LMV_STLINE siparişi bağlamıyor');
    $g = $yonetici->istek("belge/goster.php?id={$r['id']}")['govde'];
    bekle(str_contains($g, number_format($genel, 2, ',', '.')), 'belge ekranında genel toplam yok');

    // İptal: her şey geri
    $t = $yonetici->belirtec("belge/goster.php?id={$r['id']}");
    $yonetici->istek('belge/islem.php', ['csrf_token' => $t, 'islem' => 'iptal', 'id' => $r['id']]);
    bekle((int) tek($pdo, "SELECT DURUM FROM LM_BELGE WHERE ID = :i", [':i' => $r['id']]) === 9, 'iptal edilmedi');
    yakin($bakiyeOnce, (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = {$cari}"), 'iptal sonrası cari bakiye');
    yakin($stokOnce, (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = :s", [':s' => $satir['STOK_ID']]), 'iptal sonrası stok');
    yakin(0, (float) tek($pdo, "SELECT TESLIM_MIKTAR FROM LM_BELGE_SATIR WHERE ID = :i", [':i' => $satir['ID']]), 'iptal sonrası teslim');
});

akis('serbest alış faturası ve üretimden giriş: stok girer, alış cariyi alacaklandırır', function () use ($yonetici, $pdo): void {
    $stokOnce = (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 7");
    $bakiyeOnce = (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 9");
    $koli = (int) tek($pdo, "SELECT ID FROM LM_BIRIM WHERE KOD = 'KOLI'");
    $carpan = (float) tek($pdo, "SELECT CARPAN FROM LM_STOK_BIRIM WHERE STOK_ID = 7 AND BIRIM_ID = :b", [':b' => $koli]);
    $a = belge_kes($yonetici, 'belge/yeni.php?tur=alis_fatura', ['tur' => 'alis_fatura', 'cari_id' => 9, 'tarih' => date('Y-m-d'),
        'satir' => ['stok_id' => [7], 'birim_id' => [$koli], 'miktar' => ['2'], 'fiyat' => ['100,00'], 'iskonto' => ['5'], 'kdv' => ['20']]]);
    bekle($a['id'] > 0, 'alış faturası kesilmedi: ' . ($a['hata'] ?? '?'));
    // 2 koli × 100 = 200; %5 iskonto 10 → net 190; KDV 38 → 228
    yakin(228.0, (float) tek($pdo, "SELECT NET FROM LM_BELGE WHERE ID = :i", [':i' => $a['id']]), 'alış genel toplam');
    yakin($bakiyeOnce - 228.0, (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 9"), 'alış sonrası cari bakiye');
    yakin($stokOnce + 2 * $carpan, (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 7"), 'alış sonrası stok (koli → adet)');
    $u = belge_kes($yonetici, 'belge/yeni.php?tur=uretim', ['tur' => 'uretim', 'tarih' => date('Y-m-d'),
        'satir' => ['stok_id' => [7], 'birim_id' => [0], 'miktar' => ['10'], 'fiyat' => ['0'], 'iskonto' => ['0'], 'kdv' => ['0']]]);
    bekle($u['id'] > 0, 'üretim girişi kesilmedi: ' . ($u['hata'] ?? '?'));
    yakin($stokOnce + 2 * $carpan + 10, (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 7"), 'üretim sonrası stok');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_CARI_HAREKET WHERE BELGE_ID = :i", [':i' => $u['id']]) === 0, 'üretim cari hareketi yazdı');
    bekle(str_contains($yonetici->istek('belge/index.php?bas=' . date('Y-m-01'))['govde'], (string) tek($pdo, "SELECT NO FROM LM_BELGE WHERE ID = :i", [':i' => $a['id']])), 'listede yok');
});

akis('depo personeli (yalnız M15): üretim ve sayım girer; fatura göremez, kesemez, fiyat görmez', function () use ($URL, $pdo): void {
    $depo = (new Tarayici($URL))->giris('DEPO1');
    bekle(str_contains($depo->istek('index.php')['govde'], 'Üretim ve Sayım'), 'menüde Üretim ve Sayım yok');
    $once = (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 4");
    $u = belge_kes($depo, 'belge/yeni.php', ['tur' => 'uretim', 'tarih' => date('Y-m-d'),
        'satir' => ['stok_id' => [4], 'birim_id' => [0], 'miktar' => ['6'], 'fiyat' => ['0'], 'iskonto' => ['0'], 'kdv' => ['0']]]);
    bekle($u['id'] > 0, 'depo personeli üretim giremedi: ' . ($u['hata'] ?? '?'));
    yakin($once + 6, (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 4"), 'üretim sonrası stok');
    $f = $depo->istek('belge/islem.php', ['csrf_token' => $depo->belirtec('belge/yeni.php'), 'islem' => 'kaydet', 'tur' => 'satis_fatura', 'cari_id' => 3,
        'tarih' => date('Y-m-d'), 'satir' => ['stok_id' => [4], 'birim_id' => [0], 'miktar' => ['1'], 'fiyat' => ['10'], 'iskonto' => ['0'], 'kdv' => ['20']]]);
    bekle($f['kod'] === 403, "depo personeli fatura kesebildi (HTTP {$f['kod']})");
    $fatura = (int) tek($pdo, "SELECT TOP 1 ID FROM LM_BELGE WHERE TUR = 3 ORDER BY ID");
    if ($fatura > 0) {
        bekle($depo->istek("belge/goster.php?id={$fatura}")['kod'] === 404, 'depo personeli faturayı açtı');
    }
    $liste = $depo->istek('belge/index.php?bas=2020-01-01')['govde'];
    bekle(!str_contains($liste, 'FTR0'), 'depo personelinin listesinde fatura var');
    $ara = json_decode($depo->istek('belge/stok_ara.php?yon=1&q=DM-0004')['govde'], true);
    bekle(($ara[0]['fiyat'] ?? null) === 0 || ($ara[0]['fiyat'] ?? null) === 0.0, 'depo personeli fiyat görüyor');
    $depo->istek('belge/islem.php', ['csrf_token' => $depo->belirtec("belge/goster.php?id={$u['id']}"), 'islem' => 'iptal', 'id' => $u['id']]);
    yakin($once, (float) tek($pdo, "SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 4"), 'iptal sonrası stok');
});

akis('dövizli fatura: fiyat dövizle girilir, stok/cari/KDV TL; döviz tutarı ve kur saklanır; siparişten kısmi', function () use ($yonetici, $pdo): void {
    $once = (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 6");
    // 5 × 10 USD, KDV %20, kur 41,10 → döviz 60 USD; TL 5 × 411 = 2.055 + 411 KDV = 2.466
    $f = belge_kes($yonetici, 'belge/yeni.php?tur=satis_fatura', ['tur' => 'satis_fatura', 'cari_id' => 6, 'tarih' => date('Y-m-d'),
        'para_birimi' => 'USD', 'kur' => '41,10',
        'satir' => ['stok_id' => [8], 'birim_id' => [0], 'miktar' => ['5'], 'fiyat' => ['10'], 'iskonto' => ['0'], 'kdv' => ['20']]]);
    bekle($f['id'] > 0, 'dövizli fatura kesilmedi: ' . ($f['hata'] ?? '?'));
    $b = $pdo->query("SELECT PARA_BIRIMI, KUR, NET, DOVIZ_NET FROM LM_BELGE WHERE ID = {$f['id']}")->fetch(PDO::FETCH_ASSOC);
    bekle($b['PARA_BIRIMI'] === 'USD', 'para birimi yazılmadı');
    yakin(41.10, (float) $b['KUR'], 'kur');
    yakin(2466.0, (float) $b['NET'], 'TL genel toplam');
    yakin(60.0, (float) $b['DOVIZ_NET'], 'döviz genel toplam');
    yakin(10.0, (float) tek($pdo, "SELECT DOVIZ_FIYAT FROM LM_BELGE_SATIR WHERE BELGE_ID = {$f['id']}"), 'satır döviz fiyatı');
    yakin(60.0, (float) tek($pdo, "SELECT DOVIZ_TUTAR FROM LM_CARI_HAREKET WHERE BELGE_ID = {$f['id']}"), 'cari hareket döviz tutarı');
    yakin($once + 2466.0, (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 6"), 'cari bakiye (TL)');
    $g = $yonetici->istek("belge/goster.php?id={$f['id']}")['govde'];
    bekle(str_contains($g, '60,00 USD') && str_contains($g, '41,1000'), 'belge ekranında döviz toplamı/kur yok');
    $kur = belge_kes($yonetici, 'belge/yeni.php', ['tur' => 'satis_fatura', 'cari_id' => 6, 'tarih' => date('Y-m-d'), 'para_birimi' => 'USD', 'kur' => '0',
        'satir' => ['stok_id' => [8], 'birim_id' => [0], 'miktar' => ['1'], 'fiyat' => ['10'], 'iskonto' => ['0'], 'kdv' => ['20']]]);
    bekle($kur['id'] === 0 && str_contains((string) $kur['hata'], 'kur'), 'kursuz dövizli fatura kabul edildi');

    // Dövizli sipariş (EUR, kur 47) → siparişten kısmi fatura, kur 48: TL yeni kurla, döviz fiyatı siparişten
    $pdo->exec("INSERT INTO LM_BELGE (TUR, YON, NO, TARIH, CARI_ID, DEPO_ID, PARA_BIRIMI, KUR, DURUM)
                VALUES (1, 1, 'DVZTEST" . random_int(1000, 9999) . "', CAST(GETDATE() AS DATE), 6, (SELECT ID FROM LM_DEPO WHERE NO = 0), 'EUR', 47, 1)");
    $sip = (int) tek($pdo, 'SELECT MAX(ID) FROM LM_BELGE WHERE TUR = 1');
    $birim = (int) tek($pdo, 'SELECT ANA_BIRIM_ID FROM LM_STOK WHERE ID = 8');
    $pdo->exec("INSERT INTO LM_BELGE_SATIR (BELGE_ID, SIRA, SATIR_TURU, STOK_ID, BIRIM_ID, CARPAN, MIKTAR, FIYAT, DOVIZ_FIYAT, KDV_ORANI, TUTAR, NET)
                VALUES ({$sip}, 1, 0, 8, {$birim}, 1, 4, 470, 10, 20, 1880, 1880)");
    $satir = (int) tek($pdo, "SELECT ID FROM LM_BELGE_SATIR WHERE BELGE_ID = {$sip}");
    $sp = $yonetici->istek("belge/siparisten.php?siparis={$sip}")['govde'];
    bekle(str_contains($sp, 'name="kur"') && str_contains($sp, '10,00 EUR'), 'siparişten ekranında kur/döviz fiyatı yok');
    $r = belge_kes($yonetici, "belge/siparisten.php?siparis={$sip}", ['tur' => 'satis_fatura', 'kaynak_belge_id' => $sip, 'cari_id' => 6,
        'tarih' => date('Y-m-d'), 'para_birimi' => 'EUR', 'kur' => '48',
        'satir' => ['stok_id' => [8], 'birim_id' => [$birim], 'kaynak_satir_id' => [$satir], 'miktar' => ['2'], 'fiyat' => ['10'], 'iskonto' => [0], 'kdv' => [20]]]);
    bekle($r['id'] > 0, 'dövizli siparişten fatura kesilmedi: ' . ($r['hata'] ?? '?'));
    // 2 × 10 EUR = 20 + %20 = 24 EUR; TL 2 × 480 = 960 + 192 = 1.152
    yakin(24.0, (float) tek($pdo, "SELECT DOVIZ_NET FROM LM_BELGE WHERE ID = {$r['id']}"), 'siparişten döviz toplamı');
    yakin(1152.0, (float) tek($pdo, "SELECT NET FROM LM_BELGE WHERE ID = {$r['id']}"), 'siparişten TL toplamı (fatura kuru)');
    yakin(2.0, (float) tek($pdo, "SELECT TESLIM_MIKTAR FROM LM_BELGE_SATIR WHERE ID = {$satir}"), 'sipariş teslim miktarı');

    foreach ([$r['id'], $f['id']] as $id) {
        $yonetici->istek('belge/islem.php', ['csrf_token' => $yonetici->belirtec("belge/goster.php?id={$id}"), 'islem' => 'iptal', 'id' => $id]);
    }
    $pdo->exec("UPDATE LM_BELGE SET DURUM = 9 WHERE ID = {$sip}"); // test siparişi: iptal (faturalar ona bağlı kalır)
    yakin($once, (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 6"), 'iptal sonrası cari bakiye');
});

akis('fatura çıktısı: yazdırma sayfası ve PDF; KDV dökümü, yazıyla tutar; depo personeli açamaz', function () use ($yonetici, $pdo, $URL): void {
    $f = belge_kes($yonetici, 'belge/yeni.php', ['tur' => 'satis_fatura', 'cari_id' => 2, 'tarih' => date('Y-m-d'),
        'satir' => ['stok_id' => [1, 9], 'birim_id' => [0, 0], 'miktar' => ['24', '6'], 'fiyat' => ['44', '250,50'], 'iskonto' => ['0', '0'], 'kdv' => ['20', '10']]]);
    bekle($f['id'] > 0, 'fatura kesilmedi');
    try {
        $no = (string) tek($pdo, "SELECT NO FROM LM_BELGE WHERE ID = {$f['id']}");
        $g = $yonetici->istek("belge/yazdir.php?id={$f['id']}")['govde'];
        // 1.056 + 1.503 = 2.559; KDV 211,20 + 150,30 = 361,50 → 2.920,50
        bekle(str_contains($g, $no) && str_contains($g, '2.920,50') && str_contains($g, 'İkibindokuzyüzyirmi Türk Lirası Elli Kuruş'), 'yazdırma sayfasında no/toplam/yazıyla yok');
        bekle(str_contains($g, '%10') && str_contains($g, '150,30') && str_contains($g, '211,20'), 'KDV dökümü yok');
        $pdf = $yonetici->istek("belge/yazdir.php?id={$f['id']}&pdf=1");
        bekle($pdf['kod'] === 200 && str_starts_with($pdf['govde'], '%PDF'), 'PDF üretilmedi');
        $depo = (new Tarayici($URL))->giris('DEPO1');
        bekle($depo->istek("belge/yazdir.php?id={$f['id']}")['kod'] === 404, 'depo personeli faturayı yazdırdı');
    } finally {
        $yonetici->istek('belge/islem.php', ['csrf_token' => $yonetici->belirtec("belge/goster.php?id={$f['id']}"), 'islem' => 'iptal', 'id' => $f['id']]);
    }
});

/** Tanım formu gönderir; yönlendirmedeki ok/hata metnini döndürür. */
function tanim(Tarayici $t, array $alanlar): array
{
    $r = $t->istek('tanimlar/islem.php', ['csrf_token' => $t->belirtec('tanimlar/index.php')] + $alanlar);
    parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
    return ['ok' => $q['ok'] ?? null, 'hata' => $q['hata'] ?? null];
}

akis('tanımlar: firma, kasa/banka (IBAN, bakiyeli pasif engeli), depo, birim, kur; yetkisiz giremez', function () use ($yonetici, $pdo, $URL): void {
    $ek = (string) random_int(100, 999);
    bekle(tanim($yonetici, ['islem' => 'firma_kaydet', 'unvan' => 'Lumen Demo Firması', 'vergi_dairesi' => 'Demo', 'vergi_no' => '1234567890',
        'adres' => 'Örnek Mah. Deneme Cad. No: 1 Kadıköy / İstanbul', 'telefon' => '0 216 000 00 00', 'eposta' => 'info@ornek.test'])['ok'] !== null, 'firma kaydedilmedi');
    bekle(tanim($yonetici, ['islem' => 'firma_kaydet', 'unvan' => 'X', 'vergi_no' => '12'])['hata'] !== null, 'geçersiz vergi no kabul edildi');
    bekle(tanim($yonetici, ['islem' => 'firma_kaydet', 'unvan' => 'X', 'vergi_no' => '4829103751'])['hata'] !== null, 'kontrol basamağı tutmayan vergi no kabul edildi');
    bekle(str_contains((string) tanim($yonetici, ['islem' => 'hesap_kaydet', 'tur' => 2, 'kod' => "BNK.T{$ek}", 'ad' => 'Test', 'iban' => 'TR70 0006 1000 0000 1234 5678 90'])['hata'], 'IBAN'), 'geçersiz IBAN kabul edildi');
    bekle(tanim($yonetici, ['islem' => 'hesap_kaydet', 'tur' => 2, 'kod' => "BNK.T{$ek}", 'ad' => 'Test Bankası Hesabı', 'banka_adi' => 'Test', 'iban' => 'TR69 0006 1000 0000 1234 5678 90'])['ok'] !== null, 'banka hesabı eklenmedi');
    bekle((string) tek($pdo, "SELECT IBAN FROM LM_FINANS_HESAP WHERE KOD = 'BNK.T{$ek}'") === 'TR690006100000001234567890', 'IBAN boşluksuz saklanmadı');
    $kasa = (int) tek($pdo, "SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'KASA.01'");
    bekle(str_contains((string) tanim($yonetici, ['islem' => 'hesap_pasif', 'id' => $kasa])['hata'], 'Bakiyesi'), 'bakiyeli kasa pasifleştirildi');
    $yeni = (int) tek($pdo, "SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'BNK.T{$ek}'");
    bekle(tanim($yonetici, ['islem' => 'hesap_pasif', 'id' => $yeni])['ok'] !== null, 'bakiyesiz hesap pasifleştirilemedi');

    $merkez = (int) tek($pdo, 'SELECT ID FROM LM_DEPO WHERE NO = 0');
    bekle(tanim($yonetici, ['islem' => 'depo_pasif', 'id' => $merkez])['hata'] !== null, 'merkez depo pasifleştirildi');
    $no = (int) tek($pdo, 'SELECT MAX(NO) FROM LM_DEPO') + 1;
    bekle(tanim($yonetici, ['islem' => 'depo_kaydet', 'kod' => "SUBE{$ek}", 'ad' => 'Şube deposu', 'no' => $no])['ok'] !== null, 'depo eklenmedi');
    bekle(tanim($yonetici, ['islem' => 'depo_kaydet', 'kod' => "X{$ek}", 'ad' => 'Çakışan', 'no' => $no])['hata'] !== null, 'aynı ambar no kabul edildi');
    bekle(str_contains($yonetici->istek('belge/yeni.php')['govde'], 'Şube deposu'), 'yeni depo belge formunda yok');

    bekle(tanim($yonetici, ['islem' => 'birim_kaydet', 'kod' => "DST{$ek}", 'ad' => 'Deste'])['ok'] !== null, 'birim eklenmedi');
    $birim = (int) tek($pdo, "SELECT ID FROM LM_BIRIM WHERE KOD = 'DST{$ek}'");
    $adet = (int) tek($pdo, "SELECT ID FROM LM_BIRIM WHERE KOD = 'ADET'");
    bekle(tanim($yonetici, ['islem' => 'birim_sil', 'id' => $adet])['hata'] !== null, 'kullanılan birim silindi');
    bekle(tanim($yonetici, ['islem' => 'birim_sil', 'id' => $birim])['ok'] !== null, 'kullanılmayan birim silinemedi');

    $tarih = date('Y-m-d');
    bekle(tanim($yonetici, ['islem' => 'kur_kaydet', 'tarih' => $tarih, 'pb' => 'EUR', 'alis' => '48,1234', 'satis' => '48,3000'])['ok'] !== null, 'kur kaydedilmedi');
    yakin(48.1234, (float) tek($pdo, "SELECT ALIS FROM LM_KUR WHERE TARIH = :t AND PARA_BIRIMI = 'EUR'", [':t' => $tarih]), 'kur');
    bekle(str_contains($yonetici->istek('belge/yeni.php')['govde'], '48.1234'), 'yeni belge kur önerisi güncel değil');

    $satis = (new Tarayici($URL))->giris('SATIS1');
    bekle($satis->istek('tanimlar/index.php')['kod'] === 403, 'M16 olmayan kullanıcı tanımları açtı');
});

akis('Excel\'den aktar: şablon, önizleme ve satır hataları, onay; açılış bakiyesi/stok, fiyat, barkod, koli', function () use ($yonetici, $pdo): void {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
    $sablon = $yonetici->istek('tanimlar/ice_aktar.php?sablon=stok');
    bekle($sablon['kod'] === 200 && str_starts_with($sablon['govde'], 'PK'), 'şablon indirilemedi');
    $ek = (string) random_int(1000, 9999);
    // Cari dosyası: 2 geçerli, 1 hatalı no, 1 mükerrer no, 1 kod tekrarı, 1 mevcut (atlanır)
    $x = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $x->getActiveSheet()->fromArray([
        ['Kod *', 'Unvan *', 'Tür', 'Vergi dairesi', 'Vergi no / TCKN', 'İl', 'İlçe', 'Adres', 'Telefon', 'E-posta', 'Vade (gün)', 'Açılış bakiyesi'],
        ["XL{$ek}A", 'Excel Cari A', 'Alıcı', 'Kadıköy', '1234567890', 'İstanbul', '', '', '', 'a@ornek.test', 30, '1.250,50'],
        ["XL{$ek}B", 'Excel Cari B', 'Satıcı', '', '', 'Ankara', '', '', '', '', '', -400],
        ["XL{$ek}C", 'Hatalı Cari', '', '', '12', '', '', '', '', '', '', ''],
        ["XL{$ek}D", 'Mükerrer Vergi', '', '', '123 456 7890', '', '', '', '', '', '', ''],
        ["XL{$ek}A", 'Tekrar', '', '', '', '', '', '', '', '', '', ''],
        ['120.DM.001', 'Mevcut', '', '', '', '', '', '', '', '', '', ''],
    ], null, 'A1', true);
    $dosya = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($dosya);
    $once = (float) tek($pdo, 'SELECT ISNULL(SUM(BAKIYE), 0) FROM LMV_CARI_BAKIYE');
    $g = $yonetici->istekDosya('tanimlar/ice_aktar.php', ['csrf_token' => $yonetici->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'yukle',
        'tur' => 'cari', 'tarih' => date('Y-m-d')], 'dosya', $dosya, 'cariler.xlsx')['govde'];
    bekle(str_contains($g, 'Önizleme') && str_contains($g, 'Vergi no 10') && str_contains($g, 'dosyada 2. satırda')
        && str_contains($g, "XL{$ek}A") && str_contains($g, 'Excel Cari A'), 'önizleme/mükerrer vergi no hataları görünmüyor');
    preg_match('/name="anahtar" value="([a-f0-9]+)"/', $g, $m);
    bekle(isset($m[1]), 'önizleme anahtarı yok');
    $t = $yonetici->belirtec('tanimlar/ice_aktar.php');
    $r = $yonetici->istek('tanimlar/ice_aktar.php', ['csrf_token' => $t, 'islem' => 'onayla', 'anahtar' => $m[1]])['govde'];
    bekle(str_contains($r, 'Hatalı satırlar var'), 'hatalı satırlar onaysız yazıldı');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_CARI WHERE KOD LIKE 'XL{$ek}%'") === 0, 'hata varken kayıt yazıldı');
    // Yeniden yükle, hatalıları atlayarak aktar
    $g = $yonetici->istekDosya('tanimlar/ice_aktar.php', ['csrf_token' => $yonetici->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'yukle',
        'tur' => 'cari', 'tarih' => date('Y-m-d')], 'dosya', $dosya, 'cariler.xlsx')['govde'];
    preg_match('/name="anahtar" value="([a-f0-9]+)"/', $g, $m);
    $r = $yonetici->istek('tanimlar/ice_aktar.php', ['csrf_token' => $yonetici->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'onayla',
        'anahtar' => $m[1], 'hatalari_atla' => 1])['govde'];
    bekle(str_contains($r, '2 yeni cari eklendi'), 'cariler aktarılmadı: ' . (preg_match('/bildirim[^>]*>.{0,200}/su', $r, $mm) ? strip_tags($mm[0]) : '?'));
    bekle((string) tek($pdo, "SELECT VERGI_NO FROM LM_CARI WHERE KOD = 'XL{$ek}A'") === '1234567890', 'vergi no yazılmadı');
    yakin($once + 1250.50 - 400, (float) tek($pdo, 'SELECT SUM(BAKIYE) FROM LMV_CARI_BAKIYE'), 'açılış bakiyeleri');
    bekle((string) tek($pdo, "SELECT UNVAN FROM LM_CARI WHERE KOD = '120.DM.001'") !== 'Mevcut', 'mevcut cari güncellenmeden değişti');

    // Stok dosyası (CSV, noktalı virgül): yeni birim, koli, fiyat, barkod, açılış miktarı
    $csv = tempnam(sys_get_temp_dir(), 'xs') . '.csv';
    file_put_contents($csv, "Kod;Ad;Birim;KDV %;Grup;Satış fiyatı;Alış fiyatı;Barkod;Koli içi adet;Açılış miktarı\n"
        . "XS{$ek};Excel Ürün;ŞİŞE;10;Test;12,50;7,25;869{$ek}000001;12;36\n");
    $g = $yonetici->istekDosya('tanimlar/ice_aktar.php', ['csrf_token' => $yonetici->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'yukle',
        'tur' => 'stok', 'tarih' => date('Y-m-d')], 'dosya', $csv, 'stok.csv')['govde'];
    preg_match('/name="anahtar" value="([a-f0-9]+)"/', $g, $m);
    $r = $yonetici->istek('tanimlar/ice_aktar.php', ['csrf_token' => $yonetici->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'onayla', 'anahtar' => $m[1] ?? ''])['govde'];
    bekle(str_contains($r, '1 yeni ürün eklendi'), 'ürün aktarılmadı');
    $stok = (int) tek($pdo, "SELECT ID FROM LM_STOK WHERE KOD = 'XS{$ek}'");
    bekle((string) tek($pdo, "SELECT B.KOD FROM LM_STOK S JOIN LM_BIRIM B ON B.ID = S.ANA_BIRIM_ID WHERE S.ID = {$stok}") === 'ŞİŞE', 'yeni ana birim yok');
    yakin(12.0, (float) tek($pdo, "SELECT SB.CARPAN FROM LM_STOK_BIRIM SB JOIN LM_BIRIM B ON B.ID = SB.BIRIM_ID WHERE SB.STOK_ID = {$stok} AND B.KOD = 'KOLI'"), 'koli çarpanı');
    yakin(12.5, (float) tek($pdo, "SELECT FIYAT FROM LM_FIYAT WHERE STOK_ID = {$stok} AND TUR = 1"), 'satış fiyatı');
    yakin(7.25, (float) tek($pdo, "SELECT FIYAT FROM LM_FIYAT WHERE STOK_ID = {$stok} AND TUR = 2"), 'alış fiyatı');
    yakin(36.0, (float) tek($pdo, "SELECT SUM(MIKTAR) FROM LMV_STOK_MIKTAR WHERE STOK_ID = {$stok}"), 'açılış miktarı');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_BARKOD WHERE BARKOD = '869{$ek}000001'") === 1, 'barkod yok');
    @unlink($dosya);
    @unlink($csv);
});

akis('cari ve stok kartı: bakiye, belgeler, tahsilatlar, çekler; stok hareketleri ve yürüyen bakiye; yetki', function () use ($yonetici, $pdo, $URL): void {
    $c = $yonetici->istek('kart/cari.php?id=2')['govde'];
    $bakiye = (float) tek($pdo, 'SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 2');
    bekle(str_contains($c, number_format(abs($bakiye), 2, ',', '.')), 'cari kartında bakiye yok');
    bekle(str_contains($c, (string) tek($pdo, 'SELECT TOP 1 NO FROM LM_BELGE WHERE CARI_ID = 2 ORDER BY TARIH DESC, ID DESC')), 'cari kartında son belge yok');
    bekle(str_contains($yonetici->istek('kart/cari.php?id=2&sekme=fis')['govde'], 'THS'), 'cari kartında tahsilat yok');
    bekle(str_contains($yonetici->istek('kart/cari.php?id=2&sekme=cek')['govde'], 'P00002'), 'cari kartında çek yok');
    bekle(str_contains($c, 'belge/yeni.php?tur=satis_fatura&amp;cari=2') || str_contains($c, 'belge/yeni.php?tur=satis_fatura&cari=2'), 'fatura kes bağlantısı yok');
    bekle(str_contains($yonetici->istek('belge/yeni.php?tur=satis_fatura&cari=2')['govde'], 'name="cari_id" id="cariId" value="2"'), 'fatura formunda cari önceden seçili değil');

    $s = $yonetici->istek('kart/stok.php?id=5')['govde'];
    $eldeki = (float) tek($pdo, 'SELECT SUM(MIKTAR) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 5');
    bekle(preg_match('/id="eldeki"[^>]*>([^<]+)</', $s, $m) === 1 && str_starts_with(trim($m[1]), rtrim(rtrim(number_format($eldeki, 3, ',', '.'), '0'), ',')), 'stok kartında eldeki miktar yanlış');
    bekle(str_contains($s, 'SYM000001') && str_contains($s, 'Açılış') === false || str_contains($s, 'Sayım Fazlası'), 'açılış sayım hareketi görünmüyor');
    // En yeni satırın yürüyen bakiyesi eldeki miktara eşit
    bekle(preg_match_all('/<td class="sag koyu">([0-9.,-]+)<\/td>/', $s, $b) > 0 && str_replace(['.', ','], ['', '.'], $b[1][0]) == $eldeki, 'yürüyen bakiye eldeki miktarla tutmuyor');
    $depo = (new Tarayici($URL))->giris('DEPO1');   // M15 + ST1, M7 yok
    bekle($depo->istek('kart/stok.php?id=5')['kod'] === 403, 'M7 olmayan kullanıcı stok kartını açtı');
    $ara = json_decode($yonetici->istek('stok/urun_arama_ajax.php', ['barkod' => 'DM-0005'])['govde'], true);
    bekle(str_contains((string) ($ara['html'] ?? ''), 'kart/stok.php?id=5'), 'stok aramasında kart bağlantısı yok');
});

akis('ürün kartı: ekle (birim, barkod, fiyat) → düzenle → aramada ve belgede çıkar; yetkisiz giremez', function () use ($yonetici, $pdo, $URL): void {
    $birim = static fn(string $k): int => (int) tek($pdo, "SELECT ID FROM LM_BIRIM WHERE KOD = '{$k}'");
    $kod = 'UK-' . random_int(10000, 99999);
    $gonder = static fn(array $alan, int $id = 0): array => $yonetici->istek('kart/stok_duzenle.php',
        ['csrf_token' => $yonetici->belirtec('kart/stok_duzenle.php' . ($id ? "?id={$id}" : '')), 'id' => $id] + $alan);
    $r = $gonder(['kod' => strtolower($kod), 'ad' => 'Ürün kartı deneme', 'ana_birim_id' => $birim('ADET'), 'kdv' => '10', 'grup' => 'DENEME',
        'birim_id' => [$birim('KOLI'), 0], 'carpan' => ['12', ''], 'barkod' => ["869{$kod}", "869{$kod}K"], 'barkod_birim' => [0, $birim('KOLI')],
        'satis' => '15,50', 'alis' => '9,25']);
    $id = (int) tek($pdo, "SELECT ID FROM LM_STOK WHERE KOD = '{$kod}'");
    bekle($r['kod'] === 303 && $id > 0, 'ürün eklenmedi (kod büyük harfe çevrilmeli)');
    yakin(10.0, (float) tek($pdo, "SELECT KDV_ORANI FROM LM_STOK WHERE ID = {$id}"), 'KDV');
    yakin(12.0, (float) tek($pdo, "SELECT CARPAN FROM LM_STOK_BIRIM WHERE STOK_ID = {$id} AND BIRIM_ID = {$birim('KOLI')}"), 'koli çarpanı');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_BARKOD WHERE STOK_ID = {$id}") === 2, 'barkodlar yazılmadı');
    yakin(15.5, (float) tek($pdo, "SELECT FIYAT FROM LM_FIYAT WHERE STOK_ID = {$id} AND TUR = 1"), 'satış fiyatı');
    yakin(9.25, (float) tek($pdo, "SELECT FIYAT FROM LM_FIYAT WHERE STOK_ID = {$id} AND TUR = 2"), 'alış fiyatı');
    // LOGO uyumlu görünümlerde ve aramalarda görünür
    bekle((string) tek($pdo, "SELECT CODE FROM LMV_ITEMS WHERE LOGICALREF = {$id}") === $kod, 'LMV_ITEMS görünümünde yok');
    $ara = json_decode($yonetici->istek('stok/urun_arama_ajax.php', ['barkod' => "869{$kod}K"])['govde'], true);
    bekle(str_contains((string) ($ara['html'] ?? ''), "kart/stok.php?id={$id}"), 'koli barkoduyla stok aramasında çıkmadı');
    bekle(str_contains($yonetici->istek('belge/stok_ara.php?yon=1&q=' . rawurlencode($kod))['govde'], $kod), 'belge ürün aramasında çıkmadı');
    $koliAra = json_decode($yonetici->istek('belge/stok_ara.php?yon=1&q=' . rawurlencode("869{$kod}K"))['govde'], true);
    bekle(($koliAra[0]['barkod_birim'] ?? null) === $birim('KOLI'), 'koli barkoduyla aramada barkodun birimi (KOLI) gelmedi');
    // Aynı kod ve başka üründeki barkod reddedilir
    $gonder(['kod' => $kod, 'ad' => 'Kopya', 'ana_birim_id' => $birim('ADET')]);
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_STOK WHERE KOD = '{$kod}'") === 1, 'aynı kodla ikinci ürün açıldı');
    $baska = (string) tek($pdo, 'SELECT TOP 1 BARKOD FROM LM_BARKOD WHERE STOK_ID = 1');
    $r = $gonder(['ad' => 'Ürün kartı deneme', 'kdv' => '10', 'barkod' => [$baska], 'aktif' => '1'], $id);
    bekle($r['kod'] === 200 && str_contains($r['govde'], 'ürününde kayıtlı'), 'başka ürünün barkodu kabul edildi');
    // Düzenle: ad, fiyat (bugünden geçerli tek satır), koli kaldır, paket ekle
    $r = $gonder(['ad' => 'Ürün kartı düzenlendi', 'kdv' => '20', 'aktif' => '1', 'birim_id' => [$birim('PAKET')], 'carpan' => ['6'],
        'barkod' => ["869{$kod}"], 'barkod_birim' => [0], 'satis' => '17'], $id);
    bekle($r['kod'] === 303, 'düzenleme kaydedilmedi');
    bekle((string) tek($pdo, "SELECT AD FROM LM_STOK WHERE ID = {$id}") === 'Ürün kartı düzenlendi', 'ad güncellenmedi');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_STOK_BIRIM WHERE STOK_ID = {$id} AND BIRIM_ID = {$birim('KOLI')}") === 0, 'koli kaldırılmadı');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LM_FIYAT WHERE STOK_ID = {$id} AND TUR = 1") === 1, 'aynı gün fiyat değişikliği yeni satır açtı');
    yakin(17.0, (float) tek($pdo, "SELECT FIYAT FROM LM_FIYAT WHERE STOK_ID = {$id} AND TUR = 1"), 'yeni satış fiyatı');
    yakin(9.25, (float) tek($pdo, "SELECT FIYAT FROM LM_FIYAT WHERE STOK_ID = {$id} AND TUR = 2"), 'boş bırakılan alış fiyatı değişmemeli');
    bekle(str_contains($yonetici->istek("kart/stok.php?id={$id}")['govde'], "stok_duzenle.php?id={$id}"), 'stok kartında düzenle bağlantısı yok');
    // Yetki: M11 olmayan kullanıcı ekleyemez ve göremez
    $satis = (new Tarayici($URL))->giris('SATIS1');
    bekle($satis->istek('kart/stok_duzenle.php')['kod'] === 403, 'M11 olmayan kullanıcı ürün kartı ekranını açtı');
    bekle(!str_contains($satis->istek("kart/stok.php?id={$id}")['govde'], 'stok_duzenle.php'), 'M11 olmayana düzenle bağlantısı görünüyor');
});

akis('irsaliyeden fatura: stok yalnız irsaliyede düşer, fatura cariyi borçlandırır; kısmi, fazlası reddedilir; iptal geri alır; LOGO görünümlerinde tek satır', function () use ($yonetici, $pdo): void {
    $stokMiktar = static fn(): float => (float) tek($pdo, 'SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 3');
    $bakiye = static fn(): float => (float) tek($pdo, 'SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 4');
    [$stok0, $bakiye0] = [$stokMiktar(), $bakiye()];
    $irs = belge_kes($yonetici, 'belge/yeni.php', ['tur' => 'satis_irsaliye', 'cari_id' => 4, 'tarih' => date('Y-m-d'),
        'satir' => ['stok_id' => [3], 'birim_id' => [0], 'miktar' => ['10'], 'fiyat' => ['50'], 'iskonto' => ['0'], 'kdv' => ['20']]]);
    bekle($irs['id'] > 0, 'irsaliye kesilmedi: ' . ($irs['hata'] ?? ''));
    yakin($stok0 - 10, $stokMiktar(), 'irsaliye stoğu düşürmeli');
    yakin($bakiye0, $bakiye(), 'irsaliye cariyi etkilememeli');
    $g = $yonetici->istek("belge/goster.php?id={$irs['id']}")['govde'];
    bekle(str_contains($g, "siparisten.php?irsaliye={$irs['id']}"), 'irsaliyede Faturala düğmesi yok');
    $ekran = $yonetici->istek("belge/siparisten.php?irsaliye={$irs['id']}")['govde'];
    bekle(str_contains($ekran, 'value="satis_fatura"') && !str_contains($ekran, 'value="satis_irsaliye"'), 'irsaliyeden yalnız fatura seçilebilmeli');
    $satir = (int) tek($pdo, "SELECT ID FROM LM_BELGE_SATIR WHERE BELGE_ID = {$irs['id']}");
    $fatura = static fn(string $m): array => belge_kes($yonetici, "belge/siparisten.php?irsaliye={$irs['id']}", ['tur' => 'satis_fatura',
        'cari_id' => 4, 'kaynak_belge_id' => $irs['id'], 'tarih' => date('Y-m-d'), 'para_birimi' => 'TRY',
        'satir' => ['stok_id' => [3], 'birim_id' => [0], 'miktar' => [$m], 'fiyat' => ['50'], 'iskonto' => ['0'], 'kdv' => ['20'], 'kaynak_satir_id' => [$satir]]]);
    $f1 = $fatura('4');
    bekle($f1['id'] > 0, 'irsaliyeden fatura kesilmedi: ' . ($f1['hata'] ?? ''));
    yakin($stok0 - 10, $stokMiktar(), 'fatura stoğu ikinci kez düşürmemeli');
    yakin($bakiye0 + 240, $bakiye(), 'fatura cariyi KDV dahil borçlandırmalı (4 × 50 × 1,2)');
    yakin(4.0, (float) tek($pdo, "SELECT TESLIM_MIKTAR FROM LM_BELGE_SATIR WHERE ID = {$satir}"), 'irsaliye satırında faturalanan miktar');
    bekle(fatura_kes_hata($fatura('7')), 'irsaliyede kalandan fazlası faturalanabildi');
    // LOGO uyumlu görünümler: stok satırı yalnız irsaliyeninki, faturayı gösterir; faturanın ayrı stok fişi yok
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LMV_STLINE WHERE INVOICEREF = {$f1['id']}") === 1, 'LMV_STLINE fatura için tek satır vermeli');
    bekle((int) tek($pdo, "SELECT LOGICALREF FROM LMV_STLINE WHERE INVOICEREF = {$f1['id']}") === $satir, 'LMV_STLINE satırı irsaliye satırı olmalı');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LMV_STFICHE WHERE LOGICALREF = {$f1['id']}") === 0, 'faturanın ayrı stok fişi olmamalı');
    bekle((int) tek($pdo, "SELECT INVOICEREF FROM LMV_STFICHE WHERE LOGICALREF = {$irs['id']}") === $f1['id'], 'irsaliye başlığı faturayı göstermeli');
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LMV_INVOICE WHERE LOGICALREF = {$f1['id']}") === 1, 'fatura LMV_INVOICE görünümünde yok');
    // İrsaliye, faturası dururken iptal edilemez; fatura iptali faturalanan miktarı ve cariyi geri alır
    $yonetici->istek('belge/islem.php', ['csrf_token' => $yonetici->belirtec("belge/goster.php?id={$irs['id']}"), 'islem' => 'iptal', 'id' => $irs['id']]);
    bekle((int) tek($pdo, "SELECT DURUM FROM LM_BELGE WHERE ID = {$irs['id']}") === 1, 'faturalı irsaliye iptal edildi');
    $yonetici->istek('belge/islem.php', ['csrf_token' => $yonetici->belirtec("belge/goster.php?id={$f1['id']}"), 'islem' => 'iptal', 'id' => $f1['id']]);
    yakin(0.0, (float) tek($pdo, "SELECT TESLIM_MIKTAR FROM LM_BELGE_SATIR WHERE ID = {$satir}"), 'fatura iptali faturalanan miktarı geri almalı');
    yakin($bakiye0, $bakiye(), 'fatura iptali cariyi geri almalı');
    yakin($stok0 - 10, $stokMiktar(), 'fatura iptali stoğu oynatmamalı');
    bekle((int) tek($pdo, "SELECT BILLED FROM LMV_STLINE WHERE LOGICALREF = {$satir}") === 0, 'iptal faturadan sonra irsaliye satırı faturalı görünüyor');
    $f2 = $fatura('10');
    bekle($f2['id'] > 0, 'iptalden sonra irsaliyenin tamamı faturalanamadı');
    bekle(!str_contains($yonetici->istek("belge/goster.php?id={$irs['id']}")['govde'], "siparisten.php?irsaliye={$irs['id']}"), 'tamamı faturalanan irsaliyede Faturala düğmesi kalmış');
});

/** Belge kesilemediyse (hata mesajıyla döndüyse) true. */
function fatura_kes_hata(array $r): bool
{
    return $r['id'] === 0 && ($r['hata'] ?? '') !== '';
}

akis('toplu fiyat: önizleme değiştirmez; grup ve yüzdeyle yuvarlayarak bugünden geçerli yeni fiyat; eski fiyat kalır; yetkisiz giremez', function () use ($yonetici, $pdo, $URL): void {
    $grup = (string) tek($pdo, "SELECT TOP 1 GRUP FROM LM_STOK WHERE GRUP IS NOT NULL AND AKTIF = 1 GROUP BY GRUP ORDER BY COUNT(*) DESC");
    $fiyatlar = static fn(): array => $pdo->query("SELECT S.ID, (SELECT TOP 1 FIYAT FROM LM_FIYAT F WHERE F.STOK_ID = S.ID AND F.TUR = 1
        AND F.BIRIM_ID = S.ANA_BIRIM_ID AND F.BASLANGIC <= CAST(GETDATE() AS DATE) ORDER BY F.BASLANGIC DESC, F.ID DESC) AS F
        FROM LM_STOK S WHERE S.AKTIF = 1 AND S.GRUP = N'{$grup}'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $once = array_filter($fiyatlar(), static fn($f) => $f !== null);
    $digerOnce = (float) tek($pdo, "SELECT SUM(FIYAT) FROM LM_FIYAT F JOIN LM_STOK S ON S.ID = F.STOK_ID WHERE ISNULL(S.GRUP, '') <> N'{$grup}'");
    $satirOnce = (int) tek($pdo, 'SELECT COUNT(*) FROM LM_FIYAT');
    $alan = ['tur' => '1', 'grup' => $grup, 'kod' => '', 'yontem' => 'yuzde', 'deger' => '10', 'yuvarla' => '0.5', 'tarih' => date('Y-m-d')];
    $on = $yonetici->istek('tanimlar/toplu_fiyat.php', ['csrf_token' => $yonetici->belirtec('tanimlar/toplu_fiyat.php'), 'islem' => 'onizle'] + $alan);
    // Yuvarlamadan sonra fiyatı değişmeyen ürün (ör. 1,00 → 1,10 → 1,00) listeye girmez
    $degisen = count(array_filter($once, static fn($f) => abs(round((float) $f * 1.1 / 0.5) * 0.5 - (float) $f) >= 0.00005));
    bekle(str_contains($on['govde'], $degisen . ' ürün değişecek'), "önizleme ürün sayısı yanlış ({$degisen} bekleniyordu)");
    bekle((int) tek($pdo, 'SELECT COUNT(*) FROM LM_FIYAT') === $satirOnce, 'önizleme fiyat yazdı');
    $r = $yonetici->istek('tanimlar/toplu_fiyat.php', ['csrf_token' => $yonetici->belirtec('tanimlar/toplu_fiyat.php'), 'islem' => 'uygula'] + $alan);
    bekle($r['kod'] === 303, 'toplu fiyat uygulanmadı');
    $sonra = $fiyatlar();
    foreach ($once as $id => $f) {
        yakin(round((float) $f * 1.1 / 0.5) * 0.5, (float) $sonra[$id], "ürün {$id} yeni fiyat (%10, 0,50 yuvarlama)");
    }
    yakin($digerOnce, (float) tek($pdo, "SELECT SUM(FIYAT) FROM LM_FIYAT F JOIN LM_STOK S ON S.ID = F.STOK_ID WHERE ISNULL(S.GRUP, '') <> N'{$grup}'"), 'başka gruptaki fiyatlar değişmemeli');
    bekle((int) tek($pdo, 'SELECT COUNT(*) FROM LM_FIYAT') >= $satirOnce, 'eski fiyat satırları silinmemeli');
    // Aynı gün ikinci uygulama yeni satır açmaz, bugünkü satırı günceller
    $sayi = (int) tek($pdo, 'SELECT COUNT(*) FROM LM_FIYAT');
    $yonetici->istek('tanimlar/toplu_fiyat.php', ['csrf_token' => $yonetici->belirtec('tanimlar/toplu_fiyat.php'), 'islem' => 'uygula'] + ['deger' => '-5', 'yontem' => 'tutar'] + $alan);
    bekle((int) tek($pdo, 'SELECT COUNT(*) FROM LM_FIYAT') === $sayi, 'aynı gün ikinci güncelleme yeni satır açtı');
    $id = array_key_first($once);
    yakin(max(0, round((float) $once[$id] * 1.1 / 0.5) * 0.5 - 5), (float) $fiyatlar()[$id], 'tutar düşüşü');
    // Geçersiz değer ve yetki
    $h = $yonetici->istek('tanimlar/toplu_fiyat.php', ['csrf_token' => $yonetici->belirtec('tanimlar/toplu_fiyat.php'), 'islem' => 'uygula', 'deger' => '-100'] + $alan);
    bekle(str_contains($h['govde'], 'Yüzde -100 ile 1000'), '-%100 kabul edildi');
    $satis = (new Tarayici($URL))->giris('SATIS1');
    bekle($satis->istek('tanimlar/toplu_fiyat.php')['kod'] === 403, 'M16 olmayan kullanıcı toplu fiyat ekranını açtı');
});

akis('alış siparişi: stok/cari etkisiz; kısmi alış irsaliyesi → irsaliyeden fatura → kalan doğrudan fatura; bağlıyken iptal edilemez', function () use ($yonetici, $pdo): void {
    $stokMiktar = static fn(): float => (float) tek($pdo, 'SELECT ISNULL(SUM(MIKTAR), 0) FROM LMV_STOK_MIKTAR WHERE STOK_ID = 7');
    $bakiye = static fn(): float => (float) tek($pdo, 'SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 6');
    [$stok0, $bakiye0] = [$stokMiktar(), $bakiye()];
    $sat = static fn(string $m, int $kaynak = 0): array => ['stok_id' => [7], 'birim_id' => [0], 'miktar' => [$m], 'fiyat' => ['20'],
        'iskonto' => ['0'], 'kdv' => ['20'], 'kaynak_satir_id' => [$kaynak]];
    $sip = belge_kes($yonetici, 'belge/yeni.php?tur=alis_siparis', ['tur' => 'alis_siparis', 'cari_id' => 6, 'tarih' => date('Y-m-d'), 'satir' => $sat('10')]);
    bekle($sip['id'] > 0, 'alış siparişi kesilmedi: ' . ($sip['hata'] ?? ''));
    yakin($stok0, $stokMiktar(), 'sipariş stoğu oynatmamalı');
    yakin($bakiye0, $bakiye(), 'sipariş cariyi oynatmamalı');
    bekle((int) tek($pdo, "SELECT TRCODE FROM LMV_ORFICHE WHERE LOGICALREF = {$sip['id']}") === 2, 'LMV_ORFICHE alış siparişi (TRCODE 2) değil');
    bekle(str_contains($yonetici->istek('belge/index.php')['govde'], (string) tek($pdo, "SELECT NO FROM LM_BELGE WHERE ID = {$sip['id']}")), 'alış siparişi belge listesinde yok');
    bekle(preg_match('/Bekleyen alış siparişi<\/span><b>10/', $yonetici->istek('kart/stok.php?id=7')['govde']) === 1, 'stok kartında bekleyen alış siparişi görünmüyor');
    bekle(str_contains($yonetici->istek("belge/goster.php?id={$sip['id']}")['govde'], "siparisten.php?siparis={$sip['id']}"), 'alış siparişinde Faturala yok');
    $ekran = $yonetici->istek("belge/siparisten.php?siparis={$sip['id']}")['govde'];
    bekle(str_contains($ekran, 'value="alis_irsaliye"') && str_contains($ekran, 'value="alis_fatura"'), 'alış siparişinden alış faturası/irsaliyesi seçilemiyor');
    $satir = (int) tek($pdo, "SELECT ID FROM LM_BELGE_SATIR WHERE BELGE_ID = {$sip['id']}");
    // 6 adet irsaliyeyle gelir
    $irs = belge_kes($yonetici, "belge/siparisten.php?siparis={$sip['id']}", ['tur' => 'alis_irsaliye', 'cari_id' => 6, 'kaynak_belge_id' => $sip['id'],
        'tarih' => date('Y-m-d'), 'para_birimi' => 'TRY', 'satir' => $sat('6', $satir)]);
    bekle($irs['id'] > 0, 'alış irsaliyesi kesilmedi: ' . ($irs['hata'] ?? ''));
    yakin($stok0 + 6, $stokMiktar(), 'alış irsaliyesi stoğu artırmalı');
    yakin(6.0, (float) tek($pdo, "SELECT TESLIM_MIKTAR FROM LM_BELGE_SATIR WHERE ID = {$satir}"), 'siparişte teslim alınan');
    // irsaliyenin faturası: cari alacaklanır, stok değişmez
    $irsSatir = (int) tek($pdo, "SELECT ID FROM LM_BELGE_SATIR WHERE BELGE_ID = {$irs['id']}");
    $f1 = belge_kes($yonetici, "belge/siparisten.php?irsaliye={$irs['id']}", ['tur' => 'alis_fatura', 'cari_id' => 6, 'kaynak_belge_id' => $irs['id'],
        'tarih' => date('Y-m-d'), 'para_birimi' => 'TRY', 'satir' => $sat('6', $irsSatir)]);
    bekle($f1['id'] > 0, 'alış irsaliyesinden fatura kesilmedi: ' . ($f1['hata'] ?? ''));
    yakin($stok0 + 6, $stokMiktar(), 'irsaliyeden alış faturası stoğu tekrar artırmamalı');
    yakin($bakiye0 - 144, $bakiye(), 'alış faturası cariyi alacaklandırmalı (6 × 20 × 1,2)');
    // kalan 4 doğrudan faturayla
    $f2 = belge_kes($yonetici, "belge/siparisten.php?siparis={$sip['id']}", ['tur' => 'alis_fatura', 'cari_id' => 6, 'kaynak_belge_id' => $sip['id'],
        'tarih' => date('Y-m-d'), 'para_birimi' => 'TRY', 'satir' => $sat('4', $satir)]);
    bekle($f2['id'] > 0, 'siparişten kalan alış faturası kesilmedi: ' . ($f2['hata'] ?? ''));
    yakin($stok0 + 10, $stokMiktar(), 'doğrudan alış faturası stoğu artırmalı');
    yakin($bakiye0 - 240, $bakiye(), 'iki alış faturası toplamı');
    bekle(!str_contains($yonetici->istek("belge/goster.php?id={$sip['id']}")['govde'], "siparisten.php?siparis={$sip['id']}"), 'tamamlanan alış siparişinde Faturala kalmış');
    // Bağlı belgesi varken sipariş iptal edilemez; bağsız sipariş iptal edilir
    $iptalEt = static fn(int $id) => $yonetici->istek('belge/islem.php', ['csrf_token' => $yonetici->belirtec("belge/goster.php?id={$id}"), 'islem' => 'iptal', 'id' => $id]);
    $iptalEt($sip['id']);
    bekle((int) tek($pdo, "SELECT DURUM FROM LM_BELGE WHERE ID = {$sip['id']}") === 1, 'belgesi olan alış siparişi iptal edildi');
    $sip2 = belge_kes($yonetici, 'belge/yeni.php?tur=alis_siparis', ['tur' => 'alis_siparis', 'cari_id' => 6, 'tarih' => date('Y-m-d'), 'satir' => $sat('3')]);
    $iptalEt($sip2['id']);
    bekle((int) tek($pdo, "SELECT DURUM FROM LM_BELGE WHERE ID = {$sip2['id']}") === 9, 'bağsız alış siparişi iptal edilemedi');
    // Satış siparişi belge ekranından açılmaz/iptal edilmez (sipariş ekranındadır)
    $satisSip = (int) tek($pdo, 'SELECT TOP 1 ID FROM LM_BELGE WHERE TUR = 1 AND YON = 1 AND DURUM = 1');
    bekle($yonetici->istek("belge/goster.php?id={$satisSip}")['kod'] === 404, 'satış siparişi belge ekranında açıldı');
});

akis('vade takibi: carinin vade günü faturaya uygulanır; tahsilat en erken vadeyi kapatır; gecikmiş/hafta/ay; tedarikçi tarafı; cari kartı', function () use ($yonetici, $pdo): void {
    require_once dirname(__DIR__, 2) . '/includes/rapor/vade_lib.php';
    $kod = 'VT.' . random_int(10000, 99999);
    $pdo->exec("INSERT INTO LM_CARI (KOD, UNVAN, TUR, VADE_GUN) VALUES ('{$kod}', N'Vade deneme {$kod}', 3, 30)");
    $cari = (int) tek($pdo, "SELECT ID FROM LM_CARI WHERE KOD = '{$kod}'");
    $kasa = (int) tek($pdo, "SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'KASA.01'");
    $gun = static fn(int $g): string => date('Y-m-d', strtotime(($g >= 0 ? '+' : '') . $g . ' days'));
    $fatura = static function (string $tur, string $tarih, string $fiyat, string $vade = '') use ($yonetici, &$cari): array {
        return belge_kes($yonetici, 'belge/yeni.php', ['tur' => $tur, 'cari_id' => $cari, 'tarih' => $tarih, 'vade' => $vade, 'satir' => ['stok_id' => [2], 'birim_id' => [0], 'miktar' => ['1'], 'fiyat' => [$fiyat], 'iskonto' => ['0'], 'kdv' => ['0']]]);
    };
    $f1 = $fatura('satis_fatura', $gun(-40), '1000');          // vade = tarih + 30 → 10 gün gecikmiş
    $f2 = $fatura('satis_fatura', $gun(0), '300');             // vade +30 → bu ay
    $f3 = $fatura('satis_fatura', $gun(-2), '200', $gun(5));   // açık vade +5 → bu hafta
    bekle($f1['id'] > 0 && $f2['id'] > 0 && $f3['id'] > 0, 'test faturaları kesilmedi: ' . ($f1['hata'] ?? '') . ($f2['hata'] ?? '') . ($f3['hata'] ?? ''));
    bekle(substr((string) tek($pdo, "SELECT VADE FROM LM_BELGE WHERE ID = {$f1['id']}"), 0, 10) === $gun(-10), 'carinin vade günü faturaya uygulanmadı');
    bekle(substr((string) tek($pdo, "SELECT VADE FROM LM_BELGE WHERE ID = {$f3['id']}"), 0, 10) === $gun(5), 'girilen vade korunmadı');
    $t = fis_kes($yonetici, ['tur' => 1, 'hesap_id' => $kasa, 'cari_id' => $cari, 'tutar' => '400']);
    bekle($t['id'] > 0, 'tahsilat kesilmedi');
    $o = lumen_vade_ozet(lumen_vade_acik_kalemler($pdo, 'tahsil', $cari));
    yakin(600.0, $o['gecikmis'], 'gecikmiş (1000 − 400 tahsilat)');
    yakin(200.0, $o['hafta'], 'bu hafta');
    yakin(300.0, $o['ay'], 'bu ay');
    yakin(1100.0, $o['toplam'], 'toplam açık = bakiye');
    // Rapor ekranı ve cari kartı
    $r = $yonetici->istek('rapor/vade_takibi.php?q=' . rawurlencode(strtolower($kod)))['govde'];
    bekle(str_contains($r, $kod) && str_contains($r, '10 gün gecikti') && str_contains($r, '5 gün kaldı'), 'vade takibi ekranında carinin kalemleri yok');
    bekle(str_contains($yonetici->istek('rapor/dashboard.php')['govde'], 'vade_takibi.php'), 'rapor panelinde vade takibi yok');
    bekle(preg_match('/id="vadeGecmis"[^>]*>([^<]+)</', $yonetici->istek("kart/cari.php?id={$cari}")['govde'], $m) === 1
        && str_contains($m[1], '600,00'), 'cari kartında vadesi geçmiş tutar yanlış');
    // Fazla tahsilat: tahsil tarafı kapanır
    fis_kes($yonetici, ['tur' => 1, 'hesap_id' => $kasa, 'cari_id' => $cari, 'tutar' => '1100']);
    bekle(lumen_vade_acik_kalemler($pdo, 'tahsil', $cari) === [], 'tamamen tahsil edilen caride açık kalem kaldı');
    // Tedarikçi tarafı (ayrı cari): alış faturası ödenecek; kısmi ödeme düşer
    $pdo->exec("INSERT INTO LM_CARI (KOD, UNVAN, TUR, VADE_GUN) VALUES ('{$kod}T', N'Vade tedarikçi {$kod}', 2, 30)");
    $cari = (int) tek($pdo, "SELECT ID FROM LM_CARI WHERE KOD = '{$kod}T'");
    $a1 = $fatura('alis_fatura', $gun(-35), '500');            // vade −5 → gecikmiş
    fis_kes($yonetici, ['tur' => 2, 'hesap_id' => $kasa, 'cari_id' => $cari, 'tutar' => '100']);
    bekle($a1['id'] > 0, 'alış faturası kesilmedi');
    bekle(lumen_vade_acik_kalemler($pdo, 'tahsil', $cari) === [], 'tedarikçide tahsil edilecek kalem çıkmamalı');
    $od = lumen_vade_ozet(lumen_vade_acik_kalemler($pdo, 'odeme', $cari));
    yakin(400.0, $od['gecikmis'], 'ödenecek gecikmiş (500 − 100 ödeme)');
    bekle(str_contains($yonetici->istek('rapor/vade_takibi.php?yon=odeme&q=' . rawurlencode(strtolower($kod)))['govde'], '5 gün gecikti'), 'ödenecekler sekmesinde kalem yok');
    // Yetki: M17 olmayan giremez
    $depo = (new Tarayici($GLOBALS['URL']))->giris('DEPO1');
    bekle($depo->istek('rapor/vade_takibi.php')['kod'] === 403, 'yetkisiz kullanıcı vade takibini açtı');
});

akis('siparişte KDV %0 kalmış satır: Faturala ekranı ürün KDV\'sini önerir ve uyarır; çoklu ekleme bağımsızda ürün KDV\'siyle yazar', function () use ($yonetici, $pdo): void {
    $sip = (int) tek($pdo, "SELECT TOP 1 B.ID FROM LM_BELGE B WHERE B.TUR = 1 AND B.YON = 1 AND B.DURUM = 1
        AND EXISTS (SELECT 1 FROM LM_BELGE_SATIR S WHERE S.BELGE_ID = B.ID AND S.MIKTAR > S.TESLIM_MIKTAR) ORDER BY B.ID DESC");
    $satir = $pdo->query("SELECT TOP 1 S.ID, S.KDV_ORANI, ST.KDV_ORANI AS URUN FROM LM_BELGE_SATIR S JOIN LM_STOK ST ON ST.ID = S.STOK_ID
        WHERE S.BELGE_ID = {$sip} AND S.MIKTAR > S.TESLIM_MIKTAR AND ST.KDV_ORANI > 0 ORDER BY S.SIRA")->fetch(PDO::FETCH_ASSOC);
    bekle($satir !== false, 'uygun sipariş satırı yok');
    $pdo->exec("UPDATE LM_BELGE_SATIR SET KDV_ORANI = 0 WHERE ID = {$satir['ID']}");
    try {
        $g = $yonetici->istek("belge/siparisten.php?siparis={$sip}")['govde'];
        bekle(str_contains($g, 'siparişteki KDV %0'), 'KDV %0 uyarısı yok');
        bekle(preg_match('/class="kdv-onerildi"[^>]*>.*?<option value="' . preg_quote(rtrim(rtrim(number_format((float) $satir['URUN'], 2, '.', ''), '0'), '.'), '/') . '[^"]*" selected/s', $g) === 1,
            'ürün KDV\'si önerilmedi');
    } finally {
        $pdo->exec("UPDATE LM_BELGE_SATIR SET KDV_ORANI = {$satir['KDV_ORANI']} WHERE ID = {$satir['ID']}");
    }
    // Stok aramadan çoklu ekleme (lg_fis.php → hareketeklecoklu.php) satırı ürün KDV'siyle yazar
    $stok = $pdo->query('SELECT TOP 1 ID, ANA_BIRIM_ID, KDV_ORANI FROM LM_STOK WHERE AKTIF = 1 AND KDV_ORANI > 0 ORDER BY ID')->fetch(PDO::FETCH_ASSOC);
    $once = (int) tek($pdo, "SELECT ISNULL(MAX(ID), 0) FROM LM_BELGE_SATIR WHERE BELGE_ID = {$sip}");
    $yonetici->istek("siparis/lg_fis.php?stokhareket={$sip}", ['csrf_token' => $yonetici->belirtec("siparis/lg_fis.php?stokhareket={$sip}"),
        'stokhareket' => $sip, 'cstok_id' => [$stok['ID']], 'cstok_miktari' => ['2'], 'cstok_fiyati' => ['10'], 'cstok_birim' => [$stok['ANA_BIRIM_ID']]]);
    $yeni = $pdo->query("SELECT TOP 1 ID, KDV_ORANI FROM LM_BELGE_SATIR WHERE BELGE_ID = {$sip} AND ID > {$once} ORDER BY ID DESC")->fetch(PDO::FETCH_ASSOC);
    bekle($yeni !== false, 'çoklu ekleme satır yazmadı');
    yakin((float) $stok['KDV_ORANI'], (float) $yeni['KDV_ORANI'], 'çoklu eklenen satırın KDV\'si');
    $pdo->exec("DELETE FROM LMV_ORFLINE WHERE LOGICALREF = {$yeni['ID']}");
});

akis('cari kartı düzenle: adres, vergi, vade, risk; geçersiz vergi no reddedilir; yetkisiz giremez', function () use ($yonetici, $pdo, $URL): void {
    $kod = 'CD.' . random_int(10000, 99999);
    $pdo->exec("INSERT INTO LM_CARI (KOD, UNVAN) VALUES ('{$kod}', N'Düzenleme deneme')");
    $id = (int) tek($pdo, "SELECT ID FROM LM_CARI WHERE KOD = '{$kod}'");
    $gonder = static fn(array $a): array => $yonetici->istek('kart/cari_duzenle.php', ['csrf_token' => $yonetici->belirtec("kart/cari_duzenle.php?id={$id}"), 'id' => $id] + $a);
    $alan = ['unvan' => 'Düzenlendi Ltd.', 'tur' => '1', 'vergi_dairesi' => 'Kadıköy', 'vergi_no' => '4829103755', 'il' => 'İstanbul', 'ilce' => 'Kadıköy',
        'adres' => 'Moda Cd. 1', 'telefon' => '0216 000 00 00', 'eposta' => 'muhasebe@ornek.test', 'vade_gun' => '45', 'risk_limiti' => '50.000', 'aktif' => '1'];
    $r = $gonder($alan);
    bekle($r['kod'] === 303, 'cari kartı kaydedilmedi');
    $c = $pdo->query("SELECT * FROM LM_CARI WHERE ID = {$id}")->fetch(PDO::FETCH_ASSOC);
    bekle($c['UNVAN'] === 'Düzenlendi Ltd.' && (int) $c['TUR'] === 1 && $c['VERGI_NO'] === '4829103755' && $c['TC_NO'] === null && (int) $c['VADE_GUN'] === 45
        && $c['IL'] === 'İstanbul' && $c['EPOSTA'] === 'muhasebe@ornek.test', 'alanlar yazılmadı');
    yakin(50000.0, (float) $c['RISK_LIMITI'], 'risk limiti');
    $h = $gonder(['vergi_no' => '4829103751'] + $alan);
    bekle($h['kod'] === 200 && str_contains($h['govde'], 'geçersiz'), 'kontrol basamağı tutmayan vergi no kabul edildi');
    $pdo->exec("INSERT INTO LM_CARI (KOD, UNVAN, VERGI_NO) VALUES ('MUK.TEST', N'Mükerrer Kaynak', '1234567890')");
    $m = $gonder(['vergi_no' => '123 456 7890'] + $alan);
    bekle($m['kod'] === 200 && str_contains($m['govde'], 'MUK.TEST') && str_contains($m['govde'], 'Mükerrer Kaynak')
        && str_contains($m['govde'], 'mukerrer_onay'), 'düzenlemede mükerrer vergi no uyarısı yok');
    $pasif = ['vergi_no' => '10000000146'] + $alan;
    unset($pasif['aktif']);   // işaretsiz kutu tarayıcıdan hiç gönderilmez
    $gonder($pasif);
    $c = $pdo->query("SELECT VERGI_NO, TC_NO, AKTIF FROM LM_CARI WHERE ID = {$id}")->fetch(PDO::FETCH_ASSOC);
    bekle($c['TC_NO'] === '10000000146' && $c['VERGI_NO'] === null && (int) $c['AKTIF'] === 0, 'TCKN / pasifleştirme yazılmadı');
    bekle(str_contains($yonetici->istek('kart/cari.php?id=2')['govde'], 'cari_duzenle.php?id=2'), 'cari kartında Düzenle yok');
    $depo = (new Tarayici($URL))->giris('DEPO1');
    bekle($depo->istek("kart/cari_duzenle.php?id={$id}")['kod'] === 403, 'yetkisiz kullanıcı cari düzenleme ekranını açtı');
});

// ---------------------------------------------------------------- kasa / banka / çek (Faz 5)

/** Kasa/banka fişi formunu gönderir; sonuçtaki fiş kimliğini (yönlendirmeden) döndürür. */
function fis_kes(Tarayici $t, array $alanlar, ?string $istek = null): array
{
    $alanlar = ['csrf_token' => $t->belirtec('finans/yeni.php'), 'islem' => 'kaydet', 'tarih' => date('Y-m-d'),
                'istek_id' => $istek ?? bin2hex(random_bytes(16))] + $alanlar;
    $r = $t->istek('finans/islem.php', $alanlar);
    parse_str((string) parse_url($r['yer'], PHP_URL_QUERY), $q);
    return ['id' => (int) ($q['id'] ?? 0), 'ok' => $q['ok'] ?? null, 'hata' => $q['hata'] ?? null];
}

akis('kasa/banka: tahsilat kasayı artırır cariyi alacaklandırır; mükerrer gönderim tek fiş; virman; iptal geri alır', function () use ($yonetici, $pdo, $URL): void {
    $kasa = (int) tek($pdo, "SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'KASA.01'");
    $banka = (int) tek($pdo, "SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'BNK.01.TL'");
    $bakiye = static fn(int $h): float => (float) tek($pdo, "SELECT BAKIYE FROM LMV_FINANS_BAKIYE WHERE HESAP_ID = {$h}");
    $cariBakiye = static fn(): float => (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 2");
    $kasaOnce = $bakiye($kasa);
    $bankaOnce = $bakiye($banka);
    $cariOnce = $cariBakiye();

    $istek = bin2hex(random_bytes(16));
    $t = fis_kes($yonetici, ['tur' => 1, 'hesap_id' => $kasa, 'cari_id' => 2, 'tutar' => '1.234,56', 'aciklama' => 'Akış tahsilat'], $istek);
    bekle($t['id'] > 0 && $t['ok'] !== null, 'tahsilat kesilmedi: ' . ($t['hata'] ?? '?'));
    yakin($kasaOnce + 1234.56, $bakiye($kasa), 'tahsilat sonrası kasa');
    yakin($cariOnce - 1234.56, $cariBakiye(), 'tahsilat sonrası cari bakiye');
    $tekrar = fis_kes($yonetici, ['tur' => 1, 'hesap_id' => $kasa, 'cari_id' => 2, 'tutar' => '1.234,56'], $istek);
    bekle($tekrar['id'] === $t['id'] && $tekrar['hata'] !== null, 'mükerrer gönderim yeni fiş açtı');
    yakin($kasaOnce + 1234.56, $bakiye($kasa), 'mükerrer sonrası kasa');
    bekle(str_starts_with((string) tek($pdo, "SELECT NO FROM LM_FINANS_FIS WHERE ID = :i", [':i' => $t['id']]), 'THS'), 'tahsilat numarası seriden değil');
    // Cari ekstresinde tahsilat kasa fişine bağlanır
    bekle(str_contains($yonetici->istek('cari/lg_hareket.php?cariid=2')['govde'], 'finans/goster.php?id=' . $t['id']), 'ekstre fişe bağlanmıyor');

    $v = fis_kes($yonetici, ['tur' => 3, 'hesap_id' => $kasa, 'karsi_hesap_id' => $banka, 'tutar' => '1000']);
    bekle($v['id'] > 0, 'virman kesilmedi: ' . ($v['hata'] ?? '?'));
    yakin($kasaOnce + 234.56, $bakiye($kasa), 'virman sonrası kasa');
    yakin($bankaOnce + 1000, $bakiye($banka), 'virman sonrası banka');
    $ayni = fis_kes($yonetici, ['tur' => 3, 'hesap_id' => $kasa, 'karsi_hesap_id' => $kasa, 'tutar' => '5']);
    bekle($ayni['id'] === 0 && $ayni['hata'] !== null, 'aynı hesaba virman kabul edildi');
    $sifir = fis_kes($yonetici, ['tur' => 4, 'hesap_id' => $kasa, 'tutar' => '0']);
    bekle($sifir['id'] === 0, 'sıfır tutarlı masraf kabul edildi');

    foreach ([$t['id'], $v['id']] as $id) {
        $b = $yonetici->belirtec("finans/goster.php?id={$id}");
        $yonetici->istek('finans/islem.php', ['csrf_token' => $b, 'islem' => 'iptal', 'id' => $id]);
        bekle((int) tek($pdo, "SELECT DURUM FROM LM_FINANS_FIS WHERE ID = :i", [':i' => $id]) === 9, "fiş {$id} iptal edilmedi");
    }
    yakin($kasaOnce, $bakiye($kasa), 'iptal sonrası kasa');
    yakin($bankaOnce, $bakiye($banka), 'iptal sonrası banka');
    yakin($cariOnce, $cariBakiye(), 'iptal sonrası cari');

    // Yetki: M32 olmayan kullanıcı kasa ekranına giremez, menüde görmez
    $depo = (new Tarayici($URL))->giris('DEPO1');
    bekle($depo->istek('finans/index.php')['kod'] === 403, 'yetkisiz kullanıcı kasa ekranını açtı');
    bekle(!str_contains($depo->istek('index.php')['govde'], 'href="finans/'), 'yetkisiz kullanıcı menüde kasa görüyor');
    bekle(str_contains($yonetici->istek('index.php')['govde'], 'href="finans/index.php'), 'yönetici menüsünde kasa yok');
});

akis('çek: portföy LM_CEK\'ten; ciro hedef cariyi borçlandırır, çeki portföyden çıkarır', function () use ($yonetici, $pdo): void {
    // Akış aynı veritabanında tekrar koşunca portföy cirolarla tükenebilir: gerekirse bir test çeki ekle.
    if ((int) tek($pdo, 'SELECT COUNT(*) FROM LM_CEK WHERE TUR = 1 AND DURUM = 1') === 0) {
        $pdo->exec("INSERT INTO LM_CEK (TUR, PORTFOY_NO, SERI_NO, BANKA, BORCLU, VADE, TUTAR, DURUM, ALINAN_CARI_ID)
                    VALUES (1, 'T" . random_int(100000, 999999) . "', 'CKTEST', N'Demo Bankası', N'Test', DATEADD(DAY, 30, GETDATE()), 1500, 1, 1)");
    }
    bekle((int) tek($pdo, "SELECT COUNT(*) FROM LMV_CSCARD WHERE CURRSTAT = 1") >= 1, 'portföy LOGO uyumlu görünümde boş');
    $cek = $pdo->query("SELECT TOP 1 ID, TUTAR FROM LM_CEK WHERE TUR = 1 AND DURUM = 1 ORDER BY ID DESC")->fetch(PDO::FETCH_ASSOC);
    bekle($cek !== false, 'portföyde çek yok');
    $once = (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 10");
    require_once dirname(__DIR__, 2) . '/cek/cek_lib.php';
    $r = cek_ciro_core($pdo, 'dbo.LMV_', 'dbo.LMV_', 10, [(int) $cek['ID']], 'Akış cirosu', 1, bin2hex(random_bytes(16)));
    bekle(($r['ok'] ?? false) === true, 'ciro başarısız: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
    bekle((int) tek($pdo, "SELECT DURUM FROM LM_CEK WHERE ID = :i", [':i' => $cek['ID']]) === 2, 'çek ciro durumuna geçmedi');
    yakin($once + (float) $cek['TUTAR'], (float) tek($pdo, "SELECT BAKIYE FROM LMV_CARI_BAKIYE WHERE CARI_ID = 10"), 'ciro sonrası hedef cari');
});

// ---------------------------------------------------------------- raporlar (Faz 6)

akis('raporlar: bugünkü fatura ve tahsilat günlük özet, aylık satış, ürün ligi ve API\'de görünür', function () use ($yonetici, $pdo, $URL): void {
    $gun = (string) tek($pdo, "SELECT ISNULL(SUM(NET), 0) FROM LM_BELGE WHERE TUR = 3 AND YON = 1 AND DURUM <> 9 AND TARIH = CAST(GETDATE() AS DATE)");
    $f = belge_kes($yonetici, 'belge/yeni.php?tur=satis_fatura', ['tur' => 'satis_fatura', 'cari_id' => 3, 'tarih' => date('Y-m-d'),
        'satir' => ['stok_id' => [5], 'birim_id' => [0], 'miktar' => ['10'], 'fiyat' => ['100'], 'iskonto' => ['0'], 'kdv' => ['20']]]);
    bekle($f['id'] > 0, 'fatura kesilmedi: ' . ($f['hata'] ?? '?'));
    $kasa = (int) tek($pdo, "SELECT ID FROM LM_FINANS_HESAP WHERE KOD = 'KASA.01'");
    $t = fis_kes($yonetici, ['tur' => 1, 'hesap_id' => $kasa, 'cari_id' => 3, 'tutar' => '700', 'aciklama' => 'Rapor akışı']);
    bekle($t['id'] > 0, 'tahsilat kesilmedi');
    try {
        $beklenen = number_format((float) $gun + 1200, 2, ',', '.');
        $g = $yonetici->istek('rapor/gunluk_islemler.php?tarih=' . date('Y-m-d'))['govde'];
        bekle(str_contains($g, $beklenen . ' ₺') || str_contains($g, $beklenen . ' &#8378;') || str_contains($g, $beklenen), "günlük özet satışı ({$beklenen}) yok");
        bekle(str_contains($g, 'Rapor akışı') && str_contains($g, '700,00'), 'günlük özette kasa tahsilatı yok');
        bekle(str_contains($yonetici->istek('rapor/rapor_urun_top20.php')['govde'], 'DM-0005'), 'ürün liginde satılan ürün yok');
        // API (belirteçle): aylık satış bu ayı içerir, fatura listesi türü doğru
        $ch = curl_init("{$URL}/api/login.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['kullanici' => 'YONETICI', 'sifre' => 'demo1234'])]);
        $tok = (string) (json_decode((string) curl_exec($ch), true)['token'] ?? '');
        $api = static function (string $uc) use ($URL, $tok): array {
            $ch = curl_init("{$URL}/api/{$uc}");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$tok}"]]);
            return json_decode((string) curl_exec($ch), true) ?: [];
        };
        $ay = array_values(array_filter($api('rapor_satis_aylik.php')['aylar'] ?? [], static fn($a) => (int) $a['ay'] === (int) date('n')))[0] ?? [];
        bekle((float) ($ay['net'] ?? 0) >= 1200, 'API aylık satış bu ayın faturasını içermiyor');
        $fl = array_values(array_filter($api('fatura_listesi.php')['faturalar'] ?? [], static fn($x) => (int) $x['id'] === $f['id']))[0] ?? [];
        bekle(($fl['tur'] ?? '') === 'Toptan Satış', 'API fatura türü yanlış: ' . ($fl['tur'] ?? '?'));
    } finally {
        foreach ([['belge', $f['id']], ['finans', $t['id']]] as [$m, $id]) {
            $yonetici->istek("{$m}/islem.php", ['csrf_token' => $yonetici->belirtec("{$m}/goster.php?id={$id}"), 'islem' => 'iptal', 'id' => $id]);
        }
    }
});

$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d akış, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz === 0 ? 0 : 1);

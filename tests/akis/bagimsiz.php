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

akis('yeni cari: LM_CARI\'ye önekli kodla yazılır ve aramada çıkar', function () use ($yonetici, $pdo): void {
    $unvan = 'Akış Testi Cari ' . strtoupper(bin2hex(random_bytes(2)));
    $t = $yonetici->belirtec('cari/cariyeni.php');
    $yonetici->istek('cari/cariyeni.php', ['csrf_token' => $t, 'cari_ekle' => 1, 'unvan' => $unvan, 'telefon' => '0 555 000 00 00',
        'sehir' => 'Eskişehir', 'ilce' => 'Odunpazarı']);
    $r = $pdo->prepare("SELECT ID, KOD FROM LM_CARI WHERE UNVAN = :u");
    $r->execute([':u' => $unvan]);
    $c = $r->fetch(PDO::FETCH_ASSOC);
    bekle($c !== false, 'cari LM_CARI\'ye yazılmadı');
    bekle(preg_match('/^[A-Za-z0-9._-]+\d{4}$/', (string) $c['KOD']) === 1, 'kod önek + 4 hane değil: ' . $c['KOD']);
    bekle(str_contains($yonetici->istek('cari/cari.php?cari=' . rawurlencode($unvan))['govde'], $unvan), 'yeni cari aramada yok');
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
    // Cari dosyası: 2 geçerli, 1 hatalı (vergi no), 1 dosya içi tekrar, 1 mevcut (atlanır)
    $x = new PhpOffice\PhpSpreadsheet\Spreadsheet();
    $x->getActiveSheet()->fromArray([
        ['Kod *', 'Unvan *', 'Tür', 'Vergi dairesi', 'Vergi no / TCKN', 'İl', 'İlçe', 'Adres', 'Telefon', 'E-posta', 'Vade (gün)', 'Açılış bakiyesi'],
        ["XL{$ek}A", 'Excel Cari A', 'Alıcı', 'Kadıköy', '1234567890', 'İstanbul', '', '', '', 'a@ornek.test', 30, '1.250,50'],
        ["XL{$ek}B", 'Excel Cari B', 'Satıcı', '', '', 'Ankara', '', '', '', '', '', -400],
        ["XL{$ek}C", 'Hatalı Cari', '', '', '12', '', '', '', '', '', '', ''],
        ["XL{$ek}A", 'Tekrar', '', '', '', '', '', '', '', '', '', ''],
        ['120.DM.001', 'Mevcut', '', '', '', '', '', '', '', '', '', ''],
    ], null, 'A1', true);
    $dosya = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
    (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($dosya);
    $once = (float) tek($pdo, 'SELECT ISNULL(SUM(BAKIYE), 0) FROM LMV_CARI_BAKIYE');
    $g = $yonetici->istekDosya('tanimlar/ice_aktar.php', ['csrf_token' => $yonetici->belirtec('tanimlar/ice_aktar.php'), 'islem' => 'yukle',
        'tur' => 'cari', 'tarih' => date('Y-m-d')], 'dosya', $dosya, 'cariler.xlsx')['govde'];
    bekle(str_contains($g, 'Önizleme') && str_contains($g, 'Vergi no 10') && str_contains($g, 'dosyada 2. satırda'), 'önizleme/satır hataları görünmüyor');
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
    bekle(str_contains($yonetici->istek('cek/cek_panel.php')['govde'], 'P00001') || (int) tek($pdo, "SELECT COUNT(*) FROM LMV_CSCARD WHERE CURRSTAT = 1") >= 1,
        'portföy boş görünüyor');
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

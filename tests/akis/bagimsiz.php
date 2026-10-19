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
    $r = $yonetici->istek('cek/cek_panel.php');
    bekle($r['kod'] === 501 && str_contains($r['govde'], 'henüz hazır değil'), "çek modülü korunmuyor (HTTP {$r['kod']})");
    $j = $yonetici->istek('cek/cek_panel.php', null, ['X-Requested-With: XMLHttpRequest']);
    bekle($j['kod'] === 501 && (json_decode($j['govde'], true)['ok'] ?? null) === false, 'AJAX isteğine JSON dönmedi');
    bekle(!str_contains($yonetici->istek('index.php')['govde'], 'href="cek/'), 'menüde taşınmamış modül var');
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
    bekle(substr_count($e, 'Açılış Fişi') === 4, 'ekstrede 4 açılış hareketi bekleniyordu');
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

$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d akış, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz === 0 ? 0 : 1);

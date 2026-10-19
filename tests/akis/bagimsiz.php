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

$basarisiz = count(array_filter($sonuclar, static fn($r) => !$r[0]));
printf("%d akış, %d başarısız\n", count($sonuclar), $basarisiz);
exit($basarisiz === 0 ? 0 : 1);

<?php

declare(strict_types=1);

/**
 * Uygulama içi yedekleme — ek yazılım (SSMS, sqlcmd) gerektirmez.
 *
 * Kapsam: bağımsız modda veritabanının tamamı (LM_* iş verisi + M_* Lumen tabloları);
 * LOGO'ya bağlı modda yalnız Lumen'in kendi tabloları (M_*) — LOGO verisinin yedeği
 * LOGO'nun kendi aracıyla alınır.
 *
 * Biçim (.lumen.gz): gzip içinde satır satır JSON.
 *   {"lumen_yedek":1, "mod":"lumen", "veritabani":..., "olusturma":..., "tur":...}
 *   {"t":"LM_CARI", "k":[kolonlar], "h":[ikili kolon sıraları]}   ← tablo başı
 *   [satır değerleri]                                             ← her satır
 *   {"bitti":true, "tablo":N, "satir":M}                          ← yoksa dosya yarım
 * İkili kolonlar onaltılık metin; hesaplanan ve rowversion kolonları yazılmaz.
 *
 * Geri yükleme tek işlemdir: yedekteki tablolar boşaltılıp yeniden doldurulur
 * (kimlikler korunur); hata olursa hiçbir şey değişmez. Öncesinde mevcut durumun
 * yedeği otomatik alınır.
 */

require_once __DIR__ . '/../veri/kaynak.php';

const LUMEN_YEDEK_UZANTI = '.lumen.gz';
const LUMEN_YEDEK_SATIR_SINIRI = 64 * 1024 * 1024;

if (!function_exists('lumen_yedek_ayar')) {
    /** @return array{klasor:string, otomatik:bool, saklama:int} */
    function lumen_yedek_ayar(): array
    {
        $varsayilan = ['klasor' => '', 'otomatik' => true, 'saklama' => 30];
        $dosya = dirname(__DIR__, 2) . '/database/yedek_ayar.json';
        $j = is_file($dosya) ? json_decode((string) file_get_contents($dosya), true) : null;
        $a = is_array($j) ? $j + $varsayilan : $varsayilan;
        return ['klasor' => (string) $a['klasor'], 'otomatik' => (bool) $a['otomatik'], 'saklama' => max(3, min(365, (int) $a['saklama']))];
    }
}

if (!function_exists('lumen_yedek_ayar_kaydet')) {
    /** @return string|null hata */
    function lumen_yedek_ayar_kaydet(string $klasor, bool $otomatik, int $saklama): ?string
    {
        $klasor = trim($klasor);
        if ($klasor !== '') {
            if (!preg_match('#^(/|[A-Za-z]:[\\\\/]|\\\\\\\\)#', $klasor) || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $klasor)) {
                return 'Yedek klasörü tam yol olmalı (ör. D:\\Yedekler\\Lumen ya da /var/yedek/lumen).';
            }
            // Yedekler parola özetleri ve bütün iş verisini içerir: web klasörünün içine (veritabanı
            // klasörü dışında) yazılırsa tarayıcıdan indirilebilir hale gelirdi. Klasör henüz yoksa
            // var olan en yakın üst klasörün gerçek yolu denetlenir (oluşturmadan önce).
            $ust = $klasor;
            $ek = '';
            while (!is_dir($ust) && dirname($ust) !== $ust) {
                $ek = DIRECTORY_SEPARATOR . basename($ust) . $ek;
                $ust = dirname($ust);
            }
            $gercek = (string) (realpath($ust) ?: $ust) . $ek;
            $kok = (string) realpath(dirname(__DIR__, 2));
            $izinli = $kok . DIRECTORY_SEPARATOR . 'database';
            $icinde = static fn(string $a, string $b): bool => strncasecmp(rtrim($a, '/\\') . DIRECTORY_SEPARATOR, $b . DIRECTORY_SEPARATOR, strlen($b) + 1) === 0;
            if ($icinde($gercek, $kok) && !$icinde($gercek, $izinli)) {
                return 'Yedek klasörü uygulama (web) klasörünün içinde olamaz; başka bir disk ya da klasör seçin.';
            }
            if (!is_dir($klasor) && !@mkdir($klasor, 0750, true)) {
                return 'Klasör oluşturulamadı: ' . $klasor;
            }
            if (!is_writable($klasor)) {
                return 'Klasöre yazılamıyor (izinleri kontrol edin): ' . $klasor;
            }
            $klasor = (string) realpath($klasor);
        }
        $ok = file_put_contents(dirname(__DIR__, 2) . '/database/yedek_ayar.json',
            json_encode(['klasor' => $klasor, 'otomatik' => $otomatik, 'saklama' => max(3, min(365, $saklama))], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        return $ok === false ? 'Ayar kaydedilemedi (database/ klasörü yazılabilir olmalı).' : null;
    }
}

if (!function_exists('lumen_yedek_klasoru')) {
    function lumen_yedek_klasoru(): string
    {
        $k = lumen_yedek_ayar()['klasor'];
        $k = $k !== '' ? $k : dirname(__DIR__, 2) . '/database/yedekler';
        if (!is_dir($k)) {
            @mkdir($k, 0750, true);
        }
        return rtrim($k, '/\\');
    }
}

if (!function_exists('lumen_yedek_dosya_adi_gecerli')) {
    function lumen_yedek_dosya_adi_gecerli(string $ad): bool
    {
        return preg_match('/^lumen_[0-9]{8}_[0-9]{6}_[a-z_]+_[a-f0-9]{6}\.lumen\.gz$/', $ad) === 1;
    }
}

if (!function_exists('lumen_yedek_listesi')) {
    /** En yeni önce. @return list<array{ad:string, boyut:int, tarih:int, tur:string}> */
    function lumen_yedek_listesi(): array
    {
        $liste = [];
        foreach (glob(lumen_yedek_klasoru() . '/lumen_*' . LUMEN_YEDEK_UZANTI) ?: [] as $yol) {
            $ad = basename($yol);
            if (!lumen_yedek_dosya_adi_gecerli($ad)) {
                continue;
            }
            preg_match('/^lumen_[0-9]{8}_[0-9]{6}_([a-z_]+)_/', $ad, $m);
            // Başlık satırı (hangi veritabanından, hangi modda alındı) onay ekranında gösterilir.
            $baslik = [];
            if (($gz = @gzopen($yol, 'rb')) !== false) {
                $baslik = json_decode((string) gzgets($gz, 4096), true) ?: [];
                gzclose($gz);
            }
            $liste[] = ['ad' => $ad, 'boyut' => (int) filesize($yol), 'tarih' => (int) filemtime($yol), 'tur' => $m[1] ?? '',
                        'veritabani' => (string) ($baslik['veritabani'] ?? '?'), 'mod' => (string) ($baslik['mod'] ?? '?')];
        }
        usort($liste, static fn($a, $b) => strcmp($b['ad'], $a['ad']));
        return $liste;
    }
}

if (!function_exists('lumen_yedek_tablolari')) {
    /** Yedeklenecek tablolar: bağımsızda hepsi, LOGO modunda yalnız Lumen'in M_* tabloları. */
    function lumen_yedek_tablolari(PDO $dbh): array
    {
        $kosul = lumen_bagimsiz_mi() ? '' : " AND t.name LIKE 'M[_]%'";
        return $dbh->query("SELECT t.name FROM sys.tables t JOIN sys.schemas s ON s.schema_id = t.schema_id
                            WHERE s.name = 'dbo' AND t.is_ms_shipped = 0{$kosul} ORDER BY t.name")->fetchAll(PDO::FETCH_COLUMN);
    }
}

if (!function_exists('lumen_yedek_kolonlari')) {
    /** Yazılabilir kolonlar ve ikili olanlar. @return array{0: list<string>, 1: list<int>} */
    function lumen_yedek_kolonlari(PDO $dbh, string $tablo): array
    {
        $st = $dbh->prepare("SELECT c.name, ty.name AS tip FROM sys.columns c JOIN sys.types ty ON ty.user_type_id = c.user_type_id
                             WHERE c.object_id = OBJECT_ID(:t) AND c.is_computed = 0 AND ty.name NOT IN ('timestamp', 'rowversion')
                             ORDER BY c.column_id");
        $st->execute([':t' => 'dbo.' . $tablo]);
        $kol = [];
        $ikili = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $i => $r) {
            $kol[] = (string) $r['name'];
            if (in_array($r['tip'], ['varbinary', 'binary', 'image'], true)) {
                $ikili[] = $i;
            }
        }
        return [$kol, $ikili];
    }
}

if (!function_exists('lumen_yedek_al')) {
    /**
     * @param string $tur elle | otomatik | geri_yukleme_oncesi
     * @return array{ok:bool, mesaj:string, ad?:string, boyut?:int, satir?:int}
     */
    function lumen_yedek_al(PDO $dbh, string $tur = 'elle', ?string $klasor = null): array
    {
        $tur = preg_match('/^[a-z_]{2,30}$/', $tur) === 1 ? $tur : 'elle';
        $klasor ??= lumen_yedek_klasoru();
        $ad = 'lumen_' . date('Ymd_His') . "_{$tur}_" . bin2hex(random_bytes(3)) . LUMEN_YEDEK_UZANTI;
        $gecici = $klasor . '/.' . $ad . '.yaziliyor';
        $gz = @gzopen($gecici, 'wb6');
        if ($gz === false) {
            return ['ok' => false, 'mesaj' => 'Yedek klasörüne yazılamıyor: ' . $klasor];
        }
        $yaz = static function (mixed $v) use ($gz): void {
            if (gzwrite($gz, json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n") === false) {
                throw new RuntimeException('yazma hatası');
            }
        };
        $satir = 0;
        $tablolar = 0;
        try {
            $yaz(['lumen_yedek' => 1, 'mod' => lumen_veri_kaynagi(), 'veritabani' => (string) $dbh->query('SELECT DB_NAME()')->fetchColumn(),
                  'olusturma' => date('c'), 'tur' => $tur]);
            // Tutarlı görüntü: tüm tablolar aynı anın verisi (anlık görüntü yoksa okuma kilidiyle).
            foreach (lumen_yedek_tablolari($dbh) as $t) {
                [$kol, $ikili] = lumen_yedek_kolonlari($dbh, $t);
                if ($kol === []) {
                    continue;
                }
                $sec = [];
                foreach ($kol as $i => $c) {
                    $sec[] = in_array($i, $ikili, true) ? "CONVERT(VARCHAR(MAX), [{$c}], 2) AS [{$c}]" : "[{$c}]";
                }
                $yaz(['t' => $t, 'k' => $kol, 'h' => $ikili]);
                $st = $dbh->query('SELECT ' . implode(', ', $sec) . " FROM dbo.[{$t}]");
                while (($r = $st->fetch(PDO::FETCH_NUM)) !== false) {
                    $yaz($r);
                    $satir++;
                }
                $st->closeCursor();
                $tablolar++;
            }
            $yaz(['bitti' => true, 'tablo' => $tablolar, 'satir' => $satir]);
            gzclose($gz);
            if (!@rename($gecici, $klasor . '/' . $ad)) {
                throw new RuntimeException('dosya adlandırılamadı');
            }
            @chmod($klasor . '/' . $ad, 0600);   // yalnız sunucu hesabı okuyabilsin
        } catch (Throwable $e) {
            @gzclose($gz);
            @unlink($gecici);
            error_log('lumen_yedek_al: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'Yedek alınamadı: ' . $e->getMessage()];
        }
        lumen_yedek_temizle($klasor);
        return ['ok' => true, 'mesaj' => "Yedek alındı: {$tablolar} tablo, {$satir} kayıt.", 'ad' => $ad,
                'boyut' => (int) filesize($klasor . '/' . $ad), 'satir' => $satir];
    }
}

if (!function_exists('lumen_yedek_temizle')) {
    /** Saklama sayısını aşan en eski yedekleri siler (yalnız Lumen'in adlandırdığı dosyalar). */
    function lumen_yedek_temizle(?string $klasor = null): void
    {
        $saklama = lumen_yedek_ayar()['saklama'];
        $klasor ??= lumen_yedek_klasoru();
        $liste = lumen_yedek_listesi();
        foreach (array_slice($liste, $saklama) as $eski) {
            @unlink($klasor . '/' . $eski['ad']);
        }
        // Yarıda kalmış (zaman aşımı, kapanma) geçici yedek dosyaları bir gün sonra silinir.
        foreach (glob($klasor . '/.lumen_*' . LUMEN_YEDEK_UZANTI . '.yaziliyor') ?: [] as $yarim) {
            if (filemtime($yarim) < time() - 86400) {
                @unlink($yarim);
            }
        }
    }
}

if (!function_exists('lumen_yedek_dogrula')) {
    /**
     * Yedek dosyasının başlık ve bitiş kaydını doğrular (tamamını okur).
     * @return array{baslik:array, bitis:array}|string hata metni
     */
    function lumen_yedek_dogrula(string $yol): array|string
    {
        $gz = @gzopen($yol, 'rb');
        if ($gz === false) {
            return 'Dosya açılamadı.';
        }
        $baslik = json_decode((string) gzgets($gz, 4096), true);
        if (!is_array($baslik) || ($baslik['lumen_yedek'] ?? null) !== 1) {
            gzclose($gz);
            return 'Bu bir Lumen yedeği değil.';
        }
        $son = null;
        // Satır başına üst sınır: tek dev satırlı (sıkıştırma bombası) dosya belleği tüketmesin.
        while (($l = gzgets($gz, LUMEN_YEDEK_SATIR_SINIRI)) !== false) {
            if (strlen($l) >= LUMEN_YEDEK_SATIR_SINIRI - 1 && !str_ends_with($l, "\n")) {
                gzclose($gz);
                return 'Yedek dosyası geçersiz (çok uzun satır).';
            }
            $son = $l;
        }
        gzclose($gz);
        $bitis = json_decode((string) $son, true);
        if (!is_array($bitis) || ($bitis['bitti'] ?? false) !== true) {
            return 'Yedek dosyası yarım (alınırken kesilmiş ya da bozulmuş).';
        }
        return ['baslik' => $baslik, 'bitis' => $bitis];
    }
}

if (!function_exists('lumen_yedek_geri_yukle')) {
    /**
     * Yedeği geri yükler (tek işlem). Önce mevcut durumun yedeği alınır.
     * @return array{ok:bool, mesaj:string, onceki?:string}
     */
    function lumen_yedek_geri_yukle(PDO $dbh, string $yol): array
    {
        $d = lumen_yedek_dogrula($yol);
        if (is_string($d)) {
            return ['ok' => false, 'mesaj' => $d];
        }
        if (($d['baslik']['mod'] ?? '') !== lumen_veri_kaynagi()) {
            return ['ok' => false, 'mesaj' => 'Yedek başka çalışma modunda alınmış (' . ($d['baslik']['mod'] ?? '?') . '); bu kurulumda geri yüklenemez.'];
        }
        $once = lumen_yedek_al($dbh, 'geri_yukleme_oncesi');
        if (!$once['ok']) {
            return ['ok' => false, 'mesaj' => 'Geri yüklemeden önce mevcut durumun yedeği alınamadı; işlem yapılmadı. ' . $once['mesaj']];
        }

        $mevcut = array_flip(lumen_yedek_tablolari($dbh));
        $gz = gzopen($yol, 'rb');
        gzgets($gz); // başlık
        $dokunulan = [];
        $dbh->beginTransaction();
        try {
            // Tablolar arası yabancı anahtarlar boşaltma/doldurma sırasını kısıtlamasın:
            // kısıtlar ve tetikleyiciler baştan kapatılır, sonda doğrulanarak açılır.
            foreach (array_keys($mevcut) as $t) {
                $dbh->exec("ALTER TABLE dbo.[{$t}] NOCHECK CONSTRAINT ALL");
                $dbh->exec("DISABLE TRIGGER ALL ON dbo.[{$t}]");
            }
            $tablo = null;
            $kolonlar = [];
            $kimlik = false;
            $tampon = [];
            $hedefKol = [];
            $ikili = [];
            $bosalt = static function () use (&$tampon, &$tablo, &$hedefKol, &$ikili, $dbh): void {
                if ($tampon === [] || $tablo === null) {
                    $tampon = [];
                    return;
                }
                $yer = '(' . implode(', ', array_map(static fn($i) => in_array($i, $ikili, true) ? 'CONVERT(VARBINARY(MAX), ?, 2)' : '?', array_keys($hedefKol))) . ')';
                $sql = "INSERT INTO dbo.[{$tablo}] (" . implode(', ', array_map(static fn($c) => "[{$c}]", $hedefKol)) . ') VALUES '
                    . implode(', ', array_fill(0, count($tampon), $yer));
                $dbh->prepare($sql)->execute(array_merge(...$tampon));
                $tampon = [];
            };
            $secim = [];
            while (($l = gzgets($gz, LUMEN_YEDEK_SATIR_SINIRI)) !== false) {
                $v = json_decode($l, true);
                if (!is_array($v)) {
                    throw new RuntimeException('bozuk satır');
                }
                if (isset($v['bitti'])) {
                    break;
                }
                if (isset($v['t'])) {
                    $bosalt();
                    if ($tablo !== null && $kimlik) {
                        $dbh->exec("SET IDENTITY_INSERT dbo.[{$tablo}] OFF");
                    }
                    $tablo = isset($mevcut[$v['t']]) ? (string) $v['t'] : null;
                    if ($tablo === null) {
                        continue; // bu sürümde olmayan tablo: atlanır
                    }
                    [$simdiki, $simdikiIkili] = lumen_yedek_kolonlari($dbh, $tablo);
                    $kolonlar = $v['k'];
                    // Yedekte olup tabloda hâlâ var olan kolonlar (şema yenilenmiş olabilir)
                    $secim = array_values(array_filter(array_keys($kolonlar), static fn($i) => in_array($kolonlar[$i], $simdiki, true)));
                    $hedefKol = array_map(static fn($i) => $kolonlar[$i], $secim);
                    $ikili = [];
                    foreach ($hedefKol as $j => $c) {
                        if (in_array(array_search($c, $simdiki, true), $simdikiIkili, true)) {
                            $ikili[] = $j;
                        }
                    }
                    $dokunulan[] = $tablo;
                    $dbh->exec("DELETE FROM dbo.[{$tablo}]");
                    $kimlik = (int) $dbh->query("SELECT COUNT(*) FROM sys.identity_columns WHERE object_id = OBJECT_ID('dbo.[{$tablo}]')")->fetchColumn() > 0;
                    if ($kimlik) {
                        $dbh->exec("SET IDENTITY_INSERT dbo.[{$tablo}] ON");
                    }
                    continue;
                }
                if ($tablo === null || $hedefKol === []) {
                    continue;
                }
                $tampon[] = array_map(static fn($i) => $v[$i], $secim);
                if (count($tampon) >= max(1, min(500, intdiv(2000, count($hedefKol))))) {
                    $bosalt();
                }
            }
            $bosalt();
            if ($tablo !== null && $kimlik) {
                $dbh->exec("SET IDENTITY_INSERT dbo.[{$tablo}] OFF");
            }
            foreach (array_keys($mevcut) as $t) {
                $dbh->exec("ENABLE TRIGGER ALL ON dbo.[{$t}]");
                $dbh->exec("ALTER TABLE dbo.[{$t}] WITH CHECK CHECK CONSTRAINT ALL");
            }
            foreach ($dokunulan as $t) {
                // Kimlik sayacı en büyük değerin altında kalmasın.
                if ((int) $dbh->query("SELECT COUNT(*) FROM sys.identity_columns WHERE object_id = OBJECT_ID('dbo.[{$t}]')")->fetchColumn() > 0) {
                    $dbh->exec("DBCC CHECKIDENT ('dbo.[{$t}]', RESEED) WITH NO_INFOMSGS");
                }
            }
            $dbh->commit();
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            gzclose($gz);
            error_log('lumen_yedek_geri_yukle: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'Geri yükleme yapılamadı, hiçbir şey değişmedi: ' . $e->getMessage()];
        }
        gzclose($gz);
        // Yedekle birlikte eski "beni hatırla"/API belirteçleri ve kapatılmış oturumlar da geri
        // gelmiş olabilir: hepsi geçersiz sayılır, herkes (yedekteki parolasıyla) yeniden girer.
        foreach (["DELETE FROM dbo.M_REMEMBER_TOKEN", "UPDATE dbo.M_API_TOKEN SET AKTIF = 0 WHERE AKTIF = 1",
                  "DELETE FROM dbo.M_OTURUM_KAPAT",
                  "INSERT INTO dbo.M_OTURUM_KAPAT (KULLANICI_ID, KAPATMA_TS) SELECT PERSONEL, GETDATE() FROM dbo.M_P_YETKI"] as $q) {
            try {
                $dbh->exec($q);
            } catch (Throwable $e) {
                error_log('lumen_yedek_geri_yukle oturumlar: ' . $e->getMessage());
            }
        }
        return ['ok' => true, 'mesaj' => 'Yedek geri yüklendi (' . count($dokunulan) . ' tablo). Bütün oturumlar kapatıldı; herkes yeniden giriş yapmalı '
                . '(parolalar yedek anındaki haline döndü). Önceki durumun yedeği: ' . $once['ad'], 'onceki' => $once['ad']];
    }
}

if (!function_exists('lumen_otomatik_yedek')) {
    /**
     * Günlük otomatik yedek: gün içinde ilk oturumlu istekte, yanıt gönderildikten
     * sonra alınır (kullanıcı beklemez). Ayrı zamanlayıcı gerekmez.
     */
    function lumen_otomatik_yedek(PDO $dbh): void
    {
        if (PHP_SAPI === 'cli' || !lumen_yedek_ayar()['otomatik']) {
            return;
        }
        $damga = dirname(__DIR__, 2) . '/database/yedek_son_otomatik.txt';
        $bugun = date('Y-m-d');
        if (is_file($damga) && str_starts_with(trim((string) @file_get_contents($damga)), $bugun)) {
            return;
        }
        register_shutdown_function(static function () use ($dbh, $damga, $bugun): void {
            $kilit = @fopen($damga . '.kilit', 'c');
            if ($kilit === false || !flock($kilit, LOCK_EX | LOCK_NB)) {
                return; // başka istek alıyor
            }
            try {
                if (is_file($damga) && str_starts_with(trim((string) @file_get_contents($damga)), $bugun)) {
                    return;
                }
                // Gün içinde bir kez denenir: başarısız olsa da (disk dolu, ağ klasörü yok) her
                // istekte yeniden tam yedek başlamasın. Sonuç damgada ve hata günlüğünde.
                @file_put_contents($damga, $bugun . ' deneniyor');
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();   // kullanıcının diğer sekmeleri yedek bitene dek kilitlenmesin
                }
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                ignore_user_abort(true);
                @set_time_limit(600);
                $r = lumen_yedek_al($dbh, 'otomatik');
                @file_put_contents($damga, $bugun . ($r['ok'] ? '' : ' hata'));
                if (!$r['ok']) {
                    error_log('otomatik yedek: ' . $r['mesaj']);
                }
            } finally {
                flock($kilit, LOCK_UN);
                fclose($kilit);
            }
        });
    }
}

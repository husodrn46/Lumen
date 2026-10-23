<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/oturum.php';

if (!function_exists('denetimSayfaListesi')) {
    /**
     * Yetki denetiminde test edilecek temel sayfalar.
     */
    function denetimSayfaListesi(): array
    {
        return [
            ['kod' => 'M1', 'label' => 'Yeni Sipariş', 'path' => '../cari/cari.php', 'menu' => true],
            ['kod' => 'M2', 'label' => 'Siparişler', 'path' => '../siparis/lg_essiparis.php', 'menu' => true],
            ['kod' => 'M21', 'label' => 'Döviz İşlemleri', 'path' => 'doviz/index.php', 'menu' => true],
            // NOT: M3 (Mağaza Satış) öğesi kaldırıldı — probe hedefi ../fiyat/fiyat_sec.php
            // emekliye ayrıldı; ../siparis/fisekle.php GET'i fiş OLUŞTURDUĞU için probe hedefi
            // yapılamaz. M3 yalnız index.php kart görünürlüğünü kontrol eder.
            ['kod' => 'M4', 'label' => 'Müşteri Bakiye', 'path' => '../cari/lg_bakiye.php', 'menu' => true, 'allow_codes' => ['M4', 'M20'], 'kod_label' => 'M4/M20'],
            ['kod' => 'M5', 'label' => 'Tüm Siparişler', 'path' => '../siparis/lg_tumsiparisler.php', 'menu' => true],
            ['kod' => 'M6', 'label' => 'Barkodlar', 'path' => '../barkod/barkodlar.php', 'menu' => true],
            ['kod' => 'M7', 'label' => 'Stok Ara', 'path' => '../stok/stok_tara.php', 'menu' => true],
            ['kod' => 'M7', 'label' => 'Fiyat Listesi', 'path' => '../fiyat/fiyat_listesi.php', 'menu' => true],
            ['kod' => 'M8', 'label' => 'Bekleyen Ürünler', 'path' => '../siparis/bekleyen_siparis.php', 'menu' => true],
            ['kod' => 'M10', 'label' => 'Geri Dönüşüm', 'path' => '../siparis/lg_geridonusum.php', 'menu' => true],
            ['kod' => 'M13', 'label' => 'Günlük İşlemler', 'path' => '../rapor/gunluk_islemler.php', 'menu' => true],
            ['kod' => 'M15', 'label' => 'Stoklar', 'path' => 'stok/index.php', 'menu' => true],
            ['kod' => 'M16', 'label' => 'Ayarlar', 'path' => 'ayar/', 'menu' => true],
            ['kod' => 'M17', 'label' => 'Raporlar', 'path' => 'rapor/dashboard.php', 'menu' => true],
            ['kod' => 'M23', 'label' => 'Kullanıcı Aktivite', 'path' => 'dashboard_kullanici.php', 'menu' => false],
            ['kod' => 'M18', 'label' => 'Loglar', 'path' => 'loglar.php', 'menu' => false],
        ];
    }
}

if (!function_exists('denetimYetkiKodlari')) {
    /**
     * Bir denetim öğesinin erişim kodlarını döndürür.
     */
    function denetimYetkiKodlari(array $item): array
    {
        if (isset($item['allow_codes']) && is_array($item['allow_codes']) && $item['allow_codes'] !== []) {
            return array_values($item['allow_codes']);
        }

        return [trim((string) ($item['kod'] ?? ''))];
    }
}

if (!function_exists('denetimBeklenenErisimVarMi')) {
    /**
     * Kullanıcının bu öğeye beklenen erişimi var mı?
     */
    function denetimBeklenenErisimVarMi(int|string $userId, array $item): bool
    {
        return m_p_yetki_kodlarindan_birine_sahip_mi($userId, denetimYetkiKodlari($item));
    }
}

if (!function_exists('denetimKodMetni')) {
    /**
     * Denetim ekranında gösterilecek yetki kodu etiketi.
     */
    function denetimKodMetni(array $item): string
    {
        if (isset($item['kod_label']) && trim((string) $item['kod_label']) !== '') {
            return trim((string) $item['kod_label']);
        }

        $codes = array_filter(denetimYetkiKodlari($item), static fn($code): bool => trim((string) $code) !== '');
        return implode('/', $codes);
    }
}

if (!function_exists('denetimAlanYetkiGruplari')) {
    /**
     * Sayfa dışındaki alan yetkilerini gruplar.
     * Tek kaynaktan (yetki_tanimlari.php) türetilir; dosya yoksa sabit liste.
     */
    function denetimAlanYetkiGruplari(): array
    {
        $tanimDosyasi = __DIR__ . '/yetki_tanimlari.php';
        if (is_file($tanimDosyasi)) {
            require_once $tanimDosyasi;
        }
        if (function_exists('yetki_gruplari')) {
            $out = [];
            foreach (yetki_gruplari() as $key => $grup) {
                if ($key === 'menu') { continue; } // sayfa yetkileri ayrı listede
                $out[(string) $grup['ad']] = array_keys($grup['kodlar']);
            }
            return $out;
        }
        return [
            'Cari Yetkileri' => ['CR1', 'CR2', 'CR3', 'CR4'],
            'Stok Yetkileri' => ['ST1', 'ST2', 'ST3'],
            'Sipariş Yetkileri' => ['SP1', 'SP2', 'SP3', 'SP4'],
        ];
    }
}

if (!function_exists('denetimRolAdi')) {
    function denetimRolAdi(int $role): string
    {
        return match ($role) {
            0 => 'Yönetici',
            1 => 'Personel',
            default => 'Müşteri',
        };
    }
}

if (!function_exists('denetimRolSinifi')) {
    function denetimRolSinifi(int $role): string
    {
        return match ($role) {
            0 => 'bg-amber-100 text-amber-700',
            1 => 'bg-blue-100 text-blue-700',
            default => 'bg-slate-100 text-slate-700',
        };
    }
}

if (!function_exists('denetimLinki')) {
    function denetimLinki(string $path): string
    {
        if (str_starts_with($path, 'ayar/')) {
            return substr($path, 5);
        }

        return '../' . $path;
    }
}

if (!function_exists('denetimBaseUrlGuvenliMi')) {
    /**
     * Probe oturum çerezi yalnızca aynı makinedeki web sunucusuna gönderilebilir.
     * DNS çözümlemesine açık adlar yerine literal loopback adresi zorunludur.
     */
    function denetimBaseUrlGuvenliMi(string $baseUrl): bool
    {
        $baseUrl = trim($baseUrl);
        if ($baseUrl === '' || preg_match('/[\x00-\x20\x7f]/', $baseUrl) === 1) {
            return false;
        }

        try {
            $parts = parse_url($baseUrl);
        } catch (ValueError) {
            return false;
        }
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!in_array($scheme, ['http', 'https'], true) || !in_array($host, ['127.0.0.1', '::1'], true)) {
            return false;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $port = $parts['port'] ?? null;
        return $port === null || ($port >= 1 && $port <= 65535);
    }
}

if (!function_exists('denetimBaseUrl')) {
    /**
     * Yetki probe hedefini güvenilir ortam yapılandırmasından üretir.
     * HTTP Host/X-Forwarded-* başlıkları güven sınırının dışında tutulur.
     */
    function denetimBaseUrl(): string
    {
        $baseUrl = trim((string) getenv('LUMEN_DENETIM_BASE_URL'));
        if ($baseUrl === '') {
            $baseUrl = 'http://127.0.0.1';
        }

        if (!denetimBaseUrlGuvenliMi($baseUrl)) {
            throw new RuntimeException('LUMEN_DENETIM_BASE_URL yalnızca 127.0.0.1 veya ::1 loopback adresini kullanabilir.');
        }

        return rtrim($baseUrl, '/');
    }
}

if (!function_exists('denetimProbeSessionOlustur')) {
    /**
     * Test kullanıcı için geçici session oluşturur.
     */
    function denetimProbeSessionOlustur(int $userId): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            lumen_oturum_baslat();
        }

        $sessionName = session_name();
        $adminSessionId = session_id();
        $probeSessionId = function_exists('session_create_id')
            ? session_create_id('probe-')
            : ('probe_' . bin2hex(random_bytes(10)));

        session_write_close();

        session_id($probeSessionId);
        lumen_oturum_baslat();
        $_SESSION = [];
        $_SESSION['plasiyer_id'] = $userId;
        $_SESSION['session_regenerated'] = true;
        session_write_close();

        session_id($adminSessionId);
        lumen_oturum_baslat();

        return [
            'session_name' => $sessionName,
            'session_id' => $probeSessionId,
        ];
    }
}

if (!function_exists('denetimProbeSessionTemizle')) {
    /**
     * Oluşturulan geçici probe session'ını temizler.
     */
    function denetimProbeSessionTemizle(string $probeSessionId): void
    {
        if ($probeSessionId === '') {
            return;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            lumen_oturum_baslat();
        }

        $adminSessionId = session_id();

        session_write_close();

        session_id($probeSessionId);
        lumen_oturum_baslat();
        $_SESSION = [];
        session_destroy();
        session_write_close();

        session_id($adminSessionId);
        lumen_oturum_baslat();
    }
}

if (!function_exists('denetimResponseStatusu')) {
    /**
     * HTTP yanıtını erişim statüsüne çevirir.
     */
    function denetimResponseStatusu(int $httpCode, string $location, string $body, string $transportError = ''): string
    {
        if ($transportError !== '') {
            return 'error';
        }

        $bodyText = trim(strip_tags($body));
        $bodyText = function_exists('mb_strtolower')
            ? mb_strtolower($bodyText, 'UTF-8')
            : strtolower($bodyText);
        $locationText = function_exists('mb_strtolower')
            ? mb_strtolower($location, 'UTF-8')
            : strtolower($location);

        if ($httpCode === 403 || str_contains($locationText, '/403.html')) {
            return 'forbidden';
        }

        if (
            str_contains($locationText, 'giris.php?hata=oturum_gerekli') ||
            str_contains($locationText, 'giris.php?hata=gecersiz_yetki') ||
            str_contains($locationText, '/musteri/giris.php?hata=musteri_erisim')
        ) {
            return 'login';
        }

        if (
            str_contains($bodyText, 'bu bilgileri gorme yetkiniz yok') ||
            str_contains($bodyText, 'bu bilgileri görme yetkiniz yok') ||
            str_contains($bodyText, 'erisim reddedildi') ||
            str_contains($bodyText, 'erişim reddedildi') ||
            str_contains($bodyText, 'bu sayfaya erisim yetkiniz bulunmamaktadir') ||
            str_contains($bodyText, 'bu sayfaya erişim yetkiniz bulunmamaktadır')
        ) {
            return 'forbidden';
        }

        if ($httpCode >= 500) {
            return 'error';
        }

        if ($httpCode >= 300 && $httpCode < 400) {
            return 'redirect';
        }

        return 'allowed';
    }
}

if (!function_exists('denetimSonucRozeti')) {
    /**
     * Statü için okunaklı etiket ve sınıf üretir.
     */
    function denetimSonucRozeti(string $status): array
    {
        return match ($status) {
            'allowed' => ['label' => 'Acildi', 'class' => 'bg-emerald-100 text-emerald-700'],
            'forbidden' => ['label' => 'Engellendi', 'class' => 'bg-red-100 text-red-700'],
            'login' => ['label' => 'Oturum Istedi', 'class' => 'bg-amber-100 text-amber-700'],
            'redirect' => ['label' => 'Yonlendirdi', 'class' => 'bg-sky-100 text-sky-700'],
            default => ['label' => 'Hata', 'class' => 'bg-red-200 text-red-800'],
        };
    }
}

if (!function_exists('denetimHttpProbe')) {
    /**
     * Verilen session ile hedef sayfaya gerçek HTTP isteği atar.
     */
    function denetimHttpProbe(string $baseUrl, string $sessionName, string $sessionId, string $path): array
    {
        if (!denetimBaseUrlGuvenliMi($baseUrl)) {
            throw new InvalidArgumentException('Güvenli olmayan yetki denetim hedefi reddedildi.');
        }

        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        $startedAt = microtime(true);

        $httpCode = 0;
        $location = '';
        $body = '';
        $headerText = '';
        $transportError = '';

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => ['Cookie: ' . $sessionName . '=' . $sessionId],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);

            $rawResponse = curl_exec($ch);
            if ($rawResponse === false) {
                $transportError = (string) curl_error($ch);
            } else {
                $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $headerText = substr((string) $rawResponse, 0, $headerSize);
                $body = substr((string) $rawResponse, $headerSize);
            }
            curl_close($ch);
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'ignore_errors' => true,
                    'timeout' => 20,
                    'header' => "Cookie: {$sessionName}={$sessionId}\r\n",
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $rawBody = @file_get_contents($url, false, $context);
            if ($rawBody === false) {
                $transportError = 'HTTP istegi yapilamadi';
            } else {
                $body = (string) $rawBody;
                $headers = $http_response_header ?? [];
                $headerText = implode("\n", $headers);
                foreach ($headers as $headerLine) {
                    if (preg_match('/^HTTP\/\S+\s+(\d{3})/i', $headerLine, $matches)) {
                        $httpCode = (int) $matches[1];
                    }
                }
            }
        }

        foreach (preg_split('/\r\n|\r|\n/', $headerText) as $headerLine) {
            if (stripos($headerLine, 'Location:') === 0) {
                $location = trim(substr($headerLine, 9));
            }
        }

        $plainBody = trim(strip_tags($body));
        $plainBody = preg_replace('/\s+/', ' ', $plainBody ?? '') ?? '';
        $plainBody = function_exists('mb_substr')
            ? mb_substr($plainBody, 0, 200, 'UTF-8')
            : substr($plainBody, 0, 200);

        $result = [
            'url' => $url,
            'http_code' => $httpCode,
            'location' => $location,
            'status' => denetimResponseStatusu($httpCode, $location, $body, $transportError),
            'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
            'bytes' => strlen($body),
            'snippet' => $plainBody,
            'transport_error' => $transportError,
        ];

        return $result;
    }
}

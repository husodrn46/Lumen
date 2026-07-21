<?php
declare(strict_types=1);

if (!function_exists('lumen_stok_gorsel_normalize')) {
    function lumen_stok_gorsel_normalize(string|int|float|null $value): string
    {
        $text = function_exists('turkce') ? turkce($value) : (string) $value;
        $text = strtr($text, [
            'Ç' => 'C',
            'ç' => 'c',
            'Ğ' => 'G',
            'ğ' => 'g',
            'İ' => 'I',
            'ı' => 'i',
            'Ö' => 'O',
            'ö' => 'o',
            'Ş' => 'S',
            'ş' => 's',
            'Ü' => 'U',
            'ü' => 'u',
        ]);
        $text = strtoupper($text);
        $text = preg_replace('/[^A-Z0-9]+/', ' ', $text) ?? '';
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}

if (!function_exists('lumen_stok_gorsel_compact')) {
    function lumen_stok_gorsel_compact(string|int|float|null $value): string
    {
        return str_replace(' ', '', lumen_stok_gorsel_normalize($value));
    }
}

if (!function_exists('lumen_stok_gorsel_manifest')) {
    /**
     * @return array<int,array<string,string>>
     */
    function lumen_stok_gorsel_manifest(): array
    {
        static $products = null;

        if (is_array($products)) {
            return $products;
        }

        $manifestPath = __DIR__ . '/../tm/urun-gorselleri/manifest.json';
        if (!is_file($manifestPath)) {
            $products = [];
            return $products;
        }

        $payload = json_decode((string) file_get_contents($manifestPath), true);
        $products = is_array($payload) && isset($payload['products']) && is_array($payload['products'])
            ? $payload['products']
            : [];

        return $products;
    }
}

if (!function_exists('lumen_stok_gorsel_onek')) {
    /**
     * Ürün kodu ön eki (_bilgi_.inc -> $urun_kodu_oneki), normalize edilmiş.
     * Boşsa ön ek temelli eşleştirme/eleme uygulanmaz.
     */
    function lumen_stok_gorsel_onek(): string
    {
        global $urun_kodu_oneki;
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($urun_kodu_oneki ?? '')));
    }
}

if (!function_exists('lumen_stok_gorsel_sku_candidates')) {
    /**
     * @return array<int,string>
     */
    function lumen_stok_gorsel_sku_candidates(string $urunKodu): array
    {
        $onek = lumen_stok_gorsel_onek();
        $tokens = explode(' ', lumen_stok_gorsel_normalize($urunKodu));
        $skip = ['BEYAZ', 'SIYAH', 'KIRMIZI', 'MAVI', 'YESIL', 'DESENLI', 'DESEN'];
        $bases = [];
        $variants = [];
        $lastBase = null;

        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, $skip, true)) {
                continue;
            }

            if ($onek !== '' && str_starts_with($token, $onek)) {
                $token = substr($token, strlen($onek));
            }

            if ($token === '') {
                continue;
            }

            // Baz kod: harf(0-3) + rakam(2-4) + harf(0-3), örn "400", "400S", "529"
            if (preg_match('/^[A-Z]{0,3}[0-9]{2,4}[A-Z]{0,3}$/', $token) === 1) {
                $bases[] = $token;
                $lastBase = $token;
                continue;
            }

            // Kısa varyant son eki: "<ÖNEK>400-5" -> "5", "...400-1" -> "1", "1S" gibi.
            // Normalize tireyi boşluğa çevirdiği için varyant ayrı token olur ve tek
            // haneli olduğunda baz regex'ine takılmaz. Bir önceki baz kodla birleştirilir:
            // "400" + "5" => "4005". Bu yapılmazsa tüm seri "400"e çöker ve manifestte
            // birebir karşılığı olmayan varyantlara (altlık vb.) yanlış görsel atanır.
            if ($lastBase !== null && preg_match('/^[0-9]{1,2}[A-Z]{0,2}$/', $token) === 1) {
                $variants[$lastBase][] = $lastBase . $token;
            }
        }

        $candidates = [];
        foreach ($bases as $base) {
            if (!empty($variants[$base])) {
                // Varyantlı kod: yalnızca tam varyant ("4005") aday olur. Çıplak baz ("400")
                // bilerek eklenmez; aksi halde baz, serideki diğer tüm varyantlarla (ve set
                // görseliyle) eşleşip yanlış görsel seçilmesine yol açar.
                foreach ($variants[$base] as $variantCode) {
                    $candidates[] = $variantCode;
                }
            } else {
                $candidates[] = $base;
            }
        }

        return array_values(array_unique($candidates));
    }
}

if (!function_exists('lumen_stok_gorsel_color_candidates')) {
    /**
     * @return array<int,string>
     */
    function lumen_stok_gorsel_color_candidates(string $urunKodu, string $urunAdi): array
    {
        $text = lumen_stok_gorsel_normalize($urunKodu . ' ' . $urunAdi);
        $colors = [];

        foreach (['BEYAZ', 'SIYAH', 'KIRMIZI', 'MAVI', 'YESIL', 'DESENLI', 'DESEN'] as $color) {
            if (str_contains($text, $color)) {
                $colors[] = $color;
            }
        }

        return $colors;
    }
}

if (!function_exists('lumen_stok_gorsel_variant_candidates')) {
    /**
     * @return array<int,string>
     */
    function lumen_stok_gorsel_variant_candidates(string $urunKodu, string $urunAdi): array
    {
        $code = lumen_stok_gorsel_compact($urunKodu);
        $text = lumen_stok_gorsel_normalize($urunKodu . ' ' . $urunAdi);
        $candidates = [];

        $onek = lumen_stok_gorsel_onek();
        $desen = '/' . ($onek !== '' ? preg_quote($onek, '/') : '') . '([0-9]{2,4})/';
        if (preg_match($desen, $code, $matches) !== 1) {
            return $candidates;
        }

        $base = $matches[1];
        $suffix = substr($code, strpos($code, $base) + strlen($base));

        if (str_starts_with($suffix, 'KB') || (str_contains($text, 'KIRMIZI') && str_contains($text, 'BEYAZ') && !str_contains($text, 'SADE KIRMIZI'))) {
            $candidates[] = $base . 'KB';
        }

        if (str_starts_with($suffix, 'MB') || (str_contains($text, 'MAVI') && str_contains($text, 'BEYAZ'))) {
            $candidates[] = $base . 'MB';
        }

        if (str_starts_with($suffix, 'SK') || (str_contains($text, 'SADE KIRMIZI') || (str_contains($text, 'KIRMIZI') && !str_contains($text, 'BEYAZ')))) {
            $candidates[] = $base . 'K';
        }

        if (str_starts_with($suffix, 'SS') || str_contains($text, 'SADE SIYAH') || str_contains($text, 'SIYAH')) {
            $candidates[] = $base . 'S';
        }

        if (str_starts_with($suffix, 'SB') || str_contains($text, 'SADE BEYAZ') || str_contains($text, 'PROMOSYON')) {
            $candidates[] = $base;
        }

        return array_values(array_unique($candidates));
    }
}

if (!function_exists('lumen_stok_gorsel_keyword_score')) {
    function lumen_stok_gorsel_keyword_score(string $productName, string $category, string $haystack): int
    {
        $score = 0;
        $name = lumen_stok_gorsel_normalize($productName);
        $category = lumen_stok_gorsel_normalize($category);

        $keywords = [
            'KASE' => 40,
            'TABAK' => 32,
            'TABAGI' => 32,
            'TEPSI' => 38,
            'SOSLUK' => 45,
            'KAYIK' => 36,
            'BOLMELI' => 34,
            'KARE' => 24,
            'FINCAN' => 34,
            'KUPA' => 34,
        ];

        foreach ($keywords as $keyword => $points) {
            if (str_contains($name . ' ' . $category, $keyword) && str_contains($haystack, $keyword)) {
                $score += $points;
            }
        }

        return $score;
    }
}

if (!function_exists('lumen_stok_gorsel_renk_hex')) {
    function lumen_stok_gorsel_renk_hex(string|int|float|null $renk): string
    {
        $normalized = lumen_stok_gorsel_normalize($renk);

        if (str_contains($normalized, 'KIRMIZI')) {
            return '#ef4444';
        }

        if (str_contains($normalized, 'SIYAH')) {
            return '#111827';
        }

        if (str_contains($normalized, 'MAVI')) {
            return '#2563eb';
        }

        if (str_contains($normalized, 'YESIL')) {
            return '#16a34a';
        }

        if (str_contains($normalized, 'DESEN')) {
            return '#d97706';
        }

        return '';
    }
}

if (!function_exists('lumen_stok_gorsel_bul')) {
    /**
     * @return array{src:string,found:bool,renk:string}
     */
    function lumen_stok_gorsel_bul(string|int|float|null $urunKodu, string|int|float|null $urunAdi = ''): array
    {
        $default = 'tm/rs/urunyok.jpg';
        $products = lumen_stok_gorsel_manifest();

        if ($products === []) {
            return ['src' => $default, 'found' => false, 'renk' => ''];
        }

        $code = (string) $urunKodu;
        $name = (string) $urunAdi;

        // Ürün kodu ön eki tanımlıysa (_bilgi_.inc -> $urun_kodu_oneki) görsel manifesti
        // YALNIZCA o seri için geçerlidir. Ön eki içermeyen seriler normalize sırasında
        // çıplak sayısal koda indirgenir (ör. KSMT-011 -> "011") ve manifestteki aynı
        // numaralı başka bir görsele yanlış eşleşirdi. Ön ek boşsa bu eleme uygulanmaz.
        $onek = lumen_stok_gorsel_onek();
        if ($onek !== '' && !str_contains(lumen_stok_gorsel_compact($code), $onek)) {
            return ['src' => $default, 'found' => false, 'renk' => ''];
        }

        $skuCandidates = array_values(array_unique(array_merge(
            lumen_stok_gorsel_variant_candidates($code, $name),
            lumen_stok_gorsel_sku_candidates($code)
        )));
        $colors = lumen_stok_gorsel_color_candidates($code, $name);
        $haystack = lumen_stok_gorsel_normalize($code . ' ' . $name);

        $best = null;
        $bestScore = 0;

        foreach ($products as $product) {
            if (!is_array($product) || empty($product['sku']) || empty($product['image'])) {
                continue;
            }

            $sku = lumen_stok_gorsel_compact((string) $product['sku']);
            $renk = lumen_stok_gorsel_normalize((string) ($product['renk'] ?? ''));
            $score = 0;

            foreach ($skuCandidates as $candidate) {
                if ($sku === $candidate) {
                    $score += 140;
                    break;
                }

                if (str_starts_with($sku, $candidate) && strlen($sku) <= strlen($candidate) + 2) {
                    $score += 105;
                    break;
                }
            }

            if ($score === 0) {
                continue;
            }

            if ($colors !== []) {
                foreach ($colors as $color) {
                    if ($color !== '' && str_contains($renk, $color)) {
                        $score += 45;
                        break;
                    }
                }
            }

            $score += lumen_stok_gorsel_keyword_score(
                (string) ($product['ad_tr'] ?? ''),
                (string) ($product['kategori'] ?? ''),
                $haystack
            );

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        if (!is_array($best) || $bestScore < 100) {
            return ['src' => $default, 'found' => false, 'renk' => ''];
        }

        $src = (string) $best['image'];
        if (!is_file(__DIR__ . '/' . $src)) {
            return ['src' => $default, 'found' => false, 'renk' => ''];
        }

        return ['src' => $src, 'found' => true, 'renk' => (string) ($best['renk'] ?? '')];
    }
}

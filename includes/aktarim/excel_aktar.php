<?php

declare(strict_types=1);

/**
 * Excel'den (xlsx, xls, csv) cari ve stok kartı aktarma — bağımsız mod.
 *
 * Akış: şablon → yükle → doğrula (önizleme, satır satır hata) → onayla → tek işlemde yaz.
 * İsteğe bağlı açılışlar: cari açılış bakiyesi (devir hareketi), stok açılış miktarı
 * (merkez depoya açılış sayımı), satış/alış fiyatı, barkod, koli içi adet.
 * Var olan kodlar atlanır ya da (seçilirse) kart bilgileri güncellenir; bakiye ve
 * miktar yalnız yeni kartlara yazılır (mükerrer açılışı önlemek için).
 */

require_once __DIR__ . '/../belge/belge_lib.php';

const LUMEN_EXCEL_EN_FAZLA_SATIR = 20000;

if (!function_exists('lumen_excel_sablon_kolonlari')) {
    /** tür → [alan => [başlık, zorunlu, açıklama]] */
    function lumen_excel_sablon_kolonlari(string $tur): array
    {
        return $tur === 'cari' ? [
            'kod' => ['Kod', true, 'Benzersiz cari kodu (ör. 120.001)'],
            'unvan' => ['Unvan', true, 'Ticari unvan veya ad soyad'],
            'tur' => ['Tür', false, 'Alıcı, Satıcı ya da Alıcı+Satıcı (boş: Alıcı+Satıcı)'],
            'vergi_dairesi' => ['Vergi dairesi', false, ''],
            'vergi_no' => ['Vergi no / TCKN', false, '10 haneli vergi no ya da 11 haneli TCKN'],
            'il' => ['İl', false, ''], 'ilce' => ['İlçe', false, ''], 'adres' => ['Adres', false, ''],
            'telefon' => ['Telefon', false, ''], 'eposta' => ['E-posta', false, ''],
            'vade_gun' => ['Vade (gün)', false, 'Varsayılan ödeme vadesi'],
            'bakiye' => ['Açılış bakiyesi', false, 'Pozitif: cari bize borçlu; negatif: biz cariye borçluyuz'],
        ] : [
            'kod' => ['Kod', true, 'Benzersiz ürün kodu'],
            'ad' => ['Ad', true, 'Ürün adı'],
            'birim' => ['Birim', false, 'Ana birim kodu (boş: ADET); yoksa oluşturulur'],
            'kdv' => ['KDV %', false, 'Boş: 20'],
            'grup' => ['Grup', false, ''],
            'satis_fiyati' => ['Satış fiyatı', false, 'KDV hariç, TL, ana birim başına'],
            'alis_fiyati' => ['Alış fiyatı', false, 'KDV hariç, TL, ana birim başına'],
            'barkod' => ['Barkod', false, 'Ana birimin barkodu'],
            'koli_ici' => ['Koli içi adet', false, 'Doluysa KOLI birimi bu çarpanla eklenir'],
            'miktar' => ['Açılış miktarı', false, 'Merkez depodaki eldeki miktar (ana birimde)'],
        ];
    }
}

if (!function_exists('lumen_excel_sablon')) {
    /** Şablon xlsx'i (kod kolonları metin biçiminde, açıklama sayfasıyla) üretir; dosya yolunu döndürür. */
    function lumen_excel_sablon(string $tur, string $hedef): void
    {
        $k = lumen_excel_sablon_kolonlari($tur);
        $x = new PhpOffice\PhpSpreadsheet\Spreadsheet();
        $s = $x->getActiveSheet();
        $s->setTitle($tur === 'cari' ? 'Cariler' : 'Stoklar');
        $i = 1;
        foreach ($k as $alan => [$baslik, $zorunlu]) {
            $harf = PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
            $s->setCellValue($harf . '1', $baslik . ($zorunlu ? ' *' : ''));
            $s->getColumnDimension($harf)->setWidth(in_array($alan, ['unvan', 'ad', 'adres'], true) ? 36 : 16);
            if (in_array($alan, ['kod', 'vergi_no', 'telefon', 'barkod'], true)) {
                $s->getStyle($harf . '2:' . $harf . '5000')->getNumberFormat()->setFormatCode('@'); // baştaki sıfırlar kaybolmasın
            }
            $i++;
        }
        $s->getStyle('A1:' . PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($k)) . '1')->getFont()->setBold(true);
        $s->freezePane('A2');
        $ornek = $tur === 'cari'
            ? ['120.001', 'Örnek Ticaret Ltd. Şti.', 'Alıcı', 'Kadıköy', '1234567890', 'İstanbul', 'Kadıköy', 'Örnek Mah. No: 1', '0 216 000 00 00', 'info@ornek.test', 30, 1250.50]
            : ['URUN-001', 'Örnek Ürün 500 ml', 'ADET', 20, 'Bardak', 45.90, 28.10, '8690000000012', 24, 120];
        foreach (array_values($ornek) as $j => $v) {
            $s->setCellValueExplicit(PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j + 1) . '2', $v,
                is_string($v) ? PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING : PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        }
        $a = $x->createSheet();
        $a->setTitle('Açıklama');
        $a->fromArray([['Kolon', 'Zorunlu', 'Açıklama']], null, 'A1');
        $r = 2;
        foreach ($k as [$baslik, $zorunlu, $aciklama]) {
            $a->fromArray([[$baslik, $zorunlu ? 'Evet' : '', $aciklama]], null, 'A' . $r++);
        }
        $a->fromArray([['', '', 'Örnek satırı (2. satır) silin ya da üzerine yazın. Başlık satırını değiştirmeyin.']], null, 'A' . ($r + 1));
        $a->getColumnDimension('A')->setWidth(20);
        $a->getColumnDimension('C')->setWidth(70);
        $a->getStyle('A1:C1')->getFont()->setBold(true);
        $x->setActiveSheetIndex(0);
        (new PhpOffice\PhpSpreadsheet\Writer\Xlsx($x))->save($hedef);
    }
}

if (!function_exists('lumen_excel_oku')) {
    /**
     * İlk sayfayı başlık eşlemesiyle okur. Başlıklar büyük/küçük harf, Türkçe karakter ve
     * "*" farkı gözetmeden eşleşir. @return array{satirlar: list<array{no:int, v:array<string,string>}>, eksik: list<string>}
     */
    function lumen_excel_oku(string $yol, string $tur, string $orijinalAd = ''): array
    {
        $uzanti = strtolower(pathinfo($orijinalAd !== '' ? $orijinalAd : $yol, PATHINFO_EXTENSION));
        $okuyucu = match ($uzanti) {
            'csv' => (new PhpOffice\PhpSpreadsheet\Reader\Csv())->setDelimiter(str_contains((string) file_get_contents($yol, false, null, 0, 2000), ';') ? ';' : ',')->setInputEncoding('UTF-8'),
            'xls' => new PhpOffice\PhpSpreadsheet\Reader\Xls(),
            default => new PhpOffice\PhpSpreadsheet\Reader\Xlsx(),
        };
        if (method_exists($okuyucu, 'setReadDataOnly')) {
            $okuyucu->setReadDataOnly(true);
        }
        // Bellek koruması: yalnız ilk 30 kolon ve satır sınırı okunur. Sağda unutulmuş tek bir hücre
        // (ör. XFD1) eskiden her satırda 16.384 hücre oluşturup PHP belleğini tüketiyordu.
        $sonKolon = 30;
        $sonSatir = LUMEN_EXCEL_EN_FAZLA_SATIR + 1;
        if ($uzanti === 'csv') {
            $satirSayisi = 0;
            $fh = fopen($yol, 'rb');
            while ($fh !== false && fgets($fh) !== false) {
                if (++$satirSayisi > $sonSatir) {
                    fclose($fh);
                    throw new RuntimeException('Dosyada en fazla ' . LUMEN_EXCEL_EN_FAZLA_SATIR . ' satır olabilir.');
                }
            }
            if ($fh !== false) {
                fclose($fh);
            }
        } else {
            foreach (array_slice($okuyucu->listWorksheetInfo($yol), 0, 1) as $bilgi) {
                if ((int) ($bilgi['totalRows'] ?? 0) > $sonSatir) {
                    throw new RuntimeException('Dosyada en fazla ' . LUMEN_EXCEL_EN_FAZLA_SATIR . ' satır olabilir.');
                }
            }
        }
        $okuyucu->setReadFilter(new class ($sonKolon, $sonSatir) implements PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public function __construct(private int $kolon, private int $satir)
            {
            }

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->satir && PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= $this->kolon;
            }
        });
        $sayfa = $okuyucu->load($yol)->getSheet(0);
        $norm = static fn(string $s): string => preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower(trim(str_replace('*', '', $s)), 'UTF-8'),
            ['ı' => 'i', 'ğ' => 'g', 'ü' => 'u', 'ş' => 's', 'ö' => 'o', 'ç' => 'c', 'i̇' => 'i']));
        $kolonlar = lumen_excel_sablon_kolonlari($tur);
        $baslikHarita = [];
        foreach ($kolonlar as $alan => [$baslik]) {
            $baslikHarita[$norm($baslik)] = $alan;
        }
        $satirlar = [];
        $eslesme = [];
        foreach ($sayfa->getRowIterator() as $satir) {
            $no = $satir->getRowIndex();
            $hucreler = [];
            $hucreIt = $satir->getCellIterator('A', PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($sonKolon));
            $hucreIt->setIterateOnlyExistingCells(true);
            foreach ($hucreIt as $h) {
                $deger = $h->getValue();
                $hucreler[$h->getColumn()] = is_float($deger) && floor($deger) === $deger && abs($deger) < 1e15 ? (string) (int) $deger : trim((string) $deger);
            }
            if ($no === 1) {
                foreach ($hucreler as $kol => $b) {
                    if (isset($baslikHarita[$norm($b)])) {
                        $eslesme[$kol] = $baslikHarita[$norm($b)];
                    }
                }
                continue;
            }
            if (implode('', $hucreler) === '') {
                continue;
            }
            if (count($satirlar) >= LUMEN_EXCEL_EN_FAZLA_SATIR) {
                throw new RuntimeException('Dosyada en fazla ' . LUMEN_EXCEL_EN_FAZLA_SATIR . ' satır olabilir.');
            }
            $v = [];
            foreach ($eslesme as $kol => $alan) {
                $v[$alan] = (string) ($hucreler[$kol] ?? '');
            }
            $satirlar[] = ['no' => $no, 'v' => $v];
        }
        $eksik = [];
        foreach ($kolonlar as $alan => [$baslik, $zorunlu]) {
            if ($zorunlu && !in_array($alan, $eslesme, true)) {
                $eksik[] = $baslik;
            }
        }
        return ['satirlar' => $satirlar, 'eksik' => $eksik];
    }
}

if (!function_exists('lumen_excel_dogrula')) {
    /**
     * Satırları doğrular ve normalleştirir.
     * @return array{yeni: list<array>, guncel: list<array>, hatalar: list<array{0:int,1:string}>}
     */
    function lumen_excel_dogrula(PDO $dbh, string $tur, array $satirlar, bool $guncelle): array
    {
        $tablo = $tur === 'cari' ? 'LM_CARI' : 'LM_STOK';
        $mevcut = array_change_key_case($dbh->query("SELECT KOD, ID FROM dbo.{$tablo}")->fetchAll(PDO::FETCH_KEY_PAIR), CASE_UPPER);
        $barkodlar = $tur === 'stok' ? array_flip($dbh->query('SELECT BARKOD FROM dbo.LM_BARKOD')->fetchAll(PDO::FETCH_COLUMN)) : [];
        $vergiNolar = [];
        if ($tur === 'cari') {
            foreach ($dbh->query('SELECT ID, KOD, UNVAN, VERGI_NO, TC_NO FROM dbo.LM_CARI')->fetchAll(PDO::FETCH_ASSOC) as $cari) {
                foreach ([$cari['VERGI_NO'] ?? '', $cari['TC_NO'] ?? ''] as $no) {
                    $normal = lumen_vergi_no_normalize((string) $no);
                    if ($normal !== '') {
                        $vergiNolar[$normal] = ['id' => (int) $cari['ID'], 'kod' => (string) $cari['KOD'], 'unvan' => (string) $cari['UNVAN']];
                    }
                }
            }
        }
        $yeni = [];
        $guncel = [];
        $hatalar = [];
        $dosyada = [];
        $sayi = static fn(string $s): ?float => $s === '' ? null : (is_numeric(str_replace([' ', ','], ['', '.'], $s)) || preg_match('/^-?[0-9.]+(,[0-9]+)?$/', $s) ? lumen_tutar_coz($s) : NAN);
        foreach ($satirlar as $s) {
            $v = $s['v'];
            $no = (int) $s['no'];
            $kod = mb_strtoupper(trim($v['kod'] ?? ''), 'UTF-8');
            $ad = trim($v[$tur === 'cari' ? 'unvan' : 'ad'] ?? '');
            $hata = static function (string $m) use (&$hatalar, $no): void {
                $hatalar[] = [$no, $m];
            };
            if ($kod === '' || mb_strlen($kod) > 40) {
                $hata('Kod boş ya da 40 karakterden uzun.');
                continue;
            }
            if ($ad === '') {
                $hata(($tur === 'cari' ? 'Unvan' : 'Ad') . ' boş.');
                continue;
            }
            if (isset($dosyada[$kod])) {
                $hata("'{$kod}' kodu dosyada {$dosyada[$kod]}. satırda da var.");
                continue;
            }
            $dosyada[$kod] = $no;
            $var = isset($mevcut[$kod]);
            if ($var && !$guncelle) {
                continue; // mevcut kart: atlanır
            }
            if ($tur === 'cari') {
                $turAd = mb_strtolower(trim($v['tur'] ?? ''), 'UTF-8');
                $cariTur = match (true) {
                    $turAd === '' => 3, str_contains($turAd, '+') || str_contains($turAd, 've') => 3,
                    str_starts_with($turAd, 'alıcı') || str_starts_with($turAd, 'alici') || $turAd === 'müşteri' => 1,
                    str_starts_with($turAd, 'satıcı') || str_starts_with($turAd, 'satici') || $turAd === 'tedarikçi' => 2,
                    default => 0,
                };
                $vno = lumen_vergi_no_normalize((string) ($v['vergi_no'] ?? ''));
                $eposta = trim($v['eposta'] ?? '');
                $bakiye = $sayi((string) ($v['bakiye'] ?? ''));
                $vade = $sayi((string) ($v['vade_gun'] ?? ''));
                if ($cariTur === 0) {
                    $hata("Tür anlaşılamadı: '{$v['tur']}' (Alıcı, Satıcı ya da Alıcı+Satıcı).");
                    continue;
                }
                if ($vno !== '' && preg_match('/^[0-9]{10,11}$/', $vno) !== 1) {
                    $hata('Vergi no 10, TCKN 11 haneli olmalı.');
                    continue;
                }
                if ($vno !== '' && !lumen_vergi_no_gecerli($vno)) {
                    $hata("Vergi no / TCKN geçersiz ({$vno}: kontrol basamağı tutmuyor).");
                    continue;
                }
                $mevcutId = $var ? (int) $mevcut[$kod] : 0;
                if ($vno !== '' && isset($vergiNolar[$vno]) && $vergiNolar[$vno]['id'] !== $mevcutId) {
                    $es = $vergiNolar[$vno];
                    $hata("Vergi no / TCKN [{$es['kod']}] {$es['unvan']} carisinde kullanılıyor.");
                    continue;
                }
                if ($eposta !== '' && filter_var($eposta, FILTER_VALIDATE_EMAIL) === false) {
                    $hata('E-posta geçersiz.');
                    continue;
                }
                if (($bakiye !== null && is_nan($bakiye)) || ($vade !== null && (is_nan($vade) || $vade < 0 || $vade > 365))) {
                    $hata('Açılış bakiyesi ya da vade sayı değil.');
                    continue;
                }
                $kayit = ['kod' => $kod, 'unvan' => mb_substr($ad, 0, 200), 'tur' => $cariTur,
                    'vergi_dairesi' => mb_substr(trim($v['vergi_dairesi'] ?? ''), 0, 80) ?: null,
                    'vergi_no' => strlen($vno) === 10 ? $vno : null, 'tc_no' => strlen($vno) === 11 ? $vno : null,
                    'il' => mb_substr(trim($v['il'] ?? ''), 0, 60) ?: null, 'ilce' => mb_substr(trim($v['ilce'] ?? ''), 0, 60) ?: null,
                    'adres' => mb_substr(trim($v['adres'] ?? ''), 0, 400) ?: null, 'telefon' => mb_substr(trim($v['telefon'] ?? ''), 0, 40) ?: null,
                    'eposta' => $eposta ?: null, 'vade_gun' => (int) ($vade ?? 0), 'bakiye' => round((float) ($bakiye ?? 0), 2)];
                if ($vno !== '') {
                    $vergiNolar[$vno] = ['id' => $mevcutId > 0 ? $mevcutId : -$no, 'kod' => $kod, 'unvan' => $kayit['unvan']];
                }
            } else {
                $kdv = $sayi((string) ($v['kdv'] ?? ''));
                $sf = $sayi((string) ($v['satis_fiyati'] ?? ''));
                $af = $sayi((string) ($v['alis_fiyati'] ?? ''));
                $koli = $sayi((string) ($v['koli_ici'] ?? ''));
                $miktar = $sayi((string) ($v['miktar'] ?? ''));
                foreach (['KDV' => $kdv, 'Satış fiyatı' => $sf, 'Alış fiyatı' => $af, 'Koli içi adet' => $koli, 'Açılış miktarı' => $miktar] as $etiket => $d) {
                    if ($d !== null && is_nan($d)) {
                        $hata("{$etiket} sayı değil.");
                        continue 2;
                    }
                }
                if (($kdv !== null && ($kdv < 0 || $kdv > 100)) || ($sf !== null && $sf < 0) || ($af !== null && $af < 0) || ($koli !== null && $koli <= 1)) {
                    $hata('KDV 0–100, fiyatlar sıfır ya da pozitif, koli içi adet 1\'den büyük olmalı.');
                    continue;
                }
                $barkod = trim($v['barkod'] ?? '');
                if ($barkod !== '' && (isset($barkodlar[$barkod]) && !$var)) {
                    $hata("'{$barkod}' barkodu başka bir üründe kayıtlı.");
                    continue;
                }
                if ($barkod !== '') {
                    $barkodlar[$barkod] = true;
                }
                $birim = mb_strtoupper(trim($v['birim'] ?? ''), 'UTF-8') ?: 'ADET';
                if (mb_strlen($birim) > 20) {
                    $hata('Birim kodu 20 karakterden uzun.');
                    continue;
                }
                $kayit = ['kod' => $kod, 'ad' => mb_substr($ad, 0, 200), 'birim' => $birim, 'kdv' => $kdv ?? 20.0,
                    'grup' => mb_substr(trim($v['grup'] ?? ''), 0, 40) ?: null, 'satis' => $sf, 'alis' => $af,
                    'barkod' => $barkod !== '' ? mb_substr($barkod, 0, 60) : null, 'koli' => $koli, 'miktar' => round((float) ($miktar ?? 0), 6)];
            }
            if ($var) {
                $kayit['id'] = (int) $mevcut[$kod];
                $guncel[] = $kayit;
            } else {
                $yeni[] = $kayit;
            }
        }
        return ['yeni' => $yeni, 'guncel' => $guncel, 'hatalar' => $hatalar];
    }
}

if (!function_exists('lumen_excel_yaz')) {
    /**
     * Doğrulanmış kayıtları tek işlemde yazar. Açılışlar yalnız yeni kartlar için.
     * @return array{ok:bool, mesaj:string}
     */
    function lumen_excel_yaz(PDO $dbh, string $tur, array $d, string $tarih): array
    {
        try {
            $dbh->beginTransaction();
            if ($tur === 'cari') {
                $ekle = 'INSERT INTO dbo.LM_CARI (KOD, UNVAN, TUR, VERGI_DAIRESI, VERGI_NO, TC_NO, IL, ILCE, ADRES, TELEFON, EPOSTA, VADE_GUN)
                         VALUES (:kod, :unvan, :tur, :vd, :vno, :tc, :il, :ilce, :adres, :tel, :eposta, :vade)';
                $guncelle = $dbh->prepare('UPDATE dbo.LM_CARI SET UNVAN = :unvan, TUR = :tur, VERGI_DAIRESI = :vd, VERGI_NO = :vno, TC_NO = :tc,
                    IL = :il, ILCE = :ilce, ADRES = :adres, TELEFON = :tel, EPOSTA = :eposta, VADE_GUN = :vade, GUNCELLEME = SYSDATETIME() WHERE ID = :id');
                $devir = $dbh->prepare("INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, KAYNAK, BELGE_NO, BORC, ALACAK, ACIKLAMA)
                                        VALUES (:c, :t, 4, N'DEVIR', :b, :a, N'Excel açılış bakiyesi')");
                $p = static fn(array $k): array => [':unvan' => $k['unvan'], ':tur' => $k['tur'], ':vd' => $k['vergi_dairesi'], ':vno' => $k['vergi_no'],
                    ':tc' => $k['tc_no'], ':il' => $k['il'], ':ilce' => $k['ilce'], ':adres' => $k['adres'], ':tel' => $k['telefon'],
                    ':eposta' => $k['eposta'], ':vade' => $k['vade_gun']];
                foreach ($d['yeni'] as $k) {
                    $id = lumen_insert_kimlik($dbh, $ekle, $p($k) + [':kod' => $k['kod']], 'ID');
                    if (abs($k['bakiye']) >= 0.005) {
                        $devir->execute([':c' => $id, ':t' => $tarih, ':b' => max(0, $k['bakiye']), ':a' => max(0, -$k['bakiye'])]);
                    }
                }
                foreach ($d['guncel'] as $k) {
                    $guncelle->execute($p($k) + [':id' => $k['id']]);
                }
            } else {
                $birimler = array_change_key_case($dbh->query('SELECT KOD, ID FROM dbo.LM_BIRIM')->fetchAll(PDO::FETCH_KEY_PAIR), CASE_UPPER);
                $birimId = static function (string $kod) use (&$birimler, $dbh): int {
                    return (int) ($birimler[$kod] ??= lumen_insert_kimlik($dbh, 'INSERT INTO dbo.LM_BIRIM (KOD, AD) VALUES (:k, :a)',
                        [':k' => $kod, ':a' => mb_convert_case(mb_strtolower($kod, 'UTF-8'), MB_CASE_TITLE, 'UTF-8')], 'ID'));
                };
                $sb = $dbh->prepare('INSERT INTO dbo.LM_STOK_BIRIM (STOK_ID, BIRIM_ID, CARPAN) VALUES (:s, :b, :c)');
                $fiyat = $dbh->prepare('INSERT INTO dbo.LM_FIYAT (STOK_ID, TUR, BIRIM_ID, FIYAT, BASLANGIC) VALUES (:s, :t, :b, :f, :bas)');
                $barkod = $dbh->prepare('INSERT INTO dbo.LM_BARKOD (STOK_ID, BIRIM_ID, BARKOD) VALUES (:s, :b, :k)');
                $guncelle = $dbh->prepare('UPDATE dbo.LM_STOK SET AD = :ad, KDV_ORANI = :kdv, GRUP = :grup, GUNCELLEME = SYSDATETIME() WHERE ID = :id');
                $acilis = [];
                foreach ($d['yeni'] as $k) {
                    $ana = $birimId($k['birim']);
                    $id = lumen_insert_kimlik($dbh, 'INSERT INTO dbo.LM_STOK (KOD, AD, GRUP, ANA_BIRIM_ID, KDV_ORANI) VALUES (:k, :a, :g, :b, :kdv)',
                        [':k' => $k['kod'], ':a' => $k['ad'], ':g' => $k['grup'], ':b' => $ana, ':kdv' => $k['kdv']], 'ID');
                    $sb->execute([':s' => $id, ':b' => $ana, ':c' => 1]);
                    if ($k['koli'] !== null && $ana !== $birimId('KOLI')) {
                        $sb->execute([':s' => $id, ':b' => $birimId('KOLI'), ':c' => $k['koli']]);
                    }
                    foreach ([1 => $k['satis'], 2 => $k['alis']] as $t => $f) {
                        if ($f !== null) {
                            $fiyat->execute([':s' => $id, ':t' => $t, ':b' => $ana, ':f' => round($f, 4), ':bas' => $tarih]);
                        }
                    }
                    if ($k['barkod'] !== null) {
                        $barkod->execute([':s' => $id, ':b' => $ana, ':k' => $k['barkod']]);
                    }
                    if (abs($k['miktar']) > 0.000001) {
                        $acilis[$id] = $k['miktar'];
                    }
                }
                foreach ($d['guncel'] as $k) {
                    $guncelle->execute([':ad' => $k['ad'], ':kdv' => $k['kdv'], ':grup' => $k['grup'], ':id' => $k['id']]);
                }
                if ($acilis !== []) {
                    $depo = (int) $dbh->query('SELECT TOP 1 ID FROM dbo.LM_DEPO WHERE AKTIF = 1 ORDER BY NO')->fetchColumn();
                    $belge = lumen_insert_kimlik($dbh, 'INSERT INTO dbo.LM_BELGE (TUR, YON, NO, TARIH, DEPO_ID, ACIKLAMA, DURUM) VALUES (6, 2, :no, :t, :d, :a, 1)',
                        [':no' => lumen_belge_seri_no($dbh, 'SAYIM'), ':t' => $tarih, ':d' => $depo, ':a' => 'Excel açılış stoku'], 'ID');
                    $har = $dbh->prepare('INSERT INTO dbo.LM_STOK_HAREKET (STOK_ID, DEPO_ID, TARIH, BELGE_ID, GIRIS, CIKIS) VALUES (:s, :d, :t, :b, :g, :c)');
                    foreach ($acilis as $id => $m) {
                        $har->execute([':s' => $id, ':d' => $depo, ':t' => $tarih, ':b' => $belge, ':g' => max(0, $m), ':c' => max(0, -$m)]);
                    }
                }
            }
            $dbh->commit();
            $ne = $tur === 'cari' ? 'cari' : 'ürün';
            return ['ok' => true, 'mesaj' => count($d['yeni']) . " yeni {$ne} eklendi" . ($d['guncel'] !== [] ? ', ' . count($d['guncel']) . ' güncellendi' : '') . '.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('lumen_excel_yaz: ' . $e->getMessage());
            return ['ok' => false, 'mesaj' => 'Aktarma yapılamadı; hiçbir kayıt eklenmedi.'];
        }
    }
}

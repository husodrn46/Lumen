<?php

declare(strict_types=1);

/**
 * LOGO Tiger → bağımsız Lumen içe aktarma (geçiş aracı). CLI: scripts/logo_aktar.php
 *
 * Ana veri birebir, hareketler açılış (devir) olarak aktarılır:
 *   personel   LG_SLSMAN                       → LM_PERSONEL (kimlik korunur)
 *   cari       LG_fff_CLCARD                   → LM_CARI (kimlik korunur)
 *   stok       ITEMS, UNITSETL, ITMUNITA,
 *              UNITBARCODE, PRCLIST            → LM_STOK (kimlik korunur), LM_BIRIM, LM_STOK_BIRIM,
 *                                                LM_BARKOD, LM_FIYAT (geçerli son fiyat)
 *   depo       L_CAPIWHOUSE (varsa)            → LM_DEPO (ambar no)
 *   cari bakiye LV_fff_dd_GNTOTCL (yoksa CLFLINE) → LM_CARI_HAREKET (KAYNAK 4, devir)
 *   stok miktarı LV_fff_dd_STINVTOT             → ambar başına bir açılış sayım belgesi
 *   kasa       KSCARD + KSLINES                → LM_FINANS_HESAP + açılış fişi
 *   banka      BANKACC (+ BNFLINE varsa)       → LM_FINANS_HESAP + açılış fişi
 *   çek/senet  portföydeki müşteri çekleri ve
 *              ödenmemiş kendi çeklerimiz      → LM_CEK + açılış hareketi (cari etkisi devirde)
 *   Lumen      M_P_YETKI, M_OZEL_CARI_KISIT, M_USER_SETTINGS (kişi/cari kimlikleri korunduğu
 *              için olduğu gibi; parolalar ve yetkiler aynı kalır)
 *
 * Geçmiş belgeler (fatura, sipariş, kasa hareketleri) aktarılmaz; LOGO'da okunur kalır.
 * Her şey tek işlemde yazılır; sonunda kaynak ve hedef toplamları karşılaştırılır,
 * tutmazsa ya da deneme kipindeyse işlem geri alınır.
 */

require_once __DIR__ . '/../belge/belge_lib.php';

if (!function_exists('logo_aktar')) {
    /**
     * @param array{firma:int, donem:int, tarih:string, uygula:bool} $o
     * @param callable(string): void $yaz ilerleme satırı
     * @return array{ok:bool, uygulandi:bool, sayilar:array<string,int>, karsilastirma:array<string,array{0:float,1:float}>, uyarilar:string[], mesaj:string}
     */
    function logo_aktar(PDO $k, PDO $h, array $o, callable $yaz): array
    {
        $firma = (int) $o['firma'];
        $donem = (int) $o['donem'];
        $tarih = (string) $o['tarih'];
        if ($firma < 1 || $firma > 999 || $donem < 1 || $donem > 99 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) !== 1) {
            throw new InvalidArgumentException('Geçersiz firma/dönem/tarih.');
        }
        $f = sprintf('LG_%03d_', $firma);
        $fd = sprintf('LG_%03d_%02d_', $firma, $donem);
        $lv = sprintf('LV_%03d_%02d_', $firma, $donem);
        $sayilar = [];
        $uyarilar = [];

        $kolonOnbellek = [];
        $kolonlar = static function (string $tablo) use ($k, &$kolonOnbellek): array {
            if (!isset($kolonOnbellek[$tablo])) {
                $st = $k->prepare('SELECT UPPER(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t');
                $st->execute([':t' => $tablo]);
                $kolonOnbellek[$tablo] = $st->fetchAll(PDO::FETCH_COLUMN);
            }
            return $kolonOnbellek[$tablo];
        };
        $var = static fn(string $tablo): bool => $kolonlar($tablo) !== [];
        // Kaynak sürümünde olmayabilecek kolon: varsa takma adla, yoksa varsayılan ifade.
        $kol = static fn(string $tablo, string $takma, string $kolon, string $varsayilan): string
            => in_array(strtoupper($kolon), $kolonlar($tablo), true) ? "{$takma}.{$kolon}" : $varsayilan;
        $metin = static fn(mixed $v, int $uzunluk): ?string
            => ($s = trim((string) $v)) === '' ? null : mb_substr($s, 0, $uzunluk);

        foreach (['CLCARD', 'ITEMS', 'UNITSETL', 'ITMUNITA'] as $t) {
            if (!$var($f . $t)) {
                throw new RuntimeException("Kaynakta {$f}{$t} yok: firma numarası doğru mu?");
            }
        }
        if ((int) $h->query("SELECT CASE WHEN OBJECT_ID('dbo.LM_CARI', 'U') IS NULL THEN 1 ELSE 0 END")->fetchColumn() === 1) {
            throw new RuntimeException('Hedefte Lumen şeması yok (önce --sema ile kurun).');
        }
        $dolu = $h->query("SELECT (SELECT COUNT(*) FROM dbo.LM_PERSONEL) + (SELECT COUNT(*) FROM dbo.LM_CARI)
                                + (SELECT COUNT(*) FROM dbo.LM_STOK) + (SELECT COUNT(*) FROM dbo.LM_BELGE)
                                + (SELECT COUNT(*) FROM dbo.LM_FINANS_FIS) + (SELECT COUNT(*) FROM dbo.LM_CEK)")->fetchColumn();
        if ((int) $dolu > 0) {
            throw new RuntimeException('Hedef veritabanı boş değil: içe aktarma yalnız yeni kurulmuş (boş) bir Lumen veritabanına yapılır.');
        }

        $h->beginTransaction();
        try {
            $kimlikliEkle = static function (string $tablo, array $satirlar) use ($h): int {
                if ($satirlar === []) {
                    return 0;
                }
                $kol = array_keys($satirlar[0]);
                $sql = "INSERT INTO dbo.{$tablo} (" . implode(', ', $kol) . ') VALUES (' . implode(', ', array_map(static fn($c) => ':' . $c, $kol)) . ')';
                $h->exec("SET IDENTITY_INSERT dbo.{$tablo} ON");
                try {
                    $st = $h->prepare($sql);
                    foreach ($satirlar as $s) {
                        $st->execute(array_combine(array_map(static fn($c) => ':' . $c, $kol), array_values($s)));
                    }
                } finally {
                    $h->exec("SET IDENTITY_INSERT dbo.{$tablo} OFF");
                }
                return count($satirlar);
            };

            // ---------------------------------------------------------------- firma
            $firmaAdi = null;
            if ($var('L_CAPIFIRM')) {
                $st = $k->prepare('SELECT TOP 1 NAME FROM L_CAPIFIRM WHERE NR = :n');
                $st->execute([':n' => $firma]);
                $firmaAdi = $metin($st->fetchColumn(), 200);
            }
            $ayar = $h->prepare("MERGE dbo.LM_AYAR AS H USING (SELECT :a AS ANAHTAR, :d AS DEGER) AS K ON H.ANAHTAR = K.ANAHTAR
                                 WHEN MATCHED THEN UPDATE SET DEGER = K.DEGER WHEN NOT MATCHED THEN INSERT (ANAHTAR, DEGER) VALUES (K.ANAHTAR, K.DEGER);");
            $ayar->execute([':a' => 'firma_no', ':d' => (string) $firma]);
            if ($firmaAdi !== null) {
                $ayar->execute([':a' => 'firma_unvan', ':d' => $firmaAdi]);
            }
            $yaz('firma: ' . ($firmaAdi ?? "(L_CAPIFIRM'de ad yok)"));

            // ---------------------------------------------------------------- depolar
            $depoEkle = $h->prepare('INSERT INTO dbo.LM_DEPO (KOD, AD, NO) VALUES (:k, :a, :n)');
            $depoMap = static function () use ($h): array {
                return $h->query('SELECT NO, ID FROM dbo.LM_DEPO')->fetchAll(PDO::FETCH_KEY_PAIR);
            };
            $depolar = $depoMap();
            $kaynakDepolar = [];
            if ($var('L_CAPIWHOUSE')) {
                $st = $k->prepare('SELECT NR, NAME FROM L_CAPIWHOUSE WHERE FIRMNR = :f');
                $st->execute([':f' => $firma]);
                $kaynakDepolar = $st->fetchAll(PDO::FETCH_KEY_PAIR);
            }
            if ($var($lv . 'STINVTOT')) {
                foreach ($k->query("SELECT DISTINCT INVENNO FROM {$lv}STINVTOT WHERE INVENNO >= 0")->fetchAll(PDO::FETCH_COLUMN) as $no) {
                    $kaynakDepolar[(int) $no] ??= null;
                }
            }
            $yeniDepo = 0;
            foreach ($kaynakDepolar as $no => $ad) {
                $no = (int) $no;
                if (isset($depolar[$no])) {
                    if ($ad !== null) {
                        $h->prepare('UPDATE dbo.LM_DEPO SET AD = :a WHERE NO = :n')->execute([':a' => $metin($ad, 80), ':n' => $no]);
                    }
                    continue;
                }
                $depoEkle->execute([':k' => 'DEPO' . $no, ':a' => $metin($ad, 80) ?? "Ambar {$no}", ':n' => $no]);
                $yeniDepo++;
            }
            $depolar = $depoMap();
            $sayilar['depo'] = count($depolar);
            $yaz("depo: {$sayilar['depo']} ({$yeniDepo} yeni)");

            // ---------------------------------------------------------------- personel
            $yetkili = $var('M_P_YETKI') ? $k->query('SELECT PERSONEL FROM M_P_YETKI')->fetchAll(PDO::FETCH_COLUMN) : [];
            $slsKosul = in_array('FIRMNR', $kolonlar('LG_SLSMAN'), true)
                ? 'WHERE S.FIRMNR = ' . $firma . ($yetkili !== [] ? ' OR S.LOGICALREF IN (' . implode(',', array_map('intval', $yetkili)) . ')' : '')
                : '';
            $personel = [];
            $kodlar = [];
            $firmaKolonu = in_array('FIRMNR', $kolonlar('LG_SLSMAN'), true);
            foreach ($k->query("SELECT S.LOGICALREF, S.CODE, S.DEFINITION_, S.ACTIVE, " . ($firmaKolonu ? 'S.FIRMNR' : $firma) . ' AS FIRMNR, '
                    . $kol('LG_SLSMAN', 'S', 'SPECODE', 'NULL') . ' AS SPECODE, '
                    . $kol('LG_SLSMAN', 'S', 'CYPHCODE', 'NULL') . " AS CYPHCODE FROM LG_SLSMAN S {$slsKosul} ORDER BY S.LOGICALREF") as $r) {
                $kod = $metin($r['CODE'], 40) ?? ('P' . $r['LOGICALREF']);
                if (isset($kodlar[mb_strtoupper($kod)])) {
                    $uyarilar[] = "personel kodu tekrarı '{$kod}' (ref {$r['LOGICALREF']}): '{$kod}-{$r['LOGICALREF']}' olarak alındı";
                    $kod = mb_substr($kod, 0, 30) . '-' . $r['LOGICALREF'];
                }
                $kodlar[mb_strtoupper($kod)] = true;
                $ambar = trim((string) $r['SPECODE']);
                $personel[] = ['ID' => (int) $r['LOGICALREF'], 'KOD' => $kod, 'AD' => $metin($r['DEFINITION_'], 120) ?? $kod,
                    // Başka firmanın kullanıcısı (yalnız yetki kaydı için alınır) LOGO'da bu firmaya giremezdi: pasif gelir.
                    'AKTIF' => (int) $r['ACTIVE'] === 0 && (int) $r['FIRMNR'] === $firma ? 1 : 0,
                    'DEPO_ID' => ctype_digit($ambar) && isset($depolar[(int) $ambar]) ? (int) $depolar[(int) $ambar] : null,
                    'YETKI_KODU' => $metin($r['CYPHCODE'], 20)];
            }
            $sayilar['personel'] = $kimlikliEkle('LM_PERSONEL', $personel);
            $yaz("personel: {$sayilar['personel']}");

            // ---------------------------------------------------------------- cari
            $pb = $h->query('SELECT KOD FROM dbo.LM_PARA_BIRIMI')->fetchAll(PDO::FETCH_COLUMN);
            $doviz = static fn(int $kod): string => match ($kod) { 1 => 'USD', 20 => 'EUR', 17 => 'GBP', default => 'TRY' };
            $c = $f . 'CLCARD';
            $cariler = [];
            foreach ($k->query("SELECT C.LOGICALREF, C.CODE, C.DEFINITION_, C.ACTIVE, " . $kol($c, 'C', 'CARDTYPE', '3') . ' AS CARDTYPE, '
                    . $kol($c, 'C', 'TAXNR', 'NULL') . ' AS TAXNR, ' . $kol($c, 'C', 'TAXOFFICE', 'NULL') . ' AS TAXOFFICE, '
                    . $kol($c, 'C', 'TCKNO', 'NULL') . ' AS TCKNO, ' . $kol($c, 'C', 'CITY', 'NULL') . ' AS CITY, '
                    . $kol($c, 'C', 'TOWN', 'NULL') . ' AS TOWN, ' . $kol($c, 'C', 'ADDR1', "''") . ' AS ADDR1, '
                    . $kol($c, 'C', 'ADDR2', "''") . ' AS ADDR2, ' . $kol($c, 'C', 'TELNRS1', 'NULL') . ' AS TELNRS1, '
                    . $kol($c, 'C', 'EMAILADDR', 'NULL') . ' AS EMAILADDR, ' . $kol($c, 'C', 'SPECODE', 'NULL') . ' AS SPECODE, '
                    . $kol($c, 'C', 'CCURRENCY', '0') . " AS CCURRENCY FROM {$c} C ORDER BY C.LOGICALREF") as $r) {
                $kod = $metin($r['CODE'], 40);
                if ($kod === null) {
                    $uyarilar[] = "kodsuz cari atlandı (ref {$r['LOGICALREF']})";
                    continue;
                }
                $para = $doviz((int) $r['CCURRENCY']);
                $cariler[] = ['ID' => (int) $r['LOGICALREF'], 'KOD' => $kod, 'UNVAN' => $metin($r['DEFINITION_'], 200) ?? $kod,
                    'TUR' => in_array((int) $r['CARDTYPE'], [1, 2, 3], true) ? (int) $r['CARDTYPE'] : 3,
                    'VERGI_NO' => $metin($r['TAXNR'], 20), 'VERGI_DAIRESI' => $metin($r['TAXOFFICE'], 80), 'TC_NO' => $metin($r['TCKNO'], 11),
                    'IL' => $metin($r['CITY'], 60), 'ILCE' => $metin($r['TOWN'], 60),
                    'ADRES' => $metin(trim($r['ADDR1'] . ' ' . $r['ADDR2']), 400), 'TELEFON' => $metin($r['TELNRS1'], 40),
                    'EPOSTA' => $metin($r['EMAILADDR'], 160), 'OZEL_KOD' => $metin($r['SPECODE'], 40),
                    'PARA_BIRIMI' => in_array($para, $pb, true) ? $para : 'TRY', 'AKTIF' => (int) $r['ACTIVE'] === 0 ? 1 : 0];
            }
            $sayilar['cari'] = $kimlikliEkle('LM_CARI', $cariler);
            $cariVar = array_fill_keys(array_column($cariler, 'ID'), true);
            $yaz("cari: {$sayilar['cari']}");

            // ---------------------------------------------------------------- birimler ve stok
            $birimler = array_change_key_case($h->query('SELECT KOD, ID FROM dbo.LM_BIRIM')->fetchAll(PDO::FETCH_KEY_PAIR), CASE_UPPER);
            $birimId = static function (?string $kod, ?string $ad) use (&$birimler, $h): int {
                $kod = mb_strtoupper(mb_substr(trim((string) $kod), 0, 20)) ?: 'ADET';
                if (!isset($birimler[$kod])) {
                    $birimler[$kod] = lumen_insert_kimlik($h, 'INSERT INTO dbo.LM_BIRIM (KOD, AD) VALUES (:k, :a)',
                        [':k' => $kod, ':a' => mb_substr(trim((string) $ad) ?: $kod, 0, 60)], 'ID');
                }
                return (int) $birimler[$kod];
            };
            // Birim seti satırları: LOGICALREF → [kod, ad]
            $setSatir = [];
            foreach ($k->query("SELECT LOGICALREF, CODE, NAME, UNITSETREF, MAINUNIT FROM {$f}UNITSETL") as $r) {
                $setSatir[(int) $r['LOGICALREF']] = $r;
            }
            $anaBirim = [];
            foreach ($setSatir as $r) {
                if ((int) $r['MAINUNIT'] === 1) {
                    $anaBirim[(int) $r['UNITSETREF']] = $r;
                }
            }
            $it = $f . 'ITEMS';
            $stoklar = [];
            $stokAna = [];
            foreach ($k->query("SELECT I.LOGICALREF, I.CODE, I.NAME, I.ACTIVE, " . $kol($it, 'I', 'CARDTYPE', '1') . ' AS CARDTYPE, '
                    . $kol($it, 'I', 'UNITSETREF', '0') . ' AS UNITSETREF, ' . $kol($it, 'I', 'STGRPCODE', 'NULL') . ' AS STGRPCODE, '
                    . $kol($it, 'I', 'SPECODE', 'NULL') . ' AS SPECODE, ' . $kol($it, 'I', 'VAT', '20') . ' AS VAT, '
                    . $kol($it, 'I', 'SELLVAT', 'NULL') . " AS SELLVAT FROM {$it} I ORDER BY I.LOGICALREF") as $r) {
                if (in_array((int) $r['CARDTYPE'], [20, 21, 22], true)) {
                    continue; // malzeme sınıfları kart değildir
                }
                $kod = $metin($r['CODE'], 40);
                if ($kod === null) {
                    $uyarilar[] = "kodsuz stok atlandı (ref {$r['LOGICALREF']})";
                    continue;
                }
                $ana = $anaBirim[(int) $r['UNITSETREF']] ?? null;
                $anaId = $birimId($ana['CODE'] ?? 'ADET', $ana['NAME'] ?? 'Adet');
                $kdv = (float) ($r['SELLVAT'] ?? 0) > 0 ? (float) $r['SELLVAT'] : (float) ($r['VAT'] ?? 20);
                $stoklar[] = ['ID' => (int) $r['LOGICALREF'], 'KOD' => $kod, 'AD' => $metin($r['NAME'], 200) ?? $kod,
                    'GRUP' => $metin($r['STGRPCODE'], 40), 'OZEL_KOD' => $metin($r['SPECODE'], 40), 'ANA_BIRIM_ID' => $anaId,
                    'KDV_ORANI' => $kdv, 'AKTIF' => (int) $r['ACTIVE'] === 0 ? 1 : 0];
                $stokAna[(int) $r['LOGICALREF']] = $anaId;
            }
            $sayilar['stok'] = $kimlikliEkle('LM_STOK', $stoklar);
            $yaz("stok: {$sayilar['stok']}");

            $ia = $f . 'ITMUNITA';
            $sbEkle = $h->prepare('INSERT INTO dbo.LM_STOK_BIRIM (STOK_ID, BIRIM_ID, CARPAN, AGIRLIK_KG, UZUNLUK_MM, GENISLIK_MM, YUKSEKLIK_MM)
                                   VALUES (:s, :b, :c, :w, :l, :g, :y)');
            $stokBirim = [];
            foreach ($k->query("SELECT A.ITEMREF, A.UNITLINEREF, A.CONVFACT1, A.CONVFACT2, " . $kol($ia, 'A', 'WEIGHT', '0') . ' AS WEIGHT, '
                    . $kol($ia, 'A', 'LENGTH', '0') . ' AS LENGTH, ' . $kol($ia, 'A', 'WIDTH', '0') . ' AS WIDTH, '
                    . $kol($ia, 'A', 'HEIGHT', '0') . " AS HEIGHT FROM {$ia} A ORDER BY A.ITEMREF, A.LINENR") as $r) {
                $stok = (int) $r['ITEMREF'];
                $satir = $setSatir[(int) $r['UNITLINEREF']] ?? null;
                if (!isset($stokAna[$stok]) || $satir === null) {
                    continue;
                }
                $b = $birimId($satir['CODE'], $satir['NAME']);
                if (isset($stokBirim[$stok][$b])) {
                    continue;
                }
                $carpan = $b === $stokAna[$stok] ? 1.0
                    : ((float) $r['CONVFACT1'] > 0 ? (float) $r['CONVFACT2'] / (float) $r['CONVFACT1'] : (float) $r['CONVFACT2']);
                if ($carpan <= 0) {
                    $uyarilar[] = "stok {$stok} birimi {$satir['CODE']}: çarpan geçersiz, atlandı";
                    continue;
                }
                $pozitif = static fn($v): ?float => (float) $v > 0 ? round((float) $v, 4) : null;
                $sbEkle->execute([':s' => $stok, ':b' => $b, ':c' => round($carpan, 6), ':w' => $pozitif($r['WEIGHT']),
                    ':l' => $pozitif($r['LENGTH']), ':g' => $pozitif($r['WIDTH']), ':y' => $pozitif($r['HEIGHT'])]);
                $stokBirim[$stok][$b] = true;
            }
            foreach ($stokAna as $stok => $b) {
                if (!isset($stokBirim[$stok][$b])) {
                    $sbEkle->execute([':s' => $stok, ':b' => $b, ':c' => 1, ':w' => null, ':l' => null, ':g' => null, ':y' => null]);
                    $stokBirim[$stok][$b] = true;
                }
            }
            $sayilar['stok_birim'] = array_sum(array_map('count', $stokBirim));

            $sayilar['barkod'] = 0;
            if ($var($f . 'UNITBARCODE')) {
                $bkEkle = $h->prepare('INSERT INTO dbo.LM_BARKOD (STOK_ID, BIRIM_ID, BARKOD) VALUES (:s, :b, :k)');
                $gorulen = [];
                foreach ($k->query("SELECT ITEMREF, UNITLINEREF, BARCODE FROM {$f}UNITBARCODE ORDER BY LOGICALREF") as $r) {
                    $stok = (int) $r['ITEMREF'];
                    $kod = $metin($r['BARCODE'], 60);
                    if (!isset($stokAna[$stok]) || $kod === null || isset($gorulen[$kod])) {
                        continue;
                    }
                    $satir = $setSatir[(int) $r['UNITLINEREF']] ?? null;
                    $b = $satir !== null ? $birimId($satir['CODE'], $satir['NAME']) : $stokAna[$stok];
                    $bkEkle->execute([':s' => $stok, ':b' => isset($stokBirim[$stok][$b]) ? $b : $stokAna[$stok], ':k' => $kod]);
                    $gorulen[$kod] = true;
                    $sayilar['barkod']++;
                }
            }

            $sayilar['fiyat'] = 0;
            if ($var($f . 'PRCLIST')) {
                $p = $f . 'PRCLIST';
                $kosul = ['P.PTYPE IN (1, 2)', 'ISNULL(P.ACTIVE, 0) = 0'];
                foreach (['CLIENTCODE', 'CLSPECODE'] as $kk) {
                    if (in_array($kk, $kolonlar($p), true)) {
                        $kosul[] = "ISNULL(P.{$kk}, '') = ''"; // cariye özel fiyatlar aktarılmaz
                    }
                }
                if (in_array('ENDDATE', $kolonlar($p), true)) {
                    $kosul[] = "(P.ENDDATE IS NULL OR P.ENDDATE >= '{$tarih}')";
                }
                $sonFiyat = [];
                foreach ($k->query('SELECT P.CARDREF, P.PTYPE, P.PRICE, ' . $kol($p, 'P', 'UOMREF', '0') . ' AS UOMREF, '
                        . $kol($p, 'P', 'CURRENCY', '0') . ' AS CURRENCY, ' . $kol($p, 'P', 'INCVAT', '0') . ' AS INCVAT, '
                        . $kol($p, 'P', 'BEGDATE', 'NULL') . " AS BEGDATE FROM {$p} P WHERE " . implode(' AND ', $kosul)
                        . ' ORDER BY ' . (in_array('BEGDATE', $kolonlar($p), true) ? 'P.BEGDATE, ' : '') . 'P.LOGICALREF') as $r) {
                    $stok = (int) $r['CARDREF'];
                    if (!isset($stokAna[$stok]) || (float) $r['PRICE'] < 0) {
                        continue;
                    }
                    $satir = $setSatir[(int) $r['UOMREF']] ?? null;
                    $b = $satir !== null ? $birimId($satir['CODE'], $satir['NAME']) : $stokAna[$stok];
                    $b = isset($stokBirim[$stok][$b]) ? $b : $stokAna[$stok];
                    $para = $doviz((int) $r['CURRENCY']);
                    $tur = (int) $r['PTYPE'] === 1 ? 2 : 1;
                    // Aynı stok/tür/birim/para için en son başlayan geçerli fiyat kalır.
                    $sonFiyat["{$stok}|{$tur}|{$b}|{$para}"] = [$stok, $tur, $b, (float) $r['PRICE'], in_array($para, $pb, true) ? $para : 'TRY',
                        (int) $r['INCVAT'] === 1 ? 1 : 0, $r['BEGDATE'] !== null ? substr((string) $r['BEGDATE'], 0, 10) : $tarih];
                }
                $fEkle = $h->prepare('INSERT INTO dbo.LM_FIYAT (STOK_ID, TUR, BIRIM_ID, FIYAT, PARA_BIRIMI, KDV_DAHIL, BASLANGIC) VALUES (?, ?, ?, ?, ?, ?, ?)');
                foreach ($sonFiyat as $v) {
                    $fEkle->execute($v);
                    $sayilar['fiyat']++;
                }
            }
            $yaz("stok birimi: {$sayilar['stok_birim']}, barkod: {$sayilar['barkod']}, fiyat: {$sayilar['fiyat']}");

            // ---------------------------------------------------------------- açılış: cari bakiyeleri
            $kaynakCari = [];
            if ($var($lv . 'GNTOTCL')) {
                foreach ($k->query("SELECT CARDREF, SUM(DEBIT - CREDIT) AS B FROM {$lv}GNTOTCL WHERE TOTTYP = 1 GROUP BY CARDREF") as $r) {
                    $kaynakCari[(int) $r['CARDREF']] = round((float) $r['B'], 2);
                }
            } elseif ($var($fd . 'CLFLINE')) {
                $iptal = $kol($fd . 'CLFLINE', 'L', 'CANCELLED', '0');
                foreach ($k->query("SELECT L.CLIENTREF, SUM(CASE WHEN L.SIGN = 0 THEN L.AMOUNT ELSE -L.AMOUNT END) AS B
                                    FROM {$fd}CLFLINE L WHERE ISNULL({$iptal}, 0) = 0 GROUP BY L.CLIENTREF") as $r) {
                    $kaynakCari[(int) $r['CLIENTREF']] = round((float) $r['B'], 2);
                }
            } else {
                $uyarilar[] = 'cari bakiyesi için GNTOTCL / CLFLINE yok: cari açılışı yapılmadı';
            }
            $chEkle = $h->prepare("INSERT INTO dbo.LM_CARI_HAREKET (CARI_ID, TARIH, KAYNAK, BELGE_NO, BORC, ALACAK, ACIKLAMA)
                                   VALUES (:c, :t, 4, N'DEVIR', :b, :a, N'LOGO devir bakiyesi')");
            $sayilar['cari_devir'] = 0;
            $kaynakCariToplam = 0.0;
            foreach ($kaynakCari as $cari => $b) {
                if (!isset($cariVar[$cari])) {
                    if (abs($b) >= 0.005) {
                        $uyarilar[] = "kartı olmayan cari bakiyesi atlandı (ref {$cari}: {$b})";
                    }
                    continue;
                }
                $kaynakCariToplam += $b;
                if (abs($b) < 0.005) {
                    continue;
                }
                $chEkle->execute([':c' => $cari, ':t' => $tarih, ':b' => $b > 0 ? $b : 0, ':a' => $b < 0 ? -$b : 0]);
                $sayilar['cari_devir']++;
            }

            // ---------------------------------------------------------------- açılış: stok miktarları
            $kaynakStokToplam = 0.0;
            $sayilar['stok_devir'] = 0;
            if ($var($lv . 'STINVTOT')) {
                $miktarlar = [];
                foreach ($k->query("SELECT STOCKREF, INVENNO, SUM(ONHAND) AS M FROM {$lv}STINVTOT WHERE INVENNO >= 0 GROUP BY STOCKREF, INVENNO") as $r) {
                    $m = round((float) $r['M'], 6);
                    if (!isset($stokAna[(int) $r['STOCKREF']]) || abs($m) < 0.000001) {
                        continue;
                    }
                    $miktarlar[(int) $r['INVENNO']][(int) $r['STOCKREF']] = $m;
                    $kaynakStokToplam += $m;
                }
                $shEkle = $h->prepare('INSERT INTO dbo.LM_STOK_HAREKET (STOK_ID, DEPO_ID, TARIH, BELGE_ID, GIRIS, CIKIS) VALUES (:s, :d, :t, :b, :g, :c)');
                foreach ($miktarlar as $no => $liste) {
                    $depo = (int) $depolar[$no];
                    $belge = lumen_insert_kimlik($h, 'INSERT INTO dbo.LM_BELGE (TUR, YON, NO, TARIH, DEPO_ID, ACIKLAMA, DURUM) VALUES (6, 2, :no, :t, :d, :a, 1)',
                        [':no' => lumen_belge_seri_no($h, 'SAYIM'), ':t' => $tarih, ':d' => $depo, ':a' => "LOGO açılış stoku (ambar {$no})"], 'ID');
                    foreach ($liste as $stok => $m) {
                        $shEkle->execute([':s' => $stok, ':d' => $depo, ':t' => $tarih, ':b' => $belge, ':g' => $m > 0 ? $m : 0, ':c' => $m < 0 ? -$m : 0]);
                        $sayilar['stok_devir']++;
                    }
                }
            } else {
                $uyarilar[] = "{$lv}STINVTOT yok: stok açılışı yapılmadı";
            }
            $yaz("açılış: {$sayilar['cari_devir']} cari bakiyesi, {$sayilar['stok_devir']} stok miktarı");

            // ---------------------------------------------------------------- kasa / banka
            $hesapId = static function (int $tur, string $kod, string $ad, ?string $banka = null) use ($h): int {
                $st = $h->prepare('SELECT ID FROM dbo.LM_FINANS_HESAP WHERE KOD = :k');
                $st->execute([':k' => $kod]);
                if (($id = $st->fetchColumn()) !== false) {
                    $h->prepare('UPDATE dbo.LM_FINANS_HESAP SET TUR = :t, AD = :a, BANKA_ADI = :b WHERE ID = :id')
                        ->execute([':t' => $tur, ':a' => $ad, ':b' => $banka, ':id' => $id]);
                    return (int) $id;
                }
                return lumen_insert_kimlik($h, 'INSERT INTO dbo.LM_FINANS_HESAP (TUR, KOD, AD, BANKA_ADI) VALUES (:t, :k, :a, :b)',
                    [':t' => $tur, ':k' => $kod, ':a' => $ad, ':b' => $banka], 'ID');
            };
            $acilisFis = static function (int $hesap, float $bakiye, string $ack) use ($h, $tarih): void {
                if (abs($bakiye) < 0.005) {
                    return;
                }
                $tutar = round(abs($bakiye), 2);
                $fis = lumen_insert_kimlik($h, 'INSERT INTO dbo.LM_FINANS_FIS (TUR, NO, TARIH, HESAP_ID, TUTAR, ACIKLAMA) VALUES (5, :no, :t, :h, :tu, :a)',
                    [':no' => lumen_belge_seri_no($h, 'FINANS_ACILIS'), ':t' => $tarih, ':h' => $hesap, ':tu' => $tutar, ':a' => $ack], 'ID');
                $h->prepare('INSERT INTO dbo.LM_FINANS_HAREKET (HESAP_ID, TARIH, FIS_ID, GIRIS, CIKIS, ACIKLAMA) VALUES (:h, :t, :f, :g, :c, :a)')
                    ->execute([':h' => $hesap, ':t' => $tarih, ':f' => $fis, ':g' => $bakiye > 0 ? $tutar : 0, ':c' => $bakiye < 0 ? $tutar : 0, ':a' => $ack]);
            };
            $kaynakKasaToplam = 0.0;
            $sayilar['kasa'] = 0;
            if ($var($f . 'KSCARD')) {
                $bakiyeler = [];
                if ($var($fd . 'KSLINES')) {
                    $iptal = $kol($fd . 'KSLINES', 'L', 'CANCELLED', '0');
                    $bakiyeler = $k->query("SELECT L.CARDREF, SUM(CASE WHEN L.SIGN = 0 THEN L.AMOUNT ELSE -L.AMOUNT END)
                                            FROM {$fd}KSLINES L WHERE ISNULL({$iptal}, 0) = 0 GROUP BY L.CARDREF")->fetchAll(PDO::FETCH_KEY_PAIR);
                }
                foreach ($k->query("SELECT LOGICALREF, CODE, NAME FROM {$f}KSCARD WHERE ISNULL(ACTIVE, 0) = 0 ORDER BY LOGICALREF") as $r) {
                    $b = round((float) ($bakiyeler[(int) $r['LOGICALREF']] ?? 0), 2);
                    $id = $hesapId(1, $metin($r['CODE'], 30) ?? 'KASA' . $r['LOGICALREF'], $metin($r['NAME'], 120) ?? 'Kasa');
                    $acilisFis($id, $b, 'LOGO kasa devri');
                    $kaynakKasaToplam += $b;
                    $sayilar['kasa']++;
                }
            }
            $kaynakBankaToplam = 0.0;
            $sayilar['banka'] = 0;
            if ($var($f . 'BANKACC')) {
                $bakiyeler = [];
                if ($var($fd . 'BNFLINE')) {
                    $iptal = $kol($fd . 'BNFLINE', 'L', 'CANCELLED', '0');
                    $bakiyeler = $k->query("SELECT L.BNACCREF, SUM(CASE WHEN L.SIGN = 0 THEN L.AMOUNT ELSE -L.AMOUNT END)
                                            FROM {$fd}BNFLINE L WHERE ISNULL({$iptal}, 0) = 0 GROUP BY L.BNACCREF")->fetchAll(PDO::FETCH_KEY_PAIR);
                } else {
                    $uyarilar[] = "{$fd}BNFLINE yok: banka hesapları bakiyesiz açıldı";
                }
                $bankaAdi = $var($f . 'BNCARD') ? $k->query("SELECT LOGICALREF, DEFINITION_ FROM {$f}BNCARD")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
                foreach ($k->query("SELECT LOGICALREF, CODE, DEFINITION_, " . $kol($f . 'BANKACC', 'A', 'BANKREF', '0')
                        . " AS BANKREF FROM {$f}BANKACC A WHERE ISNULL(A.ACTIVE, 0) = 0 ORDER BY A.LOGICALREF") as $r) {
                    $b = round((float) ($bakiyeler[(int) $r['LOGICALREF']] ?? 0), 2);
                    $id = $hesapId(2, $metin($r['CODE'], 30) ?? 'BANKA' . $r['LOGICALREF'], $metin($r['DEFINITION_'], 120) ?? 'Banka hesabı',
                        $metin($bankaAdi[(int) $r['BANKREF']] ?? null, 80));
                    $acilisFis($id, $b, 'LOGO banka devri');
                    $kaynakBankaToplam += $b;
                    $sayilar['banka']++;
                }
            }
            $yaz("kasa: {$sayilar['kasa']}, banka hesabı: {$sayilar['banka']}");

            // ---------------------------------------------------------------- çek / senet
            // DOC 1 müşteri çeki, 2 müşteri senedi, 3 kendi çekimiz, 4 kendi senedimiz.
            // CURRSTAT 1 portföyde (müşteri), 9 verildi/ödenmedi (kendi).
            $kaynakCekToplam = 0.0;
            $sayilar['cek'] = 0;
            if ($var($fd . 'CSCARD')) {
                $cs = $fd . 'CSCARD';
                $cariSec = $var($fd . 'CSTRANS')
                    ? "(SELECT TOP 1 T.CARDREF FROM {$fd}CSTRANS T WHERE T.CSREF = C.LOGICALREF AND T.CARDMD = 5 ORDER BY T.DATE_ DESC, T.LOGICALREF DESC)"
                    : 'NULL';
                $harEkle = $h->prepare("INSERT INTO dbo.LM_CEK_HAREKET (CEK_ID, TARIH, BORDRO_NO, ISLEM, CARI_ID, ACIKLAMA) VALUES (:c, :t, N'DEVIR', :i, :cari, N'LOGO devir portföyü')");
                $portfoy = [];
                foreach ($k->query("SELECT C.LOGICALREF, C.DOC, C.CURRSTAT, C.PORTFOYNO, " . $kol($cs, 'C', 'NEWSERINO', 'NULL') . ' AS NEWSERINO, '
                        . $kol($cs, 'C', 'SERINO', 'NULL') . ' AS SERINO, ' . $kol($cs, 'C', 'BANKNAME', 'NULL') . ' AS BANKNAME, '
                        . $kol($cs, 'C', 'OWING', 'NULL') . ' AS OWING, C.DUEDATE, C.AMOUNT, ' . $kol($cs, 'C', 'SETDATE', 'NULL') . " AS SETDATE,
                        {$cariSec} AS CARI FROM {$cs} C
                        WHERE ISNULL(" . $kol($cs, 'C', 'CANCELLED', '0') . ', 0) = 0
                          AND ((C.DOC IN (1, 2) AND C.CURRSTAT = 1) OR (C.DOC IN (3, 4) AND C.CURRSTAT = 9))
                        ORDER BY C.LOGICALREF') as $r) {
                    $tutar = round((float) $r['AMOUNT'], 2);
                    if ($tutar <= 0 || $r['DUEDATE'] === null) {
                        $uyarilar[] = "çek {$r['LOGICALREF']}: tutar/vade eksik, atlandı";
                        continue;
                    }
                    $tur = [1 => 1, 2 => 3, 3 => 2, 4 => 4][(int) $r['DOC']];
                    $kendi = in_array($tur, [2, 4], true);
                    $no = $metin($r['PORTFOYNO'], 30);
                    if ($no === null || isset($portfoy[$no])) {
                        $no = 'L' . $r['LOGICALREF'];
                    }
                    $portfoy[$no] = true;
                    $cari = $r['CARI'] !== null && isset($cariVar[(int) $r['CARI']]) ? (int) $r['CARI'] : null;
                    $cekId = lumen_insert_kimlik($h, 'INSERT INTO dbo.LM_CEK (TUR, PORTFOY_NO, SERI_NO, BANKA, BORCLU, VADE, TUTAR, DURUM, ALINAN_CARI_ID, VERILEN_CARI_ID, OLUSTURMA)
                        VALUES (:tur, :p, :s, :b, :o, :v, :t, :d, :ac, :vc, :olu)', [':tur' => $tur, ':p' => $no, ':s' => $metin($r['NEWSERINO'] ?? $r['SERINO'], 40) ?? $metin($r['SERINO'], 40),
                        ':b' => $metin($r['BANKNAME'], 80), ':o' => $metin($r['OWING'], 200), ':v' => substr((string) $r['DUEDATE'], 0, 10),
                        ':t' => $tutar, ':d' => $kendi ? 2 : 1, ':ac' => $kendi ? null : $cari, ':vc' => $kendi ? $cari : null,
                        ':olu' => $r['SETDATE'] !== null ? substr((string) $r['SETDATE'], 0, 19) : $tarih], 'ID');
                    $harEkle->execute([':c' => $cekId, ':t' => $tarih, ':i' => $kendi ? 2 : 1, ':cari' => $cari]);
                    $kaynakCekToplam += $tutar;
                    $sayilar['cek']++;
                }
            }
            $yaz("çek/senet: {$sayilar['cek']}");

            // ---------------------------------------------------------------- Lumen tabloları (kimlikler korunduğu için aynen)
            foreach (['M_P_YETKI', 'M_OZEL_CARI_KISIT', 'M_USER_SETTINGS'] as $t) {
                if (!$var($t)) {
                    continue;
                }
                $st = $h->prepare('SELECT UPPER(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = :t');
                $st->execute([':t' => $t]);
                $ortak = array_values(array_intersect($kolonlar($t), $st->fetchAll(PDO::FETCH_COLUMN)));
                if ($ortak === []) {
                    continue;
                }
                $kimlik = (int) $h->query("SELECT COUNT(*) FROM sys.identity_columns WHERE object_id = OBJECT_ID('dbo.{$t}')")->fetchColumn() > 0;
                $kolSql = implode(', ', array_map(static fn($c) => "[{$c}]", $ortak));
                $satirlar = $k->query("SELECT {$kolSql} FROM dbo.{$t}")->fetchAll(PDO::FETCH_NUM);
                if ($t === 'M_P_YETKI') {
                    $kisiler = array_fill_keys(array_column($personel, 'ID'), true);
                    $i = array_search('PERSONEL', $ortak, true);
                    $satirlar = array_values(array_filter($satirlar, static fn($s) => isset($kisiler[(int) $s[$i]])));
                }
                $h->exec("DELETE FROM dbo.{$t}");
                if ($kimlik) {
                    $h->exec("SET IDENTITY_INSERT dbo.{$t} ON");
                }
                try {
                    $ekle = $h->prepare("INSERT INTO dbo.{$t} ({$kolSql}) VALUES (" . implode(', ', array_fill(0, count($ortak), '?')) . ')');
                    foreach ($satirlar as $s) {
                        $ekle->execute($s);
                    }
                } finally {
                    if ($kimlik) {
                        $h->exec("SET IDENTITY_INSERT dbo.{$t} OFF");
                    }
                }
                $sayilar[strtolower($t)] = count($satirlar);
            }
            $yaz('yetkiler: ' . ($sayilar['m_p_yetki'] ?? 0) . ' kullanıcı');

            // ---------------------------------------------------------------- doğrulama
            $tek = static fn(string $sql): float => (float) $h->query($sql)->fetchColumn();
            $karsilastirma = [
                'cari bakiye toplamı' => [round($kaynakCariToplam, 2), round($tek('SELECT ISNULL(SUM(BAKIYE), 0) FROM dbo.LMV_CARI_BAKIYE'), 2)],
                'stok miktarı toplamı' => [round($kaynakStokToplam, 4), round($tek('SELECT ISNULL(SUM(MIKTAR), 0) FROM dbo.LMV_STOK_MIKTAR'), 4)],
                'kasa bakiyesi' => [round($kaynakKasaToplam, 2), round($tek('SELECT ISNULL(SUM(B.BAKIYE), 0) FROM dbo.LMV_FINANS_BAKIYE B JOIN dbo.LM_FINANS_HESAP H ON H.ID = B.HESAP_ID WHERE H.TUR = 1'), 2)],
                'banka bakiyesi' => [round($kaynakBankaToplam, 2), round($tek('SELECT ISNULL(SUM(B.BAKIYE), 0) FROM dbo.LMV_FINANS_BAKIYE B JOIN dbo.LM_FINANS_HESAP H ON H.ID = B.HESAP_ID WHERE H.TUR = 2'), 2)],
                'çek/senet toplamı' => [round($kaynakCekToplam, 2), round($tek('SELECT ISNULL(SUM(TUTAR), 0) FROM dbo.LM_CEK'), 2)],
            ];
            $tutarsiz = array_filter($karsilastirma, static fn($v) => abs($v[0] - $v[1]) > 0.011);
            if ($tutarsiz !== []) {
                $h->rollBack();
                return ['ok' => false, 'uygulandi' => false, 'sayilar' => $sayilar, 'karsilastirma' => $karsilastirma, 'uyarilar' => $uyarilar,
                    'mesaj' => 'Toplamlar tutmadı (' . implode(', ', array_keys($tutarsiz)) . '); hiçbir şey yazılmadı.'];
            }
            if (!$o['uygula']) {
                $h->rollBack();
                return ['ok' => true, 'uygulandi' => false, 'sayilar' => $sayilar, 'karsilastirma' => $karsilastirma, 'uyarilar' => $uyarilar,
                    'mesaj' => 'Deneme tamam: toplamlar tutuyor; hiçbir şey yazılmadı (yazmak için --uygula).'];
            }
            $h->commit();
            return ['ok' => true, 'uygulandi' => true, 'sayilar' => $sayilar, 'karsilastirma' => $karsilastirma, 'uyarilar' => $uyarilar,
                'mesaj' => 'İçe aktarma tamamlandı.'];
        } catch (Throwable $e) {
            if ($h->inTransaction()) {
                $h->rollBack();
            }
            throw $e;
        }
    }
}

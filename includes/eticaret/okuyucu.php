<?php
declare(strict_types=1);

require_once __DIR__.'/adaptorler.php';

/** CSV/JSON adaptörleri yalnız ortak modele çevirir; veritabanı ve pazaryeri API bağımlılığı yoktur. */
function et_metin(mixed $v, int $sinir = 150): string
{
    if (!is_string($v) && !is_int($v)) { throw new InvalidArgumentException('Metin alanının türü geçersiz.'); }
    $s = trim((string) $v);
    if (mb_strlen($s) > $sinir || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', $s)) {
        throw new InvalidArgumentException('Metin alanı çok uzun veya geçersiz.');
    }
    return $s;
}
function et_sayi(mixed $v, float $min, float $max): float
{
    if ((!is_string($v) && !is_int($v) && !is_float($v))
        || preg_match('/\A[0-9]+(?:[.,][0-9]{1,6})?\z/', (string) $v) !== 1) {
        throw new InvalidArgumentException('Sayı biçimi geçersiz; binlik ayraç kullanmayın.');
    }
    $n = (float) str_replace(',', '.', (string) $v);
    if (!is_finite($n) || $n < $min || $n > $max) { throw new InvalidArgumentException('Sayı izin verilen aralığın dışında.'); }
    return $n;
}
function et_normalize(array $r): array
{
    $no = et_metin($r['numara'] ?? ''); $id = et_metin($r['kimlik'] ?? $no);
    if ($id === '' || $no === '') { throw new InvalidArgumentException('Sipariş kimliği ve numarası gerekiyor.'); }
    $tarih = et_metin($r['tarih'] ?? '', 35);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $tarih);
    if (!$dt || $dt->format('Y-m-d') !== $tarih) { throw new InvalidArgumentException('Tarih YYYY-AA-GG olmalı.'); }
    if (($r['para_birimi'] ?? '') !== 'TRY') { throw new InvalidArgumentException('MVP yalnız TRY siparişlerini aktarır.'); }
    foreach (['iskonto','kargo','masraf'] as $k) {
        if (et_sayi($r[$k] ?? 0, 0, 100000000) != 0) { throw new InvalidArgumentException('İskonto, kargo ve masraf aktarımı bu sürümde desteklenmiyor.'); }
    }
    $kalemler = $r['kalemler'] ?? null;
    if (!is_array($kalemler) || !array_is_list($kalemler) || count($kalemler) < 1 || count($kalemler) > 200) {
        throw new InvalidArgumentException('Sipariş 1–200 kalem içermeli.');
    }
    $out = [];
    foreach ($kalemler as $k) {
        if (!is_array($k)) { throw new InvalidArgumentException('Sipariş kalemi geçersiz.'); }
        $sku = et_metin($k['sku'] ?? ''); $barkod = et_metin($k['barkod'] ?? '');
        if ($sku === '' && $barkod === '') { throw new InvalidArgumentException('Her kalemde SKU veya barkod gerekiyor.'); }
        $birim = et_metin($k['birim'] ?? '', 30);
        if ($birim === '') { throw new InvalidArgumentException('Kalem birimi gerekiyor.'); }
        if (!isset($k['kdv_dahil']) || !is_bool($k['kdv_dahil'])) { throw new InvalidArgumentException('kdv_dahil true veya false olmalı.'); }
        if (round(et_sayi($k['kdv'] ?? '',0,100),2) !== et_sayi($k['kdv'] ?? '',0,100)) { throw new InvalidArgumentException('KDV en fazla iki ondalık haneli olmalı.'); }
        $out[] = ['sku'=>$sku,'barkod'=>$barkod,'ad'=>et_metin($k['ad'] ?? '',200),'birim'=>$birim,
            'miktar'=>et_sayi($k['miktar'] ?? '',0.000001,1000000), 'fiyat'=>et_sayi($k['fiyat'] ?? '',0.000001,100000000),
            'kdv'=>et_sayi($k['kdv'] ?? '',0,100),'kdv_dahil'=>$k['kdv_dahil'],
            'harici_kimlik'=>et_metin($k['harici_kimlik'] ?? '')];
    }
    $adresler = [];
    foreach (['musteri','fatura','teslimat'] as $a) {
        if (!isset($r[$a]) || !is_array($r[$a]) || array_is_list($r[$a])) { throw new InvalidArgumentException('Müşteri, fatura ve teslimat nesneleri gerekiyor.'); }
        $adresler[$a] = $r[$a]; // Alanlar kırpılmaz; ham kayıtla birlikte eksiksiz korunur.
    }
    return ['kimlik'=>$id,'numara'=>$no,'paket_kimligi'=>et_metin($r['paket_kimligi'] ?? '',100),'tarih'=>$tarih,'para_birimi'=>'TRY', 'genel_toplam'=>et_sayi($r['genel_toplam'] ?? '',0.01,100000000000),
        'kalemler'=>$out] + $adresler;
}
function et_dosya_oku(string $ham, string $tur, string $bicim = 'lumen'): array
{
    if (strlen($ham) > 1048576 || !mb_check_encoding($ham,'UTF-8')) { throw new InvalidArgumentException('Dosya en fazla 1 MB ve UTF-8 olmalı.'); }
    $ham = preg_replace('/^\xEF\xBB\xBF/', '', $ham);
    if (!isset(et_bicimler()[$bicim])) { throw new InvalidArgumentException('İçe aktarma biçimi tanınmadı.'); }
    if ($bicim!=='lumen') {
        if ($tur!=='json') { throw new InvalidArgumentException('Pazaryeri API yanıtı JSON dosyası olmalı.'); }
        return et_adaptor_oku($ham,$bicim);
    }
    if ($tur === 'json') {
        $j = json_decode($ham, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($j) || ($j['surum'] ?? null) !== 1 || !is_array($j['siparisler'] ?? null) || !array_is_list($j['siparisler'])) {
            throw new InvalidArgumentException('JSON, surum:1 ve siparisler listesi içermeli.');
        }
        $kayitlar = $j['siparisler'];
    } elseif ($tur === 'csv') {
        $f = fopen('php://temp','w+'); fwrite($f,$ham); rewind($f);
        try {
            $baslik = fgetcsv($f,0,';','"','');
            $zorunlu = ['numara','tarih','para_birimi','genel_toplam','musteri_adi','fatura_adres','teslimat_adres','sku','barkod','ad','birim','miktar','fiyat','kdv','kdv_dahil'];
            if (!$baslik || count(array_unique($baslik)) !== count($baslik) || array_diff($zorunlu,$baslik)) {
                throw new InvalidArgumentException('CSV başlığı örnek dosyayla aynı olmalı; ayraç noktalı virgüldür.');
            }
            $grup = []; $sayac = 0;
            while (($satir = fgetcsv($f,0,';','"','')) !== false) {
                if ($satir === [null]) { continue; }
                if (++$sayac > 3000 || count($satir) !== count($baslik)) { throw new InvalidArgumentException('CSV satır sayısı veya sütun sayısı geçersiz.'); }
                $x = array_combine($baslik,$satir);
                $anahtar = 'id:' . ($x['kimlik'] ?? $x['numara']);
                $meta = ['kimlik'=>$x['kimlik'] ?? $x['numara'],'numara'=>$x['numara'],'tarih'=>$x['tarih'],
                    'para_birimi'=>$x['para_birimi'],'genel_toplam'=>$x['genel_toplam'], 'musteri'=>['ad'=>$x['musteri_adi']],
                    'fatura'=>['adres'=>$x['fatura_adres']], 'teslimat'=>['adres'=>$x['teslimat_adres']],
                    'iskonto'=>$x['iskonto'] ?? 0,'kargo'=>$x['kargo'] ?? 0,'masraf'=>$x['masraf'] ?? 0];
                if (isset($grup[$anahtar]) && $grup[$anahtar]['meta'] !== $meta) { throw new InvalidArgumentException('Aynı CSV siparişinin başlık bilgileri tutarsız.'); }
                $grup[$anahtar]['meta'] = $meta;
                $grup[$anahtar]['kalemler'][] = array_intersect_key($x,array_flip(['sku','barkod','ad','birim','miktar','fiyat','kdv']))
                    + ['kdv_dahil'=> match ($x['kdv_dahil']) { '1','true'=>true,'0','false'=>false,default=>null }];
            }
            $kayitlar = array_map(static fn($x)=>$x['meta']+['kalemler'=>$x['kalemler']],array_values($grup));
        } finally { fclose($f); }
    } else { throw new InvalidArgumentException('Yalnız CSV ve JSON desteklenir.'); }
    if (count($kayitlar) < 1 || count($kayitlar) > 100) { throw new InvalidArgumentException('Dosya 1–100 sipariş içermeli.'); }
    return array_map(static fn($r) => et_kayit_hazirla($r, $r), $kayitlar);
}

/** Adaptörler aynı zarfı üretir. Kaynak durumu yalnız adaptör bağlamından alınır. */
function et_kayit_hazirla(mixed $ham, mixed $aday, array $kaynak = [], ?string $hata = null): array
{
    $id = ''; $no = ''; $paket = ''; $normal = null;
    try {
        if (!is_array($aday)) { throw new InvalidArgumentException('Sipariş nesnesi gerekiyor.'); }
        $no = et_metin($aday['numara'] ?? '');
        $id = et_metin($aday['kimlik'] ?? $no);
        $paket = et_metin($aday['paket_kimligi'] ?? '', 100);
        if ($id === '' || $no === '') { throw new InvalidArgumentException('Sipariş kimliği ve numarası gerekiyor.'); }
        if ($hata !== null) { throw new InvalidArgumentException($hata); }
        $normal = et_normalize($aday);
        if (($kaynak['sistem'] ?? 'lumen')!=='lumen') { $normal['kaynak_bilgileri']=$kaynak; }
    } catch (InvalidArgumentException $e) { $hata = $e->getMessage(); }
    return ['ham'=>$ham,'normal'=>$normal,'hata'=>$hata,'kimlik'=>$id,'numara'=>$no,'paket_kimligi'=>$paket,
        'kaynak'=>$kaynak['sistem'] ?? 'lumen','kaynak_durumu'=>$kaynak['durum'] ?? null,
        'kaynak_engeli'=>$kaynak['engel'] ?? null,'kaynak_guncelleme'=>$kaynak['guncelleme'] ?? null];
}

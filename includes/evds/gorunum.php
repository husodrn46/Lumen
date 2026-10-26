<?php
declare(strict_types=1);
require_once __DIR__.'/istemci.php';

function evds_sayi(?float $v): string
{
    if ($v===null) { return '—'; }
    if (abs($v)>=1e12 || ($v!=0 && abs($v)<.0001)) { return sprintf('%.4e',$v); }
    return rtrim(rtrim(number_format($v,4,',','.'),'0'),',');
}

/** Eksik gözlemde çizgi kesilir; tablo her zaman grafikle aynı gözlemleri gösterir. */
function evds_grafik(array $satirlar): string
{
    $dolu=array_values(array_filter($satirlar,static fn(array $r): bool => $r['deger']!==null));
    if (!$dolu) { return '<div class="ek-bos"><i class="fa-solid fa-chart-line" aria-hidden="true"></i><p>Bu aralıkta çizilecek gözlem yok.</p></div>'; }
    $degerler=array_column($dolu,'deger');$min=min($degerler);$max=max($degerler);
    if (!is_finite($max-$min)) { return '<div class="ek-bos">Bu serinin sayısal ölçeği grafikte gösterilemiyor; tablo ve CSV kullanılabilir.</div>'; }
    $bosluk=$max===$min ? max(abs($max)*.02,1.0) : ($max-$min)*.12;
    $min-=$bosluk;$max+=$bosluk;
    $damga=static fn(string $t): int => (new DateTimeImmutable($t,new DateTimeZone('UTC')))->getTimestamp();
    $bas=$damga($satirlar[0]['tarih']);$bit=$damga($satirlar[count($satirlar)-1]['tarih']);
    $x=static fn(array $r): float => $bit===$bas ? 470 : 82+($damga($r['tarih'])-$bas)/($bit-$bas)*780;
    $y=static fn(float $v): float => 26+($max-$v)/($max-$min)*206;
    $n=static fn(float $v): string => number_format($v,2,'.','');
    $h=static fn(string $v): string => htmlspecialchars($v,ENT_QUOTES,'UTF-8');
    $svg='<svg class="ek-grafik" viewBox="0 0 900 285" role="img" aria-labelledby="ek-grafik-baslik ek-grafik-aciklama"><title id="ek-grafik-baslik">Seçilen serinin dönem içindeki seyri</title><desc id="ek-grafik-aciklama">Eksik gözlemlerde çizgi kesilir. Tüm değerler aşağıdaki tabloda yer alır.</desc>';
    for ($i=0;$i<4;$i++) {
        $deger=$max-($max-$min)*$i/3;$cy=26+206*$i/3;
        $etiket=($max-$min)>=.1 ? evds_sayi(round($deger,2)) : evds_sayi($deger);
        $svg.='<line x1="82" x2="862" y1="'.$n($cy).'" y2="'.$n($cy).'" class="ek-grafik-izgara"/><text x="68" y="'.$n($cy+4).'" text-anchor="end">'.$h($etiket).'</text>';
    }
    $parcalar=[];$parca='';$noktalar='';
    foreach ($satirlar as $r) {
        if ($r['deger']===null) { if ($parca!=='') { $parcalar[]=$parca;$parca=''; }continue; }
        $cx=$n($x($r));$cy=$n($y($r['deger']));$parca.=($parca==='' ? 'M' : 'L').$cx.','.$cy.' ';
        if (count($satirlar)<=60 || $r===$dolu[count($dolu)-1]) {
            $noktalar.='<circle cx="'.$cx.'" cy="'.$cy.'" r="3.5" class="ek-grafik-nokta"><title>'.$h($r['donem'].' : '.evds_sayi($r['deger'])).'</title></circle>';
        }
    }
    if ($parca!=='') { $parcalar[]=$parca; }
    foreach ($parcalar as $p) { $svg.='<path d="'.$p.'" class="ek-grafik-cizgi"/>'; }
    $svg.=$noktalar;
    foreach (array_unique([0,(int) floor((count($satirlar)-1)/2),count($satirlar)-1]) as $i) {
        $svg.='<text x="'.$n($x($satirlar[$i])).'" y="266" text-anchor="middle">'.$h($satirlar[$i]['donem']).'</text>';
    }
    return $svg.'</svg>';
}

function evds_csv_hucre(string $v): string
{
    return preg_match('/\A[\s=+\-@]/u',$v) ? "'".$v : $v;
}

function evds_csv_yaz($akis, array $sonuc): void
{
    $v=$sonuc['veri'];
    fputcsv($akis,['Dönem',evds_csv_hucre($v['meta']['ad']),'Seri kodu','Kaynak','Alınma zamanı'],';', '"','');
    foreach ($v['satirlar'] as $r) {
        fputcsv($akis,[$r['donem'],$r['deger']===null ? '' : str_replace('.',',',json_encode($r['deger'],JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR)),
            $v['meta']['kod'],evds_csv_hucre(!empty($sonuc['ornek']) ? 'Lumen sentetik örneği; TCMB verisi değildir' : 'TCMB EVDS / '.$v['meta']['kaynak']),$sonuc['alinma'] ?? 'Sentetik örnek'],';', '"','');
    }
}

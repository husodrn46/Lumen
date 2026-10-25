<?php
declare(strict_types=1);

if (!isset($dbh, $terminalkullanici)) { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/eticaret/depo.php';
require_once __DIR__ . '/../includes/belge/sayfa.php';
if ((int) m_p_yetki($terminalkullanici, 'M33') !== 1) {
    lumen_hata_sayfasi('E-ticaret yetkiniz yok', 'M33 yetkisi gerekiyor.', 403);
}
function et_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
        lumen_hata_sayfasi('Geçersiz istek', 'Güvenlik doğrulaması başarısız.', 403);
    }
}
function et_hata(Throwable $e): string
{
    if ($e instanceof DomainException || $e instanceof InvalidArgumentException) { return $e->getMessage(); }
    error_log('eticaret: ' . $e->getMessage());
    return 'İşlem tamamlanamadı. Sayfayı yenileyip tekrar kontrol edin.';
}
function et_basla(string $baslik): void
{
    belge_sayfa_basla($baslik, 'fa-inbox');
    echo '<style>.sayfa h2{font-size:16px;margin:0 0 10px}.sayfa pre{font-size:13px;line-height:1.6}.sayfa table a{color:var(--red)}.sayfa .tablo-sar{max-width:100%;overflow:auto}.sayfa .et-adresler{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.et-adresler .kart{min-width:0}@media(max-width:650px){.sayfa .et-adresler{grid-template-columns:1fr}}</style>';
    echo '<nav class="kart kart-ic" style="display:flex;gap:12px;flex-wrap:wrap"><a class="btn btn-ikincil" href="index.php">Sipariş kutusu</a><a class="btn btn-ikincil" href="ice_aktar.php">CSV / JSON içe aktar</a><a class="btn btn-ikincil" href="kanallar.php">Satış kanalları</a></nav>';
}

/** Cari araması erişim filtresini kullanır; formlar kimlik yerine kod/unvan gösterir. */
function et_hedef_alanlari(PDO $db, array $r): void
{
    global $firma,$firmano,$terminalkullanici;
    $q=mb_substr(trim((string) ($_GET['cari_ara'] ?? '')),0,100);
    $terim='%'.str_replace(['[','%','_'],['[[]','[%]','[_]'],$q).'%';
    $ozel=m_p_ozel_cari_sql_filtresi($terminalkullanici,'C.LOGICALREF','et_sec');
    $where=$ozel['sql']!=='' ? ' AND '.$ozel['sql'] : '';
    $s=$db->prepare("SELECT TOP 100 C.LOGICALREF,C.CODE,C.DEFINITION_ FROM {$firma}CLCARD C
        WHERE C.ACTIVE=0 AND (C.CODE LIKE :q OR C.DEFINITION_ LIKE :ad){$where} ORDER BY C.CODE");
    $s->execute([':q'=>$terim,':ad'=>$terim]+$ozel['params']); $cariler=$s->fetchAll(PDO::FETCH_ASSOC);
    $secili=(int) ($r['CARI_ID'] ?? 0);
    if ($secili && !in_array($secili,array_column($cariler,'LOGICALREF'))
        && m_p_cariid_goruntulebilir_mi($db,$firma,$terminalkullanici,$secili)
        && ($c=siparis_cari_getir($db,$firma,$secili))) {
        array_unshift($cariler,['LOGICALREF'=>$c['id'],'CODE'=>$c['kod'],'DEFINITION_'=>$c['ad']]);
    }
    $t=lumen_tablo('depo');$s=$db->prepare("SELECT NR,NAME FROM {$t} WHERE FIRMNR=:f ORDER BY NR");
    $s->execute([':f'=>(int) $firmano]); $depolar=$s->fetchAll(PDO::FETCH_ASSOC);
    $t=lumen_tablo('personel'); $personeller=$db->query("SELECT P.ID,P.KOD,P.AD FROM {$t} P WHERE P.AKTIF=1 ORDER BY P.KOD")->fetchAll(PDO::FETCH_ASSOC);
    ?><label class="alan">Cari <select name="cari"><option value="0">Seçilmedi</option><?php foreach ($cariler as $c): ?><option value="<?= (int) $c['LOGICALREF'] ?>" <?= $secili===(int) $c['LOGICALREF'] ? 'selected' : '' ?>><?= bh($c['CODE'].' · '.$c['DEFINITION_']) ?></option><?php endforeach; ?></select></label>
    <label class="alan">Depo <select name="depo"><option value="-1">Seçilmedi</option><?php foreach ($depolar as $d): ?><option value="<?= (int) $d['NR'] ?>" <?= isset($r['DEPO_NO']) && (int) $r['DEPO_NO']===(int) $d['NR'] ? 'selected' : '' ?>><?= bh($d['NAME']) ?></option><?php endforeach; ?></select></label>
    <label class="alan">Personel <select name="personel"><option value="0">Seçilmedi</option><?php foreach ($personeller as $p): ?><option value="<?= (int) $p['ID'] ?>" <?= (int) ($r['PERSONEL_ID'] ?? 0)===(int) $p['ID'] ? 'selected' : '' ?>><?= bh($p['KOD'].' · '.$p['AD']) ?></option><?php endforeach; ?></select></label><?php
}
function et_cari_ara_formu(int $id): void
{
    ?><form method="get" class="kart kart-ic"><input type="hidden" name="id" value="<?= $id ?>"><label class="alan">Cari kodu veya unvanı ara <input name="cari_ara" value="<?= bh($_GET['cari_ara'] ?? '') ?>" maxlength="100"></label><button class="btn btn-ikincil">Cari seçeneklerini süz</button></form><?php
}

function et_tarih(?string $v): string
{
    return $v ? (new DateTimeImmutable($v))->format('d.m.Y H:i:s') : '—';
}

function et_adres_goster(array $v): void
{
    $adlar=['ad'=>'Ad','unvan'=>'Unvan','adres'=>'Adres','il'=>'İl','ilce'=>'İlçe','telefon'=>'Telefon','eposta'=>'E-posta','vergi_no'=>'Vergi no','tc_no'=>'TCKN','posta_kodu'=>'Posta kodu','ulke'=>'Ülke'];
    echo '<dl>';
    foreach ($v as $k=>$deger) {
        echo '<dt class="soluk">'.bh($adlar[$k] ?? str_replace('_',' ',(string) $k)).'</dt><dd style="margin:0 0 8px;overflow-wrap:anywhere">';
        if (is_array($deger)) { et_adres_goster($deger); } else { echo bh(is_bool($deger) ? ($deger ? 'Evet' : 'Hayır') : $deger); }
        echo '</dd>';
    }
    echo '</dl>';
}

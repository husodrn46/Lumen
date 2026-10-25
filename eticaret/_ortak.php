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
    echo '<nav class="kart kart-ic" style="display:flex;gap:12px;flex-wrap:wrap"><a href="index.php">Sipariş kutusu</a><a href="ice_aktar.php">CSV / JSON içe aktar</a><a href="kanallar.php">Satış kanalları</a></nav>';
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

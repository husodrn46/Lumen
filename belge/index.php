<?php

declare(strict_types=1);

/** Belge listesi (faturalar, iadeler, irsaliyeler, üretim, sayım) — yalnız bağımsız mod. */

include_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/_ortak.php';

$turler = $belgeTurleri;
$secTur = (string) ($_GET['tur'] ?? '');
$bas = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['bas'] ?? '')) ? (string) $_GET['bas'] : date('Y-m-01');
$bit = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['bit'] ?? '')) ? (string) $_GET['bit'] : date('Y-m-d');
$ara = trim((string) ($_GET['ara'] ?? ''));
$sayfaGirdisi = $_GET['sayfa'] ?? '1';
$sayfa = is_string($sayfaGirdisi) ? filter_var($sayfaGirdisi, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
$sayfa = $sayfa === false ? 1 : $sayfa;
$sayfaBoyutu = 50;

$kosul = '(B.TUR <> 1 OR B.YON = 2) AND B.TARIH BETWEEN :bas AND :bit';   // satış siparişleri sipariş ekranında
if (!$belgeTam) {
    $kosul .= ' AND B.TUR IN (5, 6)'; // depo personeli: üretim ve sayım
}
$p = [':bas' => $bas, ':bit' => $bit];
if (isset($turler[$secTur])) {
    $kosul .= ' AND B.TUR = :tur AND B.YON = :yon';
    $p[':tur'] = $turler[$secTur]['tur'];
    $p[':yon'] = $turler[$secTur]['yon'];
}
if ($ara !== '') {
    $t = '%' . lumen_arama_terimi($ara) . '%';
    $kosul .= ' AND (' . lumen_arama_sql('B.NO') . ' LIKE :a1 OR ' . lumen_arama_sql('C.UNVAN') . ' LIKE :a2 OR ' . lumen_arama_sql('C.KOD') . ' LIKE :a3)';
    $p += [':a1' => $t, ':a2' => $t, ':a3' => $t];
}
$ozel = m_p_ozel_cari_sql_filtresi($terminalkullanici, 'ISNULL(B.CARI_ID, 0)', 'belge_liste_gizli');
if ($ozel['sql'] !== '') {
    $kosul .= ' AND ' . $ozel['sql'];
    $p += $ozel['params'];
}
$sayac = $dbh->prepare("SELECT COUNT(*) FROM dbo.LM_BELGE B LEFT JOIN dbo.LM_CARI C ON C.ID = B.CARI_ID WHERE {$kosul}");
$sayac->execute($p);
$belgeSayisi = (int) $sayac->fetchColumn();
$sayfaSayisi = max(1, (int) ceil($belgeSayisi / $sayfaBoyutu));
$sayfa = min($sayfa, $sayfaSayisi);
$ofset = ($sayfa - 1) * $sayfaBoyutu;
$sayfaBaglantisi = static fn(int $hedef): string => '?' . http_build_query(['tur' => $secTur, 'bas' => $bas, 'bit' => $bit, 'ara' => $ara, 'sayfa' => $hedef]);

$st = $dbh->prepare("SELECT B.ID, B.TUR, B.YON, B.NO, B.TARIH, B.NET, B.DURUM, C.KOD AS CARI_KOD, C.UNVAN
                     FROM dbo.LM_BELGE B LEFT JOIN dbo.LM_CARI C ON C.ID = B.CARI_ID
                     WHERE {$kosul} ORDER BY B.TARIH DESC, B.ID DESC
                     OFFSET {$ofset} ROWS FETCH NEXT {$sayfaBoyutu} ROWS ONLY");
$st->execute($p);
$belgeler = $st->fetchAll(PDO::FETCH_ASSOC);

belge_sayfa_basla($belgeTam ? 'Faturalar ve Belgeler' : 'Üretim ve Sayım', 'fa-file-invoice', '../index.php',
    '<a href="yeni.php?tur=' . bh(array_key_first($turler)) . '" class="btn btn-birincil"><i class="fa-solid fa-plus"></i><span class="metin">Yeni Belge</span></a>');
?>
<style>
    .sayfalama { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-top: 12px; }
    .sayfalama .dugmeler { display: flex; gap: 8px; }
</style>
<form method="get" class="kart kart-ic izgara" style="align-items:end">
    <label class="alan">Tür
        <select name="tur">
            <option value="">Tümü</option>
            <?php foreach ($turler as $kod => $t): ?>
                <option value="<?= bh($kod) ?>" <?= $kod === $secTur ? 'selected' : '' ?>><?= bh($t['ad']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="alan">Başlangıç <input type="date" name="bas" value="<?= bh($bas) ?>"></label>
    <label class="alan">Bitiş <input type="date" name="bit" value="<?= bh($bit) ?>"></label>
    <label class="alan">Ara <input type="search" name="ara" value="<?= bh($ara) ?>" placeholder="Belge no, cari…"></label>
    <div><button class="btn btn-birincil" type="submit"><i class="fa-solid fa-filter"></i> Listele</button></div>
</form>

<div class="kart tablo-sar">
    <table class="liste kartla">
        <thead><tr><th>Belge No</th><th>Tür</th><th>Tarih</th><th>Cari</th><th class="sag">Tutar (KDV dahil)</th><th></th></tr></thead>
        <tbody>
        <?php if ($belgeler === []): ?>
            <tr><td colspan="6" class="soluk" style="text-align:center;padding:28px">Bu aralıkta belge yok.</td></tr>
        <?php endif; ?>
        <?php foreach ($belgeler as $b):
            $kod = lumen_belge_tur_kodu((int) $b['TUR'], (int) $b['YON']);
            $ad = $kod !== null ? $turler[$kod]['ad'] : 'Belge'; ?>
            <tr>
                <td class="koyu"><a href="goster.php?id=<?= (int) $b['ID'] ?>" style="color:inherit"><?= bh($b['NO']) ?></a></td>
                <td><span class="rozet <?= (int) $b['YON'] === 1 ? 'satis' : 'alis' ?>"><?= bh($ad) ?></span>
                    <?php if ((int) $b['DURUM'] === 9): ?><span class="rozet iptal">İptal</span><?php endif; ?></td>
                <td><?= bh(date('d.m.Y', strtotime((string) $b['TARIH']))) ?></td>
                <td><?= bh(trim(($b['CARI_KOD'] ?? '') . ' ' . ($b['UNVAN'] ?? ''))) ?: '<span class="soluk">—</span>' ?></td>
                <td class="sag koyu"><?= btl($b['NET']) ?></td>
                <td class="sag"><a class="btn btn-ikincil" href="goster.php?id=<?= (int) $b['ID'] ?>"><i class="fa-solid fa-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<nav class="sayfalama" aria-label="Belge sayfaları">
    <span class="soluk"><?= number_format($belgeSayisi, 0, ',', '.') ?> belge · Sayfa <?= $sayfa ?> / <?= $sayfaSayisi ?></span>
    <?php if ($sayfaSayisi > 1): ?>
        <div class="dugmeler">
            <?php if ($sayfa > 1): ?><a class="btn btn-ikincil" href="<?= bh($sayfaBaglantisi($sayfa - 1)) ?>">Önceki</a><?php endif; ?>
            <?php if ($sayfa < $sayfaSayisi): ?><a class="btn btn-ikincil" href="<?= bh($sayfaBaglantisi($sayfa + 1)) ?>">Sonraki</a><?php endif; ?>
        </div>
    <?php endif; ?>
</nav>
<?php belge_sayfa_bitir();

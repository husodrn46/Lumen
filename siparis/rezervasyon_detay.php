<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../ayr.php");

// YETKI KONTROLÜ: M8 (Rezervasyon Takibi) yetkisi kontrolü
if (m_p_yetki($terminalkullanici, 'M8') != 1) {
    echo '<div class="text-center text-red-600 p-4">Bu bilgileri görme yetkiniz yok!</div>';
    exit;
}

$stokId = isset($_POST['stok_id']) ? intval($_POST['stok_id']) : 0;

if ($stokId <= 0) {
    echo '<div class="text-center text-red-600 p-4">Geçersiz ürün ID!</div>';
    exit;
}

// Ürün bilgisini al
$stmtUrunBilgi = $dbh->prepare("
    SELECT CODE, NAME
    FROM " . $firma . "ITEMS
    WHERE LOGICALREF = :stokId
");
$stmtUrunBilgi->execute([':stokId' => $stokId]);
$urunBilgi = $stmtUrunBilgi->fetch(PDO::FETCH_ASSOC);

if (!$urunBilgi) {
    echo '<div class="text-center text-red-600 p-4">Ürün bulunamadı!</div>';
    exit;
}

// Koli içi bilgisi (adet -> koli dönüşümü için)
$stmtKoliIci = $dbh->prepare("
    SELECT ISNULL(MAX(CONVFACT2), 1) AS KOLI_ICI
    FROM " . $firma . "ITMUNITA
    WHERE ITEMREF = :stokId
");
$stmtKoliIci->execute([':stokId' => $stokId]);
$koliIciRow = $stmtKoliIci->fetch(PDO::FETCH_ASSOC);
$koliIci = (float) ($koliIciRow['KOLI_ICI'] ?? 1);
if ($koliIci <= 0) {
    $koliIci = 1;
}

if (!function_exists('miktarToKoli')) {
    function miktarToKoli(float|int|string|null $miktar, float $koliIci): float
    {
        $miktarDegeri = (float) ($miktar ?? 0);
        if ($koliIci <= 0) {
            return $miktarDegeri;
        }
        return $miktarDegeri / $koliIci;
    }
}

if (!function_exists('koliFormat')) {
    function koliFormat(float|int|string|null $koli): string
    {
        return number_format((float) ($koli ?? 0), 2, ',', '.');
    }
}

// Mevcut stok miktarı
$stmtStokMiktar = $dbh->prepare("
    SELECT ISNULL(SUM(ONHAND), 0) AS MIKTAR
    FROM " . $firmadonemx . "STINVTOT
    WHERE STOCKREF = :stokId AND INVENNO = -1
");
$stmtStokMiktar->execute([':stokId' => $stokId]);
$stokMiktar = $stmtStokMiktar->fetch(PDO::FETCH_ASSOC);

// Bu ürünü bekleyen siparişler
$stmtBekleyenSiparisler = $dbh->prepare("
    SELECT
        F.LOGICALREF AS FIS_ID,
        F.FICHENO,
        F.DATE_ AS TARIH,
        C.DEFINITION_ AS MUSTERI_ADI,
        C.CODE AS MUSTERI_KODU,
        L.AMOUNT AS SIPARIS_MIKTAR,
        L.SHIPPEDAMOUNT AS SEVK_EDILEN,
        (L.AMOUNT - L.SHIPPEDAMOUNT) AS KALAN_MIKTAR,
        L.PRICE AS FIYAT,
        L.RESERVEAMOUNT AS REZERVE,
        S.DEFINITION_ AS SATICI
    FROM " . $firmadonem . "ORFLINE L
    INNER JOIN " . $firmadonem . "ORFICHE F ON F.LOGICALREF = L.ORDFICHEREF
    LEFT JOIN " . $firma . "CLCARD C ON C.LOGICALREF = F.CLIENTREF
    LEFT JOIN LG_SLSMAN S ON S.LOGICALREF = F.SALESMANREF
    WHERE L.STOCKREF = :stokId
        AND L.TRCODE = 1
        AND L.STATUS <> 2
        AND L.CLOSED = 0
        AND (L.AMOUNT - L.SHIPPEDAMOUNT) > 0
    ORDER BY F.DATE_ ASC, F.FICHENO ASC
");
$stmtBekleyenSiparisler->execute([':stokId' => $stokId]);
$bekleyenSiparisler = $stmtBekleyenSiparisler->fetchAll(PDO::FETCH_ASSOC);

// Toplam beklenen miktar
$toplamBeklenen = array_sum(array_column($bekleyenSiparisler, 'KALAN_MIKTAR'));
$toplamRezerve = array_sum(array_column($bekleyenSiparisler, 'REZERVE'));

// Yetersizlik durumu
$yetersizMiktar = max(0, $toplamBeklenen - $stokMiktar['MIKTAR']);
$mevcutStokKoli = miktarToKoli($stokMiktar['MIKTAR'], $koliIci);
$toplamBeklenenKoli = miktarToKoli($toplamBeklenen, $koliIci);
$toplamRezerveKoli = miktarToKoli($toplamRezerve, $koliIci);
$yetersizKoli = miktarToKoli($yetersizMiktar, $koliIci);
?>

<div class="space-y-5">
    <!-- Ürün Özeti -->
    <div class="rounded-xl border border-red-200 bg-gradient-to-r from-red-50 to-rose-50 p-4">
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars((string) $urunBilgi['CODE']); ?></h3>
                <p class="text-sm text-gray-600"><?php echo htmlspecialchars((string) $urunBilgi['NAME']); ?></p>
                <p class="mt-1 text-xs font-medium text-red-600">Koli içi: <?php echo koliFormat($koliIci); ?></p>
            </div>
            <i class="fas fa-box text-4xl text-red-300"></i>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
            <div class="rounded-lg border border-red-100 bg-white p-3 text-center shadow-sm">
                <p class="mb-1 text-xs text-gray-500">Mevcut Stok</p>
                <p class="text-xl font-bold text-green-600"><?php echo koliFormat($mevcutStokKoli); ?> <span class="text-sm">koli</span></p>
            </div>
            <div class="rounded-lg border border-red-100 bg-white p-3 text-center shadow-sm">
                <p class="mb-1 text-xs text-gray-500">Toplam Bekleyen</p>
                <p class="text-xl font-bold text-red-600"><?php echo koliFormat($toplamBeklenenKoli); ?> <span class="text-sm">koli</span></p>
            </div>
            <div class="rounded-lg border border-red-100 bg-white p-3 text-center shadow-sm">
                <p class="mb-1 text-xs text-gray-500">Rezerve</p>
                <p class="text-xl font-bold text-purple-600"><?php echo koliFormat($toplamRezerveKoli); ?> <span class="text-sm">koli</span></p>
            </div>
            <div class="rounded-lg border border-red-100 bg-white p-3 text-center shadow-sm">
                <p class="mb-1 text-xs text-gray-500">Yetersizlik</p>
                <p class="text-xl font-bold <?php echo $yetersizMiktar > 0 ? 'text-red-600' : 'text-gray-400'; ?>">
                    <?php echo $yetersizMiktar > 0 ? koliFormat($yetersizKoli) . ' koli' : '—'; ?>
                </p>
            </div>
        </div>

        <?php if ($yetersizMiktar > 0): ?>
            <div class="mt-3 flex items-center gap-2 rounded-lg border border-red-300 bg-red-100 p-3">
                <i class="fas fa-exclamation-triangle text-red-600"></i>
                <p class="text-sm text-red-800 font-semibold">
                    Stok yetersiz. <span class="font-bold"><?php echo koliFormat($yetersizKoli); ?> koli</span> eksik.
                </p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bekleyen Siparişler -->
    <div>
        <h4 class="font-bold text-gray-800 mb-3 flex items-center gap-2">
            <i class="fas fa-list text-indigo-600"></i>
            Bekleyen Siparişler (<?php echo count($bekleyenSiparisler); ?>)
        </h4>

        <?php if (empty($bekleyenSiparisler)): ?>
            <div class="bg-gray-50 rounded-lg p-6 text-center">
                <i class="fas fa-check-circle text-green-400 text-4xl mb-2"></i>
                <p class="text-gray-600">Bu ürünü bekleyen sipariş yok.</p>
            </div>
        <?php else: ?>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                <?php
                $kalanStokKoli = $mevcutStokKoli;
                foreach ($bekleyenSiparisler as $index => $siparis):
                    $siparisMiktarKoli = miktarToKoli($siparis['SIPARIS_MIKTAR'], $koliIci);
                    $sevkMiktarKoli = miktarToKoli($siparis['SEVK_EDILEN'], $koliIci);
                    $kalanMiktarKoli = miktarToKoli($siparis['KALAN_MIKTAR'], $koliIci);
                    $rezerveKoli = miktarToKoli($siparis['REZERVE'], $koliIci);
                    // Bu sipariş için stok yeterli mi?
                    $yeterliMi = $kalanStokKoli >= $kalanMiktarKoli;
                    $karsilanabilirKoli = min($kalanStokKoli, $kalanMiktarKoli);
                    $kalanStokKoli = max(0, $kalanStokKoli - $kalanMiktarKoli);

                    $durum_bg = $yeterliMi ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200';
                    $badge_color = $yeterliMi ? 'bg-green-500' : 'bg-red-500';
                ?>
                    <div class="<?php echo $durum_bg; ?> border rounded-lg p-3 relative">
                        <!-- Öncelik badge -->
                        <div class="absolute top-2 right-2">
                            <span class="<?php echo $badge_color; ?> text-white text-xs px-2 py-1 rounded-full font-bold">
                                #<?php echo $index + 1; ?>
                            </span>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3 pr-12">
                            <!-- Sol taraf: Sipariş Bilgileri -->
                            <div>
                                <p class="font-semibold text-gray-800">
                                    <i class="fas fa-file-alt mr-1 text-blue-600"></i>
                                    Fiş No: <?php echo htmlspecialchars((string) $siparis['FICHENO']); ?>
                                </p>
                                <p class="text-sm text-gray-600 mt-1">
                                    <i class="fas fa-user mr-1 text-gray-400"></i>
                                    <?php echo htmlspecialchars((string) $siparis['MUSTERI_ADI']); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <i class="fas fa-hashtag mr-1"></i>
                                    <?php echo htmlspecialchars((string) $siparis['MUSTERI_KODU']); ?>
                                </p>
                                <p class="text-xs text-gray-500 mt-1">
                                    <i class="far fa-calendar mr-1"></i>
                                    <?php echo date('d.m.Y', strtotime((string) $siparis['TARIH'])); ?>
                                    <?php if (!empty($siparis['SATICI'])): ?>
                                        | <i class="fas fa-user-tie mr-1"></i><?php echo htmlspecialchars((string) $siparis['SATICI']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>

                            <!-- Sağ taraf: Miktar Bilgileri -->
                            <div class="border-l border-gray-300 pl-3">
                                <div class="grid grid-cols-2 gap-2 text-sm">
                                    <div>
                                        <p class="text-gray-500">Sipariş (koli):</p>
                                        <p class="font-bold text-red-600"><?php echo koliFormat($siparisMiktarKoli); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-500">Sevk (koli):</p>
                                        <p class="font-bold text-green-600"><?php echo koliFormat($sevkMiktarKoli); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-500">Kalan (koli):</p>
                                        <p class="font-bold text-orange-600"><?php echo koliFormat($kalanMiktarKoli); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-500">Fiyat:</p>
                                        <p class="font-bold text-purple-600"><?php echo tlgoster($siparis['FIYAT']); ?> ₺</p>
                                    </div>
                                </div>

                                <?php if ($siparis['REZERVE'] > 0): ?>
                                    <div class="mt-2 bg-purple-100 rounded px-2 py-1 text-xs">
                                        <i class="fas fa-lock text-purple-600 mr-1"></i>
                                        <span class="text-purple-800 font-semibold">Rezerve: <?php echo koliFormat($rezerveKoli); ?> koli</span>
                                    </div>
                                <?php endif; ?>

                                <?php if (!$yeterliMi): ?>
                                    <div class="mt-2 bg-yellow-100 rounded px-2 py-1 text-xs">
                                        <i class="fas fa-exclamation-circle text-yellow-600 mr-1"></i>
                                        <span class="text-yellow-800">
                                            Sadece <strong><?php echo koliFormat($karsilanabilirKoli); ?></strong> koli karşılanabilir
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Sipariş Detay Linki -->
                        <div class="mt-2 pt-2 border-t border-gray-200">
                            <a href="lg_fis.php?stokhareket=<?php echo $siparis['FIS_ID']; ?>"
                               target="_blank"
                               class="text-xs text-blue-600 hover:text-blue-800 font-semibold">
                                <i class="fas fa-external-link-alt mr-1"></i>Siparişi Aç
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

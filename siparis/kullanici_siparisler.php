<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
include_once(__DIR__ . "/../ayr.php");

// YETKI KONTROLÜ: M23 (Kullanıcı Aktivite Dashboard) veya yönetici
if (m_p_yetki($terminalkullanici, 'M23') != 1 && $yetkidurum !== 0) {
    echo '<div class="text-center text-red-600 p-4">Bu bilgileri görme yetkiniz yok!</div>';
    exit;
}

$kullaniciId = isset($_POST['kullanici_id']) ? intval($_POST['kullanici_id']) : 0;
$donem = $_POST['donem'] ?? 'bu_ay';
$gunRaw = $_POST['gun'] ?? date('Y-m-d');
$gunTs = strtotime((string)$gunRaw);
if ($gunTs === false) {
    $gunTs = strtotime('today');
}
$filtre_gun_sql = date('Y.m.d', $gunTs);

if ($kullaniciId <= 0) {
    echo '<div class="text-center text-red-600 p-4">Geçersiz kullanıcı ID!</div>';
    exit;
}

// Tarih hesaplama (dashboard ile aynı)
switch ($donem) {
    case 'bugun':
        $filtre_tarih_bas = $filtre_gun_sql;
        $filtre_tarih_son = $filtre_gun_sql;
        break;
    case 'bu_hafta':
        $filtre_tarih_bas = date('Y.m.d', strtotime('monday this week'));
        $filtre_tarih_son = date('Y.m.d', strtotime('sunday this week'));
        break;
    case 'bu_ay':
        $filtre_tarih_bas = date('Y.m.01');
        $filtre_tarih_son = date('Y.m.t');
        break;
    case 'son_3_ay':
        $filtre_tarih_bas = date('Y.m.01', strtotime('-2 months'));
        $filtre_tarih_son = date('Y.m.t');
        break;
    case 'son_6_ay':
        $filtre_tarih_bas = date('Y.m.01', strtotime('-5 months'));
        $filtre_tarih_son = date('Y.m.t');
        break;
    case 'bu_yil':
        $filtre_tarih_bas = date('Y.01.01');
        $filtre_tarih_son = date('Y.12.31');
        break;
    default:
        $filtre_tarih_bas = date('Y.m.01');
        $filtre_tarih_son = date('Y.m.t');
}

$satisSiparisWhere = "TRCODE IN (1,20,21,22) AND NETTOTAL > 0 AND ISNULL(CANCELLED,0) = 0";
$satisFaturaWhere = "TRCODE IN (7,8) AND NETTOTAL > 0 AND ISNULL(CANCELLED,0) = 0";

// Kullanıcı bilgisi
$stmtKullaniciBilgi = $dbh->prepare("
    SELECT CODE, DEFINITION_
    FROM LG_SLSMAN
    WHERE LOGICALREF = :kullaniciId
");
$stmtKullaniciBilgi->execute([':kullaniciId' => $kullaniciId]);
$kullaniciBilgi = $stmtKullaniciBilgi->fetch(PDO::FETCH_ASSOC);

if (!$kullaniciBilgi) {
    echo '<div class="text-center text-red-600 p-4">Kullanıcı bulunamadı!</div>';
    exit;
}

// Kullanıcının işlemleri (iptal olmayan satış siparişleri + satış faturaları)
$stmtIslemler = $dbh->prepare("
    SELECT
        F.LOGICALREF AS FIS_ID,
        F.FICHENO,
        F.DATE_ AS TARIH,
        F.NETTOTAL AS TUTAR,
        F.TRCODE,
        CASE
            WHEN F.TIP = 'siparis' THEN 'Satış Siparişi'
            WHEN F.TRCODE = 7 THEN 'Perakende Satış Faturası'
            WHEN F.TRCODE = 8 THEN 'Toptan Satış Faturası'
            ELSE 'Diğer'
        END AS TIP_ADI,
        F.TIP,
        C.DEFINITION_ AS MUSTERI_ADI,
        C.CODE AS MUSTERI_KODU
    FROM (
        -- Satış siparişleri - iptal ve 0 TL hariç
        SELECT LOGICALREF, FICHENO, DATE_, NETTOTAL, TRCODE, CLIENTREF, SALESMANREF, 'siparis' AS TIP
        FROM " . $firmadonem . "ORFICHE
        WHERE {$satisSiparisWhere}
        UNION ALL
        -- Satış faturaları - iptal ve 0 TL hariç
        SELECT LOGICALREF, FICHENO, DATE_, NETTOTAL, TRCODE, CLIENTREF, SALESMANREF, 'fatura' AS TIP
        FROM " . $firmadonem . "INVOICE
        WHERE {$satisFaturaWhere}
    ) F
    LEFT JOIN " . $firma . "CLCARD C ON C.LOGICALREF = F.CLIENTREF
    WHERE F.SALESMANREF = :kullaniciId
        AND CONVERT(VARCHAR, F.DATE_, 102) BETWEEN :tarihBas AND :tarihSon
    ORDER BY F.DATE_ DESC, F.FICHENO DESC
");
$stmtIslemler->execute([':kullaniciId' => $kullaniciId, ':tarihBas' => $filtre_tarih_bas, ':tarihSon' => $filtre_tarih_son]);
$islemler = $stmtIslemler->fetchAll(PDO::FETCH_ASSOC);

// İstatistikler
$toplamIslem = count($islemler);
$toplamTutar = array_sum(array_column($islemler, 'TUTAR'));
$ortalamaFis = $toplamIslem > 0 ? $toplamTutar / $toplamIslem : 0;

// Tip bazlı sayı
$siparisAdet = count(array_filter($islemler, fn($s): bool => $s['TIP'] == 'siparis'));
$faturaAdet = count(array_filter($islemler, fn($s): bool => $s['TIP'] == 'fatura'));
?>

<div class="space-y-4">
    <!-- Kullanıcı Özeti -->
    <div class="bg-gradient-to-r from-indigo-50 to-purple-50 p-4 rounded-lg border border-indigo-200">
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <h3 class="font-bold text-lg text-gray-800"><?php echo htmlspecialchars((string) $kullaniciBilgi['DEFINITION_']); ?></h3>
                <p class="text-sm text-gray-600"><?php echo htmlspecialchars((string) $kullaniciBilgi['CODE']); ?></p>
            </div>
            <i class="fas fa-user-tie text-4xl text-indigo-300"></i>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">
            <div class="bg-white p-3 rounded-lg text-center shadow-sm">
                <p class="text-xs text-gray-500 mb-1">Toplam İşlem</p>
                <p class="text-xl font-bold text-blue-600"><?php echo $toplamIslem; ?></p>
            </div>
            <div class="bg-white p-3 rounded-lg text-center shadow-sm">
                <p class="text-xs text-gray-500 mb-1">Sipariş</p>
                <p class="text-xl font-bold text-orange-600"><?php echo $siparisAdet; ?></p>
            </div>
            <div class="bg-white p-3 rounded-lg text-center shadow-sm">
                <p class="text-xs text-gray-500 mb-1">Fatura</p>
                <p class="text-xl font-bold text-green-600"><?php echo $faturaAdet; ?></p>
            </div>
            <div class="bg-white p-3 rounded-lg text-center shadow-sm">
                <p class="text-xs text-gray-500 mb-1">Toplam Tutar</p>
                <p class="text-lg font-bold text-purple-600"><?php echo number_format((float)$toplamTutar, 2, ',', '.'); ?> ₺</p>
            </div>
        </div>
    </div>

    <!-- İşlem Listesi -->
    <div>
        <h4 class="font-bold text-gray-800 mb-3 flex items-center justify-between">
            <span>
                <i class="fas fa-list text-blue-600 mr-2"></i>
                İşlem Listesi
            </span>
            <span class="text-sm text-gray-500 font-normal">
                <?php echo date('d.m.Y', strtotime(str_replace('.', '-', $filtre_tarih_bas))); ?> - <?php echo date('d.m.Y', strtotime(str_replace('.', '-', $filtre_tarih_son))); ?>
            </span>
        </h4>

        <?php if (empty($islemler)): ?>
            <div class="bg-gray-50 rounded-lg p-6 text-center">
                <i class="fas fa-inbox text-gray-300 text-4xl mb-2"></i>
                <p class="text-gray-600">Bu dönemde işlem bulunamadı.</p>
            </div>
        <?php else: ?>
            <div class="space-y-2 max-h-96 overflow-y-auto">
                <?php foreach ($islemler as $islem): ?>
                    <?php
                    // Tip bazlı renklendirme
                    if ($islem['TIP'] == 'siparis') {
                        $bg_class = 'bg-orange-50 border-orange-200';
                        $icon = 'fa-file-alt';
                        $icon_color = 'text-orange-600';
                        $badge_class = 'bg-orange-500';
                    } else {
                        $bg_class = 'bg-green-50 border-green-200';
                        $icon = 'fa-file-invoice';
                        $icon_color = 'text-green-600';
                        $badge_class = 'bg-green-500';
                    }

                    // Link belirleme (read-only görüntüleme)
                    $link = 'yazdir/fis_goruntule.php?fis=' . $islem['FIS_ID'] . '&tip=' . $islem['TIP'];
                    ?>
                    <div class="<?php echo $bg_class; ?> border rounded-lg p-3 hover:shadow-md transition-shadow">
                        <div class="flex items-start gap-3">
                            <i class="fas <?php echo $icon; ?> <?php echo $icon_color; ?> text-2xl mt-1"></i>

                            <div class="flex-1">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <p class="font-semibold text-gray-800">
                                            Fiş No: <?php echo htmlspecialchars((string) $islem['FICHENO']); ?>
                                        </p>
                                        <span class="<?php echo $badge_class; ?> text-white text-xs px-2 py-1 rounded-full">
                                            <?php echo htmlspecialchars((string) $islem['TIP_ADI']); ?>
                                        </span>
                                    </div>
                                    <p class="text-right">
                                        <span class="font-bold text-lg text-purple-600">
                                            <?php echo number_format((float)$islem['TUTAR'], 2, ',', '.'); ?> ₺
                                        </span>
                                    </p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-2 text-sm text-gray-700">
                                    <div>
                                        <i class="fas fa-user text-gray-400 mr-1"></i>
                                        <span><?php echo htmlspecialchars((string) $islem['MUSTERI_ADI']); ?></span>
                                    </div>
                                    <div>
                                        <i class="far fa-calendar text-gray-400 mr-1"></i>
                                        <span><?php echo date('d.m.Y', strtotime((string) $islem['TARIH'])); ?></span>
                                    </div>
                                </div>

                                <div class="mt-2 pt-2 border-t border-gray-200">
                                    <a href="<?php echo $link; ?>" target="_blank"
                                       class="text-xs text-blue-600 hover:text-blue-800 font-semibold">
                                        <i class="fas fa-external-link-alt mr-1"></i>Detayları Aç
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

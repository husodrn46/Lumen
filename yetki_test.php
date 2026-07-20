<?php
declare(strict_types=1);

// Yetki Test Sayfası - Kullanıcının gerçek yetkilerini gösterir
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/kontrol.php");

// Yetki sütunlarını tanımla
$yetki_sutunlari = function_exists('m_p_yetki_etiketleri')
    ? m_p_yetki_etiketleri()
    : ['YETKI' => 'Yetki Türü (0=Yönetici, 1=Personel, 2=Müşteri)'];

// Veritabanından direkt kullanıcı bilgilerini çek
$sql = "SELECT * FROM M_P_YETKI WHERE PERSONEL = :id";
$stmt = $dbh->prepare($sql);
$stmt->execute([':id' => $terminalkullanici]);
$db_yetkiler = $stmt->fetch(PDO::FETCH_ASSOC);

// m_p_yetki fonksiyonu ile çekilen değerler
$fonksiyon_yetkiler = [];
foreach ($yetki_sutunlari as $sutun => $aciklama) {
    $fonksiyon_yetkiler[$sutun] = m_p_yetki($terminalkullanici, $sutun);
}

// Session cache'i kontrol et
$session_cache = [];
foreach ($yetki_sutunlari as $sutun => $aciklama) {
    $cache_key = 'yetki_' . $terminalkullanici . '_' . $sutun;
    $session_cache[$sutun] = $_SESSION[$cache_key] ?? 'YOK';
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Yetki Test Sayfası</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; }
        .yetki-var { background-color: #10b981; color: white; }
        .yetki-yok { background-color: var(--red,#ef4444); color: white; }
        .yetki-uyumsuz { background-color: #f59e0b; color: white; animation: pulse 2s infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-blue-50 to-blue-200 min-h-screen">

    <!-- Üst Navigasyon -->
    <header class="bg-white shadow-md sticky top-0 z-20">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center">
                    <a href="index.php" class="text-gray-600 hover:text-blue-500 mr-4 transition-colors">
                        <i class="fa fa-home fa-lg"></i>
                    </a>
                    <h1 class="text-xl font-bold text-gray-800">Yetki Test Sayfası</h1>
                </div>
                <a href="?clear_cache=1" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm">
                    <i class="fa fa-trash mr-1"></i> Cache Temizle
                </a>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">

        <?php if (isset($_GET['clear_cache'])):
            m_p_yetki_cache_temizle($terminalkullanici);
            echo '<div class="bg-green-500 text-white p-4 rounded-lg mb-6 text-center">
                <i class="fa fa-check-circle mr-2"></i> Cache temizlendi! Sayfa yenileniyor...
            </div>';
            echo '<meta http-equiv="refresh" content="1;url=yetki_test.php">';
        endif; ?>

        <!-- Kullanıcı Bilgileri -->
        <div class="bg-white p-6 rounded-xl shadow-lg mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fa-solid fa-user-circle text-blue-500 mr-3"></i>
                Kullanıcı Bilgileri
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-blue-50 p-4 rounded-lg">
                    <p class="text-sm text-gray-600">Kullanıcı Adı</p>
                    <p class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars((string) $plasiyer_adi); ?></p>
                </div>
                <div class="bg-blue-50 p-4 rounded-lg">
                    <p class="text-sm text-gray-600">Kullanıcı ID</p>
                    <p class="text-lg font-bold text-gray-800"><?php echo $terminalkullanici; ?></p>
                </div>
                <div class="bg-blue-50 p-4 rounded-lg">
                    <p class="text-sm text-gray-600">Yetki Durumu (kontrol.php)</p>
                    <p class="text-lg font-bold text-gray-800">
                        <?php
                        $yetki_tipi = [0 => '👑 Yönetici', 1 => '👤 Personel', 2 => '🤝 Müşteri'];
                        echo $yetki_tipi[$yetkidurum] ?? '❓ Bilinmiyor';
                        ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Uyumsuzluk Uyarısı -->
        <?php
        $uyumsuzluklar = [];
        foreach ($yetki_sutunlari as $sutun => $aciklama) {
            $db_deger = $db_yetkiler[$sutun] ?? null;
            $fonk_deger = $fonksiyon_yetkiler[$sutun];

            if ($db_deger !== $fonk_deger && $db_deger !== null) {
                $uyumsuzluklar[] = ['sutun' => $sutun, 'aciklama' => $aciklama, 'db' => $db_deger, 'fonk' => $fonk_deger, 'cache' => $session_cache[$sutun]];
            }
        }

        if ($uyumsuzluklar !== []):
        ?>
        <div class="bg-red-100 border-l-4 border-red-500 p-6 rounded-lg mb-6">
            <h3 class="text-lg font-bold text-red-800 mb-3 flex items-center">
                <i class="fa-solid fa-exclamation-triangle mr-2"></i>
                ⚠️ UYUMSUZLUK TESPİT EDİLDİ! (<?php echo count($uyumsuzluklar); ?> adet)
            </h3>
            <p class="text-red-700 mb-4">Veritabanındaki değerler ile m_p_yetki() fonksiyonunun döndüğü değerler uyuşmuyor!</p>
            <div class="bg-white p-4 rounded-lg">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b-2 border-gray-300">
                            <th class="text-left py-2">Yetki</th>
                            <th class="text-left py-2">Açıklama</th>
                            <th class="text-center py-2">Veritabanı</th>
                            <th class="text-center py-2">m_p_yetki()</th>
                            <th class="text-center py-2">Session Cache</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($uyumsuzluklar as $uyumsuzluk): ?>
                        <tr class="border-b border-gray-200">
                            <td class="py-2 font-bold"><?php echo $uyumsuzluk['sutun']; ?></td>
                            <td class="py-2"><?php echo $uyumsuzluk['aciklama']; ?></td>
                            <td class="py-2 text-center">
                                <span class="px-2 py-1 rounded <?php echo $uyumsuzluk['db'] == 1 ? 'bg-green-500 text-white' : 'bg-red-500 text-white'; ?>">
                                    <?php echo $uyumsuzluk['db'] == 1 ? '✓ VAR' : '✗ YOK'; ?>
                                </span>
                            </td>
                            <td class="py-2 text-center">
                                <span class="px-2 py-1 rounded <?php echo $uyumsuzluk['fonk'] == 1 ? 'bg-green-500 text-white' : 'bg-red-500 text-white'; ?>">
                                    <?php echo $uyumsuzluk['fonk'] == 1 ? '✓ VAR' : '✗ YOK'; ?>
                                </span>
                            </td>
                            <td class="py-2 text-center">
                                <span class="px-2 py-1 rounded bg-yellow-500 text-white">
                                    <?php echo $uyumsuzluk['cache'] === 'YOK' ? 'Cache Yok' : ($uyumsuzluk['cache'] == 1 ? '✓ VAR' : '✗ YOK'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="mt-4 bg-yellow-50 p-3 rounded border-l-4 border-yellow-500">
                <p class="text-sm text-yellow-800">
                    <strong>💡 Çözüm:</strong> Yukarıdaki "Cache Temizle" butonuna tıklayın veya sayfayı yenileyin.
                </p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Yetki Tablosu -->
        <div class="bg-white p-6 rounded-xl shadow-lg">
            <h2 class="text-2xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fa-solid fa-shield-halved text-blue-500 mr-3"></i>
                Tüm Yetkiler (Detaylı Karşılaştırma)
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="text-left p-3 border">Yetki Kodu</th>
                            <th class="text-left p-3 border">Açıklama</th>
                            <th class="text-center p-3 border">Veritabanı<br><small>(Gerçek Değer)</small></th>
                            <th class="text-center p-3 border">m_p_yetki()<br><small>(Fonksiyon Sonucu)</small></th>
                            <th class="text-center p-3 border">Session Cache<br><small>(Önbellek)</small></th>
                            <th class="text-center p-3 border">Durum</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yetki_sutunlari as $sutun => $aciklama):
                            $db_deger = $db_yetkiler[$sutun] ?? null;
                            $fonk_deger = $fonksiyon_yetkiler[$sutun];
                            $cache_deger = $session_cache[$sutun];

                            // Uyumsuzluk kontrolü
                            $uyumsuz = ($db_deger !== $fonk_deger && $db_deger !== null);
                            $row_class = $uyumsuz ? 'bg-yellow-50' : '';
                        ?>
                        <tr class="border-b hover:bg-gray-50 <?php echo $row_class; ?>">
                            <td class="p-3 border font-bold"><?php echo $sutun; ?></td>
                            <td class="p-3 border"><?php echo $aciklama; ?></td>
                            <td class="p-3 border text-center">
                                <?php if ($db_deger === null): ?>
                                    <span class="px-3 py-1 rounded bg-gray-400 text-white text-xs">SÜTUN YOK</span>
                                <?php elseif ($db_deger == 1): ?>
                                    <span class="px-3 py-1 rounded bg-green-500 text-white text-xs font-bold">✓ VAR (1)</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded bg-red-500 text-white text-xs font-bold">✗ YOK (<?php echo $db_deger; ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 border text-center">
                                <?php if ($fonk_deger === null): ?>
                                    <span class="px-3 py-1 rounded bg-gray-400 text-white text-xs">NULL</span>
                                <?php elseif ($fonk_deger == 1): ?>
                                    <span class="px-3 py-1 rounded bg-green-500 text-white text-xs font-bold">✓ VAR (1)</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded bg-red-500 text-white text-xs font-bold">✗ YOK (<?php echo $fonk_deger; ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 border text-center">
                                <?php if ($cache_deger === 'YOK'): ?>
                                    <span class="px-3 py-1 rounded bg-gray-300 text-gray-700 text-xs">YOK</span>
                                <?php elseif ($cache_deger == 1): ?>
                                    <span class="px-3 py-1 rounded bg-blue-500 text-white text-xs">✓ VAR (1)</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded bg-red-400 text-white text-xs">✗ YOK (<?php echo $cache_deger; ?>)</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 border text-center">
                                <?php if ($uyumsuz): ?>
                                    <span class="px-3 py-1 rounded yetki-uyumsuz text-xs font-bold">⚠️ UYUMSUZ</span>
                                <?php else: ?>
                                    <span class="px-3 py-1 rounded bg-green-100 text-green-800 text-xs">✓ OK</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Açıklamalar -->
        <div class="bg-blue-50 border-l-4 border-blue-500 p-6 rounded-lg mt-6">
            <h3 class="text-lg font-bold text-blue-800 mb-3">📖 Açıklamalar:</h3>
            <ul class="space-y-2 text-sm text-blue-900">
                <li><strong>Veritabanı:</strong> M_P_YETKI tablosundaki gerçek değer (0 veya 1)</li>
                <li><strong>m_p_yetki():</strong> PHP fonksiyonunun döndürdüğü değer</li>
                <li><strong>Session Cache:</strong> $_SESSION'da saklanan önbellek değeri</li>
                <li><strong class="text-red-600">⚠️ UYUMSUZ:</strong> Veritabanı ile fonksiyon sonucu farklı (cache problemi olabilir)</li>
                <li><strong>SÜTUN YOK:</strong> Bu sütun veritabanında henüz oluşturulmamış</li>
            </ul>
        </div>

        <!-- Debug Bilgileri -->
        <div class="bg-gray-100 p-6 rounded-xl shadow-lg mt-6">
            <h3 class="text-lg font-bold text-gray-800 mb-3 flex items-center">
                <i class="fa-solid fa-bug text-gray-600 mr-2"></i>
                Debug Bilgileri
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs font-mono">
                <div>
                    <p class="text-gray-600">$terminalkullanici:</p>
                    <p class="bg-white p-2 rounded"><?php echo $terminalkullanici; ?></p>
                </div>
                <div>
                    <p class="text-gray-600">$yetkidurum:</p>
                    <p class="bg-white p-2 rounded"><?php echo $yetkidurum; ?></p>
                </div>
                <div>
                    <p class="text-gray-600">$plasiyer_adi:</p>
                    <p class="bg-white p-2 rounded"><?php echo htmlspecialchars((string) $plasiyer_adi); ?></p>
                </div>
                <div>
                    <p class="text-gray-600">Session ID:</p>
                    <p class="bg-white p-2 rounded"><?php echo session_id(); ?></p>
                </div>
            </div>
        </div>

    </main>

</body>
</html>

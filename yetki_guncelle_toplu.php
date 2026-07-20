<?php
declare(strict_types=1);

// Toplu Yetki Güncelleme Sayfası - Tüm yetkileri aç/kapat
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/kontrol.php");

// Sadece yöneticiler erişebilir
if ($yetkidurum != 0) {
    die('Bu sayfaya sadece yöneticiler erişebilir!');
}

// POST işlemi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['personel_id']) && isset($_POST['islem'])) {
    // CSRF koruması (POST istekleri için)
    if (!csrf_verify()) {
        http_response_code(403);
        die('Geçersiz güvenlik doğrulaması.');
    }

    $personel_id = (int)$_POST['personel_id'];
    $islem = $_POST['islem']; // 'hepsini_ac' veya 'hepsini_kapat'

    $deger = ($islem === 'hepsini_ac') ? 1 : 0;

    // Tüm yetki sütunları
    $yetkiler = array_merge(
        m_p_menu_yetki_kodlari(),
        ['ST1', 'ST2', 'ST3', 'CR1', 'CR2', 'CR3', 'CR4', 'SP1', 'SP2', 'SP3', 'SP4']
    );

    // SET clause oluştur
    $set_parts = [];
    foreach ($yetkiler as $yetki) {
        $set_parts[] = "$yetki = $deger";
    }
    $set_clause = implode(', ', $set_parts);

    // UPDATE sorgusu
    $sql = "UPDATE M_P_YETKI SET $set_clause WHERE PERSONEL = :personel_id";

    try {
        $stmt = $dbh->prepare($sql);
        $stmt->execute([':personel_id' => $personel_id]);

        // Cache temizle
        m_p_yetki_cache_temizle($personel_id);

        $mesaj = ($islem === 'hepsini_ac')
            ? "✅ Kullanıcı $personel_id için TÜM YETKİLER AÇILDI!"
            : "✅ Kullanıcı $personel_id için TÜM YETKİLER KAPATILDI!";
        $mesaj_renk = 'green';
    } catch (Exception $e) {
        $mesaj = "❌ Hata: " . $e->getMessage();
        $mesaj_renk = 'red';
    }
}

// Kullanıcı listesi
$stmtKullanicilar = $dbh->prepare("
    SELECT
        S.LOGICALREF AS ID,
        S.DEFINITION_ AS ADI,
        Y.YETKI AS YETKI_TURU
    FROM LG_SLSMAN S
    LEFT JOIN M_P_YETKI Y ON S.LOGICALREF = Y.PERSONEL
    WHERE S.FIRMNR = 1
    ORDER BY S.DEFINITION_
");
$stmtKullanicilar->execute();
$kullanicilar = $stmtKullanicilar->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toplu Yetki Güncelleme</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Avenir Next', 'Montserrat', sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-purple-50 to-purple-200 min-h-screen">

    <header class="bg-white shadow-md sticky top-0 z-20">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center">
                    <a href="index.php" class="text-gray-600 hover:text-purple-500 mr-4 transition-colors">
                        <i class="fa fa-home fa-lg"></i>
                    </a>
                    <h1 class="text-xl font-bold text-gray-800">Toplu Yetki Güncelleme</h1>
                </div>
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">

        <?php if (isset($mesaj)): ?>
        <div class="bg-<?php echo $mesaj_renk; ?>-100 border-l-4 border-<?php echo $mesaj_renk; ?>-500 text-<?php echo $mesaj_renk; ?>-700 p-4 rounded-lg mb-6">
            <p class="font-bold"><?php echo $mesaj; ?></p>
        </div>
        <?php endif; ?>

        <!-- Uyarı -->
        <div class="bg-red-100 border-l-4 border-red-500 p-6 rounded-lg mb-6">
            <h3 class="text-lg font-bold text-red-800 mb-2 flex items-center">
                <i class="fa-solid fa-exclamation-triangle mr-2"></i>
                ⚠️ DİKKAT!
            </h3>
            <p class="text-red-700">Bu sayfa seçilen kullanıcının <strong>TÜM YETKİLERİNİ</strong> toplu olarak açar veya kapatır!</p>
            <p class="text-red-700 mt-2">Lütfen dikkatli kullanın!</p>
        </div>

        <!-- Kullanıcı Listesi -->
        <div class="bg-white p-6 rounded-xl shadow-lg">
            <h2 class="text-2xl font-bold text-gray-800 mb-4 flex items-center">
                <i class="fa-solid fa-users text-purple-500 mr-3"></i>
                Kullanıcı Seç
            </h2>

            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="bg-gray-100">
                            <th class="text-left p-3 border">ID</th>
                            <th class="text-left p-3 border">Kullanıcı Adı</th>
                            <th class="text-center p-3 border">Yetki Türü</th>
                            <th class="text-center p-3 border">İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kullanicilar as $kul):
                            $yetki_tipi = [0 => '👑 Yönetici', 1 => '👤 Personel', 2 => '🤝 Müşteri', null => '❓ Tanımsız'];
                            $yetki_text = $yetki_tipi[$kul['YETKI_TURU']] ?? '❓ Tanımsız';
                        ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 border font-bold"><?php echo $kul['ID']; ?></td>
                            <td class="p-3 border"><?php echo htmlspecialchars((string) $kul['ADI']); ?></td>
                            <td class="p-3 border text-center"><?php echo $yetki_text; ?></td>
                            <td class="p-3 border text-center space-x-2">
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Bu kullanıcının TÜM YETKİLERİNİ AÇMAK istediğinize emin misiniz?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="personel_id" value="<?php echo $kul['ID']; ?>">
                                    <input type="hidden" name="islem" value="hepsini_ac">
                                    <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded text-sm">
                                        <i class="fa fa-check-circle mr-1"></i> Hepsini Aç
                                    </button>
                                </form>

                                <form method="POST" style="display: inline;" onsubmit="return confirm('Bu kullanıcının TÜM YETKİLERİNİ KAPATMAK istediğinize emin misiniz?');">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="personel_id" value="<?php echo $kul['ID']; ?>">
                                    <input type="hidden" name="islem" value="hepsini_kapat">
                                    <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded text-sm">
                                        <i class="fa fa-times-circle mr-1"></i> Hepsini Kapat
                                    </button>
                                </form>

                                <a href="yetki_test.php?kullanici_id=<?php echo $kul['ID']; ?>" target="_blank" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm inline-block">
                                    <i class="fa fa-eye mr-1"></i> İncele
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Bilgilendirme -->
        <div class="bg-blue-50 border-l-4 border-blue-500 p-6 rounded-lg mt-6">
            <h3 class="text-lg font-bold text-blue-800 mb-3">📖 Nasıl Kullanılır:</h3>
            <ol class="space-y-2 text-sm text-blue-900 list-decimal list-inside">
                <li><strong>"Hepsini Aç"</strong> butonu: M1-M20, ST1-ST3, CR1-CR4, SP1-SP4 tüm yetkiler <strong>1</strong> yapılır</li>
                <li><strong>"Hepsini Kapat"</strong> butonu: Tüm yetkiler <strong>0</strong> yapılır</li>
                <li><strong>"İncele"</strong> butonu: Kullanıcının mevcut yetkilerini gösterir</li>
                <li>İşlem sonrası otomatik olarak <strong>cache temizlenir</strong></li>
                <li>Kullanıcının yeniden giriş yapması gerekmez</li>
            </ol>
        </div>

    </main>

</body>
</html>

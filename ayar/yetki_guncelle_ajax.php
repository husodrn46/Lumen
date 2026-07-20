<?php
declare(strict_types=1);

// Output buffer'ı temizle (ayr.php ve kontrol.php'den gelen HTML çıktılarını önlemek için)
ob_start();
// Gerekli yapılandırma dosyalarını ve güvenlik kontrolünü yükle.
require_once __DIR__ . '/../ayr.php';
require_once __DIR__ . '/ayr.php';
require_once __DIR__ . '/../kontrol.php';
require_once __DIR__ . '/admin_guard.php';
ayar_require_m16($terminalkullanici, true);
// Buffer'ı temizle ve at
ob_end_clean();

// JSON yanıt için header ayarla
header('Content-Type: application/json; charset=utf-8');

// Sadece POST isteklerini kabul et
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Sadece POST istekleri kabul edilir']);
    exit;
}

ayar_require_csrf(true);

// Parametreleri al ve validate et
$personel_id = isset($_POST['personel_id']) ? (int)$_POST['personel_id'] : 0;
$yetki_kodu = isset($_POST['yetki_kodu']) ? trim((string) $_POST['yetki_kodu']) : '';
$yeni_deger = isset($_POST['yeni_deger']) ? (int)$_POST['yeni_deger'] : 0;

// Validasyon
if ($personel_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz personel ID']);
    exit;
}

// Yetki kodu kontrolü — TEK KAYNAK: yetki_tanimlari.php (M + CR + ST + SP)
require_once __DIR__ . '/yetki_tanimlari.php';
$izin_verilen_yetkiler = yetki_tum_kodlar();
$izin_verilen_yetkiler[] = 'YETKI'; // Kullanici turu degistirme (0=Yonetici, 1=Personel, 2=Musteri)
if (!in_array($yetki_kodu, $izin_verilen_yetkiler)) {
    echo json_encode(['success' => false, 'message' => 'Geçersiz yetki kodu: ' . $yetki_kodu]);
    exit;
}

// Değer kontrolü
if ($yetki_kodu === 'YETKI') {
    // YETKI icin 0, 1, 2 kabul edilir
    if (!in_array($yeni_deger, [0, 1, 2], true)) {
        echo json_encode(['success' => false, 'message' => 'YETKI için gecerli değerler: 0 (Yönetici), 1 (Personel), 2 (Müşteri)']);
        exit;
    }
} else {
    // M* yetkileri icin 0 veya 1
    if ($yeni_deger !== 0 && $yeni_deger !== 1) {
        echo json_encode(['success' => false, 'message' => 'Geçersiz değer: ' . $yeni_deger]);
        exit;
    }
}

try {
    // Önce M_P_YETKI tablosunda hangi sütunlar var kontrol et
    $columns_check = $dbh->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'M_P_YETKI' AND COLUMN_NAME = :yetki_kodu");
    $columns_check->execute([':yetki_kodu' => $yetki_kodu]);
    $column_exists = $columns_check->fetch(PDO::FETCH_ASSOC);

    if (!$column_exists) {
        echo json_encode([
            'success' => false,
            'message' => "M_P_YETKI tablosunda '$yetki_kodu' sütunu bulunamadı. Lütfen tabloyu kontrol edin."
        ]);
        exit;
    }

    // Personel için yetki kaydı var mı kontrol et
    $check_sql = "SELECT PERSONEL FROM M_P_YETKI WHERE PERSONEL = :personel_id";
    $check_stmt = $dbh->prepare($check_sql);
    $check_stmt->execute([':personel_id' => $personel_id]);
    $yetki_kayit = $check_stmt->fetch(PDO::FETCH_ASSOC);

    if ($yetki_kayit) {
        // Kayıt varsa UPDATE yap
        $update_sql = "UPDATE M_P_YETKI SET $yetki_kodu = :deger WHERE PERSONEL = :personel_id";
        $update_stmt = $dbh->prepare($update_sql);
        $result = $update_stmt->execute([
            ':deger' => $yeni_deger,
            ':personel_id' => $personel_id
        ]);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Yetki başarıyla güncellendi',
                'action' => 'update',
                'yetki_kodu' => $yetki_kodu,
                'yeni_deger' => $yeni_deger,
                'personel_id' => $personel_id
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'UPDATE sorgusu başarısız oldu'
            ]);
        }
    } else {
        // Kayıt yoksa INSERT yap
        $insert_sql = "INSERT INTO M_P_YETKI (PERSONEL, $yetki_kodu, YETKI, SIFRE) VALUES (:personel_id, :deger, 1, '')";
        $insert_stmt = $dbh->prepare($insert_sql);
        $result = $insert_stmt->execute([
            ':personel_id' => $personel_id,
            ':deger' => $yeni_deger
        ]);

        if ($result) {
            echo json_encode([
                'success' => true,
                'message' => 'Yetki kaydı oluşturuldu ve yetki atandı',
                'action' => 'insert',
                'yetki_kodu' => $yetki_kodu,
                'yeni_deger' => $yeni_deger,
                'personel_id' => $personel_id
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'INSERT sorgusu başarısız oldu'
            ]);
        }
    }

} catch (PDOException $e) {
    error_log('yetki_guncelle_ajax hata: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Veritabani hatasi'
    ]);
}
?>

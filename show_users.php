<?php
/**
 * LG_SLSMAN Kullanıcılarını Listele
 * M_P_YETKI tablosuna kullanıcı eklemek için LOGICALREF değerlerini gösterir
 *
 * GÜVENLİK: Bu dosya sadece admin kullanıcıları tarafından erişilebilir
 */

// Güvenlik: Oturum ve yetki kontrolü
include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");

// Sadece admin (seviye 0) erişebilir.
// KRİTİK DÜZELTME (2026-07-13): eskiden $terminalyetki kontrol ediliyordu ama
// bu değişken hiçbir yerde tanımlı DEĞİL — daima null; null != 0 => false olduğu
// için guard hiç tetiklenmiyor ve HERHANGİ bir personel bu admin sayfasına
// (admin hesabı açma + şifre değiştirme) erişebiliyordu. Doğru değişken
// kontrol.php'nin tanımladığı $yetkidurum. Fail-closed: tanımsızsa reddet.
if ((int) ($yetkidurum ?? 1) !== 0) {
    http_response_code(403);
    die("Bu sayfaya erişim yetkiniz bulunmamaktadır.");
}

echo "<h2>LG_SLSMAN Kullanıcıları</h2>";
echo "<p>M_P_YETKI tablosuna eklemek için aşağıdaki kullanıcıları kullanabilirsiniz:</p>";
echo "<hr>";

try {
    // Mevcut $dbh bağlantısını kullan (ayr.php'den)

    // LG_SLSMAN tablosundan aktif kullanıcıları getir
    $stmt = $dbh->prepare("
        SELECT
            L.LOGICALREF,
            L.CODE,
            L.FIRMNR,
            L.ACTIVE,
            M.PERSONEL,
            M.SIFRE,
            M.YETKI
        FROM LG_SLSMAN L
        LEFT JOIN M_P_YETKI M ON L.LOGICALREF = M.PERSONEL
        WHERE L.FIRMNR = :firmano
        ORDER BY L.ACTIVE, L.CODE
    ");
    $stmt->bindValue(':firmano', (int)$firmano, PDO::PARAM_INT);
    $stmt->execute();

    echo "<table border='1' cellpadding='10' cellspacing='0' style='border-collapse:collapse; width:100%;'>";
    echo "<tr style='background:#4CAF50; color:white;'>";
    echo "<th>LOGICALREF</th>";
    echo "<th>Kullanıcı Kodu</th>";
    echo "<th>Firma</th>";
    echo "<th>Durum</th>";
    echo "<th>M_P_YETKI</th>";
    echo "<th>Yetki Seviyesi</th>";
    echo "<th>İşlem</th>";
    echo "</tr>";

    $active_users = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $is_active = $row['ACTIVE'] == 0;
        $has_yetki = $row['PERSONEL'] !== null;

        echo "<tr style='background:" . ($is_active ? '#f9f9f9' : '#ffeeee') . "'>";
        echo "<td><strong>" . htmlspecialchars($row['LOGICALREF']) . "</strong></td>";
        echo "<td><strong>" . htmlspecialchars($row['CODE']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($row['FIRMNR']) . "</td>";
        echo "<td>" . ($is_active ? '✅ Aktif' : '❌ Pasif') . "</td>";
        echo "<td>" . ($has_yetki ? '✅ Var' : '❌ Yok') . "</td>";
        echo "<td>" . ($has_yetki ?
            ($row['YETKI'] == 0 ? 'Admin' : ($row['YETKI'] == 1 ? 'Personel' : 'Müşteri'))
            : '-') . "</td>";
        echo "<td>";

        if ($is_active) {
            $active_users[] = $row;
            if (!$has_yetki) {
                echo "<form method='post' style='display:inline-block;'>";
                echo csrf_field();
                echo "<input type='hidden' name='add_user' value='" . $row['LOGICALREF'] . "'>";
                echo "<input type='hidden' name='code' value='" . htmlspecialchars($row['CODE']) . "'>";
                echo "<input type='password' name='user_password' placeholder='Şifre belirle' required minlength='6'
                      style='padding:5px; border:1px solid #ccc; border-radius:3px; width:120px;'>";
                echo " <button type='submit'
                      style='padding:5px 10px; background:#4CAF50; color:white; border:none; cursor:pointer; border-radius:3px;'>
                      Admin Olarak Ekle</button>";
                echo "</form>";
            } else {
                // Şifre değiştirme formu
                echo "<form method='post' style='display:inline-block;'>";
                echo csrf_field();
                echo "<input type='hidden' name='personel' value='" . $row['PERSONEL'] . "'>";
                echo "<input type='password' name='new_password' placeholder='Yeni şifre' required minlength='6'
                      style='padding:5px; border:1px solid #ccc; border-radius:3px; width:120px;'>";
                echo " <button type='submit' name='change_password'
                      style='padding:5px 10px; background:#FF9800; color:white; border:none; cursor:pointer; border-radius:3px;'>
                      Şifre Değiştir</button>";
                echo "</form>";
            }
        }

        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";

    echo "<br><p><strong>Toplam " . count($active_users) . " aktif kullanıcı bulundu.</strong></p>";

    // Şifre değiştirme işlemi (POST + CSRF)
    if (isset($_POST['change_password'])) {
        if (!csrf_verify()) {
            die("Geçersiz CSRF token.");
        }

        $personel = (int)$_POST['personel'];
        $new_password = trim($_POST['new_password']);

        if (strlen($new_password) < 6) {
            echo "<hr><div style='color:red;'>❌ Şifre en az 6 karakter olmalıdır.</div>";
        } else {
            echo "<hr><h3>Şifre Değiştiriliyor...</h3>";

            try {
                // Şifreyi hashle
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $dbh->prepare("UPDATE M_P_YETKI SET SIFRE = :sifre WHERE PERSONEL = :personel");
                $update->bindParam(':sifre', $hashed_password);
                $update->bindParam(':personel', $personel, PDO::PARAM_INT);
                $update->execute();

                echo "✅ <strong style='color:green;'>Şifre başarıyla değiştirildi!</strong><br>";
                echo "<p>Güvenlik nedeniyle şifre gösterilmez.</p>";
                echo "<p><a href='show_users.php'>Geri dön</a></p>";
            } catch (PDOException $e) {
                echo "❌ <strong style='color:red;'>Hata!</strong><br>";
                echo htmlspecialchars($e->getMessage());
            }
        }
    }

    // Kullanıcı ekleme işlemi (POST + CSRF)
    if (isset($_POST['add_user']) && isset($_POST['code'])) {
        if (!csrf_verify()) {
            die("Geçersiz CSRF token.");
        }

        $logicalref = (int)$_POST['add_user'];
        $code = $_POST['code'];
        $user_password = trim($_POST['user_password']);

        if (strlen($user_password) < 6) {
            echo "<hr><div style='color:red;'>❌ Şifre en az 6 karakter olmalıdır.</div>";
        } else {
            echo "<hr><h3>Kullanıcı Ekleniyor...</h3>";

            // Şifreyi hashle
            $hashed_password = password_hash($user_password, PASSWORD_DEFAULT);

            $insert_sql = "
            INSERT INTO M_P_YETKI (
                PERSONEL, SIFRE, YETKI,
                M1, M2, M3, M4, M5, M6, M7, M8, M9, M10, M11, M12, M13, M15, M16, M17, M20, M21, M22, M23,
                ST1, ST2, ST3,
                CR1, CR2, CR3, CR4,
                SP1, SP2, SP3, SP4
            ) VALUES (
                :personel, :sifre, 0,
                1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1, 1,
                1, 1, 1,
                1, 1, 1, 1,
                1, 1, 1, 1
            )";

            try {
                $stmt = $dbh->prepare($insert_sql);
                $stmt->bindParam(':personel', $logicalref, PDO::PARAM_INT);
                $stmt->bindParam(':sifre', $hashed_password);
                $stmt->execute();

                echo "✅ <strong style='color:green;'>Kullanıcı başarıyla eklendi!</strong><br>";
                echo "<div style='background:#f0f8ff; padding:15px; margin:10px 0; border-left:4px solid #2196F3;'>";
                echo "<strong>Giriş Bilgileri:</strong><br>";
                echo "Kullanıcı Adı: <code>" . htmlspecialchars($code) . "</code><br>";
                echo "Şifre: <em>(Güvenlik nedeniyle gösterilmez)</em><br>";
                echo "Yetki: <strong>Administrator (Tüm Yetkiler)</strong>";
                echo "</div>";
                echo "<p><a href='giris.php'>Giriş sayfasına git</a></p>";
                echo "<p><a href='show_users.php'>Kullanıcı listesine dön</a></p>";
            } catch (PDOException $e) {
                echo "❌ <strong style='color:red;'>Hata!</strong><br>";
                echo htmlspecialchars($e->getMessage());
            }
        }
    }

} catch (PDOException $e) {
    echo "❌ <strong style='color:red;'>Veritabanı Hatası!</strong><br>";
    echo htmlspecialchars($e->getMessage());
}

echo "<hr>";
echo "<p><a href='test_table.php'>M_P_YETKI Tablosu Kontrol</a> | <a href='index.php'>Ana Sayfa</a> | <a href='giris.php'>Giriş</a></p>";
?>

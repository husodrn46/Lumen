<?php
declare(strict_types=1);

// Gerekli yapılandırma ve loglama dosyalarını yükle.
include_once(__DIR__ . "/ayr.php");
include_once(__DIR__ . "/log_ip.php");

// Güvenli oturum ve yetki kontrolünü zorunlu kıl.
require_once __DIR__ . '/kontrol.php';

if (isset($_POST['stokkodu'])) {
    // CSRF koruması (POST istekleri için)
    if (!csrf_verify()) {
        http_response_code(403);
        die('Geçersiz güvenlik doğrulaması.');
    }

    $stokkodu = $_POST['stokkodu'];
    $stokadi = $_POST['stokadi'];
    $stokid = $_POST['stokid'];
    $stokozelkod2 = $_POST['stokozelkod2'];
    $basari_durumu = true;

    if (empty($stokkodu) || strlen((string) $stokkodu) > 40) {
        echo '<div class="alert alert-danger" role="alert">Stok kodu boş veya 40 karakterden büyük olamaz.</div>';
        $basari_durumu = false;
    }
    if (empty($stokadi) || strlen((string) $stokadi) > 60) {
        echo '<div class="alert alert-danger" role="alert">Stok adı boş olamaz.</div>';
        $basari_durumu = false;
    }

    // Parametrik Sorgu: Güncellenecek stok kodu başka bir kartta kullanılıyor mu kontrol et
    $stmt_check = $dbh->prepare("SELECT CODE FROM " . $firma . "ITEMS WHERE NOT LOGICALREF = :stokid AND CODE = :stokkodu");
    $stmt_check->bindParam(':stokid', $stokid, PDO::PARAM_INT);
    $stmt_check->bindParam(':stokkodu', $stokkodu);
    $stmt_check->execute();

    if ($stmt_check->fetch()) {
        $basari_durumu = false;
        echo '<div class="alert alert-danger" role="alert"><strong>Dikkat! "' . htmlspecialchars((string) $stokkodu) . '"</strong> stok kodu başka bir karta atanmış.</div>';
    }

    if ($basari_durumu == true) {
        // Parametrik Sorgu: Stok bilgilerini güncelle
        $stmt_update = $dbh->prepare("UPDATE " . $firma . "ITEMS SET CODE = :stokkodu, NAME = :stokadi, SPECODE2 = :stokozelkod2 WHERE LOGICALREF = :stokid");
        $stmt_update->bindParam(':stokkodu', $stokkodu);
        $stmt_update->bindParam(':stokadi', $stokadi);
        $stmt_update->bindParam(':stokozelkod2', $stokozelkod2);
        $stmt_update->bindParam(':stokid', $stokid, PDO::PARAM_INT);
        
        if($stmt_update->execute()){
            echo '<div class="alert alert-success" role="alert">Stok başarıyla güncellendi.</div>';
        } else {
            echo '<div class="alert alert-danger" role="alert">Stok güncellenirken bir hata oluştu.</div>';
        }
    }
}

if (isset($_GET['stokid'])) {
    $stokid = $_GET['stokid'];
    // Parametrik Sorgu: Düzenlenecek stok bilgilerini getir
    $stmt_sorgustok = $dbh->prepare("SELECT CODE, NAME, SPECODE2 FROM " . $firma . "ITEMS WHERE LOGICALREF = :stokid");
    $stmt_sorgustok->bindParam(':stokid', $stokid, PDO::PARAM_INT);
    $stmt_sorgustok->execute();
    $sorgustok = $stmt_sorgustok->fetch(PDO::FETCH_ASSOC);
}
?>

<style>
	form input,
	select {
		width: 99%;
	}
</style>

<div align="center" class="meydan">
	<button class="iptal" onclick="window.location='stok_tara.php'">İptal</button>
	<?php if (m_p_yetki($terminalkullanici, 'SP2') == 1 || $yetkidurum == 0) { ?>
		<form id="frm" name="frm" class="form-horizontal " action="" method="POST">
				<?php echo csrf_field(); ?>
			<header>STOK DÜZENLEME PANELİ</header><br>
			KODU
			<input type="text" style="width: 200px;" id="stokkodu" name="stokkodu" data-toggle="tooltip"
				title="Stok kodunu düzenleyin" value="<?php echo isset($sorgustok['CODE']) ? htmlspecialchars((string) $sorgustok['CODE']) : ''; ?>" autofocus required>

			<div class="form-control-line"></div><br>
			ADI
			<input style="width: 200px;" type="text" id="stokadi" name="stokadi" title="STOK ADI GİRİNİZ" value="<?php echo isset($sorgustok['NAME']) ? htmlspecialchars((string) $sorgustok['NAME']) : ''; ?>" required><br><br>
			ÖZEL KOD2
			<select style="width: 200px;" id="stokozelkod2" name="stokozelkod2" data-toggle="tooltip" title="STOK grup GİRİNİZ">
				<option value="<?php echo isset($sorgustok['SPECODE2']) ? htmlspecialchars((string) $sorgustok['SPECODE2']) : ''; ?>" SELECTED>
					<?php echo isset($sorgustok['SPECODE2']) ? htmlspecialchars((string) $sorgustok['SPECODE2']) : 'Seçilmemiş'; ?>
				</option>
				<option value="">Boş Seçenek</option>
				<?php
				$sql = $dbh->prepare("SELECT SPECODE, DEFINITION_ FROM " . $firma . "SPECODES WHERE SPETYP2=1 ORDER BY LOGICALREF");
				$sql->execute();
				while ($satir = $sql->fetch(PDO::FETCH_ASSOC)) {
					echo '<option value="' . htmlspecialchars((string) $satir['SPECODE']) . '">' . htmlspecialchars((string) $satir['SPECODE']) . ' - ' . htmlspecialchars((string) $satir['DEFINITION_']) . '</option>';
				} ?>
			</select><br>
			<input type="hidden" id="stokid" name="stokid" value="<?php echo isset($stokid) ? htmlspecialchars((string) $stokid) : ''; ?>" required><br>
			<button type="submit" title="STOK BİLGİLERİNİ GÜNCELLE">STOK DÜZENLE</button>
		</form>
		<?php if (isset($stokid)) {
			echo '<a href="barkod_ekle.php?stok=' . htmlspecialchars((string) $stokid) . '">Barkod Ekle</a>';
		}
	} ?>
</div>
<?php
declare(strict_types=1);

// user_control.php

// Güvenlik: Oturum ve yetki kontrolü
include_once(__DIR__ . "/ayr.php");
include(__DIR__ . "/kontrol.php");

// Sadece admin (seviye 0) erişebilir.
// KRİTİK DÜZELTME (2026-07-13): $terminalyetki tanımsız (daima null) idi; guard
// hiç çalışmıyordu. Doğru değişken $yetkidurum. Fail-closed.
if ((int) ($yetkidurum ?? 1) !== 0) {
    http_response_code(403);
    die("Bu sayfaya erişim yetkiniz bulunmamaktadır.");
}

// SQLite veritabanına bağlanıyoruz.
try {
    $sqliteDb = new PDO('sqlite:loglar.db');
    $sqliteDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Veritabanı bağlantı hatası: " . $e->getMessage());
}

// Silme işlemi: POST ile ve CSRF doğrulaması ile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
    if (!csrf_verify()) {
        die("Geçersiz CSRF token.");
    }
    $id = intval($_POST['id']);
    $stmt = $sqliteDb->prepare("DELETE FROM users WHERE id = :id");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    header("Location: user_control.php");
    exit;
}

// Ekleme işlemi: POST ile gönderilen formda action=add
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    if (!csrf_verify()) {
        die("Geçersiz CSRF token.");
    }
    $ip = $_POST['ip'];
    $fingerprint = $_POST['fingerprint'];
    $name = $_POST['name'];
    $stmt = $sqliteDb->prepare("INSERT INTO users (ip, fingerprint, name) VALUES (:ip, :fingerprint, :name)");
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':fingerprint', $fingerprint);
    $stmt->bindValue(':name', $name);
    $stmt->execute();
    header("Location: user_control.php");
    exit;
}

// Güncelleme işlemi: POST ile gönderilen formda action=edit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    if (!csrf_verify()) {
        die("Geçersiz CSRF token.");
    }
    $id = intval($_POST['id']);
    $ip = $_POST['ip'];
    $fingerprint = $_POST['fingerprint'];
    $name = $_POST['name'];
    $stmt = $sqliteDb->prepare("UPDATE users SET ip = :ip, fingerprint = :fingerprint, name = :name WHERE id = :id");
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':fingerprint', $fingerprint);
    $stmt->bindValue(':name', $name);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    header("Location: user_control.php");
    exit;
}

// Tüm kullanıcıları çek
$stmt = $sqliteDb->prepare("SELECT * FROM users ORDER BY id DESC");
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kullanıcı Kontrol Paneli</title>
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Font Awesome (ikonlar için) -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <!-- DataTables CSS -->
  <link rel="stylesheet" href="https://cdn.datatables.net/1.10.25/css/jquery.dataTables.min.css">
</head>
<body>
<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary">
  <div class="container">
    <a class="navbar-brand" href="#">Kullanıcı Kontrol Paneli</a>
  </div>
</nav>

<div class="container mt-4">
  <h1 class="mb-4">Kullanıcı Yönetimi</h1>
  
  <!-- Yeni Kullanıcı Ekleme Formu -->
  <div class="card mb-4">
    <div class="card-header">
      Yeni Kullanıcı Ekle
    </div>
    <div class="card-body">
      <form method="POST" action="user_control.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="row mb-3">
          <div class="col">
            <label for="ip" class="form-label">IP Adresi</label>
            <input type="text" class="form-control" id="ip" name="ip" required>
          </div>
          <div class="col">
            <label for="fingerprint" class="form-label">Fingerprint</label>
            <input type="text" class="form-control" id="fingerprint" name="fingerprint" required>
          </div>
          <div class="col">
            <label for="name" class="form-label">Kullanıcı Adı</label>
            <input type="text" class="form-control" id="name" name="name" required>
          </div>
        </div>
        <button type="submit" class="btn btn-success">Kullanıcı Ekle</button>
      </form>
    </div>
  </div>
  
  <!-- Kullanıcı Listesi -->
  <div class="table-responsive">
    <table id="usersTable" class="table table-bordered table-striped">
      <thead class="table-dark">
        <tr>
          <th>ID</th>
          <th>IP Adresi</th>
          <th>Fingerprint</th>
          <th>Kullanıcı Adı</th>
          <th>Oluşturulma Tarihi</th>
          <th>İşlemler</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $user): ?>
        <tr data-user='<?php echo json_encode($user); ?>'>
          <td><?php echo $user['id']; ?></td>
          <td><?php echo htmlspecialchars((string) $user['ip']); ?></td>
          <td><?php echo htmlspecialchars((string) $user['fingerprint']); ?></td>
          <td><?php echo htmlspecialchars((string) $user['name']); ?></td>
          <td><?php echo htmlspecialchars((string) $user['created_at']); ?></td>
          <td>
            <button class="btn btn-primary btn-sm editUser"><i class="fa fa-edit"></i> Düzenle</button>
            <form method="POST" action="user_control.php" style="display:inline;" onsubmit="return confirm('Silmek istediğinizden emin misiniz?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?php echo $user['id']; ?>">
              <button type="submit" class="btn btn-danger btn-sm">
                <i class="fa fa-trash"></i> Sil
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Kullanıcı Düzenleme Modal'ı -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="user_control.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" id="editUserId">
        <div class="modal-header">
          <h5 class="modal-title" id="editUserModalLabel">Kullanıcı Düzenle</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="editIp" class="form-label">IP Adresi</label>
            <input type="text" class="form-control" id="editIp" name="ip" required>
          </div>
          <div class="mb-3">
            <label for="editFingerprint" class="form-label">Fingerprint</label>
            <input type="text" class="form-control" id="editFingerprint" name="fingerprint" required>
          </div>
          <div class="mb-3">
            <label for="editName" class="form-label">Kullanıcı Adı</label>
            <input type="text" class="form-control" id="editName" name="name" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Kapat</button>
          <button type="submit" class="btn btn-primary">Güncelle</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- jQuery, Bootstrap 5 Bundle ve DataTables JS -->
<script src="/tm/js/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.10.25/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function(){
  $('#usersTable').DataTable({
    "order": []
  });
  
  // Kullanıcı düzenleme modal'ı için tıklama olayı
  $('.editUser').on('click', function(){
    var user = $(this).closest('tr').data('user');
    $('#editUserId').val(user.id);
    $('#editIp').val(user.ip);
    $('#editFingerprint').val(user.fingerprint);
    $('#editName').val(user.name);
    var editModal = new bootstrap.Modal(document.getElementById('editUserModal'));
    editModal.show();
  });
});
</script>
</body>
</html>

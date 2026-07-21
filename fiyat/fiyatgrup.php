<?php
declare(strict_types=1);

require_once __DIR__ . '/../kontrol.php';
if ($fiyatgruplu == 1) {
  $fiyatgrup = isset($_SESSION['fiyatgrup']) ? (int) $_SESSION['fiyatgrup'] : 0;
}
?>


		

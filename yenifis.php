<?php
declare(strict_types=1);
 include_once(__DIR__ . "/ayr.php"); ?>



<?php
require_once __DIR__ . '/kontrol.php';
$bilgi=fopen('ayr.php','r');
$tarih=date("d/m/Y H:i:s");
$handle = printer_open("Microsoft Print to PDF");
	//printer_start_doc($handle, "My Document");
//printer_start_page($handle);
printer_set_option($handle,PRINTER_MODE,"RAW");
printer_set_option($handle, PRINTER_TEXT_ALIGN, PRINTER_TA_LEFT);
//$font = printer_create_font("Tahoma", 90, 25, 00, false, false, false, 1);
//printer_select_font($handle, $font);

//$pen = printer_create_pen(PRINTER_PEN_SOLID, 1, "000000");
//printer_select_pen($handle, $pen);

printer_write($handle, $bilgi);
//printer_delete_font($font);
//printer_delete_pen($pen);
//printer_end_page($handle);
//printer_end_doc($handle);
printer_close($handle);
//echo  '<script>window.location="index.php";</script>' ;
?>


<?php
declare(strict_types=1);

// Backward compatible endpoint: the old HTML-as-XLS export was flaky in PHP 8+
// (numeric strings -> number_format TypeError). Serve the real .xlsx export instead.
require __DIR__ . '/stok_hareket_excel_xlsx.php';

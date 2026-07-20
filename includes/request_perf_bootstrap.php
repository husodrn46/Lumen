<?php
declare(strict_types=1);

require_once __DIR__ . '/app_observability.php';

if (function_exists('app_register_global_error_handlers')) {
    app_register_global_error_handlers();
}

if (function_exists('app_register_request_perf_logger')) {
    app_register_request_perf_logger(['boot' => 'auto_prepend']);
}

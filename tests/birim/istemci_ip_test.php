<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/istemci_ip.php';

test('istemci IP: doğrudan bağlantıda X-Forwarded-For yok sayılır', function (): void {
    esit('203.0.113.9', lumen_istemci_ip(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '192.168.1.5'], ['127.0.0.1']));
});

test('istemci IP: proxy arkasında sahte sol değer kullanılmaz', function (): void {
    // İstemci "192.168.1.5" yazdı; proxy gerçek adresi (198.51.100.7) sona ekledi.
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.168.1.5, 198.51.100.7'];
    esit('198.51.100.7', lumen_istemci_ip($server, ['127.0.0.1']));
});

test('istemci IP: zincirdeki güvenilir proxyler atlanır', function (): void {
    $server = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.5'];
    esit('198.51.100.7', lumen_istemci_ip($server, ['127.0.0.1', '10.0.0.5']));
});

test('istemci IP: boş/bozuk zincirde proxy adresi döner', function (): void {
    esit('127.0.0.1', lumen_istemci_ip(['REMOTE_ADDR' => '127.0.0.1'], ['127.0.0.1']));
    esit('127.0.0.1', lumen_istemci_ip(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => 'bozuk'], ['127.0.0.1']));
});

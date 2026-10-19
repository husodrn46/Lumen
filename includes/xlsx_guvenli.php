<?php

declare(strict_types=1);

/**
 * Excel (PhpSpreadsheet) çıktılarında formül enjeksiyonunu engeller.
 *
 * PhpSpreadsheet, "=" ile başlayan metni formül olarak yazar. Çek açıklaması,
 * fiş notu, ürün adı gibi kullanıcı girdileri dışa aktarıldığında
 * =HYPERLINK(...) veya =cmd|... gibi değerler Excel'de çalışan formüle
 * dönüşüyordu. Lumen çıktıları bilinçli olarak formül yazmaz; bu bağlayıcı
 * tüm "=" ile başlayan metinleri düz metin olarak yazar. Sayılar ve diğer
 * değerler varsayılan davranışla bağlanır.
 */

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

if (!class_exists('LumenGuvenliDegerBaglayici', false)) {
    final class LumenGuvenliDegerBaglayici extends DefaultValueBinder
    {
        public function bindValue(Cell $cell, mixed $value): bool
        {
            if (is_string($value) && preg_match('/^\s*[=+\-@\t\r]/', $value) === 1 && !is_numeric(trim($value))) {
                $cell->setValueExplicit($value, DataType::TYPE_STRING);
                return true;
            }
            return parent::bindValue($cell, $value);
        }
    }
}

if (!function_exists('lumen_xlsx_guvenli_baglayici')) {
    function lumen_xlsx_guvenli_baglayici(): void
    {
        Cell::setValueBinder(new LumenGuvenliDegerBaglayici());
    }
}

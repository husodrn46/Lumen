# Content Security Policy geçişi

Lumen'in CSP başlığı nesne, taban adresi, form hedefi ve çerçeveleme için sıkıdır.
Ancak mevcut ekranlarda satır içi JavaScript/CSS ve tarayıcıda Tailwind derleme kodu
bulunduğu için `script-src` halen `'unsafe-inline'` ve `'unsafe-eval'` içerir. Bu iki izni
hazırlıksız kaldırmak sipariş, yazdırma ve rapor ekranlarını bozar.

Güvenli geçiş sırası:

1. Tailwind, jQuery ve diğer tarayıcı bağımlılıklarını sabitlenmiş yerel dosyalara taşıyın.
2. Satır içi olayları (`onclick` vb.) harici, sürüm kontrollü JavaScript dosyalarına taşıyın.
3. Kalan zorunlu satır içi betik ve stillere istek başına nonce uygulayın.
4. Yeni politikayı önce `Content-Security-Policy-Report-Only` olarak yayımlayıp ihlalleri izleyin.
5. `unsafe-eval`, ardından `unsafe-inline` izinlerini kaldırıp zorunlu politikaya geçin.

Apache (`.htaccess`), IIS (`web.config`) ve demo nginx yapılandırması aynı politika
direktiflerini taşımalı; değişiklikler üçünde birlikte yapılmalıdır.

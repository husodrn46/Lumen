# Offline favori eşleme ön kontrolü

Bu araç yerel bir JSON snapshot okur ve sonucu stdout’a yazar. SQL, ağ, ortam değişkeni veya uygulama bootstrap bağlantısı kullanmaz; kaynak dosyayı değiştirmez. Import SQL’i üretmez, UUID oluşturmaz. Üretim verisi için dışa aktarım yetkisi sağlamaz.

Sentetik kullanım (depo kökünden):

```sh
PYTHONDONTWRITEBYTECODE=1 python3 tools/favorites_dry_run.py tests/fixtures/favorites-dry-run.json
PYTHONDONTWRITEBYTECODE=1 python3 tests/favorites-dry-run-test.py
```

Girdi şeması sentetik fixture’da gösterilmiştir. Tek `connection` ve 1–999 arasında tek `firma` içerir. Her kişi, actor eşlemesi ve tercih satırı aynı kapsamı açıkça taşır. `persons`: `personel` ve `code`; `actor_links`: `personel` ve açık `actor_id`; `preferences`: `user_code` ve CSV `favorites`.

`code_match_policy: exact-reviewed`, veri sahibinin bu snapshot için CODE birebir karşılaştırmasını doğruladığını belirtir. Araç bu doğrulamayı kendisi yapamaz. SQL kolasyonunun veya farklı kaynakların kod eşitliğinin belirsiz olduğu gerçek snapshot için bu değer varsayımla eklenmemelidir. Büyük/küçük harf veya Unicode eşitliği tahmin edilmez.

Girdi en fazla 1 MiB; her koleksiyon en fazla 10.000 satır. Eksik/bilinmeyen alanlar, yinelenen JSON anahtarları ve kapsam uyuşmazlığı tüm kontrolü durdurur. Fazla favoriler kırpılmaz, geçersiz kart anahtarları silinmez. Normal boşluk/büyük harf/tekrarlı kart temizliği mevcut favori sözleşmesiyle uyumludur ve `normalization_changed` olarak gösterilir.

Kod, kişi ve actor eşleme çakışmaları raporlanır. Sorunlu tercih satırları aday listesine girmez. Kullanılmayan çelişkili/öksüz actor linkleri de snapshot’ın inceleme gerektirdiğini gösterir. Boş veya başarılı sonuç canlı kaynakla tamlık, güncellik, menü yetkisi ya da aktarım onayı anlamına gelmez.

Çıkış kodları: `0` ön kontrol sonucu incelemeye hazır; `2` çözülmesi gereken eşleme/değer sorunları; `1` geçersiz veya okunamayan girdi. Satır numaraları sıfırdan başlar. Çıktı CODE, kişi ID, UUID ve kart değerlerini içermez; sayaç, hata sınıfı, satır numarası, kart sayısı ve normalize değer SHA-256 içerir. Hash ve satır numaraları yine ilişkilendirilebilir; gerçek veri raporu herkese açık paylaşılmamalıdır. Hatalı girdi ayrıntısı stdout/stderr’e dökülmez.

Gerçek kullanım için önce yetkili snapshot kaynağı, personel/kod ilişkisi, onaylı UUID eşlemeleri, kolasyon/eşitlik politikası, kapsam ve veri saklama/paylaşma sınırı belirlenmelidir. Mevcut görevde gerçek LOGO veya üretim Lumen verisi okunmadı; yalnız fixture çalıştırıldı. Pozitif sertifika/hostname TLS kabulü ayrı açık iştir.

## Hedef Lumen snapshot karşılaştırması (şema 2)

Eski şema 1 korunur ve yalnız eşleme kontrolü yapar; çıktı `target_comparison: not_provided` ile hedefin kontrol edilmediğini belirtir. Şema 2'de ayrıca `target_snapshot_complete: true` ve `target_favorites` zorunludur. Her hedef satırı aynı `connection`, `firma`, açık `actor_id` ve CSV `favorites` içerir. Sentetik çakışma örneği: `tests/fixtures/favorites-target-dry-run.json` (çıkış kodu 2).

Tamlık işareti, snapshot sağlayıcısının bu kapsamı eksiksiz sağladığı beyanıdır; araç doğrulayamaz. Eski/eksik snapshot veya sonradan yapılan değişiklik için güvenli yazma garantisi yoktur. Bu nedenle araç yalnız inceleme raporudur; yürütülebilir aktarım planı değildir.

- Hedef yok: `insert_candidate`; hâlâ insan incelemesi gerektirir.
- Normalize kaynak ve hedef aynı: `no_change`; hedefin ham içeriği korunur, yeniden biçimlendirme önerilmez. Ham hedef SHA-256 gelecekte yeniden karşılaştırma için gösterilir; burada kontrol veya yazma yapılmaz.
- Farklı hedef, boş/dolu farkı dahil: `target_value_conflict`; aday listesinden çıkarılır. Kaynak veya hedefin üstün olduğu varsayılmaz.
- Aynı actor için birden fazla hedef kayıt: çakışma. Eşleme dışında kalan hedef kayıtlar raporlanır, silinmez veya başka kullanıcıya taşınmaz.
- Hedef firma/bağlantı uyuşmazlığı, geçersiz UUID/favori listesi veya tamlık beyanı yoksa tüm kontrol durur.

Geri alma: aracın kendisi hiç yazmadığından hedefin geri alınmasına gerek yoktur. Gelecekteki aktarım dilimi için transaction, tekrar çalıştırma, yazmadan hemen önce beklenen mevcut değer/kapsam denetimi ve geri dönüş snapshot'ı ayrı kabul gerektirir. Bu paket bunların gerçek SQL doğrulaması değildir.

## Sentetik transaction/idempotency modeli

`tests/support/favorites_transfer_simulator.py` yalnız test yardımcısıdır. Parametre olarak DB yolu/DSN almaz; tek özel SQLite `:memory:` bağlantısı açar. Üretim adaptörü, migration veya gerçek aktarım CLI'si değildir. `prepare()` ancak tam ve çakışmasız şema 2 snapshot'ını immutable işlem öğelerine dönüştürür; kapsam/kimlik ve beklenen ham hedef değeri korunur.

Model bir transaction içinde kişi–actor eşlemesini ve hedefin halen snapshot'taki ham değere eşit olduğunu yeniden denetler. Hedef yoksa ekler; mevcut ve aynıysa hiç değiştirmez. Farklı hedefin üzerine yazma yoktur. Çok satırlı işlemde sonradan çıkan hata önceki eklemeleri ve idempotency kaydını birlikte geri alır.

İstek anahtarı firma ve bağlantı kapsamında tutulur. Aynı anahtar/aynı niyet önceki sonucu döndürür; farklı niyet aynı anahtarla reddedilir. Başarılı isteğin tekrarı sonradan kullanıcı tarafından değiştirilen favoriyi geri yazmaz. Bu tekrar sonucu geçmiş işlemin sonucudur; hedefin şu anda aynı olduğuna dair kabul değildir. Sırası farklı aynı batch aynı niyettir.

SQLite transaction'ı ve tek bağlantı üzerindeki RLock sentetik yarışları sıralar. Bu sonuç SQL Server bağımsız bağlantı/kilit, deadlock, süreç çökmesi, ağ kopması, commit sonucunun belirsizliği veya TLS garantisi değildir. Hedef değişip sonra aynı ham değere geri dönerse sürüm bilgisi olmadığından fark edilmez; model mevcut değer eşitliğini denetler. Gerçek aktarım öncesi bunlar için ayrı motor kabulü ve gerekirse revision sözleşmesi gerekir. Gerçek yürütücüde yetkilendirme/izin denetimi ayrıca zorunludur; model kimlik doğrulamaz.

```sh
PYTHONDONTWRITEBYTECODE=1 python3 tests/favorites-transfer-simulation-test.py
SKIP_RECTOR=1 bash scripts/validate.sh
```

Python normalizasyonu PHP'nin ASCII `strtolower` ve `trim` karakterleriyle eşleştirilmiştir. Unicode Kelvin işareti ve NBSP gibi daha geniş Python dönüşümleri kabul edilmez. Gerçek PHP normalizasyon fonksiyonuyla diferansiyel regresyon testi vardır.

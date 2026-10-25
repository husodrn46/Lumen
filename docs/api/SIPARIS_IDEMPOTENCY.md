# API siparişlerinde tekrar gönderim koruması

`POST /api/siparis_olustur.php` isteğinde `Idempotency-Key` başlığı kullanılması
önerilir. Ağ bağlantısı koptuğunda veya istemci yanıtı alamadığında aynı anahtar ve
aynı JSON gövdesiyle istek güvenle yeniden gönderilebilir.

## Sözleşme

- Başlık UUIDv4 (`xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx`) veya 64 haneli hex
  değer olmalıdır.
- Aynı anahtar ve aynı gövde önceki başarılı yanıtı `200` ile döndürür; yeni sipariş
  oluşturmaz.
- Aynı anahtar farklı gövde, kullanıcı, firma veya dönemle gönderilirse `409` döner.
- Aynı anahtarla eşzamanlı istekler SQL Server transaction kilidiyle sıraya alınır.
  Kilit 5 saniyede alınamazsa `503` ve `Retry-After: 1` döner; istemci aynı anahtar
  ve gövdeyle yeniden denemelidir.
- Sipariş ile saklanan başarılı yanıt tek transaction içinde commit edilir. Hata
  durumunda ikisi de geri alınır.
- Anahtarın kendisi veritabanına yazılmaz; yalnız SHA-256 özeti saklanır.

Örnek:

```http
POST /api/siparis_olustur.php HTTP/1.1
Authorization: Bearer <token>
Content-Type: application/json
Idempotency-Key: <her-sipariş-için-yeni-UUIDv4>

{"cari_id":4,"kalemler":[{"stok_id":2,"miktar":12}]}
```

## Geriye uyumluluk

Başlıksız eski istemciler çalışmaya devam eder ancak tekrar gönderim koruması
almaz. Yeni ve güncellenen istemciler her mantıksal sipariş için yeni bir anahtar
üretmeli; aynı siparişi yeniden denerken anahtarı ve gövdeyi değiştirmemelidir.

## Yükseltme

Canlı ortamda uygulama dosyaları yayımlandıktan sonra doğru veritabanına bağlı
sunucuda `php scripts/sema_guncelle.php` komutunu çalıştırın. Betik tüm Lumen şema
betiklerini yeniden uygular ve `M_API_IDEMPOTENCY` tablosunu oluşturur.
Şema kurulmadan gönderilen anahtarlı istek, korumasız sipariş açmak yerine `503`
döner. Anahtarsız eski istemciler bu sırada çalışmaya devam eder.

Tamamlanmış anahtar kayıtları varsayılan olarak saklanır. Operasyonel arşivleme
yapılacaksa kayıt silindikten sonra aynı anahtarın yeniden işlem oluşturabileceği
hesaba katılmalıdır.

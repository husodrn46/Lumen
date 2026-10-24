# Lumen Kullanım Kılavuzu

Bu kılavuz Lumen'i her gün kullanan muhasebe, satış ve depo personeli ile işletme sahibi için yazıldı. Ekranlardaki adlar ve düğme yazıları, programda gördüğünüz şekliyle **tırnak içinde** verilmiştir.

> **İki çalışma modu vardır.** Lumen ya **LOGO Tiger'a bağlı** çalışır (kayıtlar LOGO'dadır, Lumen ön yüzdür) ya da **Bağımsız** çalışır (fatura, kasa/banka ve tanımlar Lumen'in içindedir; LOGO gerekmez). Hangi modda olduğunuzu "Sistem Ayarları" → "Sistem ve Yedek" → "Çalışma modu" bölümünde görürsünüz.
>
> Yalnız bağımsız modda olan bölümler bu kılavuzda **[Bağımsız mod]** diye işaretlidir. Bu bölümler LOGO'ya bağlı modda menüde hiç görünmez.

## İçindekiler

1. [Giriş ve ilk kurulumdan sonra yapılacaklar](#1-giriş-ve-ilk-kurulumdan-sonra-yapılacaklar)
2. [Tanımlar](#2-tanımlar)
3. [Faturalar ve belgeler](#3-faturalar-ve-belgeler-bağımsız-mod)
4. [Kasa ve banka](#4-kasa-ve-banka-bağımsız-mod)
5. [Çekler](#5-çekler)
6. [Cari: bakiye, ekstre, cari kartı](#6-cari-bakiye-ekstre-cari-kartı)
7. [Stok](#7-stok)
8. [Siparişler](#8-siparişler)
9. [Raporlar](#9-raporlar)
10. [Yedekleme ve geri yükleme](#10-yedekleme-ve-geri-yükleme)
11. [Sık sorulanlar ve sorun çözme](#11-sık-sorulanlar-ve-sorun-çözme)

---

## 1. Giriş ve ilk kurulumdan sonra yapılacaklar

### Ana menü

Ana sayfada yalnız yetkiniz olan kutucuklar görünür. Olası kutucuklar:

- "Yeni Sipariş", "Siparişler", "Tüm Siparişler", "Mağaza Satış", "Döviz İşlemleri"
- "Müşteri Bakiye", "Hızlı Erişim"
- "Stok Ara", "Fiyat Listesi", "Barkodlar", "Bekleyen Ürünler", "Stoklar"
- "Sil" (taslak siparişler), "Günlük İşlemler", "Görevler", "Raporlar"
- "Sistem Ayarları", "Loglar", "Personel İzleme"
- **[Bağımsız mod]** "Faturalar" (ya da depo personeli için "Üretim ve Sayım"), "Kasa ve Banka", "Tanımlar", "Yeni Ürün"
- **[Yalnız LOGO modu]** "Faturalama" (BETA; ayarlardan açılmışsa)

Üst çubukta "Bildirimler" (zil), "Ayarlar" (kendi görünüm tercihleriniz), "Şifre" ve "Çıkış" düğmeleri vardır.

### Giriş yapmak

1. "Kullanici Adi" ve "Sifre" alanlarını doldurun.
2. Kendi cihazınızdaysanız "Beni Hatirla (30 gun)" kutusunu işaretleyebilirsiniz.
3. "Giris Yap" düğmesine basın.

**Dikkat**

- Çok sayıda hatalı denemede hesap geçici olarak kilitlenir; ekranda kaç dakika bekleyeceğiniz yazar.
- Kilidi yönetici erken açabilir: "Sistem Ayarları" → "Guvenlik" → "Hesap Kilidi Yönetimi" → "Kullanıcı Adı ile Aç".
- Paylaşılan bilgisayarda "Beni Hatirla" işaretlemeyin.

### Başlangıç adımları (yönetici)

Yönetici ana sayfada "Başlangıç Adımları" kartını görür. Eksik adımın yanındaki "Ayarla" bağlantısı ilgili ekranı açar. Kartta şu adımlar bulunur: firma adı, logo, fiş yazıcısı (isteğe bağlı) ve ilk sipariş.

### Firma bilgileri ve logo

**Ne işe yarar:** Fatura ve irsaliye çıktılarında, ekstrede ve raporlarda firma bilgileriniz görünür.

**[Bağımsız mod] Nasıl yapılır:**

1. Ana menüden "Tanımlar" → "Firma" sekmesine girin.
2. "Ticari unvan" (zorunlu), "Vergi dairesi", "Vergi no / TCKN", "MERSİS no", "Adres", "Telefon", "E-posta" alanlarını doldurun.
3. "Kaydet" düğmesine basın.

**Logo (iki modda da):** "Sistem Ayarları" → "Sistem Ayarları" → "Marka / Logo" sekmesi.

**Dikkat:** Vergi no 10 hane, TCKN 11 hane olmalıdır; değilse kayıt reddedilir.

### Dönem kilidi [Bağımsız mod]

**Ne işe yarar:** Kapattığınız aya ya da yıla sonradan kimse fatura veya kasa fişi giremez, var olanı da iptal edemez.

**Nasıl yapılır:**

1. "Tanımlar" → "Firma" sekmesini açın.
2. "Dönem kilidi (bu tarih ve öncesi kapalı)" alanına kapanan dönemin son gününü girin (örneğin 31.12.2025).
3. "Kaydet" düğmesine basın.

**Dikkat**

- Bu tarih ve öncesine belge girilemez, iptal edilemez. Alanı boş bırakırsanız kilit yoktur.
- Bugünden ileri bir tarih girilemez.
- Kilidi geri almak için alanı silip kaydedin. Bunu yalnız muhasebeciyle konuşarak yapın.

### Kullanıcılar ve yetkiler

Yetkiler "Sistem Ayarları" → "Kullanıcı & Yetki" ekranından yönetilir. Ekranın üstünde şu sekmeler vardır: "Kullanıcılar", "İzin Matrisi", "Roller", "Özel Cari", "Denetim".

**Yeni kullanıcı eklemek**

1. "Kullanıcılar" sekmesinde "Yeni Kullanıcı" düğmesine basın.
2. "Personel Kodu", "Ad Soyad" ve "Parola" alanlarını doldurun. Parola en az 8 karakter olmalıdır.
3. "Yetki Tipi" seçin:
   - "Yönetici — tüm yetkiler"
   - "Personel — kısıtlı (matristen ayarlanır)"
   - "Müşteri — salt okunur"
   - "Entegrasyon — yalnız API, salt okunur" (başka programların bağlanması içindir, kişiye verilmez)
4. "Kaydet" düğmesine basın.
5. Personel tipindeki kullanıcının hangi ekranları göreceğini "İzin Matrisi" sekmesinden belirleyin.

Listede her kullanıcının yanında "Düzenle", "Pasife al" / "Aktife al" ve "Sil" düğmeleri bulunur.

**İzin Matrisi**

1. Kullanıcının satırında istediğiniz yetkileri açın ya da kapatın.
2. Satırdaki "— Hazır Rol —" seçiciyle hazır bir rolü tek seferde uygulayabilirsiniz.
3. Altta "Değişiklikleri Kaydet" düğmesine basın. "Sıfırla" kaydedilmemiş değişiklikleri geri alır.

**Roller**

- "Sabit Roller" değiştirilemez: "Satış Temsilcisi", "Mağaza / Kasiyer", "Depo Görevlisi", "Üretim", "Yönetici".
- Kendi rolünüzü "Yeni Rol" ile ekleyin: "Rol Adı", "Açıklama", "Yetki Tipi" ve "Yetkiler" alanlarını doldurup "Kaydet" düğmesine basın.

**Satış, depo ve yönetici farkı (örnek yetkiler)**

| Kişi | Tipik yetkiler (İzin Matrisi'ndeki adlarıyla) |
|---|---|
| Satış personeli | "Yeni Sipariş", "Siparişler", "Müşteri Bakiye", "Stok Ara + Fiyat Listesi", gerekirse "Cari Bakiye Görme" |
| Muhasebe | **[Bağımsız mod]** "Faturalama", "Kasa ve Banka İşlemleri"; ayrıca "Çek İşlemleri", "Raporlar" |
| Depo personeli | "Stoklar (Üretim)". Bağımsız modda "Faturalama" yetkisi yoksa menüde yalnız "Üretim ve Sayım" görünür. Bu kişi fatura göremez, fiyat ve maliyet görmez ve giremez. |
| Yönetici | Tüm yetkiler. Yedekleme ve geri yükleme yalnız yöneticiye açıktır. |

**Dikkat**

- "Ayarlar" yetkisi verdiğiniz personel tanımları değiştirebilir, ama yedek işlemlerini yapamaz.
- Stok miktarını yalnız "Stok Miktarı Görme" yetkisi olan görür.
- Satış fiyatını yalnız "Satış Fiyatı Görme/Düzenleme" yetkisi olan görür.
- Siparişte fiyat değiştirmek için "Sipariş Fiyatı Değiştirme" yetkisi gerekir.

### Özel cari kısıtı

**Ne işe yarar:** Belirli carileri (örneğin büyük müşterileri) seçtiğiniz kullanıcılardan ya da herkesten gizler.

**Nasıl yapılır:**

1. "Kullanıcı & Yetki" → "Özel Cari" sekmesini açın.
2. Üstten kullanıcıyı seçin. Herkese uygulanacak kural için "Tum Kullanicilar" seçeneğini seçin.
3. Cariyi arayıp listeye ekleyin.
4. Kuralı denemek için ekrandaki test bölümünde bir personel ve bir cari seçin. Bu bölüm hiçbir kaydı değiştirmez.

**Dikkat:** Gizlenen cari bakiye ve sipariş ekranlarında görünmez. Bağımsız modda o cariye fatura kesilemez, kasa fişi girilemez, belgesi iptal edilemez ve çekleri görünmez. Yöneticiler bu kısıttan etkilenmez.

---

## 2. Tanımlar

### Cari (müşteri / tedarikçi) açmak

**Nasıl yapılır:**

1. Ana menüden "Yeni Sipariş" kutucuğuna girin (cari listesi açılır).
2. Sağ üstteki "Yeni Cari" düğmesine basın. Bu düğme "Yeni Cari" yetkisi olanlara görünür.
3. "Cari Unvani" (zorunlu), "Telefon", "Sehir", "Ilce" alanlarını doldurun. "Cari Kodu (otomatik)" kendiliğinden verilir.
4. "Cariyi Olustur" düğmesine basın.

**Dikkat:** Vergi bilgileri, adres ve açılış bakiyesiyle çok sayıda cari girmek için "Excel'den aktar" kullanın (aşağıda).

### Cari kartını düzenlemek [Bağımsız mod]

1. Cari kartını açın (bakiye listesinde ya da aramada cariye tıklayın) ve "Düzenle"ye basın ("Yeni Cari" yetkisi ya da yönetici gerekir).
2. Unvan, tür (alıcı / satıcı / ikisi), vergi dairesi ve vergi no / TCKN, adres, telefon, e-posta, vade günü, risk limiti, özel kod ve pazarlamacıyı girin.
3. "Kaydet"e basın.

**Dikkat**

- Cari kodu değişmez.
- Vergi no 10, TCKN 11 hanedir; son hanesi (kontrol basamağı) tutmayan numara kabul edilmez.
- Vade günü, vadesi girilmeyen faturaların vadesini belirler (vade takibi bu tarihe bakar).
- Kullanmadığınız cariyi silmek yerine "Aktif" işaretini kaldırın.

### Ürün (stok kartı)

**[Bağımsız mod] Tek ürün eklemek:**

1. Ana sayfada "Yeni Ürün" kutucuğuna basın ("Yeni Stok" yetkisi ya da yönetici gerekir).
2. "Ürün kodu", "Ürün adı", "Ana birim" (ör. ADET) ve "KDV %" girin; isterseniz "Grup" ve "Özel kod".
3. Satış ve alış fiyatını yazın (ana birim, KDV hariç, TL). Satış fiyatı için fiyat görme, alış fiyatı için faturalama yetkisi gerekir.
4. "Diğer birimler"de koli, paket gibi birimleri ve çarpanını yazın (1 KOLİ = 24 ADET ise 24).
5. "Barkodlar"a barkodları yazın; koli barkodu ise yanındaki birimden KOLİ'yi seçin.
6. "Kaydet"e basın; ürünün stok kartı açılır.

**Düzenlemek:** Stok kartında sağ üstteki "Düzenle" düğmesi.

**Dikkat**

- Ürün kodu ve ana birim kaydedildikten sonra değişmez.
- Fiyatı değiştirdiğinizde yeni fiyat bugünden geçerli olur; geçmiş belgeler eski fiyatla kalır.
- Belgelerde kullanılmış bir birim karttan kaldırılamaz; hareket görmüş ürün hizmete çevrilemez.
- Satmayacağınız ürünü silmek yerine "Aktif" işaretini kaldırın.
- Çok sayıda ürün için "Tanımlar" → "Excel'den aktar" → "Stok kartları" daha hızlıdır.

- Barkod eklemek için "Barkodlar" kutucuğu kullanılır.
- Fiyatları görmek için "Fiyat Listesi" kutucuğu kullanılır.
- Ürünün özetini görmek için "Stok kartı"nı açın (bölüm 7).

### Kasa ve banka hesapları [Bağımsız mod]

1. "Tanımlar" → "Kasa ve banka" sekmesine girin.
2. "Tür" seçin: "Kasa" ya da "Banka".
3. "Kod" (örneğin KASA.02) ve "Ad" yazın. Banka için "Banka" ve "IBAN" alanlarını da doldurun.
4. "Kaydet" düğmesine basın. Değiştirmek için listedeki "Düzenle" düğmesini, kullanımdan kaldırmak için "Pasifleştir" düğmesini kullanın.

**Dikkat**

- IBAN TR ile başlamalı ve 26 karakter olmalıdır.
- Bakiyesi olan hesap pasifleştirilemez; önce bakiyeyi virmanla başka hesaba aktarın.
- En az bir etkin kasa kalmalıdır.
- Hesaplar yalnız TL'dir.

### Depolar ve birimler [Bağımsız mod]

**Depo eklemek**

1. "Tanımlar" → "Depolar" sekmesine girin.
2. "Kod", "Ad" ve "Ambar no" alanlarını doldurun. Ambar no 0 olan depo merkez depodur.
3. "Kaydet" düğmesine basın.

**Birim eklemek**

1. "Tanımlar" → "Birimler" sekmesine girin.
2. "Kod" (örneğin KOLI) ve "Ad" alanlarını doldurun.
3. "Kaydet" düğmesine basın.

**Dikkat**

- Merkez depo ve içinde stok olan depo pasifleştirilemez.
- Bir üründe ya da belgede kullanılan birim silinemez. "Sil" düğmesi yalnız hiç kullanılmamış birimde görünür.

### Döviz kurları [Bağımsız mod]

**Nasıl yapılır:**

1. "Tanımlar" → "Döviz kurları" sekmesine girin.
2. En kolay yol "Bugünün kurlarını TCMB'den al" düğmesidir.
3. Kuru elle girmek için "Kur gir" bölümünde "Tarih", "Para birimi", "Döviz alış" ve "Döviz satış" alanlarını doldurup "Kaydet" düğmesine basın.

**Dikkat**

- Dövizli faturada belge tarihinin döviz **alış** kuru önerilir. O gün için kur yoksa önceki en yakın günün kuru önerilir.
- TCMB'ye ulaşılamazsa ekran bunu söyler; o durumda kuru elle girin.
- Dövizli fatura keseceğiniz günlerde kuru girmeyi alışkanlık edinin.

### Toplu fiyat güncelleme [Bağımsız mod]

**Ne işe yarar:** Zam ya da indirim dönemlerinde çok sayıda ürünün fiyatını tek seferde değiştirir.

1. "Tanımlar" → "Toplu fiyat" sekmesine girin.
2. "Fiyat" (satış ya da alış), isterseniz "Ürün grubu" ve "Kodu şununla başlayan" seçin; boş bırakırsanız bütün ürünler.
3. "Değişiklik" olarak "Yüzde (%)" ya da "Tutar (₺)" seçip "Miktar" yazın: zam için 10, indirim için -5 gibi.
4. İsterseniz "Yuvarla" ile fiyatları 0,50 ₺ ya da 1 ₺ gibi yuvarlak sayılara çekin.
5. "Geçerlilik tarihi" seçin (genellikle bugün) ve "Önizle"ye basın: hangi ürünün fiyatı neye dönüşecek, liste halinde görünür.
6. Doğruysa "… ürünün fiyatını güncelle" düğmesine basın.

**Dikkat**

- Önizleme hiçbir şeyi değiştirmez; güncelleme düğmesine basana kadar fiyatlar aynı kalır.
- Eski fiyat silinmez; yeni fiyat seçilen tarihten geçerli olur. Geçmiş belgeler etkilenmez.
- Aynı gün ikinci kez uygularsanız o günün fiyatı yeniden hesaplanır (önceki toplu değişikliğin üzerine).
- Yalnız ana birimde TL fiyatı olan aktif ürünler değişir; fiyatı hiç girilmemiş ürün atlanır.

### Excel'den aktar [Bağımsız mod]

**Ne işe yarar:** Çok sayıda cari ya da ürün kartını tek seferde yükler. İsterseniz açılış bakiyesini ve açılış stokunu da birlikte girersiniz.

**Nasıl yapılır:**

1. "Tanımlar" → "Excel'den aktar" sekmesine girin.
2. "Cari kartları" ya da "Stok kartları" kutusunda "Şablonu indir" bağlantısına basın.
3. Şablonu Excel'de doldurun. Başlık satırını değiştirmeyin.
   - Cari şablonu: Kod ve Unvan zorunludur. Tür, vergi bilgileri, il/ilçe/adres, telefon, e-posta, vade günü ve açılış bakiyesi isteğe bağlıdır. Pozitif bakiye "cari bize borçlu", negatif bakiye "biz cariye borçluyuz" demektir.
   - Stok şablonu: Kod ve Ad zorunludur. Birim (boşsa ADET), KDV % (boşsa 20), grup, satış ve alış fiyatı (KDV hariç, TL), barkod, koli içi adet ve açılış miktarı (merkez depo) isteğe bağlıdır.
4. Dosyayı seçin (.xlsx, .xls ya da .csv) ve "Açılış tarihi" girin.
5. Önceden var olan kodların bilgilerini de yenilemek istiyorsanız "Var olan kodların kart bilgilerini güncelle" kutusunu işaretleyin.
6. "Yükle ve kontrol et" düğmesine basın.
7. "Önizleme" kutusunu inceleyin: "Dosyadaki satır", "Eklenecek", "Zaten var (atlanır)" ya da "Güncellenecek", "Hatalı" sayıları ve satır satır hata listesi görünür.
8. Hatalı satırlar varsa iki yolunuz var:
   - Dosyayı düzeltip yeniden yükleyin.
   - "Hatalı satırları atla" kutusunu işaretleyin; hatasız satırlar aktarılır.
9. "Aktar" düğmesine basın. Vazgeçmek için "Vazgeç" düğmesine basın.

**Dikkat**

- Aktarım tek seferde yapılır: bir hata çıkarsa hiçbir satır eklenmez.
- Açılış bakiyesi ve açılış miktarı yalnız yeni eklenen kartlara yazılır.
- Onaylanmayan yüklemeler bir saat sonra silinir. Önizleme süresi dolarsa dosyayı yeniden yükleyin.

---

## 3. Faturalar ve belgeler [Bağımsız mod]

Ana menüden "Faturalar" kutucuğuna girin; "Faturalar ve Belgeler" listesi açılır. Bu ekran için "Faturalama" yetkisi gerekir.

### Belge türleri

| Tür | Stok | Cari |
|---|---|---|
| "Satış Faturası" | düşer | cari borçlanır |
| "Alış Faturası" | artar | cari alacaklanır |
| "Satış İade Faturası" | artar | cari alacaklanır |
| "Alış İade Faturası" | düşer | cari borçlanır |
| "Satış İrsaliyesi" / "Alış İrsaliyesi" | düşer / artar | etkilemez |
| "Üretimden Giriş", "Sayım Fazlası" | artar | cari yok |
| "Sayım Eksiği" | düşer | cari yok |

### Listeyi kullanmak

1. "Tür", "Başlangıç", "Bitiş" alanlarını seçin; isterseniz "Ara" kutusuna belge no ya da cari yazın.
2. "Listele" düğmesine basın.
3. Belgeyi açmak için belge numarasına ya da göz simgesine tıklayın.

İptal edilmiş belgeler listede "İptal" etiketiyle kalır.

### Satış / alış faturası, irsaliye, iade kesmek

1. "Faturalar" ekranında "Yeni Belge" düğmesine basın.
2. "Belge türü" seçin.
3. "Cari" alanına kod ya da unvan yazıp listeden seçin.
4. "Tarih", gerekirse "Vade", "Depo" ve "Belge / dış no" alanlarını doldurun.
5. "Satırlar" bölümünde ürün kodu, adı ya da barkodla ürün arayıp seçin.
6. Her satırda "Miktar", "Birim", "Fiyat (KDV hariç)", "İsk. %" ve "KDV %" alanlarını kontrol edin. Satırı kaldırmak için çarpı düğmesine basın.
7. Altta "Brüt", "İskonto", "KDV" ve "Genel toplam" kendiliğinden hesaplanır.
8. İsterseniz "Açıklama" yazın ve "Belgeyi kes" düğmesine basın.

**Dikkat**

- "Belgeyi kes" düğmesi en az bir satır ve (gerekiyorsa) cari seçilmeden açılmaz.
- İade faturası bir siparişe bağlanamaz; doğrudan "Yeni Belge" ile kesilir.
- Cari kartındaki "Fatura kes" düğmesi formu o cari seçili olarak açar.
- Tarih 2000 ile bir yıl sonrası arasında olmalıdır.
- Fatura çıktısı bilgi/teslim nüshasıdır, **e-Fatura / e-Arşiv değildir**.

### Siparişten faturalama (kısmi faturalama dahil)

**Ne işe yarar:** Siparişin henüz teslim edilmemiş satırlarını faturaya ya da irsaliyeye aktarır. İsterseniz bir kısmını şimdi, kalanını sonra faturalarsınız.

**Nasıl yapılır:**

1. Siparişi açın ("Siparişler" listesinden).
2. Üstteki "Faturala" düğmesine basın ("Siparişten Belge" ekranı açılır).
3. "Belge türü" seçin: satış siparişi için "Satış Faturası" ya da "Satış İrsaliyesi", alış siparişi için alış karşılıkları.
4. Her satırda "Sipariş", "Teslim edilen" ve "Bu belgede" sütunlarını görürsünüz. Şimdi faturalamak istemediğiniz miktarı "Bu belgede" alanında azaltın.
5. "Belgeyi kes" düğmesine basın.

**Dikkat**

- Fiyat, iskonto ve KDV siparişten gelir; bu ekranda değiştirilmez.
- Siparişte kalandan fazla faturalanamaz.
- Siparişin kalan satırı yoksa ekran "Bu siparişin faturalanacak/sevk edilecek kalan satırı yok." der.

### Alış siparişi (tedarikçiye sipariş)

**Ne işe yarar:** Tedarikçiye verdiğiniz siparişi kaydeder; mal geldikçe alış irsaliyesine ya da alış faturasına aktarırsınız. Bekleyen miktar ürünün stok kartında "Bekleyen alış siparişi" olarak görünür.

1. "Faturalar" → "Yeni Belge" → belge türü "Alış Siparişi".
2. Tedarikçiyi (cari) ve ürünleri, miktar ve fiyatlarıyla girip kaydedin. Sipariş stoğu ve cari bakiyeyi değiştirmez.
3. Mal geldiğinde siparişi açıp "Faturala"ya basın; "Alış İrsaliyesi" ya da "Alış Faturası" seçin, gelen miktarı yazın.
4. İrsaliyeyle aldıysanız fatura gelince irsaliyeyi açıp "Faturala" ile faturasını kesin (stok ikinci kez artmaz).

**Dikkat**

- Kısmi teslim alabilirsiniz; kalan miktar siparişte açık kalır.
- Siparişe bağlı irsaliye ya da fatura varken sipariş iptal edilemez; önce onları iptal edin.
- Satış siparişleri burada değil, sipariş ekranlarında ("Yeni Sipariş", "Siparişler") girilir.

### İrsaliyeden faturalama

**Ne işe yarar:** Malı önce irsaliyeyle gönderip (ya da alıp) faturayı sonra kesersiniz. Stok irsaliyeyle düşer; fatura yalnız cariyi borçlandırır (alışta alacaklandırır), stoğu ikinci kez oynatmaz.

**Nasıl yapılır:**

1. İrsaliyeyi açın ("Faturalar" listesinden).
2. Üstteki "Faturala" düğmesine basın ("İrsaliyeden Fatura" ekranı açılır). Düğme yalnız faturalanmamış satır varsa görünür.
3. "Bu belgede" alanında faturalanacak miktarı bırakın ya da azaltın.
4. "Belgeyi kes" düğmesine basın.

**Dikkat**

- İrsaliyede faturalanmamış miktardan fazlası faturalanamaz; bir kısmını şimdi, kalanını sonra faturalayabilirsiniz.
- İrsaliye ekranında kesilen faturalar "Fatura:" satırında listelenir.
- Faturası duran irsaliye iptal edilemez; önce faturayı iptal edin. Fatura iptal edilince irsaliye yeniden faturalanabilir.

### Dövizli belge ve kur sınırı

1. "Yeni Belge" formunda "Para birimi" seçin. Bu alan yalnız birden çok para birimi tanımlıysa görünür.
2. "Kur (1 … = ? TL)" alanına o günün döviz alış kuru kendiliğinden gelir; gerekirse düzeltin.
3. Fiyatları döviz olarak girin. Altta "TL karşılığı" görünür.

**Dikkat**

- Stok, cari bakiye ve KDV TL olarak tutulur. Döviz tutarı ve kur belgede saklanır, çıktıda görünür.
- Kur, o tarihteki tanımlı kurdan **%10'dan fazla** farklıysa belge kesilmez. Kuru düzeltin ya da belgeyi yöneticiye kestirin; bu sınır yöneticiye uygulanmaz.
- Dövizli siparişten fatura keserken fatura gününün döviz alış kuru önerilir; siparişin kuru da yanında gösterilir.

### Yazdırmak ve PDF almak

1. Belgeyi açın.
2. Sağ üstte:
   - "Yazdır" düğmesi yazdırılabilir çıktıyı yeni sekmede açar.
   - "PDF" düğmesi belgeyi belge numarası adıyla bilgisayarınıza indirir.

Çıktıda firma ve cari bilgileri, logo, satırlar, KDV oranına göre döküm, yazıyla tutar ve (dövizde) kur bulunur. İrsaliye ve faturada imza alanı da vardır.

### Belgeyi iptal etmek

1. Belgeyi açın.
2. Alttaki "Belgeyi iptal et" bölümünü açın.
3. "Evet, iptal et" düğmesine basın.

**İptalin sonuçları**

- Belgenin stok ve cari etkisi geri alınır.
- Siparişten ya da irsaliyeden kesildiyse ilgili satırlar yeniden faturalanabilir duruma gelir.
- Belge silinmez; listede "İptal" etiketiyle kalır.

**Dikkat**

- Bu belgeden başka bir belge kesildiyse önce o iptal edilmelidir; ekran hangi belge olduğunu yazar.
- Dönem kilidinin kapsadığı tarihteki belge iptal edilemez.
- İptal geri alınamaz. Gerekirse belgeyi yeniden kesin.

### Üretim ve sayım (depo personeli)

1. "Üretim ve Sayım" (ya da "Faturalar") kutucuğuna girin ve "Yeni Belge" düğmesine basın.
2. "Belge türü" seçin: "Üretimden Giriş", "Sayım Fazlası" ya da "Sayım Eksiği".
3. Tarih ve depoyu seçin, ürünleri ekleyip miktarları girin.
4. "Belgeyi kes" düğmesine basın.

**Dikkat:** Depo personeli bu belgelere cari ya da fiyat giremez, siparişe bağlayamaz. Fiyat sütunları da ona görünmez.

---

## 4. Kasa ve banka [Bağımsız mod]

Ana menüden "Kasa ve Banka" kutucuğuna girin. Bu ekran için "Kasa ve Banka İşlemleri" yetkisi gerekir.

Ekranda şunlar görünür:

- Her kasa ve banka hesabının bakiyesi ve "Toplam".
- Tarih ve hesaba göre süzülebilen fiş listesi. Bir hesabın kutusuna tıklarsanız yalnız o hesabın fişleri listelenir.
- Fişler 50'şer gösterilir; alttaki "Önceki" ve "Sonraki" düğmeleri seçili tarih ve hesabı korur.

### Tahsilat (müşteriden para almak)

1. "Tahsilat" düğmesine (ya da "Yeni Fiş" → "Fiş türü: Tahsilat") basın.
2. "Tarih" ve "Kasa / banka" alanlarını seçin.
3. "Cari" alanına yazıp listeden seçin.
4. "Tutar (₺)" girin, isterseniz "Açıklama" yazın.
5. "Fişi kaydet" düğmesine basın.

Cari kartındaki "Tahsilat" düğmesi bu formu cari seçili olarak açar.

### Ödeme (tedarikçiye para vermek)

Tahsilat ile aynı adımları izleyin; "Fiş türü" olarak "Ödeme" seçin.

### Virman (hesaplar arası aktarım)

1. "Virman" düğmesine basın.
2. "Çıkış hesabı" ve "Karşı hesap (giriş)" alanlarını seçin. İki hesap farklı olmalıdır.
3. "Tutar (₺)" girip "Fişi kaydet" düğmesine basın.

### Masraf

"Masraf" fişi cari seçmeden kasadan ya da bankadan para çıkışı kaydeder. Adımlar tahsilat ile aynıdır.

### Açılış fişi

**Ne işe yarar:** Lumen'e başlarken kasada ve bankada o gün bulunan parayı (devir bakiyesi) girer.

1. Yönetici olarak "Kasa ve Banka" → "Yeni Fiş" ekranını açın.
2. Fiş türü olarak "Açılış"ı seçin (bu tür yalnız yöneticiye görünür).
3. Hesabı seçin, "Tutar (₺)" ve tarihi (genellikle başlangıç günü) girip "Fişi kaydet" düğmesine basın.
4. Her kasa ve banka hesabı için tekrarlayın.

**Dikkat**

- LOGO'dan geçişte açılış fişleri aktarımla kendiliğinden oluşur; ayrıca girmeyin.
- Açılış fişini yalnız yönetici iptal edebilir.

### Fişi iptal etmek

1. Fişi listeden açın.
2. "Fişi iptal et" düğmesine basın ve onay sorusunu kabul edin.

Fişin kasa/banka ve cari etkisi geri alınır. Fiş listede "İptal" etiketiyle kalır.

**Dikkat**

- Aynı formu yanlışlıkla iki kez gönderirseniz ikinci kayıt yapılmaz; ekran "Bu fiş zaten kaydedildi (tekrar gönderim)." der.
- Kilitli dönemdeki fiş iptal edilemez, o tarihe yeni fiş girilemez.
- Fiş ekranındaki "Yazdır" düğmesi fişi yazdırır.

---

## 5. Çekler

Çek işlemleri iki modda da vardır ve "Çek İşlemleri" yetkisi gerektirir.

**Nasıl açılır:**

1. "Müşteri Bakiye" ekranında cariyi bulun.
2. Carinin altındaki "İşlemler" düğmesine basın ("Cari İşlemleri" açılır).
3. Üç işlemden birini seçin:
   - "Çek Girişi": bu cariden çek alıp portföye koyar.
   - "Çek Çıkışı / Ciro": portföydeki müşteri çekini bu cariye ciro eder.
   - "Kendi Çekimiz": kendi çekinizi düzenleyip bu cariye verir.

### Çek girişi

1. "Çek Türü" seçin: "Gayri Resmi" ya da "Resmi". Sıradaki portföy numarası ekranda görünür.
2. Her çek için "Tutar ₺", "Vade", "Çek No" ve "Banka" alanlarını girin. "Çek Sahibi" ve "Açıklama (opsiyonel)" alanlarını da doldurabilirsiniz.
3. "Çeki Portföye Al" düğmesine basın.

### Ciro ve kendi çekimiz

- Ciro için portföyden çeki seçip "Ciro Et" düğmesine basın.
- Kendi çekimiz için bilgileri girip "Kendi Çekimizi Ver" düğmesine basın.

### Geri almak

Her ekranın altındaki kayıt listesinde (kim girdi, ne zaman girdi) "Geri Al" düğmesi bulunur. Geri alınan kayıt "geri alındı" diye işaretlenir.

**Dikkat**

- Ana sayfadaki çek görseli uyarısı "Çek Görselleri" ekranını açar; buradan çeke fotoğraf eklenir.
- Vadesi yaklaşan çekler "Bildirimler" kutusunda da görünür.
- **[Bağımsız mod]** Dövizli çekler TL tutarıyla tutulur.

---

## 6. Cari: bakiye, ekstre, cari kartı

### Bakiye listesi

1. "Müşteri Bakiye" kutucuğuna girin ("Bakiye Listesi" açılır).
2. "Listede Ara" kutusuna cari adı ya da kodu yazın.
3. Üstte "Listelenen Cari", "Toplam Borçlu" ve "Toplam Alacaklı" toplamları görünür.

Her carinin altındaki düğmeler:

- "Hareket": ekranda ekstreyi açar.
- "Kart": cari kartını açar (**[Bağımsız mod]**).
- "İşlemler": çek işlemlerini açar.
- "Ekstre": PDF ekstreyi açar.
- "Detay": cari bilgilerini gösterir.

**Dikkat:** Bakiye tutarını görmek için "Cari Bakiye Görme" yetkisi gerekir. Yetki yoksa ekranda "Bakiye gorme yetkiniz yok." yazar.

### Ekstre (ekranda, PDF, Excel)

1. Carinin "Hareket" düğmesine basın ("Cari Hareket Dokumu" açılır).
2. LOGO modunda sağ üstteki seçiciden dönemi seçin: "Aktif Dönem", "Önceki Dönem" ya da "Tüm Dönemler". Bağımsız modda seçici yoktur; ekstre bütün geçmişi gösterir.
3. Ekran görüntüsünü yazdırmak için "Yazdir" düğmesine basın.
4. Dosya olarak almak için "Ekstre" düğmesine basın ve "PDF" ya da "Excel" seçin.

**Dikkat**

- Çok hareketli caride **PDF yalnız son 500 hareketi** içerir. Daha eski hareketler en üstte tek bir "Devir" satırında toplanır; bakiye yine doğrudur. PDF'in üstünde bu durum yazılıdır.
- Hareketlerin **tamamı için Excel** çıktısını kullanın.
- **[Bağımsız mod]** Ekstredeki bağlantılar ilgili faturayı ya da kasa fişini açar.

### Cari kartı [Bağımsız mod]

**Ne işe yarar:** Carinin tüm bilgilerini tek sayfada gösterir.

**Nasıl açılır:** Cari listesinde, bakiye listesinde ya da belge ekranında cari adına veya "Kart" düğmesine basın.

Kartta şunlar vardır:

- Kod, tür ("Alıcı", "Satıcı", "Alıcı + satıcı"), vergi, iletişim, adres, vade/pazarlamacı.
- "Toplam borç", "Toplam alacak" ve "Bakiye". Bakiyenin altında "Cari bize borçlu", "Biz cariye borçluyuz" ya da "Kapalı" yazar.
- Hızlı düğmeler: "Ekstre", "Fatura kes", "Tahsilat", "Ödeme". Her biri yetkiniz varsa görünür.
- Sekmeler: "Belgeler", "Tahsilat / ödeme", "Çek / senet".

---

## 7. Stok

### Stok arama

1. "Stok Ara" kutucuğuna girin.
2. Arama kutusuna ürün adı, kodu ya da barkodu yazın.
3. Daha fazla sonuç için listenin altındaki düğmeye basın.

**Dikkat**

- Miktarı yalnız "Stok Miktarı Görme" yetkisi olan görür.
- Arama Türkçe karakterlere duyarsızdır: "TABAGI" yazmak "Tabağı" sonucunu getirir.
- **[Bağımsız mod]** Ürün koduna tıklayınca stok kartı açılır.

### Stok kartı [Bağımsız mod]

**Nasıl açılır:** Stok aramasında ürün koduna ya da belge ekranında ürüne tıklayın.

Kartta şunlar vardır:

- "Eldeki (tüm depolar)", "Bekleyen satış siparişi", "Bekleyen alış siparişi" ve "Kullanılabilir" miktarlar.
- Birimler ve çarpanları, barkodlar, KDV oranı.
- "Fiyatlar ve depolar": satış ve alış fiyatları, depo başına miktar.
- "Hareketler (son 100)": giriş, çıkış ve yürüyen bakiye. "Birim maliyet" sütunu yalnız "Faturalama" yetkisi olana görünür.

### Üretim ve sayım

- **[Bağımsız mod]** Üretim girişi ve sayım "Faturalar" ya da "Üretim ve Sayım" ekranından belge olarak girilir (bölüm 3).
- **[Bağımsız mod]** "Stoklar" kutucuğu (Üretim Fişleri ekranı) bu modda kapalıdır.
- **[LOGO modu]** Üretim fişleri "Stoklar" kutucuğundan ("Üretim Fişleri") girilir.

**Dikkat:** İçinde stok olan bir depoyu kapatmadan önce ürünlerini sayımla sıfırlayın ya da başka depoya aktarın.

---

## 8. Siparişler

Bu bölüm iki modda da geçerlidir.

### Yeni sipariş

1. "Yeni Sipariş" kutucuğuna girin ve cariyi bulun.
2. "Siparis Yaz" düğmesine basın.
3. "Ürün ara veya barkod okut..." kutusundan ürünleri ekleyin.
4. Sağdaki "Sipariş Özeti" bölümünde "Ara Toplam", "İskonto", "KDV" ve "Genel Toplam" görünür. İskonto ve KDV ayarları da burada yapılır.

**Dikkat**

- Cari listede yoksa sipariş ekranındaki "Yeni Cari Aç & Fişe Aktar" penceresinden cariyi açıp "Oluştur & Aktar" düğmesine basabilirsiniz.
- Sipariş ekranındaki "Yazdır" ve "Hızlı Yazdır" düğmeleri siparişi yazdırır.
- **[Bağımsız mod]** Aynı ekrandaki "Faturala" düğmesi siparişi faturaya çevirir (bölüm 3).

### Diğer sipariş ekranları

- "Siparişler": "Açık Siparişler" ve "Tüm Siparişler" sekmeleri.
- "Mağaza Satış": mağaza carisine hızlı satış. Yalnız ayarlarda mağaza carisi tanımlıysa görünür.
- "Döviz İşlemleri": "Dovizli Siparis Merkezi". USD, EUR gibi para birimlerinde sipariş oluşturulur ve listelenir.
- "Hızlı Erişim": siparişi olan cariyi bulur; seçili carinin siparişlerini, bakiyesini ve toplam sipariş tutarını gösterir.
- "Bekleyen Ürünler": bekleyen sipariş ve rezervasyonları listeler.
- "Sil": "Taslak Siparisler". Tutarı sıfır kalmış taslak siparişler buradan seçilip temizlenir.

**Dikkat:** Sipariş yetkisi olan personel görebildiği her siparişi düzenleyebilir.

---

## 9. Raporlar

"Raporlar" kutucuğuna girin. Bu ekran için "Raporlar" yetkisi gerekir.

**Finans ve Cari Hesaplar**

- "Kasa Paneli": kasa ve döviz hesaplarının anlık bakiyeleri, TL / döviz ayrımı.
- "Tahsilat Radarı": borçlu ligi; borcun büyüklüğü, son alımdan bu yana geçen süre ve Pareto payı.
- "Cari Yaşlandırma": açık borcun yaş dilimlerine dağılımı (0-30, 31-60, 61-90, 90+ gün).
- "Müşteri Ödeme Hızı": müşteri bazında son fişler, tahsilatlar ve en hızlı ödeyenler.
- "Çekler — Nakit Akışı": çek vadelerinin zaman çizelgesi (gecikmiş, bugün, bu hafta, bu ay).
- "Çek Hesap (Detay)": alınan çeklerin durumuna göre (portföy / ciro / tahsil) dökümü.
- "Kasa Matrisi": ay ve kasa bazında tahsilat girişleri ile kasa karneleri.

**Satış Raporları**

- "Aylık Satış": ay ay net ciro (iade düşülmüş), geçen yılla kıyas ve değişim.
- "Müşteri Ligi": net ciroya göre müşteri sıralaması.
- "Pazarlamacı Performans": pazarlamacı bazında satış, tahsilat ve devir hariç bakiye.
- "Yıllık Özet": yılın karnesi; ana göstergeler, çeyrekler, en çok satan ürünler ve en büyük müşteriler.

**Ürün ve Stok Yönetimi**

- "Stok İzleme": eriyen ya da eksiye düşen stoklar ve tahmini tükenme günü.
- "Ürün Ligi": net satışa göre ürün sıralaması.
- "En İyi 20 Ürün": son 30 günde en çok ciro yapan 20 ürün.

**Menüdeki ayrı rapor**

- "Günlük İşlemler": günün işlem dökümü; Excel'e alınabilir.

**Dikkat**

- Birçok raporda Excel çıktısı vardır.
- Gizlediğiniz cariler (özel cari kısıtı) raporlarda da süzülür.

---

### Vade takibi [Bağımsız mod]

**Ne işe yarar:** Hangi müşteriden ne zaman ne kadar tahsilat bekleniyor, hangi tedarikçiye ne zaman ödeme yapılacak — vadesine göre gösterir.

1. "Raporlar" → "Vade Takibi".
2. "Tahsil edilecek" ya da "Ödenecek" sekmesini seçin.
3. Üstteki kutular toplamları gösterir: "Vadesi geçmiş", "Bu hafta", "Bu ay", "30 günden sonra". Bir kutuya basınca liste o dilime süzülür; "Cari ara" ile tek cariye bakabilirsiniz.
4. Listede her açık faturanın vadesi, açık kalan tutarı ve kaç gün geciktiği / kaç gün kaldığı görünür.

**Dikkat**

- Tahsilatlar ve iadeler en erken vadeli faturadan başlayarak düşülür; kısmen ödenmiş faturanın yalnız kalan kısmı görünür.
- Faturada vade girilmezse carinin "vade günü" uygulanır (fatura tarihi + vade günü). Vade günü cari kartında / Excel aktarımında girilir.
- Cari kartında "Vadesi geçmiş" kutusu aynı hesabı o cari için gösterir.
- Aynı firma hem müşteriniz hem tedarikçinizse açık kalemler net bakiyenin olduğu tarafta görünür (ör. fazla tahsilat "Ödenecek" sekmesinde).
- Görmek için "Raporlar" ve "Cari bakiye" yetkisi gerekir.

## 10. Yedekleme ve geri yükleme

**Yeri:** "Sistem Ayarları" → "Sistem Ayarları" → "Sistem ve Yedek" sekmesi.

Yedek işlemleri **yalnız yöneticiye** açıktır, çünkü yedek kullanıcı parolalarının şifreli özetlerini de içerir. "Ayarlar" yetkili personel bu ekranda yalnız çalışma modunu görür.

### Yedek neyi içerir

- **[Bağımsız mod]** Her şeyi: cari, stok, belgeler, kasa, çek, kullanıcılar ve ayarlar.
- **[LOGO modu]** Yalnız Lumen'in kendi kayıtlarını: kullanıcı yetkileri, görevler, kayıtlar, çek ekleri. LOGO verisinin yedeğini LOGO'nun kendi aracıyla almaya devam edin.

### Otomatik günlük yedek

1. "Yedekleme ayarları" bölümünde "Her gün otomatik yedek al" kutusunu işaretleyin.
2. "Yedek klasörü" alanına başka bir disk ya da ağ klasörü yazın. Boş bırakırsanız yedek uygulama klasörüne yazılır.
3. "Saklanacak yedek sayısı" alanını doldurun (3 ile 365 arası).
4. "Kaydet" düğmesine basın.

Otomatik yedek günün ilk kullanımında arka planda alınır; son yedeğin tarihi kutunun yanında yazar.

**Dikkat:** "son otomatik yedek başarısız — klasörü kontrol edin" uyarısı görürseniz yedek klasörüne yazılamıyordur; bilgi işlem sorumlunuza haber verin.

### Elle yedek almak ve indirmek

1. "Şimdi yedek al" düğmesine basın. Ekranda "Yedek alındı: … tablo, … kayıt." yazar.
2. Listede her yedeğin tarihi, türü ("Elle", "Otomatik", "Geri yükleme öncesi", "Yüklenen") ve boyutu görünür.
3. Yedeği bilgisayarınıza almak için "İndir" düğmesine basın.

### Geri yüklemek

1. Listede geri yüklemek istediğiniz yedeğin yanındaki "Geri yükle" düğmesine basın.
2. Açılan pencerede uyarıyı okuyun.
3. Kutuya **GERİ YÜKLE** yazın ve "Geri yükle" düğmesine basın.

Başka bir bilgisayardaki yedeği kullanmak için önce "Yedek dosyası yükle" ile dosyayı seçin, sonra listeden geri yükleyin.

**Geri yüklemenin sonuçları**

- Yedekten sonra girilen **tüm kayıtlar kaybolur**.
- Geri yüklemeden önce mevcut durumun yedeği otomatik alınır ("Geri yükleme öncesi").
- İşlem tek seferde yapılır: hata olursa hiçbir şey değişmez.
- **Herkesin oturumu kapanır ve herkes yeniden giriş yapmalıdır.** "Beni Hatirla" girişleri de iptal olur.
- Parolalar ve yetkiler yedeğin alındığı andaki haline döner. Yedekten sonra parolasını değiştiren kişi eski parolasıyla girer.
- Başka çalışma modunda alınmış yedek geri yüklenemez. Başka veritabanından alınmış yedekte ekran ayrıca uyarır.

**Öneriler**

- Yedekleri yalnız sunucuda bırakmayın. Düzenli olarak "İndir" ile alıp bir USB diske ya da bulut klasörüne de kopyalayın.
- Geri yüklemeyi çalışma saati dışında yapın ve öncesinde herkese haber verin.

---

## 11. Sık sorulanlar ve sorun çözme

**"Oturum Suresi Doldu" ekranı geldi / oturumum düştü.**

- Güvenlik nedeniyle oturum kapatılmıştır. Yeniden giriş yapın.
- Yönetici yedek geri yüklediyse, parolanızı değiştirdiyse ya da hesabınızı pasife aldıysa da yeniden giriş istenir.
- Yedek geri yüklendiyse parolanız yedek anındaki haline dönmüş olabilir.

**Giriş yapamıyorum: "hesabınız geçici olarak kilitlendi" yazıyor.**

- Ekranda yazan süre kadar bekleyin.
- Ya da yöneticiden "Guvenlik" → "Hesap Kilidi Yönetimi" ekranından kilidi açmasını isteyin.

**PDF açılmıyor.**

- Belge ekranındaki "PDF" düğmesi dosyayı açmaz, **indirir**. Tarayıcınızın "İndirilenler" klasörüne bakın.
- Ekrana yazdırmak istiyorsanız "Yazdır" düğmesini kullanın.
- Ekstre PDF'i yeni sekmede açılır. Açılmıyorsa tarayıcının açılır pencere engelini kontrol edin.
- Çok hareketli carinin PDF'i son 500 hareketi gösterir; tamamı için Excel'i kullanın.

**"Bu tarih kilitli dönemde" ya da "Belge kilitli dönemde; iptal edilemez" uyarısı.** [Bağımsız mod]

- Belge tarihi, "Tanımlar" → "Firma" → "Dönem kilidi" tarihine eşit ya da daha eskidir.
- Tarihi düzeltin. Gerçekten eski döneme kayıt gerekiyorsa yöneticinizle ve muhasebecinizle konuşun.

**"Kur, … tarihli tanımlı kurdan … %10'dan fazla farklı" uyarısı.** [Bağımsız mod]

- Girdiğiniz kur o günün kuruna göre çok farklıdır.
- Kuru kontrol edin ("Tanımlar" → "Döviz kurları"); gerekirse "Bugünün kurlarını TCMB'den al" düğmesine basın.
- Kur gerçekten farklı olmalıysa belgeyi yöneticiye kestirin.

**"Dövizli belge için kur girin" uyarısı.** [Bağımsız mod]

- Kur alanı boştur. O gün için kur tanımlayın ya da kuru elle yazın.

**"Erisim Engellendi", "Yetkiniz yok" ya da "… yetkiniz yok" ekranı.**

- Bu ekran için yetkiniz yoktur. Mesajda gereken yetkinin adı yazar, örneğin "M31 (Faturalama)" ya da "M32 (Kasa ve Banka İşlemleri)".
- Yöneticinizden "Kullanıcı & Yetki" → "İzin Matrisi" ekranında bu yetkiyi açmasını isteyin.

**Aradığım cari hiçbir yerde çıkmıyor.**

- Cari sizin için gizlenmiş olabilir (özel cari kısıtı), yöneticinize sorun.
- Cari pasif de olabilir.

**Bir menü kutucuğu görünmüyor.**

- Yetkiniz yoktur ya da o özellik bu çalışma modunda yoktur.
- Örnek: "Faturalar", "Kasa ve Banka" ve "Tanımlar" yalnız bağımsız modda görünür; "Faturalama" (BETA) yalnız LOGO modunda görünür.

**Fiş kaydettim ama "Bu fiş zaten kaydedildi (tekrar gönderim)" dedi.**

- İlk kayıt yapılmıştır. Aynı fişi ikinci kez girmeyin; listeden kontrol edin.

**Belgeyi iptal edemiyorum: "Bu belgeden kesilmiş … önce iptal edilmeli".**

- Bu belgeye bağlı başka bir belge vardır. Önce mesajda yazan belgeyi iptal edin.

**Kesilen fatura e-Fatura yerine geçer mi?**

- Hayır. Lumen'in fatura çıktısı bilgi/teslim nüshasıdır; e-Fatura ve e-Arşiv bu sürümde yoktur.

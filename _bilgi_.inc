<?php
/**
 * ============================================================================
 * UYGULAMA AYARLARI - _bilgi_.inc
 * ============================================================================
 * Bu dosya uygulamanın genel ayarlarını içerir.
 * Değişiklik yapmadan önce yedek alınız.
 * ============================================================================
 */

// ============================================================================
// SİPARİŞ NUMARASI AYARLARI
// ============================================================================
$doviz = "";                    // Varsayılan döviz (boş = TL)
$sipno = "SIP";                 // Satış siparişi numarası ön eki (örn: SIP000001) — kurulumda kendi önekinizi yazın
$satinalmano = "SA";            // Satınalma siparişi numarası ön eki
// Mağaza (hızlı satış) carisi LOGICALREF — .env MAGAZA_CARI'den okunur. 0 = mağaza satış özelliği kapalı.
$magaza_cari = (int) (getenv('MAGAZA_CARI') ?: 0);

// ============================================================================
// ÇEK İŞLEMLERİ — çek giriş / çıkış / ciro (portföy yönetimi)
// Erişim "M30 (Çek İşlemleri)" yetki koduyla yönetilir:
//   Ayarlar → Kullanıcı & Yetki → İzin Matrisi. Yönetici otomatik erişir.
// (Faturalama ve tahsilat modülleri bu sürümde kapsam dışıdır.)
// ============================================================================

// ============================================================================
// YAZICI AYARLARI
// ============================================================================
$yol = getenv('DOSYA_YOLU') ?: (__DIR__ . DIRECTORY_SEPARATOR . 'tmp');  // Dosya kayıt yolu (.env: DOSYA_YOLU; varsayılan: <proje>/tmp)
$fisyazici = 'fisyazici';       // Ana fiş yazıcısı adı
$fisyazici2 = 'Microsoft Print to PDF';  // 2. fiş yazıcısı
$fisyazici3 = 'Microsoft Print to PDF';  // 3. fiş yazıcısı
$fisyazici4 = 'Microsoft Print to PDF';  // 4. fiş yazıcısı
$fisyazici_fabrika = 'FABRIKA';  // Fabrika yazıcısı adı (fabrikadaki dinleyici kullanır)
$barkodyazici = 'barkod';       // Barkod yazıcısı adı
$barkodyazici2 = 'Microsoft Print to PDF'; // 2. barkod yazıcısı

// ============================================================================
// YAZDIRMA SEÇENEKLERİ
// Değerler: '0' = Hayır/Pasif, '1' = Evet/Aktif
// ============================================================================
$exceleaktar = '1';             // Excel'e aktar butonu görünsün mü
$koliyazdir = '1';              // Koli yazdır butonu görünsün mü
$resimliyazdir = '1';           // Resimli yazdır butonu görünsün mü
$barkodyazdir = '1';            // Barkod yazdır butonu görünsün mü

// ============================================================================
// DEPO AYARLARI
// ============================================================================
$depo = 0;                      // Varsayılan depo numarası (LOGO SOURCEINDEX)
$cokludepo = 1;                 // Çoklu depo kullanımı (0: Tek depo, 1: Çoklu depo)

// ============================================================================
// SAYISAL FORMAT AYARLARI
// ============================================================================
$adetkusurat = 2;               // Adet/miktar için ondalık basamak sayısı (örn: 2 = 1.00)
$parakusurat = 2;               // Para/fiyat için ondalık basamak sayısı (örn: 2 = 100.00)

// ============================================================================
// DÖVİZ AYARLARI
// LOGO ERP Döviz Kodları: 0=TL(yerel), 1=USD, 20=EUR, 17=GBP, 53/160=TRY
// ============================================================================
$dovizlicalis = '1';            // Dövizli çalışma modu (0: Sadece TL, 1: Döviz aktif)
$dovizfirma = '1';              // L_CURRENCYLIST tablosundaki FIRMNR değeri
$doviztipleri = '1,20';         // İzin verilen döviz tipleri (virgülle ayrılmış CURTYPE değerleri)
                                // 1=USD($), 20=EUR(€), 17=GBP(£)

// ============================================================================
// FİYAT AYARLARI
// ============================================================================
$fiyatgruplu = '1';             // Fiyat grubu seçimi aktif mi (0: Hayır, 1: Evet)
$odemekodu = '01';              // Varsayılan ödeme planı kodu (PAYPLANS.CODE)

// ============================================================================
// KULLANICI AYARLARI
// ============================================================================
$terminalkullanici = '1';       // Varsayılan kullanıcı ID (giriş yoksa kullanılır)
$kullanicigirisli = '0';        // Şifreli giriş zorunlu mu (0: Hayır, 1: Evet)

// ============================================================================
// CARİ (MÜŞTERİ) AYARLARI
// ============================================================================
$bakiyegorunsun = '1';          // Müşteri bakiyesi görünsün mü (0: Hayır, 1: Evet)
$extregorunsun = '1';           // Müşteri ekstresi görünsün mü (0: Hayır, 1: Evet)
$carilistesayisi = '1000';      // Cari listesinde gösterilecek maksimum kayıt
$iletisim = '1';                // Cari iletişim bilgileri görünsün mü
$hizlicari = '12';              // Hızlı satış için varsayılan cari ID (CLCARD.LOGICALREF)
$yenicariac = '1';              // Yeni cari ekleme yetkisi (0: Hayır, 1: Evet)
$carikoduontaki = "c-";         // Yeni cari kodu ön eki (örn: c-001)

// ============================================================================
// STOK (ÜRÜN) AYARLARI
// ============================================================================
$stoklistesayisi = '1000';      // Stok listesinde gösterilecek maksimum kayıt
$stoksonalisfiyati = '1';       // Son alış fiyatı görünsün mü (0: Hayır, 1: Evet)
$stoksonsatisfiyati = '1';      // Müşteriye son satış fiyatı görünsün mü
$stokekle = 'evet';             // Yeni stok ekleme aktif mi ('evet' veya 'hayir')
$stokbirimlogicalref = '5';     // Varsayılan birim LOGICALREF (UNITSETL tablosu)
$stokkoduontaki = "s-";         // Yeni stok kodu ön eki (örn: s-001)
$yenistokac = '1';              // Yeni stok ekleme yetkisi
$yenifiyatduzenle = '1';        // Fiyat düzenleme yetkisi
$resimlistoklistesi = '0';      // Stok listesinde resim göster (0: Hayır, 1: Evet)
$coklustokgiris = 'evet';       // Çoklu stok girişi aktif mi ('evet' veya 'hayir')
$cokluekledeayniurun = 1;       // Çoklu eklemede aynı ürün tekrar eklenebilsin mi

// ============================================================================
// STOK ARAMA SAYFASI AYARLARI (lg_stok_bul.php)
// ============================================================================
$sonsiparis_aktif = '0';        // Son sipariş ürünlerini göster (0: Hayır, 1: Evet)
$favoriler_aktif = '0';         // Favori ürünleri göster (0: Hayır, 1: Evet)

// ============================================================================
// SİPARİŞ AYARLARI
// ============================================================================
$siparistefiyatduzenle = '1';   // Siparişte fiyat düzenlenebilsin mi
$yenialissiparisi = '1';        // Yeni alış siparişi oluşturma yetkisi
$reserve = '0';                 // Stok rezervasyonu aktif mi (0: Hayır, 1: Evet)

// ============================================================================
// İSKONTO AYARLARI
// Kademeli iskonto: Önce 1. iskonto, sonra 2., sonra 3. uygulanır
// ============================================================================
$iskontolu = '30';              // 1. kademe maksimum iskonto oranı (%)
$iskontolu2 = '20';             // 2. kademe maksimum iskonto oranı (%)
$iskontolu3 = '10';             // 3. kademe maksimum iskonto oranı (%)
$iskontoartir = '0';            // İskonto artırma yetkisi (0: Hayır, 1: Evet)
$topluiskonto = 1;              // Toplu/genel iskonto uygulama aktif mi
$toplukdv = 1;                  // Toplu KDV değiştirme aktif mi
// Sipariş ekranı hızlı iskonto kısayolları — ayar/hizli_iskonto.php'den yönetilir.
// Her öğe: ['ad' => 'Etiket', 'oran' => Yüzde]. Boş dizi = kısayol yok (elle İSK1/İSK2 girilir).
$hizli_iskontolar = [];

// ============================================================================
// DİĞER AYARLAR
// ============================================================================
$harfler = array('ç','ğ','ı','ü','ö','ş'); // Türkçe karakter listesi (arama için)
$tanimlialantoplam = '0';       // Tanımlı alan toplamı göster (metreküp, kilo vb.)
$tanimlialan = '0';             // Tanımlı alan aktif mi
$tlkodu = '160';                // Türk Lirası CURTYPE kodu (L_CURRENCYLIST)
$benihatirla = '1';             // "Beni Hatırla" özelliği aktif mi
$dil = '0';                     // Dil seçimi (0: Türkçe)
$hizliayrac = ',';              // Hızlı giriş ayraç karakteri
$hizlikarekter = '80';          // Hızlı giriş maksimum karakter
$eksik = 1;                     // Eksik stok kontrolü aktif mi
$maliyetkontrol = 1;            // Maliyet kontrolü (satış < alış uyarısı)

// ============================================================================
// RAPOR AYARLARI
// ============================================================================
// Müşteri ödeme hızı raporunda hariç tutulacak cari anahtar kelimeleri.
// Cari KODUNDA veya UNVANINDA bu kelimelerden biri geçerse rapora girmez.
// Kendi iç hesaplarınızı (mağaza satış, gider, yatırım vb.) elemek için kullanın.
// Boş bırakılırsa hiçbir cari elenmez. Örnek: ['MAGAZA SATIS', 'GIDER']
$rapor_haric_cari_kelimeleri = [];

// ============================================================================
// YETKİ AYARLARI
// ============================================================================
$toplusipfaturalama = '1';      // Toplu sipariş faturalama yetkisi (0: Hayır, 1: Evet)

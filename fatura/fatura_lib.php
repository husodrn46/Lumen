<?php

declare(strict_types=1);

/**
 * fatura_lib.php — "Siparişi Faturala" (BETA, geri alınabilir)
 *
 * ⚠️ KAPSAM SINIRI — KULLANMADAN ÖNCE OKUYUN
 * Bu modül KDV'siz çalışır: oluşturduğu faturaya TOTALVAT = 0 yazar.
 * Muhasebe fişi (ACCFICHE) oluşturmaz, e-fatura/e-arşiv entegrasyonu yoktur,
 * satılan malın maliyetini (SMM) hesaplamaz.
 *
 * Yani yalnızca KDV'siz ve resmi muhasebe gerektirmeyen (gayri resmi) iş
 * akışları için uygundur. KDV mükellefi bir firmada olduğu gibi kullanmayın —
 * sıfır KDV'li fatura kaydı oluşturur. KDV'li kullanım için modülün KDV
 * hesaplayacak ve muhasebe fişi yazacak şekilde genişletilmesi gerekir.
 *
 * Bu sınır, özelliğin neden BETA olduğunun ana sebebidir.
 *
 * "Faturala" = tek transaction'da:
 *   INVOICE (TRCODE=8 başlık) + STFICHE (irsaliye IOCODE=3) +
 *   STLINE (her satır stok çıkışı IOCODE=4) + CLFLINE (cari borç TRCODE=38) +
 *   ORFLINE.SHIPPEDAMOUNT güncelleme + M_FATURA_LOG kaydı.
 * Stok/cari bakiye LV_ view'dan canlı hesaplandığı için toplam tablosu güncellenmez.
 *
 * Teknik: "şablon klonla + UPDATE". Geçerli bir TL faturanın tüm satırı IDENTITY ile
 * kopyalanır (NOT NULL teknik alanlar otomatik gelir), ardından yalnızca iş alanları
 * (cari, tutar, tarih, GUID, referanslar, CAPIBLOCK) UPDATE ile yazılır.
 *
 * Geri alma (undo): M_FATURA_LOG(+DETAY) kaydından kesin silme + SHIPPEDAMOUNT iadesi.
 *
 * Çağıran dosya ayr.php yüklemiş olmalı (guid(), $kayitsaat, $saat, $dakika, $saniye global).
 */

if (!function_exists('fatura_log_tablo_olustur')) {
    function fatura_log_tablo_olustur(PDO $dbh): void
    {
        $dbh->exec("IF OBJECT_ID('dbo.M_FATURA_LOG','U') IS NULL
        CREATE TABLE M_FATURA_LOG (
            ID INT IDENTITY(1,1) PRIMARY KEY,
            REQUEST_ID VARCHAR(40) NOT NULL,
            FIRMADONEM VARCHAR(20) NOT NULL,
            DURUM VARCHAR(20) NOT NULL CONSTRAINT DF_MFL_DURUM DEFAULT 'AKTIF',
            INVOICE_REF INT NULL, INVOICE_FICHENO VARCHAR(40) NULL,
            STFICHE_REF INT NULL, STFICHE_FICHENO VARCHAR(40) NULL,
            CLFLINE_REF INT NULL,
            ORFICHE_REF INT NOT NULL, SIPARIS_NO VARCHAR(40) NULL,
            CLIENTREF INT NOT NULL,
            NETTOTAL DECIMAL(28,8) NOT NULL CONSTRAINT DF_MFL_NET DEFAULT 0,
            KULLANICI INT NULL, IP VARCHAR(64) NULL,
            OLUSTURMA DATETIME NOT NULL CONSTRAINT DF_MFL_OLU DEFAULT GETDATE(),
            HATA NVARCHAR(MAX) NULL,
            GERIALAN_KULLANICI INT NULL, GERIALMA DATETIME NULL, GERIALMA_NOT VARCHAR(255) NULL,
            CONSTRAINT UQ_MFL_REQ UNIQUE (REQUEST_ID)
        )");
        $dbh->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MFL_ORFICHE') CREATE INDEX IX_MFL_ORFICHE ON M_FATURA_LOG(ORFICHE_REF)");
        $dbh->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MFL_DURUM') CREATE INDEX IX_MFL_DURUM ON M_FATURA_LOG(DURUM)");
        $dbh->exec("IF OBJECT_ID('dbo.M_FATURA_LOG_DETAY','U') IS NULL
        CREATE TABLE M_FATURA_LOG_DETAY (
            ID INT IDENTITY(1,1) PRIMARY KEY,
            LOG_ID INT NOT NULL,
            SATIR_TIP TINYINT NOT NULL,
            STLINE_REF INT NULL, ORFLINE_REF INT NULL,
            ESKI_SHIPPEDAMOUNT DECIMAL(28,8) NULL, YENI_SHIPPEDAMOUNT DECIMAL(28,8) NULL,
            STOCKREF INT NULL, AMOUNT DECIMAL(28,8) NULL,
            CONSTRAINT FK_MFLD_LOG FOREIGN KEY (LOG_ID) REFERENCES M_FATURA_LOG(ID)
        )");
        $dbh->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MFLD_LOG') CREATE INDEX IX_MFLD_LOG ON M_FATURA_LOG_DETAY(LOG_ID)");
    }
}

if (!function_exists('fatura_say')) {
    /** Tutarı LOGO'nun kabul ettiği nokta-ondalıklı string'e çevir (locale bağımsız). */
    function fatura_say(float|int|string|null $v): string
    {
        return number_format((float) $v, 8, '.', '');
    }
}

if (!function_exists('fatura_klonla')) {
    /**
     * Geçerli bir kayıt satırını (LOGICALREF hariç tüm kolonlar) IDENTITY ile kopyalar.
     * Böylece tüm NOT NULL teknik alanlar otomatik dolar; iş alanları sonra UPDATE edilir.
     * @return int yeni LOGICALREF
     */
    function fatura_klonla(PDO $dbh, string $fullTable, int $sablonRef, array $override = []): int
    {
        $cols = $dbh->query("
            SELECT c.name FROM sys.columns c
            WHERE c.object_id = OBJECT_ID('{$fullTable}')
              AND c.name <> 'LOGICALREF'
              AND c.is_computed = 0
              AND c.system_type_id <> 189   -- timestamp/rowversion INSERT edilemez
            ORDER BY c.column_id
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) {
            throw new RuntimeException("Klon: {$fullTable} kolonları okunamadı.");
        }
        $list = implode(',', array_map(static fn($c) => "[{$c}]", $cols));
        // UNIQUE alanlar (FICHENO, GUID...) INSERT anında benzersiz olmalı; override ile sabit değer.
        $sel = implode(',', array_map(static function ($c) use ($override, $dbh) {
            return array_key_exists($c, $override) ? $dbh->quote((string) $override[$c]) : "[{$c}]";
        }, $cols));
        // ÖNEMLİ: STLINE/STFICHE/CLFLINE tablolarında AFTER trigger var; lastInsertId()/MAX(LOGICALREF)
        // YANLIŞ kimlik döndürebiliyor (klon başka faturaya bağlı kalıp çift satır oluşturuyordu).
        // OUTPUT INSERTED.LOGICALREF INTO ile eklenen satırın kimliğini KESİN al.
        $sql = "SET NOCOUNT ON; DECLARE @yeni TABLE(id INT);
INSERT INTO {$fullTable} ({$list}) OUTPUT INSERTED.LOGICALREF INTO @yeni
SELECT {$sel} FROM {$fullTable} WHERE LOGICALREF = {$sablonRef};
SELECT id FROM @yeni;";
        $stmt = $dbh->query($sql);
        $yeni = (int) $stmt->fetchColumn();
        $stmt->closeCursor();
        if ($yeni <= 0) {
            throw new RuntimeException("Klon: {$fullTable} yeni kayıt kimliği alınamadı.");
        }
        return $yeni;
    }
}

if (!function_exists('fatura_zaten_faturali_mi')) {
    function fatura_zaten_faturali_mi(PDO $dbh, string $fd, int $orficheRef): bool
    {
        $st = $dbh->prepare("SELECT COUNT(*) FROM {$fd}STLINE WHERE ORDFICHEREF = :r AND INVOICEREF > 0");
        $st->execute([':r' => $orficheRef]);
        return (int) $st->fetchColumn() > 0;
    }
}

if (!function_exists('fatura_ficheno_uret')) {
    /** Belirli bir tablo/prefix için sonraki numarayı üretir (prefix + soldan sıfır dolgulu). */
    function fatura_ficheno_uret(PDO $dbh, string $fullTable, string $prefix, int $pad, string $extraWhere = ''): string
    {
        // Ön ek BOŞ OLAMAZ: boş ön ekle LIKE '%' tüm fişleri tarar ve
        // numaralandırma LOGO'nun kendi serisiyle çakışır. Uygulamanın
        // kestiği belgeler daima ayrı bir seride durmalıdır.
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?? '';
        if ($prefix === '') {
            throw new RuntimeException('Fatura/irsaliye seri ön eki tanımlı değil. Sistem Ayarları > Faturalama bölümünden ayarlayın.');
        }

        $len = strlen($prefix) + 1;
        $sql = "SELECT MAX(CAST(SUBSTRING(FICHENO, {$len}, 30) AS BIGINT))
                FROM {$fullTable}
                WHERE FICHENO LIKE :p AND ISNUMERIC(SUBSTRING(FICHENO, {$len}, 30)) = 1";
        if ($extraWhere !== '') {
            $sql .= " AND {$extraWhere}";
        }
        $st = $dbh->prepare($sql);
        $st->execute([':p' => $prefix . '%']);
        $son = (int) $st->fetchColumn();
        return $prefix . str_pad((string) ($son + 1), $pad, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('fatura_olustur_core')) {
    /**
     * Bir satış siparişini (ORFICHE) faturalar. Tek transaction.
     * @return array{ok:bool, mesaj:string, kod?:int, invoice_ref?:int, fiche_no?:string, net?:float, log_id?:int}
     */
    function fatura_olustur_core(PDO $dbh, string $firma, string $fd, int $orficheRef, int $personel, string $requestId, array $opt = []): array
    {
        global $kayitsaat, $saat, $dakika, $saniye;
        // Seriler LOGO'nun kendi fatura/irsaliye serilerinden AYRI olmalıdır;
        // böylece uygulamanın kestiği belgeler ayırt edilebilir ve geri alınabilir.
        $invPrefix = (string) ($opt['invoice_prefix'] ?? 'LMF');   // INVOICE serisi
        $irsPrefix = (string) ($opt['irsaliye_prefix'] ?? 'LMI');  // STFICHE (irsaliye) serisi
        $ip        = (string) ($opt['ip'] ?? '');
        $ts        = (int) $kayitsaat;
        $hh = (int) $saat; $mm = (int) $dakika; $ss = (int) $saniye;

        fatura_log_tablo_olustur($dbh);

        try {
            $dbh->beginTransaction();

            // 0) Idempotency — zaten faturalıysa dur
            if (fatura_zaten_faturali_mi($dbh, $fd, $orficheRef)) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bu sipariş zaten faturalanmış.'];
            }

            // Sipariş başlığı
            $ofs = $dbh->prepare("SELECT LOGICALREF, FICHENO, CLIENTREF, SALESMANREF, SOURCEINDEX, TRCURR, BRANCH, DEPARTMENT
                                  FROM {$fd}ORFICHE WHERE LOGICALREF = :r AND TRCODE = 1");
            $ofs->execute([':r' => $orficheRef]);
            $orf = $ofs->fetch(PDO::FETCH_ASSOC);
            if (!$orf) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 404, 'mesaj' => 'Satış siparişi bulunamadı.'];
            }
            if ((int) ($orf['TRCURR'] ?? 0) !== 0) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 422, 'mesaj' => 'Dövizli sipariş beta sürümünde faturalanamaz.'];
            }
            $cariRef = (int) $orf['CLIENTREF'];
            $salesman = (int) ($orf['SALESMANREF'] ?? 0);
            $depo = (int) ($orf['SOURCEINDEX'] ?? 0);

            // NOT: Genel iskonto satırı (ORFLINE LINETYPE=2) ARTIK destekleniyor;
            // aşağıda mal satırlarından sonra ayrı STLINE LINETYPE=2 olarak eklenir.

            // Sipariş satırları (mal, LINETYPE=0)
            $ols = $dbh->prepare("SELECT LOGICALREF, STOCKREF, AMOUNT, PRICE, TOTAL, LINENET, VATMATRAH, VAT, VATAMNT,
                                         DISTDISC, DISTCOST, UOMREF, USREF, UINFO1, UINFO2, UINFO3, UINFO4, UINFO5, UINFO6, UINFO7, UINFO8,
                                         SHIPPEDAMOUNT, LINEEXP
                                  FROM {$fd}ORFLINE WHERE ORDFICHEREF = :r AND LINETYPE = 0 ORDER BY LINENO_, LOGICALREF");
            $ols->execute([':r' => $orficheRef]);
            $satirlar = $ols->fetchAll(PDO::FETCH_ASSOC);
            if (!$satirlar) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 422, 'mesaj' => 'Faturalanacak sipariş satırı yok.'];
            }

            // Toplamlar (siparişten — fatura = siparişin aynası)
            $brut = 0.0; $isk = 0.0; $net = 0.0;
            foreach ($satirlar as $s) {
                $brut += (float) $s['TOTAL'];
                $isk  += (float) $s['DISTDISC'];
                $net  += (float) $s['LINENET'];
            }
            $brut = round($brut, 2); $isk = round($isk, 2); $net = round($net, 2);

            // Şablonlar: geçerli bir TL satış faturası ve onun bileşenleri
            $invSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}INVOICE WHERE TRCODE=8 AND (TRCURR=0 OR TRCURR IS NULL) AND NETTOTAL>0 ORDER BY LOGICALREF DESC")->fetchColumn();
            if ($invSablon <= 0) {
                throw new RuntimeException('Şablon TL fatura bulunamadı.');
            }
            $stfSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}STFICHE WHERE INVOICEREF = {$invSablon}")->fetchColumn();
            $stlSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}STLINE WHERE INVOICEREF = {$invSablon} AND LINETYPE = 0")->fetchColumn();
            $clfSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CLFLINE WHERE SOURCEFREF = {$invSablon} AND TRCODE = 38 AND MODULENR = 4")->fetchColumn();
            if ($stfSablon <= 0 || $stlSablon <= 0 || $clfSablon <= 0) {
                throw new RuntimeException('Fatura bileşen şablonları (STFICHE/STLINE/CLFLINE) bulunamadı.');
            }

            // Genel iskonto satırları (ORFLINE LINETYPE=2) + onlar için STLINE LINETYPE=2 şablonu
            $iskStmt = $dbh->prepare("SELECT LOGICALREF, TOTAL, LINEEXP FROM {$fd}ORFLINE WHERE ORDFICHEREF=:r AND LINETYPE=2 ORDER BY LINENO_, LOGICALREF");
            $iskStmt->execute([':r' => $orficheRef]);
            $iskSatirlar = $iskStmt->fetchAll(PDO::FETCH_ASSOC);
            $stlSablon2 = 0;
            if ($iskSatirlar) {
                $stlSablon2 = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}STLINE WHERE TRCODE=8 AND LINETYPE=2 ORDER BY LOGICALREF DESC")->fetchColumn();
                if ($stlSablon2 <= 0) {
                    throw new RuntimeException('Genel iskonto satırı şablonu (STLINE LINETYPE=2) bulunamadı.');
                }
            }

            // Numaralar (uygulamaya özel seriler — LOGO serisinden ayrı)
            $invNo = fatura_ficheno_uret($dbh, "{$fd}INVOICE", $invPrefix, 10, 'TRCODE=8');
            $irsNo = fatura_ficheno_uret($dbh, "{$fd}STFICHE", $irsPrefix, 12, 'TRCODE=8 AND IOCODE=3');

            // ---------- 1) INVOICE ----------
            $invRef = fatura_klonla($dbh, "{$fd}INVOICE", $invSablon, ['FICHENO' => $invNo, 'GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}INVOICE SET
                TRCODE=8, GRPCODE=2, FICHENO=:no,
                DATE_=DATEADD(day,0,datediff(day,0,GETDATE())), TIME_=:ts, DOCDATE=DATEADD(day,0,datediff(day,0,GETDATE())),
                CLIENTREF=:cari, SALESMANREF=:sm, BRANCH=0, DEPARTMENT=0, SOURCEINDEX=:depo,
                ADDDISCOUNTS=:isk, TOTALDISCOUNTS=:isk2, TOTALDISCOUNTED=:brut, GROSSTOTAL=:brut2,
                TOTALVAT=0, NETTOTAL=:net, TRNET=:net2, REPORTNET=:net3, REPORTRATE=1, TRCURR=0, TRRATE=0,
                ACCFICHEREF=0, ACCOUNTED=0, CANCELLED=0, PRINTCNT=0, EINVOICE=0, PROFILEID=0,
                GENEXCTYP=2, LINEEXCTYP=0, ENTEGSET=247, RECSTATUS=1, AFFECTRISK=1, GENEXP1=:exp,
                DEDUCTIONPART1=0, DEDUCTIONPART2=0, SHIPINFOREF=0, PAYDEFREF=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ssn,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0,
                GUID=:guid
                WHERE LOGICALREF=:ref")->execute([
                ':no' => $invNo, ':ts' => $ts, ':cari' => $cariRef, ':sm' => $salesman, ':depo' => $depo,
                ':isk' => fatura_say($isk), ':isk2' => fatura_say($isk), ':brut' => fatura_say($brut), ':brut2' => fatura_say($brut),
                ':net' => fatura_say($net), ':net2' => fatura_say($net), ':net3' => fatura_say($net),
                ':exp' => 'Lumen fatura (siparis ' . (string) $orf['FICHENO'] . ')',
                ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ssn' => $ss, ':guid' => (string) guid(), ':ref' => $invRef,
            ]);

            // ---------- 2) STFICHE (irsaliye) ----------
            $stfRef = fatura_klonla($dbh, "{$fd}STFICHE", $stfSablon, ['FICHENO' => $irsNo, 'INVNO' => $invNo, 'GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}STFICHE SET
                TRCODE=8, GRPCODE=2, IOCODE=3, FICHENO=:no, INVNO=:invno, INVOICEREF=:inv,
                DATE_=DATEADD(day,0,datediff(day,0,GETDATE())), FTIME=:ts,
                DOCDATE=DATEADD(day,0,datediff(day,0,GETDATE())), DOCTIME=:ts2,
                SHIPDATE=DATEADD(day,0,datediff(day,0,GETDATE())), SHIPTIME=:ts3,
                CLIENTREF=:cari, SALESMANREF=:sm, SOURCEINDEX=:depo, BILLED=1, FICHECNT=1, DISPSTATUS=1,
                ADDDISCOUNTS=:isk, TOTALDISCOUNTS=:isk2, TOTALDISCOUNTED=:brut, GROSSTOTAL=:brut2,
                TOTALVAT=0, NETTOTAL=:net, REPORTNET=:net2, REPORTRATE=1, TRCURR=0, TRRATE=0, TRNET=:net3,
                ACCFICHEREF=0, CANCELLED=0, PRINTCNT=0, GENEXCTYP=2, AFFECTRISK=1, RECSTATUS=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ssn,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0,
                GUID=:guid
                WHERE LOGICALREF=:ref")->execute([
                ':no' => $irsNo, ':invno' => $invNo, ':inv' => $invRef, ':ts' => $ts, ':ts2' => $ts, ':ts3' => $ts,
                ':cari' => $cariRef, ':sm' => $salesman, ':depo' => $depo,
                ':isk' => fatura_say($isk), ':isk2' => fatura_say($isk), ':brut' => fatura_say($brut), ':brut2' => fatura_say($brut),
                ':net' => fatura_say($net), ':net2' => fatura_say($net), ':net3' => fatura_say($net),
                ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ssn' => $ss, ':guid' => (string) guid(), ':ref' => $stfRef,
            ]);

            // ---------- 3) STLINE (her satır stok çıkışı) ----------
            $detayStl = [];
            $sira = 0;
            $stlUpd = $dbh->prepare("UPDATE {$fd}STLINE SET
                TRCODE=8, LINETYPE=0, IOCODE=4, STOCKREF=:stok,
                DATE_=DATEADD(day,0,datediff(day,0,GETDATE())), FTIME=:ts,
                STFICHEREF=:stf, STFICHELNNO=:lnno, INVOICEREF=:inv, INVOICELNNO=:lnno2,
                ORDTRANSREF=:ordt, ORDFICHEREF=:ordf, CLIENTREF=:cari, SALESMANREF=:sm, SOURCEINDEX=:depo,
                AMOUNT=:amt, PRICE=:prc, TOTAL=:tot, LINENET=:lnet, VATMATRAH=:vm, VAT=0, VATAMNT=0,
                DISTDISC=:dd, DISTCOST=:dc, UOMREF=:uom, USREF=:us, UINFO1=:u1, UINFO2=:u2,
                BILLED=1, BILLEDITEM=0, CANCELLED=0, RECSTATUS=1, AFFECTRISK=1,
                TRCURR=0, TRRATE=0, REPORTRATE=1, PRCURR=0, LINEEXP=:exp,
                MONTH_=MONTH(GETDATE()), YEAR_=YEAR(GETDATE()),
                OUTCOST=0, RETCOST=0, GUID=:guid
                WHERE LOGICALREF=:ref");
            foreach ($satirlar as $s) {
                $sira++;
                $stlRef = fatura_klonla($dbh, "{$fd}STLINE", $stlSablon, ['GUID' => (string) guid()]);
                $stlUpd->execute([
                    ':stok' => (int) $s['STOCKREF'], ':ts' => $ts,
                    ':stf' => $stfRef, ':lnno' => $sira, ':inv' => $invRef, ':lnno2' => $sira,
                    ':ordt' => (int) $s['LOGICALREF'], ':ordf' => $orficheRef, ':cari' => $cariRef, ':sm' => $salesman, ':depo' => $depo,
                    ':amt' => fatura_say($s['AMOUNT']), ':prc' => fatura_say($s['PRICE']), ':tot' => fatura_say($s['TOTAL']),
                    ':lnet' => fatura_say($s['LINENET']), ':vm' => fatura_say($s['VATMATRAH']),
                    ':dd' => fatura_say($s['DISTDISC']), ':dc' => fatura_say($s['DISTCOST']),
                    ':uom' => (int) $s['UOMREF'], ':us' => (int) $s['USREF'],
                    ':u1' => fatura_say($s['UINFO1'] ?? 1), ':u2' => fatura_say($s['UINFO2'] ?? 1),
                    ':exp' => (string) ($s['LINEEXP'] ?? ''), ':guid' => (string) guid(), ':ref' => $stlRef,
                ]);
                $detayStl[] = ['stl' => $stlRef, 'orf' => (int) $s['LOGICALREF'],
                               'eski' => (float) $s['SHIPPEDAMOUNT'], 'yeni' => (float) $s['SHIPPEDAMOUNT'] + (float) $s['AMOUNT'],
                               'stok' => (int) $s['STOCKREF'], 'amt' => (float) $s['AMOUNT']];
            }

            // ---------- 3b) Genel iskonto satırları (STLINE LINETYPE=2) ----------
            // LOGO genel iskontoyu hem mal satırına dağıtır (DISTDISC) hem de ayrı bir
            // LINETYPE=2 iskonto satırı tutar. Bu satır stok çıkışı DEĞİLDİR (IOCODE=0,
            // STOCKREF=0, BILLED=0, AFFECTRISK=0) — yalnızca iskonto kaydıdır.
            if ($iskSatirlar) {
                // İskonto satırı (LINETYPE=2) zaten geçerli bir LINETYPE=2 STLINE'dan klonlanıyor
                // (IOCODE=0, AMOUNT=0, BILLED=0... değerleri şablondan korunuyor). UPD trigger bu
                // satırda birim çevriminde sıfıra böldüğü için UPDATE YAPMIYORUZ; iş alanlarını
                // klon INSERT'in içinde override ediyoruz (yalnızca INS trigger çalışır, o tolere eder).
                $bugunStr = date('Y-m-d');
                $ayNo = date('n'); $yilNo = date('Y');
                foreach ($iskSatirlar as $is) {
                    $sira++;
                    $iskRef = fatura_klonla($dbh, "{$fd}STLINE", $stlSablon2, [
                        'GUID' => (string) guid(),
                        'INVOICEREF' => (string) $invRef, 'INVOICELNNO' => (string) $sira,
                        'ORDTRANSREF' => (string) (int) $is['LOGICALREF'], 'ORDFICHEREF' => (string) $orficheRef,
                        'CLIENTREF' => (string) $cariRef, 'SALESMANREF' => (string) $salesman, 'SOURCEINDEX' => (string) $depo,
                        'STFICHEREF' => '0', 'STFICHELNNO' => '0',
                        'TOTAL' => fatura_say($is['TOTAL']),
                        'DATE_' => $bugunStr, 'FTIME' => (string) $ts, 'MONTH_' => (string) $ayNo, 'YEAR_' => (string) $yilNo,
                        'LINEEXP' => (string) ($is['LINEEXP'] ?? ''),
                    ]);
                    $detayStl[] = ['stl' => $iskRef, 'orf' => (int) $is['LOGICALREF'],
                                   'eski' => null, 'yeni' => null, 'stok' => 0, 'amt' => 0.0, 'iskonto' => true];
                }
            }

            // ---------- 4) CLFLINE (cari borç) ----------
            $clfRef = fatura_klonla($dbh, "{$fd}CLFLINE", $clfSablon, ['GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}CLFLINE SET
                CLIENTREF=:cari, SOURCEFREF=:inv, MODULENR=4, TRCODE=38, LINENR=0, SIGN=0,
                DATE_=DATEADD(day,0,datediff(day,0,GETDATE())), DOCDATE=DATEADD(day,0,datediff(day,0,GETDATE())), FTIME=:ts,
                TRANNO=:tranno, AMOUNT=:net, TRNET=:net2, REPORTNET=:net3, REPORTRATE=1, TRCURR=0, TRRATE=0,
                SALESMANREF=:sm, MONTH_=MONTH(GETDATE()), YEAR_=YEAR(GETDATE()),
                ACCFICHEREF=0, ACCOUNTED=0, CANCELLED=0, AFFECTRISK=1,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ssn,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0,
                GUID=:guid
                WHERE LOGICALREF=:ref")->execute([
                ':cari' => $cariRef, ':inv' => $invRef, ':ts' => $ts, ':tranno' => $invNo,
                ':net' => fatura_say($net), ':net2' => fatura_say($net), ':net3' => fatura_say($net),
                ':sm' => $salesman, ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ssn' => $ss,
                ':guid' => (string) guid(), ':ref' => $clfRef,
            ]);

            // ---------- 5) ORFLINE.SHIPPEDAMOUNT ----------
            // NOT: LOGO'nun LG_STLINE_INS trigger'ı, ORDTRANSREF dolu bir STLINE eklenince
            // ORFLINE.SHIPPEDAMOUNT'u ZATEN otomatik günceller. Burada manuel UPDATE yapılırsa
            // çift sayım olur (test: 1 yerine 2). Bu yüzden manuel güncelleme YOK; geri alma
            // için eski/yeni değerler zaten $detayStl içinde toplandı (aşağıda log'a yazılır).

            // ---------- 6) Log ----------
            $logIns = $dbh->prepare("INSERT INTO M_FATURA_LOG
                (REQUEST_ID, FIRMADONEM, DURUM, INVOICE_REF, INVOICE_FICHENO, STFICHE_REF, STFICHE_FICHENO, CLFLINE_REF,
                 ORFICHE_REF, SIPARIS_NO, CLIENTREF, NETTOTAL, KULLANICI, IP)
                VALUES (:req, :fd, 'AKTIF', :inv, :invno, :stf, :stfno, :clf, :orf, :sipno, :cari, :net, :kul, :ip)");
            $logIns->execute([
                ':req' => $requestId, ':fd' => $fd, ':inv' => $invRef, ':invno' => $invNo,
                ':stf' => $stfRef, ':stfno' => $irsNo, ':clf' => $clfRef, ':orf' => $orficheRef,
                ':sipno' => (string) $orf['FICHENO'], ':cari' => $cariRef, ':net' => fatura_say($net),
                ':kul' => $personel, ':ip' => $ip,
            ]);
            $logId = (int) $dbh->lastInsertId();
            if ($logId <= 0) {
                $logId = (int) $dbh->query("SELECT MAX(ID) FROM M_FATURA_LOG WHERE REQUEST_ID = " . $dbh->quote($requestId))->fetchColumn();
            }

            $detIns = $dbh->prepare("INSERT INTO M_FATURA_LOG_DETAY
                (LOG_ID, SATIR_TIP, STLINE_REF, ORFLINE_REF, ESKI_SHIPPEDAMOUNT, YENI_SHIPPEDAMOUNT, STOCKREF, AMOUNT)
                VALUES (:log, :tip, :stl, :orf, :eski, :yeni, :stok, :amt)");
            foreach ($detayStl as $d) {
                $detIns->execute([':log' => $logId, ':tip' => 1, ':stl' => $d['stl'], ':orf' => null,
                                  ':eski' => null, ':yeni' => null, ':stok' => $d['stok'], ':amt' => fatura_say($d['amt'])]);
                // İskonto satırının (LINETYPE=2) SHIPPEDAMOUNT izi yok (mal değil); yalnızca mal satırları için.
                if (empty($d['iskonto'])) {
                    $detIns->execute([':log' => $logId, ':tip' => 2, ':stl' => null, ':orf' => $d['orf'],
                                      ':eski' => fatura_say($d['eski']), ':yeni' => fatura_say($d['yeni']), ':stok' => $d['stok'], ':amt' => fatura_say($d['amt'])]);
                }
            }

            $dbh->commit();
            return ['ok' => true, 'mesaj' => $invNo . ' numaralı fatura oluşturuldu.', 'invoice_ref' => $invRef,
                    'fiche_no' => $invNo, 'net' => $net, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            // Ayrı bağlantı gerektirmeyen hafif hata logu (transaction dışı)
            try {
                fatura_log_tablo_olustur($dbh);
                $dbh->prepare("INSERT INTO M_FATURA_LOG (REQUEST_ID, FIRMADONEM, DURUM, ORFICHE_REF, CLIENTREF, NETTOTAL, KULLANICI, IP, HATA)
                               VALUES (:req, :fd, 'HATA', :orf, 0, 0, :kul, :ip, :hata)")
                    ->execute([':req' => $requestId . '-ERR', ':fd' => $fd, ':orf' => $orficheRef,
                               ':kul' => $personel, ':ip' => $ip, ':hata' => $e->getMessage()]);
            } catch (Throwable $e2) {
                error_log('fatura_olustur_core log: ' . $e2->getMessage());
            }
            error_log('fatura_olustur_core: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Fatura oluşturulamadı, hiçbir kayıt yapılmadı.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('fatura_geri_al_core')) {
    /**
     * Bir faturayı M_FATURA_LOG kaydından tam geri alır. Tek transaction.
     * @return array{ok:bool, mesaj:string, kod?:int}
     */
    function fatura_geri_al_core(PDO $dbh, string $fd, int $logId, int $personel, string $not = ''): array
    {
        try {
            $dbh->beginTransaction();

            $lg = $dbh->prepare("SELECT * FROM M_FATURA_LOG WHERE ID = :id");
            $lg->execute([':id' => $logId]);
            $log = $lg->fetch(PDO::FETCH_ASSOC);
            if (!$log) {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 404, 'mesaj' => 'Fatura kaydı bulunamadı.'];
            }
            if ((string) $log['DURUM'] !== 'AKTIF') {
                $dbh->rollBack();
                return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bu fatura zaten geri alınmış veya aktif değil.'];
            }
            $invRef = (int) $log['INVOICE_REF'];
            $stfRef = (int) $log['STFICHE_REF'];
            $clfRef = (int) $log['CLFLINE_REF'];

            // 1) Cari borç sil
            if ($clfRef > 0) {
                $dbh->prepare("DELETE FROM {$fd}CLFLINE WHERE LOGICALREF=:r AND SOURCEFREF=:inv AND TRCODE=38 AND MODULENR=4")
                    ->execute([':r' => $clfRef, ':inv' => $invRef]);
            }
            // 2) Stok çıkış satırları sil (logdan + emniyet: faturaya bağlı kalan)
            $det = $dbh->prepare("SELECT STLINE_REF FROM M_FATURA_LOG_DETAY WHERE LOG_ID=:l AND SATIR_TIP=1 AND STLINE_REF IS NOT NULL");
            $det->execute([':l' => $logId]);
            $stlDel = $dbh->prepare("DELETE FROM {$fd}STLINE WHERE LOGICALREF=:r AND INVOICEREF=:inv");
            foreach ($det->fetchAll(PDO::FETCH_COLUMN) as $stl) {
                $stlDel->execute([':r' => (int) $stl, ':inv' => $invRef]);
            }
            if ($invRef > 0) {
                $dbh->prepare("DELETE FROM {$fd}STLINE WHERE INVOICEREF=:inv")->execute([':inv' => $invRef]);
            }
            // 3) İrsaliye sil
            if ($stfRef > 0) {
                $dbh->prepare("DELETE FROM {$fd}STFICHE WHERE LOGICALREF=:r AND INVOICEREF=:inv AND TRCODE=8")
                    ->execute([':r' => $stfRef, ':inv' => $invRef]);
            }
            // 4) Fatura başlığı sil
            if ($invRef > 0) {
                $dbh->prepare("DELETE FROM {$fd}INVOICE WHERE LOGICALREF=:r AND TRCODE=8")->execute([':r' => $invRef]);
            }
            // 5) Sipariş SHIPPEDAMOUNT eski değerine dön
            $sd = $dbh->prepare("SELECT ORFLINE_REF, ESKI_SHIPPEDAMOUNT FROM M_FATURA_LOG_DETAY WHERE LOG_ID=:l AND SATIR_TIP=2 AND ORFLINE_REF IS NOT NULL");
            $sd->execute([':l' => $logId]);
            $shipUpd = $dbh->prepare("UPDATE {$fd}ORFLINE SET SHIPPEDAMOUNT=:eski WHERE LOGICALREF=:ref");
            foreach ($sd->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $shipUpd->execute([':eski' => fatura_say($r['ESKI_SHIPPEDAMOUNT']), ':ref' => (int) $r['ORFLINE_REF']]);
            }
            // 6) Log işaretle
            $dbh->prepare("UPDATE M_FATURA_LOG SET DURUM='GERIALINDI', GERIALAN_KULLANICI=:k, GERIALMA=GETDATE(), GERIALMA_NOT=:n WHERE ID=:id AND DURUM='AKTIF'")
                ->execute([':k' => $personel, ':n' => mb_substr($not, 0, 240), ':id' => $logId]);

            $dbh->commit();
            return ['ok' => true, 'mesaj' => 'Fatura geri alındı; stok ve cari bakiye eski haline döndü.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) {
                $dbh->rollBack();
            }
            error_log('fatura_geri_al_core: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Geri alma başarısız; fatura olduğu gibi duruyor.', 'hata' => $e->getMessage()];
        }
    }
}

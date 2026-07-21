<?php

declare(strict_types=1);

/**
 * cek_lib.php — Çek Girişi (geri alınabilir)  [Faz-1: yalnız müşteri çeki girişi]
 *
 * Bir müşteri çekinin portföye alınması = tek transaction'da DÖRT kayıt, sırayla bağlı:
 *   [1] CSCARD  (çek kartı)      DOC=1 müşteri çeki, CURRSTAT=1 portföyde
 *   [2] CSROLL  (giriş bordrosu) TRCODE=1, TOTAL=tutar, DOCCNT=1, CARDREF=cari, ROLLNO üret
 *   [3] CSTRANS (giriş hareketi) TRCODE=1, CSREF→çek, ROLLREF→bordro, CARDMD=5, CARDREF=cari
 *   [4] CLFLINE (cari alacak)    MODULENR=6, TRCODE=61, SIGN=1 → cari bakiye DÜŞER
 *                                SOURCEFREF = CSROLL.LOGICALREF (bordroya bağlanır!), TRANNO = ROLLNO
 *
 * KRİTİK (adversarial doğrulandı, [[akl-logo-cek-mekanigi]]):
 *  - Cari ayak MODULENR=6/TRCODE=61 (MODULENR=4/TRCODE=31 DEĞİL — o manuel dekont modülü).
 *  - CLFLINE.SOURCEFREF → CSROLL (CSTRANS değil; LOGICALREF çakışır). Okuma tarafı (../cari/lg_hareket.php)
 *    bordro yolunu bekler → yazma böyle olmalı.
 *  - CLFLINE'da 3 trigger var → OUTPUT INSERTED.LOGICALREF ile kesin kimlik (klon tekniği).
 *    CSCARD/CSROLL/CSTRANS'ta trigger yok ama tutarlılık için hepsinde aynı desen.
 *
 * Numaralama: PORTFOYNO = sayısal seri MAX+1 (8 hane; resmi=R+7). ROLLNO = "A" ÖN EKLİ bağımsız
 *             seri ('A'+7 hane, TRCODE bazlı sayaç) → LOGO'nun sayısal bordro no'suyla ÇAKIŞMAZ
 *             (kasa fişi A-serisi kararının devamı, 2026-07-07);
 *             CLFLINE.TRANNO = ROLLNO (birebir).
 *
 * Çağıran dosya ayr.php yüklemiş olmalı (guid(), $kayitsaat, $saat, $dakika, $saniye global).
 */

if (!function_exists('cek_log_tablo_olustur')) {
    function cek_log_tablo_olustur(PDO $dbh): void
    {
        $dbh->exec("IF OBJECT_ID('dbo.M_CEK_LOG','U') IS NULL
        CREATE TABLE M_CEK_LOG (
            ID INT IDENTITY(1,1) PRIMARY KEY,
            REQUEST_ID VARCHAR(40) NOT NULL,
            FIRMADONEM VARCHAR(20) NOT NULL,
            DURUM VARCHAR(20) NOT NULL CONSTRAINT DF_MCL_DURUM DEFAULT 'AKTIF',   -- AKTIF | GERIALINDI | HATA
            TUR VARCHAR(10) NOT NULL CONSTRAINT DF_MCL_TUR DEFAULT 'giris',       -- giris | (cikis: faz-2)
            CSCARD_REF INT NULL, CSCARD_PORTFOYNO VARCHAR(40) NULL,
            CSROLL_REF INT NULL, CSROLL_ROLLNO VARCHAR(40) NULL,
            CSTRANS_REF INT NULL,
            CLFLINE_REF INT NULL, CLFLINE_TRANNO VARCHAR(40) NULL,
            CLIENTREF INT NOT NULL,
            TUTAR DECIMAL(28,8) NOT NULL CONSTRAINT DF_MCL_TUT DEFAULT 0,
            CEKNO NVARCHAR(60) NULL, BANKNAME NVARCHAR(120) NULL, VADE DATE NULL, SAHIBI NVARCHAR(200) NULL,
            ACIKLAMA NVARCHAR(255) NULL,
            KULLANICI INT NULL, IP VARCHAR(64) NULL,
            OLUSTURMA DATETIME NOT NULL CONSTRAINT DF_MCL_OLU DEFAULT GETDATE(),
            HATA NVARCHAR(MAX) NULL,
            GERIALAN_KULLANICI INT NULL, GERIALMA DATETIME NULL, GERIALMA_NOT VARCHAR(255) NULL,
            CONSTRAINT UQ_MCL_REQ UNIQUE (REQUEST_ID)
        )");
        $dbh->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MCL_CARI')  CREATE INDEX IX_MCL_CARI  ON M_CEK_LOG(CLIENTREF)");
        $dbh->exec("IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE name='IX_MCL_DURUM') CREATE INDEX IX_MCL_DURUM ON M_CEK_LOG(DURUM)");
        // Çoklu çek (tek bordro = N çek) desteği — sonradan eklenen kolonlar (idempotent)
        $dbh->exec("IF COL_LENGTH('M_CEK_LOG','DOCCNT')   IS NULL ALTER TABLE M_CEK_LOG ADD DOCCNT INT NOT NULL CONSTRAINT DF_MCL_DOCCNT DEFAULT 1");
        $dbh->exec("IF COL_LENGTH('M_CEK_LOG','KALEMLER') IS NULL ALTER TABLE M_CEK_LOG ADD KALEMLER NVARCHAR(MAX) NULL");   // JSON: [{cscard_ref,cstrans_ref,portfoyno,cekno,tutar,vade,banka,sahibi},...]
    }
}

if (!function_exists('cek_say')) {
    /** Tutarı LOGO'nun kabul ettiği nokta-ondalıklı string'e çevir (locale bağımsız). */
    function cek_say(float|int|string|null $v): string
    {
        return number_format((float) $v, 8, '.', '');
    }
}

if (!function_exists('cek_klonla')) {
    /**
     * Geçerli bir kayıt satırını (LOGICALREF hariç, computed/timestamp hariç) IDENTITY ile kopyalar,
     * OUTPUT INSERTED.LOGICALREF ile yeni kimliği KESİN alır (trigger-safe). override → SELECT'te literal.
     * @return int yeni LOGICALREF
     */
    function cek_klonla(PDO $dbh, string $fullTable, int $sablonRef, array $override = []): int
    {
        $cols = $dbh->query("
            SELECT c.name FROM sys.columns c
            WHERE c.object_id = OBJECT_ID('{$fullTable}')
              AND c.name <> 'LOGICALREF' AND c.is_computed = 0 AND c.system_type_id <> 189
            ORDER BY c.column_id
        ")->fetchAll(PDO::FETCH_COLUMN);
        if (!$cols) {
            throw new RuntimeException("Klon: {$fullTable} kolonları okunamadı.");
        }
        $list = implode(',', array_map(static fn($c) => "[{$c}]", $cols));
        $sel = implode(',', array_map(static function ($c) use ($override, $dbh) {
            return array_key_exists($c, $override) ? $dbh->quote((string) $override[$c]) : "[{$c}]";
        }, $cols));
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

if (!function_exists('cek_seri_no')) {
    /** Sayısal seriden sonraki numarayı üretir (soldan sıfır dolgulu). where ile scope daraltılır.
     *  $prefix verilirse (örn 'A') SADECE o ön-ekli kayıtlar içinde bağımsız sayar ve ön-ekli döner
     *  (örn 'A0000001') → LOGO'nun sayısal serisiyle ÇAKIŞMAZ (kasa fişi TAHSILAT_FIS_ONEK deseni). */
    function cek_seri_no(PDO $dbh, string $fullTable, string $col, int $pad, string $where = '', string $prefix = ''): string
    {
        if ($prefix !== '') {
            $num = "SUBSTRING({$col}, " . (strlen($prefix) + 1) . ", 30)";
            $sql = "SELECT MAX(CAST({$num} AS BIGINT)) FROM {$fullTable}
                    WHERE {$col} LIKE :pfx AND {$num} NOT LIKE '%[^0-9]%' AND {$num} <> ''";
            if ($where !== '') { $sql .= " AND {$where}"; }
            $st = $dbh->prepare($sql);
            $st->execute([':pfx' => $prefix . '%']);
            $son = (int) $st->fetchColumn();
            return $prefix . str_pad((string) ($son + 1), $pad, '0', STR_PAD_LEFT);
        }
        // Salt-rakam filtresi (ISNUMERIC bazı bozuk değerlerde CAST hatası verir).
        $sql = "SELECT MAX(CAST({$col} AS BIGINT)) FROM {$fullTable} WHERE {$col} NOT LIKE '%[^0-9]%' AND {$col} <> ''";
        if ($where !== '') { $sql .= " AND {$where}"; }
        $son = (int) $dbh->query($sql)->fetchColumn();
        return str_pad((string) ($son + 1), $pad, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('cek_portfoy_seri_base')) {
    /** Seçili serideki mevcut EN BÜYÜK portföy numarasını (int) döndürür. Sonraki = base+1. */
    function cek_portfoy_seri_base(PDO $dbh, string $fd, bool $resmi): int
    {
        if ($resmi) {
            return (int) $dbh->query("SELECT MAX(CAST(SUBSTRING(PORTFOYNO,2,30) AS BIGINT))
                FROM {$fd}CSCARD WHERE PORTFOYNO LIKE 'R%' AND LEN(PORTFOYNO) > 1
                  AND SUBSTRING(PORTFOYNO,2,30) NOT LIKE '%[^0-9]%'")->fetchColumn();
        }
        return (int) $dbh->query("SELECT MAX(CAST(PORTFOYNO AS BIGINT))
            FROM {$fd}CSCARD WHERE PORTFOYNO NOT LIKE '%[^0-9]%' AND PORTFOYNO <> ''")->fetchColumn();
    }
}

if (!function_exists('cek_portfoy_bicim')) {
    /** Bir sayıyı seri biçimine sokar: resmi→"R"+7 hane (R0000086), gayri→8 hane (00000138). */
    function cek_portfoy_bicim(bool $resmi, int $num): string
    {
        return $resmi ? ('R' . str_pad((string) $num, 7, '0', STR_PAD_LEFT)) : str_pad((string) $num, 8, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('cek_portfoy_no')) {
    /** Seçili serideki SONRAKİ tek portföy no (tekli giriş / önizleme için). */
    function cek_portfoy_no(PDO $dbh, string $fd, bool $resmi): string
    {
        return cek_portfoy_bicim($resmi, cek_portfoy_seri_base($dbh, $fd, $resmi) + 1);
    }
}

if (!function_exists('cek_giris_core')) {
    /**
     * Bir müşteri çekini portföye alır (CSCARD + CSROLL + CSTRANS + CLFLINE, tek transaction).
     * @param array $cek  ['tutar'=>float,'cekno'=>string,'banka'=>string,'vade'=>string(YYYY-MM-DD),'sahibi'=>string,'aciklama'=>string]
     * @return array{ok:bool, mesaj:string, kod?:int, cscard_ref?:int, csroll_ref?:int, cstrans_ref?:int, clfline_ref?:int, portfoyno?:string, rollno?:string, tutar?:float, log_id?:int}
     */
    function cek_giris_core(PDO $dbh, string $firma, string $fd, int $cariRef, array $cek, int $personel, string $requestId, array $opt = []): array
    {
        global $kayitsaat, $saat, $dakika, $saniye;
        $ip  = (string) ($opt['ip'] ?? '');
        $ts  = (int) $kayitsaat;
        $hh  = (int) $saat; $mm = (int) $dakika; $ss = (int) $saniye;

        $resmi    = !empty($cek['resmi']);   // resmi çek → R serisi portföy no; gayri resmi → düz sayısal
        $aciklama = mb_substr(trim((string) ($cek['aciklama'] ?? '')), 0, 240);

        // Kalemleri normalize et — çoklu çek (tek bordro = N çek). Tekli eski format da desteklenir.
        $ham = (isset($cek['kalemler']) && is_array($cek['kalemler']) && $cek['kalemler'] !== [])
            ? $cek['kalemler']
            : [['tutar' => $cek['tutar'] ?? 0, 'cekno' => $cek['cekno'] ?? '', 'banka' => $cek['banka'] ?? '', 'vade' => $cek['vade'] ?? '', 'sahibi' => $cek['sahibi'] ?? '']];
        $kalemler = [];
        foreach ($ham as $x) {
            $t = round((float) ($x['tutar'] ?? 0), 2);
            $v = trim((string) ($x['vade'] ?? ''));
            if ($t <= 0) { return ['ok' => false, 'kod' => 422, 'mesaj' => 'Her çekin tutarı sıfırdan büyük olmalı.']; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return ['ok' => false, 'kod' => 422, 'mesaj' => 'Her çek için geçerli bir vade tarihi girin.']; }
            $kalemler[] = [
                'tutar' => $t, 'vade' => $v,
                'cekno'  => mb_substr(trim((string) ($x['cekno'] ?? '')), 0, 60),
                'banka'  => mb_substr(trim((string) ($x['banka'] ?? '')), 0, 120),
                'sahibi' => mb_substr(trim((string) ($x['sahibi'] ?? '')), 0, 200),
            ];
        }
        $adet = count($kalemler);
        if ($cariRef <= 0)           { return ['ok' => false, 'kod' => 400, 'mesaj' => 'Cari seçilmedi.']; }
        if ($adet < 1 || $adet > 50) { return ['ok' => false, 'kod' => 422, 'mesaj' => 'En az 1, en fazla 50 çek girilebilir.']; }
        $toplam  = round(array_sum(array_column($kalemler, 'tutar')), 2);
        $toplamS = cek_say($toplam);

        // Ağırlıklı ortalama vade günü (LOGO AVERAGEAGE)
        $bugunStr = date('Y-m-d');
        $agSum = 0.0;
        foreach ($kalemler as $kx) { $agSum += $kx['tutar'] * (int) round((strtotime($kx['vade']) - strtotime($bugunStr)) / 86400); }
        $ortGun = $toplam > 0 ? (int) round($agSum / $toplam) : 0;

        cek_log_tablo_olustur($dbh);

        $bugun = "DATEADD(day,0,datediff(day,0,GETDATE()))";

        try {
            $dbh->beginTransaction();

            // Cari doğrula (isim OWING varsayılanı için de kullanılır)
            $cari = $dbh->prepare("SELECT LOGICALREF, DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF = :r AND ACTIVE = 0");
            $cari->execute([':r' => $cariRef]);
            $cariRow = $cari->fetch(PDO::FETCH_ASSOC);
            if (!$cariRow) { $dbh->rollBack(); return ['ok' => false, 'kod' => 404, 'mesaj' => 'Cari bulunamadı/pasif.']; }
            $cariAd = mb_substr((string) $cariRow['DEFINITION_'], 0, 200);

            // Şablonlar: geçerli TL müşteri çeki giriş satırları
            $ccSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSCARD  WHERE DOC=1 AND (TRCURR=0 OR TRCURR IS NULL) AND AMOUNT>0 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            $crSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSROLL  WHERE TRCODE=1 AND (TRCURR=0 OR TRCURR IS NULL) AND TOTAL>0 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            $ctSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSTRANS WHERE TRCODE=1 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            $clSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CLFLINE WHERE MODULENR=6 AND TRCODE=61 AND SIGN=1 AND (TRCURR=0 OR TRCURR IS NULL) AND AMOUNT>0 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            if ($ccSablon <= 0 || $crSablon <= 0 || $ctSablon <= 0 || $clSablon <= 0) {
                throw new RuntimeException('Çek girişi şablonu (CSCARD/CSROLL/CSTRANS/CLFLINE) bulunamadı.');
            }

            // Numaralar (bordro no ortak TRCODE=1 sayacı; portföy no serisi resmi/gayri, N ardışık)
            $rollNo = cek_seri_no($dbh, "{$fd}CSROLL", 'ROLLNO', 7, 'TRCODE=1', 'A');   // 'A'+7=8 char (varchar(9)); LOGO sayısal serisiyle çakışmaz
            $pfBase = cek_portfoy_seri_base($dbh, $fd, $resmi);

            // ---------- [1] CSROLL (giriş bordrosu — tek, N çeki toplar) ----------
            $crRef = cek_klonla($dbh, "{$fd}CSROLL", $crSablon, ['ROLLNO' => $rollNo, 'GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}CSROLL SET
                CARDREF=:cari, CENTERREF=0, ROLLNO=:roll, DATE_={$bugun}, TRCODE=1, BRANCH=0, DEPARTMENT=0, DESTBRANCH=0, DESTDEPARTMENT=0,
                CARDMD=5, PROCTYPE=0, ONEPAYLINE=0, FROMCASH=0, FROMBANK=0, ACCOUNTED=0,
                AVERAGEAGE=:ort, DOCCNT=:adet, PRINTCNT=0,
                TOTAL=:tut, TRCURR=0, TRRATE=1, TRNET=:tut2, REPORTRATE=1, REPORTNET=:tut3,
                GENEXP1='', GENEXP2='', GENEXP3='', GENEXP4='', GENEXP5='', GENEXP6='',
                ACCFICHEREF=0, CASHTRANSREF=0, ACCREF=0, CANCELLED=0, CANCELLEDACC=0,
                AFFECTCOLLATRL=0, COLLATROLLREF=0, AFFECTRISK=1, SALESMANREF=0, APPROVE=0, APPROVEDATE=NULL,
                STATUS=0, DOCDATE=NULL, PRINTDATE=NULL, SPECODE='', CYPHCODE='', DOCODE='', WFSTATUS=0, OPSTAT=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ss,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0
                WHERE LOGICALREF=:ref")->execute([
                ':cari' => $cariRef, ':roll' => $rollNo, ':ort' => $ortGun, ':adet' => $adet,
                ':tut' => $toplamS, ':tut2' => $toplamS, ':tut3' => $toplamS,
                ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $crRef,
            ]);

            // ---------- [2] Her çek: CSCARD + CSTRANS (LINENO_ 1..N, hepsi tek bordroya) ----------
            $ccUpd = $dbh->prepare("UPDATE {$fd}CSCARD SET
                DOC=1, CURRSTAT=1, OURBANKREF=0, SERINO='', NEWSERINO=:cekno, BANKNAME=:banka, OWING=:sahibi,
                BNBRANCHNO='', BNACCOUNTNO='', IBAN='', TAXNR='', SPECODE='', CYPHCODE='', CITY='', KEFIL='', KEFIL2='', MUHABIR='',
                DUEDATE=:vade, SETDATE={$bugun}, STAMP=0,
                AMOUNT=:tut, TRNET=:tut2, REPORTNET=:tut3, TRCURR=0, TRRATE=1, REPORTRATE=1, COLLREPRATE=0, COLLTRRATE=0,
                RISKUPDATE=0, DEVIR=0, INUSE=1, EXTENREF=0,
                AFFECTRISK=1, AFFECTCOLLATRL=0, COLLATROLLREF=0, COLLATCARDREF=0,
                CIRO=0, GIROAMOUNT=0, GIROREPNET=0, GIROREPRATE=0, GIROTRRATE=0, USEGIRORATE=0,
                PRINTCNT=0, PRINTDATE=NULL, SALESMANREF=0, STATUS=0, CANCELLED=0, OPSTAT=0, WFSTATUS=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ss,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0
                WHERE LOGICALREF=:ref");
            $ctUpd = $dbh->prepare("UPDATE {$fd}CSTRANS SET
                DATE_={$bugun}, CSREF=:cc, ROLLREF=:cr, TRCODE=1, ACCOUNTED=0, DEVIR=0, STATUS=1,
                CARDMD=5, CARDREF=:cari, STATNO=1, LINENO_=:ln, ACCREF=0, COSTREF=0, CRSACCREF=0, CRSCOSTREF=0,
                FROMCASH=0, CANCELLED=0, AFFECTCOLLATRL=0, AFFECTRISK=1, USEGIRORATE=0, FROMBANK=0,
                CLACCREF=0, CLCOSTREF=0, OPSTAT=0
                WHERE LOGICALREF=:ref");
            $kayitlar = [];
            $ln = 0;
            foreach ($kalemler as $kx) {
                $ln++;
                $pno = cek_portfoy_bicim($resmi, $pfBase + $ln);
                $sah = $kx['sahibi'] !== '' ? $kx['sahibi'] : $cariAd;
                $tS  = cek_say($kx['tutar']);
                $ccRef = cek_klonla($dbh, "{$fd}CSCARD", $ccSablon, ['PORTFOYNO' => $pno, 'GUID' => (string) guid()]);
                $ccUpd->execute([
                    ':cekno' => $kx['cekno'], ':banka' => $kx['banka'], ':sahibi' => $sah, ':vade' => $kx['vade'],
                    ':tut' => $tS, ':tut2' => $tS, ':tut3' => $tS,
                    ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $ccRef,
                ]);
                $ctRef = cek_klonla($dbh, "{$fd}CSTRANS", $ctSablon, ['GUID' => (string) guid()]);
                $ctUpd->execute([':cc' => $ccRef, ':cr' => $crRef, ':cari' => $cariRef, ':ln' => $ln, ':ref' => $ctRef]);
                $kayitlar[] = ['cscard_ref' => $ccRef, 'cstrans_ref' => $ctRef, 'portfoyno' => $pno,
                               'cekno' => $kx['cekno'], 'tutar' => $kx['tutar'], 'vade' => $kx['vade'], 'banka' => $kx['banka'], 'sahibi' => $sah];
            }

            // ---------- [3] CLFLINE (tek toplam cari alacak; SOURCEFREF → CSROLL) ----------
            $clRef = cek_klonla($dbh, "{$fd}CLFLINE", $clSablon, ['TRANNO' => $rollNo, 'GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}CLFLINE SET
                CLIENTREF=:cari, CLACCREF=0, CLCENTERREF=0, CASHCENTERREF=0, CASHACCOUNTREF=0, VIRMANREF=0,
                SOURCEFREF=:cr, DATE_={$bugun}, DEPARTMENT=0, BRANCH=0, MODULENR=6, TRCODE=61, LINENR=0,
                SPECODE='', CYPHCODE='', TRANNO=:tno, DOCODE='', LINEEXP=:exp, ACCOUNTED=0, SIGN=1,
                AMOUNT=:tut, TRCURR=0, TRRATE=1, TRNET=:tut2, REPORTRATE=1, REPORTNET=:tut3,
                PAYDEFREF=0, ACCFICHEREF=0, PRINTCNT=0, CANCELLED=0, AFFECTRISK=1, AFFECTCOLLATRL=0,
                MONTH_=MONTH(GETDATE()), YEAR_=YEAR(GETDATE()), FTIME=:ts, DOCDATE=NULL, SALESMANREF=0, STATUS=0,
                CHEQINFO='', CREDITCNO='', PAIDINCASH=0, CASHAMOUNT=0, DISCFLAG=0, DISCRATE=0, VATRATE=0, VATAMOUNT=0, DEVIR=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ss,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0
                WHERE LOGICALREF=:ref")->execute([
                ':cari' => $cariRef, ':cr' => $crRef, ':tno' => $rollNo, ':exp' => $aciklama,
                ':tut' => $toplamS, ':tut2' => $toplamS, ':tut3' => $toplamS, ':ts' => $ts,
                ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $clRef,
            ]);

            // ---------- [4] Log (tek bordro = 1 kayıt; N çek KALEMLER JSON'da) ----------
            $ilk = $kayitlar[0];
            $dbh->prepare("INSERT INTO M_CEK_LOG
                (REQUEST_ID, FIRMADONEM, DURUM, TUR, CSCARD_REF, CSCARD_PORTFOYNO, CSROLL_REF, CSROLL_ROLLNO, CSTRANS_REF, CLFLINE_REF, CLFLINE_TRANNO,
                 CLIENTREF, TUTAR, DOCCNT, KALEMLER, CEKNO, BANKNAME, VADE, SAHIBI, ACIKLAMA, KULLANICI, IP)
                VALUES (:req, :fd, 'AKTIF', 'giris', :cc, :pno, :cr, :roll, :ct, :cl, :tno,
                        :cari, :tut, :adet, :kalemler, :cekno, :banka, :vade, :sahibi, :exp, :kul, :ip)")
                ->execute([
                    ':req' => $requestId, ':fd' => $fd, ':cc' => $ilk['cscard_ref'], ':pno' => $ilk['portfoyno'], ':cr' => $crRef, ':roll' => $rollNo,
                    ':ct' => $ilk['cstrans_ref'], ':cl' => $clRef, ':tno' => $rollNo,
                    ':cari' => $cariRef, ':tut' => $toplamS, ':adet' => $adet, ':kalemler' => json_encode($kayitlar, JSON_UNESCAPED_UNICODE),
                    ':cekno' => $ilk['cekno'], ':banka' => $ilk['banka'], ':vade' => $ilk['vade'], ':sahibi' => $ilk['sahibi'],
                    ':exp' => $aciklama, ':kul' => $personel, ':ip' => $ip,
                ]);
            $logId = (int) $dbh->lastInsertId();
            if ($logId <= 0) {
                $logId = (int) $dbh->query("SELECT MAX(ID) FROM M_CEK_LOG WHERE REQUEST_ID = " . $dbh->quote($requestId))->fetchColumn();
            }

            $dbh->commit();
            $mesaj = $adet === 1
                ? (cek_say($toplam) . ' TL çek portföye alındı (portföy no ' . $ilk['portfoyno'] . ').')
                : ($adet . ' çek portföye alındı — toplam ' . cek_say($toplam) . ' TL (bordro no ' . ltrim($rollNo, '0') . ').');
            return ['ok' => true, 'mesaj' => $mesaj,
                    'cscard_ref' => $ilk['cscard_ref'], 'csroll_ref' => $crRef, 'cstrans_ref' => $ilk['cstrans_ref'], 'clfline_ref' => $clRef,
                    'portfoyno' => $ilk['portfoyno'], 'rollno' => $rollNo, 'adet' => $adet, 'tutar' => $toplam, 'kayitlar' => $kayitlar, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) { $dbh->rollBack(); }
            try {
                cek_log_tablo_olustur($dbh);
                $ik = $kalemler[0] ?? ['cekno' => '', 'banka' => '', 'vade' => '', 'sahibi' => ''];
                $dbh->prepare("INSERT INTO M_CEK_LOG (REQUEST_ID, FIRMADONEM, DURUM, TUR, CLIENTREF, TUTAR, DOCCNT, CEKNO, BANKNAME, VADE, SAHIBI, KULLANICI, IP, HATA)
                               VALUES (:req, :fd, 'HATA', 'giris', :cari, :tut, :adet, :cekno, :banka, :vade, :sahibi, :kul, :ip, :hata)")
                    ->execute([':req' => $requestId . '-ERR', ':fd' => $fd, ':cari' => $cariRef, ':tut' => cek_say($toplam ?? 0), ':adet' => (int) ($adet ?? 1),
                               ':cekno' => $ik['cekno'], ':banka' => $ik['banka'], ':vade' => ($ik['vade'] !== '' ? $ik['vade'] : null), ':sahibi' => $ik['sahibi'],
                               ':kul' => $personel, ':ip' => $ip, ':hata' => $e->getMessage()]);
            } catch (Throwable $e2) {
                error_log('cek_giris_core log: ' . $e2->getMessage());
            }
            error_log('cek_giris_core: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Çek girişi yapılamadı, hiçbir kayıt oluşmadı.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('cek_geri_al_core')) {
    /**
     * Bir çek girişini M_CEK_LOG kaydından tam geri alır (CLFLINE→CSTRANS→CSROLL→CSCARD sil). Tek transaction.
     * GUARD: çek sonradan işlem görmüşse (TRCODE=1 dışı CSTRANS hareketi veya CURRSTAT<>1) geri-alma REDDEDİLİR.
     * @return array{ok:bool, mesaj:string, kod?:int}
     */
    function cek_geri_al_core(PDO $dbh, string $fd, int $logId, int $personel, string $not = ''): array
    {
        try {
            $dbh->beginTransaction();
            $lg = $dbh->prepare("SELECT * FROM M_CEK_LOG WHERE ID = :id");
            $lg->execute([':id' => $logId]);
            $log = $lg->fetch(PDO::FETCH_ASSOC);
            if (!$log) { $dbh->rollBack(); return ['ok' => false, 'kod' => 404, 'mesaj' => 'Çek kaydı bulunamadı.']; }
            if ((string) $log['DURUM'] !== 'AKTIF') { $dbh->rollBack(); return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bu çek zaten geri alınmış.']; }

            $crRef = (int) $log['CSROLL_REF'];
            $clRef = (int) $log['CLFLINE_REF'];

            // Kalemler (çoklu çek): KALEMLER JSON'dan cscard/cstrans ref listesi; yoksa tekil kolonlardan
            $kalemler = [];
            if (!empty($log['KALEMLER'])) {
                $j = json_decode((string) $log['KALEMLER'], true);
                if (is_array($j)) { foreach ($j as $k) { $kalemler[] = ['cc' => (int) ($k['cscard_ref'] ?? 0), 'ct' => (int) ($k['cstrans_ref'] ?? 0)]; } }
            }
            if (!$kalemler) { $kalemler[] = ['cc' => (int) $log['CSCARD_REF'], 'ct' => (int) $log['CSTRANS_REF']]; }

            // GUARD: bordrodaki HERHANGİ bir çek portföyden çıkmış / işlem görmüş mü?
            foreach ($kalemler as $k) {
                if ($k['cc'] <= 0) { continue; }
                $hareket = (int) $dbh->query("SELECT COUNT(*) FROM {$fd}CSTRANS WHERE CSREF={$k['cc']} AND TRCODE<>1")->fetchColumn();
                if ($hareket > 0) { $dbh->rollBack(); return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bordrodaki bir çek portföyden çıkmış (ciro/tahsil edilmiş); geri alınamaz. Önce LOGO\'da o hareketi iptal edin.']; }
                $stat = $dbh->query("SELECT CURRSTAT FROM {$fd}CSCARD WHERE LOGICALREF={$k['cc']}")->fetchColumn();
                if ($stat !== false && (int) $stat !== 1) { $dbh->rollBack(); return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bordrodaki bir çek portföyde değil (durum değişmiş); geri alınamaz.']; }
            }

            // Silme sırası: bağımlıdan bağımsıza. CLFLINE (tek toplam) önce → N CSTRANS → CSROLL → N CSCARD
            if ($clRef > 0) {
                $dbh->prepare("DELETE FROM {$fd}CLFLINE WHERE LOGICALREF=:r AND MODULENR=6 AND TRCODE=61 AND SOURCEFREF=:cr")
                    ->execute([':r' => $clRef, ':cr' => $crRef]);
            }
            $delCt = $dbh->prepare("DELETE FROM {$fd}CSTRANS WHERE LOGICALREF=:r AND CSREF=:cc AND TRCODE=1");
            foreach ($kalemler as $k) { if ($k['ct'] > 0 && $k['cc'] > 0) { $delCt->execute([':r' => $k['ct'], ':cc' => $k['cc']]); } }
            if ($crRef > 0) {
                $dbh->prepare("DELETE FROM {$fd}CSROLL WHERE LOGICALREF=:r AND TRCODE=1")->execute([':r' => $crRef]);
            }
            $delCc = $dbh->prepare("DELETE FROM {$fd}CSCARD WHERE LOGICALREF=:r AND DOC=1");
            foreach ($kalemler as $k) { if ($k['cc'] > 0) { $delCc->execute([':r' => $k['cc']]); } }

            $dbh->prepare("UPDATE M_CEK_LOG SET DURUM='GERIALINDI', GERIALAN_KULLANICI=:k, GERIALMA=GETDATE(), GERIALMA_NOT=:n WHERE ID=:id AND DURUM='AKTIF'")
                ->execute([':k' => $personel, ':n' => mb_substr($not, 0, 240), ':id' => $logId]);

            $dbh->commit();
            return ['ok' => true, 'mesaj' => 'Çek girişi geri alındı; portföyden çıkarıldı ve cari bakiye eski haline döndü.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) { $dbh->rollBack(); }
            error_log('cek_geri_al_core: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Geri alma başarısız; çek olduğu gibi duruyor.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('cek_ciro_core')) {
    /**
     * Portföydeki N müşteri çekini bir hedef cariye (tedarikçi) CİRO eder. Tek transaction.
     * Yeni CSCARD OLUŞMAZ — mevcut portföy çekleri (CURRSTAT=1, DOC=1) çıkışa alınır:
     *   1 CSROLL(T3, CARDREF=hedef) + N CSTRANS(T3) + N CSCARD.CURRSTAT 1→2
     *   + N giriş-CSTRANS(T1).STATNO 1→2 (gizli iz) + 1 CLFLINE(M6/T63/SIGN=0, hedef → borcumuz azalır).
     * @param int[] $cekRefleri cirolanacak CSCARD LOGICALREF listesi
     * @return array{ok:bool,mesaj:string,kod?:int,csroll_ref?:int,clfline_ref?:int,rollno?:string,adet?:int,tutar?:float,kayitlar?:array,log_id?:int}
     */
    function cek_ciro_core(PDO $dbh, string $firma, string $fd, int $hedefCariRef, array $cekRefleri, string $aciklama, int $personel, string $requestId, array $opt = []): array
    {
        global $kayitsaat, $saat, $dakika, $saniye;
        $ip = (string) ($opt['ip'] ?? '');
        $ts = (int) $kayitsaat; $hh = (int) $saat; $mm = (int) $dakika; $ss = (int) $saniye;
        $aciklama = mb_substr(trim($aciklama), 0, 240);
        $cekRefleri = array_values(array_unique(array_filter(array_map('intval', $cekRefleri), static fn($x) => $x > 0)));

        if ($hedefCariRef <= 0)          { return ['ok' => false, 'kod' => 400, 'mesaj' => 'Hedef cari (tedarikçi) seçilmedi.']; }
        if (!$cekRefleri)                { return ['ok' => false, 'kod' => 422, 'mesaj' => 'En az bir çek seçin.']; }
        if (count($cekRefleri) > 100)    { return ['ok' => false, 'kod' => 422, 'mesaj' => 'En fazla 100 çek ciro edilebilir.']; }

        cek_log_tablo_olustur($dbh);
        $bugun = "DATEADD(day,0,datediff(day,0,GETDATE()))";
        try {
            $dbh->beginTransaction();

            $c = $dbh->prepare("SELECT LOGICALREF, DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF=:r AND ACTIVE=0");
            $c->execute([':r' => $hedefCariRef]);
            $cRow = $c->fetch(PDO::FETCH_ASSOC);
            if (!$cRow) { $dbh->rollBack(); return ['ok' => false, 'kod' => 404, 'mesaj' => 'Hedef cari bulunamadı/pasif.']; }
            $hedefAd = mb_substr((string) $cRow['DEFINITION_'], 0, 200);

            // Seçili çekleri portföyde + uygun (DOC=1, CURRSTAT=1) doğrula
            $in = implode(',', $cekRefleri);
            $cekler = $dbh->query("SELECT LOGICALREF, PORTFOYNO, NEWSERINO, AMOUNT, CURRSTAT, DOC FROM {$fd}CSCARD WHERE LOGICALREF IN ($in)")->fetchAll(PDO::FETCH_ASSOC);
            if (count($cekler) !== count($cekRefleri)) { $dbh->rollBack(); return ['ok' => false, 'kod' => 404, 'mesaj' => 'Bazı çekler bulunamadı.']; }
            foreach ($cekler as $ck) {
                if ((int) $ck['DOC'] !== 1)      { $dbh->rollBack(); return ['ok' => false, 'kod' => 422, 'mesaj' => 'Sadece müşteri çeki (DOC=1) ciro edilebilir.']; }
                if ((int) $ck['CURRSTAT'] !== 1) { $dbh->rollBack(); return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bir çek portföyde değil (zaten çıkmış/tahsil edilmiş); ciro edilemez.']; }
            }
            $toplam = round(array_sum(array_map(static fn($x) => (float) $x['AMOUNT'], $cekler)), 2);
            $adet = count($cekler);
            $toplamS = cek_say($toplam);

            $crSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSROLL  WHERE TRCODE=3 AND (TRCURR=0 OR TRCURR IS NULL) AND TOTAL>0 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            $ctSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSTRANS WHERE TRCODE=3 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            $clSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CLFLINE WHERE MODULENR=6 AND TRCODE=63 AND SIGN=0 AND (TRCURR=0 OR TRCURR IS NULL) AND AMOUNT>0 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            if ($crSablon <= 0 || $ctSablon <= 0 || $clSablon <= 0) { throw new RuntimeException('Ciro şablonu (CSROLL/CSTRANS/CLFLINE) bulunamadı.'); }

            $rollNo = cek_seri_no($dbh, "{$fd}CSROLL", 'ROLLNO', 7, 'TRCODE=3', 'A');   // 'A'+7=8 char (varchar(9)); LOGO sayısal serisiyle çakışmaz

            // [1] CSROLL (çıkış bordrosu)
            $crRef = cek_klonla($dbh, "{$fd}CSROLL", $crSablon, ['ROLLNO' => $rollNo, 'GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}CSROLL SET
                CARDREF=:cari, CENTERREF=0, ROLLNO=:roll, DATE_={$bugun}, TRCODE=3, BRANCH=0, DEPARTMENT=0, DESTBRANCH=0, DESTDEPARTMENT=0,
                CARDMD=5, PROCTYPE=0, ONEPAYLINE=0, FROMCASH=0, FROMBANK=0, ACCOUNTED=0, AVERAGEAGE=0, DOCCNT=:adet, PRINTCNT=0,
                TOTAL=:tut, TRCURR=0, TRRATE=1, TRNET=:tut2, REPORTRATE=1, REPORTNET=:tut3,
                GENEXP1='', GENEXP2='', GENEXP3='', GENEXP4='', GENEXP5='', GENEXP6='',
                ACCFICHEREF=0, CASHTRANSREF=0, ACCREF=0, CANCELLED=0, CANCELLEDACC=0,
                AFFECTCOLLATRL=0, COLLATROLLREF=0, AFFECTRISK=1, SALESMANREF=0, APPROVE=0, APPROVEDATE=NULL,
                STATUS=0, DOCDATE=NULL, PRINTDATE=NULL, SPECODE='', CYPHCODE='', DOCODE='', WFSTATUS=0, OPSTAT=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ss,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0
                WHERE LOGICALREF=:ref")->execute([
                ':cari' => $hedefCariRef, ':roll' => $rollNo, ':adet' => $adet,
                ':tut' => $toplamS, ':tut2' => $toplamS, ':tut3' => $toplamS,
                ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $crRef,
            ]);

            // [2] her çek: CSTRANS(T3) + CSCARD.CURRSTAT 1→2 + giriş CSTRANS(T1).STATNO 1→2
            $ctUpd = $dbh->prepare("UPDATE {$fd}CSTRANS SET
                DATE_={$bugun}, CSREF=:cc, ROLLREF=:cr, TRCODE=3, ACCOUNTED=0, DEVIR=0, STATUS=2,
                CARDMD=5, CARDREF=:cari, STATNO=1, LINENO_=:ln, ACCREF=0, COSTREF=0, CRSACCREF=0, CRSCOSTREF=0,
                FROMCASH=0, CANCELLED=0, AFFECTCOLLATRL=0, AFFECTRISK=1, USEGIRORATE=0, FROMBANK=0,
                CLACCREF=0, CLCOSTREF=0, OPSTAT=0
                WHERE LOGICALREF=:ref");
            $ccUpd = $dbh->prepare("UPDATE {$fd}CSCARD SET CURRSTAT=2,
                CAPIBLOCK_MODIFIEDBY=:psr, CAPIBLOCK_MODIFIEDDATE=GETDATE(), CAPIBLOCK_MODIFIEDHOUR=:hh, CAPIBLOCK_MODIFIEDMIN=:mm, CAPIBLOCK_MODIFIEDSEC=:ss
                WHERE LOGICALREF=:ref AND CURRSTAT=1 AND DOC=1");
            $t1Upd = $dbh->prepare("UPDATE {$fd}CSTRANS SET STATNO=2 WHERE CSREF=:cc AND TRCODE=1");
            $kayitlar = []; $ln = 0;
            foreach ($cekler as $ck) {
                $ln++;
                $ccRef = (int) $ck['LOGICALREF'];
                $t1Ref = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSTRANS WHERE CSREF=$ccRef AND TRCODE=1 ORDER BY LOGICALREF")->fetchColumn();
                $ctRef = cek_klonla($dbh, "{$fd}CSTRANS", $ctSablon, ['GUID' => (string) guid()]);
                $ctUpd->execute([':cc' => $ccRef, ':cr' => $crRef, ':cari' => $hedefCariRef, ':ln' => $ln, ':ref' => $ctRef]);
                $ccUpd->execute([':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $ccRef]);
                if ($ccUpd->rowCount() < 1) { throw new RuntimeException("Çek $ccRef portföyde değil (eşzamanlı değişmiş olabilir)."); }
                if ($t1Ref > 0) { $t1Upd->execute([':cc' => $ccRef]); }
                $kayitlar[] = ['cscard_ref' => $ccRef, 'cstrans_ref' => $ctRef, 't1_ref' => $t1Ref, 'portfoyno' => $ck['PORTFOYNO'], 'cekno' => $ck['NEWSERINO'], 'tutar' => (float) $ck['AMOUNT']];
            }

            // [3] CLFLINE (M6/T63/SIGN=0, hedef cari)
            $clRef = cek_klonla($dbh, "{$fd}CLFLINE", $clSablon, ['TRANNO' => $rollNo, 'GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}CLFLINE SET
                CLIENTREF=:cari, CLACCREF=0, CLCENTERREF=0, CASHCENTERREF=0, CASHACCOUNTREF=0, VIRMANREF=0,
                SOURCEFREF=:cr, DATE_={$bugun}, DEPARTMENT=0, BRANCH=0, MODULENR=6, TRCODE=63, LINENR=0,
                SPECODE='', CYPHCODE='', TRANNO=:tno, DOCODE='', LINEEXP=:exp, ACCOUNTED=0, SIGN=0,
                AMOUNT=:tut, TRCURR=0, TRRATE=1, TRNET=:tut2, REPORTRATE=1, REPORTNET=:tut3,
                PAYDEFREF=0, ACCFICHEREF=0, PRINTCNT=0, CANCELLED=0, AFFECTRISK=1, AFFECTCOLLATRL=0,
                MONTH_=MONTH(GETDATE()), YEAR_=YEAR(GETDATE()), FTIME=:ts, DOCDATE=NULL, SALESMANREF=0, STATUS=0,
                CHEQINFO='', CREDITCNO='', PAIDINCASH=0, CASHAMOUNT=0, DISCFLAG=0, DISCRATE=0, VATRATE=0, VATAMOUNT=0, DEVIR=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ss,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0
                WHERE LOGICALREF=:ref")->execute([
                ':cari' => $hedefCariRef, ':cr' => $crRef, ':tno' => $rollNo, ':exp' => $aciklama,
                ':tut' => $toplamS, ':tut2' => $toplamS, ':tut3' => $toplamS, ':ts' => $ts,
                ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $clRef,
            ]);

            // [4] Log
            $ilk = $kayitlar[0];
            $dbh->prepare("INSERT INTO M_CEK_LOG
                (REQUEST_ID, FIRMADONEM, DURUM, TUR, CSCARD_REF, CSCARD_PORTFOYNO, CSROLL_REF, CSROLL_ROLLNO, CSTRANS_REF, CLFLINE_REF, CLFLINE_TRANNO,
                 CLIENTREF, TUTAR, DOCCNT, KALEMLER, CEKNO, SAHIBI, ACIKLAMA, KULLANICI, IP)
                VALUES (:req, :fd, 'AKTIF', 'ciro', :cc, :pno, :cr, :roll, :ct, :cl, :tno,
                        :cari, :tut, :adet, :kalemler, :cekno, :sahibi, :exp, :kul, :ip)")
                ->execute([
                    ':req' => $requestId, ':fd' => $fd, ':cc' => $ilk['cscard_ref'], ':pno' => $ilk['portfoyno'], ':cr' => $crRef, ':roll' => $rollNo,
                    ':ct' => $ilk['cstrans_ref'], ':cl' => $clRef, ':tno' => $rollNo,
                    ':cari' => $hedefCariRef, ':tut' => $toplamS, ':adet' => $adet, ':kalemler' => json_encode($kayitlar, JSON_UNESCAPED_UNICODE),
                    ':cekno' => $ilk['cekno'], ':sahibi' => $hedefAd, ':exp' => $aciklama, ':kul' => $personel, ':ip' => $ip,
                ]);
            $logId = (int) $dbh->lastInsertId();
            if ($logId <= 0) { $logId = (int) $dbh->query("SELECT MAX(ID) FROM M_CEK_LOG WHERE REQUEST_ID = " . $dbh->quote($requestId))->fetchColumn(); }

            $dbh->commit();
            return ['ok' => true, 'mesaj' => $adet . ' çek ' . mb_substr($hedefAd, 0, 40) . '\'e ciro edildi — toplam ' . cek_say($toplam) . ' TL (bordro ' . ltrim($rollNo, '0') . ').',
                    'csroll_ref' => $crRef, 'clfline_ref' => $clRef, 'rollno' => $rollNo, 'adet' => $adet, 'tutar' => $toplam, 'kayitlar' => $kayitlar, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) { $dbh->rollBack(); }
            error_log('cek_ciro_core: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Ciro yapılamadı, hiçbir kayıt oluşmadı.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('cek_ciro_geri_al')) {
    /**
     * Bir ciro'yu M_CEK_LOG kaydından tam geri alır. Tek transaction.
     * SİL: CLFLINE(T63) + N CSTRANS(T3) + CSROLL(T3). GERİ YÜKLE: CSCARD.CURRSTAT 2→1 + giriş CSTRANS(T1).STATNO 2→1.
     * GUARD: ciro T3 çekin SON hareketi değilse (sonrasında tahsil/başka çıkış) reddet.
     * @return array{ok:bool, mesaj:string, kod?:int}
     */
    function cek_ciro_geri_al(PDO $dbh, string $fd, int $logId, int $personel, string $not = ''): array
    {
        try {
            $dbh->beginTransaction();
            $lg = $dbh->prepare("SELECT * FROM M_CEK_LOG WHERE ID = :id");
            $lg->execute([':id' => $logId]);
            $log = $lg->fetch(PDO::FETCH_ASSOC);
            if (!$log) { $dbh->rollBack(); return ['ok' => false, 'kod' => 404, 'mesaj' => 'Ciro kaydı bulunamadı.']; }
            if ((string) $log['DURUM'] !== 'AKTIF') { $dbh->rollBack(); return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bu ciro zaten geri alınmış.']; }
            if ((string) $log['TUR'] !== 'ciro') { $dbh->rollBack(); return ['ok' => false, 'kod' => 422, 'mesaj' => 'Bu kayıt bir ciro değil.']; }

            $crRef = (int) $log['CSROLL_REF']; $clRef = (int) $log['CLFLINE_REF'];
            $kalemler = [];
            if (!empty($log['KALEMLER'])) {
                $j = json_decode((string) $log['KALEMLER'], true);
                if (is_array($j)) { foreach ($j as $k) { $kalemler[] = ['cc' => (int) ($k['cscard_ref'] ?? 0), 'ct' => (int) ($k['cstrans_ref'] ?? 0), 't1' => (int) ($k['t1_ref'] ?? 0)]; } }
            }
            if (!$kalemler) { $dbh->rollBack(); return ['ok' => false, 'kod' => 500, 'mesaj' => 'Ciro kalemleri okunamadı.']; }

            // GUARD: her çekin ciro T3'ü SON hareketi olmalı (sonrasında hareket varsa reddet)
            foreach ($kalemler as $k) {
                if ($k['cc'] <= 0 || $k['ct'] <= 0) { continue; }
                $sonraki = (int) $dbh->query("SELECT COUNT(*) FROM {$fd}CSTRANS WHERE CSREF={$k['cc']} AND LOGICALREF>{$k['ct']}")->fetchColumn();
                if ($sonraki > 0) { $dbh->rollBack(); return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bordrodaki bir çek ciro sonrası işlem görmüş (tahsil/başka çıkış); geri alınamaz. Önce LOGO\'da o hareketi iptal edin.']; }
            }

            // SİL: CLFLINE(T63) → N CSTRANS(T3) → CSROLL(T3)
            if ($clRef > 0) { $dbh->prepare("DELETE FROM {$fd}CLFLINE WHERE LOGICALREF=:r AND MODULENR=6 AND TRCODE=63 AND SOURCEFREF=:cr")->execute([':r' => $clRef, ':cr' => $crRef]); }
            $delCt = $dbh->prepare("DELETE FROM {$fd}CSTRANS WHERE LOGICALREF=:r AND TRCODE=3");
            foreach ($kalemler as $k) { if ($k['ct'] > 0) { $delCt->execute([':r' => $k['ct']]); } }
            if ($crRef > 0) { $dbh->prepare("DELETE FROM {$fd}CSROLL WHERE LOGICALREF=:r AND TRCODE=3")->execute([':r' => $crRef]); }

            // GERİ YÜKLE: CSCARD.CURRSTAT 2→1 + giriş CSTRANS(T1).STATNO 2→1
            $ccRes = $dbh->prepare("UPDATE {$fd}CSCARD SET CURRSTAT=1 WHERE LOGICALREF=:r AND CURRSTAT=2 AND DOC=1");
            $t1Res = $dbh->prepare("UPDATE {$fd}CSTRANS SET STATNO=1 WHERE LOGICALREF=:r AND TRCODE=1");
            foreach ($kalemler as $k) {
                if ($k['cc'] > 0) { $ccRes->execute([':r' => $k['cc']]); }
                if ($k['t1'] > 0) { $t1Res->execute([':r' => $k['t1']]); }
            }

            $dbh->prepare("UPDATE M_CEK_LOG SET DURUM='GERIALINDI', GERIALAN_KULLANICI=:k, GERIALMA=GETDATE(), GERIALMA_NOT=:n WHERE ID=:id AND DURUM='AKTIF'")
                ->execute([':k' => $personel, ':n' => mb_substr($not, 0, 240), ':id' => $logId]);
            $dbh->commit();
            return ['ok' => true, 'mesaj' => 'Ciro geri alındı; çekler portföye döndü ve cari bakiye eski haline geldi.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) { $dbh->rollBack(); }
            error_log('cek_ciro_geri_al: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Geri alma başarısız; ciro olduğu gibi duruyor.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('cek_kendi_bankalar')) {
    /** Kendi çekimiz için seçilebilir banka hesapları (LG_XXX_BNCARD, aktif). @return array<int,array{ref:int,kod:string,ad:string}> */
    function cek_kendi_bankalar(PDO $dbh, string $firma): array
    {
        $out = [];
        try {
            foreach ($dbh->query("SELECT LOGICALREF, CODE, DEFINITION_ FROM {$firma}BNCARD WHERE ACTIVE=0 ORDER BY LOGICALREF")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['ref' => (int) $r['LOGICALREF'], 'kod' => (string) $r['CODE'], 'ad' => (string) $r['DEFINITION_']];
            }
        } catch (Throwable $e) { error_log('cek_kendi_bankalar: ' . $e->getMessage()); }
        return $out;
    }
}

if (!function_exists('cek_kendi_core')) {
    /**
     * Kendi çekimizi (DOC=3) düzenleyip bir hedef cariye VERİR (çek çıkışı). Tek transaction.
     * N yeni CSCARD(DOC=3, OURBANKREF=banka, CURRSTAT=9) + 1 CSROLL(T3) + N CSTRANS(T3, STATUS=9) + 1 CLFLINE(M6/T63/SIGN=0 → cariye borcumuz azalır).
     * DOC=3 portföy no AYRI seri (DOC,PORTFOYNO bileşik unique). Bordro no (ROLLNO) TRCODE=3 sayacı (ciro ile ortak).
     * @param array $kalemler [ ['tutar'=>float,'vade'=>string(YYYY-MM-DD),'cekno'=>string], ... ]
     * @return array
     */
    function cek_kendi_core(PDO $dbh, string $firma, string $fd, int $hedefCariRef, int $bankaRef, array $kalemler, string $aciklama, int $personel, string $requestId, array $opt = []): array
    {
        global $kayitsaat, $saat, $dakika, $saniye;
        $ip = (string) ($opt['ip'] ?? '');
        $ts = (int) $kayitsaat; $hh = (int) $saat; $mm = (int) $dakika; $ss = (int) $saniye;
        $aciklama = mb_substr(trim($aciklama), 0, 240);

        $kl = [];
        foreach ($kalemler as $x) {
            $t = round((float) ($x['tutar'] ?? 0), 2);
            $v = trim((string) ($x['vade'] ?? ''));
            if ($t <= 0) { return ['ok' => false, 'kod' => 422, 'mesaj' => 'Her çekin tutarı sıfırdan büyük olmalı.']; }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) { return ['ok' => false, 'kod' => 422, 'mesaj' => 'Her çek için geçerli vade tarihi girin.']; }
            $kl[] = ['tutar' => $t, 'vade' => $v, 'cekno' => mb_substr(trim((string) ($x['cekno'] ?? '')), 0, 60)];
        }
        $adet = count($kl);
        if ($hedefCariRef <= 0)     { return ['ok' => false, 'kod' => 400, 'mesaj' => 'Hedef cari seçilmedi.']; }
        if ($bankaRef <= 0)         { return ['ok' => false, 'kod' => 400, 'mesaj' => 'Banka seçilmedi.']; }
        if ($adet < 1 || $adet > 50){ return ['ok' => false, 'kod' => 422, 'mesaj' => 'En az 1, en fazla 50 çek girilebilir.']; }
        $toplam = round(array_sum(array_column($kl, 'tutar')), 2);
        $toplamS = cek_say($toplam);
        $bugunStr = date('Y-m-d');
        $agSum = 0.0;
        foreach ($kl as $k) { $agSum += $k['tutar'] * (int) round((strtotime($k['vade']) - strtotime($bugunStr)) / 86400); }
        $ortGun = $toplam > 0 ? (int) round($agSum / $toplam) : 0;

        cek_log_tablo_olustur($dbh);
        $bugun = "DATEADD(day,0,datediff(day,0,GETDATE()))";
        try {
            $dbh->beginTransaction();

            $c = $dbh->prepare("SELECT LOGICALREF, DEFINITION_ FROM {$firma}CLCARD WHERE LOGICALREF=:r AND ACTIVE=0");
            $c->execute([':r' => $hedefCariRef]);
            $cRow = $c->fetch(PDO::FETCH_ASSOC);
            if (!$cRow) { $dbh->rollBack(); return ['ok' => false, 'kod' => 404, 'mesaj' => 'Hedef cari bulunamadı/pasif.']; }
            $hedefAd = mb_substr((string) $cRow['DEFINITION_'], 0, 200);

            $b = $dbh->prepare("SELECT LOGICALREF, DEFINITION_ FROM {$firma}BNCARD WHERE LOGICALREF=:r");
            $b->execute([':r' => $bankaRef]);
            $bRow = $b->fetch(PDO::FETCH_ASSOC);
            if (!$bRow) { $dbh->rollBack(); return ['ok' => false, 'kod' => 404, 'mesaj' => 'Banka hesabı bulunamadı.']; }
            $bankaAd = mb_substr((string) $bRow['DEFINITION_'], 0, 120);

            $ccSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSCARD  WHERE DOC=3 AND (TRCURR=0 OR TRCURR IS NULL) AND AMOUNT>0 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            $crSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSROLL  WHERE TRCODE=3 AND (TRCURR=0 OR TRCURR IS NULL) AND TOTAL>0 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            $ctSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CSTRANS WHERE TRCODE=3 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            $clSablon = (int) $dbh->query("SELECT TOP 1 LOGICALREF FROM {$fd}CLFLINE WHERE MODULENR=6 AND TRCODE=63 AND SIGN=0 AND (TRCURR=0 OR TRCURR IS NULL) AND AMOUNT>0 AND CANCELLED=0 ORDER BY LOGICALREF DESC")->fetchColumn();
            if ($ccSablon <= 0 || $crSablon <= 0 || $ctSablon <= 0 || $clSablon <= 0) { throw new RuntimeException('Kendi çek şablonu (CSCARD DOC=3 / CSROLL / CSTRANS / CLFLINE) bulunamadı.'); }

            $rollNo = cek_seri_no($dbh, "{$fd}CSROLL", 'ROLLNO', 7, 'TRCODE=3', 'A');   // 'A'+7=8 char (varchar(9)); LOGO sayısal serisiyle çakışmaz
            $pfBase = (int) $dbh->query("SELECT MAX(CAST(PORTFOYNO AS BIGINT)) FROM {$fd}CSCARD WHERE DOC=3 AND PORTFOYNO NOT LIKE '%[^0-9]%' AND PORTFOYNO <> ''")->fetchColumn();

            // [1] CSROLL (çıkış bordrosu)
            $crRef = cek_klonla($dbh, "{$fd}CSROLL", $crSablon, ['ROLLNO' => $rollNo, 'GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}CSROLL SET
                CARDREF=:cari, CENTERREF=0, ROLLNO=:roll, DATE_={$bugun}, TRCODE=3, BRANCH=0, DEPARTMENT=0, DESTBRANCH=0, DESTDEPARTMENT=0,
                CARDMD=5, PROCTYPE=0, ONEPAYLINE=0, FROMCASH=0, FROMBANK=0, ACCOUNTED=0, AVERAGEAGE=:ort, DOCCNT=:adet, PRINTCNT=0,
                TOTAL=:tut, TRCURR=0, TRRATE=1, TRNET=:tut2, REPORTRATE=1, REPORTNET=:tut3,
                GENEXP1='', GENEXP2='', GENEXP3='', GENEXP4='', GENEXP5='', GENEXP6='',
                ACCFICHEREF=0, CASHTRANSREF=0, ACCREF=0, CANCELLED=0, CANCELLEDACC=0,
                AFFECTCOLLATRL=0, COLLATROLLREF=0, AFFECTRISK=1, SALESMANREF=0, APPROVE=0, APPROVEDATE=NULL,
                STATUS=0, DOCDATE=NULL, PRINTDATE=NULL, SPECODE='', CYPHCODE='', DOCODE='', WFSTATUS=0, OPSTAT=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ss,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0
                WHERE LOGICALREF=:ref")->execute([
                ':cari' => $hedefCariRef, ':roll' => $rollNo, ':ort' => $ortGun, ':adet' => $adet,
                ':tut' => $toplamS, ':tut2' => $toplamS, ':tut3' => $toplamS,
                ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $crRef,
            ]);

            // [2] her çek: yeni CSCARD(DOC=3) + CSTRANS(T3)
            $ccUpd = $dbh->prepare("UPDATE {$fd}CSCARD SET
                DOC=3, CURRSTAT=9, OURBANKREF=:banka, SERINO='', NEWSERINO=:cekno, BANKNAME=:bankaad, OWING='Lumen',
                BNBRANCHNO='', BNACCOUNTNO='', IBAN='', TAXNR='', SPECODE='', CYPHCODE='', CITY='', KEFIL='', KEFIL2='', MUHABIR='',
                DUEDATE=:vade, SETDATE={$bugun}, STAMP=0,
                AMOUNT=:tut, TRNET=:tut2, REPORTNET=:tut3, TRCURR=0, TRRATE=1, REPORTRATE=1,
                CIRO=0, GIROAMOUNT=0, GIROREPNET=0, COLLATROLLREF=0, COLLATCARDREF=0,
                RISKUPDATE=0, DEVIR=0, INUSE=1, PRINTCNT=0, PRINTDATE=NULL, SALESMANREF=0, STATUS=0, CANCELLED=0, OPSTAT=0, WFSTATUS=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ss,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0
                WHERE LOGICALREF=:ref");
            $ctUpd = $dbh->prepare("UPDATE {$fd}CSTRANS SET
                DATE_={$bugun}, CSREF=:cc, ROLLREF=:cr, TRCODE=3, ACCOUNTED=0, DEVIR=0, STATUS=9,
                CARDMD=5, CARDREF=:cari, STATNO=1, LINENO_=:ln, ACCREF=0, COSTREF=0, CRSACCREF=0, CRSCOSTREF=0,
                FROMCASH=0, CANCELLED=0, AFFECTCOLLATRL=0, AFFECTRISK=1, USEGIRORATE=0, FROMBANK=0,
                CLACCREF=0, CLCOSTREF=0, OPSTAT=0
                WHERE LOGICALREF=:ref");
            $kayitlar = []; $ln = 0;
            foreach ($kl as $k) {
                $ln++;
                $pno = str_pad((string) ($pfBase + $ln), 8, '0', STR_PAD_LEFT);
                $tS  = cek_say($k['tutar']);
                $ccRef = cek_klonla($dbh, "{$fd}CSCARD", $ccSablon, ['DOC' => '3', 'PORTFOYNO' => $pno, 'GUID' => (string) guid()]);
                $ccUpd->execute([
                    ':banka' => $bankaRef, ':cekno' => $k['cekno'], ':bankaad' => $bankaAd, ':vade' => $k['vade'],
                    ':tut' => $tS, ':tut2' => $tS, ':tut3' => $tS,
                    ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $ccRef,
                ]);
                $ctRef = cek_klonla($dbh, "{$fd}CSTRANS", $ctSablon, ['GUID' => (string) guid()]);
                $ctUpd->execute([':cc' => $ccRef, ':cr' => $crRef, ':cari' => $hedefCariRef, ':ln' => $ln, ':ref' => $ctRef]);
                $kayitlar[] = ['cscard_ref' => $ccRef, 'cstrans_ref' => $ctRef, 'portfoyno' => $pno, 'cekno' => $k['cekno'], 'tutar' => $k['tutar'], 'vade' => $k['vade']];
            }

            // [3] CLFLINE (M6/T63/SIGN=0, hedef cari)
            $clRef = cek_klonla($dbh, "{$fd}CLFLINE", $clSablon, ['TRANNO' => $rollNo, 'GUID' => (string) guid()]);
            $dbh->prepare("UPDATE {$fd}CLFLINE SET
                CLIENTREF=:cari, CLACCREF=0, CLCENTERREF=0, CASHCENTERREF=0, CASHACCOUNTREF=0, VIRMANREF=0,
                SOURCEFREF=:cr, DATE_={$bugun}, DEPARTMENT=0, BRANCH=0, MODULENR=6, TRCODE=63, LINENR=0,
                SPECODE='', CYPHCODE='', TRANNO=:tno, DOCODE='', LINEEXP=:exp, ACCOUNTED=0, SIGN=0,
                AMOUNT=:tut, TRCURR=0, TRRATE=1, TRNET=:tut2, REPORTRATE=1, REPORTNET=:tut3,
                PAYDEFREF=0, ACCFICHEREF=0, PRINTCNT=0, CANCELLED=0, AFFECTRISK=1, AFFECTCOLLATRL=0,
                MONTH_=MONTH(GETDATE()), YEAR_=YEAR(GETDATE()), FTIME=:ts, DOCDATE=NULL, SALESMANREF=0, STATUS=0,
                CHEQINFO='', CREDITCNO='', PAIDINCASH=0, CASHAMOUNT=0, DISCFLAG=0, DISCRATE=0, VATRATE=0, VATAMOUNT=0, DEVIR=0,
                CAPIBLOCK_CREATEDBY=:psr, CAPIBLOCK_CREADEDDATE=GETDATE(), CAPIBLOCK_CREATEDHOUR=:hh, CAPIBLOCK_CREATEDMIN=:mm, CAPIBLOCK_CREATEDSEC=:ss,
                CAPIBLOCK_MODIFIEDBY=0, CAPIBLOCK_MODIFIEDDATE=NULL, CAPIBLOCK_MODIFIEDHOUR=0, CAPIBLOCK_MODIFIEDMIN=0, CAPIBLOCK_MODIFIEDSEC=0
                WHERE LOGICALREF=:ref")->execute([
                ':cari' => $hedefCariRef, ':cr' => $crRef, ':tno' => $rollNo, ':exp' => $aciklama,
                ':tut' => $toplamS, ':tut2' => $toplamS, ':tut3' => $toplamS, ':ts' => $ts,
                ':psr' => $personel, ':hh' => $hh, ':mm' => $mm, ':ss' => $ss, ':ref' => $clRef,
            ]);

            // [4] Log
            $ilk = $kayitlar[0];
            $dbh->prepare("INSERT INTO M_CEK_LOG
                (REQUEST_ID, FIRMADONEM, DURUM, TUR, CSCARD_REF, CSCARD_PORTFOYNO, CSROLL_REF, CSROLL_ROLLNO, CSTRANS_REF, CLFLINE_REF, CLFLINE_TRANNO,
                 CLIENTREF, TUTAR, DOCCNT, KALEMLER, CEKNO, BANKNAME, VADE, SAHIBI, ACIKLAMA, KULLANICI, IP)
                VALUES (:req, :fd, 'AKTIF', 'kendi_cek', :cc, :pno, :cr, :roll, :ct, :cl, :tno,
                        :cari, :tut, :adet, :kalemler, :cekno, :banka, :vade, :sahibi, :exp, :kul, :ip)")
                ->execute([
                    ':req' => $requestId, ':fd' => $fd, ':cc' => $ilk['cscard_ref'], ':pno' => $ilk['portfoyno'], ':cr' => $crRef, ':roll' => $rollNo,
                    ':ct' => $ilk['cstrans_ref'], ':cl' => $clRef, ':tno' => $rollNo,
                    ':cari' => $hedefCariRef, ':tut' => $toplamS, ':adet' => $adet, ':kalemler' => json_encode($kayitlar, JSON_UNESCAPED_UNICODE),
                    ':cekno' => $ilk['cekno'], ':banka' => $bankaAd, ':vade' => $ilk['vade'], ':sahibi' => $hedefAd, ':exp' => $aciklama, ':kul' => $personel, ':ip' => $ip,
                ]);
            $logId = (int) $dbh->lastInsertId();
            if ($logId <= 0) { $logId = (int) $dbh->query("SELECT MAX(ID) FROM M_CEK_LOG WHERE REQUEST_ID = " . $dbh->quote($requestId))->fetchColumn(); }

            $dbh->commit();
            return ['ok' => true, 'mesaj' => $adet . ' kendi çekimiz ' . mb_substr($hedefAd, 0, 40) . '\'e verildi — toplam ' . cek_say($toplam) . ' TL (bordro ' . ltrim($rollNo, '0') . ').',
                    'csroll_ref' => $crRef, 'clfline_ref' => $clRef, 'rollno' => $rollNo, 'adet' => $adet, 'tutar' => $toplam, 'kayitlar' => $kayitlar, 'log_id' => $logId];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) { $dbh->rollBack(); }
            error_log('cek_kendi_core: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Kendi çek verilemedi, hiçbir kayıt oluşmadı.', 'hata' => $e->getMessage()];
        }
    }
}

if (!function_exists('cek_kendi_geri_al')) {
    /**
     * Bir kendi-çek verme işlemini geri alır. CLFLINE(T63)+N CSTRANS(T3)+CSROLL(T3)+N CSCARD(DOC=3) SİLİNİR.
     * (Ciro'dan farkı: CSCARD bu işlemde oluştuğu için SİLİNİR.) Guard: çek çıkış sonrası işlem görmüşse reddet.
     * @return array{ok:bool, mesaj:string, kod?:int}
     */
    function cek_kendi_geri_al(PDO $dbh, string $fd, int $logId, int $personel, string $not = ''): array
    {
        try {
            $dbh->beginTransaction();
            $lg = $dbh->prepare("SELECT * FROM M_CEK_LOG WHERE ID = :id");
            $lg->execute([':id' => $logId]);
            $log = $lg->fetch(PDO::FETCH_ASSOC);
            if (!$log) { $dbh->rollBack(); return ['ok' => false, 'kod' => 404, 'mesaj' => 'Kayıt bulunamadı.']; }
            if ((string) $log['DURUM'] !== 'AKTIF') { $dbh->rollBack(); return ['ok' => false, 'kod' => 409, 'mesaj' => 'Bu işlem zaten geri alınmış.']; }
            if ((string) $log['TUR'] !== 'kendi_cek') { $dbh->rollBack(); return ['ok' => false, 'kod' => 422, 'mesaj' => 'Bu kayıt kendi çekimiz değil.']; }

            $crRef = (int) $log['CSROLL_REF']; $clRef = (int) $log['CLFLINE_REF'];
            $kalemler = [];
            if (!empty($log['KALEMLER'])) {
                $j = json_decode((string) $log['KALEMLER'], true);
                if (is_array($j)) { foreach ($j as $k) { $kalemler[] = ['cc' => (int) ($k['cscard_ref'] ?? 0), 'ct' => (int) ($k['cstrans_ref'] ?? 0)]; } }
            }
            if (!$kalemler) { $dbh->rollBack(); return ['ok' => false, 'kod' => 500, 'mesaj' => 'Kalemler okunamadı.']; }

            // GUARD: her çekin verme (T3) hareketi SON hareketi olmalı (sonrasında ödeme/tahsil görmüşse reddet)
            foreach ($kalemler as $k) {
                if ($k['cc'] <= 0 || $k['ct'] <= 0) { continue; }
                $sonraki = (int) $dbh->query("SELECT COUNT(*) FROM {$fd}CSTRANS WHERE CSREF={$k['cc']} AND LOGICALREF>{$k['ct']}")->fetchColumn();
                if ($sonraki > 0) { $dbh->rollBack(); return ['ok' => false, 'kod' => 409, 'mesaj' => 'Çek verildikten sonra işlem görmüş (ödeme/tahsil); geri alınamaz. Önce LOGO\'da o hareketi iptal edin.']; }
            }

            // SİL: CLFLINE(T63) → N CSTRANS(T3) → CSROLL(T3) → N CSCARD(DOC=3)
            if ($clRef > 0) { $dbh->prepare("DELETE FROM {$fd}CLFLINE WHERE LOGICALREF=:r AND MODULENR=6 AND TRCODE=63 AND SOURCEFREF=:cr")->execute([':r' => $clRef, ':cr' => $crRef]); }
            $delCt = $dbh->prepare("DELETE FROM {$fd}CSTRANS WHERE LOGICALREF=:r AND TRCODE=3");
            foreach ($kalemler as $k) { if ($k['ct'] > 0) { $delCt->execute([':r' => $k['ct']]); } }
            if ($crRef > 0) { $dbh->prepare("DELETE FROM {$fd}CSROLL WHERE LOGICALREF=:r AND TRCODE=3")->execute([':r' => $crRef]); }
            $delCc = $dbh->prepare("DELETE FROM {$fd}CSCARD WHERE LOGICALREF=:r AND DOC=3");
            foreach ($kalemler as $k) { if ($k['cc'] > 0) { $delCc->execute([':r' => $k['cc']]); } }

            $dbh->prepare("UPDATE M_CEK_LOG SET DURUM='GERIALINDI', GERIALAN_KULLANICI=:k, GERIALMA=GETDATE(), GERIALMA_NOT=:n WHERE ID=:id AND DURUM='AKTIF'")
                ->execute([':k' => $personel, ':n' => mb_substr($not, 0, 240), ':id' => $logId]);
            $dbh->commit();
            return ['ok' => true, 'mesaj' => 'Kendi çek verme geri alındı; çekler silindi ve cari bakiye eski haline geldi.'];
        } catch (Throwable $e) {
            if ($dbh->inTransaction()) { $dbh->rollBack(); }
            error_log('cek_kendi_geri_al: ' . $e->getMessage());
            return ['ok' => false, 'kod' => 500, 'mesaj' => 'Geri alma başarısız; işlem olduğu gibi duruyor.', 'hata' => $e->getMessage()];
        }
    }
}

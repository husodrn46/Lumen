-- LOCAL MIGRATION ONLY. No database was changed by preparing this file.
-- DBA review/approval required before application. No DROP, expiry or data deletion.
IF OBJECT_ID('dbo.M_API_IDEMPOTENCY', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.M_API_IDEMPOTENCY (
        KEYHASH CHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
        FIRMA INT NOT NULL,
        DONEM VARCHAR(10) NOT NULL,
        PERSONEL INT NOT NULL,
        KAPSAM VARCHAR(64) NOT NULL,
        BODYHASH CHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
        YANIT NVARCHAR(MAX) NOT NULL,
        OLUSTURMA DATETIME NOT NULL DEFAULT GETDATE(),
        CONSTRAINT PK_M_API_IDEMPOTENCY PRIMARY KEY (KEYHASH),
        CONSTRAINT CK_M_API_IDEMPOTENCY_FIRMA CHECK (FIRMA BETWEEN 1 AND 999),
        CONSTRAINT CK_M_API_IDEMPOTENCY_PERSONEL CHECK (PERSONEL > 0)
    );
END
GO
IF ISNULL(COL_LENGTH('dbo.M_API_IDEMPOTENCY','KEYHASH'),-1) <> 64
 OR COL_LENGTH('dbo.M_API_IDEMPOTENCY','FIRMA') IS NULL
 OR ISNULL(COL_LENGTH('dbo.M_API_IDEMPOTENCY','DONEM'),-1) <> 10
 OR COL_LENGTH('dbo.M_API_IDEMPOTENCY','PERSONEL') IS NULL
 OR ISNULL(COL_LENGTH('dbo.M_API_IDEMPOTENCY','KAPSAM'),-1) <> 64
 OR ISNULL(COL_LENGTH('dbo.M_API_IDEMPOTENCY','BODYHASH'),-1) <> 64
 OR COL_LENGTH('dbo.M_API_IDEMPOTENCY','YANIT') IS NULL
 OR COL_LENGTH('dbo.M_API_IDEMPOTENCY','OLUSTURMA') IS NULL
    THROW 51000, 'M_API_IDEMPOTENCY schema incompatible; preserve data and review migration.', 1;
GO
IF NOT EXISTS (
    SELECT 1 FROM sys.key_constraints K
    INNER JOIN sys.index_columns I ON I.object_id=K.parent_object_id AND I.index_id=K.unique_index_id
    WHERE K.parent_object_id=OBJECT_ID('dbo.M_API_IDEMPOTENCY') AND K.type='PK'
      AND I.key_ordinal=1 AND COL_NAME(I.object_id,I.column_id)='KEYHASH'
      AND NOT EXISTS (SELECT 1 FROM sys.index_columns X WHERE X.object_id=I.object_id AND X.index_id=I.index_id AND X.key_ordinal>1)
)
    THROW 51000, 'M_API_IDEMPOTENCY requires sole KEYHASH primary key; no automatic repair.', 1;
GO

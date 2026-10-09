-- DRAFT ONLY: separate database, privileged reviewed migration account; never runtime DDL.
IF COALESCE(CAST(SESSION_CONTEXT(N'LumenFavoriteTransferMigration') AS NVARCHAR(100)), N'') <> N'reviewed-separate-db'
    THROW 51000, 'Explicit reviewed transfer migration context required.', 1;
IF DB_NAME() IN ('master','model','msdb','tempdb')
    THROW 51001, 'System database rejected.', 1;
IF OBJECT_ID(N'dbo.LUMEN_ACTOR_LINK', N'U') IS NULL OR OBJECT_ID(N'dbo.LUMEN_FAVORITES', N'U') IS NULL
    THROW 51002, 'Preference schema required.', 1;
SET XACT_ABORT ON;
BEGIN TRY
BEGIN TRANSACTION;
CREATE TABLE dbo.LUMEN_FAVORITE_TRANSFER (
    CONNECTION_KEY VARCHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
    FIRMA SMALLINT NOT NULL CHECK (FIRMA BETWEEN 1 AND 999),
    REQUEST_KEY VARCHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
    FINGERPRINT CHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
    INSERTED_COUNT INT NOT NULL CHECK (INSERTED_COUNT >= 0),
    UNCHANGED_COUNT INT NOT NULL CHECK (UNCHANGED_COUNT >= 0),
    CONSTRAINT PK_LUMEN_FAVORITE_TRANSFER PRIMARY KEY (CONNECTION_KEY,FIRMA,REQUEST_KEY)
);
COMMIT;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK;
    THROW;
END CATCH;
-- Privileged transfer operator: SELECT, INSERT on ledger; SELECT on links;
-- SELECT, INSERT on favorites. Existing favorite runtime is NOT automatically granted this ability.

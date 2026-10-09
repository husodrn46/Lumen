<?php
declare(strict_types=1);
require_once __DIR__ . '/lumen_connection.php';

function lumen_favorites_enabled(): bool
{
    $flag = getenv('LUMEN_FAVORITES_ENABLED');
    if ($flag === false || $flag === '' || $flag === '0') { return false; }
    if ($flag === '1') { return true; }
    throw new RuntimeException('Invalid Lumen preference pilot flag.');
}

/** Server-authenticated scope only. No CODE fallback or POST identity. */
function lumen_favorites_scope(array $session, mixed $personel, mixed $role, mixed $firmaNo, mixed $firmaPrefix, mixed $connection): array
{
    foreach ([$personel, $session['plasiyer_id'] ?? null, $firmaNo] as $id) {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]{0,8}\z/', (string)$id)) {
            throw new RuntimeException('Authenticated preference scope unavailable.');
        }
    }
    if ((int)$session['plasiyer_id'] !== (int)$personel || !in_array($role, [0,1,2], true)
        || (int)$firmaNo > 999 || $firmaPrefix !== 'LG_' . str_pad((string)(int)$firmaNo, 3, '0', STR_PAD_LEFT) . '_'
        || !is_string($connection) || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/', $connection)) {
        throw new RuntimeException('Authenticated preference scope unavailable.');
    }
    return ['connection'=>$connection, 'firma'=>(int)$firmaNo, 'personel'=>(int)$personel];
}

function lumen_favorites_current_scope(): array
{
    global $terminalkullanici, $yetkidurum, $firmano, $firma;
    return lumen_favorites_scope($_SESSION ?? [], $terminalkullanici ?? null, $yetkidurum ?? null,
        $firmano ?? null, $firma ?? null, getenv('LUMEN_LOGO_CONNECTION_ID'));
}

/** Same CSV/normalization/40-card contract as the original favorite endpoint. */
function lumen_favorites_normalize(string $raw): string
{
    $keys = [];
    foreach (explode(',', $raw) as $key) {
        $key = strtolower(trim($key));
        if ($key === '' || !preg_match('~^[a-z0-9_./-]{1,80}$~', $key)) { continue; }
        if (!in_array($key, $keys, true)) { $keys[] = $key; }
        if (count($keys) >= 40) { break; }
    }
    return implode(',', $keys);
}

final class LumenFavoriteRepository
{
    public function __construct(private PDO $lumenDbh) {}

    private function actor(array $scope): string
    {
        // Revalidate the repository boundary even when used outside the HTTP adapter.
        $valid = lumen_favorites_scope(['plasiyer_id'=>$scope['personel'] ?? null], $scope['personel'] ?? null,
            1, $scope['firma'] ?? null, 'LG_' . str_pad((string)($scope['firma'] ?? ''),3,'0',STR_PAD_LEFT) . '_',
            $scope['connection'] ?? null);
        $stmt = $this->lumenDbh->prepare('SELECT ACTOR_ID FROM dbo.LUMEN_ACTOR_LINK
            WHERE CONNECTION_KEY = :connection AND FIRMA = :firma AND LOGO_PERSONEL = :personel');
        $stmt->bindValue(':connection', $valid['connection']);
        $stmt->bindValue(':firma', $valid['firma'], PDO::PARAM_INT);
        $stmt->bindValue(':personel', $valid['personel'], PDO::PARAM_INT);
        $stmt->execute(); $actor = $stmt->fetchColumn(); $stmt->closeCursor();
        if (!is_string($actor) || !preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/i', $actor)) {
            throw new RuntimeException('Lumen actor mapping unavailable.');
        }
        return $actor;
    }

    public function read(array $scope): string
    {
        $actor = $this->actor($scope);
        $stmt = $this->lumenDbh->prepare('SELECT FAVORITES FROM dbo.LUMEN_FAVORITES
            WHERE CONNECTION_KEY = :connection AND FIRMA = :firma AND ACTOR_ID = :actor');
        $stmt->execute([':connection'=>$scope['connection'], ':firma'=>$scope['firma'], ':actor'=>$actor]);
        $value = $stmt->fetchColumn(); $stmt->closeCursor();
        return $value === false ? '' : lumen_favorites_normalize((string)$value);
    }

    public function save(array $scope, string $value): void
    {
        if ($this->lumenDbh->inTransaction()) { throw new RuntimeException('Separate preference transaction required.'); }
        try {
            $this->lumenDbh->beginTransaction();
            $actor = $this->actor($scope);
            // Serializable key-range lock prevents simultaneous first writes duplicating a preference.
            $stmt = $this->lumenDbh->prepare('SELECT ACTOR_ID FROM dbo.LUMEN_FAVORITES WITH (UPDLOCK, HOLDLOCK)
                WHERE CONNECTION_KEY = :connection AND FIRMA = :firma AND ACTOR_ID = :actor');
            $params = [':connection'=>$scope['connection'], ':firma'=>$scope['firma'], ':actor'=>$actor];
            $stmt->execute($params); $exists = $stmt->fetchColumn() !== false; $stmt->closeCursor();
            $sql = $exists
                ? 'UPDATE dbo.LUMEN_FAVORITES SET FAVORITES = :value WHERE CONNECTION_KEY = :connection AND FIRMA = :firma AND ACTOR_ID = :actor'
                : 'INSERT INTO dbo.LUMEN_FAVORITES (CONNECTION_KEY, FIRMA, ACTOR_ID, FAVORITES) VALUES (:connection, :firma, :actor, :value)';
            $stmt = $this->lumenDbh->prepare($sql);
            $stmt->execute($params + [':value'=>lumen_favorites_normalize($value)]); $stmt->closeCursor();
            $this->lumenDbh->commit();
        } catch (Throwable $e) {
            try { if ($this->lumenDbh->inTransaction()) { $this->lumenDbh->rollBack(); } } catch (Throwable $ignored) {}
            throw new RuntimeException('Lumen preference save unavailable.');
        }
    }
}

function lumen_favorites_repository(): LumenFavoriteRepository
{
    static $repository = null;
    if ($repository === null) {
        $env = [];
        foreach (['LUMEN_DB_SERVER','LUMEN_DB_NAME','LUMEN_DB_USER','LUMEN_DB_PASS','AKL_DB_NAME','AKL_DB_USER'] as $key) {
            $value = getenv($key); if ($value !== false) { $env[$key] = $value; }
        }
        $repository = new LumenFavoriteRepository(LumenConnectionFactory::connect($env));
    }
    return $repository;
}

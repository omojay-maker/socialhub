<?php
/**
 * Social account persistence.
 *
 * Tokens are NEVER returned by all()/find() — the public column list is
 * explicit so a future column cannot leak by accident. Access and refresh
 * tokens are encrypted at rest with AES-256-GCM (see helpers::encrypt_secret).
 */
class SocialAccountModel {
    /** Columns safe to expose to the browser. */
    private const PUBLIC_COLUMNS = 'sa.id, sa.platform_id, sa.user_id, sa.account_name, sa.username,
        sa.account_type, sa.connection_status, sa.followers, sa.external_user_id, sa.external_account_id,
        sa.avatar_url, sa.last_synced_at, sa.last_error, sa.created_at, sa.updated_at,
        sp.slug, sp.name as platform_name, sp.color';

    public static function all(): array {
        $sql = 'SELECT ' . self::PUBLIC_COLUMNS . ' FROM social_accounts sa
                JOIN social_platforms sp ON sp.id = sa.platform_id ORDER BY sa.id';
        return db()->query($sql)->fetchAll();
    }

    public static function platforms(): array {
        return db()->query("SELECT * FROM social_platforms WHERE is_active=1 ORDER BY id")->fetchAll();
    }

    public static function find(int $id): ?array {
        $sql = 'SELECT ' . self::PUBLIC_COLUMNS . ', sa.meta FROM social_accounts sa
                JOIN social_platforms sp ON sp.id = sa.platform_id WHERE sa.id=?';
        $stmt = db()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) return null;
        $row['meta'] = self::decodeMeta($row['meta'] ?? null);
        return $row;
    }

    /** Full row including encrypted tokens. Internal use only. */
    public static function findRaw(int $id): ?array {
        $stmt = db()->prepare("SELECT sa.*, sp.slug, sp.name as platform_name
                               FROM social_accounts sa JOIN social_platforms sp ON sp.id=sa.platform_id
                               WHERE sa.id=?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function findByExternal(int $platformId, string $externalAccountId): ?array {
        $stmt = db()->prepare("SELECT id FROM social_accounts WHERE platform_id=? AND external_account_id=? LIMIT 1");
        $stmt->execute([$platformId, $externalAccountId]);
        $id = $stmt->fetchColumn();
        return $id ? self::find((int)$id) : null;
    }

    public static function findByPlatform(int $platformId): array {
        $sql = 'SELECT ' . self::PUBLIC_COLUMNS . ' FROM social_accounts sa
                JOIN social_platforms sp ON sp.id=sa.platform_id
                WHERE sa.platform_id=? AND sa.connection_status="connected" ORDER BY sa.id';
        $stmt = db()->prepare($sql);
        $stmt->execute([$platformId]);
        return $stmt->fetchAll();
    }

    /**
     * Insert or refresh a connected account.
     * $d expects: platform_id, external_account_id, account_name, username,
     *            account_type, external_user_id, avatar_url, followers, meta
     */
    public static function upsertConnected(array $d, int $userId): int {
        $external = (string)($d['external_account_id'] ?? '');
        $existing = $external !== '' ? self::findByExternal((int)$d['platform_id'], $external) : null;

        $payload = [
            (int)$d['platform_id'],
            $d['account_name'] ?? 'Unknown',
            $d['username'] ?? null,
            $d['account_type'] ?? 'page',
            $external !== '' ? $external : null,
            $d['external_user_id'] ?? null,
            $d['avatar_url'] ?? null,
            (int)($d['followers'] ?? 0),
            isset($d['meta']) ? json_encode($d['meta']) : null,
        ];

        if ($existing) {
            $stmt = db()->prepare("UPDATE social_accounts SET
                account_name=?, username=?, account_type=?, external_account_id=?, external_user_id=?,
                avatar_url=?, followers=?, meta=?, connection_status='connected', last_error=NULL, updated_at=NOW()
                WHERE id=?");
            $stmt->execute([
                $payload[1], $payload[2], $payload[3], $payload[4], $payload[5],
                $payload[6], $payload[7], $payload[8], $existing['id'],
            ]);
            return (int)$existing['id'];
        }

        $stmt = db()->prepare("INSERT INTO social_accounts
            (platform_id, user_id, account_name, username, account_type, external_account_id,
             external_user_id, avatar_url, followers, meta, connection_status)
            VALUES (?,?,?,?,?,?,?,?,?,'connected')");
        $stmt->execute([
            $payload[0], $userId, $payload[1], $payload[2], $payload[3],
            $payload[4], $payload[5], $payload[6], $payload[7], $payload[8],
        ]);
        return (int)db()->lastInsertId();
    }

    /** Legacy manual/mock connection. */
    public static function create(array $d): int {
        $stmt = db()->prepare("INSERT INTO social_accounts (platform_id,account_name,username,account_type,connection_status,followers,meta) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute([
            $d['platform_id'], $d['account_name'], $d['username'],
            $d['account_type'] ?? 'page', $d['connection_status'] ?? 'connected',
            $d['followers'] ?? 0, isset($d['meta']) ? json_encode($d['meta']) : null,
        ]);
        return (int)db()->lastInsertId();
    }

    /* ------------------------------------------------------------ tokens -- */

    /** Encrypts and stores OAuth credentials. */
    public static function storeToken(int $id, ?string $access, ?string $refresh = null, ?int $expiresIn = null, ?string $scopes = null): void {
        $sets = [];
        $args  = [];
        if ($access !== null && $access !== '') {
            $sets[] = 'access_token=?'; $args[] = encrypt_secret($access);
        }
        if ($refresh !== null && $refresh !== '') {
            $sets[] = 'refresh_token=?'; $args[] = encrypt_secret($refresh);
        }
        if ($expiresIn !== null) {
            $sets[] = 'token_expires_at=?'; $args[] = date('Y-m-d H:i:s', time() + $expiresIn);
        }
        if ($scopes !== null && $scopes !== '') {
            $sets[] = 'scopes=?'; $args[] = $scopes;
        }
        if (!$sets) return;
        $sets[] = 'updated_at=NOW()';
        $args[] = $id;
        db()->prepare('UPDATE social_accounts SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($args);
    }

    public static function accessToken(int $id): ?string {
        return self::readToken($id, 'access_token');
    }

    public static function refreshTokenValue(int $id): ?string {
        return self::readToken($id, 'refresh_token');
    }

    private static function readToken(int $id, string $column): ?string {
        $allowed = ['access_token', 'refresh_token'];
        if (!in_array($column, $allowed, true)) throw new InvalidArgumentException('bad column');
        $stmt = db()->prepare("SELECT $column FROM social_accounts WHERE id=?");
        $stmt->execute([$id]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : decrypt_secret((string)$value);
    }

    public static function tokenExpired(int $id): bool {
        $stmt = db()->prepare("SELECT token_expires_at FROM social_accounts WHERE id=?");
        $stmt->execute([$id]);
        $exp = $stmt->fetchColumn();
        return $exp ? strtotime((string)$exp) <= time() + 60 : false;
    }

    public static function setStatus(int $id, string $status, ?string $error = null): void {
        db()->prepare("UPDATE social_accounts SET connection_status=?, last_error=?, updated_at=NOW() WHERE id=?")
            ->execute([$status, $error, $id]);
    }

    public static function updateSync(int $id): void {
        db()->prepare("UPDATE social_accounts SET last_synced_at=NOW() WHERE id=?")->execute([$id]);
    }

    public static function updateStats(int $id, int $followers, ?array $meta = null): void {
        db()->prepare("UPDATE social_accounts SET followers=?, meta=COALESCE(?,meta), last_synced_at=NOW() WHERE id=?")
            ->execute([$followers, $meta ? json_encode($meta) : null, $id]);
    }

    public static function delete(int $id): void {
        db()->prepare("DELETE FROM social_accounts WHERE id=?")->execute([$id]);
    }

    private static function decodeMeta(?string $json): array {
        if (!$json) return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}

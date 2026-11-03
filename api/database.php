<?php
/**
 * DrawSpace – database connection.
 *
 * PostgreSQL is supported for Neon, with SQLite retained for local
 * development. MySQL remains supported for existing installations. The schema
 * is created automatically on first use; every query uses PDO.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $driver = strtolower((string) env('DB_DRIVER', 'sqlite'));

    if (!in_array($driver, ['postgres', 'pgsql', 'mysql', 'sqlite'], true)) {
        throw new RuntimeException('DB_DRIVER must be postgres, mysql or sqlite.');
    }

    if ($driver === 'sqlite') {
        if (env('VERCEL') !== null) {
            throw new RuntimeException(
                'SQLite is not supported on Vercel. Configure a managed database with DB_DRIVER=postgres.'
            );
        }

        $configuredPath = (string) env('DB_PATH', 'storage/drawspace.sqlite');
        $absolute = str_starts_with($configuredPath, '/')
            || str_starts_with($configuredPath, '\\')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $configuredPath) === 1;
        $path = $absolute
            ? $configuredPath
            : dirname(__DIR__) . DIRECTORY_SEPARATOR . $configuredPath;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the storage directory.');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');

        @chmod($path, 0600);
    } elseif ($driver === 'postgres' || $driver === 'pgsql') {
        $host = (string) env('DB_HOST', '127.0.0.1');
        $port = (int) env('DB_PORT', '5432');
        $name = (string) env('DB_NAME', 'drawspace');
        $user = (string) env('DB_USER', 'postgres');
        $pass = (string) env('DB_PASS', '');
        $sslMode = env_bool('DB_SSL', true) ? 'require' : 'prefer';
        $endpoint = (string) env('DB_ENDPOINT', '');

        // Neon pooler hosts need the endpoint option when the runtime's
        // libpq does not support SNI. Derive it from standard Neon hosts so
        // existing deployments only need the normal DB_* variables.
        if ($endpoint === '' && preg_match('/^(ep-[a-z0-9-]+?)(?:-pooler)?\./i', $host, $matches) === 1) {
            $endpoint = $matches[1];
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s%s',
            $host,
            $port,
            $name,
            $sslMode,
            $endpoint === '' ? '' : ';options=endpoint%3D' . rawurlencode($endpoint)
        );

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    } else {
        $host = (string) env('DB_HOST', '127.0.0.1');
        $port = (int) env('DB_PORT', '3306');
        $name = (string) env('DB_NAME', 'drawspace');
        $user = (string) env('DB_USER', 'root');
        $pass = (string) env('DB_PASS', '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        if (env_bool('DB_SSL', false)) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = env_bool('DB_SSL_VERIFY', true);

            if (($ca = env('DB_SSL_CA')) !== null) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
            }
        }

        $pdo = new PDO($dsn, $user, $pass, $options);
        $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    ensure_schema($pdo, $driver);
    maybe_gc($pdo);

    return $pdo;
}

function ensure_schema(PDO $pdo, string $driver): void
{
    try {
        $statement = $pdo->prepare('SELECT v FROM meta WHERE k = :k');
        $statement->execute([':k' => 'schema_version']);

        if ($statement->fetchColumn() === SCHEMA_VERSION) {
            return;
        }
    } catch (PDOException) {
        // Fresh database – fall through and install the schema.
    }

    $statements = match ($driver) {
        'postgres', 'pgsql' => postgres_schema(),
        'mysql' => mysql_schema(),
        default => sqlite_schema(),
    };

    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }

    if ($driver === 'mysql') {
        $pdo->prepare('INSERT INTO meta (k, v) VALUES (:k, :v) ON DUPLICATE KEY UPDATE v = VALUES(v)')
            ->execute([':k' => 'schema_version', ':v' => SCHEMA_VERSION]);
    } else {
        $pdo->prepare('INSERT INTO meta (k, v) VALUES (:k, :v) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
            ->execute([':k' => 'schema_version', ':v' => SCHEMA_VERSION]);
    }
}

/** @return list<string> */
function postgres_schema(): array
{
    return [
        'CREATE TABLE IF NOT EXISTS meta (
            k VARCHAR(64) PRIMARY KEY,
            v VARCHAR(255) NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS users (
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            username VARCHAR(32) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at BIGINT NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS sessions (
            token_hash CHAR(64) PRIMARY KEY,
            user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            csrf_token CHAR(64) NOT NULL,
            created_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL,
            last_seen BIGINT NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT \'\',
            user_agent VARCHAR(255) NOT NULL DEFAULT \'\'
        )',
        'CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions (user_id)',
        'CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions (expires_at)',
        'CREATE TABLE IF NOT EXISTS rooms (
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            public_id CHAR(18) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            version BIGINT NOT NULL DEFAULT 0,
            point_count BIGINT NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL
        )',
        'CREATE INDEX IF NOT EXISTS idx_rooms_owner ON rooms (owner_id, updated_at)',
        'CREATE TABLE IF NOT EXISTS strokes (
            id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,
            room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
            user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            mode VARCHAR(8) NOT NULL DEFAULT \'pen\',
            color CHAR(7) NOT NULL DEFAULT \'#111827\',
            width SMALLINT NOT NULL DEFAULT 4,
            points TEXT NOT NULL,
            created_at BIGINT NOT NULL
        )',
        'CREATE INDEX IF NOT EXISTS idx_strokes_room ON strokes (room_id, id)',
        'CREATE INDEX IF NOT EXISTS idx_strokes_user ON strokes (room_id, user_id, id)',
        'CREATE TABLE IF NOT EXISTS rate_limits (
            rl_key VARCHAR(191) PRIMARY KEY,
            rl_count INTEGER NOT NULL,
            rl_reset BIGINT NOT NULL
        )',
    ];
}

/** @return list<string> */
function mysql_schema(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS meta (
            k VARCHAR(64) NOT NULL PRIMARY KEY,
            v VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(32) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at BIGINT NOT NULL,
            UNIQUE KEY uniq_users_username (username)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS sessions (
            token_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            csrf_token CHAR(64) NOT NULL,
            created_at BIGINT NOT NULL,
            expires_at BIGINT NOT NULL,
            last_seen BIGINT NOT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            user_agent VARCHAR(255) NOT NULL DEFAULT '',
            KEY idx_sessions_user (user_id),
            KEY idx_sessions_expiry (expires_at),
            CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS rooms (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(18) NOT NULL,
            name VARCHAR(80) NOT NULL,
            owner_id BIGINT UNSIGNED NOT NULL,
            version BIGINT UNSIGNED NOT NULL DEFAULT 0,
            point_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at BIGINT NOT NULL,
            updated_at BIGINT NOT NULL,
            UNIQUE KEY uniq_rooms_public (public_id),
            KEY idx_rooms_owner (owner_id, updated_at),
            CONSTRAINT fk_rooms_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS strokes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            room_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            mode VARCHAR(8) NOT NULL DEFAULT 'pen',
            color CHAR(7) NOT NULL DEFAULT '#111827',
            width SMALLINT UNSIGNED NOT NULL DEFAULT 4,
            points MEDIUMTEXT NOT NULL,
            created_at BIGINT NOT NULL,
            KEY idx_strokes_room (room_id, id),
            KEY idx_strokes_user (room_id, user_id, id),
            CONSTRAINT fk_strokes_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
            CONSTRAINT fk_strokes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS rate_limits (
            rl_key VARCHAR(191) NOT NULL PRIMARY KEY,
            rl_count INT UNSIGNED NOT NULL,
            rl_reset BIGINT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/** @return list<string> */
function sqlite_schema(): array
{
    return [
        'CREATE TABLE IF NOT EXISTS meta (
            k TEXT NOT NULL PRIMARY KEY,
            v TEXT NOT NULL
        )',

        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL COLLATE NOCASE UNIQUE,
            password_hash TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )',

        'CREATE TABLE IF NOT EXISTS sessions (
            token_hash TEXT NOT NULL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            csrf_token TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            expires_at INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            ip TEXT NOT NULL DEFAULT \'\',
            user_agent TEXT NOT NULL DEFAULT \'\'
        )',

        'CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions (user_id)',
        'CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions (expires_at)',

        'CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            owner_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            version INTEGER NOT NULL DEFAULT 0,
            point_count INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            updated_at INTEGER NOT NULL
        )',

        'CREATE INDEX IF NOT EXISTS idx_rooms_owner ON rooms (owner_id, updated_at)',

        'CREATE TABLE IF NOT EXISTS strokes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            mode TEXT NOT NULL DEFAULT \'pen\',
            color TEXT NOT NULL DEFAULT \'#111827\',
            width INTEGER NOT NULL DEFAULT 4,
            points TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )',

        'CREATE INDEX IF NOT EXISTS idx_strokes_room ON strokes (room_id, id)',
        'CREATE INDEX IF NOT EXISTS idx_strokes_user ON strokes (room_id, user_id, id)',

        'CREATE TABLE IF NOT EXISTS rate_limits (
            rl_key TEXT NOT NULL PRIMARY KEY,
            rl_count INTEGER NOT NULL,
            rl_reset INTEGER NOT NULL
        )',
    ];
}

/** Opportunistically remove expired sessions and stale counters. */
function maybe_gc(PDO $pdo): void
{
    if (random_int(1, 40) !== 1) {
        return;
    }

    try {
        $pdo->prepare('DELETE FROM sessions WHERE expires_at < :now')->execute([':now' => now()]);
        $pdo->prepare('DELETE FROM rate_limits WHERE rl_reset < :cutoff')
            ->execute([':cutoff' => now() - 86400]);
    } catch (Throwable $error) {
        error_log('[DrawSpace] GC failed: ' . $error->getMessage());
    }
}

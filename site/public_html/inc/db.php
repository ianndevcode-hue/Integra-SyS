<?php
declare(strict_types=1);

/**
 * PDO wrapper supporting MySQL (production/Hostinger) and SQLite (local dev).
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $driver = config('db.driver', 'mysql');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($driver === 'sqlite') {
        $path = config('db.sqlite_path', STORAGE_PATH . '/database.sqlite');
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
    } else {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            config('db.host', 'localhost'),
            (int)config('db.port', 3306),
            config('db.name')
        );
        $pdo = new PDO($dsn, config('db.user'), config('db.pass'), $options);
        $pdo->exec("SET time_zone = '-03:00'");
    }
    return $pdo;
}

function db_driver(): string
{
    return config('db.driver', 'mysql');
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute(array_values($params));
    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [])
{
    $value = db_query($sql, $params)->fetchColumn();
    return $value === false ? null : $value;
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $cols),
        implode(', ', array_fill(0, count($cols), '?'))
    );
    db_query($sql, array_values($data));
    return (int)db()->lastInsertId();
}

function db_update(string $table, int $id, array $data): int
{
    if (!$data) return 0;
    $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
    $params = array_values($data);
    $params[] = $id;
    return db_exec("UPDATE $table SET $sets WHERE id = ?", $params);
}

function db_find(string $table, int $id): ?array
{
    return db_one("SELECT * FROM $table WHERE id = ?", [$id]);
}

function db_transaction(callable $fn)
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

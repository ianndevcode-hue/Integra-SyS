<?php
declare(strict_types=1);

/**
 * Developer CLI.
 *   php inc/cli.php setup-local            # SQLite config + migrate + seed + admin@local.test / admin12345
 *   php inc/cli.php migrate
 *   php inc/cli.php create-admin <email> <password> [name]
 */

if (PHP_SAPI !== 'cli') exit(1);

require __DIR__ . '/bootstrap.php';
require INC_PATH . '/content.php';
require_once INC_PATH . '/schema.php';
require INC_PATH . '/seed.php';

$cmd = $argv[1] ?? 'help';

switch ($cmd) {
    case 'setup-local':
        $config = [
            'app_url' => 'http://localhost:8080',
            'app_key' => bin2hex(random_bytes(32)),
            'debug' => true,
            'db' => ['driver' => 'sqlite', 'sqlite_path' => STORAGE_PATH . '/database.sqlite'],
        ];
        @unlink(STORAGE_PATH . '/database.sqlite');
        file_put_contents(INC_PATH . '/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");
        $GLOBALS['config'] = $config;
        run_migrations();
        seed_base();
        db_insert('users', ['name' => 'Admin Local', 'email' => 'admin@local.test', 'password_hash' => password_hash('admin12345', PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1, 'created_at' => now()]);
        echo "Local setup done. Admin: admin@local.test / admin12345\n";
        break;

    case 'migrate':
        foreach (run_migrations() as $line) echo $line, PHP_EOL;
        seed_base();
        echo "OK\n";
        break;

    case 'create-admin':
        [$email, $password] = [$argv[2] ?? '', $argv[3] ?? ''];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) exit("Usage: create-admin <email> <password(10+)> [name]\n");
        db_insert('users', ['name' => $argv[4] ?? 'Administrador', 'email' => strtolower($email), 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'admin', 'active' => 1, 'created_at' => now()]);
        echo "Admin created\n";
        break;

    default:
        echo "Commands: setup-local | migrate | create-admin <email> <password> [name]\n";
}

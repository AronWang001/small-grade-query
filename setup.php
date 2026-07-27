<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const SETUP_ROOT = __DIR__;

require_once SETUP_ROOT . '/app/Support/InteractiveSetup.php';

if (in_array('--help', array_slice($argv ?? [], 1), true)) {
    echo "成绩查询系统交互式安装\n\n";
    echo "用法：php setup.php\n";
    echo "安装程序会询问数据库连接、网站地址和超级管理员账号密码。\n";
    exit(0);
}

function setupPrompt(string $label, string $default = ''): string
{
    $suffix = $default === '' ? '' : ' [' . $default . ']';
    fwrite(STDOUT, $label . $suffix . '：');
    $value = fgets(STDIN);
    if (!is_string($value)) {
        throw new RuntimeException('无法读取终端输入。');
    }
    $value = trim($value);
    return $value === '' ? $default : $value;
}

function setupSecret(string $label): string
{
    fwrite(STDOUT, $label . '：');
    if (PHP_OS_FAMILY === 'Windows') {
        $script = <<<'POWERSHELL'
$secret = Read-Host -AsSecureString
$pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
try {
    [Console]::Out.Write([Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer))
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer)
}
POWERSHELL;
        $pipes = [];
        $process = proc_open(
            ['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script],
            [
                0 => STDIN,
                1 => ['pipe', 'w'],
                2 => STDERR,
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动安全密码输入。');
        }
        $value = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('无法读取安全密码输入。');
        }
        fwrite(STDOUT, PHP_EOL);
        return $value;
    }

    $restore = null;
    if (function_exists('shell_exec')) {
        $state = shell_exec('stty -g 2>/dev/null');
        if (is_string($state) && trim($state) !== '') {
            $restore = trim($state);
            @shell_exec('stty -echo 2>/dev/null');
        }
    }
    try {
        $value = fgets(STDIN);
    } finally {
        if ($restore !== null) {
            @shell_exec('stty ' . escapeshellarg($restore) . ' 2>/dev/null');
            fwrite(STDOUT, PHP_EOL);
        }
    }
    if (!is_string($value)) {
        throw new RuntimeException('无法读取终端输入。');
    }
    return rtrim($value, "\r\n");
}

function setupCheckRuntime(): void
{
    if (version_compare(PHP_VERSION, '8.1.0', '<')) {
        throw new RuntimeException('需要PHP 8.1或更高版本。');
    }
    foreach (['pdo_mysql', 'sodium', 'mbstring', 'fileinfo', 'zip', 'openssl'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('缺少PHP扩展：' . $extension);
        }
    }
    if (!is_file(SETUP_ROOT . '/vendor/autoload.php')) {
        throw new RuntimeException('安装包缺少vendor依赖，请使用完整一键安装包。');
    }
    if (is_file(SETUP_ROOT . '/.env')) {
        throw new RuntimeException(
            '.env已经存在。为防止覆盖现有站点，请改用 php tools/install.php。'
        );
    }
}

try {
    setupCheckRuntime();

    echo PHP_EOL . "=== 成绩查询系统一键安装 ===" . PHP_EOL;
    echo "密码输入不会显示在终端，也不会写入安装日志。" . PHP_EOL . PHP_EOL;

    $webUser = '';
    if (PHP_OS_FAMILY !== 'Windows') {
        $webUser = InteractiveSetup::detectWebUser() ?? '';
        if ($webUser === '') {
            $webUser = setupPrompt('PHP-FPM运行用户（不能填写root）');
        } else {
            echo '已检测到PHP-FPM工作进程用户：' . $webUser . PHP_EOL;
        }
    }

    $raw = [
        'host' => setupPrompt('数据库主机', '127.0.0.1'),
        'port' => setupPrompt('数据库端口', '3306'),
        'name' => setupPrompt('数据库名称'),
        'user' => setupPrompt('数据库用户名'),
        'password' => setupSecret('数据库密码'),
        'url' => setupPrompt('网站地址（例如 https://example.com，可留空）'),
        'web_user' => $webUser,
        'admin_username' => setupPrompt('超级管理员用户名', 'admin'),
        'admin_password' => setupSecret('超级管理员密码'),
    ];
    $confirmation = setupSecret('再次输入超级管理员密码');
    if (!hash_equals((string) $raw['admin_password'], $confirmation)) {
        throw new InvalidArgumentException('两次输入的超级管理员密码不一致。');
    }

    $config = InteractiveSetup::validate($raw);

    echo PHP_EOL . "即将安装到：" . PHP_EOL;
    echo '- 数据库服务器：' . $config['host'] . ':' . $config['port'] . PHP_EOL;
    echo '- 数据库名称：' . $config['name'] . PHP_EOL;
    if ($config['web_user'] !== '') {
        echo '- PHP-FPM用户：' . $config['web_user'] . PHP_EOL;
    }
    echo '- 超级管理员：' . $config['admin_username'] . PHP_EOL;
    echo '- 网站地址：' . ($config['url'] === '' ? '未填写' : $config['url']) . PHP_EOL;
    if (strtolower(setupPrompt('确认继续？输入 YES', 'NO')) !== 'yes') {
        echo "已取消，未写入配置或数据库。" . PHP_EOL;
        exit(2);
    }

    $serverDsn = sprintf(
        'mysql:host=%s;port=%d;charset=utf8mb4',
        $config['host'],
        $config['port']
    );
    try {
        $serverPdo = new PDO($serverDsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException) {
        throw new RuntimeException('无法连接数据库服务器，请检查主机、端口、用户名和密码。');
    }

    $databaseStatement = $serverPdo->prepare(
        'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?'
    );
    $databaseStatement->execute([$config['name']]);
    if ($databaseStatement->fetchColumn() === false) {
        try {
            $serverPdo->exec(
                'CREATE DATABASE `' . $config['name']
                . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            echo "数据库不存在，已自动创建。" . PHP_EOL;
        } catch (PDOException) {
            throw new RuntimeException(
                '数据库不存在且当前账号无权创建，请先由数据库管理员创建后重试。'
            );
        }
    }

    $databaseDsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['host'],
        $config['port'],
        $config['name']
    );
    $pdo = new PDO($databaseDsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $tables = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?'
    );
    $tables->execute([$config['name'], 'BASE TABLE']);
    if ((int) $tables->fetchColumn() !== 0) {
        throw new RuntimeException(
            '目标数据库不是空库。为保护现有数据，一键安装程序已停止。'
        );
    }

    $appKey = 'base64:' . base64_encode(random_bytes(32));
    $environment = InteractiveSetup::renderEnvironment($config, $appKey);
    InteractiveSetup::prepareApplicationRoot(
        SETUP_ROOT,
        $config['web_user']
    );
    InteractiveSetup::writeEnvironmentAtomically(
        SETUP_ROOT . '/.env',
        $environment,
        $config['web_user']
    );
    InteractiveSetup::prepareWritableStorage(
        SETUP_ROOT . '/storage',
        $config['web_user']
    );

    require_once SETUP_ROOT . '/app/bootstrap.php';
    require_once SETUP_ROOT . '/app/Support/Installer.php';

    try {
        $result = Installer::install(
            $pdo,
            SETUP_ROOT . '/database/schema.sql',
            SETUP_ROOT . '/database/migrations',
            static function (string $message): void {
                fwrite(STDOUT, $message . PHP_EOL);
            },
            [
                'username' => $config['admin_username'],
                'password' => $config['admin_password'],
            ]
        );
    } catch (Throwable) {
        throw new RuntimeException(
            '数据库初始化失败。.env已保留，请检查数据库权限后执行 php tools/install.php 重试。'
        );
    }

    if (!$result['admin_created']) {
        throw new RuntimeException('安装完成但未创建超级管理员，请检查目标数据库。');
    }

    echo PHP_EOL . "安装成功。" . PHP_EOL;
    echo '超级管理员：' . $result['admin_username'] . PHP_EOL;
    echo '请访问：' . ($config['url'] === '' ? '/login.php' : $config['url'] . '/login.php') . PHP_EOL;
    echo 'Turnstile、SMTP和注册邮箱验证码默认关闭，可登录后台后配置。' . PHP_EOL;
    exit(0);
} catch (InvalidArgumentException|RuntimeException $exception) {
    fwrite(STDERR, PHP_EOL . '安装失败：' . $exception->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable) {
    fwrite(
        STDERR,
        PHP_EOL . '安装失败：系统无法完成安装，请检查PHP扩展和数据库权限。' . PHP_EOL
    );
    exit(1);
}

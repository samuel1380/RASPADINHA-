<?php
// db.php
// Banco de Dados via PDO

// Carregar variáveis do .env caso existam e não estejam no getenv()
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'snakebet';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASSWORD') ?: '';
$port = getenv('DB_PORT') ?: 3306;

try {
    $dsn = "mysql:host=$host;dbname=$dbname;port=$port;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $username, $password, $options);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["error" => "Database connection failed"]);
    exit;
}

// Ensure settings exist in DB (Migration from Node.js)
function ensureSettingsExist($pdo) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("
                INSERT INTO settings (setting_key, setting_value) VALUES 
                ('cpaValue', '10'),
                ('cpaMinDeposit', '20'),
                ('realRevShare', '20'),
                ('fakeRevShare', '50'),
                ('minDeposit', '10'),
                ('minWithdraw', '50')
            ");
        }
    } catch (Exception $e) {
        // Table doesn't exist yet - user needs to run database.sql first
        // Silently ignore so API can still respond with a meaningful error
    }
}
ensureSettingsExist($pdo);
?>

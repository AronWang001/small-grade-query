<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

echo "APP_KEY Rotation - NOTICE" . PHP_EOL;
echo str_repeat("=", 50) . PHP_EOL;
echo PHP_EOL;
echo "WARNING: Automatic APP_KEY rotation is not supported in this version." . PHP_EOL;
echo "Changing APP_KEY will invalidate ALL encrypted data in the database." . PHP_EOL;
echo PHP_EOL;
echo "If you MUST rotate the key:" . PHP_EOL;
echo "1. Back up your database first." . PHP_EOL;
echo "2. Generate a new key: php tools/generate_app_key.php" . PHP_EOL;
echo "3. Update APP_KEY in .env" . PHP_EOL;
echo "4. All existing encrypted data (phone, email, query logs) will be unreadable." . PHP_EOL;
echo "5. You may need to re-register admins or re-import data." . PHP_EOL;
echo PHP_EOL;
echo "A proper key rotation tool with re-encryption will be available in a future version." . PHP_EOL;

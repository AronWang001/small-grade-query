<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$key = base64_encode(random_bytes(32));
echo 'base64:' . $key . PHP_EOL;
echo PHP_EOL;
echo 'Copy the line above and set APP_KEY= in your .env file.' . PHP_EOL;
echo 'Do NOT commit this value to version control.' . PHP_EOL;

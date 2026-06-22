<?php

require __DIR__ . '/../vendor/autoload.php';

use LinkShieldAI\Client;

$client = new Client(); // Reads LINKSHIELDAI_API_KEY.
$result = $client->scan('https://example.com', 'standard');

echo $result['verdict'] . ' ' . $result['request_id'] . PHP_EOL;

<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use LinkShieldAI\Client;

$client = new Client();
$result = $client->chimera('https://google.com');

echo $result->result . PHP_EOL;
echo ($result->probability === null ? 'unknown' : (string) $result->probability) . PHP_EOL;

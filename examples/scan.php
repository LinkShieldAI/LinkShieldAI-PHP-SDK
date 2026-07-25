<?php

require __DIR__ . '/../vendor/autoload.php';

use LinkShieldAI\Client;

$client = new Client();
$result = $client->scan('https://example.com', 'standard');

echo $result->verdict . PHP_EOL;
echo $result->requestId . PHP_EOL;
var_dump($result->reasonCodes);

if ($result->isMalicious()) {
    echo 'Block or review this URL' . PHP_EOL;
}

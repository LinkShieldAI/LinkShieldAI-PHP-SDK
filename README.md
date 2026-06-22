# LinkShieldAI PHP SDK

New integrations should use `$client->scan($url, $mode)`, which calls Bearer-authenticated `POST /v1/scan`. Existing methods remain for compatibility.

```php
$client = new \LinkShieldAI\Client(); // Reads LINKSHIELDAI_API_KEY.
$result = $client->scan('https://example.com', 'standard');
echo $result['verdict'] . ' ' . $result['request_id'];
```

`UNKNOWN` means the API found no decisive signal. Do not treat it as `SAFE`.

PHP SDK for the LinkShieldAI API at `https://api.linkshieldai.com`.

The SDK supports basic URL safety checks, detailed checks with screenshot URL and detected tag, screenshot download, NSFW site checks, Chimera AI classification, retry/backoff for transient API failures, and a small command-line tool.

## Install

```bash
composer require linkshieldai/linkshieldai
```

## Authentication

Every SDK request sends the key as an `Authorization: Bearer` header. Legacy endpoints receive only the URL as a query parameter.

```php
use LinkShieldAI\Client;

$client = new Client(apiKey: 'YOUR_API_KEY');
```

Or set `LINKSHIELDAI_API_KEY` and omit `apiKey`.

## Usage

```php
$basic = $client->basicCheck('https://example.com');
echo $basic->result;
var_dump($basic->isMalicious);

$detailed = $client->detailedCheck('https://example.com');
echo $detailed->screenshotUrl;
echo $detailed->tag;

$nsfw = $client->nsfwCheck('https://example.com');
var_dump($nsfw->isNsfw);

$chimera = $client->chimera('https://google.com');
echo $chimera->result;
echo $chimera->probability;
```

The API field `"screenshot url"` is normalized to `$result->screenshotUrl`.

## Screenshot Download

SDK screenshot downloads also use Bearer authentication. Existing direct screenshot URLs remain compatible.

```php
$imageBytes = $client->getScreenshot('05046f.png');
$client->getScreenshot('https://api.linkshieldai.com/screenshot/05046f.png', 'site.png');
```

## Options

```php
$client = new Client(
    apiKey: 'YOUR_API_KEY',
    baseUrl: 'https://api.linkshieldai.com',
    timeout: 15.0,
    maxRetries: 3,
    backoffFactor: 1.0,
);
```

By default the SDK uses `timeout: 10.0`, `maxRetries: 2`, and `backoffFactor: 0.5`.

Retries are applied to temporary connection failures and HTTP `429`, `502`, `503`, and `504`.

## CLI

```bash
vendor/bin/linkshieldai --api-key YOUR_API_KEY scan https://example.com --mode standard
vendor/bin/linkshieldai --api-key YOUR_API_KEY scan https://example.com --mode detailed
vendor/bin/linkshieldai --api-key YOUR_API_KEY scan https://example.com --mode deep
vendor/bin/linkshieldai --api-key YOUR_API_KEY basic https://example.com
vendor/bin/linkshieldai --api-key YOUR_API_KEY detailed https://example.com
vendor/bin/linkshieldai --api-key YOUR_API_KEY nsfw https://example.com
vendor/bin/linkshieldai --api-key YOUR_API_KEY chimera https://google.com
vendor/bin/linkshieldai --api-key YOUR_API_KEY screenshot 05046f.png --output site.png
```

You can omit `--api-key` if `LINKSHIELDAI_API_KEY` is set.

## Errors

```php
use LinkShieldAI\Exception\ApiConnectionException;
use LinkShieldAI\Exception\ApiResponseException;
use LinkShieldAI\Exception\ApiStatusException;
use LinkShieldAI\Exception\AuthenticationException;
use LinkShieldAI\Exception\RateLimitException;
```

Raw API payloads are preserved on result objects through `$result->raw`.

Keep API keys server-side. Do not expose them in browser JavaScript.

# LinkShieldAI PHP SDK

PHP SDK for the LinkShieldAI API at `https://api.linkshieldai.com`.

The SDK supports URL risk scanning with `POST /v1/scan`, three scan depths, optional model analysis and raw signal output, screenshot download, NSFW site checks, Chimera AI classification, retry/backoff for transient API failures, and a small command-line tool.

## Install

```bash
composer require linkshieldai/linkshieldai
```

## Authentication

The API uses Bearer authentication. The key is sent in the `Authorization` header and never in the query string.

```php
use LinkShieldAI\Client;

$client = new Client(apiKey: 'YOUR_API_KEY');
```

Or set `LINKSHIELDAI_API_KEY` and omit `apiKey`.

Keep API keys server-side. Do not expose them in browser JavaScript.

## Scan a URL

Wraps `POST https://api.linkshieldai.com/v1/scan`.

```php
$result = $client->scan('https://example.com', 'standard');

echo $result->verdict;      // SAFE, MALICIOUS or UNKNOWN
echo $result->requestId;
print_r($result->reasonCodes);

if ($result->isMalicious()) {
    // Block or review this URL
}
```

### Verdicts

`verdict` is `SAFE`, `MALICIOUS` or `UNKNOWN`.

`UNKNOWN` means no decisive signal was available. **It does not mean safe**, and `isSafe()` returns `false` for it:

| verdict | `isMalicious()` | `isSafe()` | `isUnknown()` |
| --- | --- | --- | --- |
| `MALICIOUS` | `true` | `false` | `false` |
| `SAFE` | `false` | `true` | `false` |
| `UNKNOWN` | `false` | `false` | `true` |

### Modes

| mode | What it adds |
| --- | --- |
| `standard` | Fast reputation and threat-feed decision. |
| `detailed` | Redirect, page-preview, brand, and screenshot signals when available. |
| `deep` | Page fingerprinting when earlier signals are inconclusive. |

An unrecognised mode throws `InvalidArgumentException` before any request is sent.

### Model analysis

Pass `ai: true` to add model analysis when page fingerprinting is inconclusive. It is off by default and applies to `deep` only, since the model scores the page HTML that only `deep` fetches. Without it, `riskScore` and `confidence` stay `null`.

```php
$result = $client->scan('https://example.com', 'deep', ai: true);
echo $result->verdict, $result->riskScore, $result->confidence;
```

### Raw signals

`reasonCodes` tells you why a verdict was reached. `includeSignals: true` also returns what each source independently reported, so you can apply your own precedence:

```php
$result = $client->scan('https://example.com', includeSignals: true);

if ($result->signals !== null) {
    echo $result->signals->urlReputation;      // malicious | safe | unknown
    echo $result->signals->domainReputation;
    echo $result->signals->threatFeed;
    var_dump($result->signals->externalReputation);
    var_dump($result->signals->degraded);      // a lookup failed
}
```

### Result fields

```php
$result->verdict;           // SAFE | MALICIOUS | UNKNOWN
$result->requestId;         // quote this in support tickets
$result->mode;
$result->confidence;        // null unless ai: true
$result->riskScore;         // null unless ai: true
$result->threatCategories;
$result->reasonCodes;
$result->brandTarget;       // detected impersonation target, if any
$result->screenshotUrl;
$result->submittedUrl;
$result->normalizedUrl;
$result->redirects;
$result->scannedAt;
$result->freshness;
$result->engineVersion;
$result->signals;           // null unless includeSignals: true
$result->raw;               // the untouched JSON payload
```

## Other endpoints

```php
$nsfw = $client->nsfwCheck('https://example.com');
var_dump($nsfw->isNsfw);

$chimera = $client->chimera('https://google.com');
echo $chimera->result;
echo $chimera->probability;
```

## Screenshot download

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

Retries are applied to temporary connection failures and HTTP `429`, `502`, `503`, and `504`. `Retry-After` is honoured when the API sends it.

## CLI

```bash
vendor/bin/linkshieldai --api-key YOUR_API_KEY scan https://example.com
vendor/bin/linkshieldai --api-key YOUR_API_KEY scan https://example.com --mode detailed
vendor/bin/linkshieldai --api-key YOUR_API_KEY scan https://example.com --mode deep --ai
vendor/bin/linkshieldai --api-key YOUR_API_KEY scan https://example.com --include-signals
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

| Exception | Thrown when |
| --- | --- |
| `AuthenticationException` | No API key was provided. |
| `RateLimitException` | HTTP 429. Carries `retryAfter` when the API sends it. |
| `ApiStatusException` | Any other non-success status. Carries `statusCode`. |
| `ApiResponseException` | Malformed JSON, or a payload containing an error. |
| `ApiConnectionException` | Timeouts, DNS failures, connection failures. |

Raw API payloads are preserved on result objects through `$result->raw`.

## Upgrading from 0.1.x

`basicCheck()` and `detailedCheck()` have been removed. Use `scan()`:

```php
// before
$result = $client->basicCheck($url);
if ($result->isMalicious) { /* ... */ }

// after
$result = $client->scan($url);
if ($result->isMalicious()) { /* ... */ }
```

```php
// before
$result = $client->detailedCheck($url);
echo $result->screenshotUrl, $result->tag;

// after
$result = $client->scan($url, 'detailed');
echo $result->screenshotUrl, $result->brandTarget;
```

`BasicCheckResult` and `DetailedCheckResult` are replaced by `ScanResult`. `isMalicious` is now a method, not a property. The CLI commands `basic` and `detailed` are replaced by `scan --mode`.

The underlying `GET /` and `GET /classify_link` endpoints still work and are not being removed without notice, so existing direct HTTP integrations are unaffected.

## Documentation

<https://docs.linkshieldai.com>

<?php
// Buy a virtual number on VioSMS, wait for the SMS verification code and
// cancel (refund) if it doesn't arrive. PHP 7.4+ with the curl extension.
//
//   export VIOSMS_API_KEY=your_key
//   php viosms.php wa 16          # WhatsApp, United Kingdom
//   php viosms.php --services
//   php viosms.php --countries

const VIOSMS_BASE_URL = 'https://vio-sms.com/api/v1';
const POLL_EVERY = 5;          // seconds between /status calls
const GIVE_UP_AFTER = 300;     // cancel if no code after this many seconds

class VioSMSException extends Exception
{
    public $status;
    public $body;
    public $retryAfter;

    public function __construct(int $status, array $body, int $retryAfter = 0)
    {
        parent::__construct("HTTP $status: " . ($body['error'] ?? json_encode($body)));
        $this->status = $status;
        $this->body = $body;
        $this->retryAfter = $retryAfter;
    }
}

class VioSMS
{
    private $apiKey;
    private $baseUrl;

    public function __construct(string $apiKey, string $baseUrl = VIOSMS_BASE_URL)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $retryAfter = 0;
        $headers = ['Authorization: Bearer ' . $this->apiKey];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfter) {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = (int) trim(substr($line, 12));
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Request failed: $error");
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = ['error' => $raw];
        }
        if ($status >= 400) {
            throw new VioSMSException($status, $data, $retryAfter);
        }
        return $data;
    }

    public function balance(): float
    {
        return $this->request('GET', '/balance')['balance'];
    }

    public function services(): array
    {
        return $this->request('GET', '/services')['data'];
    }

    public function countries(): array
    {
        return $this->request('GET', '/countries')['data'];
    }

    public function buy(string $service, string $country, string $operator = 'any'): array
    {
        return $this->request('POST', '/buy', [
            'service' => $service,
            'country' => $country,
            'operator' => $operator,
        ])['data'];
    }

    public function status(string $id): array
    {
        return $this->request('GET', '/status?id=' . urlencode($id))['data'];
    }

    public function cancel(string $id): array
    {
        return $this->request('POST', '/cancel', ['id' => $id]);
    }

    /** Polls /status until the activation leaves WAITING. Returns null on timeout. */
    public function waitForCode(string $id, int $pollEvery = POLL_EVERY, int $giveUpAfter = GIVE_UP_AFTER): ?array
    {
        $deadline = time() + $giveUpAfter;
        while (time() < $deadline) {
            $activation = $this->status($id);
            if ($activation['status'] !== 'WAITING') {
                return $activation;
            }
            sleep($pollEvery);
        }
        return null;
    }

    /** Cancelling is allowed 2 minutes after buying; waits out Retry-After if needed. */
    public function cancelWithRetry(string $id): array
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return $this->cancel($id);
            } catch (VioSMSException $e) {
                if (!in_array($e->status, [400, 503], true) || ($e->status === 400 && !$e->retryAfter)) {
                    throw $e;
                }
                sleep($e->retryAfter ?: 5);
            }
        }
        throw new RuntimeException("Could not cancel activation $id");
    }
}

if (PHP_SAPI !== 'cli' || realpath($argv[0]) !== __FILE__) {
    return;
}

$apiKey = getenv('VIOSMS_API_KEY');
if (!$apiKey) {
    fwrite(STDERR, "Set VIOSMS_API_KEY first (Profile page on vio-sms.com).\n");
    exit(1);
}

$client = new VioSMS($apiKey);
$args = array_slice($argv, 1);

if (($args[0] ?? '') === '--services') {
    foreach ($client->services() as $s) {
        printf("%-8s %-30s %s\n", $s['code'], $s['name'], $s['price']);
    }
    exit;
}
if (($args[0] ?? '') === '--countries') {
    foreach ($client->countries() as $c) {
        printf("%-6s %s\n", $c['code'], $c['name']);
    }
    exit;
}
if (count($args) < 2) {
    echo "Usage: php viosms.php <service> <country>   e.g. php viosms.php wa 16\n";
    exit(1);
}

printf("Balance: $%.2f\n", $client->balance());

try {
    $number = $client->buy($args[0], $args[1]);
} catch (VioSMSException $e) {
    if ($e->status === 402) {
        exit("Not enough balance: need \${$e->body['required']}, have \${$e->body['balance']}\n");
    }
    if ($e->status === 403 && ($e->body['error'] ?? '') === 'service_blocked') {
        exit("Service blocked for {$e->retryAfter}s after repeated failed activations.\n");
    }
    if ($e->status === 500) {
        exit("No numbers in stock for this service/country. Try another country (nothing was charged).\n");
    }
    throw $e;
}

echo "Number: {$number['phone']}  (id {$number['id']}, \${$number['price']}, valid until {$number['expiresAt']})\n";
echo "Enter this number in the app, then wait...\n";

$activation = $client->waitForCode($number['id']);
if ($activation !== null && $activation['status'] === 'CANCELLED') {
    exit("The activation expired and was refunded.\n");
}
if ($activation !== null) {
    echo "Code: {$activation['smsCode']}\n";
    echo "Full SMS: {$activation['smsText']}\n";
    exit;
}

echo "No code yet, cancelling for a refund...\n";
$client->cancelWithRetry($number['id']);
echo "Cancelled, the price is back on your balance.\n";

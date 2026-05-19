<?php
// paymongo.php — PayMongo API helper functions.
// Requires PAYMONGO_SECRET_KEY in your .env file.

function paymongo_secret(): string {
    $key = $_ENV['PAYMONGO_SECRET_KEY'] ?? '';
    if (!$key) {
        throw new RuntimeException('PAYMONGO_SECRET_KEY is not set in .env');
    }
    return $key;
}

/**
 * Create a PayMongo Payment Link.
 *
 * @param int    $amount_centavos  Amount in centavos (e.g. 50000 = ₱500.00)
 * @param string $description      Short description shown to the payer
 * @param array  $metadata         Extra key-value pairs stored on the link
 * @return array  ['url' => '...', 'link_id' => '...']
 * @throws RuntimeException on API error
 */
function paymongo_create_link(int $amount_centavos, string $description, array $metadata = []): array {
    $payload = [
        'data' => [
            'attributes' => [
                'amount'      => $amount_centavos,
                'description' => $description,
                'currency'    => 'PHP',
                'metadata'    => $metadata,
            ]
        ]
    ];

    $ch = curl_init('https://api.paymongo.com/v1/links');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode(paymongo_secret() . ':'),
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        throw new RuntimeException("PayMongo connection error: $curl_err");
    }

    $body = json_decode($response, true);

    if ($http_code !== 200 || empty($body['data'])) {
        $msg = $body['errors'][0]['detail'] ?? 'Unknown PayMongo error';
        throw new RuntimeException("PayMongo API error ($http_code): $msg");
    }

    return [
        'link_id'     => $body['data']['id'],
        'url'         => $body['data']['attributes']['checkout_url'],
        'reference'   => $body['data']['attributes']['reference_number'],
        'status'      => $body['data']['attributes']['status'],
        'raw'         => $body,
    ];
}

/**
 * Retrieve a PayMongo Payment Link by ID.
 */
function paymongo_get_link(string $link_id): array {
    $ch = curl_init("https://api.paymongo.com/v1/links/$link_id");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Basic ' . base64_encode(paymongo_secret() . ':'),
        ],
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $body = json_decode($response, true);

    if ($http_code !== 200 || empty($body['data'])) {
        throw new RuntimeException("PayMongo link not found: $link_id");
    }

    return $body['data'];
}

/**
 * Verify a PayMongo webhook signature.
 * Returns true if valid, false otherwise.
 *
 * @param string $raw_body     Raw request body from php://input
 * @param string $signature    X-Paymongo-Signature header value
 * @param string $webhook_secret  PAYMONGO_WEBHOOK_SECRET from .env
 */
function paymongo_verify_webhook(string $raw_body, string $signature, string $webhook_secret): bool {
    // PayMongo signature format: t=TIMESTAMP,te=TEST_SIG,li=LIVE_SIG
    $parts = [];
    foreach (explode(',', $signature) as $part) {
        [$key, $val] = explode('=', $part, 2);
        $parts[$key] = $val;
    }

    $timestamp = $parts['t'] ?? '';
    $sig_to_check = $parts['li'] ?? ($parts['te'] ?? ''); // live first, test fallback

    if (!$timestamp || !$sig_to_check) return false;

    $payload    = $timestamp . '.' . $raw_body;
    $computed   = hash_hmac('sha256', $payload, $webhook_secret);

    return hash_equals($computed, $sig_to_check);
}

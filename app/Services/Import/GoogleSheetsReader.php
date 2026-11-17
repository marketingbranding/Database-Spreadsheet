<?php

namespace App\Services\Import;

class GoogleSheetsReader implements SheetReader
{
    public function __construct(
        private readonly string $spreadsheetId,
        private readonly ?string $credentialsPath = null,
        private readonly ?string $caBundle = null,
        private readonly int $timeout = 30,
    ) {
    }

    public function read(array $tabs): array
    {
        $token = $this->accessToken();
        $result = [];

        foreach ($tabs as $tab) {
            $range = "'" . str_replace("'", "''", $tab) . "'";
            $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($this->spreadsheetId)
                . '/values/' . rawurlencode($range)
                . '?valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING';

            $response = $this->request($url, $token);
            $result[$tab] = array_map(
                fn ($row) => array_values((array) $row),
                $response['values'] ?? []
            );
        }

        return $result;
    }

    public function sourceLabel(): string
    {
        return 'google:' . $this->spreadsheetId;
    }

    private function accessToken(): string
    {
        $cache = config('marison.sheets.token_cache');
        if (is_string($cache) && is_file($cache)) {
            $cached = json_decode((string) file_get_contents($cache), true);
            if (is_array($cached) && ($cached['expires_at'] ?? 0) > time() + 60 && ! empty($cached['access_token'])) {
                return $cached['access_token'];
            }
        }

        $path = $this->credentialsPath ?: config('marison.sheets.credentials');
        if (! is_file($path)) {
            throw new \RuntimeException("Google service account file not found at [{$path}]. Set GOOGLE_SHEETS_CREDENTIALS.");
        }

        $sa = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (empty($sa['client_email']) || empty($sa['private_key'])) {
            throw new \RuntimeException('Google service account JSON is missing client_email or private_key.');
        }

        $encode = fn (string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        $now = time();
        $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $encode(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly https://www.googleapis.com/auth/drive.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        if (! openssl_sign("$header.$claims", $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Unable to sign Google service account assertion.');
        }

        $assertion = "$header.$claims." . $encode($signature);

        $response = $this->http(
            'https://oauth2.googleapis.com/token',
            [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]),
            ]
        );

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Google token exchange failed: ' . ($response['error_description'] ?? json_encode($response)));
        }

        if (is_string($cache)) {
            @mkdir(dirname($cache), 0777, true);
            file_put_contents($cache, json_encode([
                'access_token' => $response['access_token'],
                'expires_at' => $now + (int) ($response['expires_in'] ?? 3600),
            ]));
        }

        return $response['access_token'];
    }

    private function request(string $url, string $token): array
    {
        $attempts = 0;
        do {
            $attempts++;
            try {
                return $this->http($url, [
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
                ]);
            } catch (\RuntimeException $e) {
                if ($attempts >= 3 || ! str_contains($e->getMessage(), 'HTTP 429')) {
                    throw $e;
                }
                usleep(500000 * $attempts);
            }
        } while (true);
    }

    private function http(string $url, array $options): array
    {
        $ch = curl_init($url);
        $defaults = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
        ];

        $ca = $this->caBundle ?: config('marison.sheets.ca_bundle');
        if (is_string($ca) && $ca !== '') {
            $defaults[CURLOPT_CAINFO] = $ca;
        }

        curl_setopt_array($ch, $defaults + $options);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Google Sheets request failed: ' . ($error ?: 'unknown cURL error'));
        }

        $decoded = json_decode($raw, true);
        if ($status >= 400) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $raw) : $raw;
            throw new \RuntimeException("Google Sheets HTTP {$status}: " . mb_substr((string) $message, 0, 500));
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException('Google Sheets returned an invalid JSON response.');
        }

        return $decoded;
    }
}

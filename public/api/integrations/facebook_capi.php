<?php
/**
 * Facebook Conversions API integration helper
 * Sends 'Lead' events with dedup using browser eventID and cookies.
 */

if (!function_exists('facebook_capi_send_event')) {
    /**
     * Send a single CAPI event
     * @param array $event Event fields (event_name, event_time, event_source_url, action_source, event_id)
     * @param array $user User data (ph, em, fbp, fbc, client_ip_address, client_user_agent, external_id)
     * @param array $custom Custom data (optional)
     * @param string|null $logFile Log file path
     * @param array $options Overrides: pixel_id, access_token, api_version
     * @return array
     */
    function facebook_capi_send_event(array $event, array $user, array $custom = [], ?string $logFile = null, array $options = []): array
    {
        $logFile = $logFile ?: __DIR__ . '/../facebook-capi-log.txt';

        $pixelId = $options['pixel_id'] ?? (defined('FB_PIXEL_ID') ? FB_PIXEL_ID : null);
        $token = $options['access_token'] ?? (defined('FB_ACCESS_TOKEN') ? FB_ACCESS_TOKEN : null);
        $api = $options['api_version'] ?? (defined('FB_API_VERSION') ? FB_API_VERSION : 'v18.0');

        if (!$pixelId || !$token) {
            @file_put_contents($logFile, date('c') . " | Missing FB CAPI config\n", FILE_APPEND);
            return ['success' => false, 'status' => null, 'response' => null, 'error' => 'Missing FB config'];
        }

        $payload = [
            'data' => [
                [
                    'event_name' => $event['event_name'] ?? 'Lead',
                    'event_time' => (int) ($event['event_time'] ?? time()),
                    'event_source_url' => $event['event_source_url'] ?? null,
                    'action_source' => $event['action_source'] ?? 'website',
                    'event_id' => $event['event_id'] ?? null,
                    'user_data' => array_filter($user, fn($v) => $v !== null && $v !== ''),
                    'custom_data' => array_filter($custom, fn($v) => $v !== null && $v !== ''),
                ]
            ],
        ];

        $url = sprintf('https://graph.facebook.com/%s/%s/events?access_token=%s', $api, $pixelId, urlencode($token));
        if (!function_exists('curl_init')) {
            @file_put_contents($logFile, date('c') . " | cURL not available for FB CAPI\n", FILE_APPEND);
            return ['success' => false, 'status' => null, 'response' => null, 'error' => 'cURL missing'];
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $ok = ($curlErr === '' && $status >= 200 && $status < 300);
        if ($ok) {
            if (defined('FB_VERBOSE_LOG') && FB_VERBOSE_LOG) {
                @file_put_contents($logFile, date('c') . " | CAPI OK | Status: $status | Resp: $response\n", FILE_APPEND);
            }
            return ['success' => true, 'status' => $status, 'response' => $response, 'error' => null];
        }
        @file_put_contents($logFile, date('c') . " | CAPI FAIL | Status: $status | Err: $curlErr | Resp: $response\n", FILE_APPEND);
        return ['success' => false, 'status' => $status, 'response' => $response, 'error' => $curlErr ?: 'HTTP error'];
    }
}

if (!function_exists('facebook_capi_send_lead')) {
    /**
     * Convenience wrapper for sending a Lead event from raw fields
     * $params keys: name, phone, email, source_url, fbp, fbc, event_id, client_ip, client_ua, external_id
     */
    function facebook_capi_send_lead(array $params, ?string $logFile = null, array $options = []): array
    {
        $logFile = $logFile ?: __DIR__ . '/../facebook-capi-log.txt';
        $name = trim($params['name'] ?? '');
        $email = trim($params['email'] ?? '');
        $phone = trim($params['phone'] ?? '');
        $sourceUrl = $params['source_url'] ?? null;
        $fbc = $params['fbc'] ?? null;
        $fbp = $params['fbp'] ?? null;
        $eventId = $params['event_id'] ?? null;
        $clientIp = $params['client_ip'] ?? null;
        $clientUa = $params['client_ua'] ?? null;
        $externalId = $params['external_id'] ?? null;
        $projectName = $params['project_name'] ?? null; // raw proje metni
        $projectCode = $params['project_code'] ?? null; // numeric code if mapped
        $value = isset($params['value']) ? (float) $params['value'] : 0.0;
        $currency = $params['currency'] ?? 'TRY';

        // Hash helpers (SHA256 lower)
        $h = function ($v) {
            $v = trim(strtolower((string) $v));
            if ($v === '')
                return null;
            return hash('sha256', $v);
        };
        $normalizePhone = function ($ph) {
            $ph = preg_replace('/[^0-9+]+/', '', (string) $ph);
            // remove leading '+' for hashing per FB guideline
            $phDigits = ltrim($ph, '+');
            return $phDigits;
        };

        $userData = [
            'client_user_agent' => $clientUa,
            'client_ip_address' => $clientIp,
            'fbc' => $fbc ?: null,
            'fbp' => $fbp ?: null,
            'external_id' => $externalId ? $h($externalId) : null,
        ];

        if ($phone)
            $userData['ph'] = $h($normalizePhone($phone));
        if ($email)
            $userData['em'] = $h($email);
        if ($name) {
            $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
            if ($parts) {
                $userData['fn'] = $h($parts[0]);
                if (count($parts) > 1)
                    $userData['ln'] = $h(end($parts));
            }
        }

        $event = [
            'event_name' => 'Lead',
            'event_time' => time(),
            'event_source_url' => $sourceUrl,
            'action_source' => 'website',
            'event_id' => $eventId,
        ];

        $customData = [];
        if ($projectName)
            $customData['content_name'] = $projectName;
        if ($projectCode)
            $customData['project_code'] = (string) $projectCode;
        $customData['value'] = $value;
        $customData['currency'] = $currency;

        return facebook_capi_send_event($event, $userData, $customData, $logFile, $options);
    }
}

<?php
/**
 * Callintech CRM Integration
 * Posts a single lead (by id) to Callintech and flags it on success.
 */

if (!function_exists('callintech_send_lead')) {
    /**
     * Send a lead to Callintech CRM by lead id
     *
     * @param PDO   $pdo       PDO connection to the same DB with `leads` table
     * @param int   $leadId    Lead primary key id
     * @param array $options   Optional overrides: ['token' => '', 'subdomain' => '', 'timeout' => 8]
     * @param string $logFile  Path to append logs
     * @return array [success => bool, status => int|null, response => string|null, error => string|null]
     */
    function callintech_send_lead(PDO $pdo, int $leadId, array $options = [], ?string $logFile = null): array
    {
        $logFile = $logFile ?: __DIR__ . '/../callintech-log.txt';

        // Pull config from global defines if available
        $token = $options['token'] ?? (defined('CALLINTECH_TOKEN') ? CALLINTECH_TOKEN : null);
        $sub = $options['subdomain'] ?? (defined('CALLINTECH_SUBDOMAIN') ? CALLINTECH_SUBDOMAIN : null);
        $timeout = (int) ($options['timeout'] ?? (defined('CALLINTECH_TIMEOUT') ? CALLINTECH_TIMEOUT : 8));

        if (!$token || !$sub) {
            @file_put_contents($logFile, date('c') . " | Missing CALLINTECH config (token/subdomain).\n", FILE_APPEND);
            return ['success' => false, 'status' => null, 'response' => null, 'error' => 'Missing config'];
        }

        try {
            // Fetch the lead (ensure not already sent)
            $stmt = $pdo->prepare("SELECT * FROM leads WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $leadId]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$lead) {
                return ['success' => false, 'status' => null, 'response' => null, 'error' => 'Lead not found'];
            }

            // Skip if already sent (column may not exist yet)
            $alreadySent = false;
            if (array_key_exists('sent_to_callintech', $lead)) {
                $alreadySent = (int) $lead['sent_to_callintech'] === 1;
            }
            if ($alreadySent) {
                return ['success' => true, 'status' => null, 'response' => null, 'error' => null];
            }

            // CRM alan ID'lerine göre dinamik mapping
            // Ekran görüntüsündeki alanlar:
            // c1 Telefon1, c2 Telefon2, c4 Adı Soyadı, c6 E-Posta, c16 İlgilendiği Proje,
            // c17 Data Ref Link (burada source_page), c18 Tarih, c19 Saat, c20 ID,
            // c21 Mesaj, c22 KVKK Onay, c23 Reklam İleti Onay
            $kvkkVal = (isset($lead['consent_kvkk']) && (int) $lead['consent_kvkk'] === 1) ? 'evet' : 'hayır';
            $marketingVal = (isset($lead['consent_marketing']) && (int) $lead['consent_marketing'] === 1) ? 'evet' : 'hayır';

            $body = [];

            // Telefon1
            if (!empty($lead['phone'])) {
                $body['c1'] = $lead['phone'];
            }
            // Ad Soyad
            if (!empty($lead['name'])) {
                $body['c4'] = $lead['name'];
            }
            // İlgilendiği Proje (gelişmiş normalizasyon + mapping)
            if (!empty($lead['project'])) {
                $rawProject = trim($lead['project']);
                // Türkçe karakterleri ASCII'ye çevir
                $transTable = [
                    'ü' => 'u',
                    'Ü' => 'u',
                    'ş' => 's',
                    'Ş' => 's',
                    'ı' => 'i',
                    'İ' => 'i',
                    'ğ' => 'g',
                    'Ğ' => 'g',
                    'ç' => 'c',
                    'Ç' => 'c',
                    'ö' => 'o',
                    'Ö' => 'o'
                ];
                $normProject = strtr($rawProject, $transTable);
                $normProject = mb_strtolower($normProject, 'UTF-8');
                $normProject = preg_replace('/[^a-z0-9]+/u', '', $normProject);
                // CRM seçenek kodları: 4:Egel park, 3:Atapark, 2:Trendroyal 4, 1:Trendroyal 3
                $projectMap = [
                    'trendroyal4' => 2,
                    'kusadasitrendroyal4' => 2,
                    'trendroyal3' => 1,
                    'kusadasitrendroyal3' => 1,
                    'atapark' => 3,
                    'kusadasiatapark' => 3,
                    'egelpark' => 4,
                    'kusadasiegelpark' => 4,
                ];
                $chosen = $projectMap[$normProject] ?? null;
                if (!$chosen && str_starts_with($normProject, 'kusadasi')) {
                    $trimmed = substr($normProject, 8);
                    $chosen = $projectMap[$trimmed] ?? null;
                }
                // Sadece eşleşme varsa gönder, değilse alanı boş bırak (opsiyonel)
                if ($chosen !== null) {
                    $body['c16'] = $chosen;
                }
                if (defined('CALLINTECH_VERBOSE_LOG') && CALLINTECH_VERBOSE_LOG) {
                    $chosenLabel = ($chosen !== null) ? (string) $chosen : 'OMITTED';
                    @file_put_contents($logFile, date('c') . " | ProjectMap raw='$rawProject' norm='$normProject' chosen='$chosenLabel'\n", FILE_APPEND);
                }
            }
            // Kaynak sayfa -> Data Ref Link
            if (!empty($lead['source_page'])) {
                $body['c17'] = $lead['source_page'];
            }
            // Tarih & Saat (istemci anı)
            $body['c18'] = date('Y-m-d');
            $body['c19'] = date('H:i:s');
            // Internal ID
            $body['c20'] = (string) $leadId;
            // Mesaj
            if (!empty($lead['message'])) {
                $body['c21'] = $lead['message'];
            }
            // KVKK & Pazarlama izinleri
            $body['c22'] = $kvkkVal;
            $body['c23'] = $marketingVal;
            // NOT: E-Posta, Telefon2, İl/İlçe/Adres alanları elimizde yok -> gönderilmiyor.

            $payload = [
                'headers' => [
                    'token' => $token,
                    'action' => 'addData',
                ],
                'body' => $body,
            ];

            $url = sprintf('https://%s.callintech.com/api/', $sub);

            if (!function_exists('curl_init')) {
                @file_put_contents($logFile, date('c') . " | cURL not available in PHP\n", FILE_APPEND);
                return ['success' => false, 'status' => null, 'response' => null, 'error' => 'cURL missing'];
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $timeout,
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
            $respJson = null;
            if ($response !== false) {
                $decoded = json_decode($response, true);
                if (is_array($decoded)) {
                    $respJson = $decoded;
                }
            }
            $appSuccess = false;
            if ($respJson) {
                $appSuccess = (isset($respJson['success']) && (int) $respJson['success'] === 1 && (!isset($respJson['error']) || (int) $respJson['error'] === 0));
            }
            if ($ok && $appSuccess) {
                try {
                    $q = $pdo->prepare("UPDATE leads SET sent_to_callintech = 1, callintech_sent_at = NOW() WHERE id = :id");
                    $q->execute([':id' => $leadId]);
                } catch (Throwable $e) {
                    @file_put_contents($logFile, date('c') . ' | Update flag failed: ' . $e->getMessage() . "\n", FILE_APPEND);
                }
                if (defined('CALLINTECH_VERBOSE_LOG') && CALLINTECH_VERBOSE_LOG) {
                    @file_put_contents($logFile, date('c') . " | Lead #$leadId sent OK | Status: $status | Resp: $response\n", FILE_APPEND);
                }
                return ['success' => true, 'status' => $status, 'response' => $response, 'error' => null];
            }
            $errMsg = $curlErr ?: 'HTTP or application error';
            if ($respJson && isset($respJson['errormessage'])) {
                $errMsg = $respJson['errormessage'];
            }
            @file_put_contents($logFile, date('c') . " | Lead #$leadId send FAILED | Status: $status | TransportErr: $curlErr | AppErr: $errMsg | Resp: $response\n", FILE_APPEND);
            return ['success' => false, 'status' => $status, 'response' => $response, 'error' => $errMsg];
        } catch (Throwable $e) {
            @file_put_contents($logFile, date('c') . ' | Callintech exception: ' . $e->getMessage() . "\n", FILE_APPEND);
            return ['success' => false, 'status' => null, 'response' => null, 'error' => $e->getMessage()];
        }
    }
}

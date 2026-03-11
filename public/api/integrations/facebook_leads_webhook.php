<?php
/**
 * Facebook Lead Ads Webhook Handler
 * 
 * Bu dosya Meta Lead Ads reklamlarından gelen lead'leri işler:
 * 1. Webhook doğrulaması (GET)
 * 2. Lead verisi alma ve işleme (POST)
 * 3. Veritabanına kayıt
 * 4. Callintech CRM'e gönderim
 */

// Konfigürasyon ve yardımcı dosyaları yükle
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/callintech.php';

$logFile = __DIR__ . '/../facebook-leads-log.txt';

// -----------------------------------
// 1. WEBHOOK DOĞRULAMASI (GET)
// -----------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode = $_GET['hub_mode'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';
    $verifyToken = $_GET['hub_verify_token'] ?? '';
    
    $expectedToken = defined('FB_LEADS_VERIFY_TOKEN') ? FB_LEADS_VERIFY_TOKEN : '';
    
    if ($mode === 'subscribe' && $verifyToken === $expectedToken && $expectedToken !== '') {
        // Doğrulama başarılı
        @file_put_contents($logFile, date('c') . " | VERIFY OK\n", FILE_APPEND);
        http_response_code(200);
        echo $challenge;
        exit;
    }
    
    // Doğrulama başarısız
    @file_put_contents($logFile, date('c') . " | VERIFY FAILED | mode=$mode token=$verifyToken\n", FILE_APPEND);
    http_response_code(403);
    echo 'Verification failed.';
    exit;
}

// -----------------------------------
// 2. WEBHOOK VERİSİ ALMA (POST)
// -----------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed.';
    exit;
}

// Facebook'a hızlı yanıt ver (timeout önleme)
http_response_code(200);
echo 'EVENT_RECEIVED';

// Gelen veriyi oku
$rawInput = file_get_contents('php://input');
@file_put_contents($logFile, date('c') . " | RAW: $rawInput\n", FILE_APPEND);

$inputData = json_decode($rawInput, true);
if (!$inputData || !isset($inputData['entry'])) {
    @file_put_contents($logFile, date('c') . " | ERROR: Invalid JSON or no entry\n", FILE_APPEND);
    exit;
}

// Her entry ve change için lead'leri işle
foreach ($inputData['entry'] as $entry) {
    if (!isset($entry['changes'])) {
        continue;
    }
    
    foreach ($entry['changes'] as $change) {
        if (!isset($change['value']['leadgen_id'])) {
            continue;
        }
        
        $leadgenId = $change['value']['leadgen_id'];
        $formId = $change['value']['form_id'] ?? null;
        $pageId = $change['value']['page_id'] ?? null;
        $adgroupId = $change['value']['adgroup_id'] ?? null;
        $createdTime = $change['value']['created_time'] ?? time();
        
        @file_put_contents($logFile, date('c') . " | Processing leadgen_id: $leadgenId\n", FILE_APPEND);
        
        // Lead detaylarını Graph API'den çek
        $leadData = fetchLeadDetails($leadgenId, $logFile);
        
        if ($leadData) {
            // Veritabanına kaydet ve CRM'e gönder
            processLead($leadData, $leadgenId, $formId, $createdTime, $logFile);
        }
    }
}

exit;

// -----------------------------------
// 3. LEAD DETAYLARINI ÇEK
// -----------------------------------
function fetchLeadDetails(string $leadgenId, string $logFile): ?array
{
    $accessToken = defined('FB_LEADS_ACCESS_TOKEN') ? FB_LEADS_ACCESS_TOKEN : '';
    $apiVersion = defined('FB_API_VERSION') ? FB_API_VERSION : 'v18.0';
    
    if (empty($accessToken)) {
        @file_put_contents($logFile, date('c') . " | ERROR: Missing FB_LEADS_ACCESS_TOKEN\n", FILE_APPEND);
        return null;
    }
    
    // Token geçerliliğini kontrol et ve gerekirse yenile
    $accessToken = ensureValidToken($accessToken, $logFile);
    if (!$accessToken) {
        return null;
    }
    
    $url = "https://graph.facebook.com/{$apiVersion}/{$leadgenId}?access_token=" . urlencode($accessToken);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        @file_put_contents($logFile, date('c') . " | CURL ERROR: $curlError\n", FILE_APPEND);
        return null;
    }
    
    if ($httpCode !== 200) {
        @file_put_contents($logFile, date('c') . " | API ERROR: HTTP $httpCode | $response\n", FILE_APPEND);
        return null;
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['field_data'])) {
        @file_put_contents($logFile, date('c') . " | ERROR: Invalid lead data | $response\n", FILE_APPEND);
        return null;
    }
    
    // field_data'yı key-value formatına çevir
    $fields = [];
    foreach ($data['field_data'] as $field) {
        $name = $field['name'] ?? '';
        $value = $field['values'][0] ?? '';
        if ($name) {
            $fields[$name] = $value;
        }
    }
    
    $fields['_raw'] = $data;
    $fields['_created_time'] = $data['created_time'] ?? null;
    
    @file_put_contents($logFile, date('c') . " | Lead fields: " . json_encode($fields, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    
    return $fields;
}

// -----------------------------------
// 4. TOKEN GEÇERLİLİĞİ KONTROLÜ
// -----------------------------------
function ensureValidToken(string $accessToken, string $logFile): ?string
{
    $appId = defined('FB_APP_ID') ? FB_APP_ID : '';
    $appSecret = defined('FB_APP_SECRET') ? FB_APP_SECRET : '';
    
    if (empty($appId) || empty($appSecret)) {
        // App bilgileri yoksa mevcut token'ı kullan
        return $accessToken;
    }
    
    // Token debug endpoint
    $debugUrl = "https://graph.facebook.com/v18.0/debug_token?input_token=" . urlencode($accessToken) . "&access_token={$appId}|{$appSecret}";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $debugUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['data']['is_valid']) && $data['data']['is_valid']) {
        return $accessToken;
    }
    
    // Token geçersizse yenilemeyi dene
    @file_put_contents($logFile, date('c') . " | Token invalid, attempting refresh\n", FILE_APPEND);
    
    $refreshUrl = "https://graph.facebook.com/v18.0/oauth/access_token?grant_type=fb_exchange_token&client_id={$appId}&client_secret={$appSecret}&fb_exchange_token=" . urlencode($accessToken);
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $refreshUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if (isset($data['access_token'])) {
        @file_put_contents($logFile, date('c') . " | Token refreshed successfully\n", FILE_APPEND);
        return $data['access_token'];
    }
    
    @file_put_contents($logFile, date('c') . " | ERROR: Token refresh failed | $response\n", FILE_APPEND);
    return null;
}

// -----------------------------------
// 5. LEAD'İ İŞLE VE KAYDET
// -----------------------------------
function processLead(array $fields, string $leadgenId, ?string $formId, $createdTime, string $logFile): void
{
    // Veritabanı bağlantısı
    $dbHost = env('DB_HOST', 'localhost');
    $dbName = env('DB_NAME', '');
    $dbUser = env('DB_USER', '');
    $dbPass = env('DB_PASS', '');
    
    if (empty($dbName) || empty($dbUser)) {
        @file_put_contents($logFile, date('c') . " | ERROR: Database config missing\n", FILE_APPEND);
        return;
    }
    
    try {
        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        
        // Duplicate kontrolü
        $checkStmt = $pdo->prepare("SELECT id FROM leads WHERE fb_leadgen_id = :lid LIMIT 1");
        $checkStmt->execute([':lid' => $leadgenId]);
        if ($checkStmt->fetch()) {
            @file_put_contents($logFile, date('c') . " | SKIP: Duplicate leadgen_id $leadgenId\n", FILE_APPEND);
            return;
        }
        
        // Form alanlarını normalize et
        // Meta Lead Ads'de yaygın alan isimleri: full_name, email, phone_number, etc.
        $name = $fields['full_name'] ?? $fields['name'] ?? $fields['ad_soyad'] ?? '';
        $phone = $fields['phone_number'] ?? $fields['phone'] ?? $fields['telefon'] ?? '';
        $email = $fields['email'] ?? $fields['e-posta'] ?? '';
        $project = $fields['project'] ?? $fields['proje'] ?? $fields['ilgilendiğiniz_proje'] ?? '';
        
        // Telefon numarasını normalize et
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) > 10 && strpos($phone, '90') === 0) {
            $phone = substr($phone, 2);
        }
        if (strlen($phone) === 11 && $phone[0] === '0') {
            $phone = substr($phone, 1);
        }
        
        // Form kaynağı (hangi formdan geldiği)
        $sourcePage = 'Meta Lead Ads';
        if ($formId) {
            $sourcePage .= " (Form: $formId)";
        }
        
        // Veritabanı kolonlarını kontrol et
        $existingCols = [];
        try {
            $colStmt = $pdo->query('SHOW COLUMNS FROM leads');
            foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (!empty($c['Field'])) {
                    $existingCols[] = $c['Field'];
                }
            }
        } catch (Throwable $e) {
            @file_put_contents($logFile, date('c') . " | WARN: Could not check columns: " . $e->getMessage() . "\n", FILE_APPEND);
        }
        
        // INSERT sorgusu hazırla
        $insertFields = ['name', 'phone', 'source_page'];
        $insertParams = [
            ':name' => $name,
            ':phone' => $phone,
            ':source_page' => $sourcePage,
        ];
        
        // Opsiyonel alanlar
        if (in_array('email', $existingCols, true) && $email) {
            $insertFields[] = 'email';
            $insertParams[':email'] = $email;
        }
        if (in_array('project', $existingCols, true) && $project) {
            $insertFields[] = 'project';
            $insertParams[':project'] = $project;
        }
        if (in_array('fb_leadgen_id', $existingCols, true)) {
            $insertFields[] = 'fb_leadgen_id';
            $insertParams[':fb_leadgen_id'] = $leadgenId;
        }
        if (in_array('lead_source', $existingCols, true)) {
            $insertFields[] = 'lead_source';
            $insertParams[':lead_source'] = 'meta_lead_ads';
        }
        if (in_array('message', $existingCols, true)) {
            // Meta Lead Ads'den gelen ekstra bilgileri mesaj alanına yaz
            $extraInfo = [];
            foreach ($fields as $k => $v) {
                if (!in_array($k, ['full_name', 'name', 'phone_number', 'phone', 'email', '_raw', '_created_time'], true) && $v) {
                    $extraInfo[] = "$k: $v";
                }
            }
            if ($extraInfo) {
                $insertFields[] = 'message';
                $insertParams[':message'] = implode("\n", $extraInfo);
            }
        }
        
        $placeholders = array_map(fn($f) => ':' . $f, $insertFields);
        $sql = 'INSERT INTO leads (' . implode(',', $insertFields) . ') VALUES (' . implode(',', $placeholders) . ')';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($insertParams);
        $leadId = (int) $pdo->lastInsertId();
        
        @file_put_contents($logFile, date('c') . " | INSERT OK: Lead #$leadId | Name: $name | Phone: $phone\n", FILE_APPEND);
        
        // Callintech CRM'e gönder
        if ($leadId > 0 && function_exists('callintech_send_lead')) {
            try {
                $crmResult = callintech_send_lead($pdo, $leadId);
                if ($crmResult['success'] ?? false) {
                    @file_put_contents($logFile, date('c') . " | CRM OK: Lead #$leadId sent to Callintech\n", FILE_APPEND);
                } else {
                    @file_put_contents($logFile, date('c') . " | CRM FAIL: Lead #$leadId | " . json_encode($crmResult) . "\n", FILE_APPEND);
                }
            } catch (Throwable $e) {
                @file_put_contents($logFile, date('c') . " | CRM ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
        
    } catch (Throwable $e) {
        @file_put_contents($logFile, date('c') . " | DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    }
}

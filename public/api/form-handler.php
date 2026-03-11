<?php
// -----------------------------------
// 1. AYARLAR
// -----------------------------------

$thankYouUrl = '/tesekkurler';

// Konfigürasyon ve yardımcı dosyaları yükle
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate-limiter.php';
require_once __DIR__ . '/csrf.php';
@include_once __DIR__ . '/integrations/callintech.php';
@include_once __DIR__ . '/integrations/facebook_capi.php';

$wantsJson = (
    (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
);

// Veritabanı ayarları (.env'den)
$dbHost = env('DB_HOST', 'localhost');
$dbName = env('DB_NAME', '');
$dbUser = env('DB_USER', '');
$dbPass = env('DB_PASS', '');
$logFile = __DIR__ . '/form-log.txt';

// -----------------------------------
// 2. FORM VERİLERİ
// -----------------------------------
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$project = trim($_POST['project'] ?? '');
$message = trim($_POST['message'] ?? '');
$sourcePage = trim($_POST['source_page'] ?? 'Bilinmiyor');
$honeypot = trim($_POST['website_url'] ?? '');
$formStart = isset($_POST['form_start']) ? (int) $_POST['form_start'] : 0;
$csrfToken = trim($_POST['csrf_token'] ?? '');

// Meta Pixel cookies and dedup id
$fbEventId = trim($_POST['fb_event_id'] ?? '');
$fbp = trim($_POST['fbp'] ?? '');
$fbc = trim($_POST['fbc'] ?? '');

// Sadece KVKK ve Pazarlama onayları
$consentKvkk = isset($_POST['consent_kvkk']) ? 1 : 0;
$consentMarketing = isset($_POST['consent_marketing']) ? 1 : 0;

$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

// -----------------------------------
// 3. RATE LIMITING KONTROLÜ
// -----------------------------------
$rateCheck = check_rate_limit(
    $ipAddress,
    RATE_LIMIT_MAX_REQUESTS,
    RATE_LIMIT_WINDOW_SECONDS
);

if (!$rateCheck['allowed']) {
    @file_put_contents(__DIR__ . '/rate-limit-log.txt', date('c') . " | RATE_LIMIT | IP:" . ($ipAddress ?: 'NA') . " | Count:" . $rateCheck['count'] . "\n", FILE_APPEND);
    if ($wantsJson) {
        http_response_code(429);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Çok fazla istek gönderdiniz. Lütfen biraz bekleyin.',
            'retry_after' => $rateCheck['reset_at'] - time()
        ]);
        exit;
    }
    header('Location: /?form_error=rate_limit');
    exit;
}

// -----------------------------------
// 3.1 CSRF TOKEN KONTROLÜ
// -----------------------------------
// CSRF kontrolü - boş token gelmesi durumunda eski formlar için geçici olarak atla
// Yeni formlar csrf_token göndermelidir
if (!empty($csrfToken) && !csrf_validate_token($csrfToken)) {
    @file_put_contents(__DIR__ . '/security-log.txt', date('c') . " | CSRF_FAIL | IP:" . ($ipAddress ?: 'NA') . "\n", FILE_APPEND);
    if ($wantsJson) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Güvenlik doğrulaması başarısız. Sayfayı yenileyip tekrar deneyin.']);
        exit;
    }
    header('Location: /?form_error=csrf');
    exit;
}

// -----------------------------------
// 3.2 TELEFON NUMARASI VALİDASYONU
// -----------------------------------
/**
 * Telefon numarasını doğrula ve normalize et
 * @param string $phone Ham telefon numarası
 * @return array ['valid' => bool, 'normalized' => string, 'error' => string|null]
 */
function validate_phone(string $phone): array
{
    // Sadece rakamları al
    $digits = preg_replace('/[^0-9]/', '', $phone);
    
    // Türkiye için +90 veya 0 ile başlıyorsa düzelt
    if (strpos($digits, '90') === 0 && strlen($digits) >= 12) {
        $digits = substr($digits, 2); // 90'ı kaldır
    }
    if (strpos($digits, '0') === 0 && strlen($digits) === 11) {
        $digits = substr($digits, 1); // Baştaki 0'ı kaldır
    }
    
    // Uzunluk kontrolü (Türkiye: 10 hane)
    if (strlen($digits) < 10) {
        return ['valid' => false, 'normalized' => $phone, 'error' => 'Telefon numarası çok kısa'];
    }
    if (strlen($digits) > 15) {
        return ['valid' => false, 'normalized' => $phone, 'error' => 'Telefon numarası çok uzun'];
    }
    
    // Türkiye GSM operatör kodları: 5xx
    if (strlen($digits) === 10 && $digits[0] !== '5') {
        // Sabit hat olabilir, kabul et
    }
    
    return ['valid' => true, 'normalized' => $digits, 'error' => null];
}

// Telefon validasyonu uygula
if (!empty($phone)) {
    $phoneValidation = validate_phone($phone);
    if (!$phoneValidation['valid']) {
        if ($wantsJson) {
            http_response_code(400);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $phoneValidation['error']]);
            exit;
        }
        header('Location: /?form_error=phone');
        exit;
    }
    $phone = $phoneValidation['normalized'];
}

// -----------------------------------
// 3.3 ZORUNLU ALAN KONTROLÜ
// -----------------------------------
if ($name === '' || $project === '' || $message === '') {
    if ($wantsJson) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Zorunlu alanlar eksik.']);
        exit;
    }
    header('Location: /?form_error=1');
    exit;
}

// -----------------------------------
// 3.4 SPAM FİLTRELERİ (Temel)
// -----------------------------------
$isSpam = false;
$spamReason = '';

// Honeypot doluysa
if ($honeypot !== '') {
    $isSpam = true;
    $spamReason = 'honeypot';
}

// Çok hızlı gönderim (<1200ms)
if (!$isSpam && $formStart > 0 && (microtime(true) * 1000 - $formStart) < 1200) {
    $isSpam = true;
    $spamReason = 'too_fast';
}

// Link sayısı (>=4 http) veya spam kelimeleri
if (!$isSpam) {
    $lcMsg = mb_strtolower($message);
    $httpCount = substr_count($lcMsg, 'http');
    $spamWords = ['viagra', 'casino', 'bet', 'loan', 'credit', 'bitcoin', 'seo', 'porn'];
    foreach ($spamWords as $sw) {
        if (str_contains($lcMsg, $sw)) {
            $isSpam = true;
            $spamReason = 'keyword:' . $sw;
            break;
        }
    }
    if (!$isSpam && $httpCount >= 4) {
        $isSpam = true;
        $spamReason = 'too_many_links';
    }
}

if ($isSpam) {
    @file_put_contents(__DIR__ . '/spam-log.txt', date('c') . " | SPAM ($spamReason) | IP:" . ($ipAddress ?: 'NA') . " | UA:" . ($userAgent ?: 'NA') . " | MSG:" . mb_substr($message, 0, 120) . "\n", FILE_APPEND);
    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => 'Başvurunuz alındı.']); // Sessiz kabul
        exit;
    }
    header('Location: ' . $thankYouUrl . '?status=ok');
    exit;
}

// -----------------------------------
// 4. VERİTABANI KAYDI (Tek INSERT)
// -----------------------------------
try {
    $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Kolonları çek (opsiyonel consent)
    $existingCols = [];
    try {
        $colStmt = $pdo->query('SHOW COLUMNS FROM leads');
        foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (!empty($c['Field'])) {
                $existingCols[] = $c['Field'];
            }
        }
    } catch (Throwable $e) {
    }

    $fields = ['name', 'phone', 'project', 'message', 'source_page', 'ip_address', 'user_agent'];
    $params = [
        ':name' => $name,
        ':phone' => $phone,
        ':project' => $project,
        ':message' => $message,
        ':source_page' => $sourcePage,
        ':ip_address' => $ipAddress,
        ':user_agent' => $userAgent,
    ];

    if (in_array('consent_kvkk', $existingCols, true)) {
        $fields[] = 'consent_kvkk';
        $params[':consent_kvkk'] = $consentKvkk;
    }
    if (in_array('consent_marketing', $existingCols, true)) {
        $fields[] = 'consent_marketing';
        $params[':consent_marketing'] = $consentMarketing;
    }

    $placeholders = array_map(fn($f) => ':' . $f, $fields);
    $sql = 'INSERT INTO leads (' . implode(',', $fields) . ') VALUES (' . implode(',', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // CRM push (sessiz hata)
    try {
        if (function_exists('callintech_send_lead')) {
            $leadId = (int) $pdo->lastInsertId();
            if ($leadId > 0) {
                $crmResult = callintech_send_lead($pdo, $leadId);
                if (!($crmResult['success'] ?? false)) {
                    @file_put_contents($logFile, date('c') . ' | Callintech send failed #' . $leadId . ' | ' . json_encode($crmResult) . PHP_EOL, FILE_APPEND);
                }
                // Facebook CAPI (sessiz hata)
                try {
                    if (function_exists('facebook_capi_send_lead')) {
                        // Proje kodunu mevcut callintech mantığına benzer biçimde normalize edelim (yalnızca CAPI için basit tekrar)
                        $rawProject = $project;
                        $transTable = ['ü' => 'u', 'Ü' => 'u', 'ş' => 's', 'Ş' => 's', 'ı' => 'i', 'İ' => 'i', 'ğ' => 'g', 'Ğ' => 'g', 'ç' => 'c', 'Ç' => 'c', 'ö' => 'o', 'Ö' => 'o'];
                        $normProject = preg_replace('/[^a-z0-9]+/u', '', strtolower(strtr($rawProject, $transTable)));
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
                        $mappedCode = $projectMap[$normProject] ?? null;
                        $capiParams = [
                            'name' => $name,
                            'phone' => $phone,
                            'email' => '',
                            'source_url' => $sourcePage,
                            'fbc' => $fbc,
                            'fbp' => $fbp,
                            'event_id' => $fbEventId ?: null,
                            'client_ip' => $ipAddress,
                            'client_ua' => $userAgent,
                            'external_id' => (string) $leadId,
                            'project_name' => $rawProject ?: null,
                            'project_code' => $mappedCode,
                            'value' => 0,
                            'currency' => 'TRY',
                        ];
                        $capiRes = facebook_capi_send_lead($capiParams);
                        if (!($capiRes['success'] ?? false)) {
                            @file_put_contents($logFile, date('c') . ' | FB CAPI send failed #' . $leadId . ' | ' . json_encode($capiRes) . PHP_EOL, FILE_APPEND);
                        }
                    }
                } catch (Throwable $e) {
                    @file_put_contents($logFile, date('c') . ' | FB CAPI exception: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
                }
            }
        }
    } catch (Throwable $e) {
        @file_put_contents($logFile, date('c') . ' | Callintech exception: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    }

} catch (Exception $e) {
    @file_put_contents($logFile, date('c') . ' | DB Error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    if ($wantsJson) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Bir hata oluştu.']);
        exit;
    }
    header('Location: /?form_error=1');
    exit;
}

// -----------------------------------
// 5. RESPONSE
// -----------------------------------
if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'message' => 'Başvurunuz alındı. En kısa sürede iletişime geçeceğiz.',
        'redirect' => $thankYouUrl . '?status=ok'
    ]);
    exit;
}
header('Location: ' . $thankYouUrl . '?status=ok');
exit;

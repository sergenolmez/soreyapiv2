<?php
/**
 * API Konfigürasyon Dosyası
 * Hassas bilgiler .env dosyasından okunur
 */

// .env dosyasını yükle
if (!function_exists('load_env')) {
    /**
     * .env dosyasını oku ve değerleri döndür
     * @param string $path .env dosya yolu
     * @return array [key => value]
     */
    function load_env(string $path): array
    {
        $env = [];
        if (!file_exists($path)) {
            return $env;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            // Yorum satırlarını atla
            if (empty($line) || $line[0] === '#') {
                continue;
            }
            // KEY=VALUE formatını parse et
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Tırnak işaretlerini kaldır
                if (preg_match('/^(["\'])(.*)\\1$/', $value, $m)) {
                    $value = $m[2];
                }
                $env[$key] = $value;
            }
        }
        return $env;
    }
}

if (!function_exists('env')) {
    /**
     * Ortam değişkenini al
     * @param string $key Değişken adı
     * @param mixed $default Varsayılan değer
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        static $loaded = null;
        if ($loaded === null) {
            $loaded = load_env(__DIR__ . '/.env');
        }
        return $loaded[$key] ?? $default;
    }
}

// Callintech CRM konfigürasyonu
if (!defined('CALLINTECH_SUBDOMAIN')) {
    define('CALLINTECH_SUBDOMAIN', env('CALLINTECH_SUBDOMAIN', ''));
}
if (!defined('CALLINTECH_TOKEN')) {
    define('CALLINTECH_TOKEN', env('CALLINTECH_TOKEN', ''));
}
if (!defined('CALLINTECH_TIMEOUT')) {
    define('CALLINTECH_TIMEOUT', (int) env('CALLINTECH_TIMEOUT', 8));
}
if (!defined('CALLINTECH_VERBOSE_LOG')) {
    define('CALLINTECH_VERBOSE_LOG', env('CALLINTECH_VERBOSE_LOG', 'false') === 'true');
}

// Facebook Conversions API konfigürasyonu
if (!defined('FB_PIXEL_ID')) {
    define('FB_PIXEL_ID', env('FB_PIXEL_ID', ''));
}
if (!defined('FB_ACCESS_TOKEN')) {
    define('FB_ACCESS_TOKEN', env('FB_ACCESS_TOKEN', ''));
}
if (!defined('FB_API_VERSION')) {
    define('FB_API_VERSION', env('FB_API_VERSION', 'v18.0'));
}
if (!defined('FB_VERBOSE_LOG')) {
    define('FB_VERBOSE_LOG', env('FB_VERBOSE_LOG', 'false') === 'true');
}

// Meta Lead Ads Webhook konfigürasyonu
if (!defined('FB_APP_ID')) {
    define('FB_APP_ID', env('FB_APP_ID', ''));
}
if (!defined('FB_APP_SECRET')) {
    define('FB_APP_SECRET', env('FB_APP_SECRET', ''));
}
if (!defined('FB_LEADS_ACCESS_TOKEN')) {
    define('FB_LEADS_ACCESS_TOKEN', env('FB_LEADS_ACCESS_TOKEN', ''));
}
if (!defined('FB_LEADS_VERIFY_TOKEN')) {
    define('FB_LEADS_VERIFY_TOKEN', env('FB_LEADS_VERIFY_TOKEN', ''));
}

// Rate Limiting konfigürasyonu
if (!defined('RATE_LIMIT_MAX_REQUESTS')) {
    define('RATE_LIMIT_MAX_REQUESTS', (int) env('RATE_LIMIT_MAX_REQUESTS', 5));
}
if (!defined('RATE_LIMIT_WINDOW_SECONDS')) {
    define('RATE_LIMIT_WINDOW_SECONDS', (int) env('RATE_LIMIT_WINDOW_SECONDS', 600));
}

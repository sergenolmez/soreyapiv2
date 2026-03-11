<?php
/**
 * Rate Limiter - IP bazlı form gönderim sınırlaması
 * Dosya tabanlı basit implementasyon
 */

if (!function_exists('check_rate_limit')) {
    /**
     * IP için rate limit kontrolü
     * @param string|null $ip IP adresi
     * @param int $maxRequests Maksimum istek sayısı
     * @param int $windowSeconds Zaman penceresi (saniye)
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => int]
     */
    function check_rate_limit(?string $ip, int $maxRequests = 5, int $windowSeconds = 600): array
    {
        if (!$ip) {
            return ['allowed' => true, 'remaining' => $maxRequests, 'reset_at' => time() + $windowSeconds];
        }

        $storageDir = __DIR__ . '/rate-limit-data';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0700, true);
            // .htaccess ile koruma
            @file_put_contents($storageDir . '/.htaccess', "Order Allow,Deny\nDeny from all\n");
        }

        $ipHash = md5($ip);
        $file = $storageDir . '/' . $ipHash . '.json';
        $now = time();

        $data = ['requests' => [], 'first_request' => $now];

        if (file_exists($file)) {
            $content = @file_get_contents($file);
            if ($content) {
                $decoded = json_decode($content, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }

        // Eski istekleri temizle (window dışındakiler)
        $cutoff = $now - $windowSeconds;
        $data['requests'] = array_filter($data['requests'], fn($t) => $t > $cutoff);
        $data['requests'] = array_values($data['requests']);

        $currentCount = count($data['requests']);
        $allowed = $currentCount < $maxRequests;
        $remaining = max(0, $maxRequests - $currentCount - ($allowed ? 1 : 0));

        // En eski isteğin ne zaman expire olacağını hesapla
        $resetAt = $now + $windowSeconds;
        if (!empty($data['requests'])) {
            $resetAt = min($data['requests']) + $windowSeconds;
        }

        if ($allowed) {
            $data['requests'][] = $now;
            @file_put_contents($file, json_encode($data), LOCK_EX);
        }

        return [
            'allowed' => $allowed,
            'remaining' => $remaining,
            'reset_at' => $resetAt,
            'count' => $currentCount + ($allowed ? 1 : 0)
        ];
    }
}

if (!function_exists('cleanup_rate_limit_data')) {
    /**
     * Eski rate limit dosyalarını temizle (cron ile çalıştırılabilir)
     * @param int $maxAge Dosya yaşı (saniye)
     */
    function cleanup_rate_limit_data(int $maxAge = 3600): void
    {
        $storageDir = __DIR__ . '/rate-limit-data';
        if (!is_dir($storageDir)) {
            return;
        }

        $files = glob($storageDir . '/*.json');
        $cutoff = time() - $maxAge;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
            }
        }
    }
}

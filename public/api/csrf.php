<?php
/**
 * CSRF Token Yönetimi
 * Form güvenliği için Cross-Site Request Forgery koruması
 */

if (!function_exists('csrf_generate_token')) {
    /**
     * Yeni CSRF token üret ve session'a kaydet
     * @return string Token değeri
     */
    function csrf_generate_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        $_SESSION['csrf_token_time'] = time();

        return $token;
    }
}

if (!function_exists('csrf_get_token')) {
    /**
     * Mevcut CSRF token'ı al (yoksa üret)
     * @return string Token değeri
     */
    function csrf_get_token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Token yoksa veya 1 saatten eskiyse yenisini üret
        if (
            empty($_SESSION['csrf_token']) ||
            empty($_SESSION['csrf_token_time']) ||
            (time() - $_SESSION['csrf_token_time']) > 3600
        ) {
            return csrf_generate_token();
        }

        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_validate_token')) {
    /**
     * Gelen token'ı doğrula
     * @param string|null $token Form'dan gelen token
     * @param bool $regenerate Doğrulamadan sonra yeni token üret
     * @return bool Geçerli mi
     */
    function csrf_validate_token(?string $token, bool $regenerate = true): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        // Time-based check (1 saat geçerlilik)
        if (
            empty($_SESSION['csrf_token_time']) ||
            (time() - $_SESSION['csrf_token_time']) > 3600
        ) {
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }

        // Timing-safe karşılaştırma
        $valid = hash_equals($_SESSION['csrf_token'], $token);

        if ($valid && $regenerate) {
            // Başarılı doğrulamadan sonra yeni token üret (one-time use)
            csrf_generate_token();
        }

        return $valid;
    }
}

if (!function_exists('csrf_token_field')) {
    /**
     * Form için hidden input HTML'i döndür
     * @return string HTML input elementi
     */
    function csrf_token_field(): string
    {
        $token = csrf_get_token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_token_meta')) {
    /**
     * AJAX için meta tag HTML'i döndür
     * @return string HTML meta elementi
     */
    function csrf_token_meta(): string
    {
        $token = csrf_get_token();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

<?php
/**
 * หอพักจัสมิน mail helper — Resend via cURL (best-effort, never throws to caller)
 */

if (!function_exists('getEnvValue')) {
    function getEnvValue($key, $default = null) {
        $envPath = dirname(__DIR__) . '/.env';
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) < 2) {
                    continue;
                }
                if (trim($parts[0]) === $key) {
                    return trim($parts[1]);
                }
            }
        }
        $fromEnv = getenv($key);
        return ($fromEnv !== false && $fromEnv !== '') ? $fromEnv : $default;
    }
}

/**
 * Send an HTML email via Resend.
 * Returns true on success, false on any failure (missing config, empty recipient, API error).
 */
function sendMail($to, $subject, $html) {
    try {
        $to = is_string($to) ? trim($to) : '';
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $apiKey = getEnvValue('RESEND_API_KEY');
        $fromEmail = getEnvValue('FROM_EMAIL', 'onboarding@resend.dev');

        if (!$apiKey || $apiKey === 're_your_api_key_here') {
            error_log('หอพักจัสมิน mailer: RESEND_API_KEY is missing or placeholder');
            return false;
        }

        $payload = [
            'from' => $fromEmail,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
        ];

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            error_log('หอพักจัสมิน mailer cURL error: ' . $error);
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("หอพักจัสมิน mailer HTTP {$httpCode}: {$response}");
            return false;
        }

        return true;
    } catch (Throwable $e) {
        error_log('หอพักจัสมิน mailer exception: ' . $e->getMessage());
        return false;
    }
}

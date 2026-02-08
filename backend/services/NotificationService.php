<?php

require_once __DIR__ . '/../config.php';

class NotificationService {
    private string $server;
    private string $topic;
    private PDO $db;
    private ?string $lastError = null;
    
    public function __construct(PDO $db) {
        $this->server = defined('NTFY_SERVER') ? NTFY_SERVER : 'https://ntfy.sh';
        $this->topic = defined('NTFY_TOPIC') ? NTFY_TOPIC : 'domainalert';
        $this->db = $db;
    }
    
    public function getLastError(): ?string {
        return $this->lastError;
    }
    
    /**
     * Send notification via ntfy
     */
    public function sendNtfyNotification(string $title, string $message, string $priority = 'high', array $tags = []): bool {
        $this->lastError = null;
        
        if (!function_exists('curl_init')) {
            $this->lastError = 'cURL extension is not installed';
            return false;
        }
        
        $url = rtrim($this->server, '/') . '/' . $this->topic;
        
        $headers = [
            'Title: ' . $title,
            'Priority: ' . $priority,
        ];
        
        if (!empty($tags)) {
            $headers[] = 'Tags: ' . implode(',', $tags);
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $message,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            $this->lastError = "cURL error: $curlError";
            return false;
        }
        
        if ($httpCode < 200 || $httpCode >= 300) {
            $this->lastError = "HTTP error $httpCode: $response";
            return false;
        }
        
        return true;
    }
    
    /**
     * Send notification via email
     */
    public function sendEmailNotification(string $to, string $subject, string $message): bool {
        $this->lastError = null;
        
        // Get SMTP settings from config
        $smtpHost = defined('SMTP_HOST') ? SMTP_HOST : null;
        $smtpPort = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $smtpUser = defined('SMTP_USER') ? SMTP_USER : null;
        $smtpPass = defined('SMTP_PASS') ? SMTP_PASS : null;
        $smtpFrom = defined('SMTP_FROM') ? SMTP_FROM : 'domainalert@localhost';
        $smtpFromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'DomainAlert';
        
        // If SMTP is configured, use it
        if ($smtpHost && $smtpUser && $smtpPass) {
            return $this->sendSmtpEmail($to, $subject, $message, $smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom, $smtpFromName);
        }
        
        // Fallback to PHP mail()
        $headers = [
            'From' => "$smtpFromName <$smtpFrom>",
            'Reply-To' => $smtpFrom,
            'X-Mailer' => 'DomainAlert',
            'Content-Type' => 'text/html; charset=UTF-8',
        ];
        
        $headerStr = '';
        foreach ($headers as $key => $value) {
            $headerStr .= "$key: $value\r\n";
        }
        
        $htmlMessage = $this->formatEmailHtml($subject, $message);
        
        $result = @mail($to, $subject, $htmlMessage, $headerStr);
        
        if (!$result) {
            $this->lastError = 'Failed to send email via mail() function';
        }
        
        return $result;
    }
    
    /**
     * Send email via SMTP
     */
    private function sendSmtpEmail(string $to, string $subject, string $body, string $host, int $port, string $user, string $pass, string $from, string $fromName): bool {
        $socket = @fsockopen($host, $port, $errno, $errstr, 30);
        
        if (!$socket) {
            $this->lastError = "SMTP connection failed: $errstr";
            return false;
        }
        
        $response = fgets($socket, 1024);
        
        // EHLO
        fwrite($socket, "EHLO localhost\r\n");
        $this->readSmtpResponse($socket);
        
        // STARTTLS if port 587
        if ($port == 587) {
            fwrite($socket, "STARTTLS\r\n");
            $this->readSmtpResponse($socket);
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fwrite($socket, "EHLO localhost\r\n");
            $this->readSmtpResponse($socket);
        }
        
        // AUTH LOGIN
        fwrite($socket, "AUTH LOGIN\r\n");
        $this->readSmtpResponse($socket);
        fwrite($socket, base64_encode($user) . "\r\n");
        $this->readSmtpResponse($socket);
        fwrite($socket, base64_encode($pass) . "\r\n");
        $response = $this->readSmtpResponse($socket);
        
        if (strpos($response, '235') === false) {
            $this->lastError = "SMTP auth failed: $response";
            fclose($socket);
            return false;
        }
        
        // MAIL FROM
        fwrite($socket, "MAIL FROM:<$from>\r\n");
        $this->readSmtpResponse($socket);
        
        // RCPT TO
        fwrite($socket, "RCPT TO:<$to>\r\n");
        $this->readSmtpResponse($socket);
        
        // DATA
        fwrite($socket, "DATA\r\n");
        $this->readSmtpResponse($socket);
        
        $htmlBody = $this->formatEmailHtml($subject, $body);
        
        $message = "From: $fromName <$from>\r\n";
        $message .= "To: $to\r\n";
        $message .= "Subject: $subject\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/html; charset=UTF-8\r\n";
        $message .= "\r\n";
        $message .= $htmlBody;
        $message .= "\r\n.\r\n";
        
        fwrite($socket, $message);
        $this->readSmtpResponse($socket);
        
        // QUIT
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        
        return true;
    }
    
    private function readSmtpResponse($socket): string {
        $response = '';
        while ($line = fgets($socket, 1024)) {
            $response .= $line;
            if ($line[3] === ' ') break;
        }
        return $response;
    }
    
    private function formatEmailHtml(string $subject, string $body): string {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; border-radius: 8px; padding: 30px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-top: 0; }
        .message { color: #555; line-height: 1.6; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>$subject</h1>
        <div class="message">$body</div>
        <div class="footer">
            Wysłano przez DomainAlert
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    /**
     * Send notification via all configured channels
     */
    public function sendNotification(string $title, string $message, string $priority = 'high', array $tags = []): bool {
        $ntfySuccess = $this->sendNtfyNotification($title, $message, $priority, $tags);
        
        // Also send email to all users if configured
        $emailSuccess = true;
        if (defined('SMTP_HOST') || function_exists('mail')) {
            $stmt = $this->db->query("SELECT email FROM users");
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($users as $email) {
                if (!$this->sendEmailNotification($email, $title, $message)) {
                    $emailSuccess = false;
                }
            }
        }
        
        return $ntfySuccess || $emailSuccess;
    }
    
    public function notifyDomainAvailable(int $domainId, string $domain): bool {
        $title = "🎉 Domena dostępna!";
        $message = "Domena <strong>$domain</strong> jest teraz dostępna do rejestracji!<br><br>Szybko ją zarejestruj zanim ktoś inny to zrobi!";
        
        $success = $this->sendNotification($title, $message, 'urgent', ['white_check_mark', 'globe']);
        
        // Log notification
        $stmt = $this->db->prepare("INSERT INTO notifications (domain_id, type, message) VALUES (?, 'available', ?)");
        $stmt->execute([$domainId, strip_tags($message)]);
        
        return $success;
    }
    
    public function notifyExpiringDomain(int $domainId, string $domain, string $expiryDate): bool {
        $daysLeft = (int)((strtotime($expiryDate) - time()) / 86400);
        
        $title = "⏰ Domena wygasa za $daysLeft dni";
        $message = "Domena <strong>$domain</strong> wygasa <strong>$expiryDate</strong>.<br><br>Monitorujemy jej status i powiadomimy Cię gdy stanie się dostępna.";
        
        $success = $this->sendNotification($title, $message, 'default', ['hourglass', 'globe']);
        
        $stmt = $this->db->prepare("INSERT INTO notifications (domain_id, type, message) VALUES (?, 'expiring', ?)");
        $stmt->execute([$domainId, strip_tags($message)]);
        
        return $success;
    }
    
    public function getSubscriptionUrl(): string {
        return rtrim($this->server, '/') . '/' . $this->topic;
    }
    
    public function getTopic(): string {
        return $this->topic;
    }
    
    /**
     * Test ntfy connection
     */
    public function testNtfy(): array {
        $success = $this->sendNtfyNotification(
            'Test DomainAlert',
            'To jest wiadomość testowa z DomainAlert. Jeśli ją widzisz, ntfy działa poprawnie!',
            'default',
            ['test_tube']
        );
        
        return [
            'success' => $success,
            'error' => $this->lastError,
            'server' => $this->server,
            'topic' => $this->topic,
        ];
    }
    
    /**
     * Test email
     */
    public function testEmail(string $to): array {
        $success = $this->sendEmailNotification(
            $to,
            'Test DomainAlert',
            'To jest wiadomość testowa z DomainAlert. Jeśli ją widzisz, email działa poprawnie!'
        );
        
        return [
            'success' => $success,
            'error' => $this->lastError,
        ];
    }
}

<?php

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;
use PDO;

/**
 * Email service wrapping PHPMailer.
 * Loads SMTP configuration from settings_smtp table.
 */
class EmailService
{
    private PDO $db;
    private ?int $businessId;

    public function __construct(PDO $db, ?int $businessId = null)
    {
        $this->db = $db;
        $this->businessId = $businessId;
    }

    /**
     * Send an HTML email using the configured SMTP settings.
     *
     * @throws \RuntimeException if SMTP is not configured or sending fails
     */
    public function send(string $to, string $subject, string $htmlBody): void
    {
        $config = $this->getSmtpConfig();

        if (!$config || !$config['is_active']) {
            throw new \RuntimeException('SMTP is not configured or not active for this business.');
        }

        $mail = new PHPMailer(true);

        try {
            // SMTP settings
            $mail->isSMTP();
            $mail->Host       = $config['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['username'];
            $mail->Password   = $this->decryptPassword($config['password_enc']);
            $mail->SMTPSecure = $config['port'] == 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = (int) $config['port'];

            // Sender
            $mail->setFrom($config['from_address'], $config['from_name']);

            // Recipient
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();
        } catch (Exception $e) {
            error_log("EmailService: Failed to send email to {$to}: " . $e->getMessage());
            throw new \RuntimeException('Failed to send email: ' . $mail->ErrorInfo);
        }
    }

    /**
     * Get SMTP configuration for the current business.
     */
    private function getSmtpConfig(): ?array
    {
        if (!$this->businessId) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM settings_smtp WHERE business_id = :business_id LIMIT 1");
        $stmt->execute(['business_id' => $this->businessId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Decrypt stored password. Uses base64 encoding for simplicity.
     * In production, use proper encryption (e.g., openssl_decrypt with app key).
     */
    private function decryptPassword(string $encrypted): string
    {
        return base64_decode($encrypted);
    }

    /**
     * Encrypt password for storage.
     */
    public static function encryptPassword(string $plainPassword): string
    {
        return base64_encode($plainPassword);
    }

    /**
     * Save SMTP settings for a business.
     */
    public function saveSmtpSettings(int $businessId, array $data): void
    {
        $sql = "INSERT INTO settings_smtp (business_id, host, port, username, password_enc, from_address, from_name, is_active)
                VALUES (:business_id, :host, :port, :username, :password_enc, :from_address, :from_name, :is_active)
                ON DUPLICATE KEY UPDATE
                    host = VALUES(host),
                    port = VALUES(port),
                    username = VALUES(username),
                    password_enc = VALUES(password_enc),
                    from_address = VALUES(from_address),
                    from_name = VALUES(from_name),
                    is_active = VALUES(is_active)";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'business_id'  => $businessId,
            'host'         => $data['host'],
            'port'         => (int) ($data['port'] ?? 587),
            'username'     => $data['username'],
            'password_enc' => self::encryptPassword($data['password']),
            'from_address' => $data['from_address'],
            'from_name'    => $data['from_name'],
            'is_active'    => (int) ($data['is_active'] ?? 0),
        ]);
    }

    /**
     * Get SMTP settings for display (password masked).
     */
    public function getSmtpSettings(int $businessId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM settings_smtp WHERE business_id = :business_id LIMIT 1");
        $stmt->execute(['business_id' => $businessId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Mask password for display
        $row['password'] = '********';
        unset($row['password_enc']);

        return $row;
    }
}

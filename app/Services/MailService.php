<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/SystemSettingsService.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

final class MailService
{
    private array $config;

    public function __construct()
    {
        $this->config = SystemSettingsService::getMailConfigForService();
    }

    public function isConfigured(): bool
    {
        return ($this->config['enabled'] ?? false)
            && ($this->config['host'] ?? '') !== ''
            && ($this->config['username'] ?? '') !== ''
            && ($this->config['password'] ?? '') !== ''
            && ($this->config['from_address'] ?? '') !== '';
    }

    public function getConfigStatus(): array
    {
        return [
            'enabled' => $this->config['enabled'] ?? false,
            'host_configured' => ($this->config['host'] ?? '') !== '',
            'auth_configured' => ($this->config['username'] ?? '') !== '' && ($this->config['password'] ?? '') !== '',
            'from_configured' => ($this->config['from_address'] ?? '') !== '',
            'fully_configured' => $this->isConfigured(),
        ];
    }

    /**
     * Send a verification code email.
     */
    public function sendVerificationCode(string $to, string $code): bool
    {
        $subject = '管理员注册邮箱验证码';
        $body = "您好：\n\n"
              . "您正在申请成绩查询系统管理员账号。\n\n"
              . "本次验证码为：\n\n"
              . $code . "\n\n"
              . "验证码10分钟内有效，请勿向他人泄露。\n"
              . "如非本人操作，请忽略本邮件。\n\n"
              . "成绩查询系统\n"
              . "FasterTech";

        return $this->send($to, $subject, $body);
    }

    /**
     * Send an email via SMTP using PHPMailer.
     */
    public function send(string $to, string $subject, string $body): bool
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('邮件发送服务未配置。');
        }

        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('收件人邮箱地址格式无效。');
        }

        // CRLF injection protection
        if (str_contains($to, "\r") || str_contains($to, "\n")) {
            throw new RuntimeException('Invalid recipient address.');
        }

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->Port = (int) ($this->config['port'] ?? 587);
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'] ?? '';
            $mail->Password = $this->config['password'] ?? '';
            $mail->SMTPSecure = ($this->config['encryption'] ?? 'tls') === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = 15;

            // Verify TLS certificates in production
            $mail->SMTPAutoTLS = true;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ];

            $mail->setFrom(
                $this->config['from_address'] ?? '',
                $this->config['from_name'] ?? '成绩查询系统'
            );
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            PublicErrorHandler::logException($e, [
                'component' => 'mail',
                'operation' => 'smtp_send',
            ]);
            throw new RuntimeException('邮件发送失败，请稍后重试。');
        } catch (Throwable $e) {
            PublicErrorHandler::logException($e, [
                'component' => 'mail',
                'operation' => 'send',
            ]);
            throw new RuntimeException('邮件发送服务暂时不可用，请稍后重试。');
        }
    }
}

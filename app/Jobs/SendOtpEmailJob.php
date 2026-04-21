<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\InfrastructureException;
use App\Services\EnvService;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class SendOtpEmailJob
{
    private string $email;

    private string $otp;

    private int $ttl;

    private EnvService $envService;

    public function __construct(string $email, string $otp, int $ttl = 300, ?EnvService $envService = null)
    {
        $this->email = $email;
        $this->otp = $otp;
        $this->ttl = $ttl;
        $this->envService = $envService ?? new EnvService();
    }

    public function handle(): array
    {
        $payload = $this->payload();
        $mailer = $this->mailer();

        try {
            $mailer->setFrom($payload['from'], $payload['from_name']);
            $mailer->addAddress($payload['to']);
            $mailer->Subject = $payload['subject'];
            $mailer->Body = $payload['html'];
            $mailer->AltBody = $payload['body'];
            $mailer->send();
        } catch (MailerException $exception) {
            throw new InfrastructureException('Unable to send OTP email.', 0, $exception);
        }

        return [
            'status' => 'sent',
            'channel' => 'email',
            'recipient' => $this->email,
            'subject' => $payload['subject'],
        ];
    }

    public function payload(): array
    {
        $subject = 'Your Smile AI OTP Code';
        $expiresInMinutes = (int) ceil($this->ttl / 60);
        $body = sprintf(
            'Your OTP code is %s. It will expire in %d minutes.',
            $this->otp,
            $expiresInMinutes
        );

        return [
            'from' => $this->fromAddress(),
            'from_name' => $this->fromName(),
            'to' => $this->email,
            'subject' => $subject,
            'body' => $body,
            'html' => sprintf(
                '<div style="font-family:Arial,sans-serif;color:#111827;line-height:1.6">' .
                '<p>Your OTP code is:</p>' .
                '<p style="font-size:28px;font-weight:700;letter-spacing:6px;margin:16px 0">%s</p>' .
                '<p>It will expire in %d minutes.</p>' .
                '<p>If you did not request this email, you can ignore it.</p>' .
                '</div>',
                htmlspecialchars($this->otp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                $expiresInMinutes
            ),
        ];
    }

    private function mailer(): PHPMailer
    {
        $mailer = new PHPMailer(true);
        $port = $this->envService->int('MAIL_PORT', 587);
        $encryption = $this->resolveEncryption($port);

        $mailer->isSMTP();
        $mailer->Host = $this->required('MAIL_HOST');
        $mailer->Port = $port;
        $mailer->SMTPAuth = true;
        $mailer->Username = $this->required('MAIL_USERNAME');
        $mailer->Password = $this->required('MAIL_PASSWORD');
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = max(5, $this->envService->int('MAIL_TIMEOUT', 15));
        $mailer->isHTML(true);

        if ($encryption !== '') {
            $mailer->SMTPSecure = $encryption;
        }

        return $mailer;
    }

    private function resolveEncryption(int $port): string
    {
        $configured = strtolower(trim((string) $this->envService->get('MAIL_ENCRYPTION', '')));

        return match ($configured) {
            'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS,
            'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS,
            default => match ($port) {
                465 => PHPMailer::ENCRYPTION_SMTPS,
                587 => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            },
        };
    }

    private function fromAddress(): string
    {
        $fromAddress = trim((string) $this->envService->get('MAIL_FROM_ADDRESS', ''));

        if ($fromAddress !== '') {
            return $fromAddress;
        }

        return $this->required('MAIL_USERNAME');
    }

    private function fromName(): string
    {
        $fromName = trim((string) $this->envService->get('MAIL_FROM_NAME', ''));

        if ($fromName !== '') {
            return $fromName;
        }

        return trim((string) $this->envService->get('APP_NAME', 'Smile AI'));
    }

    private function required(string $key): string
    {
        $value = trim((string) $this->envService->get($key, ''));

        if ($value === '') {
            throw new InfrastructureException(sprintf('%s is missing from environment.', $key));
        }

        return $value;
    }
}

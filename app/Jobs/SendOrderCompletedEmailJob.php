<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\InfrastructureException;
use App\Services\EnvService;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class SendOrderCompletedEmailJob
{
    private string $email;

    private array $order;

    private EnvService $envService;

    public function __construct(string $email, array $order, ?EnvService $envService = null)
    {
        $this->email = $email;
        $this->order = $order;
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
            throw new InfrastructureException('Unable to send order completed email.', 0, $exception);
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
        $orderId = strtoupper(substr((string) ($this->order['id'] ?? ''), 0, 8));
        $transferSyntax = strtoupper((string) ($this->order['transfer_syntax'] ?? ''));
        $amount = number_format((float) ($this->order['total_amount'] ?? 0), 0, ',', '.');
        $items = is_array($this->order['items'] ?? null) ? $this->order['items'] : [];
        $customerEmail = trim((string) ($this->order['customer_email'] ?? ''));
        $appName = trim((string) $this->envService->get('APP_NAME', 'Smile AI'));
        $subject = sprintf('[%s] Thanh toán thành công - Đơn hàng #%s', $appName, $orderId);
        $ordersUrl = $this->ordersUrl();
        $ordersLink = $ordersUrl !== ''
            ? sprintf(
                '<a href="%s" style="color:#7c5c2e;font-weight:600;text-decoration:none">%s</a>',
                $this->escape($ordersUrl),
                $this->escape($ordersUrl)
            )
            : 'trang Đơn Hàng';

        $summaryRowsHtml = $this->renderSummaryRow('Số tiền', $amount . ' ₫');
        $summaryRowsHtml .= $this->renderSummaryRow('Nội dung CK', $transferSyntax, true);
        $summaryLines = [
            sprintf('Số tiền: %s ₫', $amount),
            sprintf('Nội dung CK: %s', $transferSyntax),
        ];

        if ($customerEmail !== '') {
            $summaryRowsHtml .= $this->renderSummaryRow('Email chính chủ', $customerEmail);
            $summaryLines[] = sprintf('Email chính chủ: %s', $customerEmail);
        }

        $itemsHtml = '';
        $itemsText = '';

        foreach ($items as $index => $item) {
            $productName = trim((string) ($item['product']['name'] ?? ''));
            $productName = $productName !== '' ? $productName : 'Sản phẩm';
            $requiresCustomerEmail = ($item['product']['requires_customer_email'] ?? false) === true;
            $username = trim((string) ($item['digital_account']['username'] ?? ''));
            $password = trim((string) ($item['digital_account']['password'] ?? ''));
            $passwordMasked = trim((string) ($item['digital_account']['password_masked'] ?? ''));
            $accountId = $item['digital_account']['id'] ?? null;
            $rowBackground = $index % 2 === 0 ? '#fffdf8' : '#fff8ef';

            if ($username === '' && $requiresCustomerEmail && $customerEmail !== '') {
                $username = $customerEmail;
            }

            $usernameDisplay = $username !== '' ? $username : 'Admin sẽ cập nhật';
            $passwordDisplay = $password !== ''
                ? $password
                : ($passwordMasked !== '' ? $passwordMasked : 'Admin sẽ cấp sau');
            $expiresDisplay = $this->formatDate($item['expires_at'] ?? null, 'Đang cập nhật');
            $productNotes = [];

            if ($requiresCustomerEmail && $customerEmail !== '') {
                $productNotes[] = 'Email chính chủ: ' . $customerEmail;
            }

            if ($accountId === null) {
                $productNotes[] = 'Tài khoản sẽ được cấp bởi admin.';
            }

            $notesHtml = '';

            if ($productNotes !== []) {
                $escapedNotes = [];

                foreach ($productNotes as $note) {
                    $escapedNotes[] = $this->escape($note);
                }

                $notesHtml = '<div style="margin-top:6px;color:#6b7280;font-size:12px;line-height:1.6">' . implode('<br>', $escapedNotes) . '</div>';
            }

            $itemsHtml .= sprintf(
                '<tr>' .
                '<td style="padding:16px 18px;border-bottom:1px solid #f0ece4;vertical-align:top;background:%s">' .
                '<strong style="display:block;color:#111827;font-size:14px;line-height:1.5">%s</strong>%s' .
                '</td>' .
                '<td style="padding:16px 14px;border-bottom:1px solid #f0ece4;vertical-align:top;background:%s">' .
                '<span style="display:inline-block;background:#ffffff;border:1px solid #eadfce;border-radius:999px;padding:7px 12px;font-family:monospace;font-size:12px;line-height:1.4;color:#32230f;word-break:break-all">%s</span>' .
                '</td>' .
                '<td style="padding:16px 14px;border-bottom:1px solid #f0ece4;vertical-align:top;background:%s">' .
                '<span style="display:inline-block;background:#fff7e8;border:1px solid #ead7b0;border-radius:999px;padding:7px 12px;font-family:monospace;font-size:12px;line-height:1.4;color:#7c5c2e;word-break:break-all">%s</span>' .
                '</td>' .
                '<td style="padding:16px 18px;border-bottom:1px solid #f0ece4;vertical-align:top;background:%s;white-space:nowrap">' .
                '<span style="display:inline-block;background:#f3efe7;border:1px solid #e5dccf;border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;line-height:1.4;color:#4b5563">%s</span>' .
                '</td>' .
                '</tr>',
                $rowBackground,
                $this->escape($productName),
                $notesHtml,
                $rowBackground,
                $this->escape($usernameDisplay),
                $rowBackground,
                $this->escape($passwordDisplay),
                $rowBackground,
                $this->escape($expiresDisplay)
            );

            $itemLine = sprintf(
                '  - %s | Username/Email: %s | Password: %s | HSD: %s',
                $productName,
                $usernameDisplay,
                $passwordDisplay,
                $expiresDisplay
            );

            if ($productNotes !== []) {
                $itemLine .= ' | Ghi chú: ' . implode(' / ', $productNotes);
            }

            $itemsText .= $itemLine . "\n";
        }

        $tableHtml = $itemsHtml !== ''
            ? '<div style="margin-top:20px;border:1px solid #eadfce;border-radius:18px;overflow:hidden;background:#fff">' .
                '<div style="padding:14px 18px;background:#fbf2e2;border-bottom:1px solid #eadfce">' .
                '<p style="margin:0;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#7c5c2e">Chi tiết bàn giao</p>' .
                '<p style="margin:6px 0 0;font-size:13px;line-height:1.6;color:#6b7280">Thông tin tài khoản và thời hạn sử dụng của từng mặt hàng.</p>' .
                '</div>' .
                '<table role="presentation" style="width:100%;border-collapse:separate;border-spacing:0;font-size:13px">' .
                '<thead><tr style="text-align:left;color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.08em;background:#fffaf1">' .
                '<th style="padding:14px 18px;border-bottom:1px solid #f0ece4">Sản phẩm</th>' .
                '<th style="padding:14px;border-bottom:1px solid #f0ece4">Username / Email</th>' .
                '<th style="padding:14px;border-bottom:1px solid #f0ece4">Password</th>' .
                '<th style="padding:14px 18px;border-bottom:1px solid #f0ece4">HSD</th>' .
                '</tr></thead><tbody>' . $itemsHtml . '</tbody></table></div>'
            : '<p style="color:#6b7280;font-size:13px">Thông tin tài khoản sẽ được cấp bởi admin.</p>';

        $hasTotpAccount = $this->hasTotpAccount($items);
        $portalUrl = $hasTotpAccount ? $this->portalUrl($transferSyntax) : '';

        $body = "Xin chào,\n\n";
        $body .= sprintf("Đơn hàng #%s của bạn đã được thanh toán thành công.\n\n", $orderId);
        $body .= "Thông tin đơn hàng:\n";
        $body .= '  - ' . implode("\n  - ", $summaryLines) . "\n\n";
        $body .= "Mặt hàng:\n";
        $body .= $itemsText !== '' ? $itemsText . "\n" : "  - Xem trên website.\n\n";

        if ($portalUrl !== '') {
            $body .= sprintf("Lấy mã xác thực 2FA tại: %s\n\n", $portalUrl);
        }

        if ($ordersUrl !== '') {
            $body .= sprintf("Xem chi tiết và thông tin đăng nhập đầy đủ tại: %s\n\n", $ordersUrl);
        }

        $body .= "Nếu bạn cần hỗ trợ, vui lòng tạo ticket hỗ trợ trên website.\n\n";
        $body .= sprintf("Trân trọng,\n%s", $appName);

        $portalBlockHtml = '';
        if ($portalUrl !== '') {
            $portalBlockHtml =
                '<div style="margin-top:20px;border:1px solid #d1fae5;border-radius:14px;background:#f0fdf4;padding:16px 20px">' .
                '<p style="margin:0 0 6px;font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#065f46">Mã Xác Thực 2FA (Google Authenticator)</p>' .
                '<p style="margin:0 0 10px;font-size:13px;color:#374151">Tài khoản của bạn sử dụng xác thực 2 yếu tố. Nhập mã đơn hàng vào cổng bên dưới để lấy mã 6 số mỗi 30 giây.</p>' .
                '<a href="' . $this->escape($portalUrl) . '" style="display:inline-block;background:#059669;color:#fff;font-weight:700;font-size:13px;border-radius:999px;padding:10px 20px;text-decoration:none">' .
                'Lấy mã 2FA ngay</a>' .
                '<p style="margin:10px 0 0;font-size:11px;color:#6b7280">Mã đơn hàng: <span style="font-family:monospace;font-weight:700;color:#111827">' . $this->escape($transferSyntax) . '</span></p>' .
                '</div>';
        }

        $html = sprintf(
            '<div style="font-family:Arial,sans-serif;color:#111827;max-width:640px;margin:0 auto;background:#fffdf7;border:1px solid #f0ece4;border-radius:16px;overflow:hidden">' .
            '<div style="background:#7c5c2e;padding:24px 32px"><p style="margin:0;color:#fff;font-size:20px;font-weight:800;letter-spacing:-.02em">%s</p></div>' .
            '<div style="padding:32px">' .
            '<h1 style="font-size:22px;font-weight:800;margin:0 0 8px">Thanh toán thành công!</h1>' .
            '<p style="color:#6b7280;font-size:14px;margin:0 0 24px">Đơn hàng <strong>#%s</strong> đã được xác nhận.</p>' .
            '<div style="background:#fff;border:1px solid #f0ece4;border-radius:12px;padding:16px 20px;margin-bottom:24px">' .
            '<table role="presentation" style="width:100%%;border-collapse:collapse"><tbody>%s</tbody></table>' .
            '</div>' .
            '%s' .
            '%s' .
            '<p style="font-size:13px;color:#6b7280;margin-top:24px">Xem chi tiết và thông tin đăng nhập đầy đủ tại: %s</p>' .
            '<p style="font-size:13px;color:#6b7280;margin-top:8px">Nếu bạn cần hỗ trợ, vui lòng tạo ticket hỗ trợ trên website.</p>' .
            '</div></div>',
            $this->escape($appName),
            $orderId,
            $summaryRowsHtml,
            $tableHtml,
            $portalBlockHtml,
            $ordersLink
        );

        return [
            'from' => $this->fromAddress(),
            'from_name' => $this->fromName(),
            'to' => $this->email,
            'subject' => $subject,
            'body' => $body,
            'html' => $html,
        ];
    }

    private function renderSummaryRow(string $label, string $value, bool $monospace = false): string
    {
        $valueStyle = $monospace
            ? 'font-weight:700;font-size:13px;font-family:monospace;color:#7c5c2e'
            : 'font-weight:700;font-size:15px;color:#111827';

        return sprintf(
            '<tr><td style="padding:0 0 8px;color:#6b7280;font-size:13px">%s</td><td style="padding:0 0 8px;text-align:right;%s">%s</td></tr>',
            $this->escape($label),
            $valueStyle,
            $this->escape($value)
        );
    }

    private function hasTotpAccount(array $items): bool
    {
        foreach ($items as $item) {
            if (($item['digital_account']['has_totp'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function portalUrl(string $transferSyntax): string
    {
        $frontendUrl = $this->frontendUrl();

        if ($frontendUrl === '' || $transferSyntax === '') {
            return '';
        }

        return $frontendUrl . '/portal?key=' . urlencode($transferSyntax);
    }

    private function ordersUrl(): string
    {
        $frontendUrl = $this->frontendUrl();

        return $frontendUrl !== '' ? $frontendUrl . '/orders' : '';
    }

    private function frontendUrl(): string
    {
        $configured = trim((string) $this->envService->get('FRONTEND_URL', $this->envService->get('CLIENT_URL', '')));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $appUrl = trim((string) $this->envService->get('APP_URL', ''));

        if ($appUrl === '') {
            return 'http://localhost:3000';
        }

        $parsed = parse_url($appUrl);
        $host = strtolower((string) ($parsed['host'] ?? ''));
        $port = (int) ($parsed['port'] ?? 0);

        if (in_array($host, ['localhost', '127.0.0.1'], true) && $port !== 3000) {
            $scheme = (string) ($parsed['scheme'] ?? 'http');

            return $scheme . '://' . $host . ':3000';
        }

        return rtrim($appUrl, '/');
    }

    private function formatDate(mixed $value, string $fallback = '—'): string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return $fallback;
        }

        $timestamp = strtotime($normalized);

        if ($timestamp === false) {
            return $fallback;
        }

        return date('d/m/Y', $timestamp);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

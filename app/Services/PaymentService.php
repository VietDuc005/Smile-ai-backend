<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\InfrastructureException;
use App\Exceptions\ValidationException;

final class PaymentService
{
    private EnvService $envService;

    public function __construct(?EnvService $envService = null)
    {
        $this->envService = $envService ?? new EnvService();
    }

    public function transferPrefix(): string
    {
        $prefix = strtoupper(trim((string) $this->envService->get('PAYMENT_TRANSFER_PREFIX', 'SMILE')));
        $prefix = preg_replace('/[^A-Z0-9 ]/', '', $prefix);
        $prefix = trim((string) $prefix);

        if ($prefix === '') {
            return 'SMILE';
        }

        return $prefix;
    }

    public function buildTransferSyntax(string $uniquePart): string
    {
        return $this->transferPrefix() . ' ' . strtoupper(trim($uniquePart));
    }

    public function extractTransferSyntax(string $transferContent): ?string
    {
        $prefix = preg_quote($this->transferPrefix(), '/');

        if (preg_match('/' . $prefix . '\s+[A-Z0-9]+/i', strtoupper($transferContent), $matches) !== 1) {
            return null;
        }

        return strtoupper(trim($matches[0]));
    }

    public function buildVietQrPayload(array $order, array $bankAccount): array
    {
        return [
            'provider' => 'vietqr',
            'bank_bin' => $bankAccount['bank_bin'] ?? '',
            'account_number' => $bankAccount['account_number'] ?? '',
            'account_name' => $bankAccount['account_name'] ?? '',
            'amount' => $order['total_amount'] ?? 0,
            'description' => $order['transfer_syntax'] ?? '',
            'template' => $bankAccount['template'] ?? 'compact2',
        ];
    }

    public function buildVietQrImageUrl(array $order, array $bankAccount): string
    {
        $payload = $this->buildVietQrPayload($order, $bankAccount);
        $baseUrl = rtrim((string) ($bankAccount['base_url'] ?? $this->envService->get('VIETQR_BASE_URL', 'https://img.vietqr.io/image')), '/');
        $bankBin = trim((string) ($payload['bank_bin'] ?? ''));
        $accountNumber = trim((string) ($payload['account_number'] ?? ''));
        $accountName = trim((string) ($payload['account_name'] ?? ''));

        if ($bankBin === '' || $accountNumber === '') {
            throw new InfrastructureException('Bank configuration is missing for VietQR generation.');
        }

        $path = sprintf(
            '%s/%s-%s-%s.png',
            $baseUrl,
            rawurlencode($bankBin),
            rawurlencode($accountNumber),
            rawurlencode((string) ($payload['template'] ?? 'compact2'))
        );
        $query = http_build_query([
            'amount' => $payload['amount'],
            'addInfo' => $payload['description'],
            'accountName' => $accountName,
        ]);

        return $path . '?' . $query;
    }

    public function paymentContext(): array
    {
        $bankBin = trim((string) $this->envService->get('BANK_BIN', ''));
        $accountNumber = trim((string) $this->envService->get('BANK_ACCOUNT_NUMBER', ''));
        $accountName = trim((string) $this->envService->get('BANK_ACCOUNT_NAME', ''));

        if ($bankBin === '' || $accountNumber === '') {
            throw new InfrastructureException('BANK_BIN and BANK_ACCOUNT_NUMBER must be configured.');
        }

        return [
            'bank_bin' => $bankBin,
            'account_number' => $accountNumber,
            'account_name' => $accountName,
            'template' => (string) $this->envService->get('VIETQR_TEMPLATE', 'compact2'),
            'base_url' => (string) $this->envService->get('VIETQR_BASE_URL', 'https://img.vietqr.io/image'),
        ];
    }

    public function buildPaymentInstructions(array $order): array
    {
        $bankAccount = $this->paymentContext();
        $qrPayload = $this->buildVietQrPayload($order, $bankAccount);
        $qrUrl = $this->buildVietQrImageUrl($order, $bankAccount);

        return [
            'provider' => 'vietqr',
            'bank_bin' => $bankAccount['bank_bin'],
            'bank_account_number' => $bankAccount['account_number'],
            'bank_account_name' => $bankAccount['account_name'],
            'transfer_syntax' => $order['transfer_syntax'] ?? '',
            'amount' => $order['total_amount'] ?? 0,
            'qr_image_url' => $qrUrl,
            'qr_payload' => $qrPayload,
        ];
    }

    public function normalizeWebhookPayload(array $payload, array $headers = [], array $query = []): array
    {
        $record = $this->extractPrimaryRecord($payload);
        $provider = $this->detectWebhookProvider($payload, $headers, $query, $record);
        $direction = $this->normalizeDirection($this->firstNonEmptyString([
            $record['transferType'] ?? null,
            $record['transactionType'] ?? null,
            $record['type'] ?? null,
            $record['txnType'] ?? null,
            $payload['transferType'] ?? null,
            $payload['transactionType'] ?? null,
            $payload['type'] ?? null,
            $payload['txnType'] ?? null,
        ]));
        $amount = $this->normalizeAmount($this->firstNumericValue([
            $record['amount'] ?? null,
            $record['transferAmount'] ?? null,
            $record['transfer_amount'] ?? null,
            $record['creditAmount'] ?? null,
            $payload['amount'] ?? null,
            $payload['transferAmount'] ?? null,
            $payload['transfer_amount'] ?? null,
            $payload['creditAmount'] ?? null,
        ]));
        $transferContent = trim($this->firstNonEmptyString([
            $record['content'] ?? null,
            $record['description'] ?? null,
            $record['transferContent'] ?? null,
            $record['transfer_content'] ?? null,
            $record['desc'] ?? null,
            $payload['content'] ?? null,
            $payload['description'] ?? null,
            $payload['transferContent'] ?? null,
            $payload['transfer_content'] ?? null,
            $payload['desc'] ?? null,
        ]));
        $externalId = $this->nullableTrim($this->firstNonEmptyString([
            $record['id'] ?? null,
            $record['transaction_id'] ?? null,
            $record['transactionId'] ?? null,
            $record['referenceCode'] ?? null,
            $record['reference'] ?? null,
            $record['code'] ?? null,
            $record['tid'] ?? null,
            $payload['id'] ?? null,
            $payload['transaction_id'] ?? null,
            $payload['transactionId'] ?? null,
            $payload['referenceCode'] ?? null,
            $payload['reference'] ?? null,
            $payload['code'] ?? null,
            $payload['tid'] ?? null,
        ]));
        $reference = $this->nullableTrim($this->firstNonEmptyString([
            $record['reference'] ?? null,
            $record['referenceCode'] ?? null,
            $record['code'] ?? null,
            $record['tid'] ?? null,
            $payload['reference'] ?? null,
            $payload['referenceCode'] ?? null,
            $payload['code'] ?? null,
            $payload['tid'] ?? null,
        ]));
        $transferSyntax = $this->extractTransferSyntax($transferContent);

        return [
            'provider' => $provider,
            'direction' => $direction,
            'amount' => $amount,
            'transfer_content' => $transferContent,
            'transfer_syntax' => $transferSyntax,
            'external_id' => $externalId,
            'reference' => $reference,
            'event_key' => $this->buildWebhookEventKey(
                $provider,
                $externalId,
                $reference,
                $amount,
                $transferSyntax,
                $transferContent
            ),
            'is_credit' => $direction !== 'out' && $amount > 0,
            'raw' => $payload,
        ];
    }

    public function assertWebhookIsTrusted(string $provider, array $headers = [], array $payload = [], array $query = []): void
    {
        $sepaySecret = trim((string) $this->envService->get('SEPAY_API_KEY', ''));
        $cassoSecret = trim((string) $this->envService->get('CASSO_WEBHOOK_SECRET', ''));

        if ($sepaySecret === '' && $cassoSecret === '') {
            return;
        }

        $providedTokens = $this->extractWebhookTokens($headers, $payload, $query);

        if ($providedTokens === []) {
            if (in_array($provider, ['sepay', 'casso'], true)) {
                throw new ValidationException('Webhook secret is missing.');
            }

            return;
        }

        $expectedSecrets = match ($provider) {
            'sepay' => array_values(array_filter([$sepaySecret])),
            'casso' => array_values(array_filter([$cassoSecret])),
            default => array_values(array_filter([$sepaySecret, $cassoSecret])),
        };

        foreach ($providedTokens as $token) {
            foreach ($expectedSecrets as $secret) {
                if (hash_equals($secret, $token)) {
                    return;
                }
            }
        }

        throw new ValidationException('Webhook authorization failed.');
    }

    public function matchWebhook(array $payload): array
    {
        return $this->normalizeWebhookPayload($payload);
    }

    private function detectWebhookProvider(array $payload, array $headers, array $query, array $record): string
    {
        $candidate = strtolower(trim($this->firstNonEmptyString([
            $query['provider'] ?? null,
            $payload['provider'] ?? null,
            $payload['source'] ?? null,
            $record['provider'] ?? null,
            $record['source'] ?? null,
        ])));

        if ($candidate !== '') {
            return $this->normalizeProvider($candidate);
        }

        $userAgent = strtolower((string) ($this->headerValue($headers, 'user-agent') ?? ''));

        if (str_contains($userAgent, 'casso')) {
            return 'casso';
        }

        if (str_contains($userAgent, 'sepay')) {
            return 'sepay';
        }

        return 'generic';
    }

    private function normalizeProvider(string $provider): string
    {
        if (str_contains($provider, 'sepay')) {
            return 'sepay';
        }

        if (str_contains($provider, 'casso')) {
            return 'casso';
        }

        return 'generic';
    }

    private function normalizeDirection(string $direction): string
    {
        $normalized = strtolower(trim($direction));

        if (in_array($normalized, ['out', 'debit', 'expense', 'withdraw', 'withdrawal'], true)) {
            return 'out';
        }

        return 'in';
    }

    private function normalizeAmount(float|int|string|null $amount): float
    {
        if ($amount === null || $amount === '') {
            return 0.0;
        }

        return round((float) $amount, 2);
    }

    private function extractPrimaryRecord(array $payload): array
    {
        foreach (['data', 'transactions', 'items', 'records'] as $key) {
            $candidate = $payload[$key] ?? null;

            if (!is_array($candidate) || $candidate === []) {
                continue;
            }

            if ($this->isAssoc($candidate)) {
                return $candidate;
            }

            foreach ($candidate as $item) {
                if (is_array($item)) {
                    return $item;
                }
            }
        }

        return $payload;
    }

    private function buildWebhookEventKey(
        string $provider,
        ?string $externalId,
        ?string $reference,
        float $amount,
        ?string $transferSyntax,
        string $transferContent
    ): string {
        return hash('sha256', implode('|', [
            strtolower(trim($provider)),
            $externalId ?? '',
            $reference ?? '',
            number_format($amount, 2, '.', ''),
            $transferSyntax ?? '',
            strtoupper(trim($transferContent)),
        ]));
    }

    private function extractWebhookTokens(array $headers, array $payload, array $query): array
    {
        $tokens = [];

        foreach ([
            'authorization',
            'x-api-key',
            'api-key',
            'apikey',
            'x-webhook-secret',
            'webhook-secret',
            'x-casso-signature',
            'x-casso-secret',
            'x-sepay-api-key',
            'x-sepay-signature',
            'x-signature',
        ] as $headerName) {
            $value = $this->headerValue($headers, $headerName);

            if ($value !== null) {
                $tokens[] = $this->normalizeWebhookToken($value);
            }
        }

        foreach (['secret', 'api_key', 'token', 'signature'] as $key) {
            if (isset($payload[$key])) {
                $tokens[] = $this->normalizeWebhookToken((string) $payload[$key]);
            }

            if (isset($query[$key])) {
                $tokens[] = $this->normalizeWebhookToken((string) $query[$key]);
            }
        }

        return array_values(array_filter(array_unique($tokens), static fn (string $token): bool => $token !== ''));
    }

    private function normalizeWebhookToken(string $token): string
    {
        $normalized = trim($token);

        if (stripos($normalized, 'Bearer ') === 0) {
            return trim(substr($normalized, 7));
        }

        if (stripos($normalized, 'Apikey ') === 0) {
            return trim(substr($normalized, 7));
        }

        return $normalized;
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== strtolower($name)) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            return is_scalar($value) ? trim((string) $value) : null;
        }

        return null;
    }

    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (!is_scalar($candidate)) {
                continue;
            }

            $normalized = trim((string) $candidate);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    private function firstNumericValue(array $candidates): float|int|string|null
    {
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function nullableTrim(string $value): ?string
    {
        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function isAssoc(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }
}

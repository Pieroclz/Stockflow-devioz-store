<?php
declare(strict_types=1);

/** @param array<string,string>|null $env */
function culqi_config(?array $env = null): array
{
    $env ??= $_ENV + $_SERVER;
    $public = trim((string) ($env['CULQI_PUBLIC_KEY'] ?? getenv('CULQI_PUBLIC_KEY') ?: ''));
    $private = trim((string) ($env['CULQI_PRIVATE_KEY'] ?? getenv('CULQI_PRIVATE_KEY') ?: ''));
    $secret = trim((string) ($env['CULQI_WEBHOOK_SECRET'] ?? getenv('CULQI_WEBHOOK_SECRET') ?: ''));
    $mode = strtolower(trim((string) ($env['CULQI_MODE'] ?? getenv('CULQI_MODE') ?: 'test')));
    $publicOk = preg_match('/^pk_(test|live)_[A-Za-z0-9]+$/', $public) === 1;
    $privateOk = preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $private) === 1;
    $prefixMatch = $publicOk && $privateOk && substr($public, 3, 4) === substr($private, 3, 4);
    return [
        'enabled' => $publicOk && $privateOk && $prefixMatch,
        'public_key' => $publicOk ? $public : '',
        'private_key' => $privateOk ? $private : '',
        'webhook_secret' => $secret,
        'mode' => in_array($mode, ['test', 'live'], true) ? $mode : 'test',
    ];
}

function culqi_public_config(array $config): array
{
    return ['enabled' => (bool) ($config['enabled'] ?? false), 'public_key' => (string) ($config['public_key'] ?? ''), 'mode' => (string) ($config['mode'] ?? 'test')];
}

function culqi_verify_charge(array $charge, array $expected): bool
{
    $amount = (int) round(((float) ($expected['amount'] ?? 0)) * 100);
    $approved = ($charge['outcome']['type'] ?? '') === 'venta_exitosa' || ($charge['paid'] ?? false) === true;
    return $approved
        && (int) ($charge['amount'] ?? 0) === $amount
        && strtoupper((string) ($charge['currency_code'] ?? '')) === strtoupper((string) ($expected['currency'] ?? 'PEN'))
        && (string) ($charge['reference_code'] ?? '') === (string) ($expected['reference'] ?? '');
}

/** @param list<string> $seenIds */
function culqi_webhook_seen(array $seenIds, string $eventId): bool
{
    return in_array($eventId, $seenIds, true);
}

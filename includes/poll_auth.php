<?php
declare(strict_types=1);

function qt_poll_raw_input(): string
{
    static $loaded = false;
    static $rawInput = '';

    if (!$loaded) {
        $rawInput = (string)file_get_contents('php://input');
        $loaded = true;
    }

    return $rawInput;
}

function qt_poll_json_input(): ?array
{
    static $decoded = false;
    static $data = null;

    if (!$decoded) {
        $parsed = json_decode(qt_poll_raw_input(), true);
        $data = is_array($parsed) ? $parsed : null;
        $decoded = true;
    }

    return $data;
}

function qt_poll_auth_context(PDO $pdo): array
{
    $token = '';
    foreach ([$_GET['token'] ?? null, $_POST['token'] ?? null] as $candidate) {
        $normalized = trim((string)($candidate ?? ''));
        if ($normalized !== '') {
            $token = $normalized;
            break;
        }
    }

    if ($token === '') {
        $jsonInput = qt_poll_json_input();
        $jsonToken = trim((string)($jsonInput['token'] ?? ''));
        if ($jsonToken !== '') {
            $token = $jsonToken;
        }
    }

    if ($token !== '') {
        $stmt = $pdo->prepare("SELECT user_id FROM sessions WHERE token = ? LIMIT 1");
        $stmt->execute([$token]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return [
            'user_id' => $session ? (int)($session['user_id'] ?? 0) : 0,
            'used_token' => true,
        ];
    }

    return [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'used_token' => false,
    ];
}

function qt_poll_auth_error_message(PDO $pdo): string
{
    $auth = qt_poll_auth_context($pdo);
    return !empty($auth['used_token']) ? 'Invalid session' : 'Not authenticated';
}

function qt_poll_require_user_id(PDO $pdo): int
{
    $auth = qt_poll_auth_context($pdo);
    $userId = (int)($auth['user_id'] ?? 0);

    if ($userId > 0) {
        $_SESSION['user_id'] = $userId;
    }

    return $userId;
}

function qt_poll_is_blank_datetime(mixed $value): bool
{
    $normalized = trim((string)($value ?? ''));
    if ($normalized === '') {
        return true;
    }

    return str_starts_with($normalized, '0000-00-00');
}

function qt_poll_parse_datetime_value(mixed $value, ?DateTimeZone $defaultTimezone = null): ?DateTimeImmutable
{
    if (qt_poll_is_blank_datetime($value)) {
        return null;
    }

    $normalized = trim((string)$value);
    $timezone = $defaultTimezone ?: new DateTimeZone('UTC');

    try {
        return new DateTimeImmutable($normalized, $timezone);
    } catch (Throwable $e) {
        return null;
    }
}

function qt_poll_normalize_input_datetime(mixed $value): ?string
{
    $parsed = qt_poll_parse_datetime_value($value, new DateTimeZone('UTC'));
    if (!$parsed) {
        return null;
    }

    return $parsed
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d H:i:s');
}

function qt_poll_datetime_to_iso8601(mixed $value): ?string
{
    $parsed = qt_poll_parse_datetime_value($value, new DateTimeZone('UTC'));
    if (!$parsed) {
        return null;
    }

    return $parsed
        ->setTimezone(new DateTimeZone('UTC'))
        ->format('Y-m-d\TH:i:s\Z');
}

function qt_poll_has_ended(mixed $endedAtValue): bool
{
    return qt_poll_parse_datetime_value($endedAtValue, new DateTimeZone('UTC')) !== null;
}

function qt_poll_has_expired(mixed $endDateValue): bool
{
    $parsed = qt_poll_parse_datetime_value($endDateValue, new DateTimeZone('UTC'));
    if (!$parsed) {
        return false;
    }

    return $parsed->getTimestamp() <= time();
}

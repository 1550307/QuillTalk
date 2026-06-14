<?php
declare(strict_types=1);

const QT_AI_IMAGE_ATTACHMENT_PREFIX = '__ATTACHMENT__:';
const QT_AI_IMAGE_DEFAULT_SERVICE = 'cloudflare_workers_ai';
const QT_AI_IMAGE_DEFAULT_MODEL = '@cf/stabilityai/stable-diffusion-xl-base-1.0';
const QT_AI_IMAGE_EDIT_DEFAULT_MODEL = '@cf/black-forest-labs/flux-2-klein-9b';
const QT_AI_IMAGE_MAX_PROMPT_LENGTH = 600;
const QT_AI_IMAGE_DEFAULT_WIDTH = 1024;
const QT_AI_IMAGE_DEFAULT_HEIGHT = 1024;
const QT_AI_IMAGE_EDIT_DEFAULT_STRENGTH = 0.40;
const QT_AI_IMAGE_EDIT_DEFAULT_STEPS = 20;
const QT_AI_IMAGE_EDIT_DEFAULT_GUIDANCE = 9.0;
const QT_AI_IMAGE_EDIT_DEFAULT_NEGATIVE_PROMPT = 'duplicate subject, cloned subject, multiple copies, twin, side by side composition, collage, mirrored duplicate, extra bird, extra character, extra object, extra limbs, extra wings, split image, two subjects';
const QT_AI_IMAGE_FLUX_EDIT_MAX_REFERENCE_SIZE = 512;

function qt_ai_image_sanitize_prompt(string $prompt): string
{
    $normalized = preg_replace('/\s+/u', ' ', trim($prompt));
    if (!is_string($normalized)) {
        return '';
    }
    return mb_substr($normalized, 0, QT_AI_IMAGE_MAX_PROMPT_LENGTH);
}

function qt_ai_image_validate_prompt(string $prompt): ?string
{
    $normalizedPrompt = qt_ai_image_sanitize_prompt($prompt);
    if ($normalizedPrompt === '') {
        return 'Type an image prompt first.';
    }

    $sexualContentPatterns = [
        '/\b(sex|sexual|porn|nude|naked|dick|cock|pussy|vagina|penis|breast|tits|ass|fuck|fucking|orgasm|masturbat|horny|aroused|erotic|xxx|nsfw)\b/i',
        '/\b(blow\s*job|hand\s*job|oral\s*sex|anal\s*sex|make\s*love|hook\s*up|one\s*night\s*stand)\b/i',
        '/\b(strip|undress|seduce|intimate)\b/i',
    ];

    foreach ($sexualContentPatterns as $pattern) {
        if (preg_match($pattern, $normalizedPrompt)) {
            return 'Image generation is disabled for sexual or inappropriate prompts.';
        }
    }

    return null;
}

function qt_ai_image_safe_file_stem(string $prompt): string
{
    $stem = preg_replace('/[^A-Za-z0-9._-]+/', '-', strtolower(qt_ai_image_sanitize_prompt($prompt)));
    $stem = trim((string)$stem, '.-_');
    if ($stem === '') {
        $stem = 'ai-image';
    }
    return substr($stem, 0, 60);
}

function qt_ai_image_detect_extension(string $contentType, string $body): string
{
    $normalizedContentType = strtolower(trim($contentType));
    if (str_contains($normalizedContentType, 'png')) {
        return 'png';
    }
    if (str_contains($normalizedContentType, 'webp')) {
        return 'webp';
    }
    if (str_contains($normalizedContentType, 'gif')) {
        return 'gif';
    }
    if (str_contains($normalizedContentType, 'jpeg') || str_contains($normalizedContentType, 'jpg')) {
        return 'jpg';
    }

    if (str_starts_with($body, "\x89PNG")) {
        return 'png';
    }
    if (substr($body, 0, 3) === 'GIF') {
        return 'gif';
    }
    if (substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP') {
        return 'webp';
    }
    if (substr($body, 0, 2) === "\xFF\xD8") {
        return 'jpg';
    }

    return 'jpg';
}

function qt_ai_image_detect_mime(string $extension, string $fallback = ''): string
{
    $normalizedExtension = strtolower(trim($extension));
    return match ($normalizedExtension) {
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        default => ($fallback !== '' ? $fallback : 'image/jpeg'),
    };
}

function qt_ai_image_pollinations_key(): string
{
    return trim((string)(
        getenv('POLLINATIONS_API_KEY')
        ?: ($_SERVER['POLLINATIONS_API_KEY'] ?? '')
        ?: getenv('POLLINATIONS_PUBLISHABLE_KEY')
        ?: ($_SERVER['POLLINATIONS_PUBLISHABLE_KEY'] ?? '')
    ));
}

function qt_ai_image_cloudflare_account_id(): string
{
    return trim((string)(
        getenv('CLOUDFLARE_WORKERS_AI_ACCOUNT_ID')
        ?: ($_SERVER['CLOUDFLARE_WORKERS_AI_ACCOUNT_ID'] ?? '')
        ?: getenv('CLOUDFLARE_ACCOUNT_ID')
        ?: ($_SERVER['CLOUDFLARE_ACCOUNT_ID'] ?? '')
    ));
}

function qt_ai_image_cloudflare_api_token(): string
{
    return trim((string)(
        getenv('CLOUDFLARE_WORKERS_AI_API_TOKEN')
        ?: ($_SERVER['CLOUDFLARE_WORKERS_AI_API_TOKEN'] ?? '')
        ?: getenv('CLOUDFLARE_API_TOKEN')
        ?: ($_SERVER['CLOUDFLARE_API_TOKEN'] ?? '')
    ));
}

function qt_ai_image_cloudflare_model(): string
{
    $configuredModel = trim((string)(
        getenv('CLOUDFLARE_WORKERS_AI_MODEL')
        ?: ($_SERVER['CLOUDFLARE_WORKERS_AI_MODEL'] ?? '')
    ));
    return $configuredModel !== '' ? $configuredModel : QT_AI_IMAGE_DEFAULT_MODEL;
}

function qt_ai_image_cloudflare_edit_model(): string
{
    $configuredModel = trim((string)(
        getenv('CLOUDFLARE_WORKERS_AI_IMAGE_EDIT_MODEL')
        ?: ($_SERVER['CLOUDFLARE_WORKERS_AI_IMAGE_EDIT_MODEL'] ?? '')
        ?: getenv('CLOUDFLARE_WORKERS_AI_EDIT_MODEL')
        ?: ($_SERVER['CLOUDFLARE_WORKERS_AI_EDIT_MODEL'] ?? '')
    ));
    return $configuredModel !== '' ? $configuredModel : QT_AI_IMAGE_EDIT_DEFAULT_MODEL;
}

function qt_ai_image_cloudflare_edit_rest_model(): string
{
    $configuredModel = qt_ai_image_cloudflare_edit_model();
    if (str_starts_with($configuredModel, '@cf/')) {
        return $configuredModel;
    }

    // The PHP backend talks to Workers AI over the hosted /ai/run REST route.
    // Proxied vendor models like openai/gpt-image-1.5 do not share that route shape.
    return QT_AI_IMAGE_EDIT_DEFAULT_MODEL;
}

function qt_ai_image_build_edit_service_prompt(string $originalPrompt, string $editInstructions): string
{
    $normalizedOriginalPrompt = qt_ai_image_sanitize_prompt($originalPrompt);
    $parts = [
        'Use the provided image as the base.',
        $normalizedOriginalPrompt !== ''
            ? 'The original image prompt was: ' . $normalizedOriginalPrompt . '.'
            : '',
        'Preserve the original composition, subject count, pose, framing, scale, and background unless the user explicitly asks to change them.',
        'Apply only this requested change: ' . $editInstructions . '.',
        'Return one coherent edited image and do not duplicate the main subject.'
    ];

    return qt_ai_image_sanitize_prompt(implode(' ', array_values(array_filter($parts))));
}

function qt_ai_image_build_cloudflare_url_for_model(string $model): string
{
    $accountId = qt_ai_image_cloudflare_account_id();
    $model = trim($model);
    if ($accountId === '' || $model === '') {
        return '';
    }

    $modelSegments = array_values(array_filter(
        explode('/', ltrim($model, '/')),
        static fn(string $segment): bool => $segment !== ''
    ));
    $encodedModelPath = implode('/', array_map(
        static fn(string $segment): string => str_replace('%40', '@', rawurlencode($segment)),
        $modelSegments
    ));
    if ($encodedModelPath === '') {
        return '';
    }

    return 'https://api.cloudflare.com/client/v4/accounts/'
        . rawurlencode($accountId)
        . '/ai/run/'
        . $encodedModelPath;
}

function qt_ai_image_build_cloudflare_url(): string
{
    return qt_ai_image_build_cloudflare_url_for_model(qt_ai_image_cloudflare_model());
}

function qt_ai_image_cloudflare_headers(): array
{
    $apiToken = qt_ai_image_cloudflare_api_token();
    if ($apiToken === '') {
        return [];
    }

    return [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json',
        'Accept: application/json,image/*',
    ];
}

function qt_ai_image_build_pollinations_url(string $prompt): string
{
    $query = [
        'model' => QT_AI_IMAGE_DEFAULT_MODEL,
        'nologo' => 'true',
    ];

    return 'https://gen.pollinations.ai/image/'
        . rawurlencode(qt_ai_image_sanitize_prompt($prompt))
        . '?'
        . http_build_query($query);
}

function qt_ai_image_pollinations_headers(): array
{
    $apiKey = qt_ai_image_pollinations_key();
    if ($apiKey === '') {
        return [];
    }

    return ['Authorization: Bearer ' . $apiKey];
}

function qt_ai_image_http_get_binary(string $url, int $timeoutSeconds = 90, array $extraHeaders = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for AI image generation.');
    }

    $responseHeaders = [];
    $requestHeaders = array_values(array_filter(array_merge(
        ['Accept: image/*,application/json,text/plain;q=0.8,*/*;q=0.5'],
        $extraHeaders
    )));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
            $length = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        },
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException($error !== '' ? $error : 'Unknown AI image request failure.');
    }

    return [
        'status' => $status,
        'body' => (string)$body,
        'content_type' => $contentType,
        'headers' => $responseHeaders,
    ];
}

function qt_ai_image_http_post_json(string $url, array $payload, int $timeoutSeconds = 90, array $extraHeaders = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for AI image generation.');
    }

    $responseHeaders = [];
    $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encodedPayload)) {
        throw new RuntimeException('Could not encode the AI image request payload.');
    }

    $requestHeaders = array_values(array_filter(array_merge(
        ['Accept: application/json,image/*'],
        $extraHeaders
    )));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encodedPayload,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
            $length = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        },
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException($error !== '' ? $error : 'Unknown AI image request failure.');
    }

    return [
        'status' => $status,
        'body' => (string)$body,
        'content_type' => $contentType,
        'headers' => $responseHeaders,
    ];
}

function qt_ai_image_http_post_multipart(string $url, array $payload, int $timeoutSeconds = 90, array $extraHeaders = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL is required for AI image generation.');
    }

    $responseHeaders = [];
    $requestHeaders = array_values(array_filter(array_merge(
        ['Accept: application/json,image/*'],
        array_filter(
            $extraHeaders,
            static fn(string $header): bool => stripos($header, 'Content-Type:') !== 0
        )
    )));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$responseHeaders): int {
            $length = strlen($headerLine);
            $parts = explode(':', $headerLine, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        },
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = trim((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException($error !== '' ? $error : 'Unknown AI image request failure.');
    }

    return [
        'status' => $status,
        'body' => (string)$body,
        'content_type' => $contentType,
        'headers' => $responseHeaders,
    ];
}

function qt_ai_image_extract_binary_from_response(array $response): array
{
    $status = (int)($response['status'] ?? 0);
    $body = (string)($response['body'] ?? '');
    $contentType = trim((string)($response['content_type'] ?? ''));
    $binaryBody = '';
    $decodedBody = null;
    $trimmedBody = ltrim($body);

    if ($trimmedBody !== '' && (str_starts_with($trimmedBody, '{') || str_starts_with($trimmedBody, '['))) {
        $decodedCandidate = json_decode($body, true);
        if (is_array($decodedCandidate)) {
            $decodedBody = $decodedCandidate;
        }
    }

    if ($status < 200 || $status >= 300) {
        $errorMessage = qt_ai_image_extract_service_error_message($body);
        throw new RuntimeException($errorMessage !== '' ? $errorMessage : 'Could not generate that image right now.');
    }

    if (is_array($decodedBody)) {
        if (($decodedBody['success'] ?? true) === false) {
            $errorMessage = qt_ai_image_extract_service_error_message($body);
            throw new RuntimeException($errorMessage !== '' ? $errorMessage : 'Could not generate that image right now.');
        }

        $result = is_array($decodedBody['result'] ?? null) ? $decodedBody['result'] : [];
        $imageField = trim((string)($result['image'] ?? ''));
        if ($imageField !== '') {
            if (preg_match('#^https?://#i', $imageField)) {
                $downloaded = qt_ai_image_http_get_binary($imageField, 90);
                $binaryBody = (string)($downloaded['body'] ?? '');
                $contentType = trim((string)($downloaded['content_type'] ?? $contentType));
            } elseif (str_starts_with($imageField, 'data:image/')) {
                $binaryBody = qt_ai_image_decode_base64_image($imageField);
            } else {
                $binaryBody = qt_ai_image_decode_base64_image($imageField);
            }

            if ($contentType === '' || str_contains(strtolower($contentType), 'application/json')) {
                $contentType = 'image/jpeg';
            }
        }
    }

    if ($binaryBody === '' && $body !== '' && str_starts_with(strtolower($contentType), 'image/')) {
        $binaryBody = $body;
    }

    if ($binaryBody === '' && $body !== '' && !is_array($decodedBody)) {
        $binaryBody = $body;
    }

    if ($binaryBody === '') {
        throw new RuntimeException('The image generator returned an empty image.');
    }

    return [
        'content_type' => $contentType,
        'body' => $binaryBody,
    ];
}

function qt_ai_image_run_cloudflare_request(string $model, array $requestPayload, int $timeoutSeconds = 90): array
{
    $cloudflareUrl = qt_ai_image_build_cloudflare_url_for_model($model);
    $authHeaders = qt_ai_image_cloudflare_headers();
    if ($cloudflareUrl === '' || !$authHeaders) {
        throw new RuntimeException('Image generation is not available right now. Please try again later.');
    }

    $response = qt_ai_image_http_post_json(
        $cloudflareUrl,
        $requestPayload,
        $timeoutSeconds,
        $authHeaders
    );

    return qt_ai_image_extract_binary_from_response($response);
}

function qt_ai_image_run_cloudflare_multipart_request(string $model, array $requestPayload, int $timeoutSeconds = 90): array
{
    $cloudflareUrl = qt_ai_image_build_cloudflare_url_for_model($model);
    $authHeaders = qt_ai_image_cloudflare_headers();
    if ($cloudflareUrl === '' || !$authHeaders) {
        throw new RuntimeException('Image generation is not available right now. Please try again later.');
    }

    $response = qt_ai_image_http_post_multipart(
        $cloudflareUrl,
        $requestPayload,
        $timeoutSeconds,
        $authHeaders
    );

    return qt_ai_image_extract_binary_from_response($response);
}

function qt_ai_image_extract_service_error_message(string $body): string
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return '';
    }

    $topLevelMessage = trim((string)($decoded['message'] ?? ''));
    if ($topLevelMessage !== '') {
        return $topLevelMessage;
    }

    $error = $decoded['error'] ?? null;
    if (is_string($error)) {
        return trim($error);
    }

    if (is_array($error)) {
        $nestedMessage = trim((string)($error['message'] ?? $error['error'] ?? $error['detail'] ?? ''));
        if ($nestedMessage !== '') {
            return $nestedMessage;
        }
    }

    $errors = $decoded['errors'] ?? null;
    if (is_array($errors)) {
        foreach ($errors as $errorEntry) {
            if (!is_array($errorEntry)) {
                continue;
            }
            $entryMessage = trim((string)($errorEntry['message'] ?? $errorEntry['error'] ?? $errorEntry['detail'] ?? ''));
            if ($entryMessage !== '') {
                return $entryMessage;
            }
        }
    }

    $detail = trim((string)($decoded['detail'] ?? ''));
    return $detail;
}

function qt_ai_image_decode_base64_image(string $image): string
{
    $normalizedImage = trim($image);
    if ($normalizedImage === '') {
        throw new RuntimeException('The image generator returned an empty image.');
    }

    $normalizedImage = preg_replace('#^data:image/[A-Za-z0-9.+-]+;base64,#', '', $normalizedImage);
    if (!is_string($normalizedImage) || $normalizedImage === '') {
        throw new RuntimeException('The image generator returned an empty image.');
    }

    $decoded = base64_decode(str_replace(' ', '+', $normalizedImage), true);
    if (!is_string($decoded) || $decoded === '') {
        throw new RuntimeException('The image generator returned an invalid image payload.');
    }

    return $decoded;
}

function qt_ai_image_storage_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'chat';
}

function qt_ai_image_project_root(): string
{
    return dirname(__DIR__);
}

function qt_ai_image_resolve_local_source_path(string $attachmentUrl): string
{
    $normalizedPath = preg_replace('/[?#].*$/', '', trim($attachmentUrl));
    if (!is_string($normalizedPath) || $normalizedPath === '') {
        throw new RuntimeException('The source image could not be found.');
    }

    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($normalizedPath, '/\\'));
    $projectRoot = qt_ai_image_project_root();
    $absolutePath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;
    $resolvedPath = realpath($absolutePath);
    $resolvedRoot = realpath($projectRoot);

    if ($resolvedPath === false || $resolvedRoot === false || !str_starts_with($resolvedPath, $resolvedRoot . DIRECTORY_SEPARATOR) || !is_file($resolvedPath)) {
        throw new RuntimeException('The source image file could not be loaded.');
    }

    return $resolvedPath;
}

function qt_ai_image_build_resized_reference_png(string $sourceBinary, int $maxEdge): ?array
{
    if (
        !function_exists('imagecreatefromstring')
        || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled')
        || !function_exists('imagepng')
    ) {
        return null;
    }

    $sourceImage = @imagecreatefromstring($sourceBinary);
    if ($sourceImage === false) {
        return null;
    }

    try {
        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        if ($sourceWidth <= 0 || $sourceHeight <= 0) {
            return null;
        }

        $scale = min(1, $maxEdge / max($sourceWidth, $sourceHeight));
        $targetWidth = max(1, (int)round($sourceWidth * $scale));
        $targetHeight = max(1, (int)round($sourceHeight * $scale));

        $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($targetImage === false) {
            return null;
        }

        try {
            imagealphablending($targetImage, false);
            imagesavealpha($targetImage, true);
            $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
            imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
            imagecopyresampled(
                $targetImage,
                $sourceImage,
                0,
                0,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $sourceWidth,
                $sourceHeight
            );

            $tempBase = tempnam(sys_get_temp_dir(), 'qt-ai-edit-');
            if ($tempBase === false) {
                return null;
            }

            $tempPath = $tempBase . '.png';
            @unlink($tempBase);
            if (!imagepng($targetImage, $tempPath)) {
                @unlink($tempPath);
                return null;
            }

            return [
                'path' => $tempPath,
                'mime' => 'image/png',
                'cleanup_path' => $tempPath,
            ];
        } finally {
            imagedestroy($targetImage);
        }
    } finally {
        imagedestroy($sourceImage);
    }
}

function qt_ai_image_prepare_flux_edit_reference_image(string $sourcePath): array
{
    $sourceBinary = @file_get_contents($sourcePath);
    if (!is_string($sourceBinary) || $sourceBinary === '') {
        throw new RuntimeException('The source image file could not be read.');
    }

    $width = 0;
    $height = 0;
    $mime = '';
    if (function_exists('getimagesizefromstring')) {
        $imageInfo = @getimagesizefromstring($sourceBinary);
        if (is_array($imageInfo)) {
            $width = (int)($imageInfo[0] ?? 0);
            $height = (int)($imageInfo[1] ?? 0);
            $mime = trim((string)($imageInfo['mime'] ?? ''));
        }
    }

    if ($width > 0 && $height > 0 && max($width, $height) > QT_AI_IMAGE_FLUX_EDIT_MAX_REFERENCE_SIZE) {
        $resized = qt_ai_image_build_resized_reference_png($sourceBinary, QT_AI_IMAGE_FLUX_EDIT_MAX_REFERENCE_SIZE);
        if (is_array($resized)) {
            return $resized;
        }
    }

    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = trim((string)@mime_content_type($sourcePath));
    }

    return [
        'path' => $sourcePath,
        'mime' => $mime !== '' ? $mime : 'image/png',
        'cleanup_path' => '',
    ];
}

function qt_ai_image_store_binary(string $prompt, string $contentType, string $body): array
{
    $uploadDir = qt_ai_image_storage_dir();
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create the AI image upload directory.');
    }

    $extension = qt_ai_image_detect_extension($contentType, $body);
    $mime = qt_ai_image_detect_mime($extension, $contentType);
    $fileName = bin2hex(random_bytes(16)) . '.' . $extension;
    $relativePath = 'uploads/chat/' . $fileName;
    $absolutePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (file_put_contents($absolutePath, $body) === false) {
        throw new RuntimeException('Could not save the generated AI image.');
    }

    return [
        'relative_path' => $relativePath,
        'absolute_path' => $absolutePath,
        'mime' => $mime,
        'size' => filesize($absolutePath) ?: strlen($body),
        'download_name' => qt_ai_image_safe_file_stem($prompt) . '.' . $extension,
    ];
}

function qt_ai_image_generate_attachment_payload(string $prompt, array $meta = []): array
{
    return qt_ai_image_generate_attachment_payload_for_prompts($prompt, $prompt, $meta);
}

function qt_ai_image_generate_attachment_payload_for_prompts(string $servicePrompt, string $displayPrompt, array $meta = []): array
{
    $validationError = qt_ai_image_validate_prompt($displayPrompt);
    if ($validationError !== null) {
        throw new RuntimeException($validationError);
    }

    $normalizedServicePrompt = qt_ai_image_sanitize_prompt($servicePrompt);
    $normalizedDisplayPrompt = qt_ai_image_sanitize_prompt($displayPrompt);
    if ($normalizedServicePrompt === '') {
        throw new RuntimeException('Type an image prompt first.');
    }

    $generatedImage = qt_ai_image_run_cloudflare_request(
        qt_ai_image_cloudflare_model(),
        [
            'prompt' => $normalizedServicePrompt,
            'width' => QT_AI_IMAGE_DEFAULT_WIDTH,
            'height' => QT_AI_IMAGE_DEFAULT_HEIGHT,
        ]
    );

    $stored = qt_ai_image_store_binary(
        $normalizedDisplayPrompt !== '' ? $normalizedDisplayPrompt : $normalizedServicePrompt,
        (string)($generatedImage['content_type'] ?? ''),
        (string)($generatedImage['body'] ?? '')
    );

    return [
        'kind' => 'image',
        'url' => $stored['relative_path'],
        'name' => $stored['download_name'],
        'mime' => $stored['mime'],
        'size' => (int)$stored['size'],
        'caption' => '',
        'ai_generated' => true,
        'ai_service' => QT_AI_IMAGE_DEFAULT_SERVICE,
        'ai_model' => qt_ai_image_cloudflare_model(),
        'ai_prompt' => $normalizedServicePrompt,
        'ai_meta' => $meta,
    ];
}

function qt_ai_image_edit_attachment_payload(string $sourceAttachmentUrl, string $originalPrompt, string $editInstructions, array $meta = []): array
{
    $normalizedInstructions = qt_ai_image_sanitize_prompt($editInstructions);
    if ($normalizedInstructions === '') {
        throw new RuntimeException('Type how you want to improvise the image first.');
    }

    $normalizedOriginalPrompt = qt_ai_image_sanitize_prompt($originalPrompt);
    $validationError = qt_ai_image_validate_prompt($normalizedInstructions);
    if ($validationError !== null) {
        throw new RuntimeException($validationError);
    }
    $servicePrompt = qt_ai_image_build_edit_service_prompt($normalizedOriginalPrompt, $normalizedInstructions);

    $sourcePath = qt_ai_image_resolve_local_source_path($sourceAttachmentUrl);
    $preparedReference = qt_ai_image_prepare_flux_edit_reference_image($sourcePath);
    $editModel = qt_ai_image_cloudflare_edit_rest_model();
    try {
        $editedImage = qt_ai_image_run_cloudflare_multipart_request(
            $editModel,
            [
                'prompt' => $servicePrompt,
                'input_image_0' => new CURLFile(
                    (string)$preparedReference['path'],
                    (string)($preparedReference['mime'] ?? 'image/png'),
                    basename((string)$preparedReference['path'])
                ),
                'width' => (string)QT_AI_IMAGE_DEFAULT_WIDTH,
                'height' => (string)QT_AI_IMAGE_DEFAULT_HEIGHT,
                'guidance' => (string)QT_AI_IMAGE_EDIT_DEFAULT_GUIDANCE,
            ]
        );
    } finally {
        $cleanupPath = trim((string)($preparedReference['cleanup_path'] ?? ''));
        if ($cleanupPath !== '') {
            @unlink($cleanupPath);
        }
    }

    $storedPrompt = $normalizedOriginalPrompt !== '' ? $normalizedOriginalPrompt : $normalizedInstructions;

    $stored = qt_ai_image_store_binary(
        $normalizedInstructions,
        (string)($editedImage['content_type'] ?? ''),
        (string)($editedImage['body'] ?? '')
    );

    return [
        'kind' => 'image',
        'url' => $stored['relative_path'],
        'name' => $stored['download_name'],
        'mime' => $stored['mime'],
        'size' => (int)$stored['size'],
        'caption' => '',
        'ai_generated' => true,
        'ai_service' => QT_AI_IMAGE_DEFAULT_SERVICE,
        'ai_model' => $editModel,
        'ai_prompt' => $storedPrompt,
        'ai_meta' => array_merge($meta, [
            'edit_instructions' => $normalizedInstructions,
            'edit_service_prompt' => $servicePrompt,
        ]),
    ];
}

function qt_ai_image_attachment_message(array $payload): string
{
    return QT_AI_IMAGE_ATTACHMENT_PREFIX . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function qt_ai_image_parse_attachment_payload(?string $message): ?array
{
    $raw = trim((string)$message);
    if ($raw === '' || !str_starts_with($raw, QT_AI_IMAGE_ATTACHMENT_PREFIX)) {
        return null;
    }

    $payload = json_decode(substr($raw, strlen(QT_AI_IMAGE_ATTACHMENT_PREFIX)), true);
    return is_array($payload) ? $payload : null;
}

function qt_ai_image_payload_origin_message_id(array $payload): int
{
    $meta = is_array($payload['ai_meta'] ?? null) ? $payload['ai_meta'] : [];
    return max(0, (int)($meta['origin_message_id'] ?? 0));
}

function qt_ai_image_response_sender_card(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            username,
            COALESCE(NULLIF(display_name, ''), username) AS display_name,
            COALESCE(NULLIF(profile_pic, ''), 'images/default-ai.png') AS profile_pic
        FROM users
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'id' => (int)($row['id'] ?? 0),
        'username' => trim((string)($row['username'] ?? 'quilltalk_ai')),
        'display_name' => trim((string)($row['display_name'] ?? 'QuillTalk AI')),
        'profile_pic' => trim((string)($row['profile_pic'] ?? 'images/default-ai.png')),
    ];
}

function qt_ai_image_get_or_create_ai_user_id(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR display_name = ? LIMIT 1");
    $stmt->execute(['quilltalk_ai', 'QuillTalk AI']);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingUser) {
        return (int)$existingUser['id'];
    }

    $insertStmt = $pdo->prepare("
        INSERT INTO users (username, display_name, email, password_hash, bio, profile_pic, created_at, is_passkey_user)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 0)
    ");
    $insertStmt->execute([
        'quilltalk_ai',
        'QuillTalk AI',
        'ai@quilltalk.internal',
        password_hash('ai_system_account_' . bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'I generate images and help with Sidequests inside QuillTalk.',
        'images/default-ai.png',
    ]);

    return (int)$pdo->lastInsertId();
}

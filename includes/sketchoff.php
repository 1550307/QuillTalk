<?php
declare(strict_types=1);

require_once __DIR__ . '/ai_image.php';

const QT_SKETCHOFF_ROUND_SECONDS = 180;
const QT_SKETCHOFF_CANVAS_SIZE = 1024;
const QT_SKETCHOFF_COMPARE_SIZE = 160;
const QT_SKETCHOFF_MATCH_RADIUS = 2;

function qt_sketchoff_prompt_bank(): array
{
    static $prompts = [
        'horse', 'backpack', 'lantern', 'roller skate', 'astronaut helmet', 'teapot', 'penguin', 'castle',
        'hot air balloon', 'camera', 'campfire', 'piano', 'dragon fruit', 'saxophone', 'robot cat', 'treehouse',
        'cactus', 'violin', 'spaceship', 'treasure chest', 'octopus', 'snow globe', 'bicycle', 'crown',
        'telescope', 'frog on a lily pad', 'desert island', 'gumball machine', 'pirate ship', 'watering can',
        'mushroom house', 'guitar', 'jellyfish', 'mailbox', 'butterfly', 'moon rover', 'traffic cone', 'ramen bowl',
        'toolbox', 'koala', 'wizard hat', 'soccer ball', 'cupcake', 'windmill', 'headphones', 'fox',
        'typewriter', 'fountain pen', 'airplane window', 'volcano', 'anchor', 'ice cream truck', 'rocket launch',
        'compass', 'lighthouse', 'coffee mug', 'dinosaur', 'submarine', 'book stack', 'bathtub', 'megaphone',
        'rain boots', 'globe', 'trophy', 'basketball hoop', 'pufferfish', 'giraffe', 'keyhole', 'snowman',
        'parachute', 'bonfire', 'igloo', 'donut', 'crystal ball', 'leaf blower', 'slingshot', 'suitcase',
        'forest cabin', 'ice skate', 'saturn with rings', 'taxi cab', 'binoculars', 'film camera', 'hammock',
        'peacock feather', 'skull candle', 'sneaker', 'shopping cart', 'turntable', 'arcade machine', 'paint palette',
        'banana peel', 'mystery door', 'train station clock', 'sailboat', 'broken umbrella', 'taco truck', 'owl',
        'seahorse', 'beekeeper helmet', 'snowboard', 'vacuum cleaner', 'crane machine', 'planetarium dome',
        'castle tower', 'garden gnome', 'bubble tea', 'neon sign', 'beach chair', 'gift box', 'harp', 'microscope',
        'pancake stack', 'vending machine', 'map', 'fire hydrant', 'carnival ferris wheel', 'wolf howling at the moon',
        'rainbow over mountains', 'robot reading a book', 'cat wearing sunglasses', 'mushroom in a jar',
        'floating island', 'tree shaped like a heart', 'cozy reading nook', 'tiny dragon guarding a key',
        'shoebox apartment', 'boat inside a bottle', 'monster under a bed', 'city skyline at sunrise',
        'alien cactus', 'cloud shaped like a whale', 'banana in sneakers', 'magic potion bottle',
        'haunted house porch', 'camping tent under stars', 'retro television', 'origami crane', 'toy train',
        'skateboard with wings', 'moonlit bridge', 'paper airplane storm', 'shark in a hoodie', 'giant strawberry',
        'astronaut planting a flag', 'pirate duck', 'bookstore cat', 'spacesuit backpack', 'lantern festival'
    ];

    return $prompts;
}

function qt_sketchoff_pick_prompt(): string
{
    $prompts = qt_sketchoff_prompt_bank();
    return $prompts[array_rand($prompts)] ?? 'horse';
}

function qt_sketchoff_reference_prompt_for(string $prompt): string
{
    $normalizedPrompt = qt_ai_image_sanitize_prompt($prompt);
    return 'simple black and white line art sketch of ' . $normalizedPrompt . ', clean centered outline, plain white background, no color, no shading, no text, no frame';
}

function qt_sketchoff_initial_state(): array
{
    return [
        'version' => 1,
        'board' => [],
        'turn' => 'w',
        'winnerColor' => null,
        'resultCode' => null,
        'resultLabel' => null,
        'lastMove' => null,
        'moveCount' => 0,
        'phase' => 'waiting',
        'prompt' => '',
        'referencePrompt' => '',
        'promptStartedAt' => null,
        'promptEndsAt' => null,
        'roundSeconds' => QT_SKETCHOFF_ROUND_SECONDS,
        'referenceImage' => null,
        'whiteSubmission' => null,
        'blackSubmission' => null,
        'whiteScore' => null,
        'blackScore' => null,
    ];
}

function qt_sketchoff_normalize_image_payload(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $url = trim((string)($value['url'] ?? ''));
    if ($url === '' || str_contains($url, '..')) {
        return null;
    }

    $mime = trim((string)($value['mime'] ?? 'image/png'));
    if (!preg_match('#^image/(png|jpeg|jpg|webp)$#i', $mime)) {
        $mime = 'image/png';
    }

    return [
        'kind' => 'image',
        'url' => $url,
        'name' => trim((string)($value['name'] ?? 'sketchoff-image.png')) ?: 'sketchoff-image.png',
        'mime' => $mime,
        'size' => max(0, (int)($value['size'] ?? 0)),
        'caption' => trim((string)($value['caption'] ?? '')),
        'submittedAt' => trim((string)($value['submittedAt'] ?? '')) ?: null,
    ];
}

function qt_sketchoff_normalize_score(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return max(0, min(100, (int)$value));
}

function qt_sketchoff_sanitize_state(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    $base = qt_sketchoff_initial_state();
    $phase = strtolower(trim((string)($value['phase'] ?? $base['phase'])));
    if (!in_array($phase, ['waiting', 'drawing', 'revealed'], true)) {
        $phase = 'waiting';
    }

    $winnerColor = trim((string)($value['winnerColor'] ?? ''));
    if ($winnerColor !== 'w' && $winnerColor !== 'b') {
        $winnerColor = null;
    }

    $resultCode = trim((string)($value['resultCode'] ?? '')) ?: null;
    $resultLabel = trim((string)($value['resultLabel'] ?? '')) ?: null;

    return [
        'version' => 1,
        'board' => [],
        'turn' => ($value['turn'] ?? 'w') === 'b' ? 'b' : 'w',
        'winnerColor' => $winnerColor,
        'resultCode' => $resultCode,
        'resultLabel' => $resultLabel,
        'lastMove' => null,
        'moveCount' => max(0, min(2000, (int)($value['moveCount'] ?? 0))),
        'phase' => $phase,
        'prompt' => qt_ai_image_sanitize_prompt((string)($value['prompt'] ?? '')),
        'referencePrompt' => qt_ai_image_sanitize_prompt((string)($value['referencePrompt'] ?? '')),
        'promptStartedAt' => trim((string)($value['promptStartedAt'] ?? '')) ?: null,
        'promptEndsAt' => trim((string)($value['promptEndsAt'] ?? '')) ?: null,
        'roundSeconds' => max(15, min(300, (int)($value['roundSeconds'] ?? QT_SKETCHOFF_ROUND_SECONDS))),
        'referenceImage' => qt_sketchoff_normalize_image_payload($value['referenceImage'] ?? null),
        'whiteSubmission' => qt_sketchoff_normalize_image_payload($value['whiteSubmission'] ?? null),
        'blackSubmission' => qt_sketchoff_normalize_image_payload($value['blackSubmission'] ?? null),
        'whiteScore' => qt_sketchoff_normalize_score($value['whiteScore'] ?? null),
        'blackScore' => qt_sketchoff_normalize_score($value['blackScore'] ?? null),
    ];
}

function qt_sketchoff_prepare_state_for_active_round(array $state): array
{
    $nextState = qt_sketchoff_sanitize_state($state) ?: qt_sketchoff_initial_state();
    $roundSeconds = max(15, min(300, (int)($nextState['roundSeconds'] ?? QT_SKETCHOFF_ROUND_SECONDS)));
    $startedAt = trim((string)($nextState['promptStartedAt'] ?? ''));
    $endsAt = trim((string)($nextState['promptEndsAt'] ?? ''));

    if ($nextState['prompt'] === '') {
        $nextState['prompt'] = qt_sketchoff_pick_prompt();
    }
    if ($nextState['referencePrompt'] === '') {
        $nextState['referencePrompt'] = qt_sketchoff_reference_prompt_for((string)$nextState['prompt']);
    }
    if ($startedAt === '') {
        $startedAt = date('Y-m-d H:i:s');
        $nextState['promptStartedAt'] = $startedAt;
    }
    if ($endsAt === '') {
        $timestamp = strtotime($startedAt);
        $endsAt = date('Y-m-d H:i:s', ($timestamp !== false ? $timestamp : time()) + $roundSeconds);
        $nextState['promptEndsAt'] = $endsAt;
    }

    $nextState['phase'] = $nextState['resultCode'] ? 'revealed' : 'drawing';
    $nextState['roundSeconds'] = $roundSeconds;
    return $nextState;
}

function qt_sketchoff_submission_key_for_color(string $color): string
{
    return $color === 'b' ? 'blackSubmission' : 'whiteSubmission';
}

function qt_sketchoff_get_submission(array $state, string $color): ?array
{
    $key = qt_sketchoff_submission_key_for_color($color);
    return is_array($state[$key] ?? null) ? $state[$key] : null;
}

function qt_sketchoff_set_submission(array $state, string $color, array $payload): array
{
    $key = qt_sketchoff_submission_key_for_color($color);
    $state[$key] = qt_sketchoff_normalize_image_payload($payload);
    $state['moveCount'] = max(0, (int)($state['moveCount'] ?? 0)) + 1;
    return $state;
}

function qt_sketchoff_time_remaining_ms(array $state): int
{
    $endsAt = trim((string)($state['promptEndsAt'] ?? ''));
    if ($endsAt === '') {
        return QT_SKETCHOFF_ROUND_SECONDS * 1000;
    }

    $deadline = strtotime($endsAt);
    if ($deadline === false) {
        return QT_SKETCHOFF_ROUND_SECONDS * 1000;
    }

    return max(0, ($deadline * 1000) - ((int)round(microtime(true) * 1000)));
}

function qt_sketchoff_scores_reason_label(?int $whiteScore, ?int $blackScore): string
{
    return 'White ' . max(0, min(100, (int)($whiteScore ?? 0))) . '% vs Black ' . max(0, min(100, (int)($blackScore ?? 0))) . '%';
}

function qt_sketchoff_storage_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'sketchoff';
}

function qt_sketchoff_relative_to_absolute_path(string $relativePath): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
}

function qt_sketchoff_store_canvas_data_url(string $dataUrl, string $nameStem = 'sketchoff-drawing'): array
{
    if (!preg_match('#^data:image/(png|jpeg|jpg|webp);base64,#i', $dataUrl)) {
        throw new RuntimeException('Sketchoff only accepts image submissions.');
    }

    $commaIndex = strpos($dataUrl, ',');
    if ($commaIndex === false) {
        throw new RuntimeException('The drawing data was incomplete.');
    }

    $binary = base64_decode(substr($dataUrl, $commaIndex + 1), true);
    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException('Could not decode the drawing.');
    }

    if (!function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
        throw new RuntimeException('GD is required for Sketchoff image handling.');
    }

    $image = @imagecreatefromstring($binary);
    if (!$image) {
        throw new RuntimeException('Could not read the uploaded drawing.');
    }

    $uploadDir = qt_sketchoff_storage_dir();
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        imagedestroy($image);
        throw new RuntimeException('Could not create the Sketchoff upload directory.');
    }

    $fileName = bin2hex(random_bytes(16)) . '.png';
    $relativePath = 'uploads/sketchoff/' . $fileName;
    $absolutePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;

    if (!imagepng($image, $absolutePath, 6)) {
        imagedestroy($image);
        throw new RuntimeException('Could not save the Sketchoff drawing.');
    }
    imagedestroy($image);

    return [
        'kind' => 'image',
        'url' => $relativePath,
        'name' => qt_ai_image_safe_file_stem($nameStem) . '.png',
        'mime' => 'image/png',
        'size' => filesize($absolutePath) ?: strlen($binary),
        'caption' => '',
        'submittedAt' => date('Y-m-d H:i:s'),
    ];
}

function qt_sketchoff_create_square_canvas(int $size)
{
    $canvas = imagecreatetruecolor($size, $size);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    return $canvas;
}

function qt_sketchoff_load_image_resource(string $relativePath)
{
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('GD is required for Sketchoff comparisons.');
    }

    $absolutePath = qt_sketchoff_relative_to_absolute_path($relativePath);
    if (!is_file($absolutePath)) {
        throw new RuntimeException('Sketchoff image could not be found.');
    }

    $binary = file_get_contents($absolutePath);
    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException('Sketchoff image could not be read.');
    }

    $image = @imagecreatefromstring($binary);
    if (!$image) {
        throw new RuntimeException('Sketchoff image could not be decoded.');
    }

    return $image;
}

function qt_sketchoff_render_to_square($image, int $size)
{
    $sourceWidth = max(1, imagesx($image));
    $sourceHeight = max(1, imagesy($image));
    $canvas = qt_sketchoff_create_square_canvas($size);
    $scale = min($size / $sourceWidth, $size / $sourceHeight);
    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));
    $offsetX = (int)floor(($size - $targetWidth) / 2);
    $offsetY = (int)floor(($size - $targetHeight) / 2);
    imagecopyresampled($canvas, $image, $offsetX, $offsetY, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
    return $canvas;
}

function qt_sketchoff_build_ink_mask($image, int $size): array
{
    $mask = [];
    $inkCount = 0;

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            $rgb = imagecolorat($image, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $luma = (int)round(($r * 299 + $g * 587 + $b * 114) / 1000);
            $isInk = $luma < 232 ? 1 : 0;
            $mask[] = $isInk;
            if ($isInk) {
                $inkCount++;
            }
        }
    }

    return [$mask, $inkCount];
}

function qt_sketchoff_has_neighboring_ink(array $mask, int $size, int $x, int $y, int $radius): bool
{
    for ($deltaY = -1 * $radius; $deltaY <= $radius; $deltaY++) {
        $nextY = $y + $deltaY;
        if ($nextY < 0 || $nextY >= $size) {
            continue;
        }
        for ($deltaX = -1 * $radius; $deltaX <= $radius; $deltaX++) {
            $nextX = $x + $deltaX;
            if ($nextX < 0 || $nextX >= $size) {
                continue;
            }
            $index = ($nextY * $size) + $nextX;
            if (!empty($mask[$index])) {
                return true;
            }
        }
    }

    return false;
}

function qt_sketchoff_mask_overlap_ratio(array $sourceMask, array $targetMask, int $size, int $radius): float
{
    $inkCount = 0;
    $matchCount = 0;
    $pixelCount = $size * $size;

    for ($index = 0; $index < $pixelCount; $index++) {
        if (empty($sourceMask[$index])) {
            continue;
        }

        $inkCount++;
        $x = $index % $size;
        $y = (int)floor($index / $size);
        if (qt_sketchoff_has_neighboring_ink($targetMask, $size, $x, $y, $radius)) {
            $matchCount++;
        }
    }

    if ($inkCount <= 0) {
        return 0.0;
    }

    return $matchCount / $inkCount;
}

function qt_sketchoff_compare_image_payloads(array $playerPayload, array $referencePayload): int
{
    $playerImage = qt_sketchoff_load_image_resource((string)($playerPayload['url'] ?? ''));
    $referenceImage = qt_sketchoff_load_image_resource((string)($referencePayload['url'] ?? ''));

    try {
        $playerSquare = qt_sketchoff_render_to_square($playerImage, QT_SKETCHOFF_COMPARE_SIZE);
        $referenceSquare = qt_sketchoff_render_to_square($referenceImage, QT_SKETCHOFF_COMPARE_SIZE);
        [$playerMask, $playerInk] = qt_sketchoff_build_ink_mask($playerSquare, QT_SKETCHOFF_COMPARE_SIZE);
        [$referenceMask, $referenceInk] = qt_sketchoff_build_ink_mask($referenceSquare, QT_SKETCHOFF_COMPARE_SIZE);

        if ($playerInk < 24 || $referenceInk < 24) {
            return 0;
        }

        $precision = qt_sketchoff_mask_overlap_ratio($playerMask, $referenceMask, QT_SKETCHOFF_COMPARE_SIZE, QT_SKETCHOFF_MATCH_RADIUS);
        $recall = qt_sketchoff_mask_overlap_ratio($referenceMask, $playerMask, QT_SKETCHOFF_COMPARE_SIZE, QT_SKETCHOFF_MATCH_RADIUS);
        $score = ($precision * 0.7) + ($recall * 0.3);
        return max(0, min(100, (int)round($score * 100)));
    } finally {
        imagedestroy($playerImage);
        imagedestroy($referenceImage);
        if (isset($playerSquare) && $playerSquare) {
            imagedestroy($playerSquare);
        }
        if (isset($referenceSquare) && $referenceSquare) {
            imagedestroy($referenceSquare);
        }
    }
}

function qt_sketchoff_finalize_game(PDO $pdo, array $game, array $state): array
{
    $gameId = (int)($game['id'] ?? 0);
    $normalizedState = qt_sketchoff_prepare_state_for_active_round($state);
    if ($normalizedState['resultCode']) {
        return $game;
    }

    $referenceImage = is_array($normalizedState['referenceImage'] ?? null) ? $normalizedState['referenceImage'] : null;
    if (!$referenceImage) {
        $referenceImage = qt_ai_image_generate_attachment_payload_for_prompts(
            (string)$normalizedState['referencePrompt'],
            (string)$normalizedState['prompt'],
            [
                'game_id' => $gameId,
                'game_type' => QT_GAME_TYPE_SKETCHOFF,
                'kind' => 'sketchoff_reference',
            ]
        );
        $referenceImage['submittedAt'] = date('Y-m-d H:i:s');
        $normalizedState['referenceImage'] = qt_sketchoff_normalize_image_payload($referenceImage);
    }

    $whiteSubmission = qt_sketchoff_get_submission($normalizedState, 'w');
    $blackSubmission = qt_sketchoff_get_submission($normalizedState, 'b');
    $whiteScore = $whiteSubmission ? qt_sketchoff_compare_image_payloads($whiteSubmission, $referenceImage) : 0;
    $blackScore = $blackSubmission ? qt_sketchoff_compare_image_payloads($blackSubmission, $referenceImage) : 0;

    $winnerColor = null;
    if ($whiteScore > $blackScore) {
        $winnerColor = 'w';
    } elseif ($blackScore > $whiteScore) {
        $winnerColor = 'b';
    }

    $normalizedState['phase'] = 'revealed';
    $normalizedState['winnerColor'] = $winnerColor;
    $normalizedState['resultCode'] = 'sketchoff_similarity';
    $normalizedState['resultLabel'] = qt_sketchoff_scores_reason_label($whiteScore, $blackScore);
    $normalizedState['whiteScore'] = $whiteScore;
    $normalizedState['blackScore'] = $blackScore;

    $statePayload = json_encode($normalizedState, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($statePayload) || $statePayload === '') {
        throw new RuntimeException('Could not save the Sketchoff result.');
    }

    $winnerUserId = null;
    if ($winnerColor === 'w') {
        $winnerUserId = (int)($game['white_user_id'] ?? 0) ?: null;
    } elseif ($winnerColor === 'b') {
        $winnerUserId = (int)($game['black_user_id'] ?? 0) ?: null;
    }

    $updateStmt = $pdo->prepare("
        UPDATE chat_games
        SET
            state_payload = ?,
            status = ?,
            winner_user_id = ?,
            result_code = ?,
            result_label = ?,
            turn_started_at = NULL,
            completed_at = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $completedAt = date('Y-m-d H:i:s');
    $updateStmt->execute([
        $statePayload,
        QT_GAME_STATUS_COMPLETED,
        $winnerUserId,
        'sketchoff_similarity',
        $normalizedState['resultLabel'],
        $completedAt,
        $gameId,
    ]);

    return qt_game_fetch($pdo, $gameId) ?: $game;
}

<?php
// php tests/run.php: no framework needed. Response bodies are the service's real shapes.
spl_autoload_register(function (string $c): void {
    if (str_starts_with($c, 'Emaki\\')) {
        require __DIR__ . '/../src/' . substr($c, 6) . '.php';
    }
});

use Emaki\Emaki;

function check(bool $ok, string $what): void { if (!$ok) { fwrite(STDERR, "FAIL $what\n"); exit(1); } }

$calls = [];
$polls = 0;
$t = function (string $url, $body, string $key) use (&$calls, &$polls): array {
    check($key === 'sk-test', 'bearer key');
    $calls[] = [$url, json_decode(json_encode($body), true)];
    if (str_ends_with($url, '/api/video/ai')) {
        return [200, ['success' => true, 'data' => ['id' => 'vid_1', 'status' => 'generating', 'model' => 'seedance_2_5']]];
    }
    if ($polls++ === 0) {
        return [200, ['success' => true, 'data' => ['id' => 'vid_1', 'status' => 'generating', 'videoUrl' => null, 'failureCode' => null]]];
    }
    return [200, ['success' => true, 'data' => ['id' => 'vid_1', 'status' => 'completed', 'videoUrl' => 'https://cdn.emaki.ai/v.mp4', 'failureCode' => null]]];
};
$e = new Emaki('sk-test', pollEvery: 0, baseUrl: 'https://t', transport: $t);
check($e->generateVideo('a fox in snow') === 'https://cdn.emaki.ai/v.mp4', 'video url');
check($calls[0][0] === 'https://t/api/video/ai' && $calls[0][1]['model'] === 'seedance_2_5', 'submit');
check($calls[0][1]['parameters'] === [], 'empty parameters');
check($calls[1][0] === 'https://t/api/video/status' && $calls[1][1] === ['videoId' => 'vid_1'], 'status body');
check(count($calls) === 3, 'polled until completed');
check(json_encode((object) []) === '{}', 'empty params serialize as object');

$calls = []; $polls = 1;
$e->generateImage('a ramen stall', parameters: ['aspect_ratio' => '16:9']);
check($calls[0][1]['model'] === 'nano_banana_2_lite' && $calls[0][1]['parameters']['aspect_ratio'] === '16:9', 'image body');

$failing = new Emaki('sk-test', pollEvery: 0, baseUrl: 'https://t', transport: fn ($u) => str_ends_with($u, '/api/video/ai')
    ? [200, ['success' => true, 'data' => ['id' => 'vid_2', 'status' => 'generating']]]
    : [200, ['success' => true, 'data' => ['id' => 'vid_2', 'status' => 'failed', 'videoUrl' => null, 'failureCode' => 'provider_error']]]);
try { $failing->generateVideo('x'); check(false, 'failed run must throw'); }
catch (\Emaki\GenerationFailedError $x) { check($x->failureCode === 'provider_error', 'failure code'); }

$credits = new Emaki('sk-test', baseUrl: 'https://t', transport: fn () => [200, ['code' => 0, 'message' => 'ok', 'data' => ['remainingCredits' => 42, 'hasPurchasedCredits' => false]]]);
check($credits->credits() === 42, 'credits');
try {
    (new Emaki('sk-bad', baseUrl: 'https://t', transport: fn () => [200, ['code' => -1, 'message' => 'Invalid API key. Create one at https://emaki.ai/settings/apikeys']]))->credits();
    check(false, 'credits code -1 must throw');
} catch (\Emaki\AuthError) {}

$cases = [
    [401, ['error' => 'Invalid API key. Create one at https://emaki.ai/settings/apikeys'], \Emaki\AuthError::class],
    [402, ['error' => 'Insufficient credits'], \Emaki\QuotaError::class],
    [403, ['error' => 'This model requires a credit purchase'], \Emaki\PlanError::class],
    [409, ['error' => 'Video generation is already in progress'], \Emaki\BusyError::class],
    [429, ['error' => 'cooldown', 'code' => 'content_cooldown'], \Emaki\RejectedError::class],
    [400, ['error' => 'blocked', 'code' => 'content_blocked'], \Emaki\RejectedError::class],
    [400, ['error' => 'duration must be between 4 and 30'], \Emaki\ValidationError::class],
    [502, ['error' => 'upstream'], \Emaki\EmakiException::class],
];
foreach ($cases as [$status, $body, $class]) {
    try {
        (new Emaki('sk-test', baseUrl: 'https://t', transport: fn () => [$status, $body]))->submit('x');
        check(false, "no error for $status");
    } catch (\Emaki\EmakiException $x) {
        check(get_class($x) === $class, "$status -> " . get_class($x));
    }
}
putenv('EMAKI_API_KEY');
try { (new Emaki())->submit('x'); check(false, 'needs key'); } catch (\Emaki\AuthError) {}
echo "all tests passed\n";

// Live smoke (spends nothing): EMAKI_LIVE=1 EMAKI_API_KEY=sk-... php tests/run.php
if (getenv('EMAKI_LIVE')) {
    putenv('EMAKI_API_KEY=' . getenv('EMAKI_LIVE_KEY'));
    echo 'credits() ok: ' . ((new Emaki())->credits() >= 0 ? 'true' : 'false') . "\n";
    try { (new Emaki('sk-not-a-real-key'))->submit('a quiet harbor at dawn', Emaki::MODEL_IMAGE); echo "wrong key accepted?!\n"; exit(1); }
    catch (\Emaki\AuthError $x) { echo "wrong key -> AuthError (HTTP {$x->httpStatus})\n"; }
}

<?php

declare(strict_types=1);

namespace Emaki;

/**
 * Official SDK for emaki (https://emaki.ai), the Japanese-language AI video generator
 * for Seedance 2.5 and Seedance 2.0.
 *
 *     $emaki = new Emaki\Emaki(getenv('EMAKI_API_KEY'));
 *     $clip  = $emaki->generateVideo('a paper lantern drifting down a night river');
 *     $image = $emaki->generateImage('a ramen stall in the rain, neon reflections');
 *     $left  = $emaki->credits();
 *
 * Create a key at https://emaki.ai/settings/apikeys. Every run costs credits on your
 * emaki account, the same price as on the website.
 */
final class Emaki
{
    public const VERSION = '0.1.0';
    public const BASE_URL = 'https://emaki.ai';
    public const KEYS_URL = self::BASE_URL . '/settings/apikeys';
    /** The default video model. */
    public const MODEL_VIDEO = 'seedance_2_5';
    /** The default image model, the cheapest run on the site. */
    public const MODEL_IMAGE = 'nano_banana_2_lite';

    private ?string $apiKey;
    /** @var callable(string,array,string):array{0:int,1:array} */
    private $transport;

    /**
     * @param string|null $apiKey  defaults to the EMAKI_API_KEY environment variable
     * @param float $timeout  seconds generateVideo / generateImage wait for a run (default 15 minutes)
     * @param float $pollEvery  seconds between status checks
     * @param callable|null $transport  fn(string $url, array $body, string $key): array{int, array}, for tests
     */
    public function __construct(
        ?string $apiKey = null,
        private float $timeout = 900.0,
        private float $pollEvery = 5.0,
        private string $baseUrl = self::BASE_URL,
        ?callable $transport = null,
    ) {
        $key = $apiKey ?? (getenv('EMAKI_API_KEY') ?: null);
        $this->apiKey = $key === null ? null : trim($key);
        $this->transport = $transport ?? [self::class, 'http'];
    }

    /**
     * Generate one clip and return its URL.
     *
     * @param array<string,mixed> $parameters e.g. ['duration' => 8, 'resolution' => '720p', 'aspect_ratio' => '9:16', 'image' => 'https://...']
     */
    public function generateVideo(string $prompt, string $model = self::MODEL_VIDEO, array $parameters = []): string
    {
        return $this->waitFor($this->submit($prompt, $model, $parameters));
    }

    /**
     * Generate one image and return its URL.
     *
     * @param array<string,mixed> $parameters e.g. ['aspect_ratio' => '16:9']
     */
    public function generateImage(string $prompt, string $model = self::MODEL_IMAGE, array $parameters = []): string
    {
        return $this->waitFor($this->submit($prompt, $model, $parameters));
    }

    /**
     * Start a run without waiting and return its id; follow it with status().
     *
     * @param array<string,mixed> $parameters
     */
    public function submit(string $prompt, string $model = self::MODEL_VIDEO, array $parameters = []): string
    {
        if (trim($prompt) === '') {
            throw new \InvalidArgumentException('prompt is required');
        }
        $body = ['prompt' => $prompt, 'model' => $model, 'parameters' => (object) $parameters];
        $data = $this->ok($this->post('/api/video/ai', $body))['data'] ?? [];
        $id = $data['id'] ?? null;
        if (!$id) {
            throw new EmakiException('the service returned no run id');
        }
        return (string) $id;
    }

    /**
     * Ask where a run stands.
     *
     * @return array{id:string,status:string,videoUrl:?string,failureCode:?string} plus whatever else the service sent
     */
    public function status(string $id): array
    {
        $data = $this->ok($this->post('/api/video/status', ['videoId' => $id]))['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /** The credits left on the account. Free to call. */
    public function credits(): int
    {
        [$status, $body] = $this->post('/api/user/get-user-credits', (object) []);
        if ($status >= 400) {
            throw self::error($status, $body);
        }
        // This endpoint answers HTTP 200 with code -1 on failure; the status alone reads as success.
        if (($body['code'] ?? null) !== 0) {
            $msg = (string) ($body['message'] ?? 'request refused');
            throw str_contains(strtolower($msg), 'api key') ? new AuthError($msg, $status) : new EmakiException($msg, $status);
        }
        $left = $body['data']['remainingCredits'] ?? null;
        if (!is_int($left)) {
            throw new EmakiException('the service returned no credit balance');
        }
        return $left;
    }

    private function waitFor(string $id): string
    {
        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            usleep((int) ($this->pollEvery * 1e6));
            try {
                $s = $this->status($id);
            } catch (AuthError $e) {
                throw $e;
            } catch (EmakiException) {
                continue; // a dropped poll is not a failed run
            }
            if (($s['status'] ?? '') === 'completed' && !empty($s['videoUrl'])) {
                return (string) $s['videoUrl'];
            }
            if (($s['status'] ?? '') === 'failed') {
                $why = $s['failureCode'] ?? null;
                throw new GenerationFailedError("run $id failed: " . ($why ?? 'unknown'), 200, null, $why);
            }
        }
        throw new TimeoutError("deadline passed; run $id may still finish in your emaki library");
    }

    /** @param array{0:int,1:array} $res */
    private function ok(array $res): array
    {
        [$status, $body] = $res;
        if ($status >= 400) {
            throw self::error($status, $body);
        }
        if (($body['success'] ?? null) === false) {
            throw new EmakiException((string) ($body['error'] ?? 'request refused'), $status, $body['code'] ?? null);
        }
        return $body;
    }

    private static function error(int $status, array $body): EmakiException
    {
        $msg = $body['error'] ?? $body['message'] ?? null;
        if ($status === 401 && $msg === null) {
            $msg = 'an API key is required; create one at ' . self::KEYS_URL;
        }
        return EmakiException::fromHttp($status, $msg === null ? null : (string) $msg, isset($body['code']) ? (string) $body['code'] : null);
    }

    /** @return array{0:int,1:array} */
    private function post(string $path, array|object $body): array
    {
        if (!$this->apiKey) {
            throw new AuthError('an API key is required; create one at ' . self::KEYS_URL);
        }
        return ($this->transport)($this->baseUrl . $path, $body, $this->apiKey);
    }

    /** @return array{0:int,1:array} */
    public static function http(string $url, array|object $body, string $key): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: emaki-php/' . self::VERSION],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) {
            throw new EmakiException($err ?: 'request failed');
        }
        $json = json_decode((string) $raw, true);
        return [$status, is_array($json) ? $json : []];
    }
}

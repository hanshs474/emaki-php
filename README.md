# emaki-php

Official SDK for emaki (emaki.ai), the Japanese-language AI video generator for Seedance 2.5 and Seedance 2.0. Generate [Seedance 2.5](https://emaki.ai/seedance-2-5) and [Seedance 2.0](https://emaki.ai/seedance-2) clips, and images, from PHP with the API key of your [emaki](https://emaki.ai/?utm_source=packagist&utm_medium=package) account.

```php
$emaki = new Emaki\Emaki(getenv('EMAKI_API_KEY'));
$clip = $emaki->generateVideo('a paper lantern drifting down a night river, slow dolly-in');
echo $clip; // URL of the finished MP4
```

Each `generate` call submits the run, waits for it and returns the URL of the finished file. Every run costs credits on your emaki account, the same price as on the website.

## Install

```bash
composer require hanshs474/emaki
```

PHP 8.1+ with the curl and json extensions. No other dependencies.

## API key

Sign up on [emaki.ai](https://emaki.ai/?utm_source=packagist&utm_medium=package), create a key at [emaki.ai/settings/apikeys](https://emaki.ai/settings/apikeys), then pass it or export it:

```bash
export EMAKI_API_KEY=sk-...
```

A key runs on your account exactly like the site does: same models, same credits, and every run shows up in your library on emaki.ai.

## Video

The default model is `seedance_2_5` (5 seconds, 480p, 16:9, with sound). Parameters go in as a map and are passed through as-is:

```php
// Seedance 2.5: 8 seconds, 720p, vertical
$emaki->generateVideo('a street drummer in the rain, handheld', 'seedance_2_5',
    ['duration' => 8, 'resolution' => '720p', 'aspect_ratio' => '9:16']);

// Seedance 2.0
$emaki->generateVideo('a koi pond seen from above, petals falling', 'seedance_2', ['duration' => 5]);
```

| model | what it is | main parameters |
|---|---|---|
| `seedance_2_5` | [Seedance 2.5](https://emaki.ai/seedance-2-5), the default | `duration` 4 to 30 · `resolution` 480p / 720p / 1080p · `aspect_ratio` 16:9, 4:3, 1:1, 3:4, 9:16, 21:9, adaptive · `generate_audio` · `image` · `last_frame_image` |
| `seedance_2` | [Seedance 2.0](https://emaki.ai/seedance-2) | `duration` 4 to 15 · `resolution` 480p / 720p / 1080p · `aspect_ratio` · `generate_audio` · `image` |
| `seedance_2_fast` | Seedance 2.0 Fast | `duration` 4 to 15 · `resolution` 480p / 720p · `aspect_ratio` · `image` |
| `seedance_2_mini` | Seedance 2.0 Mini, the cheapest video model | same as Fast |
| `minimax_h3` | MiniMax H3 | `duration` 5 to 15 · `aspect_ratio` · `image` |
| `veo_3_1` | Veo 3.1 | `resolution` 720p / 1080p / 4k · `aspectRatio` 16:9 / 9:16 · `image` |

### Image to video

Pass `image`, a public https URL, and it becomes the first frame; the prompt then describes the motion. This is the same [image to video](https://emaki.ai/image-to-video) flow as on the site:

```php
$emaki->generateVideo('the camera slowly orbits the statue', 'seedance_2_5',
    ['image' => 'https://example.com/statue.jpg']);
```

On Seedance 2.5, `last_frame_image` pins the closing frame as well.

## Images

The default image model is `nano_banana_2_lite`, the cheapest run on the site:

```php
$url = $emaki->generateImage('a ramen stall in the rain, neon reflections');
$emaki->generateImage('a ukiyo-e style wave over a modern city', 'nano_banana_2', ['aspect_ratio' => '16:9']);
```

Image models: `nano_banana_2_lite`, `nano_banana_2` (`resolution` 1K / 2K / 4K), `seedream_5_lite` (`quality` basic / high / ultra), `gpt_image_2` (`resolution` 1K / 2K / 4K). All take `aspect_ratio`.

## Submit now, collect later

```php
$id = $emaki->submit('a fox trotting through fresh snow', 'seedance_2_5');
$s = $emaki->status($id);   // ['status' => 'generating'|'completed'|'failed', 'videoUrl' => ...]
$left = $emaki->credits();  // free to call
```

`generateVideo` and `generateImage` wait up to 15 minutes, polling every 5 seconds; pass `timeout:` and `pollEvery:` to the constructor to change that.

## Errors worth catching

Every failure is a subclass of `Emaki\EmakiException`:

- `AuthError`: no key, or the key is wrong or deleted (HTTP 401)
- `QuotaError`: not enough credits left for this run (HTTP 402)
- `PlanError`: this model unlocks after the account has bought credits (HTTP 403)
- `BusyError`: another generation on this account is still in progress (HTTP 409)
- `RejectedError`: the content filter refused the prompt, or the account is cooling down after a refusal; reword rather than retry
- `ValidationError`: a parameter is missing or out of range; the message says which
- `GenerationFailedError`: the run was accepted and then failed; the failure code says why
- `TimeoutError`: the wait ran out; the run may still finish in your emaki library

Anything else (a network failure, a 5xx) is the base error with the service's message.

## More on emaki

Clips you make here can be sharpened afterwards with the [AI video upscaler](https://emaki.ai/video-upscaler) on the site.

日本語での使い方は [シーダンス](https://emaki.ai/) へ。

MIT licensed. Made by [emaki](https://emaki.ai/?utm_source=packagist&utm_medium=package).

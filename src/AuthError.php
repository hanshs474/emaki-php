<?php

declare(strict_types=1);

namespace Emaki;

/** No API key, or the key is invalid or deleted (HTTP 401). */
final class AuthError extends EmakiException
{
}

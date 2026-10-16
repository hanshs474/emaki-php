<?php

declare(strict_types=1);

namespace Emaki;

/** A parameter is missing or out of range (HTTP 400); the message says which. */
final class ValidationError extends EmakiException
{
}

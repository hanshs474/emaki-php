<?php

declare(strict_types=1);

namespace Emaki;

/** The content filter refused the prompt, or the account is cooling down after a refusal. Reword rather than retry. */
final class RejectedError extends EmakiException
{
}

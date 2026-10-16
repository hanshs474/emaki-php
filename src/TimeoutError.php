<?php

declare(strict_types=1);

namespace Emaki;

/** The wait ran out; the run may still finish in your emaki library. */
final class TimeoutError extends EmakiException
{
}

<?php

declare(strict_types=1);

namespace Procteye\Exception;

/** 429 - rate limited. Retried automatically up to max_retries. */
class RateLimitException extends ProcteyeException
{
}

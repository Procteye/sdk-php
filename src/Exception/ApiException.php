<?php

declare(strict_types=1);

namespace Procteye\Exception;

/** 5xx - an unexpected error on the Procteye side. Retried automatically. */
class ApiException extends ProcteyeException
{
}

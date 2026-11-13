<?php

declare(strict_types=1);

namespace Procteye\Exception;

/** Network failure, timeout, or aborted request (no HTTP response received). */
class ConnectionException extends ProcteyeException
{
}

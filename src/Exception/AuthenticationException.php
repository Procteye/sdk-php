<?php

declare(strict_types=1);

namespace Procteye\Exception;

/** 401 - the API key is missing, malformed, or revoked. */
class AuthenticationException extends ProcteyeException
{
}

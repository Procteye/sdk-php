<?php

declare(strict_types=1);

namespace Procteye\Exception;

/**
 * 403 - the API key is valid but lacks the scope for this request
 * (error code "insufficient_scope"). Extends AuthenticationException, so an
 * existing `catch (AuthenticationException $e)` still catches it.
 */
class PermissionException extends AuthenticationException
{
}

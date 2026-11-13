<?php

declare(strict_types=1);

namespace Procteye\Exception;

/** Raised by Webhooks::constructEvent when a payload cannot be verified. */
class SignatureVerificationException extends ProcteyeException
{
}

<?php

declare(strict_types=1);

namespace Procteye\Exception;

/** 402 - a billing problem, e.g. getCode() === "insufficient_credits". */
class BillingException extends ProcteyeException
{
}

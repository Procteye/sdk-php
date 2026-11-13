<?php

declare(strict_types=1);

namespace Procteye\Enum;

/** How much evidence a session records. */
enum EvidenceMode: string
{
    case NONE = 'NONE';
    case THUMBNAILS = 'THUMBNAILS';
    case CLIPS = 'CLIPS';
}

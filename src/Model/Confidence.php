<?php

declare(strict_types=1);

namespace LaraInspect\Model;

/**
 * Whether a finding was verified from evidence, or is a risk that needs review.
 */
enum Confidence: string
{
    case Confirmed = 'confirmed';
    case Potential = 'potential';
}

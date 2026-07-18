<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * How a detected hub is rendered relative to the clusters that reference it.
 */
enum HubPolicy: string
{
    case Duplicate = 'duplicate';
    case Separate = 'separate';
    case Exclude = 'exclude';
}

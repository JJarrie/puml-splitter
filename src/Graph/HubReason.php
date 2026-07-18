<?php

declare(strict_types=1);

namespace PumlSplitter\Graph;

/**
 * Why a node was classified as a hub — surfaced in the dry-run plan and used to
 * pick the differentiated default policy for out-only hubs.
 */
enum HubReason: string
{
    case In = 'in';
    case Out = 'out';
    case Both = 'in+out';
    case Forced = 'forced';
}

<?php

declare(strict_types=1);

namespace App\Push;

/**
 * What became of one notification to one subscription. Only Rejected proves the
 * subscription dead; Failed proves nothing either way, so the row is kept and the next
 * real send reaches it or removes it.
 */
enum SendOutcome: string
{
    case Delivered = 'delivered';
    /** the push service answered 404 or 410, or the row can never be delivered */
    case Rejected = 'rejected';
    /** a timeout, any other status, an exception while encrypting or flushing */
    case Failed = 'failed';
}

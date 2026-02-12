<?php

declare(strict_types=1);

namespace WebCalendar\Core\Domain\ValueObject;

/**
 * Enumeration of Blob types (Attachment or Comment).
 */
enum BlobType: string
{
    case ATTACHMENT = 'A';
    case COMMENT = 'C';
}

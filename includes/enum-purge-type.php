<?php
/**
 * Purge type enum for cache purging operations.
 *
 * @package ZuidWestCacheMan
 */

namespace ZW_CACHEMAN_Core;

/**
 * Represents the type of cache purge operation.
 */
enum PurgeType: string
{
    case File = 'file';
    case Prefix = 'prefix';
}

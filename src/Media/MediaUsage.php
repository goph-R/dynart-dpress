<?php

namespace Dynart\Dpress\Media;

/**
 * What uses a media item besides the content the CMS knows about
 *
 * Handed to `media:usage_counted` so a plugin that references the library from a table of its
 * own - a track's audio file, a recipe's picture - can say so. An event cannot answer, so this
 * is the thing a subscriber writes its answer into.
 *
 * It only ever adds: a subscriber cannot make a file that an article shows look unused, which is
 * the same direction a query subscriber is held to. The count is advisory, as the core's is - it
 * warns before a purge and never refuses one.
 */
class MediaUsage {

    protected int $count = 0;

    public function __construct(protected int $mediaId) {}

    public function mediaId(): int {
        return $this->mediaId;
    }

    /** `$count` more things use the item; zero and below add nothing */
    public function add(int $count): void {
        if ($count > 0) {
            $this->count += $count;
        }
    }

    public function count(): int {
        return $this->count;
    }
}

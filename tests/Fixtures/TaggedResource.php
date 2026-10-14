<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;

/** A resource with a list of scalars and a field it yields only for some rows. */
class TaggedResource extends JsonResource
{
    /** Resource renders since the last reset. */
    public static int $renders = 0;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        self::$renders++;

        return [
            'number' => $this->number,
            'tags' => $this->tags ? explode(',', $this->tags) : [],
            'flags' => [true, false],
            'note' => $this->when($this->number === 'N30', 'rare'),
        ];
    }
}

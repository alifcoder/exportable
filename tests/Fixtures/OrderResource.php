<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
final class OrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'prefix-index' => 'PX-'.$this->number,
            'total' => $this->total,
            'created_at' => $this->created_at,
            'owner' => $this->owner === null ? null : ['name' => $this->owner],
            'tags' => ['a', 'b'],
            'lines' => OrderLineResource::collection($this->lines),
        ];
    }
}

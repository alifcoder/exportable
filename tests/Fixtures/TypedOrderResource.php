<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @mixin Order */
final class TypedOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'number' => $this->number,
            'paid' => true,
            'qty' => 3,
            'ratio' => 0.5,
            'total' => '12.50',
            'created_at' => Carbon::parse('2026-03-04 05:06:07'),
            'due' => '2026-03-04',
            'off' => 5,
            'code' => '007',
        ];
    }
}

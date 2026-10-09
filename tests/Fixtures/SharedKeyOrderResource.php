<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
final class SharedKeyOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'number' => $this->number,
            'qty' => $this->total,
            'lines' => $this->lines->map(fn (OrderLine $line): array => ['qty' => $line->qty, 'sku' => $line->sku])->all(),
        ];
    }
}

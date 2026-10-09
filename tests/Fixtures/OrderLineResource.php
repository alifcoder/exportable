<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrderLine */
final class OrderLineResource extends JsonResource
{
    public function toArray($request): array
    {
        return ['sku' => $this->sku, 'qty' => $this->qty];
    }
}

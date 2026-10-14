<?php

declare(strict_types=1);

namespace Alif\Export\Tests\EndToEnd;

use Alif\Export\Concerns\HandlesExport;
use Alif\QueryFilter\Enums\FieldType;
use Alif\QueryFilter\Field;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class Partner extends Model
{
    protected $table = 'e2e_partners';

    public $timestamps = false;

    protected $guarded = [];
}

class Order extends Model
{
    protected $table = 'e2e_orders';

    protected $guarded = [];

    protected $casts = ['is_paid' => 'boolean'];

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }

    /** @return HasMany<Line, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(Line::class, 'order_id')->orderBy('sequence');
    }
}

class Line extends Model
{
    protected $table = 'e2e_lines';

    public $timestamps = false;

    protected $guarded = [];

    /** @return HasMany<Serial, $this> */
    public function serials(): HasMany
    {
        return $this->hasMany(Serial::class, 'line_id')->orderBy('imei');
    }
}

class Serial extends Model
{
    protected $table = 'e2e_serials';

    public $timestamps = false;

    protected $guarded = [];
}

class SerialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return ['imei' => $this->imei];
    }
}

class LineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        return [
            'sequence' => $this->sequence,
            'product' => ['name' => $this->product_name],
            'quantity' => $this->quantity,
            'price' => $this->price,
            'serials' => SerialResource::collection($this->whenLoaded('serials')),
        ];
    }
}

class OrderResource extends JsonResource
{
    /** @var array<string, mixed> Extra fields the tests add to every document. */
    public static array $extra = [];

    /** The number of the order whose rendering throws, or null. */
    public static ?string $explodeAt = null;

    /** @return array<string, mixed> */
    public function toArray($request): array
    {
        if (self::$explodeAt !== null && $this->number === self::$explodeAt) {
            throw new \RuntimeException('boom');
        }

        return [
            'id' => $this->id,
            'number' => $this->number,
            'document_date' => $this->document_date,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'total_amount' => $this->total_amount,
            'is_paid' => $this->is_paid,
            'comment' => $this->comment,
            'partner' => $this->whenLoaded('partner', fn () => ['name' => $this->partner->name, 'region_id' => 7]),
            'partner_id' => $this->partner_id,
            'tags' => $this->comment === 'tagged' ? ['a', 'b'] : [],
            'lines' => LineResource::collection($this->whenLoaded('lines')),
            ...self::$extra,
        ];
    }
}

/** A host's model filter, reduced to what `HandlesExport` reads: params, query, apply, the resource. */
class OrderFilter
{
    use HandlesExport;

    public static string $resource = OrderResource::class;

    /** @var list<string> The sort of the query; the tests change it. */
    public static array $sort = ['id'];

    public static bool $grouped = false;

    public static bool $union = false;

    /** @param array<string, mixed> $parameters */
    public function __construct(private readonly array $parameters = []) {}

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->parameters;
    }

    /** @var array<string, FieldType> The types the filter's fields declare. */
    public static array $declared = [];

    /** @return array<string, Field> */
    protected function fields(): array
    {
        return array_map(fn ($type) => Field::make('x')->type($type), self::$declared);
    }

    public function isShort(): bool
    {
        return false;
    }

    /** @return Builder<Order> */
    public function query(): Builder
    {
        $query = Order::query();

        foreach (self::$sort as $column) {
            $query->orderBy($column);
        }

        if (self::$grouped) {
            $query->groupBy('e2e_orders.id');
        }

        if (self::$union) {
            $query->union(Order::query()->toBase());
        }

        return $query;
    }

    /** @param  Builder<Order>  $query */
    public function apply(Builder $query): void
    {
        $query->with(['partner', 'lines.serials']);

        if (isset($this->parameters['filter']['only_paid'])) {
            $query->where('is_paid', true);
        }

        if (isset($this->parameters['filter']['bindings'])) {
            $query->where('is_paid', false)
                ->where('created_at', '>=', Carbon::parse('2026-01-01 00:00:00'))
                ->whereDate('document_date', '=', new \DateTimeImmutable('2026-03-04'))
                ->whereIn('id', [1, 2, 3, 4, 5, 6, 7, 8])
                ->where('total_amount', '>', 0.5)
                ->where('number', 'like', 'ORD-%')
                ->where(fn ($q) => $q->whereNull('comment')->orWhere('comment', 'is it? yes ?? no'))
                ->whereRaw("coalesce(comment, '') <> ?", ['x?y']);
        }

        if (isset($this->parameters['filter']['moment'])) {
            $query->where('created_at', '=', Carbon::parse('2026-03-04 05:06:07', 'Asia/Tashkent'));
        }

        if (isset($this->parameters['filter']['none'])) {
            $query->whereRaw('1 = 0');
        }
    }
}

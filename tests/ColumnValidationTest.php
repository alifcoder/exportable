<?php

declare(strict_types=1);

namespace Alif\Export\Tests;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\Exceptions\ExportException;
use Alif\Export\Helpers\ExportValidator;
use Alif\Export\Helpers\ResourceRows;
use Alif\Export\Tests\Fixtures\Doc;
use Alif\Export\Tests\Fixtures\TaggedResource;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class ColumnValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        TaggedResource::$renders = 0;

        Schema::create('docs', function (Blueprint $table): void {
            $table->id();
            $table->string('number')->nullable();
            $table->string('tags')->nullable();
            $table->timestamps();
        });

        foreach (range(1, 100) as $i) {
            Doc::query()->create(['number' => 'N'.$i, 'tags' => 'a,b']);
        }
    }

    public function test_a_list_of_scalars_is_one_joined_value(): void
    {
        $read = (new ResourceRows(TaggedResource::class))->read(new Doc(['number' => 'N1', 'tags' => 'a,b']));

        $this->assertSame('a, b', $read['values']['tags']);
        $this->assertSame('true, false', $read['values']['flags']);
    }

    public function test_a_foreign_key_column_is_refused_before_any_row_is_read(): void
    {
        try {
            ExportValidator::validateColumns(
                Doc::query()->orderBy('id'),
                TaggedResource::class,
                ['number' => 'No', 'owner_id' => 'Owner', 'lines' => ['address.region_id' => 'Region']],
            );
            $this->fail('A column ending in _id must be refused.');
        } catch (ExportException $e) {
            $this->assertSame('unknown_column', $e->errorCode);
            $this->assertCount(2, $e->errors['columns']);
            $this->assertSame(0, TaggedResource::$renders);
        }
    }

    public function test_a_field_only_later_rows_yield_is_found(): void
    {
        $query = Doc::query()->orderBy('id');

        $columns = ExportValidator::validateColumns($query, TaggedResource::class, ['number' => 'No', 'note' => 'Note', 'tags' => 'Tags']);

        $this->assertSame(['number', 'note', 'tags'], array_map(fn (ExportColumn $c): string => $c->key, $columns));
    }

    public function test_reading_stops_at_the_chunk_that_shows_the_column(): void
    {
        ExportValidator::validateColumns(Doc::query()->orderBy('id'), TaggedResource::class, ['number' => 'No', 'note' => 'Note']);

        // 20 sampled + one chunk of 50 (N30 is in it) + the blank model.
        $this->assertSame(71, TaggedResource::$renders);
    }

    public function test_a_sample_that_shows_every_column_reads_nothing_more(): void
    {
        ExportValidator::validateColumns(Doc::query()->orderBy('id'), TaggedResource::class, ['number' => 'No']);

        $this->assertSame(21, TaggedResource::$renders);
    }

    public function test_a_key_no_row_yields_is_still_refused(): void
    {
        $this->expectException(ExportException::class);

        ExportValidator::validateColumns(Doc::query()->orderBy('id'), TaggedResource::class, ['number' => 'No', 'ghost' => 'Ghost']);
    }
}

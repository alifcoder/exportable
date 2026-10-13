<?php

declare(strict_types=1);

namespace Alif\Export\Helpers;

use Alif\Export\DTO\Export\ExportColumn;
use Alif\Export\Exceptions\ExportException;
use Alif\QueryFilter\Enums\FieldType;
use Alif\QueryFilter\Field;
use Illuminate\Support\Str;
use ReflectionMethod;

/**
 * Reads the `columns` of a request: flattens the nested lists into ordered {@see ExportColumn}s and checks, per level,
 * what the filter offers. A document column must be one of the filter's `fields()` names, a list column one of the
 * `fields()` names of that list's item filter, and a list one the filter declares in `exportLists()`. A column that ends
 * in `_id` is never let out, even when offered. Every refusal is reported together.
 */
final class RequestedColumns
{
    /** Lists inside lists, counted from the document. */
    private const int MAX_DEPTH = 5;

    /**
     * @param  object  $filter  The document filter; its `fields()` names the document columns.
     * @param  array<int|string, mixed>  $lists  The filter's `exportLists()`.
     */
    public function __construct(private readonly object $filter, private readonly array $lists) {}

    /**
     * The requested columns, in the order the client sent them, each typed by its `Field`'s declared type (null when undeclared).
     *
     * @param  array<string, mixed>  $columns  `key => heading`, and `list => {key => heading, ...}` nested.
     * @return list<ExportColumn>
     *
     * @throws ExportException
     */
    public function resolve(array $columns): array
    {
        $refused = [];
        $list = $this->level($columns, $this->filter, $this->lists, [], $refused);

        if (array_filter($list, fn (ExportColumn $c): bool => $c->groups === []) === []) {
            throw ExportException::needsDocumentColumn();
        }

        if ($refused !== []) {
            throw ExportException::columnsRefused($refused);
        }

        return $list;
    }

    /**
     * One level: its own columns and, recursively, those of the lists below it.
     *
     * @param  array<string, mixed>  $columns
     * @param  array<int|string, mixed>  $lists  The lists this level may hold.
     * @param  list<string>  $groups  The lists above this level.
     * @param  list<string>  $refused  Messages, appended to.
     * @return list<ExportColumn>
     *
     * @throws ExportException
     */
    private function level(array $columns, object $filter, array $lists, array $groups, array &$refused): array
    {
        $fields = $this->fields($filter);
        $result = [];

        foreach ($columns as $key => $value) {
            $key = (string) $key;
            $path = implode('.', [...$groups, $key]);

            if (! is_array($value)) {
                $result[] = $this->column($key, (string) $value, $groups, $fields, $path, $refused);

                continue;
            }

            if (count($groups) >= self::MAX_DEPTH) {
                throw ExportException::nestedTooDeep(self::MAX_DEPTH);
            }

            $entry = $lists[$key] ?? null;
            $itemFilter = is_array($entry) ? ($entry[0] ?? null) : $entry;

            if (! is_string($itemFilter)) {
                // No item filter in exportLists(): nothing to check against. The columns are tried on the resource
                // and an item that does not yield one gets an empty cell (null).
                array_push($result, ...$this->flat($value, [...$groups, $key]));

                continue;
            }

            if (! class_exists($itemFilter)) {
                throw ExportException::invalidRegistration(sprintf('exportLists() names "%s" for the list "%s", which is not a class.', $itemFilter, $key));
            }

            // The item filter's own `[0]` is not a list name: only its string keys are the lists of its items.
            $inner = is_array($entry) ? array_diff_key($entry, [0 => null]) : [];

            array_push($result, ...$this->level($value, new $itemFilter([]), $inner, [...$groups, $key], $refused));
        }

        return $result;
    }

    /**
     * One column of a level: refused when the level does not offer it or it names a foreign key.
     *
     * @param  list<string>  $groups
     * @param  array<string, FieldType|null>  $fields  The level's `fields()` names, each with its declared type.
     * @param  list<string>  $refused
     */
    private function column(string $key, string $label, array $groups, array $fields, string $path, array &$refused): ExportColumn
    {
        if (! array_key_exists($key, $fields)) {
            $refused[] = $this->message('column_not_offered', $path);
        } elseif (str_ends_with(Str::afterLast($key, '.'), '_id')) {
            $refused[] = $this->message('column_not_exportable', $path);
        }

        return new ExportColumn($key, $label, $fields[$key] ?? null, $groups);
    }

    /**
     * The columns of an unoffered list, flattened, so the document-column rule still sees where they sit.
     *
     * @param  array<string, mixed>  $columns
     * @param  list<string>  $groups
     * @return list<ExportColumn>
     *
     * @throws ExportException
     */
    private function flat(array $columns, array $groups): array
    {
        $result = [];

        foreach ($columns as $key => $value) {
            if (! is_array($value)) {
                $result[] = new ExportColumn((string) $key, (string) $value, null, $groups);

                continue;
            }

            if (count($groups) >= self::MAX_DEPTH) {
                throw ExportException::nestedTooDeep(self::MAX_DEPTH);
            }

            array_push($result, ...$this->flat($value, [...$groups, (string) $key]));
        }

        return $result;
    }

    /**
     * The names a filter's `fields()` offers (`name => Field`, or a bare name), each with the cell type its `Field` declares.
     * A bare name or a `Field` without a type has none.
     *
     * @return array<string, FieldType|null>
     */
    private function fields(object $filter): array
    {
        $names = [];

        // Reflection, so an item filter need not share a base class with this one for a protected fields() to be read.
        foreach ((new ReflectionMethod($filter, 'fields'))->invoke($filter) as $name => $field) {
            $names[(string) (is_string($name) ? $name : $field)] = $field instanceof Field ? $field->type : null;
        }

        return $names;
    }

    /** The translated refusal for a column or list path; a missing translation falls back to its key. */
    private function message(string $key, string $path): string
    {
        $text = __('export::messages.'.$key, ['column' => $path]);

        return is_string($text) ? $text : $key;
    }
}

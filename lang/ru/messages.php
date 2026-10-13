<?php

declare(strict_types=1);

return [
    'no_data' => 'Нет данных для экспорта.',
    'too_many_rows' => 'Слишком много строк для экспорта :format (максимум :max).',
    'unknown_column' => 'Неизвестная колонка: ":column".',
    'column_not_offered' => 'Колонка ":column" недоступна в фильтре.',
    'too_many_columns' => 'Можно экспортировать не более :max колонок.',
    'needs_document_column' => 'Хотя бы одна колонка должна относиться к самому документу, а не к списку.',
    'nested_too_deep' => 'Вложенность списков не более :max уровней.',
    'errors' => [
        'row_limit_exceeded' => 'Экспорт превысил лимит строк.',
        'storage_write_failed' => 'Не удалось сохранить файл экспорта.',
        'invalid_column_value' => 'Не удалось записать значение колонки в файл.',
        'invalid_registration' => 'Экспорт настроен неверно.',
        'invalid_configuration' => 'Настройки экспорта заполнены не полностью.',
        'export_failed' => 'Не удалось выполнить экспорт.',
    ],
];

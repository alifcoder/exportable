<?php

declare(strict_types=1);

return [
    'no_data' => 'Нет данных для экспорта.',
    'too_many_rows' => 'Слишком много строк для экспорта :format (максимум :max).',
    'unknown_column' => 'Неизвестная колонка: ":column".',
    'invalid_request' => 'Запрос на экспорт некорректен.',
    'nested_too_deep' => 'Вложенность списков не более :max уровней.',
    'unpageable_query' => 'Запрос экспорта нельзя разбить на страницы: :reason.',
    'unsupported_eager_load' => 'Жадная загрузка ":relation" содержит ограничение; экспорт может повторить только простые жадные загрузки.',
    'resource_failed' => 'Ресурс ":resource" не смог сформировать строку: :reason',
    'row_limit_exceeded' => 'Экспорт превысил лимит в :max строк.',
    'storage_write_failed' => 'Не удалось сохранить файл экспорта: :reason.',
    'invalid_configuration' => 'Отсутствует настройка экспорта "export.:key".',
    'invalid_registration_detail' => 'Экспорт настроен неверно: :reason',
    'registration' => [
        'no_model' => 'В поставленном в очередь экспорте нет "model"; он был поставлен в очередь старой версией.',
        'unsupported_driver' => 'Экспорт не может разбивать на страницы драйвер ":driver".',
    ],
    'unpageable' => [
        'limit_or_offset' => 'в нём есть limit или offset',
        'no_order_by' => 'в нём нет ORDER BY',
    ],
    'storage' => [
        'temp_file' => 'не удалось создать временный файл',
        'cannot_open' => 'не удалось открыть :path',
        'xlsx_reopen' => 'не удалось заново открыть xlsx для завершения',
        'xlsx_save' => 'не удалось сохранить готовый xlsx',
        'xlsx_read' => 'не удалось прочитать лист xlsx',
    ],
    'errors' => [
        'invalid_request' => 'Запрос на экспорт некорректен.',
        'nested_too_deep' => 'Списки вложены слишком глубоко.',
        'unknown_column' => 'Некоторые запрошенные колонки не существуют.',
        'no_data' => 'Нет данных для экспорта.',
        'too_many_rows' => 'Слишком много строк для этого формата экспорта.',
        'unpageable_query' => 'Запрос экспорта нельзя читать частями.',
        'unsupported_eager_load' => 'Запрос экспорта содержит неподдерживаемую жадную загрузку.',
        'resource_failed' => 'Не удалось сформировать строку для экспорта.',
        'row_limit_exceeded' => 'Экспорт превысил лимит строк.',
        'storage_write_failed' => 'Не удалось сохранить файл экспорта.',
        'invalid_registration' => 'Экспорт настроен неверно.',
        'invalid_configuration' => 'Настройки экспорта заполнены не полностью.',
        'export_failed' => 'Не удалось выполнить экспорт.',
    ],
];

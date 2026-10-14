<?php

declare(strict_types=1);

return [
    'no_data' => 'Eksport qilish uchun ma\'lumot yo\'q.',
    'too_many_rows' => ':format eksporti uchun qatorlar juda ko\'p (ko\'pi bilan :max).',
    'unknown_column' => 'Noma\'lum ustun: ":column".',
    'invalid_request' => 'Eksport so\'rovi noto\'g\'ri.',
    'nested_too_deep' => 'Ro\'yxatlar ko\'pi bilan :max darajagacha ichma-ich bo\'lishi mumkin.',
    'unknown_relation' => '":relation" bog\'lanishi mavjud emas.',
    'unpageable_query' => 'Eksport so\'rovini sahifalab bo\'lmaydi: :reason.',
    'unsupported_eager_load' => '":relation" eager load\'iga cheklov qo\'yilgan; eksport faqat oddiy eager load\'larni takrorlay oladi.',
    'resource_failed' => '":resource" resursi qatorni shakllantira olmadi: :reason',
    'row_limit_exceeded' => 'Eksport :max qator chegarasidan oshdi.',
    'storage_write_failed' => 'Eksport faylini saqlab bo\'lmadi: :reason.',
    'invalid_configuration' => 'Eksport sozlamasi yo\'q: "export.:key".',
    'invalid_registration_detail' => 'Eksport noto\'g\'ri sozlangan: :reason',
    'registration' => [
        'no_model' => 'Navbatdagi eksportda "model" yo\'q; u eski versiya tomonidan navbatga qo\'yilgan.',
        'unsupported_driver' => 'Eksport ":driver" drayverini sahifalay olmaydi.',
    ],
    'unpageable' => [
        'group_or_union' => 'unda group yoki union bor',
        'limit_or_offset' => 'unda limit yoki offset bor',
    ],
    'storage' => [
        'temp_file' => 'vaqtinchalik fayl yaratib bo\'lmadi',
        'cannot_open' => ':path faylini ochib bo\'lmadi',
        'xlsx_reopen' => 'xlsx faylini yakunlash uchun qayta ochib bo\'lmadi',
        'xlsx_save' => 'tayyor xlsx faylini saqlab bo\'lmadi',
        'xlsx_read' => 'xlsx varag\'ini o\'qib bo\'lmadi',
    ],
    'errors' => [
        'invalid_request' => 'Eksport so\'rovi noto\'g\'ri.',
        'nested_too_deep' => 'Ro\'yxatlar juda chuqur ichma-ich joylashgan.',
        'unknown_column' => 'Ba\'zi so\'ralgan ustunlar mavjud emas.',
        'no_data' => 'Eksport qilish uchun ma\'lumot yo\'q.',
        'too_many_rows' => 'Bu eksport formati uchun qatorlar juda ko\'p.',
        'unpageable_query' => 'Eksport so\'rovini bo\'laklab o\'qib bo\'lmaydi.',
        'unsupported_eager_load' => 'Eksport so\'rovida qo\'llab-quvvatlanmaydigan eager load bor.',
        'resource_failed' => 'Eksport uchun qatorni shakllantirib bo\'lmadi.',
        'row_limit_exceeded' => 'Eksport qatorlar chegarasidan oshib ketdi.',
        'storage_write_failed' => 'Eksport faylini saqlab bo\'lmadi.',
        'invalid_registration' => 'Eksport noto\'g\'ri sozlangan.',
        'invalid_configuration' => 'Eksport sozlamalari to\'liq emas.',
        'export_failed' => 'Eksportni bajarib bo\'lmadi.',
    ],
];

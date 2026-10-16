<?php

namespace Database\Seeders;

use App\Models\ChainRole;
use App\Models\IjazaType;
use App\Models\SanadType;
use App\Models\TransmissionMode;
use Illuminate\Database\Seeder;

/**
 * قيم ابتدائية للتصنيفات المذكورة في المواصفات، وهي قابلة للإضافة والتعديل والتعطيل من لوحة التحكم.
 * آمن للتشغيل المتكرر والإنتاجي: لا يغيّر ما عدّله المدير ولا يعيد تفعيل ما عطّله.
 */
class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $sets = [
            IjazaType::class => [
                'إجازة الحديث', 'إجازة الفقه', 'إجازة عامة', 'إجازة خاصة',
                'إجازة القراءة', 'إجازة السماع', 'إجازة الرواية',
            ],
            SanadType::class => ['سند كتاب', 'سند إجازة', 'سند قراءة', 'سند سماع'],
            ChainRole::class => ['شيخ', 'راوٍ', 'مجيز', 'مجاز له', 'مؤلف'],
            TransmissionMode::class => [
                'حدثنا', 'أخبرنا', 'أنبأنا', 'سمعت', 'قرأت على', 'أجازني', 'عن',
            ],
        ];

        foreach ($sets as $model => $names) {
            foreach ($names as $index => $name) {
                $model::query()->firstOrCreate(
                    ['name' => $name],
                    ['sort_order' => $index + 1, 'is_active' => true],
                );
            }
        }
    }
}

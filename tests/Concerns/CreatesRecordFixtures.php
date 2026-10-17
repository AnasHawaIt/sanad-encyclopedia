<?php

namespace Tests\Concerns;

use App\Models\Person;
use App\Models\User;

/** مستخدمون بأدوار النظام وسجلات بحالات مختلفة، لاختبارات الأعلام والكتب والمصادر. */
trait CreatesRecordFixtures
{
    use CreatesAccessFixtures;

    protected User $researcher;

    protected User $reader;

    protected User $reviewer;

    protected function setUpRecords(): void
    {
        $this->seedAccess();
        $this->researcher = $this->userWithRole('researcher');
        $this->reader = $this->userWithRole('reader');
        $this->reviewer = $this->userWithRole('reviewer');
    }

    protected function as(User $user): static
    {
        // حارس Sanctum يخزّن أول مستخدم داخل التطبيق نفسه؛ بدون هذا يُعامَل الباحث كقارئ في طلب لاحق بالاختبار ذاته
        app('auth')->forgetGuards();

        return $this->actingAs($user, 'web');
    }

    protected function publishedPerson(array $attributes = []): Person
    {
        return Person::factory()->published()->create($attributes);
    }

    /** @return list<int> معرّفات الصفوف في استجابة قائمة */
    protected function ids($response): array
    {
        return array_column($response->json('data'), 'id');
    }
}

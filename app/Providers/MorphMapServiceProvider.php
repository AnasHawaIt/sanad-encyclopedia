<?php

namespace App\Providers;

use App\Models\Asnad;
use App\Models\AsnadNode;
use App\Models\Attachment;
use App\Models\Book;
use App\Models\BookEdition;
use App\Models\Ijaza;
use App\Models\Person;
use App\Models\PersonMergeRequest;
use App\Models\PersonRelation;
use App\Models\Source;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

/**
 * أسماء ثابتة للعلاقات متعددة الأشكال (المصادر، المرفقات، المراجعات، سجل التدقيق).
 * تُخزَّن في قاعدة البيانات «person» لا اسم الصنف الكامل، فلا تنكسر البيانات عند نقل الأصناف.
 * أي نموذج جديد يدخل في علاقة متعددة الأشكال يجب أن يُضاف هنا.
 */
class MorphMapServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'role' => Role::class,
            'person' => Person::class,
            'person_relation' => PersonRelation::class,
            'person_merge_request' => PersonMergeRequest::class,
            'book' => Book::class,
            'book_edition' => BookEdition::class,
            'ijaza' => Ijaza::class,
            'asnad' => Asnad::class,
            'asnad_node' => AsnadNode::class,
            'source' => Source::class,
            'attachment' => Attachment::class,
        ]);
    }
}

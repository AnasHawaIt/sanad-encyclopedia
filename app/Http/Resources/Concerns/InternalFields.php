<?php

namespace App\Http\Resources\Concerns;

use Illuminate\Http\Request;

trait InternalFields
{
    /** ملاحظات الباحثين ومن أنشأ السجل وعدّله حقول داخلية: لا تظهر لمن لا يرى غير المنشور. */
    protected function isInternal(Request $request): bool
    {
        return (bool) $request->user()?->can('records.view_unpublished');
    }

    /** @return array{value:string,label:string} */
    protected function status(\BackedEnum $enum): array
    {
        return ['value' => $enum->value, 'label' => $enum->label()];
    }
}

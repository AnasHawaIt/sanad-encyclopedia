<?php

return [
    'array' => 'حقل :attribute يجب أن يكون قائمة.',
    'boolean' => 'حقل :attribute يجب أن يكون صح أو خطأ.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'email' => 'حقل :attribute يجب أن يكون بريدًا إلكترونيًّا صحيحًا.',
    'exists' => 'القيمة المحددة في :attribute غير موجودة.',
    'in' => 'القيمة المحددة في :attribute غير صالحة.',
    'integer' => 'حقل :attribute يجب أن يكون عددًا صحيحًا.',
    'max' => [
        'numeric' => 'حقل :attribute يجب ألا يتجاوز :max.',
        'string' => 'حقل :attribute يجب ألا يتجاوز :max حرفًا.',
        'array' => 'حقل :attribute يجب ألا يحوي أكثر من :max عنصرًا.',
    ],
    'min' => [
        'numeric' => 'حقل :attribute يجب ألا يقل عن :min.',
        'string' => 'حقل :attribute يجب ألا يقل عن :min أحرف.',
        'array' => 'حقل :attribute يجب أن يحوي :min عناصر على الأقل.',
    ],
    'regex' => 'صيغة :attribute غير صحيحة.',
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'حقل :attribute يجب أن يكون نصًّا.',
    'unique' => 'قيمة :attribute مستخدمة من قبل (وقد تكون لحساب محذوف يمكن استعادته).',
    'password' => [
        'letters' => 'حقل :attribute يجب أن يحوي حرفًا واحدًا على الأقل.',
        'mixed' => 'حقل :attribute يجب أن يحوي حرفًا كبيرًا وحرفًا صغيرًا.',
        'numbers' => 'حقل :attribute يجب أن يحوي رقمًا واحدًا على الأقل.',
        'symbols' => 'حقل :attribute يجب أن يحوي رمزًا واحدًا على الأقل.',
        'uncompromised' => 'قيمة :attribute ظهرت في تسريب بيانات، اختر غيرها.',
    ],
    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'label' => 'التسمية',
        'roles' => 'الأدوار',
        'permissions' => 'الصلاحيات',
        'is_active' => 'الحالة',
    ],
];

<?php

/**
 * كتالوج الصلاحيات والأدوار. هذا الملف هو المصدر الوحيد لأسماء الصلاحيات؛
 * يقرؤه RolesAndPermissionsSeeder ولا تُكتب أسماء الصلاحيات نصًّا في أي مكان آخر.
 * اسم الصلاحية = «المجموعة.الإجراء» مثل persons.update.
 */

$crud = ['view' => 'عرض', 'create' => 'إضافة', 'update' => 'تعديل', 'delete' => 'أرشفة/حذف'];

return [
    'admin_role' => 'admin',

    // أدوار النظام: لا تُحذف ولا يُعاد تسميتها (يمكن تعديل صلاحياتها عدا admin).
    'protected_roles' => ['admin', 'researcher', 'reviewer', 'publisher', 'reader'],

    'groups' => [
        'persons' => ['label' => 'الأعلام (الرواة والمشايخ)', 'actions' => $crud],
        'books' => ['label' => 'الكتب', 'actions' => $crud],
        'ijazas' => ['label' => 'الإجازات', 'actions' => $crud],
        'asnads' => ['label' => 'الأسانيد', 'actions' => $crud],
        'sources' => ['label' => 'المصادر والمراجع', 'actions' => $crud],
        'lookups' => ['label' => 'القوائم المرجعية', 'actions' => ['view' => 'عرض', 'manage' => 'إدارة']],
        'records' => ['label' => 'ظهور السجلات', 'actions' => ['view_unpublished' => 'رؤية غير المنشور']],
        'reviews' => ['label' => 'المراجعة', 'actions' => ['submit' => 'إرسال للمراجعة', 'decide' => 'قبول/رد المراجعة']],
        'publishing' => ['label' => 'النشر', 'actions' => ['publish' => 'نشر وإلغاء نشر']],
        'merges' => ['label' => 'دمج الأعلام', 'actions' => ['request' => 'طلب دمج', 'approve' => 'اعتماد دمج', 'revert' => 'التراجع عن دمج']],
        'exports' => ['label' => 'التصدير', 'actions' => ['create' => 'تصدير']],
        'audit' => ['label' => 'سجل التدقيق', 'actions' => ['view' => 'عرض']],
        'users' => ['label' => 'المستخدمون', 'actions' => ['view' => 'عرض', 'manage' => 'إدارة']],
        'roles' => ['label' => 'الأدوار والصلاحيات', 'actions' => ['view' => 'عرض', 'manage' => 'إدارة']],
    ],

    // '*' = كل الصلاحيات (يُعاد مزامنتها مع كل تشغيل للـ seeder).
    // بقية الأدوار تُمنح صلاحياتها الافتراضية عند إنشائها فقط، فلا يُمحى تخصيص المدير عند إعادة التشغيل.
    'roles' => [
        'admin' => ['label' => 'مدير النظام', 'permissions' => '*'],
        'researcher' => ['label' => 'باحث', 'permissions' => [
            'persons.view', 'persons.create', 'persons.update',
            'books.view', 'books.create', 'books.update',
            'ijazas.view', 'ijazas.create', 'ijazas.update',
            'asnads.view', 'asnads.create', 'asnads.update',
            'sources.view', 'sources.create', 'sources.update',
            'lookups.view', 'records.view_unpublished', 'reviews.submit',
            'merges.request', 'exports.create',
        ]],
        'reviewer' => ['label' => 'مراجع', 'permissions' => [
            'persons.view', 'books.view', 'ijazas.view', 'asnads.view', 'sources.view',
            'lookups.view', 'records.view_unpublished', 'reviews.decide',
            'merges.approve', 'audit.view', 'exports.create',
        ]],
        'publisher' => ['label' => 'ناشر', 'permissions' => [
            'persons.view', 'books.view', 'ijazas.view', 'asnads.view', 'sources.view',
            'lookups.view', 'records.view_unpublished', 'publishing.publish', 'exports.create',
        ]],
        'reader' => ['label' => 'قارئ', 'permissions' => [
            'persons.view', 'books.view', 'ijazas.view', 'asnads.view', 'sources.view', 'lookups.view',
        ]],
    ],
];

<?php

use App\Http\Controllers\Api\V1\AsnadAnalysisController;
use App\Http\Controllers\Api\V1\AsnadComparisonController;
use App\Http\Controllers\Api\V1\AsnadController;
use App\Http\Controllers\Api\V1\AsnadNodeController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookController;
use App\Http\Controllers\Api\V1\BookEditionController;
use App\Http\Controllers\Api\V1\ExportController;
use App\Http\Controllers\Api\V1\IjazaController;
use App\Http\Controllers\Api\V1\LinkController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\LookupSetController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\PersonMergeController;
use App\Http\Controllers\Api\V1\PersonTeacherController;
use App\Http\Controllers\Api\V1\ReferenceController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:sanctum', EnsureUserIsActive::class])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');

        Route::middleware('can:users.view')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
        });
        Route::middleware('can:users.manage')->group(function () {
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::put('users/{user}', [UserController::class, 'update'])->whereNumber('user')->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->name('users.destroy');
            Route::post('users/{user}/restore', [UserController::class, 'restore'])->whereNumber('user')->name('users.restore');
        });

        Route::middleware('can:roles.view')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/{role}', [RoleController::class, 'show'])->whereNumber('role')->name('roles.show');
            Route::get('permissions', [PermissionController::class, 'index'])->name('permissions.index');
        });
        Route::middleware('can:roles.manage')->group(function () {
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('roles.destroy');
        });

        // ---- المرحلة 4: الأعلام والكتب والمصادر ----

        Route::middleware('can:lookups.view')->group(function () {
            Route::get('lookups/options', [LookupController::class, 'options'])->name('lookups.options');
            Route::get('lookups/categories', [LookupController::class, 'categories'])->name('lookups.categories');
        });
        Route::middleware('can:lookups.manage')->group(function () {
            Route::post('lookups/categories', [LookupController::class, 'storeCategory'])->name('lookups.categories.store');
            Route::put('lookups/categories/{category}', [LookupController::class, 'updateCategory'])->whereNumber('category')->name('lookups.categories.update');
        });

        Route::middleware('can:persons.view')->group(function () {
            Route::get('persons', [PersonController::class, 'index'])->name('persons.index');
            Route::get('persons/{person}', [PersonController::class, 'show'])->whereNumber('person')->name('persons.show');
            Route::get('persons/{person}/similar', [PersonController::class, 'similar'])->whereNumber('person')->name('persons.similar');
            Route::get('persons/{person}/relations', [PersonTeacherController::class, 'index'])->whereNumber('person')->name('persons.relations.index');
        });
        Route::post('persons', [PersonController::class, 'store'])->middleware('can:persons.create')->name('persons.store');
        Route::middleware('can:persons.update')->group(function () {
            Route::put('persons/{person}', [PersonController::class, 'update'])->whereNumber('person')->name('persons.update');
            Route::post('persons/{person}/relations', [PersonTeacherController::class, 'store'])->whereNumber('person')->name('persons.relations.store');
            Route::delete('persons/{person}/relations/{relation}', [PersonTeacherController::class, 'destroy'])->whereNumber(['person', 'relation'])->name('persons.relations.destroy');
        });
        Route::middleware('can:persons.delete')->group(function () {
            Route::delete('persons/{person}', [PersonController::class, 'destroy'])->whereNumber('person')->name('persons.destroy');
            Route::post('persons/{person}/restore', [PersonController::class, 'restore'])->whereNumber('person')->name('persons.restore');
        });

        Route::middleware('can:books.view')->group(function () {
            Route::get('books', [BookController::class, 'index'])->name('books.index');
            Route::get('books/{book}', [BookController::class, 'show'])->whereNumber('book')->name('books.show');
        });
        Route::post('books', [BookController::class, 'store'])->middleware('can:books.create')->name('books.store');
        Route::middleware('can:books.update')->group(function () {
            Route::put('books/{book}', [BookController::class, 'update'])->whereNumber('book')->name('books.update');
            Route::post('books/{book}/editions', [BookEditionController::class, 'store'])->whereNumber('book')->name('books.editions.store');
            Route::put('books/{book}/editions/{edition}', [BookEditionController::class, 'update'])->whereNumber(['book', 'edition'])->name('books.editions.update');
            Route::delete('books/{book}/editions/{edition}', [BookEditionController::class, 'destroy'])->whereNumber(['book', 'edition'])->name('books.editions.destroy');
        });
        Route::middleware('can:books.delete')->group(function () {
            Route::delete('books/{book}', [BookController::class, 'destroy'])->whereNumber('book')->name('books.destroy');
            Route::post('books/{book}/restore', [BookController::class, 'restore'])->whereNumber('book')->name('books.restore');
        });

        Route::middleware('can:sources.view')->group(function () {
            Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
            Route::get('sources/{source}', [SourceController::class, 'show'])->whereNumber('source')->name('sources.show');
            foreach (['persons' => 'person', 'books' => 'book', 'asnads' => 'asnad', 'ijazas' => 'ijaza'] as $prefix => $type) {
                Route::get("{$prefix}/{entity}/references", [ReferenceController::class, 'index'])->defaults('type', $type)->whereNumber('entity')->name("{$prefix}.references.index");
            }
        });
        Route::post('sources', [SourceController::class, 'store'])->middleware('can:sources.create')->name('sources.store');
        Route::middleware('can:sources.update')->group(function () {
            Route::put('sources/{source}', [SourceController::class, 'update'])->whereNumber('source')->name('sources.update');
            foreach (['persons' => 'person', 'books' => 'book', 'asnads' => 'asnad', 'ijazas' => 'ijaza'] as $prefix => $type) {
                Route::post("{$prefix}/{entity}/references", [ReferenceController::class, 'store'])->defaults('type', $type)->whereNumber('entity')->name("{$prefix}.references.store");
                Route::put("{$prefix}/{entity}/references/{reference}", [ReferenceController::class, 'update'])->defaults('type', $type)->whereNumber(['entity', 'reference'])->name("{$prefix}.references.update");
                Route::delete("{$prefix}/{entity}/references/{reference}", [ReferenceController::class, 'destroy'])->defaults('type', $type)->whereNumber(['entity', 'reference'])->name("{$prefix}.references.destroy");
            }
        });
        Route::middleware('can:reviews.decide')->group(function () {
            foreach (['persons' => 'person', 'books' => 'book', 'asnads' => 'asnad', 'ijazas' => 'ijaza'] as $prefix => $type) {
                Route::put("{$prefix}/{entity}/references/{reference}/status", [ReferenceController::class, 'status'])->defaults('type', $type)->whereNumber(['entity', 'reference'])->name("{$prefix}.references.status");
            }
        });
        Route::middleware('can:sources.delete')->group(function () {
            Route::delete('sources/{source}', [SourceController::class, 'destroy'])->whereNumber('source')->name('sources.destroy');
            Route::post('sources/{source}/restore', [SourceController::class, 'restore'])->whereNumber('source')->name('sources.restore');
        });

        // ---- المرحلة 8: المراجعة والنشر والدمج والتدقيق ----

        $types = ['persons' => 'person', 'books' => 'book', 'asnads' => 'asnad', 'ijazas' => 'ijaza'];
        Route::middleware('can:records.view_unpublished')->group(function () use ($types) {
            foreach ($types as $prefix => $type) {
                Route::get("{$prefix}/{entity}/reviews", [ReviewController::class, 'history'])->defaults('type', $type)->whereNumber('entity')->name("{$prefix}.reviews.index");
            }
        });
        Route::middleware('can:reviews.submit')->group(function () use ($types) {
            foreach ($types as $prefix => $type) {
                Route::post("{$prefix}/{entity}/submit", [ReviewController::class, 'transition'])->defaults('type', $type)->defaults('action', 'submit')->whereNumber('entity')->name("{$prefix}.review.submit");
            }
        });
        Route::middleware('can:reviews.decide')->group(function () use ($types) {
            Route::get('reviews/queue', [ReviewController::class, 'queue'])->name('reviews.queue');
            foreach ($types as $prefix => $type) {
                foreach (['approve' => 'approve', 'reject' => 'reject', 'send-back' => 'send_back'] as $path => $action) {
                    Route::post("{$prefix}/{entity}/{$path}", [ReviewController::class, 'transition'])->defaults('type', $type)->defaults('action', $action)->whereNumber('entity')->name("{$prefix}.review.{$action}");
                }
            }
        });
        Route::middleware('can:publishing.publish')->group(function () use ($types) {
            foreach ($types as $prefix => $type) {
                foreach (['publish', 'unpublish'] as $action) {
                    Route::post("{$prefix}/{entity}/{$action}", [ReviewController::class, 'transition'])->defaults('type', $type)->defaults('action', $action)->whereNumber('entity')->name("{$prefix}.review.{$action}");
                }
            }
        });

        Route::middleware('can:merges.view')->group(function () {
            Route::get('merge-requests', [PersonMergeController::class, 'index'])->name('merges.index');
            Route::get('merge-requests/{mergeRequest}', [PersonMergeController::class, 'show'])->whereNumber('mergeRequest')->name('merges.show');
        });
        Route::middleware('can:merges.request')->group(function () {
            Route::post('merge-requests', [PersonMergeController::class, 'store'])->name('merges.store');
            Route::delete('merge-requests/{mergeRequest}', [PersonMergeController::class, 'withdraw'])->whereNumber('mergeRequest')->name('merges.withdraw');
        });
        Route::middleware('can:merges.approve')->group(function () {
            Route::post('merge-requests/{mergeRequest}/approve', [PersonMergeController::class, 'approve'])->whereNumber('mergeRequest')->name('merges.approve');
            Route::post('merge-requests/{mergeRequest}/reject', [PersonMergeController::class, 'reject'])->whereNumber('mergeRequest')->name('merges.reject');
        });
        Route::post('merge-requests/{mergeRequest}/revert', [PersonMergeController::class, 'revert'])->whereNumber('mergeRequest')->middleware('can:merges.revert')->name('merges.revert');

        // التصدير (المرحلة 9): قراءة فقط، بقيد رؤية المستخدم، ويُسجَّل في التدقيق
        Route::middleware('can:exports.create')->prefix('exports')->group(function () {
            Route::get('asnads', [ExportController::class, 'asnads'])->name('exports.asnads');
            Route::get('persons', [ExportController::class, 'persons'])->name('exports.persons');
            Route::get('ijazas', [ExportController::class, 'ijazas'])->name('exports.ijazas');
        });

        Route::middleware('can:audit.view')->group(function () {
            Route::get('audit', [AuditController::class, 'index'])->name('audit.index');
            Route::get('audit/events', [AuditController::class, 'events'])->name('audit.events');
        });

        // ---- المرحلة 5: الأسانيد والإجازات ----

        Route::middleware('can:lookups.view')->group(function () {
            Route::get('lookups/sets/{set}', [LookupSetController::class, 'index'])->name('lookups.sets.index');
        });
        Route::middleware('can:lookups.manage')->group(function () {
            Route::post('lookups/sets/{set}', [LookupSetController::class, 'store'])->name('lookups.sets.store');
            Route::put('lookups/sets/{set}/{id}', [LookupSetController::class, 'update'])->whereNumber('id')->name('lookups.sets.update');
        });

        Route::middleware('can:asnads.view')->group(function () {
            Route::get('asnads', [AsnadController::class, 'index'])->name('asnads.index');
            Route::get('asnads/compare', [AsnadComparisonController::class, 'compare'])->name('asnads.compare');
            Route::get('asnads/tree', [AsnadComparisonController::class, 'tree'])->name('asnads.tree');
            Route::get('asnads/summary', [AsnadAnalysisController::class, 'summary'])->name('asnads.summary');
            Route::get('asnads/{asnad}', [AsnadController::class, 'show'])->whereNumber('asnad')->name('asnads.show');
            Route::get('asnads/{asnad}/counts', [AsnadAnalysisController::class, 'counts'])->whereNumber('asnad')->name('asnads.counts');
        });
        Route::middleware(['can:asnads.view', 'can:books.view'])->group(function () {
            Route::get('books/{book}/asnads/compare', [AsnadComparisonController::class, 'bookCompare'])->whereNumber('book')->name('books.asnads.compare');
            Route::get('books/{book}/asnads/tree', [AsnadComparisonController::class, 'bookTree'])->whereNumber('book')->name('books.asnads.tree');
        });
        Route::middleware('can:asnads.create')->group(function () {
            Route::post('asnads', [AsnadController::class, 'store'])->name('asnads.store');
            Route::post('asnads/suggest-split', [AsnadAnalysisController::class, 'suggestSplit'])->name('asnads.suggest-split');
        });
        Route::middleware('can:asnads.update')->group(function () {
            Route::put('asnads/{asnad}', [AsnadController::class, 'update'])->whereNumber('asnad')->name('asnads.update');
            Route::post('asnads/{asnad}/nodes', [AsnadNodeController::class, 'store'])->whereNumber('asnad')->name('asnads.nodes.store');
            Route::put('asnads/{asnad}/nodes/order', [AsnadNodeController::class, 'reorder'])->whereNumber('asnad')->name('asnads.nodes.reorder');
            Route::put('asnads/{asnad}/nodes/{node}', [AsnadNodeController::class, 'update'])->whereNumber(['asnad', 'node'])->name('asnads.nodes.update');
            Route::post('asnads/{asnad}/nodes/{node}/move', [AsnadNodeController::class, 'move'])->whereNumber(['asnad', 'node'])->name('asnads.nodes.move');
            Route::delete('asnads/{asnad}/nodes/{node}', [AsnadNodeController::class, 'destroy'])->whereNumber(['asnad', 'node'])->name('asnads.nodes.destroy');
        });
        Route::middleware('can:asnads.delete')->group(function () {
            Route::delete('asnads/{asnad}', [AsnadController::class, 'destroy'])->whereNumber('asnad')->name('asnads.destroy');
            Route::post('asnads/{asnad}/restore', [AsnadController::class, 'restore'])->whereNumber('asnad')->name('asnads.restore');
        });

        Route::middleware('can:ijazas.view')->group(function () {
            Route::get('ijazas', [IjazaController::class, 'index'])->name('ijazas.index');
            Route::get('ijazas/{ijaza}', [IjazaController::class, 'show'])->whereNumber('ijaza')->name('ijazas.show');
        });
        Route::post('ijazas', [IjazaController::class, 'store'])->middleware('can:ijazas.create')->name('ijazas.store');
        Route::middleware('can:ijazas.update')->group(function () {
            Route::put('ijazas/{ijaza}', [IjazaController::class, 'update'])->whereNumber('ijaza')->name('ijazas.update');
            Route::put('ijazas/{ijaza}/asnads', [LinkController::class, 'ijazaAsnads'])->whereNumber('ijaza')->name('ijazas.asnads.sync');
            Route::put('ijazas/{ijaza}/books', [LinkController::class, 'ijazaBooks'])->whereNumber('ijaza')->name('ijazas.books.sync');
        });
        Route::middleware('can:ijazas.delete')->group(function () {
            Route::delete('ijazas/{ijaza}', [IjazaController::class, 'destroy'])->whereNumber('ijaza')->name('ijazas.destroy');
            Route::post('ijazas/{ijaza}/restore', [IjazaController::class, 'restore'])->whereNumber('ijaza')->name('ijazas.restore');
        });
        Route::put('books/{book}/asnads', [LinkController::class, 'bookAsnads'])->middleware('can:books.update')->whereNumber('book')->name('books.asnads.sync');
    });
});

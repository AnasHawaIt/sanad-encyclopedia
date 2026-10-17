<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookController;
use App\Http\Controllers\Api\V1\BookEditionController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\PermissionController;
use App\Http\Controllers\Api\V1\PersonController;
use App\Http\Controllers\Api\V1\PersonTeacherController;
use App\Http\Controllers\Api\V1\ReferenceController;
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
            Route::get('persons/{entity}/references', [ReferenceController::class, 'index'])->defaults('type', 'person')->whereNumber('entity')->name('persons.references.index');
            Route::get('books/{entity}/references', [ReferenceController::class, 'index'])->defaults('type', 'book')->whereNumber('entity')->name('books.references.index');
        });
        Route::post('sources', [SourceController::class, 'store'])->middleware('can:sources.create')->name('sources.store');
        Route::middleware('can:sources.update')->group(function () {
            Route::put('sources/{source}', [SourceController::class, 'update'])->whereNumber('source')->name('sources.update');
            foreach (['persons' => 'person', 'books' => 'book'] as $prefix => $type) {
                Route::post("{$prefix}/{entity}/references", [ReferenceController::class, 'store'])->defaults('type', $type)->whereNumber('entity')->name("{$prefix}.references.store");
                Route::put("{$prefix}/{entity}/references/{reference}", [ReferenceController::class, 'update'])->defaults('type', $type)->whereNumber(['entity', 'reference'])->name("{$prefix}.references.update");
                Route::delete("{$prefix}/{entity}/references/{reference}", [ReferenceController::class, 'destroy'])->defaults('type', $type)->whereNumber(['entity', 'reference'])->name("{$prefix}.references.destroy");
            }
        });
        Route::middleware('can:reviews.decide')->group(function () {
            foreach (['persons' => 'person', 'books' => 'book'] as $prefix => $type) {
                Route::put("{$prefix}/{entity}/references/{reference}/status", [ReferenceController::class, 'status'])->defaults('type', $type)->whereNumber(['entity', 'reference'])->name("{$prefix}.references.status");
            }
        });
        Route::middleware('can:sources.delete')->group(function () {
            Route::delete('sources/{source}', [SourceController::class, 'destroy'])->whereNumber('source')->name('sources.destroy');
            Route::post('sources/{source}/restore', [SourceController::class, 'restore'])->whereNumber('source')->name('sources.restore');
        });
    });
});

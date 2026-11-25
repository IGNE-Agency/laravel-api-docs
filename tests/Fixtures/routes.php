<?php

declare(strict_types=1);

use Igne\ScrambleExtensions\Tests\Fixtures\ArticleController;
use Igne\ScrambleExtensions\Tests\Fixtures\MembersOnly;
use Igne\ScrambleExtensions\Tests\Fixtures\RejectDrafts;
use Illuminate\Support\Facades\Route;

Route::prefix('api/fixtures')->group(function (): void {
    Route::get('articles', [ArticleController::class, 'index']);
    Route::post('articles', [ArticleController::class, 'store'])->middleware([RejectDrafts::class, 'throttle:60,1']);
    Route::post('articles/{article}/archive', [ArticleController::class, 'archive']);
    Route::post('drafts', fn () => response()->noContent())->middleware(RejectDrafts::class);

    Route::post('articles/validated', [ArticleController::class, 'create']);
    Route::put('articles/{article}', [ArticleController::class, 'update'])->middleware('auth');

    // A middleware on a whole group that only refuses the routes it names.
    Route::middleware(MembersOnly::class)->group(function (): void {
        Route::get('lounge', fn () => response()->noContent())->name('fixtures.members');
        Route::get('lobby', fn () => response()->noContent())->name('fixtures.guests');
    });

    // The route model binding of the `api` group.
    Route::middleware('api')->group(function (): void {
        Route::get('pings', fn () => response()->noContent());
        Route::get('pings/{ping}', fn (string $ping) => response()->noContent());
    });

    // A session client: the `web` group runs Laravel's CSRF check.
    Route::middleware('web')->group(function (): void {
        Route::get('profile', fn () => response()->noContent());
        Route::put('profile', fn () => response()->noContent());
        Route::post('webhook', fn () => response()->noContent());
    });
});

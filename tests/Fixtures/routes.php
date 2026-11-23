<?php

declare(strict_types=1);

use Igne\ScrambleExtensions\Tests\Fixtures\ArticleController;
use Igne\ScrambleExtensions\Tests\Fixtures\RejectDrafts;
use Illuminate\Support\Facades\Route;

Route::prefix('api/fixtures')->group(function (): void {
    Route::get('articles', [ArticleController::class, 'index']);
    Route::post('articles', [ArticleController::class, 'store'])->middleware([RejectDrafts::class, 'throttle:60,1']);
    Route::post('articles/{article}/archive', [ArticleController::class, 'archive']);
    Route::post('drafts', fn () => response()->noContent())->middleware(RejectDrafts::class);
});

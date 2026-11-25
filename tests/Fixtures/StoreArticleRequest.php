<?php

declare(strict_types=1);

namespace Igne\ApiDocs\Tests\Fixtures;

use Illuminate\Foundation\Http\FormRequest;

final class StoreArticleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:120'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'tags.*' => ['string'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Services\RobotSnapshotSanitizer;
use Illuminate\Foundation\Http\FormRequest;

class StoreRobotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $snapshot = $this->input('snapshot_html');
        if (is_string($snapshot)) {
            $this->merge([
                'snapshot_html' => app(RobotSnapshotSanitizer::class)->reduceOversizedSnapshot($snapshot),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'interval_minutes' => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'target_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'notify_on_drop' => ['sometimes', 'boolean'],
            'notify_on_restock' => ['sometimes', 'boolean'],
            'selectors' => ['sometimes', 'nullable', 'array:title,description,image,price,currency,store,availability,seller,original_price,extras'],
            'selectors.title' => ['nullable', 'array:css,attr'],
            'selectors.description' => ['nullable', 'array:css,attr'],
            'selectors.image' => ['nullable', 'array:css,attr'],
            'selectors.price' => ['nullable', 'array:css,attr'],
            'selectors.currency' => ['nullable', 'array:css,attr'],
            'selectors.store' => ['nullable', 'array:css,attr'],
            'selectors.availability' => ['nullable', 'array:css,attr'],
            'selectors.seller' => ['nullable', 'array:css,attr'],
            'selectors.original_price' => ['nullable', 'array:css,attr'],
            'selectors.extras' => ['sometimes', 'nullable', 'array', 'max:10'],
            'selectors.extras.*.key' => ['required_with:selectors.extras', 'string', 'max:80', 'distinct:strict'],
            'selectors.extras.*.label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'selectors.extras.*.css' => ['required_with:selectors.extras', 'string', 'max:500'],
            'selectors.extras.*.attr' => ['required_with:selectors.extras', 'string', 'in:text,src,content,href'],
            'selectors.extras.*.type' => ['required_with:selectors.extras', 'string', 'in:availability,seller,original_price,custom'],
            'selectors.extras.*.notify' => ['sometimes', 'boolean'],
            'selectors.title.css' => ['required_with:selectors.title', 'string', 'max:500'],
            'selectors.title.attr' => ['required_with:selectors.title', 'string', 'in:text,src,content'],
            'selectors.description.css' => ['required_with:selectors.description', 'string', 'max:500'],
            'selectors.description.attr' => ['required_with:selectors.description', 'string', 'in:text,src,content'],
            'selectors.image.css' => ['required_with:selectors.image', 'string', 'max:500'],
            'selectors.image.attr' => ['required_with:selectors.image', 'string', 'in:text,src,content'],
            'selectors.price.css' => ['required_with:selectors.price', 'string', 'max:500'],
            'selectors.price.attr' => ['required_with:selectors.price', 'string', 'in:text,src,content'],
            'selectors.currency.css' => ['required_with:selectors.currency', 'string', 'max:500'],
            'selectors.currency.attr' => ['required_with:selectors.currency', 'string', 'in:text,src,content'],
            'selectors.store.css' => ['required_with:selectors.store', 'string', 'max:500'],
            'selectors.store.attr' => ['required_with:selectors.store', 'string', 'in:text,src,content'],
            'selectors.availability.css' => ['required_with:selectors.availability', 'string', 'max:500'],
            'selectors.availability.attr' => ['required_with:selectors.availability', 'string', 'in:text,src,content'],
            'selectors.seller.css' => ['required_with:selectors.seller', 'string', 'max:500'],
            'selectors.seller.attr' => ['required_with:selectors.seller', 'string', 'in:text,src,content'],
            'selectors.original_price.css' => ['required_with:selectors.original_price', 'string', 'max:500'],
            'selectors.original_price.attr' => ['required_with:selectors.original_price', 'string', 'in:text,src,content'],
            'overrides' => ['sometimes', 'nullable', 'array:title,description,image,price,currency,store,preview_provider,availability,seller,original_price'],
            'overrides.title' => ['nullable', 'string', 'max:255'],
            'overrides.description' => ['nullable', 'string', 'max:10000'],
            'overrides.image' => ['nullable', 'string', 'max:2048'],
            'overrides.price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'overrides.currency' => ['nullable', 'string', 'max:10'],
            'overrides.store' => ['nullable', 'string', 'max:255'],
            'overrides.preview_provider' => ['nullable', 'string', 'max:30'],
            'overrides.availability' => ['nullable', 'string', 'max:100'],
            'overrides.seller' => ['nullable', 'string', 'max:255'],
            'overrides.original_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'snapshot_html' => [
                'sometimes',
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (strlen($value) > 3 * 1024 * 1024) {
                        $fail('El snapshot supera 3 MB');
                    }
                },
            ],
        ];
    }
}

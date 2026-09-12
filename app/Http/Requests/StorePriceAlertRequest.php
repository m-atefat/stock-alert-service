<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @property string $target_price
 */
class StorePriceAlertRequest extends FormRequest
{
    private const int MAX_ACTIVE_ALERTS = 100;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'target_price' => ['required', 'decimal:0,8', 'gt:0', 'max:90071992.54'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $activeCount = $this->user()->priceAlerts()->active()->count();

            if ($activeCount >= self::MAX_ACTIVE_ALERTS) {
                $validator->errors()->add(
                    'target_price',
                    'You have reached the maximum of '.self::MAX_ACTIVE_ALERTS.' active alerts.',
                );
            }
        });
    }
}

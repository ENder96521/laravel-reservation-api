<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'time_slot_id' => ['required', 'integer', 'exists:time_slots,id'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}

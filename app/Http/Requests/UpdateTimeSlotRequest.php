<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateTimeSlotRequest extends FormRequest
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
            'start_at' => ['sometimes', 'required', 'date'],
            'end_at' => ['sometimes', 'required', 'date'],
            'capacity' => ['sometimes', 'required', 'integer', 'min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $timeSlot = $this->route('time_slot');
            $startAt = $this->input('start_at', $timeSlot?->start_at);
            $endAt = $this->input('end_at', $timeSlot?->end_at);

            if ($startAt && $endAt && strtotime((string) $endAt) <= strtotime((string) $startAt)) {
                $validator->errors()->add('end_at', 'The end at field must be a date after start at.');
            }
        });
    }
}

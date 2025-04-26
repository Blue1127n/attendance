<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Illuminate\Support\Carbon;

class AttendanceCorrectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'breaks.*.start' => ['nullable'],
            'breaks.*.end' => ['nullable'],
            'remarks' => ['required', 'string'],
        ];
    }

    public function messages()
    {
        return [
            'remarks.required' => '備考を記入してください',
        ];
    }

    public function withValidator(Validator $validator)
    {
    $validator->after(function ($validator) {
        $clockIn = $this->input('clock_in');
        $clockOut = $this->input('clock_out');

        if (!$clockIn || !$clockOut || $clockIn >= $clockOut) {
            $validator->errors()->add('clock_in', '出勤時間もしくは退勤時間が不適切な値です');
        }

        foreach ($this->input('breaks', []) as $index => $break) {
            $start = $break['start'] ?? null;
            $end = $break['end'] ?? null;

            if ($start && $end) {
                $startTime = Carbon::parse($start);
                $endTime = Carbon::parse($end);
                $clockInTime = Carbon::parse($clockIn);
                $clockOutTime = Carbon::parse($clockOut);

                if ($startTime >= $endTime) {
                    $validator->errors()->add("breaks.$index.start", '休憩時間が不適切な値です');
                }

                if ($startTime < $clockInTime || $endTime > $clockOutTime) {
                    $validator->errors()->add("breaks.$index.start", '休憩時間が勤務時間外です');
                }
                }
            }
        });
    }
}


<?php

namespace App\Http\Requests;

use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $schedule = $this->route('schedule');

        if ($schedule instanceof Schedule) {
            return $this->user()?->can('update', $schedule) ?? false;
        }

        return $this->user()?->can('create', Schedule::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $days = $this->input('days');

        if (is_array($days)) {
            $days = array_map(
                fn ($day): string => strtoupper(trim((string) $day)),
                $days
            );
            $days = array_values(array_unique($days));
        }

        $this->merge([
            'subject_code' => strtoupper(trim((string) $this->input('subject_code'))),
            'descriptive_title' => trim((string) $this->input('descriptive_title')),
            'days' => $days,
        ]);
    }

    public function rules(): array
    {
        return [
            'instructor_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('role', 'teacher')
                ),
            ],
            'room_id' => ['required', 'integer', Rule::exists('rooms', 'id')],
            'subject_code' => ['required', 'string', 'max:30'],
            'descriptive_title' => ['required', 'string', 'max:120'],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', Rule::in(Schedule::DAYS)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'units' => ['required', 'integer', 'min:1', 'max:6'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $instructorId = (int) $this->input('instructor_id');
            $startTime = (string) $this->input('start_time');
            $endTime = (string) $this->input('end_time');
            $days = $this->input('days', []);
            $schedule = $this->route('schedule');
            $ignoreId = $schedule instanceof Schedule ? $schedule->getKey() : 0;

            $conflict = Schedule::query()
                ->where('user_id', $instructorId)
                ->when($ignoreId > 0, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where(function ($query) use ($days): void {
                    foreach ($days as $day) {
                        $query->orWhereJsonContains('days', $day);
                    }
                })
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime)
                ->exists();

            if ($conflict) {
                $validator->errors()->add(
                    'days',
                    'This instructor already has a class during that time.'
                );
            }
        });
    }
}

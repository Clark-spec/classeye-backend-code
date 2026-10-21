<?php

namespace App\Http\Requests;

use App\Models\Room;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $room = $this->route('room');
        $roomId = $room instanceof Room ? $room->getKey() : null;

        return [
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('rooms', 'name')->ignore($roomId),
            ],
            'floor' => ['required', 'integer', 'min:1', 'max:10'],
            'building' => ['nullable', 'string', 'max:50'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'people_count' => ['required', 'integer', 'min:0', 'max:1000'],
            'is_maintenance' => ['boolean'],
        ];
    }
}

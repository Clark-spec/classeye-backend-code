<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'instructor' => new UserResource($this->whenLoaded('instructor')),
            'room' => $this->whenLoaded('room', fn () => [
                'id' => $this->room->id,
                'name' => $this->room->name,
                'floor' => $this->room->floor,
                'building' => $this->room->building,
            ]),
            'subject_code' => $this->subject_code,
            'descriptive_title' => $this->descriptive_title,
            'days' => $this->orderedDays(),
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'units' => $this->units,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

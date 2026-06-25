<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class RealtimeService
{
    /**
     * Broadcast a realtime event using Laravel Cache.
     *
     * @param string $event Event type (e.g., 'notification', 'score_updated', 'slot_updated')
     * @param array $data Data payload for the event
     * @return void
     */
    public static function broadcast(string $event, array $data): void
    {
        $events = Cache::get('realtime_events', []);
        
        $newEvent = [
            'id' => uniqid('evt_', true),
            'event' => $event,
            'data' => $data,
            'timestamp' => time(),
        ];
        
        $events[] = $newEvent;
        
        // Keep only the last 50 events to avoid memory bloat
        if (count($events) > 50) {
            array_shift($events);
        }
        
        Cache::put('realtime_events', $events, 1800); // Store events for 30 minutes
    }
}

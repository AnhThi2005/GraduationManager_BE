<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;
//chạy
class RealtimeController extends Controller
{
    /**
     * Establish a Server-Sent Events (SSE) connection.
     *
     * @return StreamedResponse
     */
    public function stream(Request $request)
    {
        // Release session lock immediately to prevent session blocking/locking on subsequent requests
        if ($request->hasSession()) {
            $request->session()->save();
        }
        if (session_id()) {
            session_write_close();
        }

        $response = new StreamedResponse(function () use ($request) {
            // Retrieve last sent event ID from query param or headers (for native reconnect)
            $lastSentEventId = $request->query(
                'last_event_id',
                $request->header('Last-Event-ID', $request->server('HTTP_LAST_EVENT_ID', ''))
            );

            // Reconnect interval for browser EventSource (10 seconds)
            echo "retry: 10000\n";

            $events = Cache::get('realtime_events', []);
            $newEvents = [];

            if (empty($lastSentEventId)) {
                // Fetch events generated in the last 4 seconds to cover reconnect gap
                $currentTime = time();
                foreach ($events as $event) {
                    if ($event['timestamp'] >= ($currentTime - 4)) {
                        $newEvents[] = $event;
                    }
                }
            } else {
                // Get all events that occurred after the last sent event ID
                $found = false;
                foreach ($events as $event) {
                    if ($found) {
                        $newEvents[] = $event;
                    } elseif ($event['id'] === $lastSentEventId) {
                        $found = true;
                    }
                }
            }

            if (! empty($newEvents)) {
                foreach ($newEvents as $event) {
                    echo "id: {$event['id']}\n";
                    echo "event: {$event['event']}\n";
                    echo 'data: '.json_encode($event['data'])."\n\n";
                }
            } else {
                // Send connection heartbeat to keep browser happy
                echo ": heartbeat\n\n";
            }

            ob_flush();
            flush();
        });

        // Set SSE stream headers
        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no'); // Prevent Nginx from buffering output

        return $response;
    }
}

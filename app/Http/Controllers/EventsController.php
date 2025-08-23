<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventsController extends Controller
{
    /**
     * Server-Sent Events stream using a simple cache-backed queue.
     * This is a minimal implementation for demo use. For production we will use Redis/Broadcasting.
     */
    public function stream(Request $request): StreamedResponse
    {
        $response = new StreamedResponse(function () {
            @set_time_limit(0);

            echo ": connected\n\n"; // comment for initial handshake
            echo "retry: 5000\n\n"; // client retry 5s
            @ob_flush();
            @flush();

            $start = time();
            while (time() - $start < 60) { // keep open ~60s then client reconnects
                $events = Cache::pull('sse_events_queue', []);

                foreach ($events as $evt) {
                    $type = $evt['type'] ?? 'message';
                    $data = json_encode($evt['data'] ?? []);
                    echo "event: {$type}\n";
                    echo "data: {$data}\n\n";
                }

                echo ": ping\n\n"; // heartbeat
                @ob_flush();
                @flush();
                usleep(900000); // 0.9s
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    public static function queueEvent(string $type, array $data): void
    {
        $events = Cache::get('sse_events_queue', []);
        $events[] = [ 'type' => $type, 'data' => $data ];
        Cache::put('sse_events_queue', $events, now()->addMinutes(5));
    }
}

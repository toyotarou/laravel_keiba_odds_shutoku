<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BaganrikiOddsSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $date;
    public string $kaisuu;
    public string $basho;
    public string $day;
    public int    $race;
    public array  $odds;

    public function __construct(string $date, string $kaisuu, string $basho, string $day, int $race, array $odds)
    {
        $this->date   = $date;
        $this->kaisuu = $kaisuu;
        $this->basho  = $basho;
        $this->day    = $day;
        $this->race   = $race;
        $this->odds   = $odds;
    }

    public function broadcastOn(): Channel
    {
        return new Channel('baganriki-odds-sent');
    }

    public function broadcastAs(): string
    {
        return 'BaganrikiOddsSent';
    }
}

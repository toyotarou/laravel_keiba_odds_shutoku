<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BaganrikiOddsSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public String $date;
    public String $kaisuu;
    public String $basho;
    public String $day;
    public int $race;
    public array $odds;

    public function __construct(String $date, String $kaisuu, String $basho, String $day, int $race, array $odds)
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

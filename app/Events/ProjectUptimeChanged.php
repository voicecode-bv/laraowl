<?php

namespace App\Events;

use App\Console\Commands\CheckProjectUptime;
use App\Models\Project;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An application started or stopped responding.
 *
 * Only a transition broadcasts, never a repeat of the state a project was
 * already in: the health check runs every thirty seconds against every
 * monitored project, and an outage that lasts an hour is one event, not a
 * hundred and twenty.
 *
 * It goes out on the team channel rather than the project one. The dashboard
 * that has to raise the alarm is usually the "All" scope, which would
 * otherwise have to hold a subscription open per project to hear about any
 * of them.
 *
 * @see CheckProjectUptime
 */
class ProjectUptimeChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Project $project,
        public string $status,
        public ?int $statusCode = null,
        public ?string $error = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('team.'.$this->project->team_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ProjectUptimeChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'project' => [
                'id' => $this->project->id,
                'name' => $this->project->name,
                'slug' => $this->project->slug,
                'url' => $this->project->url,
            ],
            'status' => $this->status,
            'status_code' => $this->statusCode,
            // Trimmed: the raw client exception can be a paragraph, and the
            // banner has one line to say what went wrong.
            'error' => $this->error === null ? null : mb_substr($this->error, 0, 160),
            'changed_at' => now()->toIso8601String(),
        ];
    }
}

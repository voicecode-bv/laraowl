<?php

use App\Models\Project;
use App\Models\Team;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('team.{teamId}', function ($user, $teamId) {
    $team = Team::find($teamId);

    return $team !== null && $user->belongsToTeam($team);
});

Broadcast::channel('project.{projectId}', function ($user, $projectId) {
    $project = Project::find($projectId);

    if (! $project) {
        return false;
    }

    return $user->belongsToTeam($project->team);
});

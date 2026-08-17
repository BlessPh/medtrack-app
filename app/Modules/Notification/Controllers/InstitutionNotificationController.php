<?php

namespace App\Modules\Notification\Controllers;

use App\Modules\Institution\Models\Institution;
use App\Modules\Notification\Notifications\InstitutionNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class InstitutionNotificationController
{
    public function store(Request $request, Institution $institution): JsonResponse
    {
        $request->user()->can('manageMembers', $institution) || abort(403);
        $data = $request->validate(['title' => ['required', 'string', 'max:120'], 'message' => ['required', 'string', 'max:1000'], 'severity' => ['nullable', Rule::in(['INFO', 'SUCCESS', 'WARNING', 'CRITICAL'])], 'url' => ['nullable', 'string', 'max:500']]);
        $recipients = $institution->members()->where('users.status', 'ACTIVE')->get();
        Notification::send($recipients, new InstitutionNotification($institution->public_id, $institution->name, $data['title'], $data['message'], 'ANNOUNCEMENT', $data['severity'] ?? 'INFO', $data['url'] ?? null));

        return response()->json(['message' => 'Notification envoyée.', 'data' => ['recipients_count' => $recipients->count()]], 201);
    }
}

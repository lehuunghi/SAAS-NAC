<?php

namespace App\Http\Controllers;

use App\Models\DeploymentNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DeploymentNotification::query()->visibleTo($request->user());

        return response()->json(['unread_count' => (clone $query)->whereNull('read_at')->count(), 'notifications' => $query->with('tenant:id,name')->latest('id')->paginate(20)]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $record = DeploymentNotification::query()->visibleTo($request->user())->findOrFail($notification);
        if (! $record->read_at) {
            $record->update(['read_at' => now()]);
        }

        return response()->json(['id' => $record->id, 'read_at' => $record->read_at]);
    }
}

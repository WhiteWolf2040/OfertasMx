<?php

namespace App\Http\Controllers;

use App\Models\RobotNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RobotNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = RobotNotification::query()
            ->with(['robot:id,name,url'])
            ->where('user_id', $request->user()->id)
            ->orderByRaw('read_at IS NOT NULL')
            ->latest('created_at')
            ->paginate(min(max((int) $request->query('per_page', 20), 1), 50));

        return response()->json([
            'data' => $notifications->getCollection()->map(fn (RobotNotification $notification): array => [
                'id' => $notification->id,
                'robot_id' => $notification->robot_id,
                'robot_name' => $notification->robot?->name ?? parse_url($notification->robot?->url ?? '', PHP_URL_HOST),
                'type' => $notification->type,
                'key' => $notification->key,
                'label' => $notification->label,
                'price' => $notification->price,
                'previous_price' => $notification->previous_price,
                'value' => $notification->value,
                'previous_value' => $notification->previous_value,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
            ])->values(),
            'current_page' => $notifications->currentPage(),
            'last_page' => $notifications->lastPage(),
            'per_page' => $notifications->perPage(),
            'total' => $notifications->total(),
            'unread_count' => RobotNotification::query()
                ->where('user_id', $request->user()->id)
                ->whereNull('read_at')
                ->count(),
        ]);
    }

    public function markRead(Request $request, RobotNotification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return response()->json(['message' => 'Notificación marcada como leída.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        RobotNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Notificaciones marcadas como leídas.']);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Liste les notifications de l'utilisateur connecte, les plus recentes d'abord.
     */
    public function index(Request $request)
    {
        $notifications = AppNotification::where('user_id', $request->user()->id)
            ->orderByDesc('date')
            ->paginate(20);

        return response()->json($notifications);
    }

    /**
     * PATCH /api/notifications/{id}/lue
     * Marque une notification comme lue.
     */
    public function marquerLue(Request $request, int $id)
    {
        $notification = AppNotification::where('user_id', $request->user()->id)
            ->where('id', $id)
            ->firstOrFail();

        $notification->update(['lu' => true]);

        return response()->json($notification);
    }
}
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
     * GET /api/notifications/unread-count
     * Nombre de notifications non lues pour l'utilisateur connecte.
     */
    public function unreadCount(Request $request)
    {
        $total = AppNotification::where('user_id', $request->user()->id)
            ->where('lu', false)
            ->count();

        return response()->json($total);
    }

    /**
     * PATCH /api/notifications/{id}/lue
     * POST /api/notifications/{id}/read (alias frontend)
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

    /**
     * POST /api/notifications/mark-all-read
     * Marque toutes les notifications de l'utilisateur connecte comme lues.
     */
    public function markAllRead(Request $request)
    {
        AppNotification::where('user_id', $request->user()->id)
            ->where('lu', false)
            ->update(['lu' => true]);

        return response()->json(['success' => true]);
    }
}
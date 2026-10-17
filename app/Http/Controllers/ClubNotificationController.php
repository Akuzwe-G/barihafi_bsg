<?php

namespace App\Http\Controllers;

use App\Models\ClubNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClubNotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications', ['notifications' => ClubNotification::where('user_id', $request->user()->id)->with('club')->latest()->paginate(20)]);
    }

    public function read(Request $request, ClubNotification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->update(['read_at' => now()]);

        return back()->with('status', 'Notification marked as read.');
    }
}

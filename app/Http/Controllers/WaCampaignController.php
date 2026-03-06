<?php

namespace App\Http\Controllers;

use App\Models\WaCampaign;
use App\Models\Area;
use App\Jobs\ProcessWaCampaign;
use App\Jobs\SendWaCampaignMessage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WaCampaignController extends Controller
{
    public function index()
    {
        $campaigns = WaCampaign::with('targetArea')->latest()->paginate(10);
        return Inertia::render('Broadcasts/Index', [
            'campaigns' => $campaigns
        ]);
    }

    public function create()
    {
        return Inertia::render('Broadcasts/Create', [
            'areas' => Area::all()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'target_type' => 'required|in:all,isolated,area,custom',
            'target_area_id' => 'required_if:target_type,area|nullable|exists:areas,id',
            'message_template' => 'required|string',
        ]);

        $campaign = WaCampaign::create($validated);

        // Dispatch the Process job
        ProcessWaCampaign::dispatch($campaign);

        return redirect()->route('broadcasts.index')->with('success', 'Campaign created and is processing.');
    }

    public function show(WaCampaign $campaign)
    {
        $campaign->load('targetArea');
        $recipients = $campaign->recipients()
            ->with('customer')
            ->orderBy('id', 'desc')
            ->paginate(20);

        return Inertia::render('Broadcasts/Show', [
            'campaign' => $campaign,
            'recipients' => $recipients
        ]);
    }

    public function retryFailed(WaCampaign $campaign)
    {
        $failedRecipients = $campaign->recipients()->where('status', 'failed')->get();

        if ($failedRecipients->isEmpty()) {
            return back()->with('success', 'No failed messages to retry.');
        }

        // Adjust counts
        $campaign->update([
            'failed_count' => $campaign->failed_count - $failedRecipients->count(),
            'status' => 'processing'
        ]);

        $delaySeconds = 0;
        foreach ($failedRecipients as $recipient) {
            $recipient->update(['status' => 'pending', 'error_message' => null]);
            $delaySeconds += rand(4, 9);
            SendWaCampaignMessage::dispatch($recipient)->delay(now()->addSeconds($delaySeconds));
        }

        return back()->with('success', 'Retrying failed messages. They have been queued.');
    }
}

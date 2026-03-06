<?php

namespace App\Jobs;

use App\Models\WaCampaign;
use App\Models\WaCampaignRecipient;
use App\Models\Customer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessWaCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public function __construct(public WaCampaign $campaign)
    {
    }

    public function handle(): void
    {
        $this->campaign->update(['status' => 'processing']);

        $customers = collect();
        if ($this->campaign->target_type === 'all') {
            $customers = Customer::whereNotNull('phone')->get();
        } elseif ($this->campaign->target_type === 'isolated') {
            $customers = Customer::whereNotNull('phone')->where('status', 'isolated')->get();
        } elseif ($this->campaign->target_type === 'area') {
            $customers = Customer::whereNotNull('phone')->where('area_id', $this->campaign->target_area_id)->get();
        }

        $this->campaign->update(['total_recipients' => $customers->count()]);

        if ($customers->count() === 0) {
            $this->campaign->update(['status' => 'completed']);
            return;
        }

        $delaySeconds = 0;
        foreach ($customers as $customer) {
            $recipient = WaCampaignRecipient::create([
                'wa_campaign_id' => $this->campaign->id,
                'customer_id' => $customer->id,
                'phone_number' => $customer->phone,
                'status' => 'pending',
            ]);

            // random delay between 4 and 9 seconds added to total delay to stagger
            $delaySeconds += rand(4, 9);
            SendWaCampaignMessage::dispatch($recipient)->delay(now()->addSeconds($delaySeconds));
        }
    }
}

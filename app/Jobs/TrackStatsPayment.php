<?php

namespace App\Jobs;

use App\Models\StatsPayment;
use App\Services\StatsAggregationsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class TrackStatsPayment extends BaseStatsTrack
{
    /**
     * Execute the job.
     */
    public function handle(): void
    {
        parent::handle();

        // A zero amount is no revenue: either a transaction without a charge (e.g. Paddle's payment method change),
        // which is not stored, or a full refund of a tracked payment. The refunded payment is deleted instead of kept
        // at zero, otherwise its user would still count as paying user and active subscriber.
        // orig_gross: a tiny foreign amount can round to a converted gross of 0 and must not delete the payment.
        if ((float) ($this->input['orig_gross'] ?? $this->input['gross']) === 0.0) {
            $this->deleteRefundedPayment();

            return;
        }

        $this->resolveVisitorHash();

        $statsPayment = StatsPayment::updateOrCreate(['id' => $this->input['id']], $this->input);

        // a payment is never a placeholder shell (unlike StatsUser), so a fresh create is the alert signal.
        if ($statsPayment->wasRecentlyCreated) {
            $statsPayment->sendPaymentAlerts();
        }
    }

    protected function deleteRefundedPayment(): void
    {
        $payment = StatsPayment::whereKey($this->input['id'])->first();

        if ($payment === null) {
            return;
        }

        $shiftedSince = DB::transaction(function () use ($payment): ?string {
            $payment->delete();

            // The user's later payments move up, otherwise none of them would be the first payment (counter 1)
            // and the user would drop out of paying users until the nightly counter check.
            $laterPayments = StatsPayment::where('stats_user_id', $payment->stats_user_id)
                ->where('counter', '>', $payment->counter);

            $since = $laterPayments->clone()->min('time');
            $laterPayments->decrement('counter');

            return $since;
        });

        // Again after the commit: the observer already invalidated inside the transaction, where a concurrent
        // dashboard read could still cache the old state. The counters follow the ingest order, not the time,
        // so a shifted payment can lie before the deleted one and needs its own invalidation.
        $aggregations = resolve(StatsAggregationsService::class);
        $aggregations->invalidateByStatsUpdate($payment);

        if ($shiftedSince !== null) {
            $aggregations->invalidatePeriodsSince($payment->team_id, $payment->project_id, CarbonImmutable::parse($shiftedSince));
        }
    }
}

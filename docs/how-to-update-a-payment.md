---
description: "Update payments already tracked in SimpleStats, for example after status changes, refunds or chargebacks."
---

# How to update an already tracked payment

::: tip
Before continue, make sure to get familiar with the [How to track new payment](how-to-track-a-new-payment.md) documentation!
:::

There are several scenarios in which updating a previously tracked payment might be necessary. Below are a few examples:

:::info
For this guide, lets assume your model which holds your payment data is called `Transaction`.
:::

## Listen for Changes

Consider a scenario where a `Transaction` has been marked as "completed" and successfully tracked. If now for any reason, the `total` of the transaction changes subsequently, it is essential to update this information in _SimpleStats_ to maintain accurate records. For this you can listen for any changes made to your `Transaction` model by listing the fields in the `watchTrackingFields` method.

To update the `gross` and `net` on our site, when your model's `total` changes, the implementation might look as follows:

```php
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;

class Transaction extends Model implements TrackablePaymentWithCondition
{
    //..
    
    /**
     * The gross amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingGross(): float
    {
        return $this->total;
    }

    /**
     * The net amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingNet(): float
    {
        return $this->total - $this->tax;
    }
    
    /**
     * The condition that should be fulfilled in order to track the payment.
     */
    public function passTrackingCondition(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * The field(s) we should watch for changes to recheck the condition.
     */
    public function watchTrackingFields(): array
    {
        return ['status', 'total'];
    }
    //..
}
```

Congrats, now if the `total` of the `Transaction` changes and the `status` is "completed", the payment will be updated on our side.

## Handling Refunds and Chargebacks  

The same concept can be used to handle refunds and chargebacks.
Let's assume you set a `refuned_at` timestamp on your `Transactions` model when a refund happens.

Now you may want the `gross` and `net` for this payment to be "0":

```php
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;

class Transaction extends Model implements TrackablePaymentWithCondition
{
    // ...
    
    /**
     * The gross amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingGross(): float
    {
        if ($this->refunded_at !== null) {
            return 0;
        }
        
        return $this->total;
    }

    /**
     * The net amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingNet(): float
    {
        if ($this->refunded_at !== null) {
            return 0;
        }
        
        return $this->total - $this->tax;
    }
    
    /**
     * The condition that should be fulfilled in order to track the payment.
     */
    public function passTrackingCondition(): bool
    {
        return $this->status === 'completed' || $this->refunded_at !== null;
    }

    /**
     * The field(s) we should watch for changes to recheck the condition.
     */
    public function watchTrackingFields(): array
    {
        return ['status', 'total', 'refunded_at'];
    }
    // ...
}
```

When a tracked payment arrives with a `gross` of `0`, _SimpleStats_ removes it. A fully refunded payment then no longer counts as revenue, paying user or active subscription. For a partial refund, send the remaining amount instead.

This mechanism provides extensive control over the actions triggered by changes in certain states within your model. Utilize this flexibility to implement the required logic according to **your specific needs** - the implementation details are entirely at your discretion!

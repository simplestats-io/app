<x-mail::message>
Hi {{ $user->name }},

we couldn't collect the latest payment for your {{ config('app.name') }} subscription. Don't worry, your plan stays active and we keep tracking your data while we retry the payment over the next days.

Please update your payment method to keep it that way. If the payment can't be collected, your subscription will be canceled and your account falls back to the free plan, which only tracks data up to its monthly event limit.

<x-mail::button :url="route('spark.portal')">
Update payment method
</x-mail::button>

You'll get this reminder once a day until the payment went through.
</x-mail::message>

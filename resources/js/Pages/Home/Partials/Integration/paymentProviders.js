import StripeLogo from "@/Pages/Home/Partials/Integration/Logos/stripe.svg?component";
import PaddleLogo from "@/Pages/Home/Partials/Integration/Logos/paddle.svg?component";
import LemonSqueezyLogo from "@/Pages/Home/Partials/Integration/Logos/lemon-squeezy.svg?component";
import MollieLogo from "@/Pages/Home/Partials/Integration/Logos/mollie.svg?component";
import PolarLogo from "@/Pages/Home/Partials/Integration/Logos/polar.svg?component";

// Mollie's logo is a wordmark, so it replaces the name instead of standing next to it.
export const paymentProviders = [
    { name: 'Stripe', via: 'via Cashier', logo: StripeLogo, className: 'CashierStripe', anchor: 'cashier-stripe' },
    { name: 'Paddle', via: 'via Cashier', logo: PaddleLogo, className: 'CashierPaddle', anchor: 'cashier-paddle' },
    { name: 'Lemon Squeezy', via: null, logo: LemonSqueezyLogo, className: 'LemonSqueezy', anchor: 'lemon-squeezy' },
    { name: 'Mollie', via: 'via Cashier', logo: MollieLogo, className: 'CashierMollie', anchor: 'cashier-mollie', wordmark: true },
    { name: 'Polar', via: null, logo: PolarLogo, className: 'Polar', anchor: 'polar' },
];

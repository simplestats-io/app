---
description: "How SimpleStats connects anonymous visitors to registrations and payments, and attributes revenue back to the original UTM source."
---

# From Visitor to User to Payment

One of the core strengths of SimpleStats is **full-funnel attribution**: connecting anonymous visitors to registered users and their payments, all the way back to the original marketing source.

## How It Works

### 1. Visitor Arrives

When someone visits your site, SimpleStats creates a [visitor hash](how-to-track-a-new-visitor.md#the-visitor-hash) and captures any [tracking codes](tracking-codes.md) (UTM parameters, referrer, etc.) from the URL. This is all done automatically and server-side.

```
https://yourapp.com?utm_source=google&utm_campaign=spring-sale
```

At this point, SimpleStats knows: _"An anonymous visitor arrived from the Google spring-sale campaign."_

### 2. Visitor Registers

When the visitor [signs up as a user](how-to-track-a-new-user.md), SimpleStats links the new registration back to the original visitor data. The tracking codes from the first visit are now permanently associated with this user.

This means: _"User #42 originally came from the Google spring-sale campaign."_

### 3. User Makes a Payment

When the user later makes a [payment](how-to-track-a-new-payment.md), the revenue is automatically attributed to the same marketing source that brought them in as a visitor.

This means: _"The Google spring-sale campaign generated $49 in revenue from User #42."_

### Payments Without Registration

Not every app requires user accounts. For e-commerce sites with guest checkout, you can skip the registration step entirely and [associate payments directly with a visitor](how-to-track-a-new-payment.md#payment-tracking-for-visitors). SimpleStats will attribute the revenue to the visitor's original tracking codes, giving you campaign ROI even without user registrations.

## Full-Funnel Example

| Step | What happens | What SimpleStats records |
|------|-------------|------------------------|
| **Visit** | Someone clicks your Google Ad | Visitor hash + `utm_source=google`, `utm_campaign=spring-sale` |
| **Registration** | They create an account | New user linked to the visitor's tracking codes |
| **Login** | They return and log in | Active user event (DAU/WAU/MAU) |
| **Payment** | They purchase a subscription | Revenue attributed to `google` / `spring-sale` |

The **Users** page shows exactly this chain for every single user: where they came from, when they registered, how often they log in, and the revenue they generated.

![The Users page: referrer, registration, logins and lifetime revenue per user](https://simplestats.io/images/screenshots/screenshot_users_light.webp)

## What Questions Can You Answer?

With this full-funnel data, your SimpleStats dashboard can answer questions like:

- _"How much revenue did my Google Ads campaign generate?"_
- _"Which marketing channel has the best visitor-to-paying-user conversion?"_
- _"What is the average revenue per visitor from Twitter vs. organic search?"_
- _"Which campaign brings the most valuable users, not just the most traffic?"_

These insights are available through KPIs like [ARPU, ARPV, CR, and REG2PU](introduction.md#kpis) on your dashboard.

## Privacy-First Approach

This attribution chain works entirely **server-side** and is **GDPR-compliant**. Similar privacy-focused analytics tools like **Plausible** and **Fathom Analytics** use comparable hashing techniques for visitor identification, but SimpleStats goes further by connecting the full funnel from visitor to revenue without ever storing personally identifiable information like IP addresses or User-Agents.

::: tip
Since all tracking happens server-side, none of it can be blocked by ad blockers or browser privacy extensions. You get a complete, unfiltered picture of your funnel.
:::

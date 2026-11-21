---
description: "How SimpleStats counts unique visitors server-side, and why the numbers differ from Google Analytics, Plausible or Fathom."
aside: false
---

# Counting Visitors

No two analytics tools report the same visitor numbers, and the gap is usually larger than people expect. This page explains how _SimpleStats_ counts visitors, and why its numbers can differ from tools like Google Analytics, Matomo, Plausible, or Fathom. One thing is worth knowing up front: SimpleStats counts unique visitors the exact same way privacy-first tools like Plausible and Fathom do, with a **daily rotating hash instead of a cookie**. That is simply how GDPR-friendly, cookieless analytics works, and it is what shapes every difference below.

## The trade-off of cookieless counting

To work without a [cookie banner](https://simplestats.io/compliance), SimpleStats identifies a visitor with a hash built from IP address, user agent, and a **daily salt**. It has two known limits, the same ones Plausible and Fathom have:

- **The same person can be counted twice in a day** if their IP changes mid-day (mostly mobile visitors switching between Wi-Fi and mobile networks).
- **Different people can be counted as one** when they share an IP and an identical user agent. Modern browsers deliberately shrink how unique the user agent is, so two people on the same OS and browser version (an office network, or many phones behind one mobile carrier) can look identical.

The two effects pull in opposite directions and partly offset, but not evenly: in practice, counting one person twice is the more likely case. They are the price of counting without a persistent identifier. Cookies avoid them, but come with their own costs: they legally need consent, and browsers cap them (Safari limits script cookies to 7 days, turning returning visitors into new ones). There is no method that is exact in every direction, only which trade-off you prefer.

## What SimpleStats never loses

Because tracking happens on your server, there is no script in the browser to block and no banner to gate it:

- **Ad blockers never remove a visit.** Around a third of users run one, more on technical audiences. Every one of them is invisible to a JavaScript tool and counted by SimpleStats.
- **Consent banners never gate it.** No opt-in is needed, so visitors who ignore or decline a banner are still counted.
- **Attribution stays intact end to end.** This is the big one. In a client-side tool a visitor can be blocked at the tracking script yet still sign up and pay, and that revenue arrives with no source attached. Server-side, the whole chain (visit, signup, payment) is captured in one place, so a payment is **reliably** traced back to the campaign, referrer, or source that produced it. Nothing is silently dropped along the way.

Together, ad blockers and consent banners can hide a large part of a browser-based tool's real audience. SimpleStats loses neither, so expect it to run higher, often around **1.5x to 2x** against a tool that a good share of your visitors block or that runs behind a consent banner. That difference is real people (and real revenue) the other tool could not see.

## Where each tool gains or loses visitors

<div class="full-width-table">

| Tool | Tracking runs | Possibly blocked | Needs consent | Counts a unique visitor by |
|---|---|---|---|---|
| **SimpleStats** | Backend | No | No | Daily hash (IP + user agent) |
| **Plausible / Fathom** | Frontend | Yes | No | Daily hash (IP + user agent) |
| **Google Analytics 4** | Frontend | Yes | Yes | Cookie (2 years) |
| **Matomo** | Frontend | Yes | In cookie mode | Cookie (13 months) |

</div>

---

If a project's numbers ever look off to you, reach out any time and we will dig into it with you.

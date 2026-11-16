---
aside: false
---

# Counting Page Views

How _SimpleStats_ counts page views, and why the numbers can differ from other tools. To set them up, see [Page Views](how-to-track-page-views.md).

## Definitions

Like Fathom and Plausible, _SimpleStats_ counts a visitor once per day (see [Counting Visitors](counting-visitors.md)).

| Number | Meaning |
|---|---|
| **Page Views** | Pages your visitors opened. |
| **Views per Visitor** | Average number of pages a visitor opened per day. |
| **Bounce Rate** | Share of visitors who opened only one page that day. |

Visitors from before you installed client v6 have no page views and are left out of these numbers, so they do not drag the averages down.

## Views belong to the first visit of the day

All pages a visitor opens during the day count toward the source, campaign, country, device and entry page of their first visit that day. Someone who arrives through your newsletter and then opens ten more pages adds eleven views to the newsletter.

That is why the **Views** column in **Entry Pages** shows all pages viewed by visitors who started on a page. How often a single page was viewed is shown in **Top Pages**.

## Why the numbers differ from other tools

- **The bounce rate is lower than in session-based tools.** Google Analytics or Plausible count a bounce per visit of up to 30 minutes. _SimpleStats_ counts per day: someone who reads one article in the morning and another one in the evening did not bounce.
- **Today's bounce rate can still change**, because visitors may open more pages later in the day.
- **Some pages never reach your server**: pages the browser shows from its back/forward cache, pages served by a full-page cache or CDN, and route changes that never request the server. They are not counted.
- **Ad blockers and cookie banners never hide a view.** Every view reaches your server, so _SimpleStats_ usually counts more views than a JavaScript tool that some of your visitors block.

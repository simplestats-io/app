import {XAAPU, XAPU, XAU} from "@/Helpers/StringHelpers.js";

const periodMap = {
    hours: 'hour',
    days: 'day',
    weeks: 'week',
    months: 'month',
    years: 'year',
};

export function getKpiDefinitions(rangeType) {
    let periodName = periodMap[rangeType];
    return {
        mrr: {
            short: 'MRR',
            long: 'Monthly Recurring Revenue',
            description: 'Active recurring revenue as of the end of the selected period, normalized to a monthly amount (yearly plans counted as net / 12).',
        },
        arr: {
            short: 'ARR',
            long: 'Annual Recurring Revenue',
            description: 'Annualized recurring revenue (MRR × 12).',
        },
        subscribers: {
            short: 'Subscribers',
            long: 'Active Subscribers',
            description: 'Number of users with an active recurring payment within its interval window as of the end of the selected period.',
        },
        arps: {
            short: 'ARPS',
            long: 'Average Revenue Per Subscriber',
            description: 'Average monthly recurring revenue per active subscriber (MRR ÷ Subscribers).',
        },
        nrr: {
            short: 'NRR',
            long: 'Net Revenue Retention',
            description: 'Recurring revenue retained from the customers active at the start of the selected period, including expansion: (start + expansion − contraction − churn) / start.',
        },
        grr: {
            short: 'GRR',
            long: 'Gross Revenue Retention',
            description: 'Recurring revenue retained from the customers active at the start of the selected period, excluding expansion: (start − contraction − churn) / start.',
        },
        ltv: {
            short: 'LTV',
            long: 'Customer Lifetime Value',
            description: 'Average recurring revenue a subscriber generates over their lifetime: ARPS ÷ monthly gross MRR churn rate.',
        },
        time_to_pay: {
            short: 'Time to Pay',
            long: 'Time to First Payment',
            description: 'Median number of days from registration to first payment, for customers whose first payment falls in the selected period.',
            lowerIsBetter: true,
        },
        time_to_convert: {
            short: 'Time to Convert',
            long: 'Time to Convert',
            description: 'Median number of days from first visit to registration, by registration date. Only counts registrations linked to a prior visit (tracked going forward).',
            lowerIsBetter: true,
        },
        quick_ratio: {
            short: 'Quick Ratio',
            long: 'MRR Quick Ratio',
            description: 'Growth efficiency over the selected period: (new + expansion) / (contraction + churn). Above 1 means recurring revenue gained outpaces revenue lost.',
        },
        gross_churn: {
            short: 'Gross churn',
            long: 'Gross Revenue Churn',
            description: "Share of the "+periodName+"'s starting MRR lost to cancellations and downgrades (churn + contraction). Lower is better.",
            lowerIsBetter: true,
        },
        net_churn: {
            short: 'Net churn',
            long: 'Net Revenue Churn',
            description: 'Gross churn offset by expansion and reactivation. Below zero means expansion outweighed losses (net revenue growth from existing subscribers).',
            lowerIsBetter: true,
        },
        pd_net: {
            small: 'Rev',
            short: 'Revenue',
            description: 'Net Revenue at this '+periodName+'.',
        },
        pd_gross: {
            short: 'Gross',
            description: 'Gross Revenue at this '+periodName+'.',
        },
        pd_fee: {
            short: 'Fee',
            description: 'Fee at this '+periodName+'.',
        },
        pd_visitor: {
            small: 'Visitors',
            short: 'Unique Visitors',
            description: 'Number of unique visitors at this '+periodName+'.',
        },
        pd_view: {
            small: 'Views',
            short: 'Page Views',
            description: 'Number of pages your visitors viewed at this '+periodName+'.',
        },
        pd_vpv: {
            small: 'Views/Visitor',
            short: 'Views per Visitor',
            description: 'Average number of pages a visitor viewed at this '+periodName+'.',
        },
        pd_bounce_rate: {
            small: 'Bounce',
            short: 'Bounce Rate',
            description: 'Share of visitors who viewed only one page at this '+periodName+'. Lower is better.',
            lowerIsBetter: true,
        },
        pd_reg: {
            small: 'Regs',
            short: 'Registrations',
            description: 'Number of new registrations at this '+periodName+'.',
        },
        pd_cr: {
            small: 'CR',
            short: 'Conversion Rate',
            description: 'Percentage of unique visitors who registered at this '+periodName+' (Conversion Rate).',
        },
        pd_nau: {
            short: 'NAU',
            long: 'New Active Users',
            description: 'Number of unique users who returned at this '+periodName+' to the app (=2nd login) after the registration (=1st login).',
        },
        pd_napu: {
            short: 'NAPU',
            long: 'New Active Paying Users',
            description: 'Number of unique users who did their first payment at this '+periodName+'.',
        },
        pd_dau: {
            short: XAU(rangeType),
            long: XAU(rangeType, true),
            description: 'Number of unique users who logged in or registered at this '+periodName+', regardless of when they signed up, so it can exceed the signups of the selected range.',
        },
        pd_dapu: {
            short: XAPU(rangeType),
            long: XAPU(rangeType, true),
            description: 'Number of unique users who logged in or registered at this '+periodName+' and did at least one payment over lifetime (including this '+periodName+')',
        },
        pd_daapu: {
            short: XAAPU(rangeType),
            long: XAAPU(rangeType, true),
            description: 'Number of unique users who logged in or registered during the given '+periodName+' and did at least one payment in the last 20 days (including this '+periodName+')',
        },
        pd_arpdau: {
            short: 'ARP' + XAU(rangeType),
            long: 'Average Revenue Per ' + XAU(rangeType),
            description: 'The average revenue per ' + XAU(rangeType, true),
        },
        lt_arpu: {
            short: 'ARPU',
            long: 'Average Revenue Per User',
            description: 'The average revenue per User/Registration over lifetime.',
        },
        lt_arppu: {
            short: 'ARPPU',
            long: 'Average Revenue Per Paying User',
            description: 'The average revenue per paying User over lifetime.',
        },
        lt_arpv: {
            short: 'ARPV',
            long: 'Average Revenue Per Visitor',
            description: 'The average revenue per Unique Visitor over lifetime.',
        },
        lt_pu: {
            small: 'PU',
            short: 'Paying Users',
            description: 'Number of users who did at least one payment over lifetime (including this '+periodName+').',
        },
        lt_tau: {
            small: 'Tot. Actives',
            short: 'Total Actives',
            description: 'Number of users who logged in at least twice over lifetime.',
        },
        lt_reg2dau: {
            short: 'Reg. to ' + XAU(rangeType),
            description: 'Percentage of registered users who become '+XAU(rangeType, true)+'.',
        },
        lt_reg2pu: {
            short: 'Reg. to Pay',
            description: 'Percentage of registered users who become paying users.',
        },
        lt_reg2tau: {
            short: 'Reg. to Active',
            description: 'Percentage of registered users who become active.',
        },
        stickiness: {
            short: 'Stickiness',
            long: 'User Stickiness',
            description: 'Average daily active users as a percentage of monthly active users (DAU/MAU) on a rolling 30-day window, plotted as of each '+periodName+'. Higher means your active users come back more often; 20%+ is a common benchmark.',
        },
        user_churn: {
            short: 'User Churn',
            long: 'User Churn',
            description: "Share of the preceding month's active users (30-60 days back) who did not log in again in the rolling last 30 days, as of each "+periodName+'. Lower is better.',
            lowerIsBetter: true,
        },
    }
}

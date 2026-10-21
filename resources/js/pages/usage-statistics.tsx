import { Head } from '@inertiajs/react';

import PublicLayout from '@/layouts/public-layout';

import type { UsageStatus } from './LandingPages/components/UsageSection';

export default function UsageStatistics({ usageStatus, privacyUrl }: { usageStatus: UsageStatus; privacyUrl: string }) {
    return (
        <PublicLayout>
            <Head title="How we count usage" />
            <article className="mx-auto max-w-3xl space-y-5">
                <h1 className="text-3xl font-semibold">How we count usage</h1>
                <p>
                    Usage statistics follow the{' '}
                    <a className="underline" href="https://coprd.countermetrics.org/en/1.0.1/">
                        COUNTER Code of Practice for Research Data, version 1.0.1
                    </a>
                    . These statistics are not COUNTER certified and are not currently reported to DataCite.
                </p>
                <p>
                    {usageStatus.startedAt
                        ? `Collection started on ${usageStatus.startedAt.slice(0, 10)} (UTC).`
                        : 'Collection has not started in this environment.'}{' '}
                    Earlier ERNIE and PanMetaWorks counters remain separate and are not converted into these metrics.
                </p>
                <h2 className="text-xl font-semibold">Views and downloads</h2>
                <p>
                    Views are unique investigations: successful visits to a published internal landing page, retrievals of its public metadata, or
                    clicks on its download links. Downloads are unique requests: clicks on available download links on DOI landing pages. A download
                    also counts as an investigation. We cannot confirm a completed transfer from an external data server. Direct external data access
                    is not measured.
                </p>
                <p>
                    IGSNs describe physical samples and have views only. Tombstone pages continue to collect views and retain earlier usage. Hiding
                    the Usage card does not stop collection.
                </p>
                <h2 className="text-xl font-semibold">Sessions and repeated clicks</h2>
                <p>
                    A session is a keyed hash of the IP address, user agent, and UTC date and hour. It changes at each hour boundary. Repeated actions
                    for the same target and event type within 30 seconds are collapsed into one action. A chain at 0, 20 and 40 seconds counts once.
                    Unique metrics count a session once per resource, even across metadata formats or files.
                </p>
                <h2 className="text-xl font-semibold">What is excluded</h2>
                <p>
                    Known crawlers, signed-in ERNIE users, drafts, review links, previews, HEAD requests, prefetches and failed responses are
                    excluded. Research clients such as curl and Python are classified as Machine access. Unknown agents are Regular; missing user
                    agents are Machine. Public cards show only unique Regular usage. Administrators can separately inspect Total or Unique and Regular
                    or Machine.
                </p>
                <p>
                    Robot and machine rules are versioned and updated weekly from COUNTER and Make Data Count lists, with research-data adaptations.
                    Updates affect subsequent events; earlier events cannot be reclassified after their user agents have been discarded.
                </p>
                <h2 className="text-xl font-semibold">Dates and completeness</h2>
                <p>
                    Completed UTC days are aggregated nightly. Totals cover the entire collection period. Charts show up to twelve calendar months
                    including the current partial month. Pending periods, partial months and known observation gaps are labelled. The data-through
                    date and last aggregation time identify the actual published data.
                </p>
                <h2 className="text-xl font-semibold">Portal searches</h2>
                <p>
                    The internal dashboard also counts successful free-text submissions and direct search URLs, including searches without results.
                    Filter changes, keyword selections and pagination are excluded. Search counts use the same exclusions and repeated-action filter;
                    they have no unique metric and are separate from resource investigations.
                </p>
                <h2 className="text-xl font-semibold">Data handling</h2>
                <p>
                    Usage collection sets no visitor cookie. Raw IP addresses and user agents are not stored in usage events. Pseudonymous events are
                    deleted after aggregation and within seven days even if processing fails. Aggregated counts and normalized search terms remain for
                    reporting.
                </p>
                <p>
                    <a className="underline" href={privacyUrl}>
                        GFZ Data Services privacy policy
                    </a>
                </p>
            </article>
        </PublicLayout>
    );
}

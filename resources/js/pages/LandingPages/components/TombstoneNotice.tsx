import type { LandingPageConfig } from '@/types/landing-page';

const REASONS: Record<string, string> = {
    data_lost: 'Data lost',
    retracted: 'Resource retracted',
    legal_restriction: 'Legal restriction',
    other: 'Other reason',
};

export function TombstoneNotice({ landingPage, isPreview }: { landingPage: LandingPageConfig; isPreview: boolean }) {
    return (
        <section
            aria-labelledby="tombstone-notice-heading"
            className="rounded-lg border-2 border-red-600 bg-red-50 p-5 text-red-950 dark:bg-red-950 dark:text-red-100"
        >
            <h2 id="tombstone-notice-heading" className="text-xl font-semibold">
                This resource is no longer available
            </h2>
            {isPreview && <p className="mt-2 font-medium">Tombstone preview — no changes have been published.</p>}
            <p className="mt-2 font-semibold">{REASONS[landingPage.tombstone_reason ?? 'other']}</p>
            <p className="mt-2 wrap-break-word whitespace-pre-wrap">{landingPage.tombstone_statement}</p>
            {landingPage.tombstoned_at && (
                <p className="mt-2 text-sm">
                    Tombstone activated on <time dateTime={landingPage.tombstoned_at}>{landingPage.tombstoned_at.slice(0, 10)}</time>.
                </p>
            )}
            <p className="mt-3 text-sm">
                The DOI and metadata are retained to identify and cite the original resource. Data downloads and data requests are unavailable.
            </p>
        </section>
    );
}

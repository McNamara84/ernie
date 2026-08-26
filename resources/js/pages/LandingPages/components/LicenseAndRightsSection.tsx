import { ExternalLink } from 'lucide-react';

import type { LandingPageLicense } from '@/types/landing-page';

import { isSafeHttpUrl } from '../lib/resolveIdentifierUrl';
import { CreativeCommonsIcon, getCreativeCommonsShortName } from './CreativeCommonsIcon';
import { LandingPageCard } from './LandingPageCard';

interface LicenseAndRightsSectionProps {
    licenses: LandingPageLicense[];
}

export function LicenseAndRightsSection({ licenses }: LicenseAndRightsSectionProps) {
    const visibleLicenses = licenses.filter((license) => license.name.trim() !== '');

    if (visibleLicenses.length === 0) {
        return null;
    }

    return (
        <LandingPageCard aria-labelledby="heading-licenses" data-testid="license-and-rights-section">
            <h2 id="heading-licenses" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                License &amp; Rights
            </h2>

            <ul className="space-y-2">
                {visibleLicenses.map((license, index) => {
                    const isRaw = license.source === 'raw';
                    const reference = license.reference && isSafeHttpUrl(license.reference) ? license.reference : null;
                    const shortName = !isRaw && license.spdx_id ? getCreativeCommonsShortName(license.spdx_id) : null;
                    const showShortName = shortName !== null && license.name.trim().toUpperCase() !== shortName.toUpperCase();
                    const title = !isRaw && license.spdx_id ? license.spdx_id : license.name;
                    const content = (
                        <>
                            {!isRaw && license.spdx_id ? (
                                <CreativeCommonsIcon spdxId={license.spdx_id} />
                            ) : reference ? (
                                <ExternalLink className="h-4 w-4 shrink-0" aria-hidden="true" />
                            ) : null}
                            <span className="min-w-0 break-words">
                                {license.name}
                                {showShortName && ` (${shortName})`}
                            </span>
                        </>
                    );
                    const className = isRaw
                        ? 'inline-flex w-full items-start gap-2 rounded-md bg-gray-100 px-3 py-2 text-sm text-gray-700 dark:bg-gray-700 dark:text-gray-200'
                        : 'inline-flex w-full items-start gap-2 rounded-md bg-green-100 px-3 py-2 text-sm font-medium text-green-800 dark:bg-green-900/30 dark:text-green-300';
                    const key =
                        license.resource_right_id !== null && license.resource_right_id !== undefined
                            ? `resource-right-${license.resource_right_id}`
                            : `license-${license.id ?? index}`;

                    return (
                        <li key={key}>
                            {reference ? (
                                <a
                                    href={reference}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    data-testid="license-and-rights-entry"
                                    className={`${className} transition-colors hover:opacity-80`}
                                    title={title}
                                >
                                    {content}
                                </a>
                            ) : (
                                <span data-testid="license-and-rights-entry" className={className} title={title}>
                                    {content}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ul>
        </LandingPageCard>
    );
}

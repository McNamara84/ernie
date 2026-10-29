import { BadgeCheck, Braces, FileCode, FileJson, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { LandingPageMetadataLink } from '@/types/landing-page';

import { DarkModeImage } from './DarkModeImage';
import { IgsnCoreDownload } from './IgsnCoreDownload';

interface DownloadMetadataSectionProps {
    resourceId: number;
    isPreview?: boolean;
    supportsIso19115?: boolean;
    supportsIso19115Json?: boolean;
    supportsIgsnCore?: boolean;
    jsonLdExportUrl?: string;
    metadataLinks?: LandingPageMetadataLink[];
}

interface MetadataDownloadActionProps {
    href?: string;
    icon: LucideIcon;
    label: string;
    title: string;
    ariaLabel?: string;
    isPreview: boolean;
    onPreviewClick: () => void;
}

const DOWNLOAD_ACTION_CLASSES =
    'h-auto whitespace-normal rounded-lg border-gray-300 bg-white px-4 py-2 text-left text-gray-700 shadow-none hover:bg-gray-50 hover:text-gray-700 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 dark:hover:text-gray-200';

function MetadataDownloadAction({ href, icon: Icon, label, title, ariaLabel, isPreview, onPreviewClick }: MetadataDownloadActionProps) {
    const content = (
        <>
            <Icon className="h-5 w-5 shrink-0" aria-hidden="true" />
            {label}
        </>
    );

    if (isPreview) {
        return (
            <Button type="button" variant="outline" className={DOWNLOAD_ACTION_CLASSES} title={title} aria-label={ariaLabel} onClick={onPreviewClick}>
                {content}
            </Button>
        );
    }

    if (href === undefined) {
        return null;
    }

    return (
        <Button asChild variant="outline" className={DOWNLOAD_ACTION_CLASSES}>
            <a href={href} title={title} aria-label={ariaLabel}>
                {content}
            </a>
        </Button>
    );
}

/**
 * Renders canonical DataCite downloads and selective ISO metadata exports.
 */
export function DownloadMetadataSection({
    resourceId,
    isPreview = false,
    supportsIso19115 = false,
    supportsIso19115Json = false,
    supportsIgsnCore = false,
    jsonLdExportUrl,
    metadataLinks = [],
}: DownloadMetadataSectionProps) {
    const [isPreviewDialogOpen, setIsPreviewDialogOpen] = useState(false);
    const urlFor = (format: LandingPageMetadataLink['format'], fallback: string): string =>
        metadataLinks.find((link) => link.format === format)?.url ?? fallback;
    const isoMetadataLink = metadataLinks.find((link) => link.format === 'iso19115-3');
    const showIsoMetadata = isoMetadataLink !== undefined || (isPreview && supportsIso19115);
    const isoJsonLink = metadataLinks.find((link) => link.format === 'iso19115-4');
    const showIsoJson = isoJsonLink !== undefined || (isPreview && supportsIso19115Json);
    const openPreviewDialog = () => setIsPreviewDialogOpen(true);
    const igsnCoreLink = metadataLinks.find((link) => link.format === 'igsn-core-2.0');

    return (
        <section className="mt-6" aria-labelledby="heading-download-metadata">
            <h3 id="heading-download-metadata" className="text-lg font-semibold text-gray-900 dark:text-gray-100">
                Download Metadata
            </h3>
            <div className="flex flex-wrap items-center gap-4">
                <DarkModeImage lightSrc="/images/datacite-logo.png" darkSrc="/images/datacite-logo-light.svg" alt="DataCite" className="h-8" />

                <MetadataDownloadAction
                    href={urlFor('datacite-xml', `/resources/${resourceId}/export-datacite-xml`)}
                    icon={FileCode}
                    label="XML"
                    title="Download as DataCite XML"
                    isPreview={isPreview}
                    onPreviewClick={openPreviewDialog}
                />

                <MetadataDownloadAction
                    href={urlFor('datacite-json', `/resources/${resourceId}/export-datacite-json`)}
                    icon={FileJson}
                    label="JSON"
                    title="Download as DataCite JSON"
                    isPreview={isPreview}
                    onPreviewClick={openPreviewDialog}
                />

                <MetadataDownloadAction
                    href={urlFor('datacite-jsonld', jsonLdExportUrl ?? `/resources/${resourceId}/export-jsonld`)}
                    icon={Braces}
                    label="JSON-LD"
                    title="Download as JSON-LD (Linked Data)"
                    isPreview={isPreview}
                    onPreviewClick={openPreviewDialog}
                />
            </div>

            {(showIsoMetadata || showIsoJson) && (
                <div className="mt-4 flex flex-wrap items-center gap-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <div
                        aria-label="ISO 19115 metadata available"
                        className="inline-flex items-center overflow-hidden rounded-md border border-gfz-primary bg-white text-gfz-primary dark:border-blue-300 dark:bg-gray-800 dark:text-blue-200"
                    >
                        <span className="inline-flex items-center gap-1 bg-gfz-primary px-2.5 py-1.5 text-sm font-bold tracking-wide text-white dark:bg-blue-300 dark:text-gray-900">
                            <BadgeCheck className="h-4 w-4" aria-hidden="true" />
                            ISO{' '}
                        </span>
                        <span className="px-2.5 py-1.5 text-sm font-semibold">19115</span>
                    </div>
                    {showIsoMetadata && (
                        <MetadataDownloadAction
                            href={isoMetadataLink?.url}
                            icon={FileCode}
                            label="XML"
                            title="Download as ISO 19115-3 XML"
                            ariaLabel="Download ISO 19115-3:2023 metadata as XML"
                            isPreview={isPreview}
                            onPreviewClick={openPreviewDialog}
                        />
                    )}
                    {showIsoJson && (
                        <MetadataDownloadAction
                            href={isoJsonLink?.url}
                            icon={FileJson}
                            label="JSON"
                            title="Download as ISO/TR 19115-4 JSON (Preview)"
                            ariaLabel="Download ISO/TR 19115-4 metadata as JSON (Preview)"
                            isPreview={isPreview}
                            onPreviewClick={openPreviewDialog}
                        />
                    )}
                </div>
            )}

            {(igsnCoreLink !== undefined || (isPreview && supportsIgsnCore)) && (
                <div className="mt-4 flex flex-wrap items-center gap-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <span className="text-sm font-semibold">IGSN 2.0 Core (Preview)</span>
                    {isPreview ? (
                        <MetadataDownloadAction
                            icon={FileCode}
                            label="XML"
                            title="Download as IGSN 2.0 Core XML (Preview)"
                            isPreview
                            onPreviewClick={openPreviewDialog}
                        />
                    ) : igsnCoreLink ? (
                        <IgsnCoreDownload link={igsnCoreLink} />
                    ) : null}
                </div>
            )}

            {isPreview && (
                <Dialog open={isPreviewDialogOpen} onOpenChange={setIsPreviewDialogOpen}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Metadata download unavailable</DialogTitle>
                            <DialogDescription className="space-y-3 text-left">
                                <span className="block">Dear User,</span>
                                <span className="block">
                                    The feature you requested is only available after your dataset has been registered with DataCite.
                                </span>
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter showCloseButton />
                    </DialogContent>
                </Dialog>
            )}
        </section>
    );
}

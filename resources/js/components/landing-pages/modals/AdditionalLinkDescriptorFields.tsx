import type { Dispatch, SetStateAction } from 'react';

import type { LandingPageLink } from '@/types/landing-page';

import { ContentDescriptorFields } from './ContentDescriptorFields';

interface AdditionalLinkDescriptorFieldsProps {
    links: LandingPageLink[];
    setLinks: Dispatch<SetStateAction<LandingPageLink[]>>;
    formats: Array<{ id: number; value: string }>;
    sizes: Array<{ id: number; label: string; content_size: string }>;
}

export function AdditionalLinkDescriptorFields({ links, setLinks, formats, sizes }: AdditionalLinkDescriptorFieldsProps) {
    const downloads = links.map((link, index) => ({ link, index })).filter(({ link }) => link.kind === 'download');

    if (downloads.length === 0) {
        return null;
    }

    return (
        <div className="space-y-2 rounded-md border p-3">
            <p className="text-sm font-medium">Direct-download descriptors</p>
            {downloads.map(({ link, index }) => (
                <div key={link.id ?? link._clientId ?? index} className="space-y-1">
                    <p className="truncate text-xs text-muted-foreground">{link.label || link.url || `Download ${index + 1}`}</p>
                    <ContentDescriptorFields
                        formatId={link.format_id ?? null}
                        sizeId={link.size_id ?? null}
                        formats={formats}
                        sizes={sizes}
                        onFormatChange={(formatId) =>
                            setLinks((current) =>
                                current.map((entry, entryIndex) => (entryIndex === index ? { ...entry, format_id: formatId } : entry)),
                            )
                        }
                        onSizeChange={(sizeId) =>
                            setLinks((current) => current.map((entry, entryIndex) => (entryIndex === index ? { ...entry, size_id: sizeId } : entry)))
                        }
                        testIdPrefix={`additional-link-${index}`}
                    />
                </div>
            ))}
        </div>
    );
}

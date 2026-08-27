import { useEffect, useState } from 'react';

import type { LandingPageIgsnMetadata } from '@/types/landing-page';

import { LandingPageCard } from './LandingPageCard';

interface SampleImageSectionProps {
    igsn: LandingPageIgsnMetadata | null | undefined;
}

export function SampleImageSection({ igsn }: SampleImageSectionProps) {
    const url = igsn?.sample_image?.url?.trim() || null;
    const [failedUrl, setFailedUrl] = useState<string | null>(null);

    useEffect(() => {
        setFailedUrl(null);
    }, [url]);

    if (url === null || failedUrl === url) {
        return null;
    }

    const sampleLabel = igsn?.name?.trim() || igsn?.igsn?.trim() || 'IGSN sample';

    return (
        <LandingPageCard aria-labelledby="heading-sample-image" data-testid="sample-image-section">
            <h2 id="heading-sample-image" className="mb-4 text-lg font-semibold text-gray-900 dark:text-gray-100">
                Sample Image
            </h2>
            <a href={url} target="_blank" rel="noopener noreferrer" aria-label={`Open full-size image of ${sampleLabel}`}>
                <img
                    src={url}
                    alt={`Sample image of ${sampleLabel}`}
                    loading="lazy"
                    decoding="async"
                    className="max-h-[32rem] w-full rounded object-contain"
                    onError={() => setFailedUrl(url)}
                />
            </a>
        </LandingPageCard>
    );
}

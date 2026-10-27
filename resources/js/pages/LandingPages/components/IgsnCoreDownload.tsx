import { FileCode } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import type { LandingPageMetadataLink } from '@/types/landing-page';

/** Only a validated XML response becomes a download; server errors stay on the page. */
export function IgsnCoreDownload({ link }: { link: LandingPageMetadataLink }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string>();
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);

    const download = async () => {
        if (request.current) return;
        const controller = new AbortController();
        request.current = controller;
        setBusy(true);
        setError(undefined);
        try {
            const response = await fetch(link.url, { headers: { Accept: 'application/xml' }, signal: controller.signal });
            if (!response.ok || !response.headers.get('content-type')?.startsWith('application/xml')) {
                setError(
                    response.status === 422
                        ? 'This sample cannot be exported as IGSN Core yet. Required metadata is missing or unsupported.'
                        : 'A valid IGSN Core metadata file could not be generated. Please try again later.',
                );
                return;
            }
            const blob = await response.blob();
            if (controller.signal.aborted) return;
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = 'igsn-core-2.0.xml';
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();
            // Allow browsers to start reading the blob before releasing it.
            window.setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch {
            if (!controller.signal.aborted) setError('The metadata download failed. Please check your connection and try again.');
        } finally {
            request.current = null;
            if (!controller.signal.aborted) setBusy(false);
        }
    };

    return (
        <>
            <Button
                type="button"
                variant="outline"
                onClick={() => void download()}
                disabled={busy}
                aria-busy={busy}
                title="Download as IGSN 2.0 Core XML (Preview)"
                aria-label="Download IGSN 2.0 Core metadata as XML (Preview)"
            >
                <FileCode className="h-5 w-5" aria-hidden="true" />
                {busy ? 'Preparing XML…' : 'XML'}
            </Button>
            {link.profile && (
                <a href={link.profile} className="text-sm underline">
                    IGSN Core schema
                </a>
            )}
            {error && (
                <p role="alert" className="w-full text-sm text-red-700 dark:text-red-300">
                    {error}
                </p>
            )}
        </>
    );
}

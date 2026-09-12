import { TriangleAlert, X } from 'lucide-react';
import { type SyntheticEvent, useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';

interface HeaderLogoPreviewProps {
    src: string;
    alt: string;
    filename: string | null;
    expectedAspectRatio: number;
    expectedAspectRatioLabel: string;
    onRemove?: () => void;
}

interface LoadedImageDimensions {
    width: number;
    height: number;
    isLegacy: boolean;
}

export function isLegacyHeaderLogo(width: number, height: number, expectedAspectRatio: number): boolean {
    if (width <= 0 || height <= 0 || expectedAspectRatio <= 0) return false;

    return width !== height * expectedAspectRatio;
}

export function HeaderLogoPreview({ src, alt, filename, expectedAspectRatio, expectedAspectRatioLabel, onRemove }: HeaderLogoPreviewProps) {
    const [loadedImage, setLoadedImage] = useState<LoadedImageDimensions | null>(null);

    useEffect(() => {
        setLoadedImage(null);
    }, [src, expectedAspectRatio]);

    const handleLoad = (event: SyntheticEvent<HTMLImageElement>) => {
        const { naturalWidth: width, naturalHeight: height } = event.currentTarget;
        setLoadedImage({
            width,
            height,
            isLegacy: isLegacyHeaderLogo(width, height, expectedAspectRatio),
        });
    };

    return (
        <div className="space-y-2" data-testid="header-logo-preview">
            <div className="flex items-center gap-3 rounded-md border bg-muted/30 p-2">
                <img src={src} alt={alt} className="h-10 max-w-40 object-contain" onLoad={handleLoad} />
                <span className="flex-1 truncate text-xs text-muted-foreground">{filename}</span>
                {onRemove && (
                    <Button variant="ghost" size="icon" className="size-7" onClick={onRemove} aria-label="Remove logo">
                        <X className="size-3.5" />
                    </Button>
                )}
            </div>

            {loadedImage?.isLegacy && (
                <div
                    role="status"
                    data-testid="legacy-header-logo-notice"
                    className="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                >
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                    <p>
                        <span className="font-medium">
                            Legacy header format ({loadedImage.width} × {loadedImage.height} px).
                        </span>{' '}
                        This logo remains supported and is shown without cropping. Replace it with a {expectedAspectRatioLabel} image to use the full
                        header width.
                    </p>
                </div>
            )}
        </div>
    );
}

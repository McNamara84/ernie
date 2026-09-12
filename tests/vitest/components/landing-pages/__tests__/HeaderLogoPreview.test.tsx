import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { HeaderLogoPreview, isLegacyHeaderLogo } from '@/components/landing-pages/header-logo-preview';

function loadImage(image: HTMLImageElement, width: number, height: number) {
    Object.defineProperties(image, {
        naturalWidth: { configurable: true, value: width },
        naturalHeight: { configurable: true, value: height },
    });
    fireEvent.load(image);
}

describe('HeaderLogoPreview', () => {
    it('classifies invalid and expected dimensions safely', () => {
        expect(isLegacyHeaderLogo(1200, 240, 9)).toBe(true);
        expect(isLegacyHeaderLogo(1799, 200, 9)).toBe(true);
        expect(isLegacyHeaderLogo(1800, 200, 9)).toBe(false);
        expect(isLegacyHeaderLogo(0, 200, 9)).toBe(false);
        expect(isLegacyHeaderLogo(1800, 0, 9)).toBe(false);
        expect(isLegacyHeaderLogo(1800, 200, 0)).toBe(false);
    });

    it('does not warn for a current 9:1 logo', () => {
        render(
            <HeaderLogoPreview
                src="/storage/header.webp"
                alt="Current header"
                filename="header.webp"
                expectedAspectRatio={9}
                expectedAspectRatioLabel="9:1"
            />,
        );

        loadImage(screen.getByAltText('Current header'), 1800, 200);

        expect(screen.queryByTestId('legacy-header-logo-notice')).not.toBeInTheDocument();
    });

    it.each([
        [1200, 240],
        [1000, 300],
        [1799, 200],
    ])('warns without blocking when an existing logo is %d × %d', (width, height) => {
        render(
            <HeaderLogoPreview
                src="/storage/header.png"
                alt="Legacy header"
                filename="header.png"
                expectedAspectRatio={9}
                expectedAspectRatioLabel="9:1"
            />,
        );

        loadImage(screen.getByAltText('Legacy header'), width, height);

        const notice = screen.getByRole('status');
        expect(notice).toHaveTextContent(`Legacy header format (${width} × ${height} px).`);
        expect(notice).toHaveTextContent('This logo remains supported and is shown without cropping.');
        expect(notice).toHaveTextContent('Replace it with a 9:1 image to use the full header width.');
    });

    it('does not classify an image before it loads or when loading fails', () => {
        render(
            <HeaderLogoPreview
                src="/storage/missing.png"
                alt="Missing header"
                filename="missing.png"
                expectedAspectRatio={9}
                expectedAspectRatioLabel="9:1"
            />,
        );

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
        fireEvent.error(screen.getByAltText('Missing header'));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('resets a legacy notice when the source changes', () => {
        const { rerender } = render(
            <HeaderLogoPreview
                src="/storage/legacy.png"
                alt="Header"
                filename="legacy.png"
                expectedAspectRatio={9}
                expectedAspectRatioLabel="9:1"
            />,
        );
        loadImage(screen.getByAltText('Header'), 1200, 240);
        expect(screen.getByRole('status')).toBeInTheDocument();

        rerender(
            <HeaderLogoPreview
                src="/storage/current.webp"
                alt="Header"
                filename="current.webp"
                expectedAspectRatio={9}
                expectedAspectRatioLabel="9:1"
            />,
        );

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });

    it('offers logo removal when requested', () => {
        const onRemove = vi.fn();
        render(
            <HeaderLogoPreview
                src="/storage/header.webp"
                alt="Removable header"
                filename="header.webp"
                expectedAspectRatio={9}
                expectedAspectRatioLabel="9:1"
                onRemove={onRemove}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Remove logo' }));
        expect(onRemove).toHaveBeenCalledOnce();
    });
});

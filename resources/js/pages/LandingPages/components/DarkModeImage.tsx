interface DarkModeImageProps {
    lightSrc: string;
    darkSrc: string;
    alt: string;
    className?: string;
}

/**
 * Renders a `<picture>` element that switches between light and dark logo variants
 * based on the system color scheme preference.
 *
 * This works for landing pages because `useSystemDarkMode` syncs the `.dark` class
 * based on `prefers-color-scheme`, so the media query in `<source>` matches.
 */
export function DarkModeImage({ lightSrc, darkSrc, alt, className }: DarkModeImageProps) {
    return (
        <picture data-slot="dark-mode-image">
            <source srcSet={darkSrc} media="(prefers-color-scheme: dark)" />
            <img src={lightSrc} alt={alt} className={className} />
        </picture>
    );
}

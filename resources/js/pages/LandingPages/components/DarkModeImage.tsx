interface DarkModeImageProps {
    lightSrc: string;
    darkSrc: string;
    alt: string;
    className?: string;
}

/**
 * Renders a `<picture>` element that switches between light and dark logo variants.
 *
 * The `<source media="(prefers-color-scheme: dark)">` is evaluated natively by the
 * browser based on the operating system's color scheme setting — it does not depend
 * on any JavaScript class toggling (e.g. the `.dark` utility class).
 */
export function DarkModeImage({ lightSrc, darkSrc, alt, className }: DarkModeImageProps) {
    return (
        <picture data-slot="dark-mode-image">
            <source srcSet={darkSrc} media="(prefers-color-scheme: dark)" />
            <img src={lightSrc} alt={alt} className={className} />
        </picture>
    );
}

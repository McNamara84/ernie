import type { ReactNode } from 'react';

import { BackToTopButton } from './BackToTopButton';
import { DarkModeImage } from './DarkModeImage';
import { LandingPageToaster } from './LandingPageToaster';

interface LandingPageShellProps {
    isPreview: boolean;
    isDark: boolean;
    mainAriaLabel: string;
    customLogoUrl?: string | null;
    hero: ReactNode;
    leftColumnSections: ReactNode[];
    rightColumnSections: ReactNode[];
}

export function LandingPageShell({
    isPreview,
    isDark,
    mainAriaLabel,
    customLogoUrl,
    hero,
    leftColumnSections,
    rightColumnSections,
}: LandingPageShellProps) {
    const defaultHeaderLogoClassName = 'h-24 max-w-full object-contain dark:grayscale dark:invert dark:mix-blend-screen';

    return (
        <>
            <div data-landing-page className="min-h-screen bg-gfz-primary pt-6 dark:bg-gray-950">
                <a
                    href="#main-content"
                    className="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-gfz-primary focus:shadow-lg"
                >
                    Skip to main content
                </a>

                {isPreview && (
                    <div role="status" className="bg-yellow-400 px-4 py-2 text-center text-sm font-medium text-gray-900">
                        Preview Mode
                    </div>
                )}

                <div className="mx-auto max-w-7xl rounded-xl bg-white dark:bg-gray-900">
                    <header aria-label="GFZ Data Services" className="px-4 py-2">
                        <div className="mb-1 flex items-center justify-end gap-3">
                            <a
                                href="/legal-notice"
                                className="text-xs text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                Legal Notice
                            </a>
                            <span className="text-xs text-gray-300 dark:text-gray-600" aria-hidden="true">
                                |
                            </span>
                            <a
                                href="https://dataservices.gfz.de/web/about-us/data-protection"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-xs text-gray-600 hover:text-gray-900 hover:underline dark:text-gray-400 dark:hover:text-gray-200"
                            >
                                Data Protection
                            </a>
                        </div>
                        <div className="flex justify-center">
                            {customLogoUrl ? (
                                <img src={customLogoUrl} alt="GFZ Data Services" className="h-auto w-auto max-w-full object-contain" />
                            ) : (
                                <img src="/images/gfz-ds-logo.png" alt="GFZ Data Services" className={defaultHeaderLogoClassName} />
                            )}
                        </div>
                    </header>

                    <main id="main-content" aria-label={mainAriaLabel} tabIndex={-1}>
                        {hero}

                        <div className="mx-8 mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                            <div data-testid="landing-page-right-column" className="order-1 space-y-6 lg:order-2 lg:col-span-2">
                                {rightColumnSections}
                            </div>

                            <div data-testid="landing-page-left-column" className="order-2 space-y-6 lg:order-1 lg:col-span-1">
                                {leftColumnSections}
                            </div>
                        </div>
                    </main>

                    <footer aria-label="Institutional links" className="border-t border-gray-300 px-4 py-6 dark:border-gray-700">
                        <div className="flex items-center justify-between">
                            <a href="https://www.gfz.de" target="_blank" rel="noopener noreferrer">
                                <DarkModeImage lightSrc="/images/gfz-logo-en.gif" darkSrc="/images/gfz-logo_en.svg" alt="GFZ" className="h-12" />
                            </a>
                            <a href="https://www.helmholtz.de" target="_blank" rel="noopener noreferrer">
                                <DarkModeImage
                                    lightSrc="/images/helmholtz-logo-blue.png"
                                    darkSrc="/images/helmholtz-logo-white.svg"
                                    alt="Helmholtz"
                                    className="h-8"
                                />
                            </a>
                        </div>
                    </footer>
                </div>

                <BackToTopButton />
                <LandingPageToaster position="bottom-right" richColors theme={isDark ? 'dark' : 'light'} />
            </div>
        </>
    );
}

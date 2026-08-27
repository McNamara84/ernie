/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { LicenseAndRightsSection } from '@/pages/LandingPages/components/LicenseAndRightsSection';
import type { LandingPageLicense } from '@/types/landing-page';

const catalogLicense = (overrides: Partial<LandingPageLicense> = {}): LandingPageLicense => ({
    id: 1,
    resource_right_id: 11,
    name: 'Creative Commons Attribution 4.0 International',
    spdx_id: 'CC-BY-4.0',
    reference: 'https://creativecommons.org/licenses/by/4.0/',
    scheme_uri: 'https://spdx.org/licenses/',
    source: 'catalog',
    ...overrides,
});

describe('LicenseAndRightsSection', () => {
    it('returns null when no displayable rights exist', () => {
        const { container } = render(<LicenseAndRightsSection licenses={[]} />);

        expect(container.innerHTML).toBe('');
    });

    it('renders linked Creative Commons licenses with their conventional short name', () => {
        render(<LicenseAndRightsSection licenses={[catalogLicense()]} />);

        expect(screen.getByRole('heading', { name: 'License & Rights' })).toBeInTheDocument();
        const link = screen.getByRole('link', { name: /Creative Commons Attribution 4\.0 International \(CC BY 4\.0\)/ });
        expect(link).toHaveAttribute('href', 'https://creativecommons.org/licenses/by/4.0/');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        expect(link).toHaveAttribute('title', 'CC-BY-4.0');
    });

    it('does not duplicate a name that already is the Creative Commons short name', () => {
        render(<LicenseAndRightsSection licenses={[catalogLicense({ name: 'CC BY 4.0' })]} />);

        expect(screen.getByTestId('license-and-rights-entry')).toHaveTextContent('CC BY 4.0');
        expect(screen.queryByText(/\(CC BY 4\.0\)/)).not.toBeInTheDocument();
    });

    it('renders a linked custom catalog right without SPDX branding', () => {
        render(
            <LicenseAndRightsSection
                licenses={[
                    catalogLicense({
                        id: 2,
                        resource_right_id: 12,
                        name: 'Community Data License',
                        spdx_id: null,
                        reference: 'https://example.org/community-license',
                    }),
                ]}
            />,
        );

        const link = screen.getByRole('link', { name: 'Community Data License' });
        expect(link).toHaveAttribute('href', 'https://example.org/community-license');
        expect(screen.queryByAltText(/Creative Commons/)).not.toBeInTheDocument();
    });

    it('renders unresolved imported rights neutrally without SPDX branding', () => {
        render(
            <LicenseAndRightsSection
                licenses={[
                    {
                        id: null,
                        resource_right_id: 42,
                        name: 'Use requires individual permission.',
                        spdx_id: null,
                        reference: 'https://example.org/rights/permission',
                        source: 'raw',
                    },
                ]}
            />,
        );

        const link = screen.getByRole('link', { name: 'Use requires individual permission.' });
        expect(link).toHaveClass('bg-gray-100');
        expect(link).not.toHaveClass('bg-green-100');
        expect(screen.queryByAltText(/Creative Commons/)).not.toBeInTheDocument();
    });

    it.each(['javascript:alert(1)', 'data:text/html,test', 'info:eu-repo/semantics/restrictedAccess'])(
        'does not create a link for the unsafe or non-web URI %s',
        (reference) => {
            render(
                <LicenseAndRightsSection
                    licenses={[
                        {
                            id: null,
                            resource_right_id: 44,
                            name: 'Imported rights statement',
                            spdx_id: null,
                            reference,
                            source: 'raw',
                        },
                    ]}
                />,
            );

            expect(screen.getByText('Imported rights statement').closest('a')).toBeNull();
        },
    );

    it('renders rights without a reference as non-link text', () => {
        render(<LicenseAndRightsSection licenses={[catalogLicense({ reference: null })]} />);

        expect(screen.getByTestId('license-and-rights-entry').closest('a')).toBeNull();
    });
});

import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import FilesDownload from '@/components/landing-pages/shared/FilesDownload';

describe('FilesDownload', () => {
    const mockResourceWithLicense = {
        doi: '10.5880/GFZ.TEST.2024.001',
        licenses: [
            {
                id: 1,
                identifier: 'CC-BY-4.0',
                name: 'Creative Commons Attribution 4.0 International',
                spdx_id: 'CC-BY-4.0',
                reference: 'https://creativecommons.org/licenses/by/4.0/',
                details_url: 'https://spdx.org/licenses/CC-BY-4.0.html',
                is_osi_approved: false,
                is_fsf_libre: true,
            },
        ],
    };

    const mockResourceMultipleLicenses = {
        doi: '10.5880/GFZ.TEST.2024.002',
        licenses: [
            {
                id: 1,
                identifier: 'CC-BY-4.0',
                name: 'Creative Commons Attribution 4.0 International',
                spdx_id: 'CC-BY-4.0',
                is_fsf_libre: true,
            },
            {
                id: 2,
                identifier: 'MIT',
                name: 'MIT License',
                spdx_id: 'MIT',
                is_osi_approved: true,
                is_fsf_libre: true,
            },
        ],
    };

    const mockResourceNoLicense = {
        doi: '10.5880/GFZ.TEST.2024.003',
        licenses: [],
    };

    const mockConfigWithFtpUrl = {
        ftp_url: 'https://datapub.gfz-potsdam.de/download/custom-path',
    };

    const mockConfigEmpty = {};

    describe('Rendering', () => {
        it('should render download section with heading', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByRole('heading', { name: /download dataset/i })).toBeInTheDocument();
        });

        it('should render custom heading', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                    heading="Get Data"
                />,
            );

            expect(screen.getByRole('heading', { name: /get data/i })).toBeInTheDocument();
        });

        it('should not render when no FTP URL and no DOI', () => {
            const resourceNoDoi = { licenses: [] };
            const { container } = render(
                <FilesDownload resource={resourceNoDoi} config={mockConfigEmpty} />,
            );

            expect(container).toBeEmptyDOMElement();
        });
    });

    describe('Download Button', () => {
        it('should display download button', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const button = screen.getByRole('link', { name: /download files/i });
            expect(button).toBeInTheDocument();
        });

        it('should link to configured FTP URL', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const button = screen.getByRole('link', { name: /download files/i });
            expect(button).toHaveAttribute(
                'href',
                'https://datapub.gfz-potsdam.de/download/custom-path',
            );
            expect(button).toHaveAttribute('target', '_blank');
            expect(button).toHaveAttribute('rel', 'noopener noreferrer');
        });

        it('should build FTP URL from DOI when config is empty', () => {
            render(
                <FilesDownload resource={mockResourceWithLicense} config={mockConfigEmpty} />,
            );

            const button = screen.getByRole('link', { name: /download files/i });
            expect(button).toHaveAttribute(
                'href',
                'https://datapub.gfz-potsdam.de/download/GFZ.TEST.2024.001',
            );
        });
    });

    describe('FTP URL Display', () => {
        it('should display FTP URL', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByText(/download url:/i)).toBeInTheDocument();
            expect(
                screen.getByText('https://datapub.gfz-potsdam.de/download/custom-path'),
            ).toBeInTheDocument();
        });

        it('should make FTP URL clickable', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const urlLink = screen
                .getByText('https://datapub.gfz-potsdam.de/download/custom-path')
                .closest('a');
            expect(urlLink).toHaveAttribute(
                'href',
                'https://datapub.gfz-potsdam.de/download/custom-path',
            );
        });
    });

    describe('License Display', () => {
        it('should display license badge', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByText('CC-BY-4.0')).toBeInTheDocument();
        });

        it('should display multiple licenses', () => {
            render(
                <FilesDownload
                    resource={mockResourceMultipleLicenses}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByText('CC-BY-4.0')).toBeInTheDocument();
            expect(screen.getByText('MIT')).toBeInTheDocument();
        });

        it('should make license badge clickable when details_url exists', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const licenseLink = screen.getByText('CC-BY-4.0').closest('a');
            expect(licenseLink).toHaveAttribute(
                'href',
                'https://spdx.org/licenses/CC-BY-4.0.html',
            );
            expect(licenseLink).toHaveAttribute('target', '_blank');
        });

        it('should use reference when details_url is missing', () => {
            const resourceWithReference = {
                doi: '10.5880/test',
                licenses: [
                    {
                        identifier: 'CC-BY-4.0',
                        name: 'Creative Commons Attribution 4.0',
                        spdx_id: 'CC-BY-4.0',
                        reference: 'https://creativecommons.org/licenses/by/4.0/',
                        details_url: null,
                    },
                ],
            };

            render(
                <FilesDownload resource={resourceWithReference} config={mockConfigWithFtpUrl} />,
            );

            const licenseLink = screen.getByText('CC-BY-4.0').closest('a');
            expect(licenseLink).toHaveAttribute(
                'href',
                'https://creativecommons.org/licenses/by/4.0/',
            );
        });

        it('should not make badge clickable when no URL available', () => {
            const resourceNoUrl = {
                doi: '10.5880/test',
                licenses: [
                    {
                        identifier: 'Custom',
                        name: 'Custom License',
                        details_url: null,
                        reference: null,
                    },
                ],
            };

            render(<FilesDownload resource={resourceNoUrl} config={mockConfigWithFtpUrl} />);

            const licenseSpan = screen.getByText('Custom');
            expect(licenseSpan.tagName).toBe('SPAN');
        });

        it('should show OSI approval badge', () => {
            render(
                <FilesDownload
                    resource={mockResourceMultipleLicenses}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByText(/OSI ✓/)).toBeInTheDocument();
        });

        it('should show FSF Libre badge', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByText(/FSF ✓/)).toBeInTheDocument();
        });

        it('should display full license names', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(
                screen.getByText(/Creative Commons Attribution 4.0 International/),
            ).toBeInTheDocument();
        });

        it('should hide license details when showLicenseDetails=false', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                    showLicenseDetails={false}
                />,
            );

            expect(screen.queryByText(/OSI ✓/)).not.toBeInTheDocument();
            expect(screen.queryByText(/FSF ✓/)).not.toBeInTheDocument();
            expect(
                screen.queryByText(/Creative Commons Attribution 4.0 International/),
            ).not.toBeInTheDocument();
        });

        it('should not show license section when no licenses', () => {
            render(
                <FilesDownload resource={mockResourceNoLicense} config={mockConfigWithFtpUrl} />,
            );

            expect(screen.queryByText(/license:/i)).not.toBeInTheDocument();
        });

        it('should use singular "License" for one license', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByText(/^License:$/)).toBeInTheDocument();
        });

        it('should use plural "Licenses" for multiple', () => {
            render(
                <FilesDownload
                    resource={mockResourceMultipleLicenses}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByText(/^Licenses:$/)).toBeInTheDocument();
        });
    });

    describe('License Badge Colors', () => {
        it('should apply green color to OSI approved licenses', () => {
            render(
                <FilesDownload
                    resource={mockResourceMultipleLicenses}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const mitBadge = screen.getByText('MIT').closest('a');
            expect(mitBadge?.className).toContain('bg-green-100');
        });

        it('should apply blue color to Creative Commons licenses', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const ccBadge = screen.getByText('CC-BY-4.0').closest('a');
            expect(ccBadge?.className).toContain('bg-blue-100');
        });

        it('should apply gray color to other licenses', () => {
            const resourceCustomLicense = {
                doi: '10.5880/test',
                licenses: [
                    {
                        identifier: 'Custom',
                        name: 'Custom License',
                        is_osi_approved: false,
                        is_fsf_libre: false,
                    },
                ],
            };

            render(
                <FilesDownload resource={resourceCustomLicense} config={mockConfigWithFtpUrl} />,
            );

            const customBadge = screen.getByText('Custom');
            expect(customBadge.className).toContain('bg-gray-100');
        });
    });

    describe('Download Note', () => {
        it('should display download compliance note', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(
                screen.getByText(/By downloading this dataset, you agree to comply/),
            ).toBeInTheDocument();
        });

        it('should mention license terms when licenses exist', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            expect(screen.getByText(/license terms listed above/)).toBeInTheDocument();
        });

        it('should not mention "listed above" when no licenses', () => {
            render(
                <FilesDownload resource={mockResourceNoLicense} config={mockConfigWithFtpUrl} />,
            );

            const note = screen.getByText(/By downloading this dataset/);
            expect(note.textContent).not.toContain('listed above');
        });
    });

    describe('Edge Cases', () => {
        it('should handle license without ID using index as key', () => {
            const resourceWithoutIds = {
                doi: '10.5880/test',
                licenses: [
                    { identifier: 'MIT', name: 'MIT License', spdx_id: 'MIT' },
                    { identifier: 'Apache-2.0', name: 'Apache 2.0', spdx_id: 'Apache-2.0' },
                ],
            };

            render(
                <FilesDownload resource={resourceWithoutIds} config={mockConfigWithFtpUrl} />,
            );

            expect(screen.getByText('MIT')).toBeInTheDocument();
            expect(screen.getByText('Apache-2.0')).toBeInTheDocument();
        });

        it('should use identifier when spdx_id is missing', () => {
            const resourceNoSpdx = {
                doi: '10.5880/test',
                licenses: [
                    {
                        identifier: 'Custom License v1.0',
                        name: 'Custom License',
                        spdx_id: null,
                    },
                ],
            };

            render(<FilesDownload resource={resourceNoSpdx} config={mockConfigWithFtpUrl} />);

            expect(screen.getByText('Custom License v1.0')).toBeInTheDocument();
        });

        it('should handle very long FTP URLs', () => {
            const longUrl = `https://datapub.gfz-potsdam.de/download/${'very-long-path/'.repeat(10)}dataset.zip`;
            const configLongUrl = { ftp_url: longUrl };

            render(<FilesDownload resource={mockResourceNoLicense} config={configLongUrl} />);

            expect(screen.getByText(longUrl)).toBeInTheDocument();
        });
    });

    describe('Accessibility', () => {
        it('should have proper aria-label on section', () => {
            const { container } = render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Download Dataset');
        });

        it('should have custom aria-label when heading is custom', () => {
            const { container } = render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                    heading="Data Files"
                />,
            );

            const section = container.querySelector('section');
            expect(section).toHaveAttribute('aria-label', 'Data Files');
        });

        it('should have aria-hidden on decorative icons', () => {
            render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const icons = document.querySelectorAll('[aria-hidden="true"]');
            expect(icons.length).toBeGreaterThan(0);
        });
    });

    describe('Dark Mode Support', () => {
        it('should have dark mode classes', () => {
            const { container } = render(
                <FilesDownload
                    resource={mockResourceWithLicense}
                    config={mockConfigWithFtpUrl}
                />,
            );

            const darkElements = container.querySelectorAll('.dark\\:bg-gray-800, .dark\\:text-gray-100');
            expect(darkElements.length).toBeGreaterThan(0);
        });
    });
});

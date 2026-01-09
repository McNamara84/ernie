/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { FilesSection } from '@/pages/LandingPages/components/FilesSection';

const mockLicenses = [
    {
        id: 1,
        name: 'CC BY 4.0',
        spdx_id: 'CC-BY-4.0',
        reference: 'https://creativecommons.org/licenses/by/4.0/',
    },
    {
        id: 2,
        name: 'MIT',
        spdx_id: 'MIT',
        reference: 'https://opensource.org/licenses/MIT',
    },
];

const mockContactPersonWithEmail = {
    id: 1,
    name: 'John Doe',
    given_name: 'John',
    family_name: 'Doe',
    type: 'Person',
    orcid: null,
    website: null,
    has_email: true,
};

const mockContactPersonWithWebsite = {
    id: 2,
    name: 'Jane Smith',
    given_name: 'Jane',
    family_name: 'Smith',
    type: 'Person',
    orcid: null,
    website: 'https://example.com/jane',
    has_email: false,
};

const mockContactPersonNoContact = {
    id: 3,
    name: 'Bob Wilson',
    given_name: 'Bob',
    family_name: 'Wilson',
    type: 'Person',
    orcid: null,
    website: null,
    has_email: false,
};

describe('FilesSection', () => {
    describe('when downloadUrl is provided', () => {
        it('renders download button with correct URL', () => {
            render(
                <FilesSection
                    downloadUrl="https://datapub.gfz-potsdam.de/download/test.zip"
                    licenses={[]}
                />,
            );

            const downloadLink = screen.getByRole('link', { name: /download data and description/i });
            expect(downloadLink).toBeInTheDocument();
            expect(downloadLink).toHaveAttribute('href', 'https://datapub.gfz-potsdam.de/download/test.zip');
            expect(downloadLink).toHaveAttribute('target', '_blank');
        });

        it('shows download button even when contact person with email exists', () => {
            render(
                <FilesSection
                    downloadUrl="https://example.com/data.zip"
                    licenses={[]}
                    contactPersons={[mockContactPersonWithEmail]}
                />,
            );

            expect(screen.getByRole('link', { name: /download data and description/i })).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /request data via contact form/i })).not.toBeInTheDocument();
        });

        it('does not show contact button when download URL is available', () => {
            render(
                <FilesSection
                    downloadUrl="https://example.com/data.zip"
                    licenses={[]}
                    contactPersons={[mockContactPersonWithEmail]}
                />,
            );

            expect(screen.queryByRole('button', { name: /request data via contact form/i })).not.toBeInTheDocument();
        });
    });

    describe('when downloadUrl is not provided', () => {
        it('does not render download button when downloadUrl is undefined', () => {
            render(<FilesSection licenses={[]} />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });

        it('does not render download button when downloadUrl is null', () => {
            render(<FilesSection downloadUrl={null} licenses={[]} />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });

        it('does not render download button when downloadUrl is empty string', () => {
            render(<FilesSection downloadUrl="" licenses={[]} />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });

        it('does not render download button when downloadUrl is "#"', () => {
            render(<FilesSection downloadUrl="#" licenses={[]} />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });

        it('does not render download button when downloadUrl is whitespace only', () => {
            render(<FilesSection downloadUrl="   " licenses={[]} />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });
    });

    describe('contact form fallback (when no download URL)', () => {
        it('shows contact form button when contact person with email exists', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactPersons={[mockContactPersonWithEmail]}
                    datasetTitle="Test Dataset"
                />,
            );

            const contactButton = screen.getByRole('button', { name: /request data via contact form/i });
            expect(contactButton).toBeInTheDocument();
        });

        it('prioritizes contact person with email over website', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactPersons={[mockContactPersonWithWebsite, mockContactPersonWithEmail]}
                    datasetTitle="Test Dataset"
                />,
            );

            // Should show contact form button, not website link
            expect(screen.getByRole('button', { name: /request data via contact form/i })).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: /visit contact person website/i })).not.toBeInTheDocument();
        });
    });

    describe('website fallback (when no download URL and no email)', () => {
        it('shows website link when contact person has website but no email', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactPersons={[mockContactPersonWithWebsite]}
                    datasetTitle="Test Dataset"
                />,
            );

            const websiteLink = screen.getByRole('link', { name: /visit contact person website/i });
            expect(websiteLink).toBeInTheDocument();
            expect(websiteLink).toHaveAttribute('href', 'https://example.com/jane');
            expect(websiteLink).toHaveAttribute('target', '_blank');
        });

        it('does not show website link when contact person has no website', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactPersons={[mockContactPersonNoContact]}
                />,
            );

            expect(screen.queryByRole('link', { name: /visit contact person website/i })).not.toBeInTheDocument();
        });
    });

    describe('fallback message (when no download URL and no contact options)', () => {
        it('shows fallback message when no contact persons available', () => {
            render(<FilesSection licenses={[]} />);

            expect(screen.getByText(/download information not available/i)).toBeInTheDocument();
            expect(screen.getByText(/please contact the authors/i)).toBeInTheDocument();
        });

        it('shows fallback message when contactPersons is empty array', () => {
            render(<FilesSection licenses={[]} contactPersons={[]} />);

            expect(screen.getByText(/download information not available/i)).toBeInTheDocument();
        });

        it('shows fallback message when contact persons have neither email nor website', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactPersons={[mockContactPersonNoContact]}
                />,
            );

            expect(screen.getByText(/download information not available/i)).toBeInTheDocument();
        });
    });

    describe('license badges', () => {
        it('renders license badges when licenses are provided', () => {
            render(<FilesSection licenses={mockLicenses} />);

            // CC licenses include icon aria-label in accessible name
            expect(screen.getByRole('link', { name: /CC BY 4\.0/ })).toBeInTheDocument();
            expect(screen.getByRole('link', { name: 'MIT' })).toBeInTheDocument();
        });

        it('displays full license names correctly', () => {
            const fullNameLicenses = [
                {
                    id: 1,
                    name: 'Creative Commons Attribution 4.0 International',
                    spdx_id: 'CC-BY-4.0',
                    reference: 'https://creativecommons.org/licenses/by/4.0/',
                },
                {
                    id: 2,
                    name: 'Creative Commons Attribution Share Alike 4.0 International',
                    spdx_id: 'CC-BY-SA-4.0',
                    reference: 'https://creativecommons.org/licenses/by-sa/4.0/',
                },
            ];

            render(<FilesSection licenses={fullNameLicenses} />);

            // CC licenses include icon aria-label in accessible name
            expect(
                screen.getByRole('link', { name: /Creative Commons Attribution 4\.0 International/ }),
            ).toBeInTheDocument();
            expect(
                screen.getByRole('link', { name: /Creative Commons Attribution Share Alike 4\.0 International/ }),
            ).toBeInTheDocument();
        });

        it('license links have correct href', () => {
            render(<FilesSection licenses={mockLicenses} />);

            // CC licenses include icon aria-label in accessible name
            expect(screen.getByRole('link', { name: /CC BY 4\.0/ })).toHaveAttribute(
                'href',
                'https://creativecommons.org/licenses/by/4.0/',
            );
            expect(screen.getByRole('link', { name: 'MIT' })).toHaveAttribute(
                'href',
                'https://opensource.org/licenses/MIT',
            );
        });

        it('does not render license section when no licenses', () => {
            render(<FilesSection licenses={[]} />);

            expect(screen.queryByRole('link', { name: 'CC BY 4.0' })).not.toBeInTheDocument();
            expect(screen.queryByText('License')).not.toBeInTheDocument();
        });

        it('shows License label above license links', () => {
            render(<FilesSection licenses={mockLicenses} />);

            expect(screen.getByText('License')).toBeInTheDocument();
        });

        it('displays SPDX identifier in tooltip', () => {
            render(<FilesSection licenses={mockLicenses} />);

            // CC licenses include icon aria-label in accessible name
            const licenseLink = screen.getByRole('link', { name: /CC BY 4\.0/ });
            expect(licenseLink).toHaveAttribute('title', 'SPDX: CC-BY-4.0');
        });
    });

    describe('section header', () => {
        it('renders the Files heading', () => {
            render(<FilesSection licenses={[]} />);

            expect(screen.getByRole('heading', { name: 'Files' })).toBeInTheDocument();
        });
    });

    describe('GFZ branding', () => {
        it('applies GFZ action button styling to download button', () => {
            render(
                <FilesSection
                    downloadUrl="https://example.com/data.zip"
                    licenses={[]}
                />,
            );

            const downloadLink = screen.getByRole('link', { name: /download data and description/i });
            expect(downloadLink).toHaveClass('gfz-action-button');
        });

        it('applies GFZ action button styling to contact button', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactPersons={[mockContactPersonWithEmail]}
                />,
            );

            const contactButton = screen.getByRole('button', { name: /request data via contact form/i });
            expect(contactButton).toHaveClass('gfz-action-button');
        });

        it('applies GFZ action button styling to website link', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactPersons={[mockContactPersonWithWebsite]}
                />,
            );

            const websiteLink = screen.getByRole('link', { name: /visit contact person website/i });
            expect(websiteLink).toHaveClass('gfz-action-button');
        });
    });
});

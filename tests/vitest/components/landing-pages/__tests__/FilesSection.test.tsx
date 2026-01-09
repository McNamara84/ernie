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

        it('does not show contact form link when download URL is available', () => {
            render(
                <FilesSection
                    downloadUrl="https://example.com/data.zip"
                    licenses={[]}
                    contactUrl="https://example.com/contact"
                />,
            );

            expect(screen.queryByRole('link', { name: /request data via contact form/i })).not.toBeInTheDocument();
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

        it('shows contact form link when contactUrl is provided but no download URL', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactUrl="https://example.com/contact"
                    datasetTitle="Test Dataset"
                />,
            );

            const contactLink = screen.getByRole('link', { name: /request data via contact form/i });
            expect(contactLink).toBeInTheDocument();
            expect(contactLink).toHaveAttribute('href', expect.stringContaining('https://example.com/contact'));
            expect(contactLink).toHaveAttribute('href', expect.stringContaining('subject='));
            expect(contactLink).toHaveAttribute('target', '_blank');
        });

        it('shows fallback message when neither download URL nor contact URL is available', () => {
            render(<FilesSection licenses={[]} />);

            expect(screen.getByText(/download information not available/i)).toBeInTheDocument();
            expect(screen.getByText(/please contact the authors/i)).toBeInTheDocument();
        });
    });

    describe('license badges', () => {
        it('renders license badges when licenses are provided', () => {
            render(<FilesSection licenses={mockLicenses} />);

            expect(screen.getByRole('link', { name: 'CC BY 4.0' })).toBeInTheDocument();
            expect(screen.getByRole('link', { name: 'MIT' })).toBeInTheDocument();
        });

        it('license links have correct href', () => {
            render(<FilesSection licenses={mockLicenses} />);

            expect(screen.getByRole('link', { name: 'CC BY 4.0' })).toHaveAttribute(
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
        });
    });

    describe('section header', () => {
        it('renders the Files heading', () => {
            render(<FilesSection licenses={[]} />);

            expect(screen.getByRole('heading', { name: 'Files' })).toBeInTheDocument();
        });
    });

    describe('contact form URL encoding', () => {
        it('encodes dataset title in contact URL subject parameter', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactUrl="https://example.com/contact"
                    datasetTitle="Test & Special <Characters>"
                />,
            );

            const contactLink = screen.getByRole('link', { name: /request data via contact form/i });
            expect(contactLink).toHaveAttribute(
                'href',
                expect.stringContaining(encodeURIComponent('Data request: Test & Special <Characters>')),
            );
        });

        it('does not add subject parameter when datasetTitle is not provided', () => {
            render(
                <FilesSection
                    licenses={[]}
                    contactUrl="https://example.com/contact"
                />,
            );

            const contactLink = screen.getByRole('link', { name: /request data via contact form/i });
            expect(contactLink).toHaveAttribute('href', 'https://example.com/contact');
        });
    });
});

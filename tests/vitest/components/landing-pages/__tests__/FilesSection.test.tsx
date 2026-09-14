/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { FilesSection } from '@/pages/LandingPages/components/FilesSection';

const mockContactPersonWithEmail = {
    id: 1,
    name: 'John Doe',
    given_name: 'John',
    family_name: 'Doe',
    type: 'Person',
    source: 'creator' as const,
    affiliations: [],
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
    source: 'creator' as const,
    affiliations: [],
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
    source: 'creator' as const,
    affiliations: [],
    orcid: null,
    website: null,
    has_email: false,
};

describe('FilesSection', () => {
    describe('when downloadUrl is provided', () => {
        it('renders download button with correct URL', () => {
            render(<FilesSection downloadUrl="https://datapub.gfz-potsdam.de/download/test.zip" />);

            const downloadLink = screen.getByRole('link', { name: /download data and description/i });
            expect(downloadLink).toBeInTheDocument();
            expect(downloadLink).toHaveAttribute('href', 'https://datapub.gfz-potsdam.de/download/test.zip');
            expect(downloadLink).toHaveAttribute('target', '_blank');
            expect(downloadLink).toHaveAttribute('title', 'https://datapub.gfz-potsdam.de/download/test.zip');
        });

        it('renders a configured primary label', () => {
            render(<FilesSection downloadUrl="https://example.com/data.zip" downloadLabel="Download data via IGETS Database" />);

            expect(screen.getByRole('link', { name: 'Download data via IGETS Database' })).toBeInTheDocument();
        });

        it('shows download button even when contact person with email exists', () => {
            render(<FilesSection downloadUrl="https://example.com/data.zip" contactPersons={[mockContactPersonWithEmail]} />);

            expect(screen.getByRole('link', { name: /download data and description/i })).toBeInTheDocument();
            expect(screen.queryByRole('button', { name: /request data via contact form/i })).not.toBeInTheDocument();
        });

        it('does not show contact button when download URL is available', () => {
            render(<FilesSection downloadUrl="https://example.com/data.zip" contactPersons={[mockContactPersonWithEmail]} />);

            expect(screen.queryByRole('button', { name: /request data via contact form/i })).not.toBeInTheDocument();
        });
    });

    describe('when downloadUrl is not provided', () => {
        it('does not render download button when downloadUrl is undefined', () => {
            render(<FilesSection />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });

        it('does not render download button when downloadUrl is null', () => {
            render(<FilesSection downloadUrl={null} />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });

        it('does not render download button when downloadUrl is empty string', () => {
            render(<FilesSection downloadUrl="" />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });

        it('does not render download button when downloadUrl is "#"', () => {
            render(<FilesSection downloadUrl="#" />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });

        it('does not render download button when downloadUrl is whitespace only', () => {
            render(<FilesSection downloadUrl="   " />);

            expect(screen.queryByRole('link', { name: /download data and description/i })).not.toBeInTheDocument();
        });
    });

    describe('data request fallback (when no download URL)', () => {
        it('shows the request heading and form button when a contact person exists', () => {
            render(<FilesSection contactPersons={[mockContactPersonWithEmail]} datasetTitle="Test Dataset" />);

            expect(
                screen.getByRole('heading', {
                    name: 'The dataset is not available for automated download. Please fill in the request form to receive download information.',
                }),
            ).toBeInTheDocument();
            const contactButton = screen.getByRole('button', { name: /request data via contact form/i });
            expect(contactButton).toBeInTheDocument();
        });

        it('uses the form instead of a website-only fallback', () => {
            render(<FilesSection contactPersons={[mockContactPersonWithWebsite, mockContactPersonWithEmail]} datasetTitle="Test Dataset" />);

            expect(screen.getByRole('button', { name: /request data via contact form/i })).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: /visit contact person website/i })).not.toBeInTheDocument();
        });

        it('uses the team request when a contact person has a website but no email', () => {
            render(<FilesSection contactPersons={[mockContactPersonWithWebsite]} datasetTitle="Test Dataset" hasDataPublicationTeamRecipient />);

            expect(screen.getByRole('button', { name: /request data via contact form/i })).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: /visit contact person website/i })).not.toBeInTheDocument();
        });

        it('uses the team request when no contact option exists', () => {
            render(<FilesSection contactPersons={[mockContactPersonNoContact]} hasDataPublicationTeamRecipient />);

            expect(screen.getByRole('button', { name: /request data via contact form/i })).toBeInTheDocument();
            expect(screen.queryByRole('link', { name: /visit contact person website/i })).not.toBeInTheDocument();
        });

        it('uses the team request when the contact list is omitted', () => {
            render(<FilesSection hasDataPublicationTeamRecipient />);

            expect(screen.getByRole('button', { name: /request data via contact form/i })).toBeInTheDocument();
            expect(screen.queryByText(/download information not available/i)).not.toBeInTheDocument();
        });
    });

    describe('section header', () => {
        it('renders the Files heading', () => {
            render(<FilesSection downloadUrl="https://example.com/data.zip" />);

            expect(screen.getByRole('heading', { name: 'Files' })).toBeInTheDocument();
        });
    });

    describe('GFZ branding', () => {
        it('applies GFZ action button styling to download button', () => {
            render(<FilesSection downloadUrl="https://example.com/data.zip" />);

            const downloadLink = screen.getByRole('link', { name: /download data and description/i });
            expect(downloadLink).toHaveClass('gfz-action-button');
        });

        it('applies GFZ action button styling to contact button', () => {
            render(<FilesSection contactPersons={[mockContactPersonWithEmail]} />);

            const contactButton = screen.getByRole('button', { name: /request data via contact form/i });
            expect(contactButton).toHaveClass('gfz-action-button');
        });

        it('does not fall back to a website-only action', () => {
            render(<FilesSection contactPersons={[mockContactPersonWithWebsite]} />);

            expect(screen.queryByRole('link', { name: /visit contact person website/i })).not.toBeInTheDocument();
        });
    });
});

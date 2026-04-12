/**
 * @vitest-environment jsdom
 */
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { FilesSection } from '@/pages/LandingPages/components/FilesSection';

const mockLicenses = [
    { id: 1, name: 'CC BY 4.0', spdx_id: 'CC-BY-4.0', reference: 'https://creativecommons.org/licenses/by/4.0/' },
];

describe('FilesSection', () => {
    it('renders download link when downloadUrl is provided', () => {
        render(<FilesSection downloadUrl="https://example.com/files" licenses={mockLicenses} />);

        expect(screen.getByText('Download data and description')).toBeInTheDocument();
    });

    it('renders fallback message when no download or contacts', () => {
        render(<FilesSection licenses={[]} />);

        expect(screen.getByText(/Download information not available/)).toBeInTheDocument();
    });

    it('renders contact form button when contact with email exists', () => {
        const contactPersons = [
            {
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
            },
        ];

        render(<FilesSection licenses={[]} contactPersons={contactPersons} />);

        expect(screen.getByText('Request data via contact form')).toBeInTheDocument();
    });

    it('renders website link when contact has website but no email', () => {
        const contactPersons = [
            {
                id: 2,
                name: 'Jane Smith',
                given_name: 'Jane',
                family_name: 'Smith',
                type: 'Person',
                source: 'creator' as const,
                affiliations: [],
                orcid: null,
                website: 'https://jane.example.com',
                has_email: false,
            },
        ];

        render(<FilesSection licenses={[]} contactPersons={contactPersons} />);

        expect(screen.getByText('Visit contact person website')).toBeInTheDocument();
    });

    it('renders license badges', () => {
        render(<FilesSection licenses={mockLicenses} />);

        expect(screen.getByText('CC BY 4.0')).toBeInTheDocument();
    });

    it('does not render download link for empty or hash URL', () => {
        render(<FilesSection downloadUrl="#" licenses={[]} />);

        expect(screen.queryByText('Download data and description')).not.toBeInTheDocument();
    });

    it('prefers download over contact fallback', () => {
        const contactPersons = [
            {
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
            },
        ];

        render(<FilesSection downloadUrl="https://example.com/files" licenses={[]} contactPersons={contactPersons} />);

        expect(screen.getByText('Download data and description')).toBeInTheDocument();
        expect(screen.queryByText('Request data via contact form')).not.toBeInTheDocument();
    });

    describe('Additional Links', () => {
        const mockLinks = [
            { id: 1, url: 'https://gitlab.com/example/repo', label: 'GitLab Repository', position: 0 },
            { id: 2, url: 'https://example.com/project', label: 'Project Website', position: 1 },
        ];

        it('renders additional links below download', () => {
            render(<FilesSection downloadUrl="https://example.com/files" licenses={[]} additionalLinks={mockLinks} />);

            expect(screen.getByText('GitLab Repository')).toBeInTheDocument();
            expect(screen.getByText('Project Website')).toBeInTheDocument();
        });

        it('renders additional links with correct styling', () => {
            render(<FilesSection downloadUrl="https://example.com/files" licenses={[]} additionalLinks={mockLinks} />);

            const link = screen.getByText('GitLab Repository').closest('a');
            expect(link).toHaveClass('bg-gray-100');
            expect(link).toHaveClass('text-gray-700');
        });

        it('renders links in position order', () => {
            const unorderedLinks = [
                { id: 2, url: 'https://example.com/second', label: 'Second', position: 1 },
                { id: 1, url: 'https://example.com/first', label: 'First', position: 0 },
            ];

            render(<FilesSection downloadUrl="https://example.com/files" licenses={[]} additionalLinks={unorderedLinks} />);

            const links = screen.getAllByRole('link');
            const additionalLinkTexts = links.filter((l) => l.classList.contains('bg-gray-100')).map((l) => l.textContent);
            expect(additionalLinkTexts).toEqual(['First', 'Second']);
        });

        it('does not render section when additionalLinks is empty', () => {
            render(<FilesSection downloadUrl="https://example.com/files" licenses={[]} additionalLinks={[]} />);

            expect(screen.queryByText('GitLab Repository')).not.toBeInTheDocument();
        });

        it('opens links in new tab with security attributes', () => {
            render(<FilesSection downloadUrl="https://example.com/files" licenses={[]} additionalLinks={mockLinks} />);

            const link = screen.getByText('GitLab Repository').closest('a');
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        });
    });
});

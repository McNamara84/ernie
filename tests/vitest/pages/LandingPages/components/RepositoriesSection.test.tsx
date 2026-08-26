import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { RepositoriesSection } from '@/pages/LandingPages/components/RepositoriesSection';
import type { LandingPageIgsnMetadata } from '@/types/landing-page';

const metadata = (overrides: Partial<LandingPageIgsnMetadata> = {}): LandingPageIgsnMetadata => ({
    igsn: null,
    name: null,
    user_code: null,
    sample_type: null,
    material: null,
    cruise_field_program: null,
    sample_purpose: null,
    collection_method: null,
    collection_method_description: null,
    collection_date_precision: null,
    depth_min: null,
    depth_max: null,
    depth_scale: null,
    coordinate_system: null,
    sample_access: null,
    comments: [],
    current_archive: null,
    current_archive_contact: null,
    original_archive: null,
    original_archive_contact: null,
    sizes: [],
    geological_units: [],
    parent: null,
    ...overrides,
});

describe('RepositoriesSection', () => {
    it('collapses when every repository value is empty', () => {
        const { container } = render(<RepositoriesSection igsn={metadata()} datasetTitle="Sample" />);

        expect(container.firstChild).toBeNull();
    });

    it('renders the approved GFLMU repository and sample-access values', () => {
        render(
            <RepositoriesSection
                igsn={metadata({
                    current_archive: 'GFZ German Research Centre for Geosciences, Potsdam, Germany',
                    current_archive_contact: 'Lena Muhl',
                    original_archive: 'GFZ German Research Centre for Geosciences, Potsdam, Germany',
                    original_archive_contact: 'Guido Blöcher',
                    sample_access: 'Private',
                })}
                datasetTitle="Sample"
            />,
        );

        expect(screen.getByRole('heading', { name: 'Repositories' })).toBeInTheDocument();
        expect(screen.getByText('Lena Muhl')).toBeInTheDocument();
        expect(screen.getByText('Guido Blöcher')).toBeInTheDocument();
        expect(screen.getByText('Private')).toBeInTheDocument();
    });

    it('opens independent protected forms without rendering an email address', async () => {
        const user = userEvent.setup();
        render(
            <RepositoriesSection
                datasetTitle="Sensitive sample"
                igsn={metadata({
                    current_archive: 'BGR Berlin',
                    current_archive_contact: 'Tina Kollaske',
                    original_archive: 'Legacy Archive',
                    original_archive_contact: 'Legacy Archive contact',
                    repository_contacts: [
                        { type: 'current', label: 'Tina Kollaske', has_email: true },
                        { type: 'original', label: 'Legacy Archive contact', has_email: true },
                    ],
                })}
            />,
        );

        expect(document.body).not.toHaveTextContent('Tina.Kollaske@bgr.de');
        expect(screen.getByRole('button', { name: 'Contact current repository' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Contact original repository' })).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Contact current repository' }));

        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('Sensitive sample')).toBeInTheDocument();
        expect(screen.getAllByText('Tina Kollaske')).toHaveLength(2);
    });

    it('enables a protected legacy contact only for a complete email address', () => {
        render(<RepositoriesSection igsn={metadata({ current_archive_contact: 'Archive Team <archive@example.org>' })} datasetTitle="Sample" />);

        expect(screen.getByText('Current repository contact')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Contact current repository' })).toBeInTheDocument();
        expect(document.body).not.toHaveTextContent('archive@example.org');
    });

    it.each(['broken@address', 'victim@example.org@attacker.com'])('does not expose or enable malformed legacy contact %s', (contact) => {
        render(<RepositoriesSection igsn={metadata({ current_archive_contact: contact })} datasetTitle="Sample" />);

        expect(screen.getByText('Current repository contact')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Contact current repository' })).not.toBeInTheDocument();
        expect(document.body).not.toHaveTextContent(contact);
    });
});

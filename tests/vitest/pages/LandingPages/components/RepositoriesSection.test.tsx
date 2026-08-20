import { render, screen } from '@testing-library/react';
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
        const { container } = render(<RepositoriesSection igsn={metadata()} />);

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
            />,
        );

        expect(screen.getByRole('heading', { name: 'Repositories' })).toBeInTheDocument();
        expect(screen.getByText('Lena Muhl')).toBeInTheDocument();
        expect(screen.getByText('Guido Blöcher')).toBeInTheDocument();
        expect(screen.getByText('Private')).toBeInTheDocument();
    });
});

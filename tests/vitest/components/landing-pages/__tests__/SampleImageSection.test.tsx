import { fireEvent, screen } from '@testing-library/react';
import { render } from '@tests/vitest/utils/render';
import { describe, expect, it } from 'vitest';

import { SampleImageSection } from '@/pages/LandingPages/components/SampleImageSection';
import type { LandingPageIgsnMetadata } from '@/types/landing-page';

function metadata(url: string): LandingPageIgsnMetadata {
    return {
        igsn: 'GFSO273N39',
        name: 'SO273-31D-18',
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
        sample_image: { url, hosting: 'managed' },
    };
}

describe('SampleImageSection', () => {
    it('renders an accessible lazy full-size image link', () => {
        render(<SampleImageSection igsn={metadata('/storage/igsn-sample-images/sample.jpg')} />);

        const image = screen.getByRole('img', { name: 'Sample image of SO273-31D-18' });
        expect(image).toHaveAttribute('src', '/storage/igsn-sample-images/sample.jpg');
        expect(image).toHaveAttribute('loading', 'lazy');
        expect(image).toHaveAttribute('decoding', 'async');
        expect(image.closest('a')).toHaveAttribute('target', '_blank');
        expect(image.closest('a')).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('renders nothing without a URL and removes the complete card after an image error', () => {
        const { rerender } = render(<SampleImageSection igsn={{ ...metadata(''), sample_image: null }} />);
        expect(screen.queryByTestId('sample-image-section')).not.toBeInTheDocument();

        rerender(<SampleImageSection igsn={metadata('https://data.icdp-online.org/sites/cosc/sample.jpg')} />);
        fireEvent.error(screen.getByRole('img'));
        expect(screen.queryByTestId('sample-image-section')).not.toBeInTheDocument();
    });

    it('falls back to the IGSN in its alternative text', () => {
        render(<SampleImageSection igsn={{ ...metadata('/sample.jpg'), name: null }} />);
        expect(screen.getByAltText('Sample image of GFSO273N39')).toBeInTheDocument();
    });
});

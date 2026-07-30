import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ContentDescriptorFields } from '@/components/landing-pages/modals/ContentDescriptorFields';

describe('ContentDescriptorFields', () => {
    it('associates both select triggers with their visible labels', () => {
        render(
            <ContentDescriptorFields
                formatId={null}
                sizeId={null}
                formats={[]}
                sizes={[]}
                onFormatChange={vi.fn()}
                onSizeChange={vi.fn()}
                testIdPrefix="primary-descriptor"
            />,
        );

        expect(screen.getByRole('combobox', { name: 'MIME type' })).toHaveAttribute('aria-labelledby', 'primary-descriptor-format-label');
        expect(screen.getByRole('combobox', { name: 'Digital size' })).toHaveAttribute('aria-labelledby', 'primary-descriptor-size-label');
    });
});

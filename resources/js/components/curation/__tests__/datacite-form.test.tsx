import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, describe, it, expect } from 'vitest';
import DataCiteForm from '../datacite-form';
import { LANGUAGE_OPTIONS } from '@/constants/languages';
import type { ResourceType } from '@/types';

describe('DataCiteForm', () => {
    beforeAll(() => {
        // Polyfill methods required by Radix UI Select
        Element.prototype.hasPointerCapture = () => false;
        Element.prototype.setPointerCapture = () => {};
        Element.prototype.releasePointerCapture = () => {};
        Element.prototype.scrollIntoView = () => {};
    });

    const resourceTypes: ResourceType[] = [
        { id: 1, name: 'Dataset', slug: 'dataset' },
    ];

    it('renders fields and language options', async () => {
        render(<DataCiteForm resourceTypes={resourceTypes} />);
        // basic fields
        expect(screen.getByLabelText('DOI')).toBeInTheDocument();
        expect(screen.getByLabelText('Year')).toBeInTheDocument();
        expect(screen.getByLabelText('Version')).toBeInTheDocument();

        // resource type option
        const resourceTypeTrigger = screen.getByLabelText('Resource Type');
        const user = userEvent.setup({ pointerEventsCheck: 0 });
        await user.click(resourceTypeTrigger);
        expect(
            await screen.findByRole('option', { name: 'Dataset' }),
        ).toBeInTheDocument();

        // language options
        const languageTrigger = screen.getByLabelText('Language of Data');
        await user.click(languageTrigger);
        for (const option of LANGUAGE_OPTIONS) {
            expect(
                await screen.findByRole('option', { name: option.label }),
            ).toBeInTheDocument();
        }
    });
});

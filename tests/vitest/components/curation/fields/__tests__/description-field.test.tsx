import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DescriptionField, { type DescriptionEntry } from '@/components/curation/fields/description-field';
import type { DescriptionType, Language } from '@/types';

const allDescriptionTypes: DescriptionType[] = [
    { id: 1, name: 'Abstract', slug: 'Abstract' },
    { id: 2, name: 'Methods', slug: 'Methods' },
    { id: 3, name: 'SeriesInformation', slug: 'SeriesInformation' },
    { id: 4, name: 'TableOfContents', slug: 'TableOfContents' },
    { id: 5, name: 'TechnicalInfo', slug: 'TechnicalInfo' },
    { id: 6, name: 'Other', slug: 'Other' },
];

const languages: Language[] = [
    { id: 1, code: 'en', name: 'English' },
    { id: 2, code: 'de', name: 'German' },
];

const abstract = (id: string, value = ''): DescriptionEntry => ({ id, type: 'Abstract', value, language: null });

function DescriptionHarness({ initialDescriptions }: { initialDescriptions: DescriptionEntry[] }) {
    const [descriptions, setDescriptions] = useState(initialDescriptions);

    return (
        <DescriptionField
            descriptions={descriptions}
            onChange={setDescriptions}
            availableTypes={allDescriptionTypes}
            languages={languages}
        />
    );
}

describe('DescriptionField', () => {
    const onChange = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the formatting notice and the current entries', () => {
        render(<DescriptionHarness initialDescriptions={[abstract('abstract-1')]} />);

        expect(screen.getByRole('alert')).toHaveTextContent(/Landing pages support a limited HTML subset/i);
        expect(screen.getByPlaceholderText(/Enter a brief summary/i)).toBeInTheDocument();
        expect(screen.getByText('(Required)')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add Description' })).toBeEnabled();
    });

    it('preserves and renders repeated description types independently', () => {
        render(
            <DescriptionHarness
                initialDescriptions={[
                    abstract('abstract-1', 'First abstract with enough content to pass the length rule.'),
                    abstract('abstract-2', 'Second abstract with different content and enough characters.'),
                    { id: 'methods-1', type: 'Methods', value: 'First method', language: 'en' },
                    { id: 'methods-2', type: 'Methods', value: 'Second method', language: 'de' },
                ]}
            />,
        );

        expect(screen.getAllByPlaceholderText(/Enter a brief summary/i)).toHaveLength(2);
        expect(screen.getAllByPlaceholderText(/Describe the methods used/i)).toHaveLength(2);
        expect(screen.getByDisplayValue('First method')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Second method')).toBeInTheDocument();
    });

    it('updates only the entry identified by its stable id', async () => {
        const user = userEvent.setup();
        render(
            <DescriptionHarness
                initialDescriptions={[
                    { id: 'methods-1', type: 'Methods', value: 'First', language: null },
                    { id: 'methods-2', type: 'Methods', value: 'Second', language: null },
                ]}
            />,
        );

        const [firstTextarea] = screen.getAllByPlaceholderText(/Describe the methods used/i);
        await user.clear(firstTextarea);
        await user.type(firstTextarea, 'Changed');

        expect(screen.getByDisplayValue('Changed')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Second')).toBeInTheDocument();
    });

    it('adds another description without an item cap', async () => {
        const user = userEvent.setup();
        const existing = Array.from({ length: 101 }, (_, index) => ({
            id: `other-${index}`,
            type: 'Other' as const,
            value: `Description ${index}`,
            language: null,
        }));
        render(<DescriptionHarness initialDescriptions={existing} />);

        await user.click(screen.getByRole('button', { name: 'Add Description' }));

        expect(screen.getAllByTestId('description-entry')).toHaveLength(102);
    });

    it('removes the selected description and leaves the others untouched', async () => {
        const user = userEvent.setup();
        render(
            <DescriptionHarness
                initialDescriptions={[
                    abstract('abstract-1', 'Abstract value'),
                    { id: 'methods-1', type: 'Methods', value: 'Methods value', language: null },
                ]}
            />,
        );

        await user.click(screen.getByRole('button', { name: 'Remove description 1' }));

        expect(screen.queryByDisplayValue('Abstract value')).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('Methods value')).toBeInTheDocument();
    });

    it('reports validation feedback for a missing Abstract', () => {
        render(
            <DescriptionField
                descriptions={[]}
                onChange={onChange}
                availableTypes={allDescriptionTypes}
                languages={languages}
                abstractTouched
                abstractValidationMessages={[{ severity: 'error', message: 'Abstract is required' }]}
            />,
        );

        expect(screen.getByText('Abstract is required')).toBeInTheDocument();
    });

    it('validates an additional Abstract independently after the field was touched', () => {
        render(
            <DescriptionField
                descriptions={[
                    abstract('abstract-1', 'A valid abstract containing more than fifty characters for this regression test.'),
                    abstract('abstract-2', 'Too short'),
                ]}
                onChange={onChange}
                availableTypes={allDescriptionTypes}
                languages={languages}
                abstractTouched
            />,
        );

        expect(screen.getByText('Abstract must be between 50 and 17,500 characters.')).toBeInTheDocument();
        expect(screen.getAllByPlaceholderText(/Enter a brief summary/i)[1]).toHaveAttribute('aria-invalid', 'true');
    });

    it('calls Abstract blur validation and displays localized character counts', async () => {
        const user = userEvent.setup();
        const onBlur = vi.fn();
        render(
            <DescriptionField
                descriptions={[abstract('abstract-1', 'Test')]}
                onChange={onChange}
                availableTypes={allDescriptionTypes}
                languages={languages}
                onAbstractValidationBlur={onBlur}
            />,
        );

        await user.click(screen.getByTestId('abstract-textarea'));
        await user.tab();

        expect(onBlur).toHaveBeenCalledOnce();
        expect(screen.getByText(/4 characters/)).toBeInTheDocument();
        expect(screen.getByText(/46 more needed/)).toBeInTheDocument();
    });
});

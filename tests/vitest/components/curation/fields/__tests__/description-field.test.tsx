import userEvent from '@testing-library/user-event';
import { render, screen, within } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import DescriptionField, { DESCRIPTION_LANGUAGE_CODES, type DescriptionEntry } from '@/components/curation/fields/description-field';
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
    { id: 3, code: 'fr', name: 'French' },
];

const abstract = (id: string, value = '', language: string | null = null): DescriptionEntry => ({
    id,
    type: 'Abstract',
    value,
    language,
});

function DescriptionHarness({ initialDescriptions }: { initialDescriptions: DescriptionEntry[] }) {
    const [descriptions, setDescriptions] = useState(initialDescriptions);

    return <DescriptionField descriptions={descriptions} onChange={setDescriptions} availableTypes={allDescriptionTypes} languages={languages} />;
}

const groupFor = (type: DescriptionEntry['type']): HTMLElement => {
    const group = screen.getAllByTestId('description-entry').find((element) => element.dataset.descriptionType === type);

    if (!group) throw new Error(`Description group ${type} not found`);

    return group;
};

describe('DescriptionField', () => {
    const onChange = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders the formatting notice and a type-grouped Abstract', () => {
        render(<DescriptionHarness initialDescriptions={[abstract('abstract-1')]} />);

        expect(screen.getByRole('alert')).toHaveTextContent(/Landing pages support a limited HTML subset/i);
        expect(groupFor('Abstract')).toContainElement(screen.getByPlaceholderText(/Enter a brief summary/i));
        expect(screen.getByText('(Required)')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add Description Type' })).toBeEnabled();
    });

    it('groups repeated description types and switches their language variants with tabs', async () => {
        const user = userEvent.setup();
        render(
            <DescriptionHarness
                initialDescriptions={[
                    abstract('abstract-de', 'Deutsche Zusammenfassung mit ausreichend langem Beispieltext.', 'de'),
                    abstract('abstract-en', 'English abstract with enough content for this example resource.', 'en'),
                    { id: 'methods-en', type: 'Methods', value: 'English methods', language: 'en' },
                    { id: 'methods-de', type: 'Methods', value: 'Deutsche Methoden', language: 'de' },
                ]}
            />,
        );

        expect(screen.getAllByTestId('description-entry')).toHaveLength(2);
        const abstractGroup = groupFor('Abstract');
        expect(within(abstractGroup).getByRole('tab', { name: 'German (de)' })).toBeInTheDocument();
        expect(within(abstractGroup).getByRole('tab', { name: 'English (en)' })).toBeInTheDocument();
        expect(screen.getByDisplayValue('Deutsche Zusammenfassung mit ausreichend langem Beispieltext.')).toBeInTheDocument();

        await user.click(within(abstractGroup).getByRole('tab', { name: 'English (en)' }));
        expect(screen.getByDisplayValue('English abstract with enough content for this example resource.')).toBeInTheDocument();
    });

    it('offers exactly German and English when adding a language version', async () => {
        const user = userEvent.setup();
        render(<DescriptionHarness initialDescriptions={[abstract('abstract-1', 'Unassigned abstract')]} />);

        expect(DESCRIPTION_LANGUAGE_CODES).toEqual(['de', 'en']);
        await user.click(within(groupFor('Abstract')).getByRole('button', { name: 'Add language version' }));

        expect(screen.getByRole('menuitem', { name: 'German (de)' })).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'English (en)' })).toBeInTheDocument();
        expect(screen.queryByRole('menuitem', { name: /French/ })).not.toBeInTheDocument();
    });

    it('adds and activates a selected language version without changing its sibling', async () => {
        const user = userEvent.setup();
        render(<DescriptionHarness initialDescriptions={[abstract('abstract-de', 'Bestehender deutscher Text', 'de')]} />);

        await user.click(within(groupFor('Abstract')).getByRole('button', { name: 'Add language version' }));
        await user.click(screen.getByRole('menuitem', { name: 'English (en)' }));

        const abstractGroup = groupFor('Abstract');
        expect(within(abstractGroup).getByRole('tab', { name: 'German (de)' })).toBeInTheDocument();
        expect(within(abstractGroup).getByRole('tab', { name: 'English (en)' })).toHaveAttribute('data-state', 'active');
        expect(screen.getByPlaceholderText(/Enter a brief summary/i)).toHaveValue('');

        await user.click(within(abstractGroup).getByRole('tab', { name: 'German (de)' }));
        expect(screen.getByDisplayValue('Bestehender deutscher Text')).toBeInTheDocument();
    });

    it('preserves and numbers imported duplicate languages and missing language values', () => {
        render(
            <DescriptionHarness
                initialDescriptions={[
                    abstract('abstract-en-1', 'First English', 'en'),
                    abstract('abstract-en-2', 'Second English', 'en'),
                    abstract('abstract-null-1', 'First unknown'),
                    abstract('abstract-null-2', 'Second unknown'),
                ]}
            />,
        );

        const group = groupFor('Abstract');
        expect(within(group).getByRole('tab', { name: 'English (en) 1' })).toBeInTheDocument();
        expect(within(group).getByRole('tab', { name: 'English (en) 2' })).toBeInTheDocument();
        expect(within(group).getByRole('tab', { name: 'Language not specified 1' })).toBeInTheDocument();
        expect(within(group).getByRole('tab', { name: 'Language not specified 2' })).toBeInTheDocument();
    });

    it('keeps an imported BCP-47 language visible without offering it for new variants', async () => {
        const user = userEvent.setup();
        render(<DescriptionHarness initialDescriptions={[abstract('abstract-imported', 'Canadian English text', 'en-CA')]} />);

        expect(within(groupFor('Abstract')).getByRole('tab', { name: 'Imported language (en-ca)' })).toBeInTheDocument();
        await user.click(within(groupFor('Abstract')).getByRole('button', { name: 'Add language version' }));
        expect(screen.queryByRole('menuitem', { name: /en-ca/i })).not.toBeInTheDocument();
    });

    it('updates only the active entry identified by its stable id', async () => {
        const user = userEvent.setup();
        render(
            <DescriptionHarness
                initialDescriptions={[
                    { id: 'methods-en', type: 'Methods', value: 'First', language: 'en' },
                    { id: 'methods-de', type: 'Methods', value: 'Second', language: 'de' },
                ]}
            />,
        );

        const firstTextarea = screen.getByPlaceholderText(/Describe the methods used/i);
        await user.clear(firstTextarea);
        await user.type(firstTextarea, 'Changed');
        await user.click(within(groupFor('Methods')).getByRole('tab', { name: 'German (de)' }));

        expect(screen.getByDisplayValue('Second')).toBeInTheDocument();
        await user.click(within(groupFor('Methods')).getByRole('tab', { name: 'English (en)' }));
        expect(screen.getByDisplayValue('Changed')).toBeInTheDocument();
    });

    it('adds a new description type group', async () => {
        const user = userEvent.setup();
        render(<DescriptionHarness initialDescriptions={[abstract('abstract-1')]} />);

        await user.click(screen.getByRole('button', { name: 'Add Description Type' }));
        await user.click(screen.getByRole('menuitem', { name: 'Methods' }));

        expect(groupFor('Methods')).toBeInTheDocument();
        expect(screen.getAllByTestId('description-entry')).toHaveLength(2);
    });

    it('removes only the active version and removes the group with its final version', async () => {
        const user = userEvent.setup();
        render(
            <DescriptionHarness
                initialDescriptions={[
                    abstract('abstract-en', 'English abstract', 'en'),
                    abstract('abstract-de', 'Deutscher Abstract', 'de'),
                    { id: 'methods-en', type: 'Methods', value: 'Methods value', language: 'en' },
                ]}
            />,
        );

        await user.click(within(groupFor('Abstract')).getByRole('button', { name: 'Remove Abstract en' }));
        expect(within(groupFor('Abstract')).queryByRole('tab', { name: 'English (en)' })).not.toBeInTheDocument();
        expect(within(groupFor('Abstract')).getByRole('tab', { name: 'German (de)' })).toBeInTheDocument();

        await user.click(within(groupFor('Methods')).getByRole('button', { name: 'Remove Methods en' }));
        expect(screen.queryByText('Methods value')).not.toBeInTheDocument();
        expect(screen.getAllByTestId('description-entry')).toHaveLength(1);
    });

    it('reports validation feedback for a missing Abstract', () => {
        render(
            <DescriptionField
                descriptions={[]}
                onChange={onChange}
                availableTypes={allDescriptionTypes}
                languages={languages}
                validationTouched
                validationMessages={[{ severity: 'error', message: 'Abstract is required' }]}
            />,
        );

        expect(screen.getByText('Abstract is required')).toBeInTheDocument();
    });

    it('associates validation feedback with the affected language tab', async () => {
        const user = userEvent.setup();
        render(
            <DescriptionField
                descriptions={[
                    abstract('abstract-en', 'A valid abstract containing more than fifty characters for this regression test.', 'en'),
                    abstract('abstract-de', 'Zu kurz', 'de'),
                ]}
                onChange={onChange}
                availableTypes={allDescriptionTypes}
                languages={languages}
                validationTouched
                validationMessages={[
                    {
                        severity: 'error',
                        message: 'German Abstract must be at least 50 characters',
                        fieldId: 'abstract-de',
                    },
                ]}
            />,
        );

        expect(screen.queryByText('German Abstract must be at least 50 characters')).not.toBeInTheDocument();
        await user.click(within(groupFor('Abstract')).getByRole('tab', { name: 'German (de)' }));
        expect(screen.getByText('German Abstract must be at least 50 characters')).toBeInTheDocument();
        expect(screen.getByPlaceholderText(/Enter a brief summary/i)).toHaveAttribute('aria-invalid', 'true');
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
        expect(screen.getByTestId('abstract-character-count')).toHaveTextContent('4 characters');
        expect(screen.getByTestId('abstract-character-count')).toHaveTextContent('46 more needed');
    });
});

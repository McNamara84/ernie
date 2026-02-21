import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';

import AuthorField, {
    type InstitutionAuthorEntry,
    type PersonAuthorEntry,
} from '@/components/curation/fields/author-field';
import type { AffiliationSuggestion } from '@/types/affiliations';

const { createTagifyMock } = await vi.hoisted(() => import('@test-helpers/tagify-mock'));
vi.mock('@yaireo/tagify', () => createTagifyMock());

describe('AuthorField', () => {
    const basePersonAuthor: PersonAuthorEntry = {
        id: 'author-1',
        type: 'person',
        orcid: '',
        firstName: '',
        lastName: 'Doe',
        email: '',
        website: '',
        isContact: false,
        affiliations: [],
        affiliationsInput: '',
    };

    const baseInstitutionAuthor: InstitutionAuthorEntry = {
        id: 'author-2',
        type: 'institution',
        institutionName: 'Institute',
        affiliations: [],
        affiliationsInput: '',
    };

    const suggestions: AffiliationSuggestion[] = [
        {
            value: 'Example University',
            rorId: 'https://ror.org/01',
            searchTerms: ['Example University'],
            country: 'Germany',
            countryCode: 'DE',
        },
    ];

    it('calls onAffiliationsChange with selected ROR identifiers', () => {
        const handleAffiliationsChange = vi.fn();

        render(
            <AuthorField
                author={basePersonAuthor}
                index={0}
                onTypeChange={vi.fn()}
                onPersonFieldChange={vi.fn()}
                onInstitutionNameChange={vi.fn()}
                onContactChange={vi.fn()}
                onAffiliationsChange={handleAffiliationsChange}
                onRemoveAuthor={vi.fn()}
                canRemove
                onAddAuthor={vi.fn()}
                canAddAuthor={false}
                affiliationSuggestions={suggestions}
            />,
        );

        const affiliationInput = screen.getByTestId('author-0-affiliations-input') as HTMLInputElement & {
            tagify: { addTags: (value: unknown) => void };
        };

        act(() => {
            affiliationInput.tagify.addTags([
                { value: 'Example University', rorId: 'https://ror.org/01' },
            ]);
        });

        expect(handleAffiliationsChange).toHaveBeenCalledWith({
            raw: 'Example University',
            tags: [
                {
                    value: 'Example University',
                    rorId: 'https://ror.org/01',
                },
            ],
        });
    });

    it('renders institution authors without crashing', () => {
        render(
            <AuthorField
                author={baseInstitutionAuthor}
                index={1}
                onTypeChange={vi.fn()}
                onPersonFieldChange={vi.fn()}
                onInstitutionNameChange={vi.fn()}
                onContactChange={vi.fn()}
                onAffiliationsChange={vi.fn()}
                onRemoveAuthor={vi.fn()}
                canRemove
                onAddAuthor={vi.fn()}
                canAddAuthor={false}
                affiliationSuggestions={suggestions}
            />,
        );

        expect(
            screen.getByRole('textbox', { name: /Institution name/i }),
        ).toBeInTheDocument();
    });
});

import '@testing-library/jest-dom/vitest';

import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import ContributorField, {
    type InstitutionContributorEntry,
    type PersonContributorEntry,
} from '@/components/curation/fields/contributor-field';
import type { AffiliationSuggestion } from '@/types/affiliations';

const { createTagifyMock } = await vi.hoisted(() => import('@test-helpers/tagify-mock'));
vi.mock('@yaireo/tagify', () => createTagifyMock());

describe('ContributorField', () => {
    const basePersonContributor: PersonContributorEntry = {
        id: 'contributor-1',
        type: 'person',
        roles: [],
        rolesInput: '',
        orcid: '',
        firstName: '',
        lastName: 'Doe',
        affiliations: [],
        affiliationsInput: '',
    };

    const baseInstitutionContributor: InstitutionContributorEntry = {
        id: 'contributor-2',
        type: 'institution',
        roles: [],
        rolesInput: '',
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
    const personRoleOptions = ['Researcher', 'Editor', 'Rights Holder'];
    const institutionRoleOptions = ['Hosting Institution', 'Rights Holder'];

    it('renders contributor specific fields without contact checkbox', () => {
        render(
            <ContributorField
                contributor={basePersonContributor}
                index={0}
                onTypeChange={vi.fn()}
                onRolesChange={vi.fn()}
                onPersonFieldChange={vi.fn()}
                onInstitutionNameChange={vi.fn()}
                onAffiliationsChange={vi.fn()}
                onRemoveContributor={vi.fn()}
                canRemove
                onAddContributor={vi.fn()}
                canAddContributor
                affiliationSuggestions={suggestions}
                personRoleOptions={personRoleOptions}
                institutionRoleOptions={institutionRoleOptions}
            />,
        );

        expect(screen.getByText('Contributor type')).toBeInTheDocument();
        expect(screen.getByLabelText(/^Roles/)).toBeInTheDocument();
        expect(screen.queryByText('CP')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Email address')).not.toBeInTheDocument();
        expect(screen.queryByLabelText('Website')).not.toBeInTheDocument();

        const typeContainer = screen.getByTestId('contributor-0-type-field');
        const rolesContainer = screen.getByTestId('contributor-0-roles-field');
        expect(typeContainer).toHaveClass('md:col-span-6');
        expect(rolesContainer).toHaveClass('md:col-span-6');
        expect(rolesContainer).toHaveClass('lg:col-span-8');

        const orcidContainer = screen.getByTestId('contributor-0-orcid-field');
        expect(orcidContainer).toHaveClass('lg:col-span-4');

        const roleInput = screen.getByTestId('contributor-0-roles-input') as HTMLInputElement & {
            tagify: { addTags: (values: unknown) => void };
        };

        act(() => {
            roleInput.tagify.addTags([personRoleOptions[0]]);
        });

        expect(roleInput.value).toContain('Researcher');

        const addButtons = screen.getAllByRole('button', { name: /Add contributor/i });
        expect(addButtons.length).toBeGreaterThan(0);
    });

    it('notifies about role selections with unique, valid entries', () => {
        const handleRolesChange = vi.fn();

        render(
            <ContributorField
                contributor={basePersonContributor}
                index={0}
                onTypeChange={vi.fn()}
                onRolesChange={handleRolesChange}
                onPersonFieldChange={vi.fn()}
                onInstitutionNameChange={vi.fn()}
                onAffiliationsChange={vi.fn()}
                onRemoveContributor={vi.fn()}
                canRemove
                onAddContributor={vi.fn()}
                canAddContributor
                affiliationSuggestions={suggestions}
                personRoleOptions={personRoleOptions}
                institutionRoleOptions={institutionRoleOptions}
            />,
        );

        const roleInput = screen.getByTestId('contributor-0-roles-input') as HTMLInputElement & {
            tagify: { addTags: (values: unknown) => void };
        };

        act(() => {
            roleInput.tagify.addTags([
                { value: personRoleOptions[0] },
                { value: personRoleOptions[0] },
                { value: personRoleOptions[1] },
            ]);
        });

        expect(handleRolesChange).toHaveBeenCalledWith({
            raw: `${personRoleOptions[0]}, ${personRoleOptions[0]}, ${personRoleOptions[1]}`,
            tags: [
                { value: personRoleOptions[0] },
                { value: personRoleOptions[0] },
                { value: personRoleOptions[1] },
            ],
        });
    });

    it('disables role input and announces missing options when no person roles are available', () => {
        render(
            <ContributorField
                contributor={basePersonContributor}
                index={0}
                onTypeChange={vi.fn()}
                onRolesChange={vi.fn()}
                onPersonFieldChange={vi.fn()}
                onInstitutionNameChange={vi.fn()}
                onAffiliationsChange={vi.fn()}
                onRemoveContributor={vi.fn()}
                canRemove
                onAddContributor={vi.fn()}
                canAddContributor
                affiliationSuggestions={suggestions}
                personRoleOptions={[]}
                institutionRoleOptions={institutionRoleOptions}
            />,
        );

        const roleInput = screen.getByTestId('contributor-0-roles-input') as HTMLInputElement;
        expect(roleInput).toBeDisabled();
        expect(
            screen.getByText('No roles are available for person contributors yet.'),
        ).toBeInTheDocument();
    });

    it('renders institutions with roles input available', () => {
        render(
            <ContributorField
                contributor={baseInstitutionContributor}
                index={1}
                onTypeChange={vi.fn()}
                onRolesChange={vi.fn()}
                onPersonFieldChange={vi.fn()}
                onInstitutionNameChange={vi.fn()}
                onAffiliationsChange={vi.fn()}
                onRemoveContributor={vi.fn()}
                canRemove
                onAddContributor={vi.fn()}
                canAddContributor={false}
                affiliationSuggestions={suggestions}
                personRoleOptions={personRoleOptions}
                institutionRoleOptions={institutionRoleOptions}
            />,
        );

        expect(screen.getByLabelText(/^Roles/)).toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: /Institution name/i }),
        ).toBeInTheDocument();
    });
});

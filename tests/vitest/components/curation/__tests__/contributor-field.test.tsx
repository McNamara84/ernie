import '@testing-library/jest-dom/vitest';
import { act, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import ContributorField, {
    type InstitutionContributorEntry,
    type PersonContributorEntry,
    CONTRIBUTOR_ROLE_OPTIONS,
} from '@/components/curation/fields/contributor-field';
import type { AffiliationSuggestion } from '@/types/affiliations';

vi.mock('@yaireo/tagify', () => {
    type ChangeHandler = (event: CustomEvent) => void;

    type NormalisedTag = { value: string; rorId: string | null };

    type MockTagifyValue = { value: string; rorId: string | null; data: { rorId: string | null } };

    class MockTagify {
        public DOM: { scope: HTMLElement; input: HTMLInputElement };
        public value: MockTagifyValue[] = [];
        private inputElement: HTMLInputElement;
        private handlers = new Map<string, Set<ChangeHandler>>();

        constructor(inputElement: HTMLInputElement) {
            this.inputElement = inputElement;
            const scope = document.createElement('div');
            scope.className = 'tagify';
            const input = document.createElement('input');
            input.className = 'tagify__input';
            this.DOM = { scope, input };
            const parent = inputElement.parentElement;
            if (parent) {
                parent.appendChild(scope);
            }
            scope.appendChild(input);
        }

        on(event: string, handler: ChangeHandler) {
            if (!this.handlers.has(event)) {
                this.handlers.set(event, new Set());
            }
            this.handlers.get(event)!.add(handler);
        }

        off(event: string, handler: ChangeHandler) {
            this.handlers.get(event)?.delete(handler);
        }

        destroy() {
            this.handlers.clear();
            this.DOM.scope.remove();
        }

        setReadonly(readonly: boolean) {
            if (readonly) {
                this.DOM.input.setAttribute('readonly', '');
            } else {
                this.DOM.input.removeAttribute('readonly');
            }
        }

        removeAllTags() {
            this.value = [];
            this.renderTags([]);
            this.emitChange('');
        }

        addTags(tags: Array<string | Record<string, unknown>> | string, _skipInvalid?: boolean, silent?: boolean) {
            const incoming = Array.isArray(tags) ? tags : [tags];
            const processed = incoming
                .map((tag) => this.normaliseTag(tag))
                .filter((tag): tag is NormalisedTag => Boolean(tag));
            this.renderTags(processed);
            if (!silent) {
                this.emitChange(processed.map((tag) => tag.value).join(', '));
            }
        }

        loadOriginalValues(raw: string) {
            const processed = raw
                .split(',')
                .map((value) => value.trim())
                .filter((value) => value.length > 0);
            this.renderTags(processed.map((value) => ({ value, rorId: null })));
        }

        private normaliseTag(tag: unknown): NormalisedTag | null {
            if (typeof tag === 'string') {
                const trimmed = tag.trim();
                return trimmed ? { value: trimmed, rorId: null } : null;
            }

            if (!tag || typeof tag !== 'object') {
                return null;
            }

            const raw = tag as Record<string, unknown>;
            const value = typeof raw.value === 'string' ? raw.value.trim() : '';

            if (!value) {
                return null;
            }

            const rorId = typeof raw.rorId === 'string'
                ? raw.rorId
                : raw.rorId === null
                    ? null
                    : null;

            return { value, rorId };
        }

        private renderTags(values: NormalisedTag[]) {
            this.value = values.map((tag) => ({
                value: tag.value,
                rorId: tag.rorId,
                data: { rorId: tag.rorId },
            }));
            this.inputElement.value = values.map((tag) => tag.value).join(', ');
            const existingTags = this.DOM.scope.querySelectorAll('.tagify__tag');
            existingTags.forEach((tag) => tag.remove());
            for (const item of values) {
                const tagElement = document.createElement('span');
                tagElement.className = 'tagify__tag';
                const tagText = document.createElement('span');
                tagText.className = 'tagify__tag-text';
                tagText.textContent = item.value;
                tagElement.appendChild(tagText);
                this.DOM.scope.insertBefore(tagElement, this.DOM.input);
            }
        }

        private emitChange(raw: string) {
            const handlers = this.handlers.get('change');
            if (!handlers || handlers.size === 0) {
                return;
            }
            const event = new CustomEvent('change', {
                detail: { value: raw, tagify: this },
            }) as CustomEvent;
            handlers.forEach((handler) => handler(event));
        }
    }

    return { default: MockTagify };
});

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
            />,
        );

        expect(screen.getByText('Contributor type')).toBeInTheDocument();
        expect(screen.getByLabelText('Roles')).toBeInTheDocument();
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
            roleInput.tagify.addTags(['Researcher']);
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
            />,
        );

        const roleInput = screen.getByTestId('contributor-0-roles-input') as HTMLInputElement & {
            tagify: { addTags: (values: unknown) => void };
        };

        act(() => {
            roleInput.tagify.addTags([
                { value: CONTRIBUTOR_ROLE_OPTIONS[0] },
                { value: CONTRIBUTOR_ROLE_OPTIONS[0] },
                { value: CONTRIBUTOR_ROLE_OPTIONS[1] },
            ]);
        });

        expect(handleRolesChange).toHaveBeenCalledWith({
            raw: `${CONTRIBUTOR_ROLE_OPTIONS[0]}, ${CONTRIBUTOR_ROLE_OPTIONS[0]}, ${CONTRIBUTOR_ROLE_OPTIONS[1]}`,
            tags: [
                { value: CONTRIBUTOR_ROLE_OPTIONS[0] },
                { value: CONTRIBUTOR_ROLE_OPTIONS[0] },
                { value: CONTRIBUTOR_ROLE_OPTIONS[1] },
            ],
        });
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
            />,
        );

        expect(screen.getByLabelText('Roles')).toBeInTheDocument();
        expect(
            screen.getByRole('textbox', { name: /Institution name/i }),
        ).toBeInTheDocument();
    });
});

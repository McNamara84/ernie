import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import { act } from 'react';
import { describe, expect, it, vi } from 'vitest';
import AuthorField, {
    type PersonAuthorEntry,
    type InstitutionAuthorEntry,
} from '@/components/curation/fields/author-field';
import type { AffiliationSuggestion } from '@/types/affiliations';

vi.mock('@yaireo/tagify', () => {
    type ChangeHandler = (event: CustomEvent) => void;
    type NormalisedTag = { value: string; rorId: string | null };

    class MockTagify {
        public DOM: { scope: HTMLElement; input: HTMLInputElement };
        public value: Array<{ value: string; rorId: string | null; data: { rorId: string | null } }> = [];
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
            for (const tag of values) {
                const tagElement = document.createElement('span');
                tagElement.className = 'tagify__tag';
                const tagText = document.createElement('span');
                tagText.className = 'tagify__tag-text';
                tagText.textContent = tag.value;
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

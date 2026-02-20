/**
 * Shared MockTagify Class
 *
 * A complete mock implementation of @yaireo/tagify for use in Vitest tests.
 * This replaces the ~100-line inline MockTagify definitions that were
 * duplicated across datacite-form.test.tsx, author-field.test.tsx, and
 * contributor-field.test.tsx.
 *
 * @example
 * // In your test file:
 * import { createTagifyMock } from '@test-helpers/tagify-mock';
 * vi.mock('@yaireo/tagify', () => createTagifyMock());
 */

// ============================================================================
// Types
// ============================================================================

type ChangeHandler = (event: CustomEvent) => void;

interface NormalisedTag {
    value: string;
    rorId: string | null;
}

interface MockTagifyValue {
    value: string;
    rorId: string | null;
    data: { rorId: string | null };
}

// ============================================================================
// MockTagify Class
// ============================================================================

export class MockTagify {
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

    addTags(
        tags: Array<string | Record<string, unknown>> | string,
        _skipInvalid?: boolean,
        silent?: boolean,
    ) {
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

        const rorId = typeof raw.rorId === 'string' ? raw.rorId : null;
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

// ============================================================================
// Mock Factory
// ============================================================================

/**
 * Creates a `@yaireo/tagify` module mock using MockTagify.
 *
 * @example
 * vi.mock('@yaireo/tagify', () => createTagifyMock());
 */
export function createTagifyMock() {
    return { default: MockTagify };
}

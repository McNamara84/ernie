import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

// Mock Tagify since it requires a real DOM environment
vi.mock('@yaireo/tagify', () => {
    return {
        default: class MockTagify {
            DOM = {
                scope: {
                    dataset: {} as Record<string, string>,
                },
            };
            value: Array<{ value: string }> = [];
            whitelist: string[] = [];
            settings = { dropdown: { enabled: 0 } };
            constructor() {}
            addTags() {}
            removeAllTags() {}
            destroy() {}
            setReadonly() {}
            on() { return this; }
            off() { return this; }
        },
    };
});

import TagInputField from '@/components/curation/fields/tag-input-field';

describe('TagInputField', () => {
    it('renders with label', () => {
        render(
            <TagInputField
                id="test-tags"
                label="Keywords"
                value={[]}
                onChange={vi.fn()}
            />,
        );
        expect(screen.getByText('Keywords')).toBeInTheDocument();
    });

    it('hides label when hideLabel is true', () => {
        render(
            <TagInputField
                id="test-tags"
                label="Keywords"
                hideLabel
                value={[]}
                onChange={vi.fn()}
            />,
        );
        // Label should be visually hidden but still in DOM for accessibility
        const label = screen.getByText('Keywords');
        expect(label).toHaveClass('sr-only');
    });

    it('renders with placeholder', () => {
        render(
            <TagInputField
                id="test-tags"
                label="Keywords"
                value={[]}
                onChange={vi.fn()}
                placeholder="Enter keywords"
            />,
        );
        // The input element should exist
        const input = document.querySelector('#test-tags');
        expect(input).toBeInTheDocument();
    });

    it('renders the underlying input element', () => {
        render(
            <TagInputField
                id="tags"
                label="Tags"
                value={[{ value: 'climate' }]}
                onChange={vi.fn()}
            />,
        );
        const input = document.querySelector('#tags') as HTMLInputElement;
        expect(input).toBeInTheDocument();
    });

    it('applies data-testid', () => {
        render(
            <TagInputField
                id="tags"
                label="Tags"
                value={[]}
                onChange={vi.fn()}
                data-testid="custom-tag-input"
            />,
        );
        const input = document.querySelector('#tags');
        expect(input).toHaveAttribute('data-testid', 'custom-tag-input');
    });

    it('renders with disabled state', () => {
        render(
            <TagInputField
                id="tags"
                label="Tags"
                value={[]}
                onChange={vi.fn()}
                disabled
            />,
        );
        const input = document.querySelector('#tags');
        expect(input).toBeDisabled();
    });
});

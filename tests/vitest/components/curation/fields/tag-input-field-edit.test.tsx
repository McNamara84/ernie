/**
 * @vitest-environment jsdom
 */

import { createTagifyMock, MockTagify } from '@test-helpers/tagify-mock';
import { cleanup, render } from '@tests/vitest/utils/render';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { TagInputField } from '@/components/curation/fields/tag-input-field';

vi.mock('@yaireo/tagify', () => createTagifyMock());

/**
 * Helper: get the MockTagify instance attached to the input element.
 */
function getTagifyInstance(inputId: string): MockTagify {
    const input = document.getElementById(inputId) as HTMLInputElement & { tagify?: MockTagify };
    if (!input?.tagify) {
        throw new Error(`Tagify instance not found on #${inputId}`);
    }
    return input.tagify;
}

function getDropdownSettings(tagify: MockTagify): { enabled: number | boolean } {
    const dropdown = tagify.settings.dropdown;
    if (!dropdown || typeof dropdown !== 'object') {
        throw new Error('Expected dropdown settings to exist');
    }
    return dropdown as { enabled: number | boolean };
}

function getAutoCompleteSettings(tagify: MockTagify): { enabled: boolean } {
    const autoComplete = tagify.settings.autoComplete;
    if (!autoComplete || typeof autoComplete !== 'object') {
        throw new Error('Expected autoComplete settings to exist');
    }
    return autoComplete as { enabled: boolean };
}

describe('TagInputField — edit mode rorId preservation', () => {
    afterEach(() => {
        cleanup();
    });

    it('preserves rorId when a tag is edited', () => {
        const onChange = vi.fn();
        const rorId = 'https://ror.org/04z8jg394';

        render(
            <TagInputField
                id="affiliations"
                label="Affiliations"
                value={[{ value: 'GFZ Helmholtz Centre for Geosciences', rorId }]}
                onChange={onChange}
            />,
        );

        const tagify = getTagifyInstance('affiliations');

        // Simulate edit:start — should capture the rorId
        const tagData = { value: 'GFZ Helmholtz Centre for Geosciences', rorId };
        tagify.emit('edit:start', { data: tagData });

        // Simulate edit:updated — the tag data should get the rorId back
        const editedTagData: Record<string, unknown> = {
            value: 'GFZ Helmholtz Centre for Geosciences, Potsdam, Germany',
        };
        tagify.emit('edit:updated', { data: editedTagData });

        expect(editedTagData.rorId).toBe(rorId);
    });

    it('suspends delimiter during edit of tag with rorId and restores it after', () => {
        const onChange = vi.fn();
        const rorId = 'https://ror.org/04z8jg394';

        render(
            <TagInputField
                id="affiliations"
                label="Affiliations"
                value={[{ value: 'GFZ Helmholtz Centre for Geosciences', rorId }]}
                onChange={onChange}
            />,
        );

        const tagify = getTagifyInstance('affiliations');

        // Initially delimiter should be comma
        expect(tagify.settings.delimiters).toBe(',');

        // Simulate edit:start on tag with rorId — delimiter should be suspended
        tagify.emit('edit:start', { data: { value: 'GFZ Helmholtz Centre for Geosciences', rorId } });
        expect(tagify.settings.delimiters).toBeInstanceOf(RegExp);
        expect(','.match(tagify.settings.delimiters as RegExp)).toBeNull();
        expect(getDropdownSettings(tagify).enabled).toBe(false);
        expect(getAutoCompleteSettings(tagify).enabled).toBe(false);

        // Simulate edit:updated — delimiter should be restored
        tagify.emit('edit:updated', { data: { value: 'GFZ Helmholtz Centre for Geosciences, Potsdam, Germany' } });
        expect(tagify.settings.delimiters).toBe(',');
        expect(getDropdownSettings(tagify).enabled).toBe(0);
        expect(getAutoCompleteSettings(tagify).enabled).toBe(true);
    });

    it('does not suspend delimiter when editing a tag without rorId', () => {
        const onChange = vi.fn();

        render(
            <TagInputField
                id="keywords"
                label="Keywords"
                value={[{ value: 'Geology', rorId: null }]}
                onChange={onChange}
            />,
        );

        const tagify = getTagifyInstance('keywords');

        expect(tagify.settings.delimiters).toBe(',');

        // Edit a tag without rorId — delimiter should remain unchanged
        tagify.emit('edit:start', { data: { value: 'Geology', rorId: null } });
        expect(tagify.settings.delimiters).toBe(',');
    });

    it('restores delimiter when edit is cancelled via Escape key', () => {
        const onChange = vi.fn();
        const rorId = 'https://ror.org/04z8jg394';

        render(
            <TagInputField
                id="affiliations"
                label="Affiliations"
                value={[{ value: 'GFZ Helmholtz Centre for Geosciences', rorId }]}
                onChange={onChange}
            />,
        );

        const tagify = getTagifyInstance('affiliations');

        // Start edit on tag with rorId — delimiter suspended
        tagify.emit('edit:start', { data: { value: 'GFZ Helmholtz Centre for Geosciences', rorId } });
        expect(tagify.settings.delimiters).toBeInstanceOf(RegExp);

        // Cancel edit (Escape) — edit:keydown fires with Escape key
        tagify.emit('edit:keydown', { event: new KeyboardEvent('keydown', { key: 'Escape' }) });
        expect(tagify.settings.delimiters).toBe(',');
    });

    it('does not set rorId on edited tag if original tag had no rorId', () => {
        const onChange = vi.fn();

        render(
            <TagInputField
                id="affiliations"
                label="Affiliations"
                value={[{ value: 'Custom Institution', rorId: null }]}
                onChange={onChange}
            />,
        );

        const tagify = getTagifyInstance('affiliations');

        // Start edit on a tag without rorId
        tagify.emit('edit:start', { data: { value: 'Custom Institution', rorId: null } });

        // Finish edit
        const editedTagData: Record<string, unknown> = { value: 'Custom Institution, Berlin' };
        tagify.emit('edit:updated', { data: editedTagData });

        // rorId should NOT be set since the original had none
        expect(editedTagData.rorId).toBeUndefined();
    });

    it('keeps an edited ROR affiliation label exactly as typed and preserves rorId', () => {
        const onChange = vi.fn();
        const rorId = 'https://ror.org/04z8jg394';
        const editedValue = 'GFZ Helmholtz Centre for Geosciences, Potsdam, Germany';

        render(
            <TagInputField
                id="affiliations"
                label="Affiliations"
                value={[{ value: 'GFZ Helmholtz Centre for Geosciences', rorId }]}
                onChange={onChange}
                tagifySettings={{
                    whitelist: [{ value: 'GFZ Helmholtz Centre for Geosciences', rorId, searchTerms: ['GFZ'] }],
                    dropdown: {
                        enabled: 1,
                        maxItems: 20,
                        closeOnSelect: true,
                        searchKeys: ['value', 'searchTerms'],
                    },
                }}
            />,
        );

        const tagify = getTagifyInstance('affiliations');
        expect(getDropdownSettings(tagify).enabled).toBe(1);

        tagify.emit('edit:start', { data: { value: 'GFZ Helmholtz Centre for Geosciences', rorId } });
        expect(getDropdownSettings(tagify).enabled).toBe(false);
        expect(getAutoCompleteSettings(tagify).enabled).toBe(false);

        const editedTagData: Record<string, unknown> = { value: editedValue };
        tagify.value = [{ value: editedValue, rorId, data: { rorId } }];
        tagify.emit('edit:updated', { data: editedTagData });
        tagify.emit('change', { value: editedValue, tagify });

        expect(editedTagData).toEqual({ value: editedValue, rorId });
        expect(editedValue.startsWith('(')).toBe(false);
        expect(onChange).toHaveBeenLastCalledWith({
            raw: editedValue,
            tags: [{ value: editedValue, rorId }],
        });
        expect(getDropdownSettings(tagify).enabled).toBe(1);
        expect(getAutoCompleteSettings(tagify).enabled).toBe(true);
    });

    it('keeps dropdown suspended if affiliation suggestions load while a ROR tag is being edited', () => {
        const onChange = vi.fn();
        const rorId = 'https://ror.org/04z8jg394';
        const initialValue = [{ value: 'GFZ Helmholtz Centre for Geosciences', rorId }];

        const { rerender } = render(
            <TagInputField
                id="affiliations"
                label="Affiliations"
                value={initialValue}
                onChange={onChange}
                tagifySettings={{
                    whitelist: [],
                    dropdown: { enabled: 0 },
                }}
            />,
        );

        const tagify = getTagifyInstance('affiliations');
        tagify.emit('edit:start', { data: { value: 'GFZ Helmholtz Centre for Geosciences', rorId } });

        rerender(
            <TagInputField
                id="affiliations"
                label="Affiliations"
                value={initialValue}
                onChange={onChange}
                tagifySettings={{
                    whitelist: [{ value: 'GFZ Helmholtz Centre for Geosciences', rorId, searchTerms: ['GFZ'] }],
                    dropdown: { enabled: 1 },
                }}
            />,
        );

        expect(getDropdownSettings(tagify).enabled).toBe(false);

        tagify.emit('edit:updated', { data: { value: 'GFZ Helmholtz Centre for Geosciences, Potsdam, Germany' } });
        expect(getDropdownSettings(tagify).enabled).toBe(1);
    });
});

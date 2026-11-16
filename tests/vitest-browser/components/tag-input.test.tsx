import { useState } from 'react';

import { TagInputField, type TagInputItem } from '@/components/curation/fields/tag-input-field';

import { expect, page, render, test, vi } from '../helpers/test';

test('adds and removes affiliation tags with the real Tagify implementation', async ({ user }) => {
    const onChange = vi.fn();
    function Tags() {
        const [value, setValue] = useState<TagInputItem[]>([]);
        return (
            <TagInputField
                id="affiliations"
                label="Affiliations"
                value={value}
                onChange={(detail) => {
                    setValue(detail.tags);
                    onChange(detail);
                }}
            />
        );
    }
    await render(<Tags />);
    const input = page.getByTestId('affiliations-tagify').getByRole('textbox');
    await input.fill('GFZ Potsdam');
    await user.keyboard('{Enter}');
    await expect.poll(() => onChange.mock.lastCall?.[0].tags.map((tag: TagInputItem) => tag.value)).toEqual(['GFZ Potsdam']);
    const remove = document.querySelector<HTMLElement>('.tagify__tag__removeBtn');
    expect(remove).not.toBeNull();
    await page.elementLocator(remove!).click();
    await expect.poll(() => onChange.mock.lastCall?.[0].tags).toEqual([]);
});

test('edits an existing tag and releases Tagify DOM nodes when unmounted', async ({ user }) => {
    const onChange = vi.fn();
    const view = await render(<TagInputField id="keywords" label="Keywords" value={[{ value: 'Old name' }]} onChange={onChange} />);
    const tag = page.getByText('Old name', { exact: true });
    await tag.dblClick();
    const editable = document.querySelector<HTMLElement>('.tagify__tag [contenteditable="true"]');
    expect(editable).not.toBeNull();
    await page.elementLocator(editable!).fill('New name');
    await user.keyboard('{Enter}');
    await expect.poll(() => onChange.mock.lastCall?.[0].tags.map((item: TagInputItem) => item.value)).toEqual(['New name']);
    await view.unmount();
    expect(document.querySelector('.tagify')).toBeNull();
});

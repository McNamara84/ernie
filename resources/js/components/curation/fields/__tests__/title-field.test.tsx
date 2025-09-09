import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import TitleField from '../title-field';

describe('TitleField', () => {
    it('renders add button for first row', async () => {
        const onAdd = vi.fn();
        render(
            <TitleField
                index={0}
                title=""
                titleType=""
                options={[]}
                onTitleChange={() => {}}
                onTypeChange={() => {}}
                onAdd={onAdd}
                onRemove={() => {}}
                isFirst
            />,
        );
        const addButton = screen.getByRole('button', { name: 'Add title' });
        await userEvent.click(addButton);
        expect(onAdd).toHaveBeenCalled();
    });

    it('renders remove button for other rows', async () => {
        const onRemove = vi.fn();
        render(
            <TitleField
                index={1}
                title=""
                titleType=""
                options={[]}
                onTitleChange={() => {}}
                onTypeChange={() => {}}
                onAdd={() => {}}
                onRemove={onRemove}
                isFirst={false}
            />, 
        );
        const removeButton = screen.getByRole('button', { name: 'Remove title' });
        await userEvent.click(removeButton);
        expect(onRemove).toHaveBeenCalled();
    });

    it('hides labels for non-first rows', () => {
        render(
            <TitleField
                index={1}
                title=""
                titleType=""
                options={[]}
                onTitleChange={() => {}}
                onTypeChange={() => {}}
                onAdd={() => {}}
                onRemove={() => {}}
                isFirst={false}
            />,
        );
        expect(screen.getByText('Title')).toHaveClass('sr-only');
        expect(screen.getByText('Title Type')).toHaveClass('sr-only');
    });
});

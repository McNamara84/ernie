import '@testing-library/jest-dom/vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, it, expect, vi } from 'vitest';
import TitleField from '@/components/curation/fields/title-field';

describe('TitleField', () => {
    it('renders add button for first row', async () => {
        const onAdd = vi.fn();
        render(
            <TitleField
                id="row-0"
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
                id="row-1"
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
                id="row-1"
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

    it('disables add button when cannot add', () => {
        render(
            <TitleField
                id="row-0"
                title=""
                titleType=""
                options={[]}
                onTitleChange={() => {}}
                onTypeChange={() => {}}
                onAdd={() => {}}
                onRemove={() => {}}
                isFirst
                canAdd={false}
            />,
        );
        expect(
            screen.getByRole('button', { name: 'Add title' }),
        ).toBeDisabled();
    });

    it('marks title input as required only for main title type', () => {
        const { rerender } = render(
            <TitleField
                id="row-0"
                title=""
                titleType="main-title"
                options={[]}
                onTitleChange={() => {}}
                onTypeChange={() => {}}
                onAdd={() => {}}
                onRemove={() => {}}
                isFirst
            />,
        );
        const mainInput = screen.getByRole('textbox', { name: /Title/ });
        expect(mainInput).toBeRequired();
        const mainLabel = screen
            .getAllByText(/Title/, { selector: 'label' })
            .filter((l) => ['Title', 'Title*'].includes(l.textContent?.trim() ?? ''))[0];
        expect(mainLabel).toHaveTextContent('*');

        rerender(
            <TitleField
                id="row-1"
                title=""
                titleType="subtitle"
                options={[]}
                onTitleChange={() => {}}
                onTypeChange={() => {}}
                onAdd={() => {}}
                onRemove={() => {}}
                isFirst={false}
            />,
        );
        const subInput = screen.getByRole('textbox', { name: /Title/ });
        expect(subInput).not.toBeRequired();
        expect(screen.getByText('Title')).not.toHaveTextContent('*');
    });
});

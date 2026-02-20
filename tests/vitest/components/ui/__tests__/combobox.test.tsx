import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { Combobox, type ComboboxOption } from '@/components/ui/combobox';

const options: ComboboxOption[] = [
    { value: 'apple', label: 'Apple' },
    { value: 'banana', label: 'Banana' },
    { value: 'cherry', label: 'Cherry' },
];

describe('Combobox', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('single select', () => {
        describe('rendering', () => {
            it('renders with placeholder when no value', () => {
                render(<Combobox options={options} placeholder="Select fruit" />);
                expect(screen.getByText('Select fruit')).toBeInTheDocument();
            });

            it('renders with default placeholder', () => {
                render(<Combobox options={options} />);
                expect(screen.getByText('Select...')).toBeInTheDocument();
            });

            it('renders selected option label', () => {
                render(<Combobox options={options} value="banana" />);
                expect(screen.getByText('Banana')).toBeInTheDocument();
            });

            it('renders combobox role', () => {
                render(<Combobox options={options} />);
                expect(screen.getByRole('combobox')).toBeInTheDocument();
            });
        });

        describe('interactions', () => {
            it('opens dropdown on click', async () => {
                render(<Combobox options={options} />);
                const trigger = screen.getByRole('combobox');
                await userEvent.click(trigger);
                expect(trigger).toHaveAttribute('aria-expanded', 'true');
            });

            it('calls onChange when option is selected', async () => {
                const onChange = vi.fn();
                render(<Combobox options={options} onChange={onChange} />);

                await userEvent.click(screen.getByRole('combobox'));
                await userEvent.click(screen.getByRole('option', { name: 'Apple' }));

                expect(onChange).toHaveBeenCalledWith('apple');
            });

            it('deselects when same option is clicked again', async () => {
                const onChange = vi.fn();
                render(<Combobox options={options} value="apple" onChange={onChange} />);

                await userEvent.click(screen.getByRole('combobox'));
                await userEvent.click(screen.getByRole('option', { name: 'Apple' }));

                expect(onChange).toHaveBeenCalledWith(undefined);
            });
        });

        describe('clear button', () => {
            it('shows clear button when value is selected and clearable', () => {
                render(<Combobox options={options} value="apple" clearable />);
                expect(screen.getByLabelText('Clear selection')).toBeInTheDocument();
            });

            it('hides clear button when no value', () => {
                render(<Combobox options={options} clearable />);
                expect(screen.queryByLabelText('Clear selection')).not.toBeInTheDocument();
            });

            it('hides clear button when clearable is false', () => {
                render(<Combobox options={options} value="apple" clearable={false} />);
                expect(screen.queryByLabelText('Clear selection')).not.toBeInTheDocument();
            });

            it('calls onChange with undefined when cleared', async () => {
                const onChange = vi.fn();
                render(<Combobox options={options} value="apple" onChange={onChange} clearable />);

                await userEvent.click(screen.getByLabelText('Clear selection'));
                expect(onChange).toHaveBeenCalledWith(undefined);
            });
        });
    });

    describe('multi select', () => {
        describe('rendering', () => {
            it('renders placeholder when no values selected', () => {
                render(<Combobox options={options} multiple placeholder="Select fruits" />);
                expect(screen.getByText('Select fruits')).toBeInTheDocument();
            });

            it('renders badges for selected values', () => {
                render(<Combobox options={options} multiple values={['apple', 'banana']} />);
                expect(screen.getByText('Apple')).toBeInTheDocument();
                expect(screen.getByText('Banana')).toBeInTheDocument();
            });

            it('shows "+N more" when exceeding maxDisplayItems', () => {
                const manyOptions: ComboboxOption[] = [
                    { value: '1', label: 'One' },
                    { value: '2', label: 'Two' },
                    { value: '3', label: 'Three' },
                    { value: '4', label: 'Four' },
                    { value: '5', label: 'Five' },
                ];
                render(
                    <Combobox
                        options={manyOptions}
                        multiple
                        values={['1', '2', '3', '4', '5']}
                        maxDisplayItems={3}
                    />,
                );
                expect(screen.getByText('+2 more')).toBeInTheDocument();
            });

            it('shows remove button on each badge', () => {
                render(<Combobox options={options} multiple values={['apple']} />);
                expect(screen.getByLabelText('Remove Apple')).toBeInTheDocument();
            });
        });

        describe('interactions', () => {
            it('adds value to selection', async () => {
                const onValuesChange = vi.fn();
                render(
                    <Combobox
                        options={options}
                        multiple
                        values={['apple']}
                        onValuesChange={onValuesChange}
                    />,
                );

                await userEvent.click(screen.getByRole('combobox'));
                await userEvent.click(screen.getByText('Banana'));

                expect(onValuesChange).toHaveBeenCalledWith(['apple', 'banana']);
            });

            it('removes value from selection when clicked again', async () => {
                const onValuesChange = vi.fn();
                render(
                    <Combobox
                        options={options}
                        multiple
                        values={['apple', 'banana']}
                        onValuesChange={onValuesChange}
                    />,
                );

                await userEvent.click(screen.getByRole('combobox'));
                await userEvent.click(screen.getByRole('option', { name: 'Apple' }));

                expect(onValuesChange).toHaveBeenCalledWith(['banana']);
            });

            it('removes value when badge X is clicked', async () => {
                const onValuesChange = vi.fn();
                render(
                    <Combobox
                        options={options}
                        multiple
                        values={['apple', 'banana']}
                        onValuesChange={onValuesChange}
                    />,
                );

                await userEvent.click(screen.getByLabelText('Remove Apple'));
                expect(onValuesChange).toHaveBeenCalledWith(['banana']);
            });
        });

        describe('clear all', () => {
            it('clears all values when clear button is clicked', async () => {
                const onValuesChange = vi.fn();
                render(
                    <Combobox
                        options={options}
                        multiple
                        values={['apple', 'banana']}
                        onValuesChange={onValuesChange}
                        clearable
                    />,
                );

                await userEvent.click(screen.getByLabelText('Clear selection'));
                expect(onValuesChange).toHaveBeenCalledWith([]);
            });
        });
    });

    describe('disabled state', () => {
        it('disables the trigger button', () => {
            render(<Combobox options={options} disabled />);
            expect(screen.getByRole('combobox')).toBeDisabled();
        });
    });

    describe('error state', () => {
        it('sets aria-invalid when error is true', () => {
            render(<Combobox options={options} error />);
            expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
        });

        it('applies destructive border class', () => {
            render(<Combobox options={options} error />);
            expect(screen.getByRole('combobox').className).toContain('border-destructive');
        });
    });

    describe('required state', () => {
        it('sets aria-required', () => {
            render(<Combobox options={options} required />);
            expect(screen.getByRole('combobox')).toHaveAttribute('aria-required', 'true');
        });
    });

    describe('hidden inputs', () => {
        it('renders single hidden input for single select', () => {
            const { container } = render(<Combobox options={options} name="fruit" value="apple" />);
            const inputs = container.querySelectorAll('input[type="hidden"]');
            expect(inputs).toHaveLength(1);
            expect(inputs[0]).toHaveAttribute('name', 'fruit');
            expect(inputs[0]).toHaveAttribute('value', 'apple');
        });

        it('renders multiple hidden inputs for multi select', () => {
            const { container } = render(
                <Combobox options={options} name="fruits" multiple values={['apple', 'banana']} />,
            );
            const inputs = container.querySelectorAll('input[type="hidden"]');
            expect(inputs).toHaveLength(2);
            expect(inputs[0]).toHaveAttribute('name', 'fruits[]');
            expect(inputs[1]).toHaveAttribute('name', 'fruits[]');
        });

        it('does not render hidden inputs when no name', () => {
            const { container } = render(<Combobox options={options} value="apple" />);
            expect(container.querySelector('input[type="hidden"]')).not.toBeInTheDocument();
        });
    });

    describe('custom rendering', () => {
        it('uses renderOption for dropdown items', async () => {
            render(
                <Combobox
                    options={options}
                    renderOption={(opt) => <span data-testid={`custom-${opt.value}`}>{opt.label.toUpperCase()}</span>}
                />,
            );

            await userEvent.click(screen.getByRole('combobox'));
            expect(screen.getByTestId('custom-apple')).toHaveTextContent('APPLE');
        });
    });

    describe('id prop', () => {
        it('applies id to trigger button', () => {
            render(<Combobox options={options} id="my-combobox" />);
            expect(screen.getByRole('combobox')).toHaveAttribute('id', 'my-combobox');
        });
    });
});

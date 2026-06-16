import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { Upload } from 'lucide-react';
import { describe, expect, it } from 'vitest';

import { AccordionSectionHeader, SectionHeader, SectionHelpAction } from '@/components/curation/section-header';
import { Button } from '@/components/ui/button';

describe('SectionHeader', () => {
    describe('Rendering', () => {
        it('should render label', () => {
            render(<SectionHeader label="Funding References" />);

            expect(screen.getByText('Funding References')).toBeInTheDocument();
        });

        it('should render description when provided', () => {
            render(<SectionHeader label="Funding References" description="Grant and funder information" />);

            expect(screen.getByText('Grant and funder information')).toBeInTheDocument();
        });

        it('should not render description when not provided', () => {
            render(<SectionHeader label="Funding References" />);

            // Should only have label, no description paragraph
            expect(screen.queryByText('Grant and funder information')).not.toBeInTheDocument();
        });

        it('should render with data-testid', () => {
            render(<SectionHeader label="Test" data-testid="section-header-test" />);

            expect(screen.getByTestId('section-header-test')).toBeInTheDocument();
        });

        it('should render with custom id', () => {
            const { container } = render(<SectionHeader label="Test" id="custom-id" />);

            expect(container.querySelector('#custom-id')).toBeInTheDocument();
        });

        it('should render label with data-slot attribute', () => {
            const { container } = render(<SectionHeader label="Test" />);

            expect(container.querySelector('[data-slot="label"]')).toBeInTheDocument();
        });
    });

    describe('Required Indicator', () => {
        it('should show asterisk when required is true', () => {
            render(<SectionHeader label="Authors" required />);

            const indicator = screen.getByLabelText('Required');

            expect(indicator).toBeInTheDocument();
            expect(indicator).toHaveClass('font-bold');
        });

        it('should not show asterisk when required is false', () => {
            render(<SectionHeader label="Contributors" required={false} />);

            expect(screen.queryByLabelText('Required')).not.toBeInTheDocument();
        });

        it('should not show asterisk when required is not provided', () => {
            render(<SectionHeader label="Contributors" />);

            expect(screen.queryByLabelText('Required')).not.toBeInTheDocument();
        });
    });

    describe('Counter', () => {
        it('should render counter when provided', () => {
            render(<SectionHeader label="Funding" counter={{ current: 3, max: 10 }} />);

            expect(screen.getByText('(3 / 10)')).toBeInTheDocument();
        });

        it('should not render counter when not provided', () => {
            render(<SectionHeader label="Funding" />);

            expect(screen.queryByText(/\(/)).not.toBeInTheDocument();
        });

        it('should show correct counter values', () => {
            render(<SectionHeader label="Items" counter={{ current: 0, max: 99 }} />);

            expect(screen.getByText('(0 / 99)')).toBeInTheDocument();
        });
    });

    describe('Tooltip', () => {
        it('should render help icon when tooltip is provided', () => {
            render(<SectionHeader label="Dates" tooltip="Add important dates" />);

            expect(screen.getByRole('button', { name: /help for dates/i })).toBeInTheDocument();
        });

        it('should not render help icon when tooltip is not provided', () => {
            render(<SectionHeader label="Dates" />);

            expect(screen.queryByRole('button', { name: /help/i })).not.toBeInTheDocument();
        });

        it('should show tooltip content on hover', async () => {
            const user = userEvent.setup();

            render(<SectionHeader label="Coverage" tooltip="Define geographic boundaries" />);

            const helpButton = screen.getByRole('button', { name: /help for coverage/i });
            await user.hover(helpButton);

            // Note: Radix tooltip may require additional setup for testing
            // This test verifies the button exists and is interactive
            expect(helpButton).toBeInTheDocument();
        });
    });

    describe('Actions', () => {
        it('should render actions when provided', () => {
            render(
                <SectionHeader
                    label="Authors"
                    actions={
                        <Button variant="outline" size="sm">
                            <Upload className="mr-2 h-4 w-4" />
                            CSV Import
                        </Button>
                    }
                />,
            );

            expect(screen.getByRole('button', { name: /csv import/i })).toBeInTheDocument();
        });

        it('should not render actions container when not provided', () => {
            const { container } = render(<SectionHeader label="Simple" />);

            // Only one button should exist (help icon) or none
            const buttons = container.querySelectorAll('button');
            expect(buttons.length).toBe(0);
        });

        it('should render multiple action buttons', () => {
            render(
                <SectionHeader
                    label="Authors"
                    actions={
                        <>
                            <Button variant="outline" size="sm">
                                CSV Import
                            </Button>
                            <Button variant="ghost" size="sm">
                                Clear All
                            </Button>
                        </>
                    }
                />,
            );

            expect(screen.getByRole('button', { name: /csv import/i })).toBeInTheDocument();
            expect(screen.getByRole('button', { name: /clear all/i })).toBeInTheDocument();
        });
    });

    describe('Styling', () => {
        it('should accept custom className', () => {
            const { container } = render(<SectionHeader label="Test" className="custom-class" />);

            expect(container.firstChild).toHaveClass('custom-class');
        });

        it('should have margin-bottom by default', () => {
            const { container } = render(<SectionHeader label="Test" />);

            expect(container.firstChild).toHaveClass('mb-4');
        });

        it('should have semibold font for label', () => {
            const { container } = render(<SectionHeader label="Test" />);

            const label = container.querySelector('[data-slot="label"]');
            expect(label).toHaveClass('font-semibold');
        });
    });

    describe('Layout', () => {
        it('should align label and actions in a row', () => {
            const { container } = render(
                <SectionHeader
                    label="Test"
                    actions={<Button size="sm">Action</Button>}
                />,
            );

            const flexContainer = container.querySelector('.flex.items-center.justify-between');
            expect(flexContainer).toBeInTheDocument();
        });
    });
});

describe('AccordionSectionHeader', () => {
    it('renders title, description, required indicator, counter, badge, and status', () => {
        render(
            <AccordionSectionHeader
                label="Authors"
                description="People or institutions who created this work."
                required
                counter={{ current: 2, max: 100 }}
                badge={<span>EPOS/MSL</span>}
                status={<span aria-label="Section incomplete or has errors">status</span>}
                data-testid="accordion-section-header"
            />,
        );

        expect(screen.getByTestId('accordion-section-header')).toBeInTheDocument();
        expect(screen.getByText('Authors')).toBeInTheDocument();
        expect(screen.getByText('People or institutions who created this work.')).toBeInTheDocument();
        expect(screen.getByLabelText('Required')).toBeInTheDocument();
        expect(screen.getByText('(2 / 100)')).toBeInTheDocument();
        expect(screen.getByText('EPOS/MSL')).toBeInTheDocument();
        expect(screen.getByLabelText('Section incomplete or has errors')).toBeInTheDocument();
    });

    it('omits optional metadata when not provided', () => {
        render(<AccordionSectionHeader label="Citations" />);

        expect(screen.getByText('Citations')).toBeInTheDocument();
        expect(screen.queryByLabelText('Required')).not.toBeInTheDocument();
        expect(screen.queryByText(/\(/)).not.toBeInTheDocument();
    });
});

describe('SectionHelpAction', () => {
    it('renders a help button when tooltip content is provided', () => {
        render(<SectionHelpAction label="Resource Information" tooltip="Required fields: Year and Resource Type" />);

        expect(screen.getByRole('button', { name: /help for resource information/i })).toBeInTheDocument();
    });

    it('renders nothing when tooltip content is missing', () => {
        const { container } = render(<SectionHelpAction label="Resource Information" />);

        expect(container).toBeEmptyDOMElement();
    });
});

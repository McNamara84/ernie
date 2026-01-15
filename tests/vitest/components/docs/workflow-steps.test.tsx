import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { WorkflowSteps, WorkflowSuccess } from '@/components/docs/workflow-steps';

describe('WorkflowSteps', () => {
    it('renders workflow steps with numbers', () => {
        render(
            <WorkflowSteps>
                <WorkflowSteps.Step number={1} title="First Step">
                    Step 1 content
                </WorkflowSteps.Step>
                <WorkflowSteps.Step number={2} title="Second Step">
                    Step 2 content
                </WorkflowSteps.Step>
            </WorkflowSteps>,
        );

        expect(screen.getByText('1')).toBeInTheDocument();
        expect(screen.getByText('2')).toBeInTheDocument();
    });

    it('renders step titles', () => {
        render(
            <WorkflowSteps>
                <WorkflowSteps.Step number={1} title="Upload XML">
                    Upload content
                </WorkflowSteps.Step>
            </WorkflowSteps>,
        );

        expect(screen.getByText('Upload XML')).toBeInTheDocument();
    });

    it('renders step content', () => {
        render(
            <WorkflowSteps>
                <WorkflowSteps.Step number={1} title="Step Title">
                    This is the step description
                </WorkflowSteps.Step>
            </WorkflowSteps>,
        );

        expect(screen.getByText('This is the step description')).toBeInTheDocument();
    });

    it('renders multiple steps in order', () => {
        render(
            <WorkflowSteps>
                <WorkflowSteps.Step number={1} title="First">
                    First content
                </WorkflowSteps.Step>
                <WorkflowSteps.Step number={2} title="Second">
                    Second content
                </WorkflowSteps.Step>
                <WorkflowSteps.Step number={3} title="Third">
                    Third content
                </WorkflowSteps.Step>
            </WorkflowSteps>,
        );

        expect(screen.getByText('First')).toBeInTheDocument();
        expect(screen.getByText('Second')).toBeInTheDocument();
        expect(screen.getByText('Third')).toBeInTheDocument();
    });

    it('automatically sets isLast on the last step', () => {
        const { container } = render(
            <WorkflowSteps>
                <WorkflowSteps.Step number={1} title="First">
                    First
                </WorkflowSteps.Step>
                <WorkflowSteps.Step number={2} title="Last">
                    Last
                </WorkflowSteps.Step>
            </WorkflowSteps>,
        );

        // The last step should not have the pb-8 class
        const steps = container.querySelectorAll('.flex-1');
        expect(steps[0]).toHaveClass('pb-8');
        expect(steps[1]).not.toHaveClass('pb-8');
    });
});

describe('WorkflowSuccess', () => {
    it('renders success message with icon', () => {
        render(<WorkflowSuccess>Operation completed successfully!</WorkflowSuccess>);

        expect(screen.getByText('Operation completed successfully!')).toBeInTheDocument();
    });

    it('renders the check circle icon', () => {
        const { container } = render(<WorkflowSuccess>Success!</WorkflowSuccess>);

        // The CheckCircle2 icon should be present
        const svg = container.querySelector('svg');
        expect(svg).toBeInTheDocument();
    });

    it('applies success styling', () => {
        const { container } = render(<WorkflowSuccess>Success!</WorkflowSuccess>);

        const wrapper = container.querySelector('.bg-green-50');
        expect(wrapper).toBeInTheDocument();
    });
});

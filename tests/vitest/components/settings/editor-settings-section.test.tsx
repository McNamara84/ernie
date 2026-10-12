import userEvent from '@testing-library/user-event';
import { render, screen } from '@tests/vitest/utils/render';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';

import { EditorSettingsAccordion, EditorSettingsSection } from '@/components/settings/editor-settings-section';
import { Accordion } from '@/components/ui/accordion';

function SettingsCards() {
    const [openSections, setOpenSections] = useState<string[]>([]);

    return (
        <EditorSettingsAccordion value={openSections} onValueChange={setOpenSections}>
            <EditorSettingsSection value="resource-types" title="Resource Types" summary="2 resource types">
                Resource type controls
            </EditorSettingsSection>
        </EditorSettingsAccordion>
    );
}

describe('EditorSettingsSection', () => {
    it('shows the matching bulk-action tooltip on hover', async () => {
        const user = userEvent.setup();
        render(<SettingsCards />);

        const expand = screen.getByRole('button', { name: 'Expand all settings cards' });
        await user.hover(expand);
        expect(await screen.findByRole('tooltip')).toHaveTextContent('Expand all settings cards');
        await user.click(expand);

        const collapse = screen.getByRole('button', { name: 'Collapse all settings cards' });
        await user.hover(collapse);
        expect(await screen.findByRole('tooltip')).toHaveTextContent('Collapse all settings cards');
    });

    it('supports individual toggling without the settings bulk-action provider', async () => {
        const user = userEvent.setup();
        render(
            <Accordion type="multiple">
                <EditorSettingsSection
                    value="standalone"
                    title="Standalone section"
                    description="Section description"
                    icon={<span aria-label="Decorative icon">Icon</span>}
                    summary="Section summary"
                >
                    Section controls
                </EditorSettingsSection>
            </Accordion>,
        );

        const trigger = screen.getByRole('button', { name: /^Standalone section/ });
        expect(screen.queryByRole('button', { name: /all settings cards/ })).not.toBeInTheDocument();
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        await user.click(trigger);
        expect(screen.getByText('Section controls')).toBeVisible();
        await user.click(trigger);
        expect(screen.queryByText('Section controls')).not.toBeInTheDocument();
    });

    it('orders settings cards while retaining informational children', () => {
        render(
            <EditorSettingsAccordion value={[]} onValueChange={() => {}}>
                Additional configuration information
                <span>Supporting information</span>
                <EditorSettingsSection value="licenses" title="Licenses" summary="1 license">
                    License controls
                </EditorSettingsSection>
                <EditorSettingsSection value="resource-types" title="Resource Types" summary="2 resource types">
                    Resource type controls
                </EditorSettingsSection>
            </EditorSettingsAccordion>,
        );

        expect(screen.getAllByRole('button', { expanded: false }).map((button) => button.textContent)).toEqual([
            'Resource Types2 resource types',
            'Licenses1 license',
        ]);
        expect(screen.getByText('Supporting information')).toBeVisible();
        expect(screen.getByTestId('settings-accordion')).toHaveTextContent('Additional configuration information');
    });
});

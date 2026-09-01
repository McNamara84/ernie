import type { ReactNode } from 'react';

import type { LandingPageIgsnDescriptionGroup } from '@/types/landing-page';

import { MetadataList, type MetadataRow } from './MetadataList';

interface IgsnDescriptionGroupsProps {
    groups: LandingPageIgsnDescriptionGroup[];
    subgrid?: boolean;
}

const readableScheme = (scheme: string): string =>
    scheme
        .trim()
        .replace(/[_-]+/g, ' ')
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/\s+/g, ' ')
        .split(' ')
        .map((word) => (word === word.toLowerCase() ? word.charAt(0).toUpperCase() + word.slice(1) : word))
        .join(' ');

export const igsnDescriptionLabel = (scheme: string | null): string => {
    if (!scheme?.trim()) {
        return 'Description';
    }

    const readable = readableScheme(scheme);

    return /description$/i.test(readable) ? readable : `${readable} Description`;
};

export function IgsnDescriptionGroups({ groups, subgrid = false }: IgsnDescriptionGroupsProps): ReactNode {
    const visibleGroups = groups
        .map((group) => ({ entries: group.entries.filter((entry) => entry.value.trim() !== '') }))
        .filter((group) => group.entries.length > 0);

    if (visibleGroups.length === 0) {
        return null;
    }

    return (
        <div data-slot="igsn-description-groups" className={subgrid ? 'col-span-2 grid grid-cols-subgrid gap-y-3' : 'space-y-3'}>
            {visibleGroups.map((group, groupIndex) => {
                const rows: MetadataRow[] = group.entries.map((entry, entryIndex) => ({
                    key: `description-${groupIndex}-${entryIndex}`,
                    label: igsnDescriptionLabel(entry.scheme),
                    value: <span className="whitespace-pre-line">{entry.value}</span>,
                }));

                return (
                    <div
                        key={`description-group-${groupIndex}`}
                        data-slot="igsn-description-group"
                        className={`${subgrid ? 'col-span-2 grid grid-cols-subgrid' : ''} ${
                            groupIndex > 0 ? 'border-t border-gray-200 pt-3 dark:border-gray-700' : ''
                        }`.trim()}
                    >
                        <MetadataList rows={rows} subgrid={subgrid} />
                    </div>
                );
            })}
        </div>
    );
}

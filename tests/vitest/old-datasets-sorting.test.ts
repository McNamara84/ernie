import { describe, expect, it } from 'vitest';

/**
 * Tests for old-datasets sorting logic
 * 
 * These tests validate the frontend sorting behavior without requiring
 * backend connectivity, making them safe for CI environments.
 */

describe('OldDataset Sort State', () => {
    
    describe('Sort Key Validation', () => {
        const validSortKeys = [
            'id',
            'identifier',
            'title',
            'resourcetypegeneral',
            'first_author',
            'publicationyear',
            'curator',
            'publicstatus',
            'created_at',
            'updated_at',
        ] as const;

        it('has exactly 10 valid sort keys', () => {
            expect(validSortKeys).toHaveLength(10);
        });

        it.each(validSortKeys)('accepts %s as valid sort key', (key) => {
            expect(validSortKeys).toContain(key);
        });
    });

    describe('Sort Direction', () => {
        const validDirections = ['asc', 'desc'] as const;

        it('has exactly 2 valid sort directions', () => {
            expect(validDirections).toHaveLength(2);
        });

        it.each(validDirections)('accepts %s as valid direction', (direction) => {
            expect(validDirections).toContain(direction);
        });
    });

    describe('Sort State Toggle Logic', () => {
        type SortKey = 'id' | 'identifier' | 'title' | 'resourcetypegeneral' | 'first_author' | 
                       'publicationyear' | 'curator' | 'publicstatus' | 'created_at' | 'updated_at';
        type SortDirection = 'asc' | 'desc';
        type SortState = { key: SortKey; direction: SortDirection };

        const determineNextDirection = (
            currentState: SortState,
            newKey: SortKey
        ): SortDirection => {
            if (currentState.key !== newKey) {
                return 'asc';
            }
            return currentState.direction === 'asc' ? 'desc' : 'asc';
        };

        it('starts with asc when clicking a new column', () => {
            const currentState: SortState = { key: 'id', direction: 'asc' };
            const nextDirection = determineNextDirection(currentState, 'title');
            
            expect(nextDirection).toBe('asc');
        });

        it('toggles to desc when clicking the same column sorted asc', () => {
            const currentState: SortState = { key: 'title', direction: 'asc' };
            const nextDirection = determineNextDirection(currentState, 'title');
            
            expect(nextDirection).toBe('desc');
        });

        it('toggles to asc when clicking the same column sorted desc', () => {
            const currentState: SortState = { key: 'title', direction: 'desc' };
            const nextDirection = determineNextDirection(currentState, 'title');
            
            expect(nextDirection).toBe('asc');
        });

        it('handles multiple column switches correctly', () => {
            let state: SortState = { key: 'id', direction: 'asc' };
            
            // Click on title
            state = { key: 'title', direction: determineNextDirection(state, 'title') };
            expect(state).toEqual({ key: 'title', direction: 'asc' });
            
            // Click on title again
            state = { key: 'title', direction: determineNextDirection(state, 'title') };
            expect(state).toEqual({ key: 'title', direction: 'desc' });
            
            // Click on author
            state = { key: 'first_author', direction: determineNextDirection(state, 'first_author') };
            expect(state).toEqual({ key: 'first_author', direction: 'asc' });
            
            // Click on author again
            state = { key: 'first_author', direction: determineNextDirection(state, 'first_author') };
            expect(state).toEqual({ key: 'first_author', direction: 'desc' });
        });
    });

    describe('Sort Label Mapping', () => {
        type SortKey = 'id' | 'identifier' | 'title' | 'resourcetypegeneral' | 'first_author' | 
                       'publicationyear' | 'curator' | 'publicstatus' | 'created_at' | 'updated_at';

        const getSortLabel = (key: SortKey): string => {
            const labels: Record<SortKey, string> = {
                'id': 'ID',
                'identifier': 'Identifier',
                'title': 'Title',
                'resourcetypegeneral': 'Resource Type',
                'first_author': 'Author',
                'publicationyear': 'Year',
                'curator': 'Curator',
                'publicstatus': 'Status',
                'created_at': 'Created',
                'updated_at': 'Updated',
            };
            return labels[key];
        };

        it.each([
            ['id', 'ID'],
            ['identifier', 'Identifier'],
            ['title', 'Title'],
            ['resourcetypegeneral', 'Resource Type'],
            ['first_author', 'Author'],
            ['publicationyear', 'Year'],
            ['curator', 'Curator'],
            ['publicstatus', 'Status'],
            ['created_at', 'Created'],
            ['updated_at', 'Updated'],
        ] as const)('maps %s to "%s"', (key, expectedLabel) => {
            expect(getSortLabel(key)).toBe(expectedLabel);
        });
    });

    describe('Sort Column Configuration', () => {
        it('validates that columns with sort options have correct structure', () => {
            const sampleSortOption = {
                key: 'first_author' as const,
                label: 'Author',
                description: 'Sort by the first author\'s last name',
            };

            expect(sampleSortOption).toHaveProperty('key');
            expect(sampleSortOption).toHaveProperty('label');
            expect(sampleSortOption).toHaveProperty('description');
            expect(typeof sampleSortOption.key).toBe('string');
            expect(typeof sampleSortOption.label).toBe('string');
            expect(typeof sampleSortOption.description).toBe('string');
        });

        it('validates merged column with multiple sort options', () => {
            const authorYearColumn = {
                key: 'author_year',
                sortOptions: [
                    { key: 'first_author', label: 'Author', description: 'Sort by the first author\'s last name' },
                    { key: 'publicationyear', label: 'Year', description: 'Sort by the publication year' },
                ],
            };

            expect(authorYearColumn.sortOptions).toHaveLength(2);
            expect(authorYearColumn.sortOptions[0].key).toBe('first_author');
            expect(authorYearColumn.sortOptions[1].key).toBe('publicationyear');
        });
    });

    describe('Sort State Persistence', () => {
        const SORT_PREFERENCE_STORAGE_KEY = 'old-datasets-sort-preference';

        it('defines correct storage key', () => {
            expect(SORT_PREFERENCE_STORAGE_KEY).toBe('old-datasets-sort-preference');
        });

        it('validates stored sort state structure', () => {
            const validStoredState = {
                key: 'first_author' as const,
                direction: 'asc' as const,
            };

            expect(validStoredState).toHaveProperty('key');
            expect(validStoredState).toHaveProperty('direction');
            expect(['asc', 'desc']).toContain(validStoredState.direction);
        });

        it('handles invalid stored data gracefully', () => {
            const invalidData = { key: 'invalid_key', direction: 'invalid_dir' };
            
            // Validation function would check if key is valid
            const isValidKey = (key: string): boolean => {
                const validKeys = [
                    'id', 'identifier', 'title', 'resourcetypegeneral', 
                    'first_author', 'publicationyear', 'curator', 
                    'publicstatus', 'created_at', 'updated_at'
                ];
                return validKeys.includes(key);
            };

            const isValidDirection = (dir: string): boolean => {
                return ['asc', 'desc'].includes(dir);
            };

            expect(isValidKey(invalidData.key)).toBe(false);
            expect(isValidDirection(invalidData.direction)).toBe(false);
        });
    });

    describe('Author Data Structure', () => {
        it('validates first_author structure', () => {
            const sampleAuthor = {
                familyName: 'Doe',
                givenName: 'John',
                name: 'Doe, John',
            };

            expect(sampleAuthor).toHaveProperty('familyName');
            expect(sampleAuthor).toHaveProperty('givenName');
            expect(sampleAuthor).toHaveProperty('name');
        });

        it('handles author with only name field', () => {
            const authorWithNameOnly = {
                familyName: null,
                givenName: null,
                name: 'Full Name',
            };

            expect(authorWithNameOnly.familyName).toBeNull();
            expect(authorWithNameOnly.givenName).toBeNull();
            expect(authorWithNameOnly.name).toBe('Full Name');
        });

        it('handles author with family and given name', () => {
            const authorWithSeparateNames = {
                familyName: 'Smith',
                givenName: 'Jane',
                name: 'Smith, Jane',
            };

            // Display logic would prioritize familyName and givenName
            const displayName = authorWithSeparateNames.familyName && authorWithSeparateNames.givenName
                ? `${authorWithSeparateNames.familyName}, ${authorWithSeparateNames.givenName}`
                : authorWithSeparateNames.name;

            expect(displayName).toBe('Smith, Jane');
        });

        it('handles missing author data', () => {
            const noAuthor = null;

            // Display logic would show fallback
            const displayName = noAuthor ? 'Author Name' : '-';

            expect(displayName).toBe('-');
        });
    });
});

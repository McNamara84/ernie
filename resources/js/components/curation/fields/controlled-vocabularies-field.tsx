import { Search, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useDebounce } from '@/hooks/use-debounce';
import { cn } from '@/lib/utils';
import type { GCMDKeyword, GCMDVocabularyType, SelectedKeyword } from '@/types/gcmd';
import { getSchemeFromVocabularyType, getVocabularyTypeFromScheme } from '@/types/gcmd';

import { GCMDTree } from './gcmd-tree';

/**
 * Minimum number of characters required before triggering vocabulary search.
 *
 * Rationale: Short search queries (1-2 characters) produce too many results in hierarchical
 * GCMD vocabularies, degrading user experience. Three characters provides a good balance:
 * - Reduces result set to manageable size
 * - Allows meaningful abbreviations (e.g., "CO2", "GPS", "SAR")
 * - Prevents UI lag from rendering thousands of tree nodes
 */
const MIN_SEARCH_LENGTH = 3;

interface ThesauriAvailability {
    science_keywords: boolean;
    platforms: boolean;
    instruments: boolean;
}

interface ControlledVocabulariesFieldProps {
    scienceKeywords: GCMDKeyword[];
    platforms: GCMDKeyword[];
    instruments: GCMDKeyword[];
    mslVocabulary?: GCMDKeyword[]; // Optional MSL vocabulary
    selectedKeywords: SelectedKeyword[];
    onChange: (keywords: SelectedKeyword[]) => void;
    showMslTab?: boolean; // Control MSL tab visibility
    autoSwitchToMsl?: boolean; // Auto-switch to MSL tab when it becomes available
    enabledThesauri?: ThesauriAvailability; // Which thesauri are enabled in settings
}

/**
 * Recursively searches for keywords matching a search query
 * Optimized with early exit and pre-lowercased query
 */
function searchKeywords(keywords: GCMDKeyword[], query: string): GCMDKeyword[] {
    if (!query) return keywords;

    const results: GCMDKeyword[] = [];
    const lowerQuery = query.toLowerCase();

    function searchNode(keyword: GCMDKeyword): void {
        const textMatch = keyword.text.toLowerCase().includes(lowerQuery);
        const descMatch = keyword.description?.toLowerCase().includes(lowerQuery) ?? false;

        if (textMatch || descMatch) {
            results.push(keyword);
        }

        // Continue searching in children even if parent matches
        // This ensures all matching descendants are found
        if (keyword.children && keyword.children.length > 0) {
            keyword.children.forEach(searchNode);
        }
    }

    keywords.forEach(searchNode);
    return results;
}

/**
 * Main component for controlled vocabularies selection
 */
export default function ControlledVocabulariesField({
    scienceKeywords,
    platforms,
    instruments,
    mslVocabulary = [],
    selectedKeywords,
    onChange,
    showMslTab = false,
    autoSwitchToMsl = false,
    enabledThesauri = { science_keywords: true, platforms: true, instruments: true },
}: ControlledVocabulariesFieldProps) {
    // Determine which tabs are available based on enabled thesauri
    const showScienceTab = enabledThesauri.science_keywords;
    const showPlatformsTab = enabledThesauri.platforms;
    const showInstrumentsTab = enabledThesauri.instruments;

    // Determine default active tab based on what's available
    const getDefaultTab = (): GCMDVocabularyType => {
        if (showScienceTab) return 'science';
        if (showPlatformsTab) return 'platforms';
        if (showInstrumentsTab) return 'instruments';
        if (showMslTab) return 'msl';
        return 'science'; // Fallback
    };

    const [activeTab, setActiveTab] = useState<GCMDVocabularyType>(getDefaultTab);
    const [searchQuery, setSearchQuery] = useState('');

    // Track if auto-switch has already occurred to prevent interference with manual tab changes
    const hasAutoSwitched = useRef(false);

    // Auto-switch to MSL tab when it becomes available (triggered by parent notification logic)
    useEffect(() => {
        if (autoSwitchToMsl && showMslTab && !hasAutoSwitched.current) {
            hasAutoSwitched.current = true;
            setActiveTab('msl');
        }

        // Reset flag when MSL tab is hidden
        if (!showMslTab) {
            hasAutoSwitched.current = false;
        }
    }, [autoSwitchToMsl, showMslTab]);

    // Switch to an available tab if the current tab becomes unavailable
    useEffect(() => {
        const isCurrentTabAvailable =
            (activeTab === 'science' && showScienceTab) ||
            (activeTab === 'platforms' && showPlatformsTab) ||
            (activeTab === 'instruments' && showInstrumentsTab) ||
            (activeTab === 'msl' && showMslTab);

        if (!isCurrentTabAvailable) {
            if (showScienceTab) setActiveTab('science');
            else if (showPlatformsTab) setActiveTab('platforms');
            else if (showInstrumentsTab) setActiveTab('instruments');
            else if (showMslTab) setActiveTab('msl');
        }
    }, [activeTab, showScienceTab, showPlatformsTab, showInstrumentsTab, showMslTab]);

    // Debounce search query to avoid excessive re-renders
    // Only trigger search after user stops typing for 300ms
    const debouncedSearchQuery = useDebounce(searchQuery, 300);

    // Apply minimum search length threshold (defined at module level)
    const effectiveSearchQuery = debouncedSearchQuery.trim().length >= MIN_SEARCH_LENGTH ? debouncedSearchQuery.trim() : '';

    // Show loading state while debouncing
    const isSearching = searchQuery.trim().length >= MIN_SEARCH_LENGTH && searchQuery !== debouncedSearchQuery;

    // Get the appropriate keyword tree based on active tab
    const currentKeywords = useMemo(() => {
        switch (activeTab) {
            case 'science':
                return scienceKeywords;
            case 'platforms':
                return platforms;
            case 'instruments':
                return instruments;
            case 'msl':
                return mslVocabulary;
            default:
                return [];
        }
    }, [activeTab, scienceKeywords, platforms, instruments, mslVocabulary]);

    // Filter keywords based on search query
    // Only search if query is at least MIN_SEARCH_LENGTH characters
    const filteredKeywords = useMemo(() => {
        if (!effectiveSearchQuery) {
            return currentKeywords;
        }
        return searchKeywords(currentKeywords, effectiveSearchQuery);
    }, [currentKeywords, effectiveSearchQuery]);

    // Get selected keyword IDs for current vocabulary
    const selectedIdsForCurrentVocabulary = useMemo(() => {
        // Filter keywords by scheme matching the active tab
        const targetScheme = getSchemeFromVocabularyType(activeTab);
        return new Set(selectedKeywords.filter((k) => k.scheme.toLowerCase().includes(targetScheme.toLowerCase().split(' ')[0])).map((k) => k.id));
    }, [selectedKeywords, activeTab]);

    // Handle keyword toggle (select/deselect)
    const handleToggle = useCallback(
        (keyword: GCMDKeyword, path: string[]) => {
            const isSelected = selectedKeywords.some((k) => k.id === keyword.id);

            if (isSelected) {
                // Remove keyword
                onChange(selectedKeywords.filter((k) => k.id !== keyword.id));
            } else {
                // Add keyword with all GCMD metadata
                const newKeyword: SelectedKeyword = {
                    id: keyword.id,
                    text: keyword.text,
                    path: path.join(' > '),
                    language: keyword.language,
                    scheme: keyword.scheme,
                    schemeURI: keyword.schemeURI,
                };
                onChange([...selectedKeywords, newKeyword]);
            }
        },
        [selectedKeywords, onChange],
    );

    // Handle keyword removal from badge
    const handleRemove = useCallback(
        (id: string) => {
            onChange(selectedKeywords.filter((k) => k.id !== id));
        },
        [selectedKeywords, onChange],
    );

    // Group selected keywords by vocabulary type (based on scheme)
    const keywordsByVocabulary = useMemo(() => {
        const grouped: Record<GCMDVocabularyType, SelectedKeyword[]> = {
            science: [],
            platforms: [],
            instruments: [],
            msl: [],
        };

        for (const keyword of selectedKeywords) {
            const type = getVocabularyTypeFromScheme(keyword.scheme);
            grouped[type].push(keyword);
        }

        return grouped;
    }, [selectedKeywords]);

    // Check if a vocabulary type has selected keywords
    const hasKeywords = useCallback(
        (type: GCMDVocabularyType): boolean => {
            return keywordsByVocabulary[type].length > 0;
        },
        [keywordsByVocabulary],
    );

    // Check if any thesauri are available
    const hasAnyThesaurus = showScienceTab || showPlatformsTab || showInstrumentsTab || showMslTab;

    return (
        <div className="space-y-4">
            {/* Show message if no thesauri are enabled */}
            {!hasAnyThesaurus && (
                <div className="rounded-md border border-muted bg-muted/50 p-4 text-center text-sm text-muted-foreground">
                    No controlled vocabularies are currently enabled. Contact an administrator to enable GCMD thesauri in the settings.
                </div>
            )}

            {/* Selected Keywords Display */}
            {selectedKeywords.length > 0 && (
                <div className="space-y-3">
                    {(
                        [
                            ...(showScienceTab ? ['science' as const] : []),
                            ...(showPlatformsTab ? ['platforms' as const] : []),
                            ...(showInstrumentsTab ? ['instruments' as const] : []),
                            ...(showMslTab ? ['msl' as const] : []),
                        ] as GCMDVocabularyType[]
                    ).map((type) => {
                        const keywords = keywordsByVocabulary[type];
                        if (keywords.length === 0) return null;

                        const typeLabels: Record<GCMDVocabularyType, string> = {
                            science: 'Science Keywords',
                            platforms: 'Platforms',
                            instruments: 'Instruments',
                            msl: 'MSL Vocabulary',
                        };

                        // Check if there are any legacy keywords
                        const legacyKeywords = keywords.filter((kw) => kw.isLegacy);
                        const hasLegacyKeywords = legacyKeywords.length > 0;

                        return (
                            <div key={type}>
                                <Label className="mb-2 block text-xs font-medium text-muted-foreground">
                                    {typeLabels[type]}:
                                    {hasLegacyKeywords && (
                                        <span
                                            className="ml-2 text-xs font-semibold text-amber-600 dark:text-amber-400"
                                            title="Some keywords are from the old database and don't exist in the current vocabulary. Please review and replace with current keywords."
                                        >
                                            ⚠️ {legacyKeywords.length} Legacy Keyword{legacyKeywords.length > 1 ? 's' : ''} - Please Review
                                        </span>
                                    )}
                                </Label>
                                <div className="flex flex-wrap gap-2">
                                    {keywords.map((keyword) => (
                                        <Badge
                                            key={keyword.id}
                                            variant={keyword.isLegacy ? 'destructive' : 'secondary'}
                                            className={cn(
                                                'gap-1.5 pr-1.5',
                                                keyword.isLegacy &&
                                                    'border-amber-300 bg-amber-100 text-amber-900 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-100',
                                            )}
                                            title={
                                                keyword.isLegacy
                                                    ? `⚠️ Legacy keyword from old database: "${keyword.path}"\nThis keyword doesn't exist in the current vocabulary.\nPlease remove and select a replacement from the current MSL vocabulary.`
                                                    : keyword.path
                                            }
                                        >
                                            {keyword.isLegacy && <span className="text-amber-600 dark:text-amber-400">⚠️</span>}
                                            <span className="max-w-md truncate">{keyword.path}</span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-4 w-4 p-0 hover:bg-transparent"
                                                onClick={() => handleRemove(keyword.id)}
                                                aria-label={`Remove ${keyword.path}`}
                                            >
                                                <X className="h-3 w-3" />
                                            </Button>
                                        </Badge>
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {/* Search and Tabs - only show if at least one thesaurus is available */}
            {hasAnyThesaurus && (
                <>
                    {/* Search Input - searches across all vocabulary types */}
                    <div className="relative">
                        <Search
                            className={cn(
                                'absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 transform',
                                isSearching ? 'animate-pulse text-primary' : 'text-muted-foreground',
                            )}
                        />
                        <Input
                            type="text"
                            placeholder={`Search all vocabularies (min. ${MIN_SEARCH_LENGTH} characters)...`}
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            className="pl-9"
                        />
                        {searchQuery && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="absolute top-1/2 right-1 h-7 w-7 -translate-y-1/2 transform p-0"
                                onClick={() => setSearchQuery('')}
                                aria-label="Clear search"
                            >
                                <X className="h-4 w-4" />
                            </Button>
                        )}
                    </div>

                    {/* Tabs for vocabulary types */}
                    <Tabs value={activeTab} onValueChange={(val) => setActiveTab(val as GCMDVocabularyType)}>
                        <TabsList
                            className={cn(
                                'grid w-full',
                                // Dynamically calculate grid columns based on visible tabs
                                (() => {
                                    const visibleCount = [showScienceTab, showPlatformsTab, showInstrumentsTab, showMslTab].filter(Boolean).length;
                                    switch (visibleCount) {
                                        case 1:
                                            return 'grid-cols-1';
                                        case 2:
                                            return 'grid-cols-2';
                                        case 3:
                                            return 'grid-cols-3';
                                        case 4:
                                            return 'grid-cols-4';
                                        default:
                                            return 'grid-cols-3';
                                    }
                                })(),
                            )}
                        >
                            {showScienceTab && (
                                <TabsTrigger value="science" className="relative">
                                    Science Keywords
                                    {hasKeywords('science') && (
                                        <span
                                            className="ml-1 inline-block h-2 w-2 rounded-full bg-green-500"
                                            aria-label="Has keywords"
                                            title="This vocabulary has selected keywords"
                                        />
                                    )}
                                </TabsTrigger>
                            )}
                            {showPlatformsTab && (
                                <TabsTrigger value="platforms" className="relative">
                                    Platforms
                                    {hasKeywords('platforms') && (
                                        <span
                                            className="ml-1 inline-block h-2 w-2 rounded-full bg-green-500"
                                            aria-label="Has keywords"
                                            title="This vocabulary has selected keywords"
                                        />
                                    )}
                                </TabsTrigger>
                            )}
                            {showInstrumentsTab && (
                                <TabsTrigger value="instruments" className="relative">
                                    Instruments
                                    {hasKeywords('instruments') && (
                                        <span
                                            className="ml-1 inline-block h-2 w-2 rounded-full bg-green-500"
                                            aria-label="Has keywords"
                                            title="This vocabulary has selected keywords"
                                        />
                                    )}
                                </TabsTrigger>
                            )}
                            {showMslTab && (
                                <TabsTrigger value="msl" className="relative">
                                    MSL Vocabulary
                                    {hasKeywords('msl') && (
                                        <span
                                            className="ml-1 inline-block h-2 w-2 rounded-full bg-green-500"
                                            aria-label="Has keywords"
                                            title="This vocabulary has selected keywords"
                                        />
                                    )}
                                </TabsTrigger>
                            )}
                        </TabsList>

                        <TabsContent value={activeTab} className="mt-4 space-y-4">
                            {/* Tree View */}
                            {effectiveSearchQuery ? (
                                <div>
                                    <p className="mb-2 text-xs text-muted-foreground">
                                        {isSearching ? (
                                            <span className="italic">Searching...</span>
                                        ) : (
                                            <>
                                                {filteredKeywords.length} result
                                                {filteredKeywords.length !== 1 ? 's' : ''} found
                                            </>
                                        )}
                                    </p>
                                    <GCMDTree
                                        keywords={filteredKeywords}
                                        selectedIds={selectedIdsForCurrentVocabulary}
                                        onToggle={handleToggle}
                                        emptyMessage="No keywords match your search"
                                        searchQuery={effectiveSearchQuery}
                                    />
                                </div>
                            ) : (
                                <GCMDTree
                                    keywords={currentKeywords}
                                    selectedIds={selectedIdsForCurrentVocabulary}
                                    onToggle={handleToggle}
                                    emptyMessage="No keywords available"
                                />
                            )}
                        </TabsContent>
                    </Tabs>
                </>
            )}
        </div>
    );
}

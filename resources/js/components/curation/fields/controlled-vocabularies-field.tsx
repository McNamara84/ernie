import { Search, X } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useDebounce } from '@/hooks/use-debounce';
import { cn } from '@/lib/utils';
import type { GCMDKeyword, GCMDVocabularyType, SelectedKeyword } from '@/types/gcmd';

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

interface ControlledVocabulariesFieldProps {
    scienceKeywords: GCMDKeyword[];
    platforms: GCMDKeyword[];
    instruments: GCMDKeyword[];
    mslVocabulary?: GCMDKeyword[]; // Optional MSL vocabulary
    selectedKeywords: SelectedKeyword[];
    onChange: (keywords: SelectedKeyword[]) => void;
    showMslTab?: boolean; // Control MSL tab visibility
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
}: ControlledVocabulariesFieldProps) {
    const [activeTab, setActiveTab] = useState<GCMDVocabularyType>('science');
    const [searchQuery, setSearchQuery] = useState('');
    
    // Debounce search query to avoid excessive re-renders
    // Only trigger search after user stops typing for 300ms
    const debouncedSearchQuery = useDebounce(searchQuery, 300);
    
    // Apply minimum search length threshold (defined at module level)
    const effectiveSearchQuery = debouncedSearchQuery.trim().length >= MIN_SEARCH_LENGTH 
        ? debouncedSearchQuery.trim() 
        : '';
    
    // Show loading state while debouncing
    const isSearching = searchQuery.trim().length >= MIN_SEARCH_LENGTH && 
                       searchQuery !== debouncedSearchQuery;

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
        return new Set(
            selectedKeywords.filter((k) => k.vocabularyType === activeTab).map((k) => k.id),
        );
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
                    vocabularyType: activeTab,
                };
                onChange([...selectedKeywords, newKeyword]);
            }
        },
        [selectedKeywords, onChange, activeTab],
    );

    // Handle keyword removal from badge
    const handleRemove = useCallback(
        (id: string) => {
            onChange(selectedKeywords.filter((k) => k.id !== id));
        },
        [selectedKeywords, onChange],
    );

    // Group selected keywords by vocabulary type
    const keywordsByVocabulary = useMemo(() => {
        const grouped: Record<GCMDVocabularyType, SelectedKeyword[]> = {
            science: [],
            platforms: [],
            instruments: [],
            msl: [],
        };

        for (const keyword of selectedKeywords) {
            grouped[keyword.vocabularyType].push(keyword);
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

    return (
        <div className="space-y-4">
            {/* Selected Keywords Display */}
            {selectedKeywords.length > 0 && (
                <div className="space-y-3">
                    {(['science', 'platforms', 'instruments', ...(showMslTab ? ['msl' as const] : [])] as GCMDVocabularyType[]).map(
                        (type) => {
                            const keywords = keywordsByVocabulary[type];
                            if (keywords.length === 0) return null;

                            const typeLabels: Record<GCMDVocabularyType, string> = {
                                science: 'Science Keywords',
                                platforms: 'Platforms',
                                instruments: 'Instruments',
                                msl: 'MSL Vocabulary',
                            };

                            return (
                                <div key={type}>
                                    <Label className="text-xs font-medium text-muted-foreground mb-2 block">
                                        {typeLabels[type]}:
                                    </Label>
                                    <div className="flex flex-wrap gap-2">
                                        {keywords.map((keyword) => (
                                            <Badge
                                                key={keyword.id}
                                                variant="secondary"
                                                className="gap-1.5 pr-1.5"
                                            >
                                                <span className="max-w-md truncate" title={keyword.path}>
                                                    {keyword.path}
                                                </span>
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
                        },
                    )}
                </div>
            )}

            {/* Search Input - searches across all vocabulary types */}
            <div className="relative">
                <Search className={cn(
                    "absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4",
                    isSearching ? "text-primary animate-pulse" : "text-muted-foreground"
                )} />
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
                        className="absolute right-1 top-1/2 transform -translate-y-1/2 h-7 w-7 p-0"
                        onClick={() => setSearchQuery('')}
                        aria-label="Clear search"
                    >
                        <X className="h-4 w-4" />
                    </Button>
                )}
            </div>

            {/* Tabs for vocabulary types */}
            <Tabs value={activeTab} onValueChange={(val) => setActiveTab(val as GCMDVocabularyType)}>
                <TabsList className={cn("grid w-full", showMslTab ? "grid-cols-4" : "grid-cols-3")}>
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

                <TabsContent value={activeTab} className="space-y-4 mt-4">
                    {/* Tree View */}
                    {effectiveSearchQuery ? (
                        <div>
                            <p className="text-xs text-muted-foreground mb-2">
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
        </div>
    );
}

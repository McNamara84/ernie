import { Search, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { GCMDKeyword, GCMDVocabularyType, SelectedKeyword } from '@/types/gcmd';

import { GCMDTree } from './gcmd-tree';

interface ControlledVocabulariesFieldProps {
    scienceKeywords: GCMDKeyword[];
    platforms: GCMDKeyword[];
    instruments: GCMDKeyword[];
    selectedKeywords: SelectedKeyword[];
    onChange: (keywords: SelectedKeyword[]) => void;
}

/**
 * Recursively searches for keywords matching a search query
 */
function searchKeywords(keywords: GCMDKeyword[], query: string): GCMDKeyword[] {
    const results: GCMDKeyword[] = [];
    const lowerQuery = query.toLowerCase();

    for (const keyword of keywords) {
        const matches =
            keyword.text.toLowerCase().includes(lowerQuery) ||
            (keyword.description && keyword.description.toLowerCase().includes(lowerQuery));

        if (matches) {
            results.push(keyword);
        }

        if (keyword.children && keyword.children.length > 0) {
            const childResults = searchKeywords(keyword.children, query);
            results.push(...childResults);
        }
    }

    return results;
}

/**
 * Main component for controlled vocabularies selection
 */
export default function ControlledVocabulariesField({
    scienceKeywords,
    platforms,
    instruments,
    selectedKeywords,
    onChange,
}: ControlledVocabulariesFieldProps) {
    const [activeTab, setActiveTab] = useState<GCMDVocabularyType>('science');
    const [searchQuery, setSearchQuery] = useState('');

    // Get the appropriate keyword tree based on active tab
    const currentKeywords = useMemo(() => {
        switch (activeTab) {
            case 'science':
                return scienceKeywords;
            case 'platforms':
                return platforms;
            case 'instruments':
                return instruments;
            default:
                return [];
        }
    }, [activeTab, scienceKeywords, platforms, instruments]);

    // Filter keywords based on search query
    const filteredKeywords = useMemo(() => {
        if (!searchQuery.trim()) {
            return currentKeywords;
        }
        return searchKeywords(currentKeywords, searchQuery);
    }, [currentKeywords, searchQuery]);

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
                // Add keyword
                const newKeyword: SelectedKeyword = {
                    id: keyword.id,
                    text: keyword.text,
                    path: path.join(' > '),
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

    // Clear search when switching tabs
    useEffect(() => {
        setSearchQuery('');
    }, [activeTab]);

    // Group selected keywords by vocabulary type
    const keywordsByVocabulary = useMemo(() => {
        const grouped: Record<GCMDVocabularyType, SelectedKeyword[]> = {
            science: [],
            platforms: [],
            instruments: [],
        };

        for (const keyword of selectedKeywords) {
            grouped[keyword.vocabularyType].push(keyword);
        }

        return grouped;
    }, [selectedKeywords]);

    return (
        <div className="space-y-4">
            <div>
                <Label className="text-base font-semibold">Controlled Vocabularies (GCMD)</Label>
                <p className="text-sm text-muted-foreground mt-1">
                    Select keywords from NASA's Global Change Master Directory to categorize your
                    dataset.
                </p>
            </div>

            {/* Selected Keywords Display */}
            {selectedKeywords.length > 0 && (
                <div className="space-y-3">
                    {(['science', 'platforms', 'instruments'] as GCMDVocabularyType[]).map(
                        (type) => {
                            const keywords = keywordsByVocabulary[type];
                            if (keywords.length === 0) return null;

                            const typeLabels = {
                                science: 'Science Keywords',
                                platforms: 'Platforms',
                                instruments: 'Instruments',
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
                                                <span className="max-w-xs truncate" title={keyword.path}>
                                                    {keyword.text}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-4 w-4 p-0 hover:bg-transparent"
                                                    onClick={() => handleRemove(keyword.id)}
                                                    aria-label={`Remove ${keyword.text}`}
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

            {/* Tabs for vocabulary types */}
            <Tabs value={activeTab} onValueChange={(val) => setActiveTab(val as GCMDVocabularyType)}>
                <TabsList className="grid w-full grid-cols-3">
                    <TabsTrigger value="science">Science Keywords</TabsTrigger>
                    <TabsTrigger value="platforms">Platforms</TabsTrigger>
                    <TabsTrigger value="instruments">Instruments</TabsTrigger>
                </TabsList>

                <TabsContent value={activeTab} className="space-y-4 mt-4">
                    {/* Search Input */}
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                        <Input
                            type="text"
                            placeholder="Search keywords..."
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

                    {/* Tree View */}
                    {searchQuery ? (
                        <div>
                            <p className="text-xs text-muted-foreground mb-2">
                                {filteredKeywords.length} result
                                {filteredKeywords.length !== 1 ? 's' : ''} found
                            </p>
                            <GCMDTree
                                keywords={filteredKeywords}
                                selectedIds={selectedIdsForCurrentVocabulary}
                                onToggle={handleToggle}
                                emptyMessage="No keywords match your search"
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

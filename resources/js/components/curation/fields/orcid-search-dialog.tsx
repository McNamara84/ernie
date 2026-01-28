/**
 * OrcidSearchDialog Component
 *
 * Modal dialog for searching ORCID records by name, institution, or keywords.
 * Uses shadcn/ui Command component for an improved search experience.
 */

import { ExternalLink, Search, User } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Spinner } from '@/components/ui/spinner';
import { type OrcidSearchResult, OrcidService } from '@/services/orcid';

interface OrcidSearchDialogProps {
    onSelect: (result: OrcidSearchResult) => void;
    triggerClassName?: string;
}

export function OrcidSearchDialog({ onSelect, triggerClassName }: OrcidSearchDialogProps) {
    const [open, setOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const [isSearching, setIsSearching] = useState(false);
    const [results, setResults] = useState<OrcidSearchResult[]>([]);
    const [hasSearched, setHasSearched] = useState(false);
    const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Debounced search function
    const performSearch = useCallback(async (query: string) => {
        if (!query.trim() || query.length < 2) {
            setResults([]);
            setHasSearched(false);
            return;
        }

        setIsSearching(true);
        setHasSearched(true);

        try {
            const response = await OrcidService.searchOrcid(query, 20);

            if (response.success && response.data) {
                setResults(response.data.results);
            } else {
                setResults([]);
            }
        } catch (error) {
            console.error('ORCID search error:', error);
            setResults([]);
        } finally {
            setIsSearching(false);
        }
    }, []);

    // Handle search input with debounce
    const handleSearchChange = useCallback(
        (value: string) => {
            setSearchQuery(value);

            // Clear previous timer
            if (debounceTimerRef.current) {
                clearTimeout(debounceTimerRef.current);
            }

            // Set new debounce timer (300ms)
            debounceTimerRef.current = setTimeout(() => {
                performSearch(value);
            }, 300);
        },
        [performSearch],
    );

    // Cleanup debounce timer on unmount
    useEffect(() => {
        return () => {
            if (debounceTimerRef.current) {
                clearTimeout(debounceTimerRef.current);
            }
        };
    }, []);

    const handleSelect = (result: OrcidSearchResult) => {
        onSelect(result);
        setOpen(false);
        // Reset dialog state
        setSearchQuery('');
        setResults([]);
        setHasSearched(false);
    };

    const handleOpenChange = (isOpen: boolean) => {
        setOpen(isOpen);
        if (!isOpen) {
            // Reset state when closing
            setSearchQuery('');
            setResults([]);
            setHasSearched(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogTrigger asChild>
                <Button type="button" variant="ghost" size="icon" className={triggerClassName} aria-label="Search for ORCID">
                    <Search className="h-3 w-3" />
                </Button>
            </DialogTrigger>
            <DialogContent className="flex max-h-[80vh] max-w-2xl flex-col gap-0 p-0">
                <DialogHeader className="px-4 pt-4 pb-2">
                    <DialogTitle>Search for ORCID</DialogTitle>
                    <DialogDescription>Search for ORCID records by name, institution, or keywords</DialogDescription>
                </DialogHeader>

                <Command className="rounded-none border-0" shouldFilter={false}>
                    <CommandInput
                        placeholder="Search by name, institution, keywords..."
                        value={searchQuery}
                        onValueChange={handleSearchChange}
                    />
                    <CommandList className="max-h-none">
                        <ScrollArea className="h-[400px]">
                            {/* Loading state */}
                            {isSearching && (
                                <div className="flex items-center justify-center p-8 text-muted-foreground">
                                    <Spinner size="md" className="mr-2" />
                                    <span>Searching ORCID database...</span>
                                </div>
                            )}

                            {/* Empty state - no search yet */}
                            {!isSearching && !hasSearched && (
                                <div className="flex flex-col items-center justify-center p-8 text-muted-foreground">
                                    <Search className="mb-2 h-10 w-10 opacity-50" />
                                    <p className="text-sm">Start typing to search ORCID records</p>
                                    <p className="mt-1 text-xs">Tip: Use specific names or institutions for better results</p>
                                </div>
                            )}

                            {/* Empty state - no results */}
                            {!isSearching && hasSearched && results.length === 0 && <CommandEmpty>No ORCID records found. Try a different search term.</CommandEmpty>}

                            {/* Results */}
                            {!isSearching && results.length > 0 && (
                                <CommandGroup heading={`${results.length} result${results.length !== 1 ? 's' : ''} found`}>
                                    {results.map((result) => (
                                        <CommandItem
                                            key={result.orcid}
                                            value={result.orcid}
                                            onSelect={() => handleSelect(result)}
                                            className="flex cursor-pointer flex-col items-start gap-1 py-3"
                                        >
                                            <div className="flex w-full items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <User className="h-4 w-4 text-muted-foreground" />
                                                    <span className="font-medium">
                                                        {result.lastName || 'Unknown'}
                                                        {result.firstName && `, ${result.firstName}`}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <code className="rounded bg-muted px-2 py-0.5 text-xs">{result.orcid}</code>
                                                    <a
                                                        href={`https://orcid.org/${result.orcid}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        onClick={(e) => e.stopPropagation()}
                                                        className="text-muted-foreground transition-colors hover:text-foreground"
                                                        aria-label="View on ORCID.org"
                                                    >
                                                        <ExternalLink className="h-3.5 w-3.5" />
                                                    </a>
                                                </div>
                                            </div>
                                            {result.institutions.length > 0 && (
                                                <div className="ml-6 text-xs text-muted-foreground">
                                                    {result.institutions.slice(0, 2).join(' • ')}
                                                    {result.institutions.length > 2 && ` (+${result.institutions.length - 2} more)`}
                                                </div>
                                            )}
                                        </CommandItem>
                                    ))}
                                </CommandGroup>
                            )}
                        </ScrollArea>
                    </CommandList>
                </Command>
            </DialogContent>
        </Dialog>
    );
}

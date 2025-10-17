/**
 * OrcidSearchDialog Component
 * 
 * Modal dialog for searching ORCID records by name, institution, or keywords.
 * Allows users to find and select ORCID records without pre-filling name fields.
 */

import { ExternalLink, Loader2, Search } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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

    const handleSearch = async () => {
        if (!searchQuery.trim()) return;

        setIsSearching(true);
        setHasSearched(true);

        try {
            const response = await OrcidService.searchOrcid(searchQuery, 20);

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
    };

    const handleSelect = (result: OrcidSearchResult) => {
        onSelect(result);
        setOpen(false);
        // Reset dialog state
        setSearchQuery('');
        setResults([]);
        setHasSearched(false);
    };

    const handleKeyPress = (e: React.KeyboardEvent) => {
        if (e.key === 'Enter') {
            handleSearch();
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={triggerClassName}
                    aria-label="Search for ORCID"
                >
                    <Search className="h-3 w-3" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl max-h-[80vh] flex flex-col">
                <DialogHeader>
                    <DialogTitle>Search for ORCID</DialogTitle>
                    <DialogDescription>
                        Search for ORCID records by name, institution, or keywords
                    </DialogDescription>
                </DialogHeader>

                {/* Search Input */}
                <div className="space-y-2">
                    <Label htmlFor="orcid-search-query">Search Query</Label>
                    <div className="flex gap-2">
                        <Input
                            id="orcid-search-query"
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            onKeyPress={handleKeyPress}
                            placeholder="e.g., John Smith, GFZ, Geosciences..."
                            className="flex-1"
                            autoFocus
                        />
                        <Button
                            type="button"
                            onClick={handleSearch}
                            disabled={!searchQuery.trim() || isSearching}
                        >
                            {isSearching ? (
                                <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Searching...
                                </>
                            ) : (
                                <>
                                    <Search className="h-4 w-4 mr-2" />
                                    Search
                                </>
                            )}
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Tip: Use specific names or institutions for better results
                    </p>
                </div>

                {/* Results */}
                <div className="flex-1 overflow-y-auto border rounded-md mt-4">
                    {isSearching && (
                        <div className="flex items-center justify-center p-8 text-muted-foreground">
                            <Loader2 className="h-6 w-6 animate-spin mr-2" />
                            Searching ORCID database...
                        </div>
                    )}

                    {!isSearching && hasSearched && results.length === 0 && (
                        <div className="flex flex-col items-center justify-center p-8 text-muted-foreground">
                            <Search className="h-12 w-12 mb-2 opacity-50" />
                            <p className="text-sm">No results found</p>
                            <p className="text-xs mt-1">Try a different search query</p>
                        </div>
                    )}

                    {!isSearching && !hasSearched && (
                        <div className="flex flex-col items-center justify-center p-8 text-muted-foreground">
                            <Search className="h-12 w-12 mb-2 opacity-50" />
                            <p className="text-sm">Enter a search query to find ORCID records</p>
                        </div>
                    )}

                    {!isSearching && results.length > 0 && (
                        <div className="overflow-x-auto">
                            <table className="w-full">
                                <thead className="bg-muted/50 sticky top-0 z-10">
                                    <tr className="border-b">
                                        <th className="text-left p-3 text-sm font-semibold">Last Name</th>
                                        <th className="text-left p-3 text-sm font-semibold">First Name</th>
                                        <th className="text-left p-3 text-sm font-semibold">ORCID</th>
                                        <th className="text-left p-3 text-sm font-semibold">Current Affiliations</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {results.map((result) => (
                                        <tr
                                            key={result.orcid}
                                            onClick={() => handleSelect(result)}
                                            className="cursor-pointer hover:bg-accent transition-colors focus-within:bg-accent"
                                            tabIndex={0}
                                            onKeyPress={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    handleSelect(result);
                                                }
                                            }}
                                        >
                                            <td className="p-3 text-sm align-top">
                                                <span className="font-medium">
                                                    {result.lastName || '-'}
                                                </span>
                                            </td>
                                            <td className="p-3 text-sm align-top">
                                                {result.firstName || '-'}
                                            </td>
                                            <td className="p-3 text-sm align-top">
                                                <div className="flex items-center gap-2">
                                                    <code className="text-xs bg-muted px-2 py-0.5 rounded">
                                                        {result.orcid}
                                                    </code>
                                                    <a
                                                        href={`https://orcid.org/${result.orcid}`}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        onClick={(e) => e.stopPropagation()}
                                                        className="text-muted-foreground hover:text-foreground"
                                                        aria-label="View on ORCID.org"
                                                    >
                                                        <ExternalLink className="h-3.5 w-3.5" />
                                                    </a>
                                                </div>
                                            </td>
                                            <td className="p-3 text-sm align-top">
                                                {result.institutions.length > 0 ? (
                                                    <ul className="space-y-0.5 text-muted-foreground">
                                                        {result.institutions.map((inst, idx) => (
                                                            <li key={idx} className="truncate max-w-xs" title={inst}>
                                                                • {inst}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                ) : (
                                                    <span className="text-muted-foreground italic">
                                                        No affiliations
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                {/* Results Count */}
                {results.length > 0 && (
                    <div className="text-xs text-muted-foreground pt-2 border-t">
                        Found {results.length} result{results.length !== 1 ? 's' : ''}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

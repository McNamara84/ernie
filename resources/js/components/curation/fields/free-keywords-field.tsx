import { Info, Upload } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

import FreeKeywordsCsvImport from './free-keywords-csv-import';
import TagInputField, { type TagInputChangeDetail, type TagInputItem } from './tag-input-field';

interface FreeKeywordsFieldProps {
    keywords: TagInputItem[];
    onChange: (keywords: TagInputItem[]) => void;
}

/**
 * Free Keywords Field Component
 *
 * Provides a user-friendly interface for entering free-form keywords.
 * Users can add multiple keywords separated by commas.
 *
 * Best Practices Applied:
 * - Clear labeling with helpful description
 * - Visual feedback with info icon
 * - Accessible design with proper ARIA attributes
 * - Intuitive comma-separated input
 * - Clean, modern UI with consistent spacing
 */
export default function FreeKeywordsField({ keywords, onChange }: FreeKeywordsFieldProps) {
    const [isCsvImportOpen, setIsCsvImportOpen] = useState(false);

    const handleChange = (detail: TagInputChangeDetail) => {
        onChange(detail.tags);
    };

    const handleCsvImport = (importedKeywords: string[]) => {
        // Convert imported keywords to TagInputItem format
        // No need to filter - FreeKeywordsCsvImport already excludes existing keywords
        const newTags: TagInputItem[] = importedKeywords.map((keyword) => ({
            value: keyword,
        }));

        // Add imported keywords to existing ones
        onChange([...keywords, ...newTags]);
        setIsCsvImportOpen(false);
    };

    // Extract keyword values for CSV import duplicate detection
    const existingKeywordValues = keywords.map((k) => k.value);

    return (
        <div className="space-y-4">
            {/* Header with title, description, and CSV import button */}
            <div className="space-y-2">
                <div className="flex items-center justify-between gap-2">
                    <Label className="text-base font-semibold">Free Keywords</Label>
                    <Button type="button" variant="outline" size="sm" onClick={() => setIsCsvImportOpen(true)} className="gap-1.5">
                        <Upload className="h-3.5 w-3.5" />
                        CSV Import
                    </Button>
                </div>
                <div className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Info className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <p>
                        Add custom keywords to describe your dataset. Separate multiple keywords with commas. These help others discover your work
                        through search.
                    </p>
                </div>
            </div>

            {/* Tag Input Field */}
            <div className="space-y-2">
                <TagInputField
                    id="free-keywords"
                    label="Keywords"
                    hideLabel
                    value={keywords}
                    onChange={handleChange}
                    placeholder="e.g., climate change, temperature, precipitation"
                    data-testid="free-keywords-input"
                    containerProps={{
                        'data-testid': 'free-keywords-field',
                    }}
                    aria-describedby="free-keywords-help"
                />
                <p id="free-keywords-help" className="text-xs text-muted-foreground">
                    Press Enter or type a comma after each keyword to add it.
                </p>
            </div>

            {/* Keywords count indicator */}
            {keywords.length > 0 && (
                <div className="text-sm text-muted-foreground">
                    {keywords.length} {keywords.length === 1 ? 'keyword' : 'keywords'} added
                </div>
            )}

            {/* CSV Import Dialog */}
            <Dialog open={isCsvImportOpen} onOpenChange={setIsCsvImportOpen}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Import Free Keywords from CSV</DialogTitle>
                        <DialogDescription>
                            Upload a CSV file to bulk import multiple keywords at once. New keywords will be added to your existing list.
                        </DialogDescription>
                    </DialogHeader>
                    <FreeKeywordsCsvImport
                        onImport={handleCsvImport}
                        onClose={() => setIsCsvImportOpen(false)}
                        existingKeywords={existingKeywordValues}
                    />
                </DialogContent>
            </Dialog>
        </div>
    );
}

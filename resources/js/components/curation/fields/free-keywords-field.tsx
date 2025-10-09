import { Info } from 'lucide-react';

import { Label } from '@/components/ui/label';

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
export default function FreeKeywordsField({
    keywords,
    onChange,
}: FreeKeywordsFieldProps) {
    const handleChange = (detail: TagInputChangeDetail) => {
        onChange(detail.tags);
    };

    return (
        <div className="space-y-4">
            {/* Header with title and description */}
            <div className="space-y-2">
                <Label className="text-base font-semibold">
                    Free Keywords
                </Label>
                <div className="flex items-start gap-2 text-sm text-muted-foreground">
                    <Info className="h-4 w-4 mt-0.5 flex-shrink-0" aria-hidden="true" />
                    <p>
                        Add custom keywords to describe your dataset. Separate multiple keywords 
                        with commas. These help others discover your work through search.
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
                <p 
                    id="free-keywords-help" 
                    className="text-xs text-muted-foreground"
                >
                    Press Enter or type a comma after each keyword to add it.
                </p>
            </div>

            {/* Keywords count indicator */}
            {keywords.length > 0 && (
                <div className="text-sm text-muted-foreground">
                    {keywords.length} {keywords.length === 1 ? 'keyword' : 'keywords'} added
                </div>
            )}
        </div>
    );
}

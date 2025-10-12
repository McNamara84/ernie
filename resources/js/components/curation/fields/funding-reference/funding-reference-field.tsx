import { Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';

import { FundingReferenceItem } from './funding-reference-item';
import { loadRorFunders } from './ror-search';
import type { FundingReferenceEntry, RorFunder } from './types';
import { MAX_FUNDING_REFERENCES } from './types';

interface FundingReferenceFieldProps {
    value: FundingReferenceEntry[];
    onChange: (fundings: FundingReferenceEntry[]) => void;
}

export function FundingReferenceField({
    value = [],
    onChange,
}: FundingReferenceFieldProps) {
    const [, setRorFunders] = useState<RorFunder[]>([]);
    const [isLoadingRor, setIsLoadingRor] = useState(true);

    // Load ROR data on mount
    useEffect(() => {
        const loadData = async () => {
            try {
                const funders = await loadRorFunders();
                setRorFunders(funders);
            } catch (error) {
                console.error('Failed to load ROR funders:', error);
            } finally {
                setIsLoadingRor(false);
            }
        };
        loadData();
    }, []);

    const handleAdd = () => {
        if (value.length >= MAX_FUNDING_REFERENCES) return;

        const newFunding: FundingReferenceEntry = {
            id: `funding-${Date.now()}`,
            funderName: '',
            funderIdentifier: '',
            awardNumber: '',
            awardUri: '',
            awardTitle: '',
            isExpanded: false,
        };

        onChange([...value, newFunding]);
    };

    const handleRemove = (index: number) => {
        const updated = value.filter((_, i) => i !== index);
        onChange(updated);
    };

    const handleFieldChange = (
        index: number,
        field: keyof FundingReferenceEntry,
        fieldValue: string | boolean
    ) => {
        const updated = value.map((funding, i) =>
            i === index ? { ...funding, [field]: fieldValue } : funding
        );
        onChange(updated);
    };

    const handleToggleExpanded = (index: number) => {
        handleFieldChange(index, 'isExpanded', !value[index].isExpanded);
    };

    const canAdd = value.length < MAX_FUNDING_REFERENCES;
    const canRemove = value.length > 0;

    return (
        <div className="space-y-6">
            {/* Info Header */}
            <div className="flex items-center justify-between">
                <p className="text-sm text-muted-foreground">
                    {value.length} / {MAX_FUNDING_REFERENCES} funding reference
                    {value.length !== 1 ? 's' : ''}
                </p>
                {isLoadingRor && (
                    <p className="text-xs text-muted-foreground">
                        Loading ROR data...
                    </p>
                )}
            </div>

            {/* List of Funding References */}
            {value.length === 0 ? (
                <div className="rounded-lg border border-dashed border-border bg-muted/30 p-12 text-center">
                    <p className="text-sm text-muted-foreground">
                        No funding references added yet.
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        Click "Add Funding Reference" to get started.
                    </p>
                </div>
            ) : (
                <div className="space-y-4">
                    {value.map((funding, index) => (
                        <FundingReferenceItem
                            key={funding.id}
                            funding={funding}
                            index={index}
                            onFunderNameChange={(val) =>
                                handleFieldChange(index, 'funderName', val)
                            }
                            onAwardNumberChange={(val) =>
                                handleFieldChange(index, 'awardNumber', val)
                            }
                            onAwardUriChange={(val) =>
                                handleFieldChange(index, 'awardUri', val)
                            }
                            onAwardTitleChange={(val) =>
                                handleFieldChange(index, 'awardTitle', val)
                            }
                            onToggleExpanded={() => handleToggleExpanded(index)}
                            onRemove={() => handleRemove(index)}
                            canRemove={canRemove}
                        />
                    ))}
                </div>
            )}

            {/* Add Button */}
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={handleAdd}
                disabled={!canAdd}
                className="w-full"
            >
                <Plus className="mr-2 h-4 w-4" />
                Add Funding Reference
                {!canAdd && ' (Maximum reached)'}
            </Button>
        </div>
    );
}

import { Filter } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';

interface ResourceType {
    id: number;
    name: string;
}

interface LicenseResourceTypePopoverProps {
    licenseId: number;
    licenseName: string;
    resourceTypes: ResourceType[];
    excludedIds: number[];
    onExcludedChange: (excludedIds: number[]) => void;
}

export function LicenseResourceTypePopover({
    licenseId,
    licenseName,
    resourceTypes,
    excludedIds,
    onExcludedChange,
}: LicenseResourceTypePopoverProps) {
    const [open, setOpen] = React.useState(false);

    const toggleResourceType = (id: number) => {
        if (excludedIds.includes(id)) {
            onExcludedChange(excludedIds.filter((i) => i !== id));
        } else {
            onExcludedChange([...excludedIds, id]);
        }
    };

    const excludedCount = excludedIds.length;
    const availableCount = resourceTypes.length - excludedCount;

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    size="sm"
                    className="h-8 gap-1"
                    aria-label={`Configure resource types for ${licenseName}`}
                >
                    <Filter className="h-3.5 w-3.5" />
                    {excludedCount > 0 ? (
                        <Badge variant="secondary" className="rounded-sm px-1 font-normal">
                            {availableCount}/{resourceTypes.length}
                        </Badge>
                    ) : (
                        <span className="text-muted-foreground text-xs">All</span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-72 p-0" align="start">
                <div className="border-b p-3">
                    <h4 className="text-sm font-medium">Resource Type Restrictions</h4>
                    <p className="text-muted-foreground text-xs">
                        Uncheck resource types where this license should NOT be available.
                    </p>
                </div>
                <ScrollArea className="h-64">
                    <div className="space-y-1 p-3">
                        {resourceTypes.map((rt) => {
                            const isExcluded = excludedIds.includes(rt.id);
                            return (
                                <div key={rt.id} className="hover:bg-muted flex items-center space-x-2 rounded p-1">
                                    <Checkbox
                                        id={`rt-excl-${licenseId}-${rt.id}`}
                                        checked={!isExcluded}
                                        onCheckedChange={() => toggleResourceType(rt.id)}
                                    />
                                    <Label
                                        htmlFor={`rt-excl-${licenseId}-${rt.id}`}
                                        className="flex-1 cursor-pointer text-sm font-normal"
                                    >
                                        {rt.name}
                                    </Label>
                                    {isExcluded && (
                                        <Badge variant="destructive" className="text-xs">
                                            Excluded
                                        </Badge>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </ScrollArea>
                <div className="border-t p-2">
                    <div className="text-muted-foreground flex justify-between text-xs">
                        <span>Available for {availableCount} types</span>
                        {excludedCount > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-auto p-0 text-xs"
                                onClick={() => onExcludedChange([])}
                            >
                                Reset
                            </Button>
                        )}
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}

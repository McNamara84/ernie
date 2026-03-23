import { Calendar } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Slider } from '@/components/ui/slider';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { TemporalDateType, TemporalFilterValue, TemporalRange } from '@/types/portal';

const DATE_TYPE_LABELS: Record<TemporalDateType, string> = {
    Created: 'Created',
    Collected: 'Collected',
    Coverage: 'Coverage',
};

const DATE_TYPE_DESCRIPTIONS: Record<TemporalDateType, string> = {
    Created: 'When the data was created',
    Collected: 'When the data was collected (temporal coverage)',
    Coverage: 'Temporal coverage period',
};

interface PortalTemporalFilterProps {
    enabled: boolean;
    onToggle: (enabled: boolean) => void;
    temporalRange: TemporalRange;
    temporal: TemporalFilterValue | null;
    onTemporalChange: (temporal: TemporalFilterValue | null) => void;
}

export function PortalTemporalFilter({ enabled, onToggle, temporalRange, temporal, onTemporalChange }: PortalTemporalFilterProps) {
    const availableTypes = useMemo(
        () => (Object.keys(temporalRange) as TemporalDateType[]).filter((key) => temporalRange[key] !== undefined),
        [temporalRange],
    );

    const [selectedType, setSelectedType] = useState<TemporalDateType>(() => temporal?.dateType ?? availableTypes[0] ?? 'Created');

    const currentRange = useMemo(() => temporalRange[selectedType], [temporalRange, selectedType]);

    const [yearFrom, setYearFrom] = useState<number>(() => temporal?.yearFrom ?? currentRange?.min ?? 1900);
    const [yearTo, setYearTo] = useState<number>(() => temporal?.yearTo ?? currentRange?.max ?? new Date().getFullYear());

    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Sync local state when temporal filter changes from URL
    useEffect(() => {
        if (temporal) {
            setSelectedType(temporal.dateType);
            setYearFrom(temporal.yearFrom);
            setYearTo(temporal.yearTo);
        } else if (currentRange) {
            setYearFrom(currentRange.min);
            setYearTo(currentRange.max);
        }
    }, [temporal, currentRange]);

    // Cleanup debounce on unmount
    useEffect(() => {
        return () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        };
    }, []);

    const emitChange = useCallback(
        (type: TemporalDateType, from: number, to: number) => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
            debounceRef.current = setTimeout(() => {
                onTemporalChange({ dateType: type, yearFrom: from, yearTo: to });
            }, 300);
        },
        [onTemporalChange],
    );

    const handleSliderChange = useCallback(
        (values: number[]) => {
            const [from, to] = values;
            setYearFrom(from);
            setYearTo(to);
            emitChange(selectedType, from, to);
        },
        [selectedType, emitChange],
    );

    const handleTabChange = useCallback(
        (type: string) => {
            const dateType = type as TemporalDateType;
            setSelectedType(dateType);
            const range = temporalRange[dateType];
            if (range) {
                setYearFrom(range.min);
                setYearTo(range.max);
                // Reset to full range on tab change – clear filter
                onTemporalChange({ dateType, yearFrom: range.min, yearTo: range.max });
            }
        },
        [temporalRange, onTemporalChange],
    );

    const handleYearFromInput = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            const val = parseInt(e.target.value, 10);
            if (isNaN(val) || !currentRange) return;
            const clamped = Math.max(currentRange.min, Math.min(val, yearTo));
            setYearFrom(clamped);
            emitChange(selectedType, clamped, yearTo);
        },
        [currentRange, yearTo, selectedType, emitChange],
    );

    const handleYearToInput = useCallback(
        (e: React.ChangeEvent<HTMLInputElement>) => {
            const val = parseInt(e.target.value, 10);
            if (isNaN(val) || !currentRange) return;
            const clamped = Math.min(currentRange.max, Math.max(val, yearFrom));
            setYearTo(clamped);
            emitChange(selectedType, yearFrom, clamped);
        },
        [currentRange, yearFrom, selectedType, emitChange],
    );

    const handleToggle = useCallback(
        (value: boolean) => {
            onToggle(value);
            if (!value) {
                if (debounceRef.current) {
                    clearTimeout(debounceRef.current);
                    debounceRef.current = null;
                }
                onTemporalChange(null);
            }
        },
        [onToggle, onTemporalChange],
    );

    const isSingleYear = currentRange && currentRange.min === currentRange.max;

    // If no date types have data, hide the entire filter
    if (availableTypes.length === 0) {
        return null;
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <Label className="flex items-center gap-2 text-sm font-medium">
                    <Calendar className="h-4 w-4" />
                    Temporal Filter
                </Label>
                <Switch checked={enabled} onCheckedChange={handleToggle} aria-label="Enable temporal filter" />
            </div>

            {enabled && (
                <div className="space-y-3">
                    {/* Date type tabs – only show if more than 1 type available */}
                    {availableTypes.length > 1 && (
                        <Tabs value={selectedType} onValueChange={handleTabChange}>
                            <TabsList className="w-full">
                                {availableTypes.map((type) => (
                                    <TabsTrigger key={type} value={type} className="flex-1 text-xs">
                                        {DATE_TYPE_LABELS[type]}
                                    </TabsTrigger>
                                ))}
                            </TabsList>
                        </Tabs>
                    )}

                    {currentRange && (
                        <>
                            {isSingleYear ? (
                                <p className="text-center text-sm text-muted-foreground">
                                    All records from {currentRange.min}
                                </p>
                            ) : (
                                <>
                                    {/* Dual range slider */}
                                    <Slider
                                        value={[yearFrom, yearTo]}
                                        min={currentRange.min}
                                        max={currentRange.max}
                                        step={1}
                                        onValueChange={handleSliderChange}
                                        aria-label="Temporal filter year range"
                                    />

                                    {/* Year inputs */}
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="number"
                                            value={yearFrom}
                                            onChange={handleYearFromInput}
                                            min={currentRange.min}
                                            max={yearTo}
                                            className="h-8 w-20 text-center text-xs"
                                            aria-label="Minimum year"
                                        />
                                        <span className="text-xs text-muted-foreground">—</span>
                                        <Input
                                            type="number"
                                            value={yearTo}
                                            onChange={handleYearToInput}
                                            min={yearFrom}
                                            max={currentRange.max}
                                            className="h-8 w-20 text-center text-xs"
                                            aria-label="Maximum year"
                                        />
                                    </div>
                                </>
                            )}
                        </>
                    )}

                    {/* Description text */}
                    <p className="text-xs text-muted-foreground">{DATE_TYPE_DESCRIPTIONS[selectedType]}</p>
                </div>
            )}
        </div>
    );
}

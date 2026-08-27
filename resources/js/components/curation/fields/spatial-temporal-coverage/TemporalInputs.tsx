import { useMemo } from 'react';

import { Label } from '@/components/ui/label';
import {
    isCompleteCoverageDate,
    isCoverageRangeReversed,
    isCoverageTimeRangeReversed,
    isValidCoverageDate,
    isValidCoverageTime,
} from '@/lib/temporal-coverage';

import InputField from '../input-field';
import { SelectField } from '../select-field';

interface TemporalInputsProps {
    startDate: string;
    endDate: string;
    startTime: string;
    endTime: string;
    timezone: string;
    onChange: (field: 'startDate' | 'endDate' | 'startTime' | 'endTime' | 'timezone', value: string) => void;
    showLabels?: boolean;
}

/**
 * Common timezone options
 * Source: IANA Time Zone Database
 */
const TIMEZONE_OPTIONS = [
    { value: 'UTC', label: 'UTC (Coordinated Universal Time)' },
    { value: 'Europe/Berlin', label: 'Europe/Berlin (CET/CEST)' },
    { value: 'Europe/London', label: 'Europe/London (GMT/BST)' },
    { value: 'Europe/Paris', label: 'Europe/Paris (CET/CEST)' },
    { value: 'Europe/Rome', label: 'Europe/Rome (CET/CEST)' },
    { value: 'Europe/Vienna', label: 'Europe/Vienna (CET/CEST)' },
    { value: 'Europe/Zurich', label: 'Europe/Zurich (CET/CEST)' },
    { value: 'America/New_York', label: 'America/New_York (EST/EDT)' },
    { value: 'America/Chicago', label: 'America/Chicago (CST/CDT)' },
    { value: 'America/Denver', label: 'America/Denver (MST/MDT)' },
    { value: 'America/Los_Angeles', label: 'America/Los_Angeles (PST/PDT)' },
    { value: 'America/Toronto', label: 'America/Toronto (EST/EDT)' },
    { value: 'America/Vancouver', label: 'America/Vancouver (PST/PDT)' },
    { value: 'Asia/Tokyo', label: 'Asia/Tokyo (JST)' },
    { value: 'Asia/Shanghai', label: 'Asia/Shanghai (CST)' },
    { value: 'Asia/Hong_Kong', label: 'Asia/Hong_Kong (HKT)' },
    { value: 'Asia/Singapore', label: 'Asia/Singapore (SGT)' },
    { value: 'Asia/Dubai', label: 'Asia/Dubai (GST)' },
    { value: 'Australia/Sydney', label: 'Australia/Sydney (AEDT/AEST)' },
    { value: 'Australia/Melbourne', label: 'Australia/Melbourne (AEDT/AEST)' },
    { value: 'Pacific/Auckland', label: 'Pacific/Auckland (NZDT/NZST)' },
];

export default function TemporalInputs({ startDate, endDate, startTime, endTime, timezone, onChange, showLabels = true }: TemporalInputsProps) {
    const timezoneOptions = useMemo(() => {
        if (!timezone || TIMEZONE_OPTIONS.some((opt) => opt.value === timezone)) {
            return TIMEZONE_OPTIONS;
        }

        const isOffset = /^[+-]\d{2}:\d{2}$/.test(timezone);
        const label = isOffset ? `UTC${timezone} (imported)` : `${timezone} (imported)`;
        const importedOption = { value: timezone, label };
        return [importedOption, ...TIMEZONE_OPTIONS];
    }, [timezone]);

    return (
        <div className="space-y-4">
            {showLabels && <Label className="text-sm font-medium">Temporal Information</Label>}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {/* Start Date & Time */}
                <div className="space-y-3">
                    <Label className="text-xs font-semibold text-muted-foreground uppercase">Start</Label>
                    <div className="space-y-2">
                        <InputField
                            id="start-date"
                            label="Date (optional)"
                            type="text"
                            value={startDate}
                            onChange={(e) => onChange('startDate', e.target.value)}
                            placeholder="YYYY, YYYY-MM, or YYYY-MM-DD"
                            className={startDate && !isValidCoverageDate(startDate) ? 'border-destructive' : ''}
                        />
                        {startDate && !isValidCoverageDate(startDate) && (
                            <p className="text-xs text-destructive">Date must use YYYY, YYYY-MM, or YYYY-MM-DD format</p>
                        )}
                        <InputField
                            id="start-time"
                            label="Time (optional)"
                            type="time"
                            value={startTime}
                            onChange={(e) => onChange('startTime', e.target.value)}
                            placeholder="HH:MM or HH:MM:SS"
                            className={
                                startTime && (!isValidCoverageTime(startTime) || !isCompleteCoverageDate(startDate)) ? 'border-destructive' : ''
                            }
                        />
                        {startTime && !isValidCoverageTime(startTime) && (
                            <p className="text-xs text-destructive">Time must be in HH:MM or HH:MM:SS format</p>
                        )}
                        {startTime && isValidCoverageTime(startTime) && !isCompleteCoverageDate(startDate) && (
                            <p className="text-xs text-destructive">A start time requires a complete start date</p>
                        )}
                    </div>
                </div>

                {/* End Date & Time */}
                <div className="space-y-3">
                    <Label className="text-xs font-semibold text-muted-foreground uppercase">End</Label>
                    <div className="space-y-2">
                        <InputField
                            id="end-date"
                            label="Date (optional)"
                            type="text"
                            value={endDate}
                            onChange={(e) => onChange('endDate', e.target.value)}
                            placeholder="YYYY, YYYY-MM, or YYYY-MM-DD"
                            className={endDate && !isValidCoverageDate(endDate) ? 'border-destructive' : ''}
                        />
                        {endDate && !isValidCoverageDate(endDate) && (
                            <p className="text-xs text-destructive">Date must use YYYY, YYYY-MM, or YYYY-MM-DD format</p>
                        )}
                        <InputField
                            id="end-time"
                            label="Time (optional)"
                            type="time"
                            value={endTime}
                            onChange={(e) => onChange('endTime', e.target.value)}
                            placeholder="HH:MM or HH:MM:SS"
                            className={endTime && (!isValidCoverageTime(endTime) || !isCompleteCoverageDate(endDate)) ? 'border-destructive' : ''}
                        />
                        {endTime && !isValidCoverageTime(endTime) && (
                            <p className="text-xs text-destructive">Time must be in HH:MM or HH:MM:SS format</p>
                        )}
                        {endTime && isValidCoverageTime(endTime) && !isCompleteCoverageDate(endDate) && (
                            <p className="text-xs text-destructive">An end time requires a complete end date</p>
                        )}
                    </div>
                </div>
            </div>

            {/* Timezone */}
            <div>
                <SelectField
                    id="timezone"
                    label="Timezone (optional)"
                    value={timezone}
                    onValueChange={(value) => onChange('timezone', value)}
                    options={timezoneOptions}
                    clearable
                />
                {timezone && !startDate && !endDate && <p className="mt-2 text-xs text-destructive">A timezone requires a start or end date</p>}
            </div>

            {/* Validation: Start date must be before end date */}
            {startDate && endDate && isCoverageRangeReversed(startDate, endDate) && (
                <p className="text-xs text-destructive">Start date must be before or equal to end date</p>
            )}

            {/* Validation: If same date, start time must be before end time */}
            {startDate &&
                endDate &&
                startDate === endDate &&
                startTime &&
                endTime &&
                isValidCoverageTime(startTime) &&
                isValidCoverageTime(endTime) &&
                isCoverageTimeRangeReversed(startTime, endTime) && (
                    <p className="text-xs text-destructive">Start time must be before or equal to end time when dates are the same</p>
                )}
        </div>
    );
}

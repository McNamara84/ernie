import React from 'react';

import { Label } from '@/components/ui/label';

import InputField from '../input-field';
import { SelectField } from '../select-field';

interface TemporalInputsProps {
    startDate: string;
    endDate: string;
    startTime: string;
    endTime: string;
    timezone: string;
    onChange: (
        field: 'startDate' | 'endDate' | 'startTime' | 'endTime' | 'timezone',
        value: string,
    ) => void;
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

/**
 * Validates time format (HH:MM or HH:MM:SS)
 */
const isValidTime = (value: string): boolean => {
    if (!value) return true; // Empty is valid (optional field)
    // Accept both HH:MM and HH:MM:SS formats
    const timeRegex = /^([0-1][0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/;
    return timeRegex.test(value);
};

export default function TemporalInputs({
    startDate,
    endDate,
    startTime,
    endTime,
    timezone,
    onChange,
    showLabels = true,
}: TemporalInputsProps) {
    return (
        <div className="space-y-4">
            {showLabels && (
                <Label className="text-sm font-medium">Temporal Information</Label>
            )}

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* Start Date & Time */}
                <div className="space-y-3">
                    <Label className="text-xs font-semibold text-muted-foreground uppercase">
                        Start
                    </Label>
                    <div className="space-y-2">
                        <InputField
                            id="start-date"
                            label="Date (optional)"
                            type="date"
                            value={startDate}
                            onChange={(e) => onChange('startDate', e.target.value)}
                        />
                        <InputField
                            id="start-time"
                            label="Time (optional)"
                            type="time"
                            value={startTime}
                            onChange={(e) => onChange('startTime', e.target.value)}
                            placeholder="HH:MM or HH:MM:SS"
                            className={
                                startTime && !isValidTime(startTime)
                                    ? 'border-destructive'
                                    : ''
                            }
                        />
                        {startTime && !isValidTime(startTime) && (
                            <p className="text-xs text-destructive">
                                Time must be in HH:MM or HH:MM:SS format
                            </p>
                        )}
                    </div>
                </div>

                {/* End Date & Time */}
                <div className="space-y-3">
                    <Label className="text-xs font-semibold text-muted-foreground uppercase">
                        End
                    </Label>
                    <div className="space-y-2">
                        <InputField
                            id="end-date"
                            label="Date (optional)"
                            type="date"
                            value={endDate}
                            onChange={(e) => onChange('endDate', e.target.value)}
                        />
                        <InputField
                            id="end-time"
                            label="Time (optional)"
                            type="time"
                            value={endTime}
                            onChange={(e) => onChange('endTime', e.target.value)}
                            placeholder="HH:MM or HH:MM:SS"
                            className={
                                endTime && !isValidTime(endTime) ? 'border-destructive' : ''
                            }
                        />
                        {endTime && !isValidTime(endTime) && (
                            <p className="text-xs text-destructive">
                                Time must be in HH:MM or HH:MM:SS format
                            </p>
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
                    options={TIMEZONE_OPTIONS}
                />
            </div>

            {/* Validation: Start date must be before end date */}
            {startDate && endDate && startDate > endDate && (
                <p className="text-xs text-destructive">
                    Start date must be before or equal to end date
                </p>
            )}

            {/* Validation: If same date, start time must be before end time */}
            {startDate &&
                endDate &&
                startDate === endDate &&
                startTime &&
                endTime &&
                isValidTime(startTime) &&
                isValidTime(endTime) &&
                startTime >= endTime && (
                    <p className="text-xs text-destructive">
                        Start time must be before end time when dates are the same
                    </p>
                )}
        </div>
    );
}

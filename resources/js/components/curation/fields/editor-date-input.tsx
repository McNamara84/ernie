import { de } from 'date-fns/locale';
import { CalendarDays, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    createEditorCalendarDate,
    EDITOR_MAX_DATE,
    EDITOR_MAX_YEAR,
    EDITOR_MIN_DATE,
    EDITOR_MIN_YEAR,
    type EditorDateLocale,
    editorDateToCalendarDate,
    formatEditorDate,
    todayLocalIso,
    validateEditorDate,
} from '@/lib/editor-date';

interface EditorDateInputProps {
    id: string;
    inputLabel: string;
    value: string | null;
    onChange: (value: string, committed?: boolean) => void;
    onEditingChange?: (id: string, editing: boolean) => void;
    locale: EditorDateLocale;
    calendarLabel: string;
    clearLabel: string;
    allowFuture?: boolean;
    readOnly?: boolean;
}

/** Text and calendar controls for one ISO or reduced-precision editor date. */
export function EditorDateInput({
    id,
    inputLabel,
    value,
    onChange,
    onEditingChange,
    locale,
    calendarLabel,
    clearLabel,
    allowFuture = false,
    readOnly = false,
}: EditorDateInputProps) {
    const [focused, setFocused] = useState(false);
    const [draft, setDraft] = useState('');
    const [touched, setTouched] = useState(false);
    const [open, setOpen] = useState(false);
    const [month, setMonth] = useState<Date>(new Date());
    const [yearDraft, setYearDraft] = useState('');
    const [yearTouched, setYearTouched] = useState(false);
    const today = new Date();
    const maxYear = allowFuture ? EDITOR_MAX_YEAR : today.getFullYear();
    const year = /^\d{1,4}$/.test(yearDraft) ? Number(yearDraft) : Number.NaN;
    const validYear = Number.isInteger(year) && year >= EDITOR_MIN_YEAR && year <= maxYear;
    const yearError = yearTouched && !validYear ? 'Enter a year between 1 and ' + maxYear + '.' : null;
    const yearId = id + '-calendar-year';
    const yearErrorId = yearId + '-error';
    const validation = value?.trim() ? validateEditorDate(value, locale, new Date(), allowFuture) : null;
    const error = touched ? validation?.error : null;
    const calendarDate = validation?.error ? undefined : editorDateToCalendarDate(value, locale);
    const selected = validation?.parsed?.precision === 'day' ? calendarDate : undefined;
    const errorId = `${id}-error`;

    const handleBlur = () => {
        setFocused(false);
        setTouched(true);
        onEditingChange?.(id, false);
        if (readOnly) return;
        if (!draft.trim()) {
            onChange('', true);
            return;
        }
        const result = validateEditorDate(draft, locale, new Date(), allowFuture);
        if (!result.error && result.parsed) onChange(result.parsed.iso, true);
    };

    const handleOpenChange = (nextOpen: boolean) => {
        if (nextOpen) {
            const initialMonth = calendarDate ?? new Date();
            setMonth(initialMonth);
            setYearDraft(String(initialMonth.getFullYear()));
            setYearTouched(false);
        }
        setOpen(nextOpen);
    };

    const handleMonthChange = (nextMonth: Date) => {
        setMonth(nextMonth);
        setYearDraft(String(nextMonth.getFullYear()));
        setYearTouched(false);
    };

    const commitYear = () => {
        setYearTouched(true);
        if (!validYear) return;
        const maxMonth = !allowFuture && year === maxYear ? today.getMonth() : 11;
        setMonth(createEditorCalendarDate(year, Math.min(month.getMonth(), maxMonth)));
    };

    return (
        <div className="space-y-1">
            <div className="flex min-w-0 gap-1">
                <Input
                    id={id}
                    aria-label={inputLabel}
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    value={focused && !readOnly ? draft : formatEditorDate(value, locale)}
                    readOnly={readOnly}
                    placeholder={locale === 'de' ? 'DD.MM.YYYY or YYYY-MM-DD' : 'YYYY-MM-DD'}
                    aria-invalid={Boolean(error)}
                    aria-describedby={error ? errorId : undefined}
                    title={locale === 'de' ? 'Also accepts YYYY, YYYY-MM and YYYY-MM-DD' : 'Also accepts YYYY and YYYY-MM'}
                    onFocus={() => {
                        setDraft(formatEditorDate(value, locale));
                        setFocused(true);
                        onEditingChange?.(id, true);
                    }}
                    onChange={(event) => {
                        setDraft(event.target.value);
                        onChange(event.target.value);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            event.currentTarget.blur();
                        }
                    }}
                    onBlur={handleBlur}
                />
                {value && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="shrink-0"
                        aria-label={clearLabel}
                        onClick={() => onChange('', true)}
                    >
                        <X className="size-4" />
                    </Button>
                )}
                <Popover open={open && !readOnly} onOpenChange={handleOpenChange}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="outline" size="icon" className="shrink-0" aria-label={calendarLabel}>
                            <CalendarDays className="size-4" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                        <div className="flex items-center gap-2 px-3 pt-3">
                            <Label htmlFor={yearId}>Year</Label>
                            <Input
                                id={yearId}
                                type="number"
                                inputMode="numeric"
                                min={EDITOR_MIN_YEAR}
                                max={maxYear}
                                step={1}
                                className="h-8 w-24"
                                value={yearDraft}
                                aria-invalid={Boolean(yearError)}
                                aria-describedby={yearError ? yearErrorId : undefined}
                                onChange={(event) => setYearDraft(event.target.value)}
                                onBlur={commitYear}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        commitYear();
                                    }
                                }}
                            />
                        </div>
                        {yearError && (
                            <p id={yearErrorId} role="alert" className="px-3 pt-1 text-xs text-destructive">
                                {yearError}
                            </p>
                        )}
                        <Calendar
                            mode="single"
                            month={month}
                            onMonthChange={handleMonthChange}
                            selected={selected}
                            onSelect={(date) => {
                                onChange(date ? todayLocalIso(date) : '', true);
                                setTouched(false);
                                setOpen(false);
                            }}
                            captionLayout="dropdown-months"
                            navLayout="after"
                            startMonth={editorDateToCalendarDate(EDITOR_MIN_DATE, 'iso')}
                            endMonth={
                                allowFuture ? editorDateToCalendarDate(EDITOR_MAX_DATE, 'iso') : createEditorCalendarDate(maxYear, today.getMonth())
                            }
                            disabled={(date) =>
                                date.getFullYear() < EDITOR_MIN_YEAR ||
                                date.getFullYear() > EDITOR_MAX_YEAR ||
                                (!allowFuture && todayLocalIso(date) > todayLocalIso(today))
                            }
                            locale={locale === 'de' ? de : undefined}
                            autoFocus
                        />
                    </PopoverContent>
                </Popover>
            </div>
            {error && (
                <p id={errorId} role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

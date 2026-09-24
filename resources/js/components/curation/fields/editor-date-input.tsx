import { de } from 'date-fns/locale';
import { CalendarDays, X } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import {
    EDITOR_MIN_DATE,
    type EditorDateLocale,
    editorDateToCalendarDate,
    formatEditorDate,
    todayLocalIso,
    validateEditorDate,
} from '@/lib/editor-date';

interface EditorDateInputProps {
    id: string;
    value: string | null;
    onChange: (value: string) => void;
    onEditingChange?: (id: string, editing: boolean) => void;
    locale: EditorDateLocale;
    calendarLabel: string;
    clearLabel: string;
}

/** Text and calendar controls for one ISO or reduced-precision editor date. */
export function EditorDateInput({ id, value, onChange, onEditingChange, locale, calendarLabel, clearLabel }: EditorDateInputProps) {
    const [focused, setFocused] = useState(false);
    const [draft, setDraft] = useState('');
    const [touched, setTouched] = useState(false);
    const [open, setOpen] = useState(false);
    const [month, setMonth] = useState<Date>(new Date());
    const validation = value?.trim() ? validateEditorDate(value, locale) : null;
    const error = touched ? validation?.error : null;
    const calendarDate = validation?.error ? undefined : editorDateToCalendarDate(value, locale);
    const selected = validation?.parsed?.precision === 'day' ? calendarDate : undefined;
    const errorId = `${id}-error`;

    const handleBlur = () => {
        setFocused(false);
        setTouched(true);
        onEditingChange?.(id, false);
        if (!draft.trim()) {
            onChange('');
            return;
        }
        const result = validateEditorDate(draft, locale);
        if (!result.error && result.parsed) onChange(result.parsed.iso);
    };

    const handleOpenChange = (nextOpen: boolean) => {
        if (nextOpen) setMonth(calendarDate ?? new Date());
        setOpen(nextOpen);
    };

    return (
        <div className="space-y-1">
            <div className="flex min-w-0 gap-1">
                <Input
                    id={id}
                    type="text"
                    inputMode="numeric"
                    autoComplete="off"
                    value={focused ? draft : formatEditorDate(value, locale)}
                    placeholder={locale === 'de' ? 'TT.MM.JJJJ oder JJJJ-MM-TT' : 'YYYY-MM-DD'}
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
                    <Button type="button" variant="outline" size="icon" className="shrink-0" aria-label={clearLabel} onClick={() => onChange('')}>
                        <X className="size-4" />
                    </Button>
                )}
                <Popover open={open} onOpenChange={handleOpenChange}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="outline" size="icon" className="shrink-0" aria-label={calendarLabel}>
                            <CalendarDays className="size-4" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-auto p-0" align="start">
                        <Calendar
                            mode="single"
                            month={month}
                            onMonthChange={setMonth}
                            selected={selected}
                            onSelect={(date) => {
                                onChange(date ? todayLocalIso(date) : '');
                                setTouched(false);
                                setOpen(false);
                            }}
                            captionLayout="dropdown"
                            navLayout="after"
                            startMonth={new Date(1900, 0)}
                            endMonth={new Date(new Date().getFullYear(), new Date().getMonth())}
                            disabled={(date) => todayLocalIso(date) < EDITOR_MIN_DATE || todayLocalIso(date) > todayLocalIso()}
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

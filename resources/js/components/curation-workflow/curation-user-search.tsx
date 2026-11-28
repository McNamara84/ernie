import axios from 'axios';
import { useEffect, useState } from 'react';

import { Input } from '@/components/ui/input';
import type { CurationUser } from '@/types/curation';

export function CurationUserSearch({ mentions = false, onUsers }: { mentions?: boolean; onUsers: (users: CurationUser[]) => void }) {
    const [query, setQuery] = useState('');
    const [error, setError] = useState('');
    useEffect(() => {
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            void axios
                .get<CurationUser[]>('/curation/users', { params: { q: query, mentions: mentions ? 1 : 0 }, signal: controller.signal })
                .then((response) => {
                    if (!controller.signal.aborted) {
                        onUsers(response.data);
                        setError('');
                    }
                })
                .catch(() => {
                    if (!controller.signal.aborted) setError('Could not search colleagues. Change the search to retry.');
                });
        }, 200);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query, mentions, onUsers]);
    return (
        <div className="space-y-1">
            <label className="text-sm">
                Search colleagues by name or email
                <Input value={query} maxLength={100} onChange={(event) => setQuery(event.target.value)} />
            </label>
            {error && <p role="alert">{error}</p>}
        </div>
    );
}

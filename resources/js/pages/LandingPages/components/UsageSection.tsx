import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

import { LandingPageCard } from './LandingPageCard';

export interface UsageStatus {
    state: 'inactive' | 'placeholder' | 'pending' | 'ready' | 'incomplete';
    startedAt: string | null;
    lastAggregatedAt: string | null;
    dataThrough: string | null;
    metricsRevision: number;
    paused: boolean;
    incompleteDays: string[];
    methodologyUrl: string;
}

export interface UsageData extends UsageStatus {
    views: number;
    downloads: number | null;
    months: { month: string; views: number | null; downloads: number | null; partial: boolean; incomplete: boolean }[];
}

const number = (value: number | null) => (value === null ? 'Pending' : value.toLocaleString('en-GB'));

export function UsageSection({ usage }: { usage: UsageData }) {
    const hasDownloads = usage.downloads !== null;
    const hasData = usage.state === 'ready' || usage.state === 'incomplete';
    const hasActivity = usage.months.some((month) => (month.views ?? 0) > 0 || (month.downloads ?? 0) > 0);

    return (
        <LandingPageCard aria-label="Usage">
            <h2 className="mb-4 text-xl font-semibold">Usage</h2>
            {hasData ? (
                <>
                    <dl className="mb-4 flex flex-wrap gap-8">
                        <div>
                            <dt>Views</dt>
                            <dd className="text-2xl font-semibold">{number(usage.views)}</dd>
                        </div>
                        {hasDownloads && (
                            <div>
                                <dt>Downloads</dt>
                                <dd className="text-2xl font-semibold">{number(usage.downloads)}</dd>
                            </div>
                        )}
                    </dl>
                    <p className="text-sm text-muted-foreground">Unique usage from regular access since {usage.startedAt?.slice(0, 10)} (UTC).</p>
                    {hasDownloads && (
                        <p className="text-sm text-muted-foreground">Downloads measure clicks on download links, not completed transfers.</p>
                    )}
                    {usage.state === 'incomplete' && (
                        <p role="status" className="my-2 text-sm">
                            Some days have missing observations. Totals may be incomplete.
                        </p>
                    )}
                    {hasActivity ? (
                        <div className="my-4 h-56" aria-label="Monthly usage chart; equivalent values are available in the table below">
                            <ResponsiveContainer width="100%" height="100%">
                                <LineChart data={usage.months} accessibilityLayer>
                                    <CartesianGrid strokeDasharray="3 3" />
                                    <XAxis dataKey="month" />
                                    <YAxis allowDecimals={false} width={45} />
                                    <Tooltip />
                                    <Legend />
                                    <Line type="monotone" dataKey="views" name="Views" stroke="#2563eb" strokeWidth={2} dot />
                                    {hasDownloads && (
                                        <Line
                                            type="monotone"
                                            dataKey="downloads"
                                            name="Downloads"
                                            stroke="#15803d"
                                            strokeDasharray="5 3"
                                            strokeWidth={2}
                                            dot
                                        />
                                    )}
                                </LineChart>
                            </ResponsiveContainer>
                        </div>
                    ) : (
                        <p className="my-4 text-sm">No usage recorded in the displayed months.</p>
                    )}
                    <details className="my-4">
                        <summary className="cursor-pointer text-sm underline">Monthly data table</summary>
                        <div className="overflow-x-auto">
                            <table className="mt-2 w-full text-left text-sm">
                                <caption className="sr-only">Usage by UTC calendar month</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Month (UTC)</th>
                                        <th scope="col">Views</th>
                                        {hasDownloads && <th scope="col">Downloads</th>}
                                        <th scope="col">Coverage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {usage.months.map((month) => (
                                        <tr key={month.month}>
                                            <th scope="row" className="py-1 font-normal">
                                                {month.month}
                                            </th>
                                            <td>{number(month.views)}</td>
                                            {hasDownloads && <td>{number(month.downloads)}</td>}
                                            <td>
                                                {month.incomplete
                                                    ? 'Missing observations'
                                                    : month.views === null
                                                      ? 'Pending'
                                                      : month.partial
                                                        ? 'Partial month'
                                                        : 'Complete month'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </details>
                    <p className="text-xs text-muted-foreground">
                        Data through {usage.dataThrough} (UTC). Last aggregated: {usage.lastAggregatedAt?.replace('T', ' ').replace('+00:00', ' UTC')}
                        .
                    </p>
                </>
            ) : (
                <p className="mb-4 text-sm text-muted-foreground">
                    {usage.state === 'placeholder'
                        ? 'Usage becomes available after publication and aggregation.'
                        : usage.state === 'inactive'
                          ? 'Usage collection has not started yet.'
                          : 'Usage collection has started. The first daily aggregation is pending.'}
                </p>
            )}
            {usage.paused && <p className="text-sm">Collection is currently paused.</p>}
            <a className="mt-3 inline-block text-sm underline" href={usage.methodologyUrl}>
                How we count
            </a>
        </LandingPageCard>
    );
}

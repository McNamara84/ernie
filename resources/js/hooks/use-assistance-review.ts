import { queryOptions, useQuery } from '@tanstack/react-query';
import axios from 'axios';

import { queryKeys } from '@/lib/query-keys';
import type { AssistanceResourceGroup, AssistanceSummary, PaginatedData } from '@/types/assistance';
import type { ResourceImpactFilterState } from '@/types/resource-impact-filters';

export class AssistanceRequestError extends Error {
    constructor(
        message: string,
        public readonly status: number | null,
        public readonly requestId: string,
    ) {
        super(message);
        this.name = 'AssistanceRequestError';
    }
}

function requestId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();

    return `assistance-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function normalizedDoi(filters: ResourceImpactFilterState): string | null {
    const doi = filters.doi?.trim();

    return doi ? doi.toLowerCase() : null;
}

function params(filters: ResourceImpactFilterState, page?: number, perPage?: number): Record<string, string | number> {
    return {
        ...(normalizedDoi(filters) ? { doi: normalizedDoi(filters) as string } : {}),
        ...(filters.datacenter_id !== null ? { datacenter_id: filters.datacenter_id } : {}),
        ...(page !== undefined ? { page } : {}),
        ...(perPage !== undefined ? { per_page: perPage } : {}),
    };
}

async function get<T>(url: string, filters: ResourceImpactFilterState, signal: AbortSignal, page?: number, perPage?: number): Promise<T> {
    const clientRequestId = requestId();

    try {
        const response = await axios.get<T>(url, {
            params: params(filters, page, perPage),
            signal,
            headers: { 'X-Assistance-Request-Id': clientRequestId },
        });

        return response.data;
    } catch (error) {
        if (!axios.isAxiosError(error)) throw error;

        const responseRequestId = error.response?.headers?.['x-assistance-request-id'];
        const responseMessage = error.response?.data as { message?: unknown; request_id?: unknown } | undefined;
        const serverRequestId = typeof responseMessage?.request_id === 'string' ? responseMessage.request_id : null;
        const message = typeof responseMessage?.message === 'string' ? responseMessage.message : 'The assistance data could not be loaded.';

        throw new AssistanceRequestError(
            message,
            error.response?.status ?? null,
            typeof responseRequestId === 'string' ? responseRequestId : (serverRequestId ?? clientRequestId),
        );
    }
}

export function assistanceSummaryQueryOptions(filters: ResourceImpactFilterState, enabled = true) {
    const doi = normalizedDoi(filters);

    return queryOptions({
        queryKey: queryKeys.assistance.summary(doi, filters.datacenter_id),
        queryFn: ({ signal }) => get<AssistanceSummary>('/assistance/data/summary', filters, signal),
        enabled,
        retry: false,
        staleTime: 30_000,
    });
}

export function assistanceReviewQueryOptions(
    scope: 'all' | string,
    filters: ResourceImpactFilterState,
    page: number,
    perPage: number,
    enabled = true,
) {
    const doi = normalizedDoi(filters);
    const url = scope === 'all' ? '/assistance/data/all' : `/assistance/data/${encodeURIComponent(scope)}`;

    return queryOptions({
        queryKey: queryKeys.assistance.review(scope, doi, filters.datacenter_id, page, perPage),
        queryFn: ({ signal }) => get<PaginatedData<AssistanceResourceGroup>>(url, filters, signal, page, perPage),
        enabled,
        retry: false,
    });
}

export function useAssistanceSummary(filters: ResourceImpactFilterState, enabled = true) {
    return useQuery(assistanceSummaryQueryOptions(filters, enabled));
}

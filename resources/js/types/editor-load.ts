export interface EditorLoadContext {
    token: string;
    resourceId: number;
    serverProgress: number;
    slowThresholdMs: number;
}

export type EditorClientLoadStage = 'loader' | 'client_resource_types' | 'client_vocabularies' | 'client_ready';

export interface EditorLoadStatus {
    status: 'pending' | 'loading' | 'server_ready' | 'failed' | 'complete';
    stage: string;
    progress: number;
    error: string | null;
}

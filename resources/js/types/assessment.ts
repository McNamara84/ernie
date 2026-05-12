export type AssessmentScope = 'resource' | 'igsn';

export interface AssessmentEntry {
    id: number;
    doi: string | null;
    mainTitle: string;
    score: number;
    assessedAt: string | null;
}

export interface AssessmentSummary {
    total: number;
    assessed: number;
    failed: number;
    skipped: number;
    unassessed: number;
}

export interface AssessmentJobStatus {
    status: 'queued' | 'running' | 'completed' | 'failed' | 'unknown';
    progress: string;
    error?: string;
    totalResources?: number;
    processedResources?: number;
    assessedResources?: number;
    failedResources?: number;
    skippedResources?: number;
}

export interface AssessmentPageProps {
    fujiConfigured: boolean;
    fujiHealthy: boolean;
    fujiStatusMessage: string | null;
    fujiStatusCode: number | null;
    resourcesNeedingAttention: AssessmentEntry[];
    igsnsNeedingAttention: AssessmentEntry[];
    resourceAssessmentSummary: AssessmentSummary;
    igsnAssessmentSummary: AssessmentSummary;
}
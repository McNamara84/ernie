export type AssessmentScope = 'resource' | 'igsn';

export type FairDimension = 'F' | 'A' | 'I' | 'R';

export type FairImprovementSeverity = 'low' | 'medium' | 'high' | 'very-high';

export type FairImprovementSuggestionActor = 'curator' | 'administrator';

export interface FairImprovementSuggestion {
    key: string;
    actor: FairImprovementSuggestionActor;
    text: string;
}

export type FairImprovementOpportunity =
    | {
          status: 'available';
          dimension: FairDimension;
          dimensionLabel: 'Findability' | 'Accessibility' | 'Interoperability' | 'Reusability';
          missingPoints: number;
          totalPoints: number;
          potentialFairGain: number;
          severity: FairImprovementSeverity;
          requiresReassessment: boolean;
          guidanceMessage?: string;
          suggestions: FairImprovementSuggestion[];
          scopeNote?: string;
      }
    | {
          status: 'complete';
          message: 'No FAIR improvement gap was found.';
      }
    | {
          status: 'unavailable';
          reason: 'invalid-payload' | 'invalid-scope';
          message: string;
      };

export interface AssessmentEntry {
    id: number;
    doi: string | null;
    mainTitle: string;
    score: number;
    assessedAt: string | null;
    improvementOpportunity: FairImprovementOpportunity;
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

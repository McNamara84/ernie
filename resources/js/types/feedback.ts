export type UserFeedbackCategory = 'problem' | 'idea' | 'praise' | 'other';

interface FeedbackDiagnosticBase {
    occurred_at: string;
}

export interface FeedbackNavigationDiagnostic extends FeedbackDiagnosticBase {
    type: 'navigation';
    path: string;
}

export interface FeedbackHttpErrorDiagnostic extends FeedbackDiagnosticBase {
    type: 'http_error';
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' | 'HEAD' | 'OPTIONS';
    path: string;
    status?: number;
    message?: string;
}

export interface FeedbackJavascriptErrorDiagnostic extends FeedbackDiagnosticBase {
    type: 'javascript_error';
    message: string;
}

export type FeedbackDiagnosticEvent = FeedbackNavigationDiagnostic | FeedbackHttpErrorDiagnostic | FeedbackJavascriptErrorDiagnostic;

export interface FeedbackEnvironment {
    appearance: 'light' | 'dark' | 'system';
    resolved_theme: 'light' | 'dark';
    viewport_width: number;
    viewport_height: number;
    device_pixel_ratio: number;
    locale: string;
    timezone: string;
}

export interface FeedbackTechnicalSnapshot {
    page: {
        path: string;
        title: string;
    };
    environment: FeedbackEnvironment;
    diagnostics: FeedbackDiagnosticEvent[];
}

export interface UserFeedbackRequest extends FeedbackTechnicalSnapshot {
    category: UserFeedbackCategory;
    message: string;
}

export interface UserFeedbackResponse {
    message: string;
    feedback_id: string;
}

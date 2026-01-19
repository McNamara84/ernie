import { AlertTriangle, RefreshCw } from 'lucide-react';
import { Component, type ErrorInfo, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';

interface ErrorBoundaryProps {
    children: ReactNode;
    fallback?: ReactNode;
    /** Called when an error is caught. Useful for error reporting. */
    onError?: (error: Error, errorInfo: ErrorInfo) => void;
}

interface ErrorBoundaryState {
    hasError: boolean;
    error: Error | null;
}

/**
 * React Error Boundary component that catches JavaScript errors anywhere in the child
 * component tree and displays a fallback UI instead of crashing the whole app.
 *
 * @example
 * ```tsx
 * <ErrorBoundary>
 *   <MyComponent />
 * </ErrorBoundary>
 *
 * // With custom fallback
 * <ErrorBoundary fallback={<CustomErrorMessage />}>
 *   <MyComponent />
 * </ErrorBoundary>
 *
 * // With error reporting
 * <ErrorBoundary onError={(error, info) => logToService(error, info)}>
 *   <MyComponent />
 * </ErrorBoundary>
 * ```
 */
export class ErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
    constructor(props: ErrorBoundaryProps) {
        super(props);
        this.state = { hasError: false, error: null };
    }

    static getDerivedStateFromError(error: Error): ErrorBoundaryState {
        return { hasError: true, error };
    }

    componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
        // Log error to console in development
        console.error('ErrorBoundary caught an error:', error, errorInfo);

        // Call optional error handler (e.g., for error reporting service)
        this.props.onError?.(error, errorInfo);
    }

    handleReset = (): void => {
        this.setState({ hasError: false, error: null });
    };

    handleReload = (): void => {
        window.location.reload();
    };

    render(): ReactNode {
        if (this.state.hasError) {
            // Allow custom fallback UI
            if (this.props.fallback) {
                return this.props.fallback;
            }

            // Default error UI
            return (
                <div className="flex min-h-[400px] flex-col items-center justify-center gap-4 p-8">
                    <div className="rounded-full bg-destructive/10 p-4">
                        <AlertTriangle className="h-12 w-12 text-destructive" />
                    </div>
                    <h2 className="text-xl font-semibold text-foreground">Something went wrong</h2>
                    <p className="max-w-md text-center text-muted-foreground">An unexpected error occurred. Please try again or reload the page.</p>
                    <div className="flex gap-2">
                        <Button onClick={this.handleReset} variant="outline">
                            <RefreshCw className="mr-2 h-4 w-4" />
                            Try again
                        </Button>
                        <Button onClick={this.handleReload}>Reload page</Button>
                    </div>
                    {process.env.NODE_ENV === 'development' && this.state.error && (
                        <details className="mt-4 w-full max-w-2xl">
                            <summary className="cursor-pointer text-sm text-muted-foreground hover:text-foreground">
                                Error details (development only)
                            </summary>
                            <pre className="mt-2 overflow-auto rounded-lg bg-muted p-4 text-xs">
                                <code>{this.state.error.message}</code>
                                {this.state.error.stack && (
                                    <>
                                        {'\n\n'}
                                        <code className="text-muted-foreground">{this.state.error.stack}</code>
                                    </>
                                )}
                            </pre>
                        </details>
                    )}
                </div>
            );
        }

        return this.props.children;
    }
}

/**
 * A smaller, inline error boundary for wrapping individual sections.
 * Shows a minimal error message without taking up too much space.
 */
interface SectionErrorBoundaryProps {
    children: ReactNode;
    sectionName?: string;
}

interface SectionErrorBoundaryState {
    hasError: boolean;
}

export class SectionErrorBoundary extends Component<SectionErrorBoundaryProps, SectionErrorBoundaryState> {
    constructor(props: SectionErrorBoundaryProps) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError(): SectionErrorBoundaryState {
        return { hasError: true };
    }

    componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
        console.error(`Error in section "${this.props.sectionName || 'unknown'}":`, error, errorInfo);
    }

    handleRetry = (): void => {
        this.setState({ hasError: false });
    };

    render(): ReactNode {
        if (this.state.hasError) {
            return (
                <div className="flex items-center gap-2 rounded-md border border-destructive/20 bg-destructive/5 p-3">
                    <AlertTriangle className="h-4 w-4 shrink-0 text-destructive" />
                    <span className="text-sm text-destructive">Failed to load {this.props.sectionName || 'this section'}.</span>
                    <Button variant="ghost" size="sm" onClick={this.handleRetry} className="ml-auto h-7 text-xs">
                        Retry
                    </Button>
                </div>
            );
        }

        return this.props.children;
    }
}

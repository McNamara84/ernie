import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';

describe('AlertDialog', () => {
    it('renders the trigger button', () => {
        render(
            <AlertDialog>
                <AlertDialogTrigger>Open Dialog</AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogTitle>Title</AlertDialogTitle>
                    <AlertDialogDescription>Description</AlertDialogDescription>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.getByText('Open Dialog')).toBeInTheDocument();
    });

    it('does not show content when closed', () => {
        render(
            <AlertDialog>
                <AlertDialogTrigger>Open</AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogTitle>Hidden Title</AlertDialogTitle>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.queryByText('Hidden Title')).not.toBeInTheDocument();
    });

    it('shows content when open', () => {
        render(
            <AlertDialog open={true}>
                <AlertDialogTrigger>Open</AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogTitle>Visible Title</AlertDialogTitle>
                    <AlertDialogDescription>Visible Description</AlertDialogDescription>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.getByText('Visible Title')).toBeInTheDocument();
        expect(screen.getByText('Visible Description')).toBeInTheDocument();
    });

    it('renders header and footer sections', () => {
        render(
            <AlertDialog open={true}>
                <AlertDialogContent>
                    <AlertDialogHeader data-testid="header">
                        <AlertDialogTitle>Title</AlertDialogTitle>
                    </AlertDialogHeader>
                    <AlertDialogFooter data-testid="footer">
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction>Continue</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.getByTestId('header')).toBeInTheDocument();
        expect(screen.getByTestId('footer')).toBeInTheDocument();
    });

    it('renders action and cancel buttons', () => {
        render(
            <AlertDialog open={true}>
                <AlertDialogContent>
                    <AlertDialogTitle>Confirmation Dialog</AlertDialogTitle>
                    <AlertDialogDescription>Please confirm your action.</AlertDialogDescription>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction>Continue</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.getByText('Cancel')).toBeInTheDocument();
        expect(screen.getByText('Continue')).toBeInTheDocument();
    });

    it('applies custom className to content', () => {
        render(
            <AlertDialog open={true}>
                <AlertDialogContent className="custom-content" data-testid="content">
                    <AlertDialogTitle>Title</AlertDialogTitle>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.getByTestId('content')).toHaveClass('custom-content');
    });

    it('applies custom className to header', () => {
        render(
            <AlertDialog open={true}>
                <AlertDialogContent>
                    <AlertDialogHeader className="custom-header" data-testid="header">
                        <AlertDialogTitle>Title</AlertDialogTitle>
                    </AlertDialogHeader>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.getByTestId('header')).toHaveClass('custom-header');
    });

    it('applies custom className to footer', () => {
        render(
            <AlertDialog open={true}>
                <AlertDialogContent>
                    <AlertDialogTitle>Title</AlertDialogTitle>
                    <AlertDialogFooter className="custom-footer" data-testid="footer">
                        <AlertDialogAction>OK</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.getByTestId('footer')).toHaveClass('custom-footer');
    });

    it('renders a complete alert dialog', () => {
        render(
            <AlertDialog open={true}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Are you sure?</AlertDialogTitle>
                        <AlertDialogDescription>
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction>Delete</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        );

        expect(screen.getByRole('alertdialog')).toBeInTheDocument();
        expect(screen.getByText('Are you sure?')).toBeInTheDocument();
        expect(screen.getByText('This action cannot be undone.')).toBeInTheDocument();
        expect(screen.getByText('Cancel')).toBeInTheDocument();
        expect(screen.getByText('Delete')).toBeInTheDocument();
    });
});

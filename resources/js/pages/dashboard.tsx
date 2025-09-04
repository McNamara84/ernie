import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Head, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

export default function Dashboard() {
    const { auth } = usePage<SharedData>().props;
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [isDragging, setIsDragging] = useState(false);

    function handleDragOver(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        setIsDragging(true);
    }

    function handleDragLeave(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        const related = event.relatedTarget as Node | null;
        if (!related || !event.currentTarget.contains(related)) {
            setIsDragging(false);
        }
    }

    function handleDrop(event: React.DragEvent<HTMLDivElement>) {
        event.preventDefault();
        setIsDragging(false);
        const files = Array.from(event.dataTransfer.files);
        const xmlFiles = files.filter((file) => file.type === 'text/xml' || file.name.endsWith('.xml'));
        if (xmlFiles.length) {
            // TODO: handle uploaded XML files
        }
    }

    function handleFileSelect(event: React.ChangeEvent<HTMLInputElement>) {
        const files = event.target.files;
        if (files && files.length) {
            // TODO: handle uploaded XML files
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Hello {auth.user.name}!</CardTitle>
                            <CardDescription>
                                Nice to see you today! You still have x datasets to curate. Have fun, your
                                ERNIE!
                            </CardDescription>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>x datasets from y data centers of z institutions</CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>ERNIE version 0.1.0 MySQL 8.0 PHP 8.4</CardTitle>
                        </CardHeader>
                    </Card>
                </div>
                <Card className="flex flex-col items-center justify-center">
                    <CardHeader className="items-center text-center">
                        <CardTitle>Dropzone for XML files</CardTitle>
                        <CardDescription>
                            Here you can upload new XML files sent by ELMO for curation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex w-full justify-center">
                        <div
                            onDrop={handleDrop}
                            onDragOver={handleDragOver}
                            onDragLeave={handleDragLeave}
                            className={`flex w-full flex-col items-center justify-center rounded-md border-2 border-dashed p-12 text-center ${
                                isDragging ? 'bg-accent' : 'bg-muted'
                            }`}
                        >
                            <p className="mb-4 text-sm text-muted-foreground">Drag &amp; drop XML files here</p>
                            <input
                                ref={fileInputRef}
                                type="file"
                                accept=".xml"
                                className="hidden"
                                onChange={handleFileSelect}
                            />
                            <Button type="button" onClick={() => fileInputRef.current?.click()}>Upload</Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}


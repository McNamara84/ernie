import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Head, usePage, router } from '@inertiajs/react';
import { useRef, useState } from 'react';

export const handleXmlFiles = async (files: File[]): Promise<void> => {
    if (!files.length) return;
    const formData = new FormData();
    formData.append('file', files[0]);
    const response = await fetch('/dashboard/upload-xml', {
        method: 'POST',
        body: formData,
    });
    if (response.ok) {
        const data: { doi?: string } = await response.json();
        if (data.doi) {
            router.get('/curation', { doi: data.doi });
        }
    }
};

type DashboardProps = {
    onXmlFiles?: (files: File[]) => void | Promise<void>;
};

function filterXmlFiles(files: File[]): File[] {
    return files.filter((file) => file.type === 'text/xml' || file.name.endsWith('.xml'));
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard().url,
    },
];

export default function Dashboard({ onXmlFiles = handleXmlFiles }: DashboardProps = {}) {
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
        const xmlFiles = filterXmlFiles(files);
        if (xmlFiles.length) {
            void onXmlFiles(xmlFiles);
        }
    }

    function handleFileSelect(event: React.ChangeEvent<HTMLInputElement>) {
        const files = event.target.files;
        if (files && files.length) {
            const xmlFiles = filterXmlFiles(Array.from(files));
            if (xmlFiles.length) {
                void onXmlFiles(xmlFiles);
            }
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
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            Nice to see you today! You still have x datasets to curate. Have fun, your ERNIE!
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Statistics</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            x datasets from y data centers of z institutions
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Environment</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm text-muted-foreground">
                            <table className="w-full">
                                <tbody>
                                    <tr>
                                        <td className="py-1">ERNIE Version</td>
                                        <td className="py-1 text-right">
                                            <Badge className="w-14 bg-[#003da6] text-white">0.1.0</Badge>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td className="py-1">PHP Version</td>
                                        <td className="py-1 text-right">
                                            <Badge className="w-14 bg-[#777BB4] text-white">8.4.12</Badge>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td className="py-1">Laravel Version</td>
                                        <td className="py-1 text-right">
                                            <Badge className="w-14 bg-[#FF2D20] text-white">12.28.1</Badge>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
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


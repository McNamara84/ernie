import type { DragEndEvent } from '@dnd-kit/core';
import { closestCenter, DndContext, KeyboardSensor, PointerSensor, useSensor, useSensors } from '@dnd-kit/core';
import { arrayMove, SortableContext, sortableKeyboardCoordinates, useSortable, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Head, router, usePage } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { Copy, GripVertical, ImagePlus, LayoutTemplate, Pencil, Plus, Trash2, X } from 'lucide-react';
import { type ChangeEvent, useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';

import { AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle } from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { LoadingButton } from '@/components/ui/loading-button';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import type { LandingPageTemplateConfig, LeftColumnSection, RightColumnSection } from '@/types/landing-page';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Landing Pages', href: '/landing-pages' }];

/** Display labels for right column sections */
const RIGHT_SECTION_LABELS: Record<RightColumnSection, string> = {
    descriptions: 'Abstract & Descriptions',
    creators: 'Creators / Authors',
    contributors: 'Contributors',
    funders: 'Funding References',
    keywords: 'Keywords / Subjects',
    metadata_download: 'Metadata Download',
    location: 'Location / Map',
};

/** Display labels for left column sections */
const LEFT_SECTION_LABELS: Record<LeftColumnSection, string> = {
    files: 'Files & Downloads',
    contact: 'Contact Person',
    model_description: 'Model / Method Description',
    related_work: 'Related Work',
};

interface PageProps extends SharedData {
    templates: LandingPageTemplateConfig[];
    [key: string]: unknown;
}

// --- Sortable Section Item ---
function SortableSectionItem({ id, label }: { id: string; label: string }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({ id });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
        opacity: isDragging ? 0.5 : 1,
    };

    return (
        <div
            ref={setNodeRef}
            style={style}
            className="flex items-center gap-2 rounded-md border bg-background px-3 py-2 text-sm"
        >
            <Button
                variant="ghost"
                size="icon"
                type="button"
                aria-label={`Reorder ${label}`}
                className="size-6 cursor-grab touch-none text-muted-foreground hover:text-foreground"
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-4" />
            </Button>
            <span className="flex-1">{label}</span>
        </div>
    );
}

// --- Section Order Editor ---
function SectionOrderEditor({
    title,
    items,
    labels,
    onReorder,
}: {
    title: string;
    items: string[];
    labels: Record<string, string>;
    onReorder: (items: string[]) => void;
}) {
    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
    );

    const handleDragEnd = useCallback(
        (event: DragEndEvent) => {
            const { active, over } = event;
            if (!over || active.id === over.id) return;
            const oldIndex = items.indexOf(String(active.id));
            const newIndex = items.indexOf(String(over.id));
            if (oldIndex === -1 || newIndex === -1) return;
            onReorder(arrayMove(items, oldIndex, newIndex));
        },
        [items, onReorder],
    );

    return (
        <div className="space-y-2">
            <Label className="text-sm font-medium">{title}</Label>
            <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
                <SortableContext items={items} strategy={verticalListSortingStrategy}>
                    <div className="space-y-1.5">
                        {items.map((key) => (
                            <SortableSectionItem key={key} id={key} label={labels[key] ?? key} />
                        ))}
                    </div>
                </SortableContext>
            </DndContext>
        </div>
    );
}

export default function LandingPageTemplatesPage() {
    const { templates } = usePage<PageProps>().props;

    // Clone dialog
    const [cloneOpen, setCloneOpen] = useState(false);
    const [cloneName, setCloneName] = useState('');
    const [cloning, setCloning] = useState(false);

    // Edit dialog
    const [editOpen, setEditOpen] = useState(false);
    const [editTemplate, setEditTemplate] = useState<LandingPageTemplateConfig | null>(null);
    const [editName, setEditName] = useState('');
    const [editRightOrder, setEditRightOrder] = useState<RightColumnSection[]>([]);
    const [editLeftOrder, setEditLeftOrder] = useState<LeftColumnSection[]>([]);
    const [saving, setSaving] = useState(false);

    // Delete dialog
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteTemplate, setDeleteTemplate] = useState<LandingPageTemplateConfig | null>(null);
    const [deleting, setDeleting] = useState(false);

    // Logo upload
    const [uploadingLogo, setUploadingLogo] = useState<number | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const [logoTargetId, setLogoTargetId] = useState<number | null>(null);

    // --- Clone ---
    const handleClone = async () => {
        if (!cloneName.trim()) return;
        setCloning(true);
        try {
            await axios.post('/landing-pages', { name: cloneName.trim() });
            toast.success('Template cloned successfully');
            setCloneOpen(false);
            setCloneName('');
            router.reload({ only: ['templates'] });
        } catch (error) {
            if (isAxiosError(error) && error.response?.data?.errors?.name) {
                toast.error(error.response.data.errors.name[0]);
            } else {
                toast.error('Failed to clone template');
            }
        } finally {
            setCloning(false);
        }
    };

    // --- Edit ---
    const openEdit = (tmpl: LandingPageTemplateConfig) => {
        setEditTemplate(tmpl);
        setEditName(tmpl.name);
        setEditRightOrder([...tmpl.right_column_order]);
        setEditLeftOrder([...tmpl.left_column_order]);
        setEditOpen(true);
    };

    const handleSave = async () => {
        if (!editTemplate) return;
        setSaving(true);
        try {
            await axios.put(`/landing-pages/${editTemplate.id}`, {
                name: editName.trim(),
                right_column_order: editRightOrder,
                left_column_order: editLeftOrder,
            });
            toast.success('Template updated successfully');
            setEditOpen(false);
            router.reload({ only: ['templates'] });
        } catch (error) {
            if (isAxiosError(error) && error.response?.data?.errors) {
                const errors = error.response.data.errors;
                const msg = Object.values(errors).flat().join(', ');
                toast.error(msg);
            } else {
                toast.error('Failed to update template');
            }
        } finally {
            setSaving(false);
        }
    };

    // --- Delete ---
    const handleDelete = async () => {
        if (!deleteTemplate) return;
        setDeleting(true);
        try {
            await axios.delete(`/landing-pages/${deleteTemplate.id}`);
            toast.success('Template deleted successfully');
            setDeleteOpen(false);
            setDeleteTemplate(null);
            router.reload({ only: ['templates'] });
        } catch (error) {
            if (isAxiosError(error) && error.response?.data?.message) {
                toast.error(error.response.data.message);
            } else {
                toast.error('Failed to delete template');
            }
        } finally {
            setDeleting(false);
        }
    };

    // --- Logo Upload ---
    const triggerLogoUpload = (templateId: number) => {
        setLogoTargetId(templateId);
        fileInputRef.current?.click();
    };

    const handleLogoFileChange = async (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file || !logoTargetId) return;

        // Reset file input
        e.target.value = '';

        setUploadingLogo(logoTargetId);
        try {
            const formData = new FormData();
            formData.append('logo', file);
            await axios.post(`/landing-pages/${logoTargetId}/logo`, formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            toast.success('Logo uploaded successfully');
            router.reload({ only: ['templates'] });
        } catch (error) {
            if (isAxiosError(error) && error.response?.data?.errors?.logo) {
                toast.error(error.response.data.errors.logo[0]);
            } else {
                toast.error('Failed to upload logo');
            }
        } finally {
            setUploadingLogo(null);
            setLogoTargetId(null);
        }
    };

    const handleDeleteLogo = async (templateId: number) => {
        try {
            await axios.delete(`/landing-pages/${templateId}/logo`);
            toast.success('Logo removed');
            router.reload({ only: ['templates'] });
        } catch {
            toast.error('Failed to remove logo');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Landing Pages" />

            {/* Hidden file input for logo upload */}
            <input
                ref={fileInputRef}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                className="hidden"
                onChange={handleLogoFileChange}
            />

            <div className="mx-auto max-w-5xl space-y-6 p-6">
                {/* Page Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Landing Pages</h1>
                        <p className="text-muted-foreground">
                            Manage custom templates for resource landing pages. Clone the default template and customize section order and logo.
                        </p>
                    </div>
                    <Button onClick={() => { setCloneName(''); setCloneOpen(true); }}>
                        <Plus className="mr-2 size-4" />
                        New Template
                    </Button>
                </div>

                <Separator />

                {/* Template Cards */}
                <div className="grid gap-4 md:grid-cols-2">
                    {templates.map((tmpl) => (
                        <Card key={tmpl.id} className={tmpl.is_default ? 'border-primary/30' : ''}>
                            <CardHeader className="pb-3">
                                <div className="flex items-start justify-between">
                                    <div className="flex items-center gap-2">
                                        <LayoutTemplate className="size-5 text-muted-foreground" />
                                        <div>
                                            <CardTitle className="text-base">{tmpl.name}</CardTitle>
                                            <CardDescription className="text-xs">
                                                {tmpl.is_default ? 'Built-in default template' : `Created by ${tmpl.creator?.name ?? 'Unknown'}`}
                                            </CardDescription>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-1">
                                        {tmpl.is_default && (
                                            <Badge variant="secondary" className="text-xs">Default</Badge>
                                        )}
                                        {(tmpl.landing_pages_count ?? 0) > 0 && (
                                            <Badge variant="outline" className="text-xs">
                                                {tmpl.landing_pages_count} {tmpl.landing_pages_count === 1 ? 'page' : 'pages'}
                                            </Badge>
                                        )}
                                    </div>
                                </div>
                            </CardHeader>

                            <CardContent className="space-y-3">
                                {/* Logo Preview */}
                                {tmpl.logo_url && (
                                    <div className="flex items-center gap-3 rounded-md border bg-muted/30 p-2">
                                        <img
                                            src={tmpl.logo_url}
                                            alt={`${tmpl.name} logo`}
                                            className="h-10 max-w-40 object-contain"
                                        />
                                        <span className="flex-1 truncate text-xs text-muted-foreground">{tmpl.logo_filename}</span>
                                        {!tmpl.is_default && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-7"
                                                onClick={() => handleDeleteLogo(tmpl.id)}
                                                aria-label="Remove logo"
                                            >
                                                <X className="size-3.5" />
                                            </Button>
                                        )}
                                    </div>
                                )}

                                {/* Section Order Summary */}
                                <div className="grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                                    <div>
                                        <span className="font-medium text-foreground">Right Column:</span>
                                        <ol className="mt-0.5 list-inside list-decimal space-y-0.5">
                                            {tmpl.right_column_order.map((key) => (
                                                <li key={key}>{RIGHT_SECTION_LABELS[key] ?? key}</li>
                                            ))}
                                        </ol>
                                    </div>
                                    <div>
                                        <span className="font-medium text-foreground">Left Column:</span>
                                        <ol className="mt-0.5 list-inside list-decimal space-y-0.5">
                                            {tmpl.left_column_order.map((key) => (
                                                <li key={key}>{LEFT_SECTION_LABELS[key] ?? key}</li>
                                            ))}
                                        </ol>
                                    </div>
                                </div>

                                {/* Actions */}
                                {!tmpl.is_default && (
                                    <div className="flex items-center gap-2 pt-1">
                                        <Button variant="outline" size="sm" onClick={() => openEdit(tmpl)}>
                                            <Pencil className="mr-1.5 size-3.5" />
                                            Edit
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => triggerLogoUpload(tmpl.id)}
                                            disabled={uploadingLogo === tmpl.id}
                                        >
                                            <ImagePlus className="mr-1.5 size-3.5" />
                                            {tmpl.logo_url ? 'Replace Logo' : 'Upload Logo'}
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="ml-auto text-destructive hover:text-destructive"
                                            onClick={() => { setDeleteTemplate(tmpl); setDeleteOpen(true); }}
                                        >
                                            <Trash2 className="mr-1.5 size-3.5" />
                                            Delete
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    ))}
                </div>

                {templates.length === 0 && (
                    <div className="py-12 text-center text-muted-foreground">
                        <LayoutTemplate className="mx-auto mb-4 size-12 opacity-50" />
                        <p>No templates found. Create one to get started.</p>
                    </div>
                )}
            </div>

            {/* Clone Dialog */}
            <Dialog open={cloneOpen} onOpenChange={setCloneOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Copy className="size-5" />
                            Clone Default Template
                        </DialogTitle>
                        <DialogDescription>
                            Create a new template based on the default GFZ template. You can customize the section order and logo afterwards.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="clone-name">Template Name</Label>
                            <Input
                                id="clone-name"
                                placeholder="e.g. GFZ Geophysics Template"
                                value={cloneName}
                                onChange={(e) => setCloneName(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && handleClone()}
                                autoFocus
                            />
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setCloneOpen(false)}>Cancel</Button>
                        <LoadingButton loading={cloning} disabled={!cloneName.trim()} onClick={handleClone}>
                            Clone Template
                        </LoadingButton>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Edit Dialog */}
            <Dialog open={editOpen} onOpenChange={setEditOpen}>
                <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Pencil className="size-5" />
                            Edit Template
                        </DialogTitle>
                        <DialogDescription>
                            Customize the template name and drag sections to reorder them.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-6 py-2">
                        <div className="space-y-2">
                            <Label htmlFor="edit-name">Template Name</Label>
                            <Input
                                id="edit-name"
                                value={editName}
                                onChange={(e) => setEditName(e.target.value)}
                            />
                        </div>

                        <Separator />

                        <div className="grid gap-6 md:grid-cols-2">
                            <SectionOrderEditor
                                title="Right Column (main content)"
                                items={editRightOrder}
                                labels={RIGHT_SECTION_LABELS}
                                onReorder={(items) => setEditRightOrder(items as RightColumnSection[])}
                            />
                            <SectionOrderEditor
                                title="Left Column (sidebar)"
                                items={editLeftOrder}
                                labels={LEFT_SECTION_LABELS}
                                onReorder={(items) => setEditLeftOrder(items as LeftColumnSection[])}
                            />
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setEditOpen(false)}>Cancel</Button>
                        <LoadingButton loading={saving} disabled={!editName.trim()} onClick={handleSave}>
                            Save Changes
                        </LoadingButton>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation */}
            <AlertDialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Template</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete &ldquo;{deleteTemplate?.name}&rdquo;?
                            {(deleteTemplate?.landing_pages_count ?? 0) > 0 && (
                                <span className="mt-2 block font-medium text-destructive">
                                    This template is currently used by {deleteTemplate?.landing_pages_count} landing page(s) and cannot be deleted.
                                    Please reassign those pages to a different template first.
                                </span>
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                            onClick={handleDelete}
                            disabled={deleting || (deleteTemplate?.landing_pages_count ?? 0) > 0}
                        >
                            {deleting ? 'Deleting...' : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}

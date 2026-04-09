import { Head } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import PublicLayout from '@/layouts/public-layout';

interface MetadataFormat {
    schema: string;
    namespace: string;
}

interface Props {
    baseUrl: string;
    adminEmail: string;
    metadataFormats: Record<string, MetadataFormat>;
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    return (
        <Button variant="ghost" size="icon" className="h-6 w-6 shrink-0" onClick={handleCopy} title="Copy to clipboard">
            {copied ? <span className="text-xs text-green-600">✓</span> : <Copy className="h-3.5 w-3.5" />}
        </Button>
    );
}

function ExampleUrl({ url }: { url: string }) {
    return (
        <div className="flex items-center gap-2 rounded-md bg-muted/50 px-3 py-2">
            <code className="flex-1 break-all text-sm">{url}</code>
            <CopyButton text={url} />
        </div>
    );
}

export default function OaiPmhDocs({ baseUrl, adminEmail, metadataFormats }: Props) {
    return (
        <PublicLayout>
            <Head title="OAI-PMH Documentation" />

            <div className="space-y-8">
                {/* Header */}
                <div>
                    <h1 className="mb-2 text-3xl font-bold tracking-tight">OAI-PMH Harvesting Endpoint</h1>
                    <p className="text-lg text-muted-foreground">
                        This repository provides a fully compliant{' '}
                        <a
                            href="http://www.openarchives.org/OAI/openarchivesprotocol.html"
                            target="_blank"
                            rel="noreferrer"
                            className="text-primary underline"
                        >
                            OAI-PMH 2.0
                        </a>{' '}
                        endpoint for harvesting metadata of published research datasets and physical samples (IGSN).
                    </p>
                </div>

                {/* Base URL */}
                <Card>
                    <CardHeader>
                        <CardTitle>Base URL</CardTitle>
                        <CardDescription>Use this URL as the base for all OAI-PMH requests</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ExampleUrl url={baseUrl} />
                        <p className="mt-2 text-sm text-muted-foreground">
                            Contact: <a href={`mailto:${adminEmail}`} className="text-primary underline">{adminEmail}</a>
                        </p>
                    </CardContent>
                </Card>

                {/* Supported Verbs */}
                <Card>
                    <CardHeader>
                        <CardTitle>Supported Verbs</CardTitle>
                        <CardDescription>The endpoint supports all 6 standard OAI-PMH verbs</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Verb</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead>Required Parameters</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow>
                                    <TableCell><Badge variant="secondary">Identify</Badge></TableCell>
                                    <TableCell>Information about the repository</TableCell>
                                    <TableCell className="text-muted-foreground">None</TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell><Badge variant="secondary">ListMetadataFormats</Badge></TableCell>
                                    <TableCell>Supported metadata formats (optionally per record)</TableCell>
                                    <TableCell className="text-muted-foreground">None (optional: <code>identifier</code>)</TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell><Badge variant="secondary">ListSets</Badge></TableCell>
                                    <TableCell>Available set structure for selective harvesting</TableCell>
                                    <TableCell className="text-muted-foreground">None</TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell><Badge variant="secondary">ListIdentifiers</Badge></TableCell>
                                    <TableCell>Record headers only (identifier, datestamp, sets)</TableCell>
                                    <TableCell><code>metadataPrefix</code></TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell><Badge variant="secondary">ListRecords</Badge></TableCell>
                                    <TableCell>Full metadata records with headers</TableCell>
                                    <TableCell><code>metadataPrefix</code></TableCell>
                                </TableRow>
                                <TableRow>
                                    <TableCell><Badge variant="secondary">GetRecord</Badge></TableCell>
                                    <TableCell>A single record by its unique identifier</TableCell>
                                    <TableCell><code>identifier</code>, <code>metadataPrefix</code></TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Metadata Formats */}
                <Card>
                    <CardHeader>
                        <CardTitle>Metadata Formats</CardTitle>
                        <CardDescription>Two metadata formats are available for harvesting</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Prefix</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead>Schema</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {Object.entries(metadataFormats).map(([prefix, format]) => (
                                    <TableRow key={prefix}>
                                        <TableCell><code>{prefix}</code></TableCell>
                                        <TableCell>{prefix === 'oai_dc' ? 'Dublin Core (mandatory)' : 'DataCite Kernel 4.7'}</TableCell>
                                        <TableCell>
                                            <a href={format.schema} target="_blank" rel="noreferrer" className="text-primary underline break-all text-sm">
                                                {format.schema}
                                            </a>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                {/* Sets */}
                <Card>
                    <CardHeader>
                        <CardTitle>Sets (Selective Harvesting)</CardTitle>
                        <CardDescription>Records are organized into sets for selective harvesting</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div>
                            <h4 className="mb-2 font-medium">Resource Type Sets</h4>
                            <p className="mb-2 text-sm text-muted-foreground">
                                Filter by DataCite resource type using the <code>set</code> parameter.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {['Dataset', 'Text', 'Image', 'PhysicalObject', 'Software', 'Collection'].map((type) => (
                                    <Badge key={type} variant="outline">resourcetype:{type}</Badge>
                                ))}
                            </div>
                        </div>
                        <div>
                            <h4 className="mb-2 font-medium">Publication Year Sets</h4>
                            <p className="mb-2 text-sm text-muted-foreground">
                                Filter by publication year.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                <Badge variant="outline">year:2023</Badge>
                                <Badge variant="outline">year:2024</Badge>
                                <Badge variant="outline">year:2025</Badge>
                                <span className="text-sm text-muted-foreground">...</span>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Example Requests */}
                <Card>
                    <CardHeader>
                        <CardTitle>Example Requests</CardTitle>
                        <CardDescription>Copyable example URLs for each verb</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Tabs defaultValue="identify" className="w-full">
                            <TabsList className="mb-4 flex flex-wrap h-auto gap-1">
                                <TabsTrigger value="identify">Identify</TabsTrigger>
                                <TabsTrigger value="formats">ListMetadataFormats</TabsTrigger>
                                <TabsTrigger value="sets">ListSets</TabsTrigger>
                                <TabsTrigger value="identifiers">ListIdentifiers</TabsTrigger>
                                <TabsTrigger value="records">ListRecords</TabsTrigger>
                                <TabsTrigger value="getrecord">GetRecord</TabsTrigger>
                            </TabsList>

                            <TabsContent value="identify" className="space-y-2">
                                <p className="text-sm">Retrieve information about this repository:</p>
                                <ExampleUrl url={`${baseUrl}?verb=Identify`} />
                            </TabsContent>

                            <TabsContent value="formats" className="space-y-2">
                                <p className="text-sm">List all supported metadata formats:</p>
                                <ExampleUrl url={`${baseUrl}?verb=ListMetadataFormats`} />
                            </TabsContent>

                            <TabsContent value="sets" className="space-y-2">
                                <p className="text-sm">List all available sets:</p>
                                <ExampleUrl url={`${baseUrl}?verb=ListSets`} />
                            </TabsContent>

                            <TabsContent value="identifiers" className="space-y-4">
                                <div className="space-y-2">
                                    <p className="text-sm">List all record headers in Dublin Core format:</p>
                                    <ExampleUrl url={`${baseUrl}?verb=ListIdentifiers&metadataPrefix=oai_dc`} />
                                </div>
                                <div className="space-y-2">
                                    <p className="text-sm">Filter by set and date range:</p>
                                    <ExampleUrl url={`${baseUrl}?verb=ListIdentifiers&metadataPrefix=oai_dc&set=resourcetype:Dataset&from=2024-01-01&until=2024-12-31`} />
                                </div>
                            </TabsContent>

                            <TabsContent value="records" className="space-y-4">
                                <div className="space-y-2">
                                    <p className="text-sm">Harvest all records in Dublin Core format:</p>
                                    <ExampleUrl url={`${baseUrl}?verb=ListRecords&metadataPrefix=oai_dc`} />
                                </div>
                                <div className="space-y-2">
                                    <p className="text-sm">Harvest DataCite XML for datasets only:</p>
                                    <ExampleUrl url={`${baseUrl}?verb=ListRecords&metadataPrefix=oai_datacite&set=resourcetype:Dataset`} />
                                </div>
                            </TabsContent>

                            <TabsContent value="getrecord" className="space-y-2">
                                <p className="text-sm">Retrieve a single record by its OAI identifier:</p>
                                <ExampleUrl url={`${baseUrl}?verb=GetRecord&identifier=oai:ernie.gfz.de:10.5880/GFZ.1.2.2024.001&metadataPrefix=oai_dc`} />
                            </TabsContent>
                        </Tabs>
                    </CardContent>
                </Card>

                {/* Resumption Tokens */}
                <Card>
                    <CardHeader>
                        <CardTitle>Resumption Tokens (Pagination)</CardTitle>
                        <CardDescription>How large result sets are paginated</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <p>
                            When a <code>ListRecords</code> or <code>ListIdentifiers</code> response contains more than <strong>100 records</strong>,
                            the response includes a <code>&lt;resumptionToken&gt;</code> element.
                        </p>
                        <p>To retrieve the next page, send a new request with the token as the exclusive parameter:</p>
                        <ExampleUrl url={`${baseUrl}?verb=ListRecords&resumptionToken=<token_value>`} />
                        <p className="text-muted-foreground">
                            Tokens expire after <strong>24 hours</strong>. An empty <code>&lt;resumptionToken&gt;</code> element
                            (with no text content) signals the end of the result set.
                        </p>
                    </CardContent>
                </Card>

                {/* Selective Harvesting */}
                <Card>
                    <CardHeader>
                        <CardTitle>Selective Harvesting</CardTitle>
                        <CardDescription>Filtering records by date and set</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4 text-sm">
                        <div>
                            <h4 className="mb-1 font-medium">Date-based Harvesting</h4>
                            <p className="text-muted-foreground">
                                Use <code>from</code> and <code>until</code> parameters to harvest records modified within a date range.
                                Both parameters accept dates in <code>YYYY-MM-DD</code> or <code>YYYY-MM-DDThh:mm:ssZ</code> format.
                            </p>
                        </div>
                        <div>
                            <h4 className="mb-1 font-medium">Set-based Harvesting</h4>
                            <p className="text-muted-foreground">
                                Use the <code>set</code> parameter to harvest only records belonging to a specific set.
                                For example, <code>set=resourcetype:Dataset</code> harvests only dataset records.
                            </p>
                        </div>
                        <div>
                            <h4 className="mb-1 font-medium">Combining Filters</h4>
                            <p className="text-muted-foreground">
                                Date and set filters can be combined for targeted harvesting:
                            </p>
                            <ExampleUrl url={`${baseUrl}?verb=ListRecords&metadataPrefix=oai_datacite&set=resourcetype:Dataset&from=2024-01-01`} />
                        </div>
                    </CardContent>
                </Card>

                {/* Deleted Records */}
                <Card>
                    <CardHeader>
                        <CardTitle>Deleted Records</CardTitle>
                        <CardDescription>How this repository handles deleted or depublished records</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <p>
                            This repository uses <strong>persistent</strong> deleted record tracking.
                            When a record is deleted or depublished, it remains permanently in the OAI-PMH responses
                            with a <code>status=&quot;deleted&quot;</code> attribute in the header.
                        </p>
                        <p>
                            Deleted records appear in <code>ListRecords</code> and <code>ListIdentifiers</code> responses
                            and can be individually retrieved via <code>GetRecord</code>. They do not include metadata,
                            only the header with identifier, datestamp, and set memberships.
                        </p>
                    </CardContent>
                </Card>

                {/* Best Practices */}
                <Card>
                    <CardHeader>
                        <CardTitle>Best Practices for Harvesters</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <ul className="list-inside list-disc space-y-2 text-muted-foreground">
                            <li>Use incremental harvesting with <code>from</code> dates to avoid re-harvesting unchanged records.</li>
                            <li>Prefer <code>oai_datacite</code> format for richer metadata (DataCite Kernel 4.7).</li>
                            <li>Implement retry logic for transient errors (HTTP 503 with Retry-After header).</li>
                            <li>Respect the <code>resumptionToken</code> expiration; do not cache tokens beyond 24 hours.</li>
                            <li>Process deleted records to remove or flag previously harvested entries.</li>
                        </ul>
                    </CardContent>
                </Card>

                {/* OAI Identifier Format */}
                <Card>
                    <CardHeader>
                        <CardTitle>OAI Identifier Format</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p>Record identifiers follow the format:</p>
                        <div className="rounded-md bg-muted/50 px-3 py-2">
                            <code>oai:ernie.gfz.de:&#123;DOI&#125;</code>
                        </div>
                        <p className="text-muted-foreground">
                            Example: <code>oai:ernie.gfz.de:10.5880/GFZ.1.2.2024.001</code>
                        </p>
                    </CardContent>
                </Card>
            </div>
        </PublicLayout>
    );
}

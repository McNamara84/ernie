import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { getSelectAllState } from '@/lib/select-all';

export interface ContributorRoleRow {
    id: number;
    name: string;
    slug: string;
    category: 'person' | 'institution' | 'both';
    active: boolean;
    elmo_active: boolean;
}

interface ContributorRolesCardProps {
    title: string;
    description: string;
    roles: ContributorRoleRow[];
    dataKey: string;
    onRoleChange: (index: number, field: 'active' | 'elmo_active' | 'category', value: boolean | string) => void;
    onSetAll: (roles: ContributorRoleRow[]) => void;
}

export function ContributorRolesCard({ title, description, roles, dataKey, onRoleChange, onSetAll }: ContributorRolesCardProps) {
    const ernieState = getSelectAllState(roles.map((r) => r.active));
    const elmoState = getSelectAllState(roles.map((r) => r.elmo_active));

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Slug</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead className="text-center">
                                    ERNIE
                                    <br />
                                    active
                                    <div className="mt-1">
                                        <Checkbox
                                            checked={ernieState.allChecked}
                                            indeterminate={ernieState.indeterminate}
                                            onCheckedChange={(checked) => {
                                                onSetAll(roles.map((r) => ({ ...r, active: checked === true })));
                                            }}
                                            aria-label={`Select all ERNIE active for ${title}`}
                                        />
                                    </div>
                                </TableHead>
                                <TableHead className="text-center">
                                    ELMO
                                    <br />
                                    active
                                    <div className="mt-1">
                                        <Checkbox
                                            checked={elmoState.allChecked}
                                            indeterminate={elmoState.indeterminate}
                                            onCheckedChange={(checked) => {
                                                onSetAll(roles.map((r) => ({ ...r, elmo_active: checked === true })));
                                            }}
                                            aria-label={`Select all ELMO active for ${title}`}
                                        />
                                    </div>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {roles.map((role, index) => (
                                <TableRow key={role.id}>
                                    <TableCell>{role.id}</TableCell>
                                    <TableCell>{role.name}</TableCell>
                                    <TableCell>{role.slug}</TableCell>
                                    <TableCell>
                                        <Select
                                            value={role.category}
                                            onValueChange={(value) => onRoleChange(index, 'category', value)}
                                        >
                                            <SelectTrigger className="w-[130px]">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="person">Person</SelectItem>
                                                <SelectItem value="institution">Institution</SelectItem>
                                                <SelectItem value="both">Both</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </TableCell>
                                    <TableCell className="text-center">
                                        <Label htmlFor={`${dataKey}-active-${role.id}`} className="sr-only">
                                            ERNIE active
                                        </Label>
                                        <Checkbox
                                            id={`${dataKey}-active-${role.id}`}
                                            checked={role.active}
                                            onCheckedChange={(checked) => onRoleChange(index, 'active', checked === true)}
                                            aria-label="ERNIE active"
                                        />
                                    </TableCell>
                                    <TableCell className="text-center">
                                        <Label htmlFor={`${dataKey}-elmo-active-${role.id}`} className="sr-only">
                                            ELMO active
                                        </Label>
                                        <Checkbox
                                            id={`${dataKey}-elmo-active-${role.id}`}
                                            checked={role.elmo_active}
                                            onCheckedChange={(checked) => onRoleChange(index, 'elmo_active', checked === true)}
                                            aria-label="ELMO active"
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </CardContent>
        </Card>
    );
}

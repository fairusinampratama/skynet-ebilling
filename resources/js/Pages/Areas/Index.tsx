import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from "@/Components/ui/dropdown-menu";
import { Plus, Search, MoreHorizontal, Edit, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { Badge } from '@/Components/ui/badge';

interface Area {
    id: number;
    name: string;
    code: string;
    customers_count?: number;
}

interface Props {
    areas: {
        data: Area[];
        links: any[];
        from: number;
        to: number;
        total: number;
        last_page: number;
        current_page: number;
    };
    filters: {
        search: string;
    };
}

export default function Index({ areas, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [areaToDelete, setAreaToDelete] = useState<Area | null>(null);

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('areas.index'), { search }, { preserveState: true });
    };

    const confirmDelete = (area: Area) => {
        setAreaToDelete(area);
        setConfirmOpen(true);
    };

    const handleDelete = () => {
        if (areaToDelete) {
            router.delete(route('areas.destroy', areaToDelete.id), {
                onFinish: () => setConfirmOpen(false),
            });
        }
    };

    return (
        <AuthenticatedLayout
            breadcrumbs={[{ label: 'Areas', href: route('areas.index') }]}
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Areas
                    </h2>
                    <Button asChild>
                        <Link href={route('areas.create')}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Area
                        </Link>
                    </Button>
                </div>
            }
        >
            <Head title="Areas" />

            <div className="py-8">
                <div className="space-y-4">
                    {/* Filters */}
                    <div className="flex items-center justify-between bg-card p-4 rounded-lg border shadow-sm">
                        <form onSubmit={handleSearch} className="flex w-full max-w-sm items-center space-x-2">
                            <div className="relative flex-1">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search areas..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    className="pl-8"
                                />
                            </div>
                        </form>
                    </div>

                    {/* Table */}
                    <div className="rounded-md border bg-card shadow-sm">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Code</TableHead>
                                    <TableHead>Customers</TableHead>
                                    <TableHead className="w-[80px]"></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {areas.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell colSpan={4} className="h-24 text-center text-muted-foreground">
                                            No areas found.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    areas.data.map((area) => (
                                        <TableRow key={area.id}>
                                            <TableCell className="font-medium">{area.name}</TableCell>
                                            <TableCell className="font-mono text-xs">{area.code}</TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">
                                                    {area.customers_count || 0} Customers
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" className="h-8 w-8 p-0">
                                                            <span className="sr-only">Open menu</span>
                                                            <MoreHorizontal className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem onClick={() => router.visit(route('areas.edit', area.id))}>
                                                            <Edit className="mr-2 h-4 w-4" />
                                                            Edit
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            onClick={() => confirmDelete(area)}
                                                            className="text-destructive focus:text-destructive"
                                                        >
                                                            <Trash2 className="mr-2 h-4 w-4" />
                                                            Delete
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Pagination */}
                    <div className="flex items-center justify-end space-x-2 py-4">
                        <div className="text-sm text-muted-foreground">
                            Page {areas.current_page} of {areas.last_page}
                        </div>
                        <div className="space-x-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.visit(areas.links[0].url || '#')} // Previous
                                disabled={!areas.links[0].url}
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => router.visit(areas.links[areas.links.length - 1].url || '#')} // Next
                                disabled={!areas.links[areas.links.length - 1].url}
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Delete Area?"
                description={`Are you sure you want to delete ${areaToDelete?.name}? This action cannot be undone.`}
                confirmText="Delete"
                variant="destructive"
                onConfirm={handleDelete}
            />
        </AuthenticatedLayout>
    );
}

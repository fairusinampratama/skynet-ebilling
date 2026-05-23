import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { MoneyText } from '@/Components/Format';
import { ResourcePageHeader } from '@/Components/ResourcePageHeader';
import { DeleteAction, EditAction } from '@/Components/TableActions';
import { Archive, CheckCircle2, Plus, Server } from 'lucide-react';

interface Package {
    id: number;
    name: string;
    price: number;
    mikrotik_profile?: string;
    rate_limit?: string;
    router?: {
        id: number;
        name: string;
    } | null;
    customers_count: number;
    is_assignable: boolean;
    archive_reason?: string | null;
    profile_exists: boolean;
}

interface Router {
    id: number;
    name: string;
}

interface Props {
    packages: PaginatedData<Package>;
    routers: Router[];
    filters?: {
        search?: string;
        limit?: number;
        sort?: string;
        direction?: 'asc' | 'desc';
        router_id?: string | null;
        view?: 'active' | 'archive';
    };
}

export default function Index({ packages, routers, filters = {} }: Props) {
    const [packageToDelete, setPackageToDelete] = useState<Package | null>(null);
    const isArchive = filters.view === 'archive';

    const filterConfigs: FilterConfig[] = [
        {
            key: 'view',
            placeholder: 'View',
            options: [
                { label: 'Active Catalog', value: 'active', icon: CheckCircle2 },
                { label: 'Archive', value: 'archive', icon: Archive },
            ],
        },
        {
            key: 'router_id',
            placeholder: 'Router',
            options: routers.map((item) => ({
                label: item.name,
                value: String(item.id),
                icon: Server,
            })),
        },
    ];

    const statusBadge = (pkg: Package) => {
        if (pkg.is_assignable) {
            return (
                <Badge variant="outline" className="border-emerald-500/20 bg-emerald-500/10 text-emerald-700">
                    Assignable
                </Badge>
            );
        }

        return (
            <Badge variant="outline" className="border-amber-500/20 bg-amber-500/10 text-amber-700">
                {pkg.archive_reason || 'Archived'}
            </Badge>
        );
    };

    const columns: Column<Package>[] = [
        {
            header: 'Package Name',
            accessorKey: 'name',
            sortable: true,
            cell: (pkg) => <span className="font-medium">{pkg.name}</span>,
        },
        {
            header: 'Router',
            accessorKey: 'router',
            cell: (pkg) => <span className="text-sm text-muted-foreground">{pkg.router?.name || '-'}</span>,
        },
        {
            header: 'Tech Profile',
            accessorKey: 'mikrotik_profile',
            sortable: true,
            cell: (pkg) => (
                <div className="flex flex-col gap-1">
                    <span className="font-mono text-xs text-muted-foreground">{pkg.mikrotik_profile || '-'}</span>
                    {!pkg.profile_exists && (
                        <span className="text-xs text-amber-600">Not synced on router</span>
                    )}
                </div>
            ),
        },
        {
            header: 'Rate Limit',
            accessorKey: 'rate_limit',
            cell: (pkg) => <span className="font-mono text-xs text-muted-foreground">{pkg.rate_limit || '-'}</span>,
        },
        {
            header: 'Price',
            accessorKey: 'price',
            sortable: true,
            cell: (pkg) => <MoneyText amount={pkg.price} className="font-mono font-medium text-emerald-600" />,
        },
        {
            header: 'Active Customers',
            accessorKey: 'customers_count',
            sortable: true,
            cell: (pkg) => (
                <Badge variant="secondary">
                    {pkg.customers_count} {pkg.customers_count === 1 ? 'customer' : 'customers'}
                </Badge>
            ),
        },
        {
            header: 'Status',
            cell: statusBadge,
        },
        {
            header: 'Actions',
            className: 'w-[100px] text-right',
            cell: (pkg) => (
                <div className="flex items-center justify-end gap-2">
                    <EditAction onClick={() => router.visit(route('packages.edit', pkg.id))} title="Edit Package" />
                    <DeleteAction onClick={() => setPackageToDelete(pkg)} title="Delete Package" />
                </div>
            ),
        },
    ];

    return (
        <AuthenticatedLayout
            breadcrumbs={[{ label: 'Packages', href: route('packages.index') }]}
            header={
                <ResourcePageHeader
                    title="Packages"
                    actions={
                        <Button asChild size="sm" className="h-8 gap-2">
                            <Link href={route('packages.create')}>
                                <Plus className="h-3.5 w-3.5" />
                                Add Package
                            </Link>
                        </Button>
                    }
                />
            }
        >
            <Head title="Packages" />

            <div className="py-8">
                {isArchive && (
                    <div className="mb-4 rounded-md border border-amber-500/20 bg-amber-500/10 px-4 py-3 text-sm text-amber-800">
                        Archive contains legacy, retired, global, or invalid packages. These packages are not available for customer assignment.
                    </div>
                )}
                <DataTable
                    data={packages}
                    columns={columns}
                    filters={filters}
                    filterConfigs={filterConfigs}
                    title={isArchive ? 'Package Archive' : 'Router Package Catalog'}
                    description={
                        isArchive
                            ? `Showing ${packages.data.length} of ${packages.total} archived packages`
                            : `Showing ${packages.data.length} of ${packages.total} assignable packages`
                    }
                    searchPlaceholder="Search packages..."
                    routeName="packages.index"
                    onRowClick={(item) => router.visit(route('packages.show', item.id))}
                />
            </div>

            <ConfirmDialog
                open={!!packageToDelete}
                onOpenChange={(open) => !open && setPackageToDelete(null)}
                title="Delete Package?"
                description={`Are you sure you want to delete ${packageToDelete?.name}? This action cannot be undone.`}
                confirmText="Delete"
                variant="destructive"
                onConfirm={() => packageToDelete && router.delete(route('packages.destroy', packageToDelete.id), {
                    onFinish: () => setPackageToDelete(null),
                })}
            />
        </AuthenticatedLayout>
    );
}

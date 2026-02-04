import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Button } from '@/Components/ui/button';
import { Server, RefreshCw } from 'lucide-react';
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';
import { toast } from 'sonner';
import { EditAction, DeleteAction } from '@/Components/TableActions';
import { ConfirmDialog } from '@/Components/ConfirmDialog';
import { RouterStatusBadge, SyncStatus } from '@/Components/RouterStatusBadge';

import { PageProps } from '@/types';

interface Router {
    id: number;
    name: string;
    ip_address: string;
    port: number;
    is_active: boolean;
    connection_status: 'unknown' | 'online' | 'offline';
    customers_count: number;
    current_online_count: number;
    total_pppoe_count: number;
    cpu_load: number | null;
}

interface CommonProps extends PageProps {
    // Add existing CommonProps that were unique, if any
    [key: string]: any;
}

interface Props {
    routers: PaginatedData<Router>;
    filters: {
        search?: string;
        status?: string;
        sort?: string;
        direction?: 'asc' | 'desc';
    };
}

export default function Index({ routers, filters = {} }: Props) {
    const { flash } = usePage<PageProps>().props;
    const [isSyncingAll, setIsSyncingAll] = useState(false);
    const [currentSyncingId, setCurrentSyncingId] = useState<number | null>(null);
    const [syncedIds, setSyncedIds] = useState<Set<number>>(new Set());
    const [failedIds, setFailedIds] = useState<Set<number>>(new Set());

    const [excludeConfirmOpen, setExcludeConfirmOpen] = useState(false);
    const [routerToDelete, setRouterToDelete] = useState<number | null>(null);

    const handleSyncAll = async () => {
        // Only sync ACTIVE routers (matching backend behavior)
        const activeRouters = routers.data.filter(r => r.is_active);

        if (activeRouters.length === 0) {
            toast.error('No active routers to sync');
            return;
        }

        setIsSyncingAll(true);
        setSyncedIds(new Set());
        setFailedIds(new Set());

        let succeeded = 0;
        let failed = 0;

        for (const routerItem of activeRouters) {
            setCurrentSyncingId(routerItem.id);

            try {
                await new Promise<void>((resolve) => {
                    router.post(route('routers.sync', routerItem.id), {}, {
                        preserveScroll: true,
                        onSuccess: (page) => {
                            // Check the flash message from property page.props
                            // We need to cast page.props to check for specific custom props
                            const props = page.props as unknown as CommonProps;

                            if (props.flash?.success) {
                                setSyncedIds(prev => new Set(prev).add(routerItem.id));
                                succeeded++;
                            } else {
                                // If no success flash, assume it failed (e.g. error flash)
                                setFailedIds(prev => new Set(prev).add(routerItem.id));
                                failed++;
                            }
                            resolve();
                        },
                        onError: () => {
                            setFailedIds(prev => new Set(prev).add(routerItem.id));
                            failed++;
                            resolve();
                        },
                        onFinish: () => {
                            // Fallback if needed
                        }
                    });
                });
            } catch (e) {
                setFailedIds(prev => new Set(prev).add(routerItem.id));
                failed++;
            }
        }


        setCurrentSyncingId(null);
        setIsSyncingAll(false);



        // Auto-clear icons after 5 seconds
        setTimeout(() => {
            setSyncedIds(new Set());
            setFailedIds(new Set());
        }, 5000);
    };

    const handleDelete = (id: number) => {
        setRouterToDelete(id);
        setExcludeConfirmOpen(true);
    };

    const confirmDelete = () => {
        if (routerToDelete) {
            router.delete(route('routers.destroy', routerToDelete), {
                onFinish: () => {
                    setExcludeConfirmOpen(false);
                    setRouterToDelete(null);
                }
            });
        }
    };

    const getRouterSyncStatus = (id: number): SyncStatus => {
        if (currentSyncingId === id) return 'syncing';
        if (syncedIds.has(id)) return 'success';
        if (failedIds.has(id)) return 'failed';
        return 'idle';
    };

    const columns: Column<Router>[] = [
        {
            header: "Name",
            accessorKey: "name",
            sortable: true,
            cell: (routerData) => (
                <div className="flex items-center gap-2">
                    <div className="flex flex-col">
                        <span className="font-medium">{routerData.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {routerData.connection_status === 'online' ? 'Connected' :
                                routerData.connection_status === 'offline' ? 'Unreachable' :
                                    'Unknown'} • IP: {routerData.ip_address}
                        </span>
                    </div>
                </div>
            ),
        },
        {
            header: "Status",
            className: "w-[150px]",
            cell: (routerData) => (
                <RouterStatusBadge
                    connectionStatus={routerData.connection_status}
                    syncStatus={getRouterSyncStatus(routerData.id)}
                    cpuLoad={routerData.cpu_load}
                />
            ),
        },
        {
            header: "Online / Total",
            accessorKey: "current_online_count",
            sortable: true,
            className: "text-right w-[150px]",
            cell: (routerData) => (
                <div className="flex flex-col items-end">
                    <div className="flex items-center gap-1 font-semibold">
                        <span className="text-emerald-600">{routerData.current_online_count}</span>
                        <span className="text-muted-foreground">/ {routerData.total_pppoe_count || routerData.customers_count}</span>
                    </div>
                </div>
            ),
        },
        {
            header: "Actions",
            className: "text-right w-[100px]",
            cell: (routerData) => (
                <div className="flex items-center justify-end gap-2">
                    <EditAction
                        onClick={() => router.visit(route('routers.edit', routerData.id))}
                        title="Edit Router"
                    />
                    <DeleteAction
                        onClick={() => handleDelete(routerData.id)}
                        title="Delete Router"
                    />
                </div>
            ),
        },
    ];

    const filterConfigs: FilterConfig[] = [
        {
            key: 'status',
            placeholder: 'Status',
            options: [
                { value: 'active', label: 'Active' },
                { value: 'inactive', label: 'Inactive' },
            ],
        },
    ];

    return (
        <AuthenticatedLayout
            header={
                <div className="flex items-center justify-between">
                    <h2 className="text-xl font-semibold leading-tight text-foreground">
                        Routers
                    </h2>
                </div>
            }
        >
            <Head title="Routers" />

            <div className="py-8">
                <DataTable
                    data={routers}
                    columns={columns}
                    filters={filters}
                    title="Routers"
                    description={`Showing ${routers.data.length} of ${routers.total} routers`}
                    searchPlaceholder="Search Name, IP, Status..."
                    filterConfigs={filterConfigs}
                    routeName="routers.index"
                    onRowClick={(item) => router.visit(route('routers.show', item.id))}
                    actions={
                        <div className="flex items-center gap-2">
                            <Button
                                onClick={handleSyncAll}
                                disabled={isSyncingAll}
                                variant="outline"
                                size="sm"
                                className="h-8 gap-2 border-dashed"
                            >
                                <RefreshCw className={`h-3.5 w-3.5 ${isSyncingAll ? 'animate-spin' : ''}`} />
                                {isSyncingAll ? 'Syncing...' : 'Sync All'}
                            </Button>
                            <Link href={route('routers.create')}>
                                <Button size="sm" className="h-8 gap-2">
                                    <Server className="h-3.5 w-3.5" />
                                    Add Router
                                </Button>
                            </Link>
                        </div>
                    }
                />
            </div>

            <ConfirmDialog
                open={excludeConfirmOpen}
                onOpenChange={setExcludeConfirmOpen}
                title="Delete Router"
                description="Are you sure you want to delete this router? This action cannot be undone."
                confirmText="Delete Router"
                variant="destructive"
                onConfirm={confirmDelete}
            />
        </AuthenticatedLayout>
    );
}

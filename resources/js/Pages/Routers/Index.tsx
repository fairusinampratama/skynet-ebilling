import { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Server, Wifi, Activity, Globe, Eye, MoreHorizontal } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu";
import DataTable, { Column, FilterConfig, PaginatedData } from '@/Components/DataTable';

interface Router {
    id: number;
    name: string;
    ip_address: string;
    port: number;
    winbox_port: number | null;
    is_active: boolean;
    customers_count: number;
    current_online_count: number;
    total_pppoe_count: number;
    cpu_load: number | null;
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
    const getStatusBadge = (isActive: boolean) => {
        const className = isActive
            ? 'text-emerald-500 border-emerald-500/20 bg-emerald-500/10'
            : 'text-zinc-500 border-zinc-500/20 bg-zinc-500/10';

        return (
            <Badge variant="outline" className={`${className} capitalize border`}>
                {isActive ? 'Active' : 'Disabled'}
            </Badge>
        );
    };

    const columns: Column<Router>[] = [
        {
            header: "Name",
            accessorKey: "name",
            sortable: true,
            cell: (router) => (
                <div className="flex flex-col">
                    <span className="font-medium">{router.name}</span>
                    <span className="text-xs text-muted-foreground">{router.is_active ? 'Online' : 'Offline'} • IP: {router.ip_address}</span>
                </div>
            ),
        },
        {
            header: "Status",
            className: "w-[150px]",
            cell: (router) => (
                <div className="flex items-center gap-2">
                    <Badge variant={router.is_active ? "outline" : "secondary"} className={router.is_active ? "text-emerald-500 border-emerald-500/20 bg-emerald-500/10" : ""}>
                        {router.is_active ? 'Active' : 'Unreachable'}
                    </Badge>
                    {router.is_active && router.cpu_load !== null && (
                        <Badge variant="secondary" className="text-xs bg-muted/50">
                            CPU: {router.cpu_load}%
                        </Badge>
                    )}
                </div>
            ),
        },
        {
            header: "Online / Total", // Renamed
            accessorKey: "current_online_count",
            sortable: true,
            className: "text-right w-[150px]",
            cell: (router) => (
                <div className="flex flex-col items-end">
                    <div className="flex items-center gap-1 font-semibold">
                        <span className="text-emerald-600">{router.current_online_count}</span>
                        <span className="text-muted-foreground">/ {router.total_pppoe_count || router.customers_count}</span>
                    </div>
                </div>
            ),
        },
        {
            header: "Actions",
            className: "text-right w-[100px]",
            cell: (row) => (
                <div className="flex items-center justify-end gap-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" className="h-8 w-8 p-0">
                                <span className="sr-only">Open menu</span>
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={() => router.visit(route('routers.show', row.id))}>
                                <Eye className="mr-2 h-4 w-4" />
                                View Details
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            ),
        },
    ];

    const filterConfigs: FilterConfig[] = [
        {
            key: 'status',
            placeholder: 'Filter by status',
            options: [
                { label: 'All Status', value: 'all' },
                { label: 'Active', value: 'active' },
                { label: 'Disabled', value: 'disabled' },
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
                    <Link href="/routers/create">
                        <Button>
                            <Server className="mr-2 h-4 w-4" />
                            Add Router
                        </Button>
                    </Link>
                </div>
            }
        >
            <Head title="Routers" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <DataTable<Router>
                        data={routers}
                        columns={columns}
                        filterConfigs={filterConfigs}
                        searchPlaceholder="Search routers..."
                        title="Managed Routers"
                        description="List of all MikroTik routers connected to the system."
                        routeName="routers.index"
                    />
                </div>
            </div>
        </AuthenticatedLayout>
    );
}
